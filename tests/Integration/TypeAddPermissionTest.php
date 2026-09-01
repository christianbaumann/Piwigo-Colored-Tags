<?php
use PHPUnit\Framework\TestCase;

/**
 * Permission and input bounds of typetags.type.add over the real ws.php endpoint.
 *
 * The method shipped with no options array at all, so core's admin_only check
 * (include/ws_core.inc.php:514) never ran and any anonymous caller could insert
 * a colour row. It also passed the name straight to single_insert(), so a name
 * longer than the varchar(255) column threw an uncaught mysqli_sql_exception
 * and rendered a stack trace into the HTTP response.
 *
 * Every [NEG] case asserts the row was not written as well as the status: a
 * method that refuses and inserts anyway would pass a status-only assertion.
 */
final class TypeAddPermissionTest extends TestCase
{
    private const FIXTURE_PREFIX = '_test_type_add_permission_';

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
        // LIKE, not '=': a row leaked by a failing case must not survive either.
        // The prefix's own underscores are escaped, or LIKE would read them as
        // single-character wildcards and the pattern would delete more rows
        // than this test ever created.
        $pattern = $this->db->escape(
            str_replace('_', '\_', self::FIXTURE_PREFIX) . '%'
        );
        $this->db->query("DELETE FROM piwigo_typetags WHERE name LIKE '$pattern'");
        $this->ws->logout();
    }

    private function countRows(string $name): int
    {
        $escaped = $this->db->escape($name);
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_typetags WHERE name = '$escaped'"
        );
    }

    /** A name nothing else in the install can collide with. */
    private function uniqueName(string $suffix): string
    {
        return self::FIXTURE_PREFIX . $suffix;
    }

    /** A fixture name of exactly $length characters, still carrying the prefix. */
    private function nameOfLength(int $length): string
    {
        $this->assertGreaterThan(
            strlen(self::FIXTURE_PREFIX),
            $length,
            'fixture must be long enough to keep the prefix teardown deletes by'
        );
        $name = self::FIXTURE_PREFIX . str_repeat('a', $length - strlen(self::FIXTURE_PREFIX));
        $this->assertSame($length, mb_strlen($name), 'fixture length must be exact');
        return $name;
    }

    // ── Access control ────────────────────────────────────────────────────

    /** [NEG] */
    public function testAnAnonymousCallerIsRefused(): void
    {
        $name = $this->uniqueName('anon_post');

        // Third argument false suppresses the cookie jar, so this call carries
        // no session even though $this->ws is logged in (AddTagTest:109).
        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => $name,
            'typetag_color' => 'AABBCC',
        ), false);

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertSame(0, $this->countRows($name));
    }

    /**
     * [NEG] [ECP] The hole was reachable over GET, so the verb is its own
     * equivalence class. callGet() takes no $useCookies argument, so the
     * anonymous session comes from a fresh client that never logs in.
     */
    public function testAnAnonymousGetIsRefused(): void
    {
        $name = $this->uniqueName('anon_get');
        $anonymous = new WsClient();

        $res = $anonymous->callGet('typetags.type.add', array(
            'typetag_name' => $name,
            'typetag_color' => 'AABBCC',
        ));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertSame(0, $this->countRows($name));
    }

    /**
     * [NEG] [ECP] An admin gate is only proven by an authenticated non-admin
     * meeting it. Each WsClient owns its own cookie jar, so this is a second
     * client rather than a re-login of the first.
     */
    public function testAnAuthenticatedNonAdminIsRefused(): void
    {
        $name = $this->uniqueName('normal');
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());

        try
        {
            $res = $normal->call('typetags.type.add', array(
                'typetag_name' => $name,
                'typetag_color' => 'AABBCC',
            ));
        }
        finally
        {
            $normal->logout();
        }

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertSame(0, $this->countRows($name));
    }

    /**
     * [HAPPY] The only legitimate caller is an administrator on admin/tags.php.
     * Without this case the three [NEG] ones above would pass against a method
     * that refuses everybody.
     */
    public function testTheWebmasterCanStillCreateAColour(): void
    {
        $name = $this->uniqueName('webmaster');
        $this->assertSame(0, $this->countRows($name), 'precondition: colour does not exist');

        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => $name,
            'typetag_color' => 'AABBCC',
        ));

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1, $this->countRows($name));
    }

    // ── Name length ───────────────────────────────────────────────────────

    /** [BVA] [NEG] One character past the column width. */
    public function testAnOverLongNameIsRefusedWithoutAStackTrace(): void
    {
        $name = $this->nameOfLength(256);

        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => $name,
            'typetag_color' => 'AABBCC',
        ));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1003, $res['json']['err']);
        $this->assertSame(0, $this->countRows($name));
        $this->assertStringNotContainsString('mysqli_sql_exception', $res['body']);
        $this->assertStringNotContainsString('Stack trace', $res['body']);
    }

    /**
     * [BVA] [HAPPY] Exactly the column width. piwigo_typetags.name is
     * varchar(255) utf8mb3, which counts characters, not bytes (measured
     * against the live schema 2026-09-01) - so the bound is a character count.
     */
    public function testANameAtTheBoundIsAccepted(): void
    {
        $name = $this->nameOfLength(255);
        $this->assertSame(0, $this->countRows($name), 'precondition: colour does not exist');

        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => $name,
            'typetag_color' => 'AABBCC',
        ));

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1, $this->countRows($name));
    }

    // ── The validation that already existed ───────────────────────────────

    /** [NEG] The length check must not displace check_color(). */
    public function testAnInvalidColourIsStillRefusedForAnAdmin(): void
    {
        $name = $this->uniqueName('bad_colour');

        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => $name,
            'typetag_color' => 'ZZZZZZ',
        ));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1003, $res['json']['err']);
        $this->assertStringContainsString('color', strtolower($res['json']['message']));
        $this->assertSame(0, $this->countRows($name));
    }
}
