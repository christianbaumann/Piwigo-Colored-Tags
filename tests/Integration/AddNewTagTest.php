<?php
use PHPUnit\Framework\TestCase;

/**
 * typetags.image.addNewTag over the real ws.php endpoint: the picture page's
 * field for a tag that is not offered as a + badge.
 *
 * Requirement: plan 2026-10-09 "Tags in the image file", Phase 5, Q15 - any
 * logged-in account; a new name is created, an existing one is linked and
 * keeps its group.
 */
final class AddNewTagTest extends TestCase
{
    /** The longest name the tags table holds, in characters. */
    private const MAX_NAME = 255;
    /** WS_ERR_INVALID_PARAM; this suite's bootstrap loads no core. */
    private const INVALID_PARAM = 1003;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private string $suffix;
    private array $names = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);
        $this->ws->login(Config::normalUsername(), Config::normalPassword());

        $this->imageId = $this->fixtures->testImageId();
        $this->fixtures->imageWithNoTags($this->imageId);
        $this->suffix = bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach ($this->names as $name)
        {
            $this->fixtures->trackTagNamed($name);
        }
        $this->fixtures->restore();
        $this->ws->logout();
    }

    // ── Creating and linking ──────────────────────────────────────────────

    /** [HAPPY] A normal account types a new name: the tag is created and linked. */
    public function testANormalAccountCreatesAndLinksANewName(): void
    {
        $name = $this->newName('Kirmes');

        $res = $this->addNewTag($name);

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $answer = $res['json']['result'];
        $this->assertTrue($answer['created']);
        $this->assertSame($name, $answer['name']);
        $this->assertSame($this->tagIdNamed($name), $answer['tag_id']);
        $this->assertSame(array($answer['tag_id']), $this->fixtures->assignedTagIds($this->imageId));
    }

    /** [ECP] An existing name is linked, not created again, and keeps its group. */
    public function testAnExistingNameIsLinkedAndKeepsItsGroup(): void
    {
        $striped = $this->fixtures->createStripedGroup();
        $name = (string)$this->db->scalar('SELECT name FROM piwigo_tags WHERE id = ' . $striped['tag_id']);

        $res = $this->addNewTag($name);

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $answer = $res['json']['result'];
        $this->assertFalse($answer['created']);
        $this->assertSame($striped['tag_id'], $answer['tag_id']);
        $this->assertSame(1, $this->tagCount($name));
        $this->assertSame((string)$striped['group_id'],
            (string)$this->db->scalar('SELECT id_typetags FROM piwigo_tags WHERE id = ' . $striped['tag_id']));
        $this->assertSame(array($striped['tag_id']), $this->fixtures->assignedTagIds($this->imageId));
        $this->assertStringContainsString('repeating-linear-gradient', $answer['style'], 'the answer is not the group\'s badge');
        $this->assertNotSame('', $answer['emoji_html'], 'the answer lacks the group\'s emoji');
    }

    /** [ERR] Markup is stripped as core strips it from a typed tag (get_tag_ids); no requirement names it. */
    public function testMarkupIsStrippedAsCoreDoes(): void
    {
        $typed = '<b>Fett</b> & x ' . $this->suffix;
        $stored = 'Fett & x ' . $this->suffix;
        $this->names[] = $stored;

        $res = $this->addNewTag($typed);

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame($stored, $res['json']['result']['name']);
        $this->assertSame(1, $this->tagCount($stored));
    }

    /** [ERR] A quote reaches the table as typed, neither slashed nor lost. */
    public function testAnApostropheIsStoredAsTyped(): void
    {
        $name = $this->newName("D'r Kirmes");

        $res = $this->addNewTag($name);

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1, $this->tagCount($name));
    }

    // ── Names ─────────────────────────────────────────────────────────────

    /** [BVA] 255 characters, each of them two bytes, are accepted. */
    public function testTheLongestNameIsAccepted(): void
    {
        $name = $this->longName(self::MAX_NAME);

        $res = $this->addNewTag($name);

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1, $this->tagCount($name));
    }

    /** [BVA] One character more is refused, and no tag is left behind. */
    public function testOneCharacterMoreIsRefused(): void
    {
        $name = $this->longName(self::MAX_NAME + 1);

        $res = $this->addNewTag($name);

        $this->assertRefused($res, self::INVALID_PARAM);
        $this->assertSame(0, $this->tagCount(mb_substr($name, 0, self::MAX_NAME)));
    }

    /** [BVA] Nothing typed. */
    public function testAnEmptyNameIsRefused(): void
    {
        $this->assertRefused($this->addNewTag(''), null);
    }

    /** [BVA] Only spaces, or only markup, leave no name. */
    public function testABlankNameIsRefused(): void
    {
        $this->assertRefused($this->addNewTag('   '), self::INVALID_PARAM);
        $this->assertRefused($this->addNewTag('<b></b>'), self::INVALID_PARAM);
    }

    /**
     * [NEG] The tags table is utf8mb3 (measured 2026-10-09): a character of
     * four UTF-8 bytes, as most emoji are, cannot be stored. Refused, rather
     * than the database error and its stack trace reaching the answer.
     */
    public function testAnEmojiOutsideTheBasicPlaneIsRefused(): void
    {
        $res = $this->addNewTag("Kirmes \u{1F389} " . $this->suffix);

        $this->assertRefused($res, self::INVALID_PARAM);
        $this->assertStringNotContainsString('Stack trace', $res['body']);
    }

    /**
     * [NEG] Core prints a tag's name unescaped inside HTML attributes (the
     * keywords meta of every page, the title on the tags page), so a name
     * that could end an attribute or open a tag is refused, and so is a
     * control character. So is a backslash: the admin tags page writes
     * orphan tag names into a JavaScript array literal, where it escapes the
     * closing quote. Only an administrator could name a tag in core.
     */
    public function testCharactersThatCouldBreakOutOfAnAttributeAreRefused(): void
    {
        foreach (array('x" onmouseover="alert(1)', 'a > b', 'a < b', "a\tb", 'a\\b') as $base)
        {
            $name = $base . ' ' . $this->suffix;
            $this->assertRefused($this->addNewTag($name), self::INVALID_PARAM);
        }
        $this->assertSame(0, (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_tags WHERE name LIKE '%" . $this->db->escape($this->suffix) . "'"
        ));
    }

    /**
     * [BVA] The URL name core derives from the name spells œ as "oe", so a
     * name of fewer than 255 characters can still need more than the
     * url_name column holds (255). One "oe" short of it is accepted.
     */
    public function testTheLongestUrlNameIsAccepted(): void
    {
        $name = $this->suffix . str_repeat('œ', (self::MAX_NAME - strlen($this->suffix)) >> 1);
        $this->names[] = $name;

        $this->assertSame('ok', $this->addNewTag($name)['json']['stat'] ?? null);
        $this->assertSame(1, $this->tagCount($name));
    }

    /** [BVA] One œ more makes the URL name too long; refused rather than a database error. */
    public function testAUrlNameOverTheColumnIsRefused(): void
    {
        $name = $this->suffix . str_repeat('œ', ((self::MAX_NAME - strlen($this->suffix)) >> 1) + 1);
        $this->assertLessThan(self::MAX_NAME, mb_strlen($name), 'anti-vacuity: the name itself must fit');

        $res = $this->addNewTag($name);

        $this->assertRefused($res, self::INVALID_PARAM);
        $this->assertStringNotContainsString('INSERT', $res['body']);
        $this->assertSame(0, $this->tagCount($name));
    }

    /** [NEG] A list instead of a name; core's ws layer refuses it before the method runs. */
    public function testAListIsRefused(): void
    {
        $res = $this->ws->call('typetags.image.addNewTag', array(
            'image_id' => $this->imageId,
            'tag_name[0]' => $this->newName('Kirmes'),
            'pwg_token' => $this->ws->token(),
            ));

        $this->assertRefused($res, self::INVALID_PARAM);
    }

    /** [NEG] Bytes that are not UTF-8 at all. */
    public function testInvalidUtf8IsRefused(): void
    {
        $res = $this->addNewTag("Kirmes \xC3( " . $this->suffix);

        $this->assertRefused($res, self::INVALID_PARAM);
    }

    /** [ECP] A character of three bytes fits the table, so ✍ is a valid name. */
    public function testAThreeByteCharacterIsAccepted(): void
    {
        $name = $this->newName("Kirmes \u{270D}");

        $this->assertSame('ok', $this->addNewTag($name)['json']['stat'] ?? null);
        $this->assertSame(1, $this->tagCount($name));
    }

    // ── Refusals ──────────────────────────────────────────────────────────

    /** [NEG] */
    public function testGuestIsRefused(): void
    {
        $name = $this->newName('Kirmes');

        $res = $this->ws->call('typetags.image.addNewTag', array(
            'image_id' => $this->imageId, 'tag_name' => $name, 'pwg_token' => 'fake',
            ), false);

        $this->assertRefused($res, 401);
        $this->assertSame(0, $this->tagCount($name));
    }

    /** [NEG] */
    public function testAWrongTokenIsRefused(): void
    {
        $name = $this->newName('Kirmes');

        $this->assertRefused($this->addNewTag($name, array('pwg_token' => 'wrong_token_value')), 403);
        $this->assertSame(0, $this->tagCount($name));
    }

    /** [NEG] A GET could be a link someone was sent. */
    public function testAGetIsRefused(): void
    {
        $name = $this->newName('Kirmes');

        $res = $this->ws->callGet('typetags.image.addNewTag', array(
            'image_id' => $this->imageId, 'tag_name' => $name, 'pwg_token' => $this->ws->token(),
            ));

        $this->assertSame('fail', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertSame(0, $this->tagCount($name));
    }

    /** [NEG] An unknown photo is refused before the tag is created. */
    public function testAnUnknownImageIsRefusedAndNoTagIsCreated(): void
    {
        $name = $this->newName('Kirmes');

        $res = $this->addNewTag($name, array('image_id' => $this->fixtures->nonexistentImageId()));

        $this->assertRefused($res, 404);
        $this->assertSame(0, $this->tagCount($name));
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** A name unique to this run, removed again in tearDown. */
    private function newName(string $base): string
    {
        $name = $base . ' ' . $this->suffix;
        $this->names[] = $name;
        return $name;
    }

    /** $length characters of two bytes each, unique to this run. */
    private function longName(int $length): string
    {
        $name = $this->suffix . str_repeat('ä', $length - strlen($this->suffix));
        $this->names[] = $name;
        $this->assertSame($length, mb_strlen($name), 'anti-vacuity: the name has the wrong length');
        return $name;
    }

    private function addNewTag(string $name, array $overrides = array()): array
    {
        return $this->ws->call('typetags.image.addNewTag', $overrides + array(
            'image_id' => $this->imageId,
            'tag_name' => $name,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function assertRefused(array $res, ?int $code): void
    {
        $this->assertSame('fail', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        if ($code !== null)
        {
            $this->assertSame($code, $res['json']['err']);
        }
        $this->assertSame(array(), $this->fixtures->assignedTagIds($this->imageId), 'a refused call linked a tag');
    }

    private function tagCount(string $name): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_tags WHERE name = '" . $this->db->escape($name) . "'");
    }

    private function tagIdNamed(string $name): int
    {
        $id = (int)$this->db->scalar("SELECT id FROM piwigo_tags WHERE name = '" . $this->db->escape($name) . "'");
        $this->assertGreaterThan(0, $id, "no tag named $name");
        return $id;
    }
}
