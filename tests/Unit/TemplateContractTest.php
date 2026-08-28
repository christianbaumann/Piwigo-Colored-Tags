<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard: the prefilter couples to literal template text.
 *
 * WHY. str_replace on a non-match is a no-op that returns the input unchanged.
 * If picture.tpl moves, or modus grows its own copy, both replacements silently
 * do nothing: no error, no warning, and the whole feature disappears from the
 * page. No other test in this suite can see that — the integration tests would
 * report a page that renders fine and simply has no assignment UI on it.
 */
final class TemplateContractTest extends TestCase
{
    /** Lower bound against a scan that reads nothing. Measured 2026-08-28: 331 lines. */
    private const MIN_TEMPLATE_BYTES = 1000;

    private function pictureTemplate(): string
    {
        return file_get_contents(PIWIGO_ROOT . 'themes/default/template/picture.tpl');
    }

    public function testPictureTemplateStillContainsBothPrefilterTargets(): void
    {
        $src = $this->pictureTemplate();
        $this->assertSame(1, substr_count($src, TYPETAGS_TPL_TAG_ANCHOR));
        $this->assertSame(1, substr_count($src, TYPETAGS_TPL_INJECT_POINT));
    }

    public function testNoChildThemeShadowsPictureTemplate(): void
    {
        // modus declares parent=default (themes/modus/themeconf.inc.php:12).
        // A modus-owned picture.tpl would shadow the parent and break both replacements.
        $this->assertFileDoesNotExist(PIWIGO_ROOT . 'themes/modus/template/picture.tpl');
    }

    public function testGuardFixtureIsNotVacuous(): void
    {
        // Anti-vacuity. Without this, the test above stays green when the file
        // moves and the read returns nothing.
        $this->assertGreaterThan(self::MIN_TEMPLATE_BYTES, strlen($this->pictureTemplate()));
    }
}
