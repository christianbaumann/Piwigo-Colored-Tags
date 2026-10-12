<?php
use PHPUnit\Framework\TestCase;

/**
 * index.php?/taggroups: the colour groups, read-only, for every logged-in
 * account. Lists each group with its colour, stripes and emoji, and only the
 * tags the account can see, as core's tags page counts them.
 *
 * Requirement: change 2026-10-11-role-permissions, design "Normal users get
 * read-only data pages, not copies of admin screens", task 10.
 *
 * Scope: server-rendered source. That the badge is painted striped in a
 * browser, and that the tags page link leads here, is E2E's job.
 */
final class TagGroupsPageTest extends TestCase
{
    /** A rendered gallery page shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;

    /** The group list alone, for one fixture group with one tag. */
    private const MIN_GROUPS_BYTES = 200;

    private const PAGE = '/index.php?/taggroups';

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private array $striped;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);

        $this->striped = $this->fixtures->createStripedGroup();
        $imageId = $this->fixtures->testImageId();
        $this->fixtures->imageWithNoTags($imageId);
        $this->fixtures->givenAssigned($imageId, array($this->striped['tag_id']));
        $this->fixtures->invalidateUserCache();
    }

    protected function tearDown(): void
    {
        $this->fixtures->restore();
        $this->ws->logout();
    }

    /** [HAPPY] A normal account sees the group's colour, stripes and emoji, and its tag as a badge. */
    public function testANormalAccountSeesTheGroupWithItsTag(): void
    {
        $group = $this->groupBlock($this->groupsOfNormal(), $this->striped['group_id']);

        $this->assertStringContainsString('data-color="' . FixtureBuilder::STRIPED_GROUP_COLOR . '"', $group);
        $this->assertStringContainsString('data-striped="1"', $group);
        $this->assertStringContainsString(typetags_swatch(FixtureBuilder::STRIPED_GROUP_COLOR, true), $group);
        $this->assertStringContainsString(typetags_emoji_html(FixtureBuilder::STRIPED_GROUP_EMOJI), $group);
        $this->assertStringContainsString('data-tag-id="' . $this->striped['tag_id'] . '"', $group);
        $this->assertStringContainsString(
            'style="' . typetags_badge_style(FixtureBuilder::STRIPED_GROUP_COLOR, true) . '"',
            $group
        );
    }

    /** [ECP] Every group in the table is listed, with or without a visible tag. */
    public function testEveryGroupIsListed(): void
    {
        $groups = $this->groupsOfNormal();

        $expected = (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_typetags');
        $this->assertGreaterThan(0, $expected, 'anti-vacuity: the fixture group exists');
        $this->assertSame($expected, substr_count($groups, 'data-group-id="'));
    }

    /** [NEG] A tag only on a photo in a private album is not listed; its group still is. */
    public function testATagOnlyInAPrivateAlbumIsNotListed(): void
    {
        $this->fixtures->makeTestAlbumPrivate();

        $group = $this->groupBlock($this->groupsOfNormal(), $this->striped['group_id']);

        $this->assertStringNotContainsString('data-tag-id="' . $this->striped['tag_id'] . '"', $group);
        $this->assertStringContainsString('typetags-group-empty', $group);
    }

    /** [ECP] Granted the album, the same account sees the tag again. */
    public function testATagInAGrantedPrivateAlbumIsListed(): void
    {
        $this->fixtures->makeTestAlbumPrivate();
        $this->fixtures->grantTestAlbumTo(Config::normalUsername());

        $group = $this->groupBlock($this->groupsOfNormal(), $this->striped['group_id']);

        $this->assertStringContainsString('data-tag-id="' . $this->striped['tag_id'] . '"', $group);
    }

    /** [NEG] The page offers nothing to change: no form and no control. */
    public function testThePageHasNoFormOrControl(): void
    {
        $groups = $this->groupsOfNormal();

        foreach (array('<form', '<input', '<button', '<select', '<textarea') as $control)
        {
            $this->assertStringNotContainsString($control, $groups);
        }
    }

    /** [HAPPY] The page and the tags page's link to it read German. */
    public function testTheTagsPageLinksToThePageInGerman(): void
    {
        $german = $this->germanStrings();
        $this->ws->login(Config::normalUsername(), Config::normalPassword());

        $tags = $this->page('/tags.php');
        $this->assertMatchesRegularExpression(
            '#<p id="typetags-groups-link"[^>]*><a href="[^"]*index\.php\?/taggroups">'
                . preg_quote($german['Tag groups'], '#') . '</a></p>#',
            $tags
        );
        $this->assertStringContainsString($german['Tag groups'] . '</h2>', $this->page(self::PAGE));
    }

    private function groupsOfNormal(): string
    {
        $this->ws->login(Config::normalUsername(), Config::normalPassword());
        $body = $this->page(self::PAGE);

        $start = strpos($body, '<div id="typetags-groups">');
        $end = strpos($body, '</div> <!-- content -->');
        $this->assertNotFalse($start, 'the group list is missing');
        $this->assertNotFalse($end, 'the content end is missing');
        $groups = substr($body, $start, $end - $start);
        $this->assertGreaterThan(self::MIN_GROUPS_BYTES, strlen($groups), 'anti-vacuity: the group list is empty');
        return $groups;
    }

    private function groupBlock(string $groups, int $groupId): string
    {
        $pattern = '#<div class="typetags-group" data-group-id="' . $groupId . '".*?(?=<div class="typetags-group" |\z)#s';
        $this->assertSame(1, preg_match($pattern, $groups, $m), "group $groupId is not listed");
        return $m[0];
    }

    private function page(string $path): string
    {
        $res = $this->ws->fetchPage($path);
        $this->assertSame(200, (int)$res['http_code'], $path . ' did not load');
        $body = (string)$res['body'];
        $this->assertGreaterThan(self::MIN_PAGE_BYTES, strlen($body), 'anti-vacuity: ' . $path . ' rendered too little');
        return $body;
    }

    /** The plugin's own German file, never a second copy of its wording. */
    private function germanStrings(): array
    {
        $lang = array();
        include TYPETAGS_PATH . 'language/de_DE/plugin.lang.php';
        $this->assertArrayHasKey('Tag groups', $lang);
        return $lang;
    }
}
