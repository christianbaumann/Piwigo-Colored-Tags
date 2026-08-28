<?php
use PHPUnit\Framework\TestCase;

/**
 * What the server-rendered picture page contains.
 *
 * Scope: page source only. The remove button is built at runtime by the
 * injected JavaScript, so no assertion here can witness it — that belongs to
 * the E2E layer and is not restated here.
 */
final class PicturePageSourceTest extends TestCase
{
    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;
    private int $imageId;
    private string $picturePath;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);

        $this->imageId = $this->fixtures->anyImageId();
        $categoryId = $this->fixtures->categoryIdFor($this->imageId);
        $this->picturePath = "/picture.php?/{$this->imageId}/category/{$categoryId}";
    }

    protected function tearDown(): void
    {
        $this->fixtures->restore();
        $this->ws->logout();
    }

    private function loggedInPage(): string
    {
        $this->ws->login(Config::username(), Config::password());
        $res = $this->ws->fetchPage($this->picturePath);
        $this->assertSame(200, $res['http_code'], 'picture page must load before its source can be asserted on');
        return $res['body'];
    }

    /**
     * The page with every <script> block removed.
     *
     * WHY. The injected JavaScript builds both containers as string literals,
     * so `<div id="Tags" ...>` and `<div id="typetags-unassigned" ...>` occur
     * in the page source even when the server rendered neither. Scanning the
     * raw body finds the JS copy and reports an element that is not there —
     * this layer is about what the server rendered, so the script goes first.
     */
    private function markup(string $html): string
    {
        return preg_replace('#<script\b.*?</script>#is', '', $html);
    }

    /**
     * Anti-vacuity for the two "element is absent" tests: a markup() that
     * stripped too much would make any absence assertion pass. The info table
     * is rendered on every picture page and never inside a script.
     */
    private function assertMarkupSurvivedStripping(string $html): void
    {
        $this->assertStringContainsString('<dl id="standard"', $this->markup($html));
    }

    /** The injected unassigned-tag container, or '' when it is absent. */
    private function unassignedSection(string $html): string
    {
        return preg_match('#<div id="typetags-unassigned".*?</div>#s', $this->markup($html), $m) ? $m[0] : '';
    }

    /** The theme's #Tags row, or '' when the image has no tags at all. */
    private function tagsSection(string $html): string
    {
        return preg_match('#<div id="Tags".*?</div>#s', $this->markup($html), $m) ? $m[0] : '';
    }

    // ── Smoke ─────────────────────────────────────────────────────────────

    /** [HAPPY] */
    public function testPageReturnsTwoHundredForLoggedInUser(): void
    {
        $this->fixtures->someAssignedSomeUnassigned($this->imageId);

        $this->ws->login(Config::username(), Config::password());
        $res = $this->ws->fetchPage($this->picturePath);

        $this->assertSame(200, $res['http_code']);
    }

    /** [NEG] */
    public function testPageHasNoFatalError(): void
    {
        $this->fixtures->someAssignedSomeUnassigned($this->imageId);

        $this->assertStringNotContainsString('Fatal error', $this->loggedInPage());
    }

    /** [NEG] */
    public function testPageHasNoSmartyCompilerError(): void
    {
        $this->fixtures->someAssignedSomeUnassigned($this->imageId);

        $this->assertStringNotContainsString('Smarty Compiler', $this->loggedInPage());
    }

    /**
     * [ERR] Regression guard: the prefilter was once registered twice, which
     * injected the whole IIFE twice and fired every click handler twice.
     */
    public function testExactlyOneScriptBlockIsInjected(): void
    {
        $this->fixtures->someAssignedSomeUnassigned($this->imageId);

        $this->assertSame(1, substr_count($this->loggedInPage(), ';(function()'));
    }

    // ── Unassigned badges ─────────────────────────────────────────────────

    /**
     * [ERR] Anti-vacuity for the count below: a fixture that produced no
     * unassigned tags would let a "count matches" assertion pass over zero.
     */
    public function testFixtureProducesAtLeastOneUnassignedTag(): void
    {
        $state = $this->fixtures->someAssignedSomeUnassigned($this->imageId);

        $this->assertGreaterThan(0, count($state['unassigned']));
    }

    /**
     * [ECP] Replaces the assertion at test_ws_tag_assignment.php:379, whose
     * condition ended in a literal true disjunct and so could never fail —
     * it survived both mutations that kill this one. Given K unassigned tags,
     * the page must carry exactly K add badges — no more, no fewer.
     */
    public function testUnassignedBadgeCountMatchesFixture(): void
    {
        $state = $this->fixtures->someAssignedSomeUnassigned($this->imageId);
        $expected = count($state['unassigned']);
        $this->assertGreaterThan(0, $expected, 'anti-vacuity: the fixture must leave something unassigned');

        $section = $this->unassignedSection($this->loggedInPage());

        $this->assertNotSame('', $section, '#typetags-unassigned should be present');
        $this->assertSame($expected, substr_count($section, 'class="typetag-badge typetag-add"'));
    }

    /** [BVA] State B: every colored tag assigned, so the container is not rendered. */
    public function testAllAssignedRendersNoUnassignedSection(): void
    {
        $state = $this->fixtures->allColoredAssigned($this->imageId);
        $this->assertGreaterThan(0, count($state['assigned']), 'anti-vacuity: something must have been assigned');

        $html = $this->loggedInPage();
        $this->assertMarkupSurvivedStripping($html);
        $this->assertSame('', $this->unassignedSection($html));
    }

    // ── Assigned badges ───────────────────────────────────────────────────

    /**
     * [HAPPY] Replaces the tautological assertion at
     * test_ws_tag_assignment.php:388. That one looked for the string
     * `typetag-remove`, which occurs only inside the injected JavaScript and
     * would therefore be found on a page with no assigned tags at all. What
     * page source can actually witness is the data-tag-id the prefilter adds
     * to each anchor — one per assigned colored tag.
     */
    public function testAssignedColouredTagsRenderAsTaggedAnchors(): void
    {
        $state = $this->fixtures->someAssignedSomeUnassigned($this->imageId);
        $expected = count($state['assigned']);
        $this->assertGreaterThan(0, $expected, 'anti-vacuity: the fixture must assign something');

        $section = $this->tagsSection($this->loggedInPage());

        $this->assertNotSame('', $section, '#Tags should be present');
        $this->assertSame($expected, substr_count($section, 'data-tag-id="'));
        foreach ($state['assigned'] as $tagId)
        {
            $this->assertStringContainsString('data-tag-id="' . $tagId . '"', $section);
        }
    }

    /** [BVA] State C's precondition: with no tags at all the theme renders no #Tags row. */
    public function testImageWithNoTagsRendersNoTagsRow(): void
    {
        $this->fixtures->imageWithNoTags($this->imageId);
        $this->assertSame(array(), $this->fixtures->assignedTagIds($this->imageId), 'precondition: image has no tags');

        $html = $this->loggedInPage();
        $this->assertMarkupSurvivedStripping($html);
        $this->assertSame('', $this->tagsSection($html));
    }

    // ── Guests ────────────────────────────────────────────────────────────

    /** [NEG] */
    public function testGuestSeesNoAssignmentUi(): void
    {
        $this->fixtures->someAssignedSomeUnassigned($this->imageId);

        $res = $this->ws->fetchPage($this->picturePath, false);

        $this->assertSame(200, $res['http_code']);
        $this->assertStringNotContainsString('Fatal error', $res['body']);
        $this->assertStringNotContainsString('typetags-unassigned', $res['body']);
        $this->assertStringNotContainsString('typetag-add', $res['body']);
    }
}
