<?php
use PHPUnit\Framework\TestCase;

/**
 * The Colored Tags screens the handbook photographs render German.
 *
 * Four strings this plugin emits were untranslated. Three now resolve through
 * the fork's local override (local/language/de_DE.lang.php); 'Create' was a raw
 * French literal with no |translate filter, and wrapping it was the whole fix,
 * because core's admin.lang.php already translates that key.
 *
 * The three admin cases read a page whose compiled template embeds this
 * plugin's tags.tpl verbatim, injected by a prefilter. Smarty's compile_id
 * hashes only the callback name, so an edit to that template leaves the cached
 * compile in place and this suite keeps reading the old wording - see "Clear
 * _data/templates_c/ after editing a Smarty prefilter" in
 * .claude/rules/plugin-test-suites.md. Measured 2026-08-31: against a stale
 * cache, restoring the raw 'Creer' literal did not turn these red.
 *
 * Scope: server-rendered source. The remove control is built at runtime by the
 * injected JavaScript, but the title it carries is a server-rendered string
 * literal inside that script, so this layer can witness the wording. Whether
 * the control appears is E2E's job and is not restated here.
 */
final class GermanScreenTest extends TestCase
{
    /** A rendered admin or picture page shorter than this is an error page or a redirect. */
    private const MIN_PAGE_BYTES = 2000;

    private Db $db;
    private WsClient $ws;
    private FixtureBuilder $fixtures;

    protected function setUp(): void
    {
        $this->db = new Db();
        $this->ws = new WsClient();
        $this->fixtures = new FixtureBuilder($this->db);

        $this->ws->login(Config::username(), Config::password());
    }

    protected function tearDown(): void
    {
        $this->fixtures->restore();
        $this->ws->logout();
    }

    private function page(string $path): string
    {
        $res = $this->ws->fetchPage($path);

        $this->assertSame(200, (int)$res['http_code'], $path . ' did not load');

        $body = (string)$res['body'];
        $this->assertGreaterThan(
            self::MIN_PAGE_BYTES,
            strlen($body),
            'anti-vacuity: ' . $path . ' rendered too little for the assertions below to mean anything'
        );

        return $body;
    }

    private function adminTagsPage(): string
    {
        $this->assertGreaterThan(
            0,
            (int)$this->db->scalar('SELECT COUNT(*) FROM piwigo_typetags'),
            'anti-vacuity: with no colours configured the injected form would prove nothing'
        );

        return $this->page('/admin.php?page=tags');
    }

    /**
     * A picture page showing both a colour the photo carries and one it does
     * not, so the "+" badge and the "x" control are both emitted.
     */
    private function picturePageWithBothStates(): string
    {
        $imageId = $this->fixtures->anyImageId();
        $state = $this->fixtures->someAssignedSomeUnassigned($imageId);

        $this->assertNotEmpty($state['assigned'], 'anti-vacuity: no assigned tag means no remove control');
        $this->assertNotEmpty($state['unassigned'], 'anti-vacuity: no unassigned tag means no add badge');

        $categoryId = $this->fixtures->categoryIdFor($imageId);

        return $this->page("/picture.php?/{$imageId}/category/{$categoryId}");
    }

    /** [HAPPY] The colour-removal control on the injected admin form reads German. */
    public function testTheAdminFormRendersRemoveColorInGerman(): void
    {
        $body = $this->adminTagsPage();

        $this->assertStringContainsString('Farbe entfernen', $body);
        $this->assertStringNotContainsString('Remove color', $body);
    }

    /**
     * [HAPPY] The create button reads German.
     *
     * It carried a raw 'Créer' with no |translate filter, so no language file
     * could reach it. Wrapping it was the whole fix: core's admin.lang.php
     * already translates 'Create', in German and in French alike.
     */
    public function testTheAdminFormRendersTheCreateButtonInGerman(): void
    {
        $body = $this->adminTagsPage();

        $this->assertStringContainsString('id="TypetagsCreate" class="typetag-button icon-plus">Erstellen<', $body);
        $this->assertStringNotContainsString('Créer', $body);
    }

    /** [HAPPY] The colour button the plugin injects into the core tag list reads German. */
    public function testTheInjectedColourButtonReadsGerman(): void
    {
        $body = $this->adminTagsPage();

        $this->assertStringContainsString('<button id="TypetagsChangeColor" class="icon-brush">Farbe</button>', $body);
        $this->assertStringNotContainsString('Couleur', $body);
    }

    /** [HAPPY] The "+" badge that assigns a colour carries a German tooltip. */
    public function testTheAddBadgeCarriesAGermanTooltip(): void
    {
        $body = $this->picturePageWithBothStates();

        $this->assertStringContainsString('title="Schlagwort hinzufügen"', $body);
        $this->assertStringNotContainsString('Add tag', $body);
    }

    /** [HAPPY] The "x" control that removes a colour carries a German tooltip. */
    public function testTheRemoveControlCarriesAGermanTooltip(): void
    {
        $body = $this->picturePageWithBothStates();

        $this->assertStringContainsString('title="Schlagwort entfernen"', $body);
        $this->assertStringNotContainsString('Remove tag', $body);
    }
}
