<?php
use PHPUnit\Framework\TestCase;

/**
 * A user who read the tag list sees a tag come and go right after a + or x
 * (SES-2026-10-14-22 F1, SES-2026-10-13-02 F4).
 *
 * Core caches the tag list per user in $persistent_cache, keyed by the
 * user_cache row's cache_update_time (get_available_tags()). The methods used
 * to null nb_available_tags only, which leaves that key, so the list stayed
 * as it was; they now call invalidate_user_cache() (decision 0071, superseding
 * 0004).
 */
final class CacheInvalidationTest extends TestCase
{
    private const SENTINEL = 5;
    /** A rendered page shorter than this is an error page. */
    private const MIN_PAGE_BYTES = 2000;
    private const TAG_LIST = '/tags.php';

    private Db $db;
    private WsClient $ws;
    private WsClient $reader;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private int $tagId;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);
        $this->ws->login(Config::username(), Config::password());
        $this->reader = new WsClient();
        $this->reader->login(Config::normalUsername(), Config::normalPassword());

        $this->imageId = $this->fixtures->testImageId();
        $this->fixtures->recordTagCounts();
        $this->fixtures->imageWithNoTags($this->imageId);
        // a coloured tag on no photo, so the list shows it only through this photo
        $this->tagId = $this->fixtures->createStripedGroup()['tag_id'];
        $this->fixtures->invalidateUserCache();
    }

    protected function tearDown(): void
    {
        $this->fixtures->restore();
        $this->ws->logout();
        $this->reader->logout();
    }

    private function tagList(): string
    {
        $res = $this->reader->fetchPage(self::TAG_LIST);
        $body = (string)$res['body'];
        $this->assertSame(200, $res['http_code']);
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'the tag list did not render');
        $this->assertStringContainsString('tags/', $body, 'anti-vacuity: the list holds no tag at all');
        return $body;
    }

    private function listsTheTag(): bool
    {
        return str_contains($this->tagList(), FixtureBuilder::STRIPED_TAG_NAME);
    }

    private function call(string $method): void
    {
        $res = $this->ws->call($method, array(
            'image_id' => $this->imageId,
            'tag_id' => $this->tagId,
            'pwg_token' => $this->ws->token(),
        ));
        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
    }

    /** [ST] read without the tag, then + : listed at once */
    public function testAnAddedTagIsListedAtOnce(): void
    {
        $this->assertFalse($this->listsTheTag(), 'precondition: the reader has read a list without the tag');

        $this->call('typetags.image.addTag');

        $this->assertTrue($this->listsTheTag(), 'the list is stale after the +');
    }

    /** [ST] read with the tag, then x : gone at once */
    public function testARemovedTagLeavesTheListAtOnce(): void
    {
        $this->fixtures->givenAssigned($this->imageId, array($this->tagId));
        $this->fixtures->invalidateUserCache();
        $this->assertTrue($this->listsTheTag(), 'precondition: the reader has read a list with the tag');

        $this->call('typetags.image.removeTag');

        $this->assertFalse($this->listsTheTag(), 'the list is stale after the x');
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
