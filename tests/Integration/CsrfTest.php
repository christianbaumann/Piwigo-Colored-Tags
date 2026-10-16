<?php
use PHPUnit\Framework\TestCase;

/**
 * Every typetags write takes POST and the session's pwg_token, so a forged link
 * or a form on another site cannot change a group: typetags.type.add,
 * typetags.tags.setType and the plugin page's add, edit, delete and
 * configuration forms. Each refusal also asserts nothing changed.
 *
 * As the webmaster, who passes admin_only: what is left to refuse a forged
 * request is exactly the verb and the token.
 */
final class CsrfTest extends TestCase
{
    private const FIXTURE_PREFIX = '_test_csrf_';
    private const PAGE = '/admin.php?page=plugin-typetags';
    /** core's ws_core.inc.php: a post_only method called by GET */
    private const POST_ONLY_REFUSAL = 405;
    private const BAD_TOKEN = 403;
    /** WS_ERR_MISSING_PARAM: the token is a required parameter */
    private const MISSING_PARAM = 1002;
    /** core's bad_request('missing token') of check_pwg_token() */
    private const MISSING_TOKEN_STATUS = 400;
    /** WS_ERR_INVALID_PARAM */
    private const INVALID_PARAM = 1003;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $groupId;
    private int $tagId;
    private ?string $config;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->fixtures = new FixtureBuilder($this->db);
        $striped = $this->fixtures->createStripedGroup();
        $this->groupId = $striped['group_id'];
        $this->tagId = $striped['tag_id'];
        $this->config = $this->configValue();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->ws->logout();
        $pattern = $this->db->escape(str_replace('_', '\_', self::FIXTURE_PREFIX) . '%');
        $this->db->query("DELETE FROM piwigo_typetags WHERE name LIKE '$pattern'");
        $this->fixtures->restore();
        // a configuration form that got through must not change the install
        $this->db->query("UPDATE piwigo_config SET value = '" . $this->db->escape((string)$this->config) . "' WHERE param = 'TypeTags'");
    }

    private function configValue(): ?string
    {
        return $this->db->scalar("SELECT value FROM piwigo_config WHERE param = 'TypeTags'");
    }

    private function groupRows(string $name): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_typetags WHERE name = '" . $this->db->escape($name) . "'");
    }

    private function tagGroup(): ?string
    {
        return $this->db->scalar("SELECT id_typetags FROM piwigo_tags WHERE id = {$this->tagId}");
    }

    private function group(): array
    {
        return $this->db->query("SELECT name, color, striped, emoji FROM piwigo_typetags WHERE id = {$this->groupId}")->fetch_assoc() ?? array();
    }

    private static function err(array $res): ?int
    {
        return isset($res['json']['err']) ? (int)$res['json']['err'] : null;
    }

    // ── typetags.type.add ─────────────────────────────────────────────────

    /** [NEG] [ECP] the verb: a link or an <img> can only send GET */
    public function testTypeAddRefusesGet(): void
    {
        $name = self::FIXTURE_PREFIX . 'get';
        $res = $this->ws->callGet('typetags.type.add', array(
            'typetag_name' => $name, 'typetag_color' => 'AABBCC', 'pwg_token' => $this->ws->token()));

        $this->assertSame(self::POST_ONLY_REFUSAL, self::err($res), $res['body']);
        $this->assertSame(0, $this->groupRows($name));
    }

    /** [NEG] [ECP] a cross-site form posts without the token */
    public function testTypeAddRefusesAPostWithoutToken(): void
    {
        $name = self::FIXTURE_PREFIX . 'no_token';
        $res = $this->ws->call('typetags.type.add', array('typetag_name' => $name, 'typetag_color' => 'AABBCC'));

        $this->assertSame(self::MISSING_PARAM, self::err($res), $res['body']);
        $this->assertSame(0, $this->groupRows($name));
    }

    /** [NEG] */
    public function testTypeAddRefusesAWrongToken(): void
    {
        $name = self::FIXTURE_PREFIX . 'wrong_token';
        $res = $this->ws->call('typetags.type.add', array('typetag_name' => $name, 'typetag_color' => 'AABBCC', 'pwg_token' => str_repeat('0', 32)));

        $this->assertSame(self::BAD_TOKEN, self::err($res), $res['body']);
        $this->assertSame(0, $this->groupRows($name));
    }

    /** [HAPPY] */
    public function testTypeAddTakesAPostWithToken(): void
    {
        $name = self::FIXTURE_PREFIX . 'ok';
        $res = $this->ws->call('typetags.type.add', array('typetag_name' => $name, 'typetag_color' => 'AABBCC', 'pwg_token' => $this->ws->token()));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertSame(1, $this->groupRows($name));
    }

    // ── typetags.tags.setType ─────────────────────────────────────────────

    /** [NEG] [ECP] */
    public function testSetTypeRefusesGet(): void
    {
        $res = $this->ws->callGet('typetags.tags.setType', array(
            'tag_id' => $this->tagId, 'typetag_id' => 0, 'pwg_token' => $this->ws->token()));

        $this->assertSame(self::POST_ONLY_REFUSAL, self::err($res), $res['body']);
        $this->assertSame((string)$this->groupId, $this->tagGroup());
    }

    /** [NEG] [ECP] */
    public function testSetTypeRefusesAPostWithoutToken(): void
    {
        $res = $this->ws->call('typetags.tags.setType', array('tag_id' => $this->tagId, 'typetag_id' => 0));

        $this->assertSame(self::MISSING_PARAM, self::err($res), $res['body']);
        $this->assertSame((string)$this->groupId, $this->tagGroup());
    }

    /** [NEG] typetag_id went into the SQL as sent */
    public function testSetTypeRefusesAGroupThatIsNoNumber(): void
    {
        $res = $this->ws->call('typetags.tags.setType', array(
            'tag_id' => $this->tagId, 'typetag_id' => '0 OR 1=1', 'pwg_token' => $this->ws->token()));

        $this->assertSame(self::INVALID_PARAM, self::err($res), $res['body']);
        $this->assertSame((string)$this->groupId, $this->tagGroup());
    }

    /** [HAPPY] */
    public function testSetTypeTakesAPostWithToken(): void
    {
        $res = $this->ws->call('typetags.tags.setType', array(
            'tag_id' => $this->tagId, 'typetag_id' => 0, 'pwg_token' => $this->ws->token()));

        $this->assertSame('ok', $res['json']['stat'] ?? null, $res['body']);
        $this->assertNull($this->tagGroup());
    }

    // ── the plugin page's forms ───────────────────────────────────────────

    /** [NEG] the delete was a GET link; that link now does nothing */
    public function testTheOldDeleteLinkDeletesNothing(): void
    {
        $page = $this->ws->fetchPage(self::PAGE . '&deletetypetag=' . $this->groupId);

        $this->assertSame(200, $page['http_code']);
        $this->assertNotSame(array(), $this->group());
        $this->assertSame((string)$this->groupId, $this->tagGroup());
    }

    /** [NEG] */
    public function testDeleteRefusesAPostWithoutToken(): void
    {
        $res = $this->ws->postPage(self::PAGE, array('deletetypetag' => $this->groupId));

        $this->assertSame(self::MISSING_TOKEN_STATUS, $res['http_code']);
        $this->assertNotSame(array(), $this->group());
        $this->assertSame((string)$this->groupId, $this->tagGroup());
    }

    /** [HAPPY] the form the page renders deletes the group and frees its tags */
    public function testTheDeleteFormDeletesTheGroup(): void
    {
        $html = $this->ws->fetchPage(self::PAGE)['body'];
        $this->assertStringContainsString('name="deletetypetag" value="' . $this->groupId . '"', $html);
        $this->assertMatchesRegularExpression('/name="pwg_token" value="' . preg_quote($this->ws->token(), '/') . '"/', $html);

        $res = $this->ws->postPage(self::PAGE, array('deletetypetag' => $this->groupId, 'pwg_token' => $this->ws->token()));

        $this->assertSame(200, $res['http_code']);
        $this->assertSame(array(), $this->group());
        $this->assertNull($this->tagGroup());
    }

    /** [NEG] */
    public function testEditRefusesAPostWithoutToken(): void
    {
        $before = $this->group();
        $res = $this->ws->postPage(self::PAGE, array('edittypetag' => 1, 'edited_typetag' => $this->groupId,
            'typetag_name' => self::FIXTURE_PREFIX . 'renamed', 'typetag_color' => '#123456', 'typetag_emoji' => ''));

        $this->assertSame(self::MISSING_TOKEN_STATUS, $res['http_code']);
        $this->assertSame($before, $this->group());
    }

    /** [NEG] */
    public function testEditRefusesAWrongToken(): void
    {
        $before = $this->group();
        $res = $this->ws->postPage(self::PAGE, array('edittypetag' => 1, 'edited_typetag' => $this->groupId,
            'typetag_name' => self::FIXTURE_PREFIX . 'renamed', 'typetag_color' => '#123456', 'typetag_emoji' => '',
            'pwg_token' => str_repeat('0', 32)));

        $this->assertNotSame(200, $res['http_code']);
        $this->assertSame($before, $this->group());
    }

    /** [NEG] */
    public function testAddRefusesAPostWithoutToken(): void
    {
        $name = self::FIXTURE_PREFIX . 'form_no_token';
        $res = $this->ws->postPage(self::PAGE, array('addtypetag' => 1, 'typetag_name' => $name, 'typetag_color' => '#123456'));

        $this->assertSame(self::MISSING_TOKEN_STATUS, $res['http_code']);
        $this->assertSame(0, $this->groupRows($name));
    }

    /** [NEG] the configuration form, the page's fourth write */
    public function testConfigurationRefusesAPostWithoutToken(): void
    {
        // the opposite of the stored setting, so a form that got through would change it
        $opposite = str_contains((string)$this->config, 'b:1;') ? 'false' : 'true';
        $res = $this->ws->postPage(self::PAGE, array('save_config' => 1, 'show_all' => $opposite));

        $this->assertSame(self::MISSING_TOKEN_STATUS, $res['http_code']);
        $this->assertSame($this->config, $this->configValue());
    }
}
