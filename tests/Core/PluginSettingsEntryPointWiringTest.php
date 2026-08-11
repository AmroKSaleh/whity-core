<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * #713 item 1: both entry points must build the settings catalogue, and build it
 * the same way.
 *
 * The divergence bug class this repo has already paid for three times (#717 for
 * the RoleChecker, #724 for the permission and resource-type registries, #727
 * for the container handing out empty ones) has a particularly quiet form here.
 * A missing catalogue in the CLI does not throw and does not fail closed: a
 * command reading a plugin-declared key would resolve the REGISTRY DEFAULT while
 * the HTTP host resolved the operator's configured value. Two different answers
 * to the same question, neither of them an error — a scheduled job quietly
 * running with the shipped default while the admin screen shows the value they
 * set.
 *
 * Neither entry point can be executed in a unit test (a worker bootstrap and a
 * live DB connection respectively), so this pins the wiring by scanning their
 * source — the technique {@see HealthProbeRegistryEntryPointWiringTest} and
 * {@see PermissionResolverEntryPointWiringTest} already use for the same
 * drift-prone convention.
 */
final class PluginSettingsEntryPointWiringTest extends TestCase
{
    private const HTTP = __DIR__ . '/../../public/index.php';
    private const CLI = __DIR__ . '/../../src/Cli/Commands/BaseCommand.php';

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function entryPoints(): array
    {
        return [
            'HTTP host' => [self::HTTP, 'public/index.php'],
            'CLI kernel' => [self::CLI, 'src/Cli/Commands/BaseCommand.php'],
        ];
    }

    /**
     * @dataProvider entryPoints
     */
    public function testBothEntryPointsRegisterThePluginSettingsRegistry(string $path, string $label): void
    {
        $source = $this->read($path);

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\Settings\\\\PluginSettingsRegistry::class/',
            $source,
            "{$label} must register the plugin-settings catalogue, or a plugin resolving it "
            . 'gets a RuntimeException and cannot declare a setting at all.'
        );
    }

    /**
     * @dataProvider entryPoints
     */
    public function testBothEntryPointsRegisterTheUnionCatalogue(string $path, string $label): void
    {
        $source = $this->read($path);

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\Settings\\\\SettingsCatalog::class/',
            $source,
            "{$label} must register the union view. Core's static registry answers core-only "
            . 'by design, so anything resolving the catalogue and getting nothing would see '
            . 'plugin keys as "unknown setting" — the quiet failure this whole change removes.'
        );
        self::assertMatchesRegularExpression(
            '/new \\\\?Whity\\\\Core\\\\Settings\\\\SettingsCatalog\(\s*\$pluginSettingsRegistry/',
            $source,
            "{$label} must build the union over the SAME registry instance the loader fills; "
            . 'a catalogue over a second, empty registry would answer "unknown" for every '
            . 'declared key.'
        );
    }

    /**
     * @dataProvider entryPoints
     */
    public function testBothEntryPointsPassTheRegistryToThePluginLoader(string $path, string $label): void
    {
        $source = $this->read($path);

        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$pluginSettingsRegistry/s',
            $source,
            "{$label}: the loader is what registers a plugin's settings under the plugin NAME "
            . 'it supplies; a catalogue that never reaches it stays empty no matter what '
            . 'plugins declare.'
        );
    }

    /**
     * The catalogue is only useful if the thing that RESOLVES values consults
     * it. A settings service built without it silently resolves core-only, so
     * every plugin key would read as its default forever — values written to the
     * database and never read back.
     *
     * @dataProvider entryPoints
     */
    public function testBothEntryPointsGiveTheCatalogueToTheSettingsService(string $path, string $label): void
    {
        $source = $this->read($path);

        self::assertMatchesRegularExpression(
            '/new \\\\?Whity\\\\Core\\\\Settings\\\\SettingsService\((?:[^;]*?)\$settingsCatalog/s',
            $source,
            "{$label} must pass the union catalogue to SettingsService, or plugin keys are "
            . 'declared everywhere and resolved nowhere.'
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
