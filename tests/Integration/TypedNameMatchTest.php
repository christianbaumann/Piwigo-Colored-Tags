<?php
use PHPUnit\Framework\TestCase;

/**
 * Which stored tag typetags.image.addNewTag links for a typed name: the name
 * is put into Unicode NFC and matched by name only, under the column's
 * utf8mb3_general_ci collation; a tag's url_name is never a match. The answer
 * names the tag the photo got.
 *
 * Requirement: change 2026-10-15-exploratory-testing-answers, design
 * "Typed tag names are NFC and match by name only" (findings 13-02 F1-F3).
 */
final class TypedNameMatchTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private string $suffix;
    /** Exact names to remove in tearDown; looked up byte for byte. */
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
            foreach ($this->tagIdsNamed($name) as $id)
            {
                $this->db->query('DELETE FROM piwigo_image_tag WHERE tag_id = ' . $id);
                $this->db->query("DELETE FROM piwigo_activity WHERE object = 'tag' AND object_id = " . $id);
                $this->db->query('DELETE FROM piwigo_tags WHERE id = ' . $id);
            }
        }
        $this->fixtures->restore();
        $this->ws->logout();
    }

    /** [ECP] A name typed decomposed links the tag stored composed; no second tag. */
    public function testADecomposedNameLinksTheComposedTag(): void
    {
        $stored = $this->createTag("H\u{00E4}user " . $this->suffix, 'hauser_' . $this->suffix);
        $decomposed = "Ha\u{0308}user " . $this->suffix;
        $this->names[] = $decomposed;

        $res = $this->addNewTag($decomposed);

        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertFalse($res['json']['result']['created']);
        $this->assertSame($stored, $res['json']['result']['tag_id']);
        $this->assertSame("H\u{00E4}user " . $this->suffix, $res['json']['result']['name']);
        $this->assertSame(array(), $this->tagIdsNamed($decomposed), 'a second tag of the same visible name was created');
        $this->assertSame(array($stored), $this->fixtures->assignedTagIds($this->imageId));
    }

    /**
     * [NEG] A name no tag carries is created, even when a marker tag's url_name
     * equals it: `Name` and `Kategorie` against `Name ?` and `Kategorie ?`.
     */
    public function testANameMatchingOnlyAUrlNameIsCreated(): void
    {
        foreach (array('Name', 'Kategorie') as $base)
        {
            $this->fixtures->imageWithNoTags($this->imageId);
            $typed = $base . ' ' . $this->suffix;
            $marker = $this->createTag($typed . ' ?', strtolower($base) . '_' . $this->suffix);
            $this->names[] = $typed;

            $res = $this->addNewTag($typed);

            $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
            $this->assertTrue($res['json']['result']['created'], "$typed was not created");
            $this->assertSame($typed, $res['json']['result']['name']);
            $created = $this->tagIdsNamed($typed);
            $this->assertCount(1, $created, "no tag named $typed");
            $this->assertNotSame($marker, $created[0]);
            $this->assertSame($created, $this->fixtures->assignedTagIds($this->imageId));
        }
    }

    /**
     * [ECP] A name the collation calls equal links the stored tag, and the
     * answer names the stored tag, not the typed text.
     */
    public function testACaseAndAccentVariantLinksTheStoredTagAndTheAnswerNamesIt(): void
    {
        $storedName = 'hauser ortsansichten ' . $this->suffix;
        $stored = $this->createTag($storedName, 'hauser_ortsansichten_' . $this->suffix);

        $res = $this->addNewTag('Häuser Ortsansichten ' . $this->suffix);

        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertFalse($res['json']['result']['created']);
        $this->assertSame($stored, $res['json']['result']['tag_id']);
        $this->assertSame($storedName, $res['json']['result']['name']);
        $this->assertSame(array($stored), $this->fixtures->assignedTagIds($this->imageId));
    }

    /** [BVA] At the daily cap a variant of an existing tag is linked, and the count stays. */
    public function testAtTheCapAVariantOfAnExistingTagIsLinkedAndNotCounted(): void
    {
        $stored = $this->createTag("H\u{00E4}user " . $this->suffix, 'hauser_' . $this->suffix);
        $this->fixtures->seedTypedTagCreations(Config::normalUsername(), TYPETAGS_NEW_TAGS_PER_DAY);
        $before = $this->typedToday();
        $this->assertGreaterThanOrEqual(TYPETAGS_NEW_TAGS_PER_DAY, $before, 'anti-vacuity: the account must be at its cap');

        $res = $this->addNewTag("Ha\u{0308}user " . $this->suffix);

        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertSame($stored, $res['json']['result']['tag_id']);
        $this->assertSame($before, $this->typedToday());
    }

    /** [NEG] At the cap a name matching only a url_name is a new name, and refused. */
    public function testAtTheCapANameMatchingOnlyAUrlNameIsRefused(): void
    {
        $typed = 'Name ' . $this->suffix;
        $marker = $this->createTag($typed . ' ?', 'name_' . $this->suffix);
        $this->names[] = $typed;
        $this->fixtures->seedTypedTagCreations(Config::normalUsername(), TYPETAGS_NEW_TAGS_PER_DAY);

        $res = $this->addNewTag($typed);

        $this->assertSame(429, $res['json']['err'] ?? null, 'Got: ' . $res['body']);
        $this->assertSame(array(), $this->fixtures->assignedTagIds($this->imageId), "the marker $marker was linked");
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** A tag inserted directly with the url_name given (core's str2url() form), removed in tearDown. */
    private function createTag(string $name, string $urlName): int
    {
        $this->db->query(
            "INSERT INTO piwigo_tags (name, url_name) VALUES ('" . $this->db->escape($name) . "', '"
            . $this->db->escape($urlName) . "')"
        );
        $this->names[] = $name;
        $ids = $this->tagIdsNamed($name);
        $this->assertCount(1, $ids, "the tag $name was not created");
        return $ids[0];
    }

    /** Ids of the tags with exactly these bytes as name. @return int[] */
    private function tagIdsNamed(string $name): array
    {
        $result = $this->db->query(
            "SELECT id FROM piwigo_tags WHERE BINARY name = '" . $this->db->escape($name) . "' ORDER BY id"
        );
        $ids = array();
        while ($row = $result->fetch_row())
        {
            $ids[] = (int)$row[0];
        }
        return $ids;
    }

    /** Typed-tag creations of the normal account in the last 24 hours, as the cap counts them. */
    private function typedToday(): int
    {
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_activity a JOIN piwigo_users u ON u.id = a.performed_by"
            . " WHERE u.username = '" . $this->db->escape(Config::normalUsername()) . "'"
            . ' AND ' . typetags_typed_tags_condition()
        );
    }

    private function addNewTag(string $name): array
    {
        return $this->ws->call('typetags.image.addNewTag', array(
            'image_id' => $this->imageId,
            'tag_name' => $name,
            'pwg_token' => $this->ws->token(),
        ));
    }
}
