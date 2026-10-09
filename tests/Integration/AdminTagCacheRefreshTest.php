<?php
use PHPUnit\Framework\TestCase;

/**
 * Browsers keep core's admin tag list in localStorage, keyed on
 * MAX(tags.lastmodified) and the tag count (get_admin_client_cache_keys()).
 * A list cached while pwg.tags.getAdminList still answered badge HTML would
 * outlive the fix, so update() touches the coloured tags once. update()
 * runs on every request for a "Version: auto" plugin (autoupdate_plugin()),
 * so loading a page is the update, and a config row keeps it to one touch.
 */
final class AdminTagCacheRefreshTest extends TestCase
{
    private const LONG_AGO = '2001-01-01 00:00:00';

    private string $marker;
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $coloredTagId;
    private int $plainTagId;
    /** tag id => lastmodified before the test, for every tag a page load may touch */
    private array $savedTimes = array();
    private ?string $savedMarker;

    protected function setUp(): void
    {
        // maintain.class.php needs core loaded, so its constant is read from the source
        $source = file_get_contents(TYPETAGS_PATH . 'maintain.class.php');
        $this->assertSame(1, preg_match("/const ADMIN_CACHE_REFRESHED = '([a-z_]+)';/", $source, $m));
        $this->marker = $m[1];

        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);
        $this->coloredTagId = $this->fixtures->createStripedGroup()['tag_id'];
        $this->plainTagId = $this->fixtures->ensurePlainTagId();

        $res = $this->db->query("SELECT id, lastmodified FROM piwigo_tags WHERE id_typetags IS NOT NULL OR id = {$this->plainTagId}");
        while ($row = $res->fetch_assoc())
        {
            $this->savedTimes[(int)$row['id']] = $row['lastmodified'];
        }
        $this->savedMarker = $this->db->scalar("SELECT value FROM piwigo_config WHERE param = '" . $this->marker . "'");
    }

    protected function tearDown(): void
    {
        foreach ($this->savedTimes as $id => $time)
        {
            $value = $time === null ? 'NULL' : "'$time'";
            $this->db->query("UPDATE piwigo_tags SET lastmodified = $value WHERE id = $id");
        }
        $this->db->query("DELETE FROM piwigo_config WHERE param = '" . $this->marker . "'");
        if ($this->savedMarker !== null)
        {
            $this->db->query("INSERT INTO piwigo_config (param, value) VALUES ('" . $this->marker . "', '" . $this->db->escape($this->savedMarker) . "')");
        }
        $this->fixtures->restore();
    }

    private function backdate(int $tagId): void
    {
        $this->db->query("UPDATE piwigo_tags SET lastmodified = '" . self::LONG_AGO . "' WHERE id = $tagId");
        $this->assertSame(self::LONG_AGO, $this->lastModified($tagId));
    }

    private function lastModified(int $tagId): string
    {
        return (string)$this->db->scalar("SELECT lastmodified FROM piwigo_tags WHERE id = $tagId");
    }

    private function loadAPage(): void
    {
        $res = $this->ws->fetchPage('/index.php', false);
        $this->assertSame(200, $res['http_code']);
    }

    /** [ST] not yet refreshed → first request touches → second request leaves alone */
    public function testUpdateTouchesTheColouredTagsOnce(): void
    {
        $this->db->query("DELETE FROM piwigo_config WHERE param = '" . $this->marker . "'");
        $this->backdate($this->coloredTagId);
        $this->backdate($this->plainTagId);

        $this->loadAPage();
        $this->assertGreaterThan(self::LONG_AGO, $this->lastModified($this->coloredTagId));
        $this->assertSame(self::LONG_AGO, $this->lastModified($this->plainTagId), 'a tag in no group was never badged');
        $this->assertNotNull($this->db->scalar("SELECT value FROM piwigo_config WHERE param = '" . $this->marker . "'"));

        $this->backdate($this->coloredTagId);
        $this->loadAPage();
        $this->assertSame(self::LONG_AGO, $this->lastModified($this->coloredTagId), 'the refresh happens once, not on every request');
    }
}
