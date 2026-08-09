<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * WC-status-probes: every host that loads plugins must also give them a probe
 * catalogue, and the process that COLLECTS must build one too.
 *
 * There are three places, and they fail in three different ways:
 *
 *  - public/index.php — a missing registry means the HTTP host never learns
 *    about a contributed component, so the /status payload silently omits a
 *    card the collector is happily writing samples for.
 *  - BaseCommand::setupKernel() — the CLI/HTTP divergence this repo has now
 *    paid for three times (#717, #724, and the permission registry before
 *    that): a plugin reached through a CLI command asks the container for the
 *    catalogue and gets a RuntimeException.
 *  - HealthWatchCommand — the only process that actually SAMPLES. It runs
 *    outside both hosts, so if it does not build a catalogue of its own, a
 *    contributed probe is declared everywhere and run nowhere.
 *
 * None of the three can be executed in a unit test (a worker bootstrap, a live
 * DB connection, and an endless collection loop respectively), so this pins the
 * wiring by scanning their source — the technique
 * PermissionResolverEntryPointWiringTest and PluginRoleSeederEntryPointWiringTest
 * already use for the same drift-prone convention.
 */
final class HealthProbeRegistryEntryPointWiringTest extends TestCase
{
    public function testHttpEntryPointRegistersTheCatalogueAsAService(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\Health\\\\HealthProbeRegistry::class/',
            $source,
            'public/index.php must register the probe catalogue, or a plugin resolving it '
            . 'gets a RuntimeException and cannot contribute a probe at all.'
        );
        self::assertStringContainsString(
            '$healthProbeRegistry->registerCoreProbes();',
            $source,
            "Core's four probes must be registered up front, before the plugin loader runs."
        );
    }

    public function testHttpEntryPointPassesTheCatalogueToThePluginLoader(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$healthProbeRegistry/s',
            $source,
            'The loader is what registers a plugin\'s probes under the plugin NAME it supplies; '
            . 'a catalogue that never reaches it stays empty no matter what plugins declare.'
        );
    }

    /**
     * Collected-but-never-rendered is the failure mode this one catches: the
     * samples land in health_samples and the page shows only core's cards.
     */
    public function testHttpEntryPointGivesTheCatalogueToTheStatusReport(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/new \\\\?Whity\\\\Core\\\\Health\\\\StatusReport\(\s*[^;]*?\$healthProbeRegistry/s',
            $source,
            'StatusReport must receive the catalogue so a contributed component is rendered '
            . 'as its own card rather than sampled and then dropped.'
        );
    }

    public function testCliEntryPointRegistersAndWiresTheSameCatalogue(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\Health\\\\HealthProbeRegistry::class/',
            $source,
            'The CLI kernel must register the same catalogue contract as the HTTP host.'
        );
        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$healthProbeRegistry/s',
            $source,
            'and must pass it to its loader, or the two entry points disagree about which '
            . 'components exist.'
        );
    }

    public function testTheCollectorBuildsACatalogueOfItsOwn(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/HealthWatchCommand.php');

        self::assertStringContainsString(
            'new HealthProbeRegistry()',
            $source,
            'health:watch runs outside both hosts, so nothing has registered a catalogue for '
            . 'it; it must build one.'
        );
        self::assertStringContainsString(
            '$loader->load();',
            $source,
            'and must load the plugin tree into it — this is the ONLY process that samples, '
            . 'so a probe not registered here is never run.'
        );
        self::assertMatchesRegularExpression(
            '/new HealthProbe\(\s*[^;]*?\$this->probeRegistry\(\)/s',
            $source,
            'and must hand that catalogue to the probe it runs each pass.'
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
