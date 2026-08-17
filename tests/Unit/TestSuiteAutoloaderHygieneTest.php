<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * #825 — a test that registers a global autoloader must remove it.
 *
 * `spl_autoload_register()` mutates the PHP process, not the test. A test that
 * registers one and walks away has re-pointed a namespace for every test that
 * runs after it, and the damage does not surface where it was done: it surfaces
 * thousands of tests later, in an unrelated file, as `Cannot redeclare class` —
 * a FATAL that ends the run rather than failing a case. That is what made #825
 * expensive. `tests/Unit`, `tests/Integration` and `tests/OpenAPI` are separate
 * CI jobs, so the leak never met the class it shadowed in CI and only ever bit
 * the contributor running the suites together before pushing, exactly as
 * CONTRIBUTING asks.
 *
 * Scoped to `tests/` deliberately. A long-lived autoloader is legitimate in
 * production code — {@see \Whity\Core\PluginLoader} registers one for the life
 * of the worker on purpose — but nothing in a test suite has a reason to leave
 * one behind.
 *
 * The scan is token-based rather than a text search because this repository's
 * own fix generates the registering code as a string, to be run in a child
 * process. Reading that string as a call is precisely the false positive that
 * would make a guard like this untrustworthy.
 */
final class TestSuiteAutoloaderHygieneTest extends TestCase
{
    public function testNoTestLeavesAnAutoloaderRegisteredInTheProcess(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach ($this->phpFilesUnder($root . '/tests') as $path) {
            $source = (string) file_get_contents($path);

            // Cheap reject first: the overwhelming majority of files never
            // mention it at all, and tokenising the whole suite is not free.
            if (!str_contains($source, 'spl_autoload_register')) {
                continue;
            }

            $called = $this->functionCallsIn($source);
            if (in_array('spl_autoload_register', $called, true)
                && !in_array('spl_autoload_unregister', $called, true)) {
                $offenders[] = ltrim(str_replace($root, '', $path), '/\\');
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These tests register a process-wide autoloader and never remove it. Capture the "
            . "closure and spl_autoload_unregister() it in tearDown(). If the class it loads "
            . "shares a name with one the suite loads from elsewhere, unregistering is not "
            . "enough — the declaration outlives the autoloader, so load it in a child "
            . "process (see DesktopPluginReleaseServiceTest).\nOffenders:\n  "
            . implode("\n  ", $offenders)
        );
    }

    /**
     * Names of functions this source actually CALLS. A bare name followed by an
     * opening parenthesis, so a mention inside a string, a comment or a heredoc
     * is never mistaken for a call.
     *
     * @return list<string>
     */
    private function functionCallsIn(string $source): array
    {
        $tokens = token_get_all($source);
        $names = [];

        foreach ($tokens as $index => $token) {
            if (!is_array($token)) {
                continue;
            }
            if (!in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }

            // Next significant token must be `(`.
            for ($next = $index + 1; $next < count($tokens); $next++) {
                $candidate = $tokens[$next];
                if (is_array($candidate) && in_array($candidate[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                if ($candidate === '(') {
                    $names[] = strtolower(ltrim($token[1], '\\'));
                }
                break;
            }
        }

        return array_values(array_unique($names));
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $dir): array
    {
        $files = [];
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
            /** @var SplFileInfo $item */
            if ($item->isFile() && $item->getExtension() === 'php') {
                $files[] = $item->getPathname();
            }
        }
        sort($files);

        return $files;
    }
}
