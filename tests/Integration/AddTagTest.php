<?php
use PHPUnit\Framework\TestCase;

/**
 * typetags.image.addTag over the real ws.php endpoint.
 *
 * Ports the addTag half of test_ws_tag_assignment.php and closes the gaps
 * that file's coverage map exposed: boundary values on both ids, a counted
 * idempotency check instead of a presence check, and the image validation
 * added in Phase 2.
 */
final class AddTagTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private int $coloredTagId;
    private ?int $usedNonexistentImageId = null;

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

        // Force the tag unassigned: every case below is about what happens on
        // the way in, so none of them may start from an already-assigned tag.
        $this->imageId = $this->fixtures->anyImageId();
        $this->fixtures->imageWithNoTags($this->imageId);
    }

    protected function tearDown(): void
    {
        // A defect can write the very row a test set out to prove absent
        // (orphan rows survive addTag's missing image validation); clean it
        // up so a later run isn't polluted by an earlier one.
        if ($this->usedNonexistentImageId !== null)
        {
            $this->db->query(
                "DELETE FROM piwigo_image_tag WHERE image_id = {$this->usedNonexistentImageId} AND tag_id = {$this->coloredTagId}"
            );
        }
        $this->fixtures->restore();
        $this->ws->logout();
    }

    private function addTag(array $overrides = array()): array
    {
        return $this->ws->call('typetags.image.addTag', $overrides + array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function assignmentCount(int $imageId, int $tagId): int
    {
        return (int)$this->db->scalar(
            "SELECT COUNT(*) FROM piwigo_image_tag WHERE image_id = $imageId AND tag_id = $tagId"
        );
    }

    private function nonexistentImageId(): int
    {
        $id = $this->fixtures->nonexistentImageId();

        // image_id is a mediumint unsigned (max 16777215, install/piwigo_structure-mysql.sql:206).
        // A value beyond that range gets silently clipped to the column max by
        // INSERT IGNORE rather than rejected, which would retarget this fixture
        // onto a row no assertion below looks at.
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

    // ── Happy path ────────────────────────────────────────────────────────

    /** [HAPPY] */
    public function testAssignsColouredTag(): void
    {
        $this->assertSame(0, $this->assignmentCount($this->imageId, $this->coloredTagId), 'precondition: not assigned');

        $res = $this->addTag();

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1, $this->assignmentCount($this->imageId, $this->coloredTagId));
    }

    // ── Access control ────────────────────────────────────────────────────

    /** [NEG] */
    public function testGuestIsRejected(): void
    {
        $res = $this->ws->call('typetags.image.addTag', array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => 'fake',
        ), false);

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(401, $res['json']['err']);
        $this->assertSame(0, $this->assignmentCount($this->imageId, $this->coloredTagId));
    }

    /** [NEG] */
    public function testBadTokenIsRejected(): void
    {
        $res = $this->addTag(array('pwg_token' => 'wrong_token_value'));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(403, $res['json']['err']);
        $this->assertSame(0, $this->assignmentCount($this->imageId, $this->coloredTagId));
    }

    /**
     * [BVA] An empty token is rejected one layer earlier than a wrong one:
     * ws.php treats '' as an absent parameter, so this is WS_ERR_MISSING_PARAM
     * (1002), not the 403 the handler would return. Recorded as it behaves.
     */
    public function testEmptyTokenIsRejected(): void
    {
        $res = $this->addTag(array('pwg_token' => ''));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1002, $res['json']['err']);
        $this->assertSame(0, $this->assignmentCount($this->imageId, $this->coloredTagId));
    }

    /** [BVA] */
    public function testMissingTokenParameterIsRejected(): void
    {
        $res = $this->ws->call('typetags.image.addTag', array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
        ));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1002, $res['json']['err']);
        $this->assertStringContainsString('pwg_token', $res['json']['message']);
    }

    // ── Tag validation ────────────────────────────────────────────────────

    /** [NEG] */
    public function testNonColouredTagIsRejected(): void
    {
        $plainTagId = $this->fixtures->ensurePlainTagId();

        $res = $this->addTag(array('tag_id' => $plainTagId));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(404, $res['json']['err']);
        $this->assertSame(0, $this->assignmentCount($this->imageId, $plainTagId));
    }

    /** [NEG] */
    public function testNonexistentTagIsRejected(): void
    {
        $nonexistent = (int)$this->db->scalar('SELECT MAX(id) FROM piwigo_tags') + 1000;
        $this->assertNull(
            $this->db->scalar("SELECT 1 FROM piwigo_tags WHERE id = $nonexistent"),
            'fixture tag id must not exist'
        );

        $res = $this->addTag(array('tag_id' => $nonexistent));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }

    /**
     * [BVA] Zero is rejected by WS_TYPE_ID's positive-integer check before the
     * handler runs, so this is WS_ERR_INVALID_PARAM (1003) rather than the 404
     * a nonexistent tag gets.
     */
    public function testZeroTagIdIsRejected(): void
    {
        $res = $this->addTag(array('tag_id' => 0));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1003, $res['json']['err']);
    }

    /** [BVA] */
    public function testNegativeTagIdIsRejected(): void
    {
        $res = $this->addTag(array('tag_id' => -1));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1003, $res['json']['err']);
    }

    // ── Image validation (the Phase 2 defect) ─────────────────────────────

    /** [NEG] */
    public function testNonexistentImageIsRejected(): void
    {
        $res = $this->addTag(array('image_id' => $this->nonexistentImageId()));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(404, $res['json']['err']);
    }

    /** [NEG] */
    public function testNonexistentImageWritesNoOrphanRow(): void
    {
        $imageId = $this->nonexistentImageId();

        $this->addTag(array('image_id' => $imageId));

        $count = $this->db->scalar("SELECT COUNT(*) FROM piwigo_image_tag WHERE image_id = $imageId");
        $this->assertSame('0', (string)$count);
    }

    /** [BVA] */
    public function testZeroImageIdIsRejected(): void
    {
        $res = $this->addTag(array('image_id' => 0));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1003, $res['json']['err']);
    }

    /** [BVA] */
    public function testNegativeImageIdIsRejected(): void
    {
        $res = $this->addTag(array('image_id' => -1));

        $this->assertSame('fail', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1003, $res['json']['err']);
    }

    // ── State transition ──────────────────────────────────────────────────

    /**
     * [ST] The original script asserted the row was still present after a
     * second add. Presence cannot distinguish "ignored" from "inserted twice";
     * the count can, and it is what PRIMARY KEY (image_id, tag_id) guarantees.
     */
    public function testDuplicateAddIsIdempotent(): void
    {
        $this->assertSame('ok', $this->addTag()['json']['stat']);

        $res = $this->addTag();

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1, $this->assignmentCount($this->imageId, $this->coloredTagId));
    }

    // ── Characterization ──────────────────────────────────────────────────

    /**
     * [ERR] Characterization: the oracle here is the code, not a requirement.
     * `post_only` is deliberately not set (decision: pwg_token is the CSRF
     * guard, and post_only would break external callers), so the method also
     * answers to GET. This test records that so a future change is visible;
     * it cannot find a defect.
     */
    public function testMethodAlsoAnswersToGet(): void
    {
        $res = $this->ws->callGet('typetags.image.addTag', array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => $this->ws->token(),
        ));

        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);
        $this->assertSame(1, $this->assignmentCount($this->imageId, $this->coloredTagId));
    }
}
