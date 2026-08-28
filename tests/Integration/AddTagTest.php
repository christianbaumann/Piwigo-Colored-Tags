<?php
use PHPUnit\Framework\TestCase;

/**
 * Phase 2 slice: only the two tests that reproduce and verify the
 * `addTag` image-validation defect. Phase 3 ports the remaining assertions
 * from test_ws_tag_assignment.php into this file.
 */
final class AddTagTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private int $coloredTagId;
    private ?int $usedNonexistentImageId = null;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->ws->login(Config::username(), Config::password());

        $coloredTagId = $this->db->scalar('SELECT id FROM piwigo_tags WHERE id_typetags IS NOT NULL LIMIT 1');
        if ($coloredTagId === null)
        {
            $this->markTestSkipped('No colored tag fixture available on this install');
        }
        $this->coloredTagId = (int)$coloredTagId;
    }

    protected function tearDown(): void
    {
        // A defect can write the very row this test set out to prove absent
        // (orphan rows survive addTag's missing image validation); clean it
        // up so a later run of this test isn't polluted by an earlier one.
        if ($this->usedNonexistentImageId !== null)
        {
            $this->db->query(
                "DELETE FROM piwigo_image_tag WHERE image_id = {$this->usedNonexistentImageId} AND tag_id = {$this->coloredTagId}"
            );
        }
        $this->ws->logout();
    }

    private function nonexistentImageId(): int
    {
        // image_id is a mediumint unsigned (max 16777215, install/piwigo_structure-mysql.sql:206).
        // A value beyond that range gets silently clipped to the column max
        // by INSERT IGNORE rather than rejected, which would corrupt this
        // fixture into testing the wrong id. Stay in range and pick a value
        // above every real image id instead.
        $maxRealId = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_images');
        $id = $maxRealId + 1000;
        $this->assertLessThanOrEqual(16777215, $id, 'fixture id must stay within mediumint unsigned range');

        // Anti-vacuity: confirm the fixture really doesn't exist, so the
        // rejection below isn't accidentally exercising a real image.
        $this->assertNull(
            $this->db->scalar("SELECT 1 FROM piwigo_images WHERE id = $id"),
            'fixture image id must not exist'
        );

        $this->usedNonexistentImageId = $id;
        return $id;
    }

    public function testNonexistentImageIsRejected(): void
    {
        $imageId = $this->nonexistentImageId();

        $res = $this->ws->call('typetags.image.addTag', array(
            'image_id' => $imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }

    public function testNonexistentImageWritesNoOrphanRow(): void
    {
        $imageId = $this->nonexistentImageId();

        $this->ws->call('typetags.image.addTag', array(
            'image_id' => $imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => $this->ws->token(),
        ));

        $count = $this->db->scalar("SELECT COUNT(*) FROM piwigo_image_tag WHERE image_id = $imageId");
        $this->assertSame('0', (string)$count);
    }
}
