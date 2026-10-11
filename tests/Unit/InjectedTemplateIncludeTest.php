<?php
use PHPUnit\Framework\TestCase;

require_once TYPETAGS_PATH . 'include/events_admin.inc.php';

/**
 * The admin prefilter references template/tags.tpl, it does not copy it.
 *
 * Smarty decides whether a compiled template is current from the mtime of the
 * template it compiled - core's admin tags.tpl - and never from a file a
 * prefilter read while compiling it. Content pasted in by the prefilter is
 * frozen into the compiled page until someone empties _data/templates_c/ by
 * hand (decision 0036). An {include} is compiled as a template of its own and
 * checked on its own mtime.
 */
final class InjectedTemplateIncludeTest extends TestCase
{
    /** Shorter than this, a file is a stub or a failed read and "not pasted in" says nothing. */
    private const MIN_TPL_BYTES = 100;

    private function readFile(string $path): string
    {
        $content = (string)file_get_contents($path);
        $this->assertGreaterThan(
            self::MIN_TPL_BYTES,
            strlen($content),
            'anti-vacuity: too little was read from ' . $path
        );

        return $content;
    }

    private function prefilteredCoreTemplate(): string
    {
        return typetags_admin_prefilter($this->readFile(PIWIGO_ROOT . 'admin/themes/default/template/tags.tpl'));
    }

    private function pluginTemplatePath(): string
    {
        $path = realpath(TYPETAGS_PATH . 'template/tags.tpl');
        $this->assertNotFalse($path, 'template/tags.tpl is gone');

        return $path;
    }

    /**
     * [HAPPY] Core's tags page gets exactly one include of the file, by
     * absolute path, so it resolves whatever directory Smarty compiles from.
     */
    public function testThePrefilterIncludesTheTemplateFileOnce(): void
    {
        $path = $this->pluginTemplatePath();

        $this->assertSame(
            1,
            preg_match_all('/\{include file=([\'"])' . preg_quote($path, '/') . '\1\}/', $this->prefilteredCoreTemplate()),
            'typetags_admin_prefilter does not include template/tags.tpl exactly once'
        );
    }

    /**
     * [NEG] The file's content is not in the output, which is what froze it
     * into the compiled page.
     */
    public function testThePrefilterDoesNotPasteTheTemplateFileIn(): void
    {
        $fileContent = $this->readFile($this->pluginTemplatePath());

        $this->assertStringNotContainsString($fileContent, $this->prefilteredCoreTemplate());
    }
}
