<?php
use PHPUnit\Framework\TestCase;

/**
 * Structural guard: every file the plugin needs at runtime is committed.
 *
 * WHY. A runtime file that exists on this machine but was never `git add`ed works
 * perfectly here and is absent from every fresh clone. Nothing else in the pyramid
 * can see that: the unit suite reads the working tree, and the integration and E2E
 * suites drive the working tree through a web server. All three stay green while a
 * clone of the same commit is broken — the plugin would fail on include, or render
 * a page with no template.
 *
 * The plugin is vendored as a git submodule, so this is the failure mode Phase 7 of
 * the 2026-08-28 plan exists to prevent: the superproject pins a commit, and whatever
 * that commit does not carry does not reach anyone else.
 *
 * The runtime file list is DISCOVERED from production source rather than transcribed
 * here — a second hand-typed copy would rot the day a new include is added.
 */
final class CleanCheckoutTest extends TestCase
{
    /**
     * Lower bound against a scan that matches nothing. Measured 2026-08-29: the
     * include graph resolves 5 targets (3 PHP includes, 2 .tpl templates).
     */
    private const MIN_RUNTIME_TARGETS = 4;

    /**
     * Lower bound against `git ls-files` returning an empty set — which it does,
     * silently and with exit code 0, when run outside a work tree.
     * Measured 2026-08-29: 154 tracked files.
     */
    private const MIN_TRACKED_FILES = 100;

    /**
     * Files Piwigo's plugin loader and installer require by convention, so they
     * never appear in the include graph scanned below.
     * `main.inc.php` is the plugin (admin/include/plugins.class.php also parses its
     * metadata header out of the first 2048 bytes); `maintain.class.php` carries
     * install()/uninstall().
     */
    private const LOADER_ENTRY_POINTS = array('main.inc.php', 'maintain.class.php');

    private static ?array $tracked = null;

    protected function setUp(): void
    {
        if (self::$tracked === null)
        {
            self::$tracked = $this->trackedFiles();
        }

        if (self::$tracked === array())
        {
            $this->markTestSkipped(
                'Not a git work tree (a plugin installed from a zip has no repository), '
                . 'so committed-ness cannot be observed here.'
                );
        }
    }

    /** Paths tracked by git, relative to the plugin root. */
    private function trackedFiles(): array
    {
        $cmd = 'git -C ' . escapeshellarg(rtrim(TYPETAGS_PATH, '/'))
            . ' ls-files 2>/dev/null';

        exec($cmd, $out, $status);

        return $status === 0 ? $out : array();
    }

    /**
     * Every path the plugin includes or renders at runtime, read out of production
     * source: `TYPETAGS_PATH . '<relative path>'`. Covers both the PHP include graph
     * and the Smarty templates handed to set_filename().
     */
    private function runtimeTargets(): array
    {
        $targets = array();

        foreach ($this->pluginPhpSources() as $file)
        {
            if (preg_match_all(
                "/TYPETAGS_PATH\s*\.\s*'([^']+)'/",
                file_get_contents($file),
                $matches
                ))
            {
                $targets = array_merge($targets, $matches[1]);
            }
        }

        sort($targets);

        return array_values(array_unique($targets));
    }

    /** Tracked PHP sources, excluding the test suite itself. */
    private function pluginPhpSources(): array
    {
        $sources = array();

        foreach (self::$tracked as $relative)
        {
            if (substr($relative, -4) === '.php' and strpos($relative, 'tests/') !== 0)
            {
                $sources[] = TYPETAGS_PATH . $relative;
            }
        }

        return $sources;
    }

    public function testEveryRuntimeIncludeTargetIsCommitted(): void
    {
        foreach ($this->runtimeTargets() as $relative)
        {
            $this->assertFileExists(
                TYPETAGS_PATH . $relative,
                $relative . ' is included at runtime but missing from the working tree'
                );
            $this->assertContains(
                $relative,
                self::$tracked,
                $relative . ' is included at runtime but is NOT tracked by git - '
                . 'it would be absent from a fresh clone'
                );
        }
    }

    public function testLoaderEntryPointsAreCommitted(): void
    {
        foreach (self::LOADER_ENTRY_POINTS as $relative)
        {
            $this->assertContains(
                $relative,
                self::$tracked,
                $relative . ' is required by Piwigo\'s plugin loader but is NOT tracked by git'
                );
        }
    }

    public function testNoRuntimeFileIsGitIgnored(): void
    {
        // .gitignore carries /vendor/, /node_modules/ and friends. An unanchored or
        // over-broad pattern added later could swallow a runtime path.
        //
        // --no-index is load-bearing, and its absence made this test vacuous when it
        // was first written (found 2026-08-29 by the mutant that appends a runtime
        // filename to .gitignore, which survived). git check-ignore skips TRACKED
        // files by default, so without it the only path that could fail here is one
        // that is both ignored and untracked - which
        // testEveryRuntimeIncludeTargetIsCommitted already catches. With --no-index the
        // rules themselves are queried, which is the distinct thing this test claims:
        // a pattern matching a runtime path is a trap even while the tracked file keeps
        // clones working, because the next runtime file added under it is silently lost.
        $runtime = array_merge($this->runtimeTargets(), self::LOADER_ENTRY_POINTS);

        foreach ($runtime as $relative)
        {
            $cmd = 'git -C ' . escapeshellarg(rtrim(TYPETAGS_PATH, '/'))
                . ' check-ignore -q --no-index ' . escapeshellarg($relative);

            exec($cmd, $out, $status);

            // check-ignore exits 0 when the path IS ignored, 1 when it is not.
            $this->assertSame(
                1,
                $status,
                $relative . ' is matched by .gitignore but is needed at runtime'
                );
        }
    }

    public function testGuardFixtureIsNotVacuous(): void
    {
        // Anti-vacuity, two ways. Without the first, a regex that stops matching makes
        // the loops above iterate zero times and pass. Without the second, `git ls-files`
        // run outside a work tree returns an empty set with exit code 0, and every
        // assertContains above would fail loudly rather than pass - but the skip in
        // setUp() would have swallowed the run instead, hiding a broken checkout.
        $this->assertGreaterThanOrEqual(
            self::MIN_RUNTIME_TARGETS,
            count($this->runtimeTargets()),
            'the include-graph scan matched almost nothing - the pattern has rotted'
            );
        $this->assertGreaterThanOrEqual(
            self::MIN_TRACKED_FILES,
            count(self::$tracked),
            'git ls-files returned an implausibly small set'
            );
    }
}
