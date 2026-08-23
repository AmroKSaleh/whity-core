<?php

declare(strict_types=1);

namespace Tests\Ops;

use PHPUnit\Framework\TestCase;

/**
 * The `composer phpstan` script must survive a cold analysis.
 *
 * #934 made that script the single definition of how PHPStan is invoked — CI,
 * `scripts/ci-local.sh` and any hand-run all go through it — which immediately
 * exposed it to a Composer default nobody had needed before: scripts are killed
 * at **300 seconds**. A warm result cache finishes well inside that, so the
 * change looked fine in CI and on any machine that had run PHPStan recently. A
 * COLD analyse does not, and it fails with
 *
 *     The following exception is caused by a process timeout
 *
 * which says nothing about PHPStan and appears only for the person least likely
 * to have context: someone running the checks for the first time.
 *
 * `Composer\Config::disableProcessTimeout` removes the cap for this script only,
 * rather than raising `config.process-timeout` globally — a per-script opt-out
 * is what Composer documents for long-running commands, and it keeps the cap on
 * everything else, where a hang really should be interrupted.
 */
final class ComposerPhpstanScriptTest extends TestCase
{
    /** @return array<string, mixed> */
    private function composerJson(): array
    {
        $raw = file_get_contents(dirname(__DIR__, 2) . '/composer.json');
        self::assertNotFalse($raw, 'composer.json must be readable');

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'composer.json must be valid JSON');

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /** @return list<string> */
    private function phpstanScript(): array
    {
        $composer = $this->composerJson();
        self::assertIsArray($composer['scripts'] ?? null, 'composer.json must define scripts');

        /** @var array<string, mixed> $scripts */
        $scripts = $composer['scripts'];
        $script = $scripts['phpstan'] ?? null;

        self::assertIsArray(
            $script,
            'The phpstan script must be a LIST of steps. A bare string cannot carry the '
            . 'timeout opt-out, which is the whole reason this is a list.'
        );

        /** @var list<string> $script */
        return $script;
    }

    public function testTheScriptDisablesComposersProcessTimeout(): void
    {
        self::assertContains(
            'Composer\\Config::disableProcessTimeout',
            $this->phpstanScript(),
            'Without this, a cold PHPStan run is killed at 300s and reports a process timeout '
            . 'rather than an analysis result.'
        );
    }

    public function testTheScriptStillRunsPhpstanOverEveryCiPath(): void
    {
        $command = implode(' ', $this->phpstanScript());

        foreach (['src', 'tests', 'plugins', 'sdk', 'ops'] as $path) {
            self::assertMatchesRegularExpression(
                '/\bphpstan analyse\b[^\n]*\b' . preg_quote($path, '/') . '\b/',
                $command,
                "The script must analyse `{$path}`, or CI silently stops covering it — this "
                . 'script is the only place the path list now exists.'
            );
        }
    }

    public function testTheScriptKeepsTheMemoryLimitThatCiReliesOn(): void
    {
        self::assertStringContainsString(
            '--memory-limit=1G',
            implode(' ', $this->phpstanScript()),
            'At the container default (128M) PHPStan crashes its parallel workers and reports '
            . 'them as findings — the misleading count #934 was filed about.'
        );
    }
}
