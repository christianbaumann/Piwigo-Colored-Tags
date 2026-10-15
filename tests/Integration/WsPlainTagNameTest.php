<?php
use PHPUnit\Framework\TestCase;

/**
 * Every ws.php answer carries the plain tag name; the badge markup belongs to
 * rendered pages only (design "The API returns plain tag names").
 *
 * API clients got inline <span style=...> in tag names (SES-2026-10-13-12).
 * pwg.tags.getAdminList also feeds the tag fields of photo properties and the
 * Batch Manager (core's TagsCache), which put each name into a selectize chip
 * unescaped; typetags_chip_css() already paints that chip, so badge HTML there
 * doubled the emoji and the stripes, and the field's search matched the markup.
 */
final class WsPlainTagNameTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private array $striped;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);
        $this->striped = $this->fixtures->createStripedGroup();
        $this->fixtures->givenAssigned($this->fixtures->testImageId(), array($this->striped['tag_id']));
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->fixtures->restore();
        $this->ws->logout();
    }

    /** @return array the seeded tag as the method answered it */
    private function seededTagFrom(string $method, array $params = array()): array
    {
        $res = $this->ws->call($method, $params);
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $tags = $res['json']['result']['tags'];
        $this->assertNotEmpty($tags);
        foreach ($tags as $tag)
        {
            if ((int)$tag['id'] === $this->striped['tag_id'])
            {
                return $tag;
            }
        }
        $this->fail("$method did not list the seeded tag {$this->striped['tag_id']}");
    }

    /** The seeded tag's name as core stores it. */
    private function storedName(): string
    {
        $stored = (string)$this->db->scalar("SELECT name FROM piwigo_tags WHERE id = {$this->striped['tag_id']}");
        $this->assertNotSame('', $stored);
        return $stored;
    }

    /**
     * Anti-vacuity: the render hook is registered (show_all) and still paints
     * the seeded tag on a rendered page (the tag's own photo list, whose title
     * core passes through render_tag_name), so a plain name in a ws.php answer
     * is the rule at work, not a hook that never ran.
     */
    public function testARenderedPageStillGetsTheBadge(): void
    {
        $conf = unserialize((string)$this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'TypeTags'"));
        $this->assertTrue($conf['show_all'] ?? false, 'show_all must be on for render_tag_name to be hooked');

        $res = $this->ws->fetchPage('/index.php?/tags/' . $this->striped['tag_id']);
        $this->assertSame(200, (int)$res['http_code']);
        $this->assertMatchesRegularExpression(
            '/<span style="[^"]*">(?:<span class="typetag-emoji">[^<]*<\/span>)?\s*'
                . preg_quote(htmlspecialchars($this->storedName()), '/') . '<\/span>/',
            (string)$res['body']
        );
    }

    /** [NEG] pwg.images.getInfo lists the photo's tags by their plain names */
    public function testThePhotoInfoCarriesThePlainName(): void
    {
        $tag = $this->seededTagFrom('pwg.images.getInfo', array('image_id' => $this->fixtures->testImageId()));
        $this->assertSame($this->storedName(), $tag['name']);
    }

    /** [NEG] pwg.tags.getList carries the plain name */
    public function testTheTagListCarriesThePlainName(): void
    {
        // get_available_tags() keeps a persistent cache per cache_update_time,
        // computed before the fixture tag was assigned
        $this->fixtures->invalidateUserCache();
        $tag = $this->seededTagFrom('pwg.tags.getList');
        $this->assertSame($this->storedName(), $tag['name']);
    }

    /** [NEG] the admin list carries the plain name, as core stores it */
    public function testTheAdminTagListCarriesThePlainName(): void
    {
        $tag = $this->seededTagFrom('pwg.tags.getAdminList');
        $this->assertSame($this->striped['group_id'], (int)$tag['id_typetags']);
        $stored = (string)$this->db->scalar("SELECT name FROM piwigo_tags WHERE id = {$this->striped['tag_id']}");
        $this->assertNotSame('', $stored);
        $this->assertSame($stored, $tag['name_raw']);

        $this->assertSame($tag['name_raw'], $tag['name']);
        $this->assertStringNotContainsString('<', $tag['name']);
    }

    /**
     * [NEG] Renaming a tag on the admin tag screen: core's tags.js puts the
     * answered name into the tag box with .html() and into the edit field's
     * value, so it must come back plain like the admin list's. The fixture tag
     * is deleted in tearDown, so the new name needs no restore.
     */
    public function testRenamingAColouredTagAnswersThePlainName(): void
    {
        $newName = '_fixture_striped_tag_renamed';
        $res = $this->ws->call('pwg.tags.rename', array(
            'tag_id' => $this->striped['tag_id'],
            'new_name' => $newName,
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame($newName, (string)$this->db->scalar("SELECT name FROM piwigo_tags WHERE id = {$this->striped['tag_id']}"));
        $this->assertContains($this->striped['tag_id'], $this->fixtures->stripedTagIds(), 'anti-vacuity: the renamed tag must still be coloured');

        $this->assertSame($newName, $res['json']['result']['name']);
        $this->assertStringNotContainsString('<', $res['json']['result']['name']);
    }
}
