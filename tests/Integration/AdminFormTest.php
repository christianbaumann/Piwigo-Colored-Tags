<?php
use PHPUnit\Framework\TestCase;

/**
 * The plugin page's add and edit forms: the striped checkbox and the emoji
 * field, posted as a browser would.
 */
final class AdminFormTest extends TestCase
{
    private const FIXTURE_PREFIX = '_test_admin_form_';
    private const PAGE = '/admin.php?page=plugin-typetags';
    private const INVALID_EMOJI = '#Ungültiges Emoji|Invalid emoji#';

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
        $pattern = $this->db->escape(str_replace('_', '\_', self::FIXTURE_PREFIX) . '%');
        $this->db->query("DELETE FROM piwigo_typetags WHERE name LIKE '$pattern'");
        $this->ws->logout();
    }

    private function row(string $name): ?array
    {
        $escaped = $this->db->escape($name);
        $res = $this->db->query("SELECT id, color, striped, emoji FROM piwigo_typetags WHERE name = '$escaped'");
        return $res->fetch_assoc();
    }

    private function post(array $fields): string
    {
        $res = $this->ws->postPage(self::PAGE, $fields);
        $this->assertSame(200, $res['http_code']);
        return $res['body'];
    }

    /** [HAPPY] */
    public function testAddStoresStripesAndAPastedEmoji(): void
    {
        $name = self::FIXTURE_PREFIX . 'added';

        $this->post(array('addtypetag' => 1, 'typetag_name' => $name, 'typetag_color' => '#d00000',
            'typetag_striped' => 1, 'typetag_emoji' => "\u{1F5BC}\u{FE0F}"));

        $row = $this->row($name);
        $this->assertNotNull($row);
        $this->assertSame(array('#d00000', '1', '1F5BC FE0F'), array($row['color'], $row['striped'], $row['emoji']));
    }

    /** [ECP] an unticked box and an empty field */
    public function testAddWithoutStripesOrEmojiIsPlain(): void
    {
        $name = self::FIXTURE_PREFIX . 'plain';

        $this->post(array('addtypetag' => 1, 'typetag_name' => $name, 'typetag_color' => '#77A600', 'typetag_emoji' => ''));

        $row = $this->row($name);
        $this->assertNotNull($row);
        $this->assertSame(array('0', ''), array($row['striped'], $row['emoji']));
    }

    /** [NEG] */
    public function testAddRefusesAnInvalidEmoji(): void
    {
        $name = self::FIXTURE_PREFIX . 'bad';

        $html = $this->post(array('addtypetag' => 1, 'typetag_name' => $name, 'typetag_color' => '#d00000', 'typetag_emoji' => 'hello'));

        $this->assertMatchesRegularExpression(self::INVALID_EMOJI, $html);
        $this->assertNull($this->row($name));
    }

    /** [HAPPY] */
    public function testEditSetsAndClearsStripesAndEmoji(): void
    {
        $name = self::FIXTURE_PREFIX . 'edited';
        $this->db->query("INSERT INTO piwigo_typetags (name, color) VALUES ('$name', '#e4e6e3')");
        $id = $this->db->insertId();

        $this->post(array('edittypetag' => 1, 'edited_typetag' => $id, 'typetag_name' => $name,
            'typetag_color' => '#e4e6e3', 'typetag_striped' => 1, 'typetag_emoji' => '270D FE0F'));
        $this->assertSame(array('1', '270D FE0F'), array($this->row($name)['striped'], $this->row($name)['emoji']));

        $this->post(array('edittypetag' => 1, 'edited_typetag' => $id, 'typetag_name' => $name,
            'typetag_color' => '#e4e6e3', 'typetag_emoji' => ''));
        $this->assertSame(array('0', ''), array($this->row($name)['striped'], $this->row($name)['emoji']));
    }

    /** [NEG] */
    public function testEditRefusesAnInvalidEmoji(): void
    {
        $name = self::FIXTURE_PREFIX . 'edit_bad';
        $this->db->query("INSERT INTO piwigo_typetags (name, color, emoji) VALUES ('$name', '#e4e6e3', '270D')");
        $id = $this->db->insertId();

        $html = $this->post(array('edittypetag' => 1, 'edited_typetag' => $id, 'typetag_name' => $name,
            'typetag_color' => '#e4e6e3', 'typetag_emoji' => '110000'));

        $this->assertMatchesRegularExpression(self::INVALID_EMOJI, $html);
        $this->assertSame('270D', $this->row($name)['emoji']);
    }
}
