<?php
use PHPUnit\Framework\TestCase;

/**
 * Regression net for the other callers of get_color_text() and check_color().
 *
 * Phase 2 changed get_color_text()'s behaviour for unparseable input, and both
 * helpers are reached from well outside the picture page. These two paths are
 * the ones no other test in the suite touches.
 */
final class ColorHelperCallersTest extends TestCase
{
    private const FIXTURE_TYPE_NAME = '_test_colour_helper_callers';

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
        $name = $this->db->escape(self::FIXTURE_TYPE_NAME);
        $this->db->query("DELETE FROM piwigo_typetags WHERE name = '$name'");
        $this->ws->logout();
    }

    /** [HAPPY] typetags_admin() calls get_color_text() for every colour on the admin tags page. */
    public function testAdminTagsPageRenders(): void
    {
        $this->assertGreaterThan(
            0,
            (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_typetags'),
            'anti-vacuity: with no colours configured this page would prove nothing'
        );

        $res = $this->ws->fetchPage('/admin.php?page=tags');

        $this->assertSame(200, $res['http_code']);
        $this->assertStringNotContainsString('Fatal error', $res['body']);
        $this->assertStringNotContainsString('TypeError', $res['body']);
    }

    /** [HAPPY] ws_typetags_type_add() calls check_color() and then get_color_text() on its result. */
    public function testTypeAddReturnsContrastColour(): void
    {
        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => self::FIXTURE_TYPE_NAME,
            'typetag_color' => 'AABBCC',
        ));

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame('#AABBCC', $res['json']['result']['color'], 'check_color normalises to a leading hash');
        $this->assertSame('#000', $res['json']['result']['color_text'], 'a light background needs black text');
    }
}
