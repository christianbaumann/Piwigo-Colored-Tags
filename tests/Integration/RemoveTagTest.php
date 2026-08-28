<?php
use PHPUnit\Framework\TestCase;

/**
 * typetags.image.removeTag over the real ws.php endpoint.
 *
 * Ports the removeTag half of test_ws_tag_assignment.php and closes the gap
 * that file left: it never exercised removeTag with a nonexistent tag, and
 * never asserted a full round trip against the database.
 *
 * removeTag has no image-existence check on purpose — a DELETE on a
 * nonexistent image is already a no-op, so there is no reachable defect to
 * guard against. The asymmetry with addTag is deliberate (see main.inc.php).
 */
final class RemoveTagTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private int $coloredTagId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);
        $this->ws->login(Config::username(), Config::password());

        $colored = $this->fixtures->coloredTagIds();
        if (count($colored) === 0)
        {
            $this->markTestSkipped('No colored tag fixture available on this install');
        }
        $this->coloredTagId = $colored[0];

        $this->imageId = $this->fixtures->anyImageId();
        $this->fixtures->imageWithNoTags($this->imageId);
    }

    protected function tearDown(): void
    {
        $this->fixtures->restore();
        $this->ws->logout();
    }

    private function removeTag(array $overrides = array()): array
    {
        return $this->ws->call('typetags.image.removeTag', $overrides + array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function addTag(array $overrides = array()): array
    {
        return $this->ws->call('typetags.image.addTag', $overrides + array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function assignmentCount(int $tagId): int
    {
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_image_tag WHERE image_id = {$this->imageId} AND tag_id = $tagId"
        );
    }

    private function givenTagIsAssigned(): void
    {
        $this->db->query(
            "INSERT IGNORE INTO piwigo_image_tag (image_id, tag_id) VALUES ({$this->imageId}, {$this->coloredTagId})"
        );
        $this->assertSame(1, $this->assignmentCount($this->coloredTagId), 'precondition: tag assigned');
    }

    // ── Happy path ────────────────────────────────────────────────────────

    /** [HAPPY] */
    public function testRemovesAssignedTag(): void
    {
        $this->givenTagIsAssigned();

        $res = $this->removeTag();

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(0, $this->assignmentCount($this->coloredTagId));
    }

    // ── Access control ────────────────────────────────────────────────────

    /** [NEG] */
    public function testGuestIsRejected(): void
    {
        $this->givenTagIsAssigned();

        $res = $this->ws->call('typetags.image.removeTag', array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => 'fake',
        ), false);

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertSame(1, $this->assignmentCount($this->coloredTagId), 'a rejected call must not delete');
    }

    /** [NEG] */
    public function testBadTokenIsRejected(): void
    {
        $this->givenTagIsAssigned();

        $res = $this->removeTag(array('pwg_token' => 'wrong_token_value'));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(403, $res['json']['err']);
        $this->assertSame(1, $this->assignmentCount($this->coloredTagId), 'a rejected call must not delete');
    }

    // ── Tag validation ────────────────────────────────────────────────────

    /** [NEG] */
    public function testNonColouredTagIsRejected(): void
    {
        $plainTagId = $this->fixtures->ensurePlainTagId();
        $this->db->query(
            "INSERT IGNORE INTO piwigo_image_tag (image_id, tag_id) VALUES ({$this->imageId}, $plainTagId)"
        );
        $this->assertSame(1, $this->assignmentCount($plainTagId), 'precondition: plain tag assigned');

        $res = $this->removeTag(array('tag_id' => $plainTagId));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(404, $res['json']['err']);
        $this->assertSame(1, $this->assignmentCount($plainTagId), 'a non-colored tag must survive the call');
    }

    /** [NEG] Gap in the original script: only addTag was tested for this. */
    public function testNonexistentTagIsRejected(): void
    {
        $nonexistent = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_tags') + 1000;
        $this->assertNull(
            $this->db->scalar("SELECT 1 FROM piwigo_tags WHERE id = $nonexistent"),
            'fixture tag id must not exist'
        );

        $res = $this->removeTag(array('tag_id' => $nonexistent));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }

    // ── State transition ──────────────────────────────────────────────────

    /** [ST] */
    public function testRemoveWhenNotAssignedIsIdempotent(): void
    {
        $this->assertSame(0, $this->assignmentCount($this->coloredTagId), 'precondition: not assigned');

        $res = $this->removeTag();

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(0, $this->assignmentCount($this->coloredTagId));
    }

    /** [ST] unassigned -> assigned -> unassigned, verified in the database at each step. */
    public function testRoundTrip(): void
    {
        $this->assertSame(0, $this->assignmentCount($this->coloredTagId), 'start: unassigned');

        $this->assertSame('ok', $this->addTag()['json']['stat']);
        $this->assertSame(1, $this->assignmentCount($this->coloredTagId), 'after add: assigned');

        $this->assertSame('ok', $this->removeTag()['json']['stat']);
        $this->assertSame(0, $this->assignmentCount($this->coloredTagId), 'after remove: unassigned again');
    }
}
