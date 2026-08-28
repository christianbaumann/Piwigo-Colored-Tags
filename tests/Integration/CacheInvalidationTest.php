<?php
use PHPUnit\Framework\TestCase;

/**
 * Both write methods null piwigo_user_cache.nb_available_tags so the tag
 * count is recomputed on the next page load.
 *
 * The invalidation is deliberately unscoped (UPDATE with no WHERE) — over-
 * invalidation is safe and correctly scoping it would mean computing which
 * users can see the image. Recorded as accepted, not fixed.
 */
final class CacheInvalidationTest extends TestCase
{
    private const SENTINEL = 5;

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
        $this->fixtures->recordTagCounts();
        $this->fixtures->imageWithNoTags($this->imageId);
    }

    protected function tearDown(): void
    {
        $this->fixtures->restore();
        $this->ws->logout();
    }

    /**
     * Forces a non-null cache value and asserts it took effect, so the
     * "is it null now" assertion below cannot pass over a value that was
     * already null before the call.
     */
    private function givenTheTagCountCacheIsPopulated(): void
    {
        if ((int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_user_cache') === 0)
        {
            $this->markTestSkipped('No piwigo_user_cache rows on this install');
        }

        $this->db->query('UPDATE piwigo_user_cache SET nb_available_tags = ' . self::SENTINEL);

        $this->assertSame(
            0,
            (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_user_cache WHERE nb_available_tags IS NULL'),
            'precondition: no cached count may be null before the call'
        );
    }

    private function assertTagCountCacheIsInvalidated(): void
    {
        $this->assertSame(
            0,
            (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_user_cache WHERE nb_available_tags IS NOT NULL'),
            'every cached tag count should have been nulled'
        );
    }

    /** [ST] */
    public function testAddNullsAvailableTagCount(): void
    {
        $this->givenTheTagCountCacheIsPopulated();

        $res = $this->ws->call('typetags.image.addTag', array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);

        $this->assertTagCountCacheIsInvalidated();
    }

    /** [ST] Gap in the original script: only addTag's invalidation was tested. */
    public function testRemoveNullsAvailableTagCount(): void
    {
        $this->db->query(
            "INSERT IGNORE INTO piwigo_image_tag (image_id, tag_id) VALUES ({$this->imageId}, {$this->coloredTagId})"
        );
        $this->givenTheTagCountCacheIsPopulated();

        $res = $this->ws->call('typetags.image.removeTag', array(
            'image_id' => $this->imageId,
            'tag_id' => $this->coloredTagId,
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'], 'Got: ' . $res['body']);

        $this->assertTagCountCacheIsInvalidated();
    }

    /**
     * [ERR] The script this suite replaces set nb_available_tags = 5 and never
     * put it back, so every run left the install a little further from where
     * it started. The fixture builder records the column and restores it; this
     * asserts that it actually does.
     */
    public function testCacheIsRestoredAfterRun(): void
    {
        $before = $this->db->scalar('SELECT nb_available_tags FROM piwigo_user_cache ORDER BY user_id LIMIT 1');

        $this->db->query('UPDATE piwigo_user_cache SET nb_available_tags = ' . self::SENTINEL);
        $this->assertSame(
            (string)self::SENTINEL,
            (string)$this->db->scalar('SELECT nb_available_tags FROM piwigo_user_cache ORDER BY user_id LIMIT 1'),
            'the mutation this test undoes must actually have happened'
        );

        $this->fixtures->restore();

        $this->assertSame(
            $before === null ? null : (string)$before,
            ($after = $this->db->scalar('SELECT nb_available_tags FROM piwigo_user_cache ORDER BY user_id LIMIT 1')) === null ? null : (string)$after
        );
    }
}
