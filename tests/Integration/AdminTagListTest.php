<?php
use PHPUnit\Framework\TestCase;

/**
 * pwg.tags.getAdminList feeds the tag fields of photo properties and the
 * Batch Manager (core's TagsCache), which put each name into a selectize
 * chip unescaped. typetags_chip_css() already paints that chip, so the name
 * must come back plain: badge HTML there doubles the emoji and the stripes,
 * and the field's search matches the markup.
 */
final class AdminTagListTest extends TestCase
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

    /**
     * Anti-vacuity: the render hook is registered (show_all) and turns the
     * seeded tag into a badge on another ws.php method (the photo's tags in
     * pwg.images.getInfo), so a plain name in the admin list is the
     * exemption at work, not a hook that never ran.
     */
    public function testAnotherWsMethodStillGetsTheBadge(): void
    {
        $conf = unserialize((string)$this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'TypeTags'"));
        $this->assertTrue($conf['show_all'] ?? false, 'show_all must be on for render_tag_name to be hooked');

        $tag = $this->seededTagFrom('pwg.images.getInfo', array('image_id' => $this->fixtures->testImageId()));
        $this->assertStringContainsString('<span style=', $tag['name']);
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
