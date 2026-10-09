<?php
use PHPUnit\Framework\TestCase;

/**
 * typetags.type.list, typetags.type.update and the striped/emoji parameters
 * of typetags.type.add, over the real ws.php endpoint. The deploy's seed step
 * is their only caller besides the admin screens.
 *
 * Every [NEG] case on update also asserts the row did not change.
 */
final class TypeListUpdateTest extends TestCase
{
    private const FIXTURE_PREFIX = '_test_type_list_update_';
    /** WS_ERR_INVALID_PARAM, include/ws_core.inc.php */
    private const INVALID_PARAM = 1003;

    private Db $db;
    private WsClient $ws;
    private int $groupId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->db->query("INSERT INTO piwigo_typetags (name, color) VALUES ('" . self::FIXTURE_PREFIX . "group', '#77A600')");
        $this->groupId = $this->db->insertId();
        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $pattern = $this->db->escape(str_replace('_', '\_', self::FIXTURE_PREFIX) . '%');
        $this->db->query("DELETE FROM piwigo_typetags WHERE name LIKE '$pattern'");
        $this->ws->logout();
    }

    private function row(int $id): array
    {
        $res = $this->db->query("SELECT name, color, striped, emoji FROM piwigo_typetags WHERE id = $id");
        return $res->fetch_assoc();
    }

    private function update(array $params): array
    {
        return $this->ws->call('typetags.type.update', $params + array(
            'typetag_id' => $this->groupId,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function assertUnchanged(): void
    {
        $this->assertSame(
            array('name' => self::FIXTURE_PREFIX . 'group', 'color' => '#77A600', 'striped' => '0', 'emoji' => ''),
            $this->row($this->groupId)
        );
    }

    // ── list ──────────────────────────────────────────────────────────────

    /** [HAPPY] */
    public function testListAnswersEveryGroupWithItsStyle(): void
    {
        $this->db->query("UPDATE piwigo_typetags SET striped = 1, emoji = '1F5BC FE0F' WHERE id = {$this->groupId}");

        $res = $this->ws->call('typetags.type.list');

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $groups = array_column($res['json']['result'], null, 'id');
        $this->assertSame(
            (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_typetags'),
            count($groups)
        );
        $this->assertSame(
            array('id' => $this->groupId, 'name' => self::FIXTURE_PREFIX . 'group', 'color' => '#77A600', 'striped' => true, 'emoji' => '1F5BC FE0F'),
            $groups[$this->groupId]
        );
    }

    /** [NEG] */
    public function testListRefusesANormalAccountAndAGuest(): void
    {
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());
        try
        {
            $this->assertSame(401, $normal->call('typetags.type.list')['json']['err']);
        }
        finally
        {
            $normal->logout();
        }

        $this->assertSame(401, $this->ws->call('typetags.type.list', array(), false)['json']['err']);
    }

    // ── update ────────────────────────────────────────────────────────────

    /** [HAPPY] */
    public function testUpdateChangesColourStripesAndEmoji(): void
    {
        $res = $this->update(array('typetag_color' => 'd00000', 'striped' => 'true', 'emoji' => "\u{1F5BC}\u{FE0F}"));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(
            array('name' => self::FIXTURE_PREFIX . 'group', 'color' => '#d00000', 'striped' => '1', 'emoji' => '1F5BC FE0F'),
            $this->row($this->groupId)
        );
        $this->assertSame(true, $res['json']['result']['striped']);
        $this->assertSame('1F5BC FE0F', $res['json']['result']['emoji']);
    }

    /** [ECP] a parameter left out keeps its value */
    public function testUpdateLeavesOutWhatIsNotGiven(): void
    {
        $this->db->query("UPDATE piwigo_typetags SET striped = 1, emoji = '270D' WHERE id = {$this->groupId}");

        $res = $this->update(array('typetag_color' => '#e4e6e3'));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $row = $this->row($this->groupId);
        $this->assertSame(array('#e4e6e3', '1', '270D'), array($row['color'], $row['striped'], $row['emoji']));
    }

    /** [BVA] an empty emoji removes it, striped=false clears the flag */
    public function testUpdateCanClearTheEmojiAndTheStripes(): void
    {
        $this->db->query("UPDATE piwigo_typetags SET striped = 1, emoji = '270D' WHERE id = {$this->groupId}");

        $res = $this->update(array('striped' => 'false', 'emoji' => ''));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertUnchanged();
    }

    /** [NEG] */
    public function testUpdateRefusesAnUnknownGroup(): void
    {
        $unknown = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_typetags') + 1000;

        $res = $this->update(array('typetag_id' => $unknown, 'typetag_color' => 'd00000'));

        $this->assertSame('fail', $res['json']['stat']);
        $this->assertSame(404, $res['json']['err']);
        $this->assertUnchanged();
    }

    /** [NEG] */
    public function testUpdateRefusesAWrongToken(): void
    {
        $res = $this->update(array('pwg_token' => 'wrong', 'typetag_color' => 'd00000'));

        $this->assertSame(403, $res['json']['err']);
        $this->assertUnchanged();
    }

    /** [NEG] */
    public function testUpdateRefusesGet(): void
    {
        $res = $this->ws->callGet('typetags.type.update', array(
            'typetag_id' => $this->groupId,
            'typetag_color' => 'd00000',
            'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame('fail', $res['json']['stat']);
        $this->assertUnchanged();
    }

    /** [NEG] */
    public function testUpdateRefusesANormalAccount(): void
    {
        $normal = new WsClient();
        $normal->login(Config::normalUsername(), Config::normalPassword());
        try
        {
            $res = $normal->call('typetags.type.update', array(
                'typetag_id' => $this->groupId,
                'typetag_color' => 'd00000',
                'pwg_token' => $normal->token(),
            ));
            $this->assertSame(401, $res['json']['err']);
        }
        finally
        {
            $normal->logout();
        }
        $this->assertUnchanged();
    }

    /** [NEG] */
    public function testUpdateRefusesAnInvalidEmoji(): void
    {
        $res = $this->update(array('emoji' => 'hello'));

        $this->assertSame(self::INVALID_PARAM, $res['json']['err']);
        $this->assertStringContainsString('emoji', $res['json']['message']);
        $this->assertUnchanged();
    }

    /** [NEG] */
    public function testUpdateRefusesAnInvalidColour(): void
    {
        $res = $this->update(array('typetag_color' => 'ZZZZZZ'));

        $this->assertSame(self::INVALID_PARAM, $res['json']['err']);
        $this->assertUnchanged();
    }

    // ── add ───────────────────────────────────────────────────────────────

    /** [HAPPY] */
    public function testAddStoresStripesAndEmoji(): void
    {
        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => self::FIXTURE_PREFIX . 'added',
            'typetag_color' => 'd00000',
            'striped' => 'true',
            'emoji' => 'U+270D U+FE0F',
        ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $this->assertSame(true, $res['json']['result']['striped']);
        $this->assertSame('270D FE0F', $res['json']['result']['emoji']);
        $row = $this->row((int)$res['json']['result']['id']);
        $this->assertSame(array('1', '270D FE0F'), array($row['striped'], $row['emoji']));
    }

    /** [ECP] without the new parameters a group is plain, as before */
    public function testAddWithoutTheNewParametersIsPlain(): void
    {
        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => self::FIXTURE_PREFIX . 'plain',
            'typetag_color' => '77A600',
        ));

        $this->assertSame('ok', $res['json']['stat'], $res['body']);
        $row = $this->row((int)$res['json']['result']['id']);
        $this->assertSame(array('0', ''), array($row['striped'], $row['emoji']));
    }

    /** [NEG] */
    public function testAddRefusesAnInvalidEmoji(): void
    {
        $res = $this->ws->call('typetags.type.add', array(
            'typetag_name' => self::FIXTURE_PREFIX . 'bad_emoji',
            'typetag_color' => 'd00000',
            'emoji' => '110000',
        ));

        $this->assertSame(self::INVALID_PARAM, $res['json']['err']);
        $this->assertSame(0, (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_typetags WHERE name = '" . self::FIXTURE_PREFIX . "bad_emoji'"));
    }
}
