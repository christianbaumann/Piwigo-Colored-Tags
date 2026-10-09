<?php
use PHPUnit\Framework\TestCase;

/**
 * An account may type in at most TYPETAGS_NEW_TAGS_PER_DAY new tags in 24
 * hours. piwigo_tags.id is smallint unsigned, so unlimited creation by any
 * logged-in account could use up every id of the install. Linking a tag that
 * exists is never refused.
 *
 * Requirement: plan 2026-10-09 "Tags in the image file", owner's answer Q16.
 */
final class CreationCapTest extends TestCase
{
    /** A minute past the window, and a minute inside it. */
    private const OUTSIDE_WINDOW_MINUTES = 24 * 60 + 1;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private string $suffix;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);
        $this->ws->login(Config::normalUsername(), Config::normalPassword());

        $this->imageId = $this->fixtures->testImageId();
        $this->fixtures->imageWithNoTags($this->imageId);
        $this->suffix = bin2hex(random_bytes(4));
        $this->assertGreaterThan(0, TYPETAGS_NEW_TAGS_PER_DAY);
    }

    protected function tearDown(): void
    {
        $this->fixtures->trackTagNamed($this->typedName());
        $this->fixtures->trackTagNamed($this->typedName() . ' 2');
        $this->fixtures->restore();
        $this->ws->logout();
    }

    /** [BVA] The last tag of the allowance is created, and counted. */
    public function testTheLastTagOfTheDayIsCreated(): void
    {
        $this->fixtures->seedTypedTagCreations(Config::normalUsername(), TYPETAGS_NEW_TAGS_PER_DAY - 1);

        $res = $this->addNewTag($this->typedName());

        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertTrue($res['json']['result']['created']);
        // the creation itself counts: the next new name is one too many
        $next = $this->addNewTag($this->typedName() . ' 2');
        $this->assertSame(429, $next['json']['err'] ?? null, 'Got: ' . $next['body']);
    }

    /** [BVA] One more is refused, and no tag is created. */
    public function testOneMoreIsRefused(): void
    {
        $this->fixtures->seedTypedTagCreations(Config::normalUsername(), TYPETAGS_NEW_TAGS_PER_DAY);

        $res = $this->addNewTag($this->typedName());

        $this->assertSame('fail', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertSame(429, $res['json']['err']);
        $this->assertSame(0, $this->tagCount($this->typedName()));
    }

    /** [ECP] At the cap an existing name is still linked: nothing is created. */
    public function testAnExistingNameIsStillLinkedAtTheCap(): void
    {
        $striped = $this->fixtures->createStripedGroup();
        $name = (string)$this->db->scalar('SELECT name FROM piwigo_tags WHERE id = ' . $striped['tag_id']);
        $this->fixtures->seedTypedTagCreations(Config::normalUsername(), TYPETAGS_NEW_TAGS_PER_DAY);

        $res = $this->addNewTag($name);

        $this->assertSame('ok', $res['json']['stat'] ?? null, 'Got: ' . $res['body']);
        $this->assertFalse($res['json']['result']['created']);
        $this->assertSame(array($striped['tag_id']), $this->fixtures->assignedTagIds($this->imageId));
    }

    /** [BVA] Creations older than 24 hours no longer count. */
    public function testCreationsOlderThanADayDoNotCount(): void
    {
        $this->fixtures->seedTypedTagCreations(Config::normalUsername(), TYPETAGS_NEW_TAGS_PER_DAY, self::OUTSIDE_WINDOW_MINUTES);

        $this->assertSame('ok', $this->addNewTag($this->typedName())['json']['stat'] ?? null);
    }

    /** [ECP] Another account's creations are its own. */
    public function testAnotherAccountsCreationsDoNotCount(): void
    {
        $this->fixtures->seedTypedTagCreations(Config::username(), TYPETAGS_NEW_TAGS_PER_DAY);

        $this->assertSame('ok', $this->addNewTag($this->typedName())['json']['stat'] ?? null);
    }

    private function typedName(): string
    {
        return 'Kirmes ' . $this->suffix;
    }

    private function addNewTag(string $name): array
    {
        return $this->ws->call('typetags.image.addNewTag', array(
            'image_id' => $this->imageId,
            'tag_name' => $name,
            'pwg_token' => $this->ws->token(),
        ));
    }

    private function tagCount(string $name): int
    {
        return (int)$this->db->scalar("SELECT COUNT(*) FROM piwigo_tags WHERE name = '" . $this->db->escape($name) . "'");
    }
}
