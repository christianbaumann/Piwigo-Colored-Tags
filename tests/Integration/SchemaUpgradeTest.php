<?php
use PHPUnit\Framework\TestCase;

/**
 * The striped and emoji columns arrive through install(), which core runs
 * as update() on every request for a "Version: auto" plugin
 * (include/functions_plugins.inc.php, autoupdate_plugin()). So dropping the
 * columns and loading any page is the upgrade an existing install goes
 * through, and loading a second page is install() running again over it.
 */
final class SchemaUpgradeTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private array $saved = array();

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $res = $this->db->query('SELECT id, striped, emoji FROM piwigo_typetags');
        while ($row = $res->fetch_assoc())
        {
            $this->saved[] = $row;
        }
    }

    protected function tearDown(): void
    {
        // Put back what a group had before the columns were dropped.
        foreach ($this->saved as $row)
        {
            $emoji = $this->db->escape($row['emoji']);
            $this->db->query("UPDATE piwigo_typetags SET striped = {$row['striped']}, emoji = '$emoji' WHERE id = {$row['id']}");
        }
    }

    private function columns(): array
    {
        $names = array();
        $res = $this->db->query('SHOW COLUMNS FROM piwigo_typetags');
        while ($row = $res->fetch_assoc())
        {
            $names[] = $row['Field'];
        }
        return $names;
    }

    private function loadAPage(): void
    {
        $res = $this->ws->fetchPage('/index.php', false);
        $this->assertSame(200, $res['http_code']);
        $this->assertStringNotContainsString('Duplicate column', $res['body']);
    }

    /** [ST] old schema → first request → second request */
    public function testInstallAddsTheColumnsOnceAndKeepsTheRows(): void
    {
        $rowsBefore = (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_typetags');
        $this->assertGreaterThan(0, $rowsBefore, 'the upgrade must run over existing groups');

        $this->db->query('ALTER TABLE piwigo_typetags DROP COLUMN striped, DROP COLUMN emoji');
        $this->assertSame(array('id', 'name', 'color'), $this->columns());

        $this->loadAPage();
        $this->assertSame(array('id', 'name', 'color', 'striped', 'emoji'), $this->columns());
        $this->assertSame($rowsBefore, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_typetags'));
        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_typetags WHERE striped <> 0 OR emoji <> ''"));

        $this->loadAPage();
        $this->assertSame(array('id', 'name', 'color', 'striped', 'emoji'), $this->columns());
        $this->assertSame($rowsBefore, (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_typetags'));
    }
}
