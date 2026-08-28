<?php
use PHPUnit\Framework\TestCase;

/**
 * Automates the Phase 2 manual check: "with a deliberately corrupted
 * typetags.color value, the picture page renders instead of white-screening".
 *
 * WHY THIS IS NOT A DUPLICATE of GetColorTextTest::testMalformedLengthReturnsSafeDefault.
 * The unit test proves one function stops throwing. This proves the whole
 * request path survives a malformed value that is actually sitting in the
 * database — i.e. that get_color_text() was the only thing that choked on it.
 * `typetags.color` is varchar(255) with no constraint, so the corrupt value
 * this test writes is a state the live schema genuinely permits.
 */
final class MalformedColorRenderingTest extends TestCase
{
    private const MALFORMED_COLOR = '#12345';
    private const PICTURE_PATH = '/picture.php?/1/category/1';

    private Db $db;
    private WsClient $ws;
    private ?int $typetagId = null;
    private ?string $originalColor = null;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();

        $row = $this->db->query('SELECT id, color FROM piwigo_typetags ORDER BY id LIMIT 1')->fetch_assoc();
        if ($row === null)
        {
            $this->markTestSkipped('No typetags rows on this install');
        }

        $this->typetagId = (int)$row['id'];
        $this->originalColor = $row['color'];

        // Anti-vacuity: if the stored colour were already malformed, this test
        // would pass without ever changing anything.
        $this->assertSame(
            7,
            strlen((string)$this->originalColor),
            'baseline colour must be well-formed, or corrupting it proves nothing'
        );

        $escaped = self::MALFORMED_COLOR;
        $this->db->query("UPDATE piwigo_typetags SET color = '$escaped' WHERE id = {$this->typetagId}");

        $this->assertSame(
            self::MALFORMED_COLOR,
            $this->db->scalar("SELECT color FROM piwigo_typetags WHERE id = {$this->typetagId}"),
            'fixture must actually have corrupted the colour'
        );
    }

    protected function tearDown(): void
    {
        if ($this->typetagId !== null and $this->originalColor !== null)
        {
            $escaped = $this->db->escape($this->originalColor);
            $this->db->query("UPDATE piwigo_typetags SET color = '$escaped' WHERE id = {$this->typetagId}");
        }
    }

    private function assertPageIsHealthy(array $res, string $context): void
    {
        $this->assertSame(200, $res['http_code'], "$context: expected HTTP 200");
        $this->assertStringNotContainsString('Fatal error', $res['body'], "$context: page contains a fatal error");
        $this->assertStringNotContainsString('TypeError', $res['body'], "$context: page contains a TypeError");
        $this->assertStringNotContainsString('Smarty Compiler', $res['body'], "$context: Smarty compiler error");
    }

    public function testGuestPicturePageRendersWithMalformedColor(): void
    {
        $this->assertPageIsHealthy($this->ws->fetchPage(self::PICTURE_PATH, false), 'guest');
    }

    public function testLoggedInPicturePageRendersWithMalformedColor(): void
    {
        // The logged-in path is the one that runs typetags_picture_tags(),
        // and so the partition + get_color_text() over every colored tag.
        $this->ws->login(Config::username(), Config::password());
        $this->assertPageIsHealthy($this->ws->fetchPage(self::PICTURE_PATH), 'logged-in');
        $this->ws->logout();
    }
}
