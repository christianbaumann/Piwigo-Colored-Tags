<?php
use PHPUnit\Framework\TestCase;

/**
 * Closes the three manual boxes left in the 2026-04-19 installation plan.
 *
 * Each was written as "look at the admin UI and confirm": the plugin is active,
 * its configuration page loads, and a tag can be given a colour. All three have
 * an oracle in the database or over HTTP, so none of them needs a human.
 */
final class PluginActivationTest extends TestCase
{
    private const PLUGIN_ID = 'typetags';
    private const FIXTURE_TAG_NAME = '_test_plugin_activation_tag';

    private Db $db;
    private WsClient $ws;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $name = $this->db->escape(self::FIXTURE_TAG_NAME);
        $this->db->query("DELETE FROM piwigo_tags WHERE name = '$name'");
        $this->ws->logout();
    }

    /**
     * [HAPPY] Plan A box: "Plugin appears in the installed plugins list with status Active".
     *
     * The list is a rendering of piwigo_plugins, which is where activate()
     * writes — asserting the row is asserting what the list would show.
     */
    public function testPluginIsInstalledAndActive(): void
    {
        $state = $this->db->scalar(
            "SELECT state FROM piwigo_plugins WHERE id = '" . self::PLUGIN_ID . "'"
        );

        $this->assertNotNull($state, 'the plugin has no row in piwigo_plugins, so it was never installed');
        $this->assertSame('active', $state);
    }

    /**
     * [HAPPY] Plan A box: "Plugin configuration page loads without errors".
     *
     * Asserts more than HTTP 200: every configured colour must actually be
     * painted onto the page, so a page that renders a shell with an empty list
     * cannot pass. Expected values are read from piwigo_typetags rather than
     * typed here — a second copy of the palette would rot on the first edit.
     */
    public function testConfigurationPageRendersEveryConfiguredColour(): void
    {
        $colours = array();
        $result = $this->db->query('SELECT color FROM piwigo_typetags');
        while ($row = $result->fetch_row())
        {
            $colours[] = $row[0];
        }

        $this->assertGreaterThan(
            0,
            count($colours),
            'anti-vacuity: with no colours configured this test would assert nothing'
        );

        $res = $this->ws->fetchPage('/admin.php?page=plugin-typetags');

        $this->assertSame(200, $res['http_code']);
        $this->assertStringNotContainsString('Fatal error', $res['body']);
        $this->assertStringNotContainsString('TypeError', $res['body']);
        $this->assertStringNotContainsString('Smarty Compiler', $res['body']);
        $this->assertStringContainsString('Colored Tags', $res['body'], 'the plugin page, not a redirect to some other admin screen');

        foreach ($colours as $colour)
        {
            $this->assertStringContainsString(
                'background-color:' . $colour . ';',
                $res['body'],
                "configured colour $colour is not painted on the configuration page"
            );
        }
    }

    /**
     * [HAPPY] Plan A box: "Tags in the gallery can be assigned colors".
     *
     * typetags.tags.setType is the write the admin tags screen performs, so
     * driving it exercises the same path the manual check went through.
     */
    public function testTagCanBeAssignedAColour(): void
    {
        $tagId = $this->createPlainFixtureTag();
        $typetagId = (int)$this->db->scalar('SELECT id FROM piwigo_typetags ORDER BY id LIMIT 1');
        $this->assertGreaterThan(0, $typetagId, 'anti-vacuity: no colour exists to assign');

        $this->assertNull(
            $this->db->scalar("SELECT id_typetags FROM piwigo_tags WHERE id = $tagId"),
            'fixture precondition: the tag starts with no colour'
        );

        $res = $this->ws->call('typetags.tags.setType', array(
            'tag_id' => array($tagId),
            'typetag_id' => $typetagId,
        ));

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(
            $typetagId,
            (int)$this->db->scalar("SELECT id_typetags FROM piwigo_tags WHERE id = $tagId"),
            'the colour did not reach the tag'
        );
    }

    /** [ST] The same method removes a colour again — typetag_id 0 means "no colour". */
    public function testTagColourCanBeRemovedAgain(): void
    {
        $tagId = $this->createPlainFixtureTag();
        $typetagId = (int)$this->db->scalar('SELECT id FROM piwigo_typetags ORDER BY id LIMIT 1');

        $this->ws->call('typetags.tags.setType', array(
            'tag_id' => array($tagId),
            'typetag_id' => $typetagId,
        ));
        $this->assertSame(
            $typetagId,
            (int)$this->db->scalar("SELECT id_typetags FROM piwigo_tags WHERE id = $tagId"),
            'precondition: the tag is coloured before we remove the colour'
        );

        $res = $this->ws->call('typetags.tags.setType', array(
            'tag_id' => array($tagId),
            'typetag_id' => 0,
        ));

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertNull($this->db->scalar("SELECT id_typetags FROM piwigo_tags WHERE id = $tagId"));
    }

    /**
     * [NEG] setType is admin_only, unlike the two image methods. A guest must not
     * be able to recolour the gallery's tags.
     */
    public function testGuestCannotAssignAColour(): void
    {
        $tagId = $this->createPlainFixtureTag();
        $typetagId = (int)$this->db->scalar('SELECT id FROM piwigo_typetags ORDER BY id LIMIT 1');

        $this->ws->logout();
        $res = $this->ws->call('typetags.tags.setType', array(
            'tag_id' => array($tagId),
            'typetag_id' => $typetagId,
        ));
        $this->ws->login(Config::username(), Config::password());

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertNull(
            $this->db->scalar("SELECT id_typetags FROM piwigo_tags WHERE id = $tagId"),
            'the rejected call must not have written a colour'
        );
    }

    /** A tag with no colour, forced rather than found, so the test owns its precondition. */
    private function createPlainFixtureTag(): int
    {
        $name = $this->db->escape(self::FIXTURE_TAG_NAME);
        $this->db->query("DELETE FROM piwigo_tags WHERE name = '$name'");
        $this->db->query("INSERT INTO piwigo_tags (name, url_name) VALUES ('$name', '$name')");
        return $this->db->insertId();
    }
}
