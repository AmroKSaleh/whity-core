<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * The permission catalogue must be REGISTERED as a service by every host that
 * fills one — and both hosts must fill the same one they hand the plugin loader.
 *
 * Neither entry point did. `public/index.php` built a PermissionRegistry, filled
 * it with the core set, passed it to PluginLoader — and never registered it. So
 * `\Whity\app(PermissionRegistry::class)` fell through to the container's
 * auto-instantiation fallback (concrete class, one OPTIONAL argument) and
 * returned a fresh, EMPTY registry. Reproduced on a live boot: a plugin's
 * declared permission read back as non-existent, the plugin failed closed, and
 * nothing threw, warned or logged. A silently empty security catalogue is the
 * worst possible answer — worse than an error, because there is nothing to
 * diagnose from.
 *
 * `HostWiredService` now makes the unregistered lookup throw instead (pinned in
 * ServiceContainerTest), but a THROW is only the safety net; the fix is that
 * both hosts register the real, populated instance. This test pins that, in
 * both places, because a registry wired in only one entry point is the
 * divergence bug class this repo has already paid for in #717 and #724.
 *
 * Neither entry point can be executed in a unit test (a full worker bootstrap
 * and a live DB connection respectively), so the wiring is pinned by scanning
 * their source — the technique PermissionResolverEntryPointWiringTest,
 * HealthProbeRegistryEntryPointWiringTest and DataTypeEntryPointWiringTest
 * already use. The behaviour those scans stand in for is exercised for real, on
 * a booted host, by Tests\Integration\EntryPointServiceWiringRealBootTest.
 */
final class PermissionRegistryEntryPointWiringTest extends TestCase
{
    public function testHttpEntryPointRegistersThePermissionRegistryAsAService(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?(?:Whity\\\\Core\\\\RBAC\\\\)?PermissionRegistry::class\s*,\s*\$permissionRegistry\s*\)/',
            $source,
            'public/index.php must register the SAME $permissionRegistry it fills and hands to '
            . 'the plugin loader, or a plugin resolving the catalogue sees no plugin permissions '
            . 'at all.'
        );
    }

    public function testHttpEntryPointRegistersTheInstanceItGivesToThePluginLoader(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$permissionRegistry/s',
            $source,
            'The loader is what registers a plugin\'s declared permissions; a catalogue that '
            . 'never reaches it stays empty however it is resolved.'
        );

        $registrationOffset = $this->offsetOf(
            $source,
            '/register_service\(\s*\\\\?(?:Whity\\\\Core\\\\RBAC\\\\)?PermissionRegistry::class/'
        );
        $loaderOffset = $this->offsetOf($source, '/new PluginLoader\(/');

        self::assertLessThan(
            $loaderOffset,
            $registrationOffset,
            'Register BEFORE the plugin loader runs: a plugin\'s register()/boot() may resolve '
            . 'the catalogue, and at that moment the container must already hold it.'
        );
    }

    public function testCliEntryPointRegistersTheSamePermissionRegistry(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?(?:Whity\\\\Core\\\\RBAC\\\\)?PermissionRegistry::class\s*,\s*\$permissionRegistry\s*\)/',
            $source,
            'The CLI kernel must register the same catalogue contract as the HTTP host, or the '
            . 'two entry points disagree about which permissions exist — the recurring divergence '
            . 'this repo has paid for in #717 and #724.'
        );

        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$permissionRegistry/s',
            $source,
            'and must pass that same instance to its loader.'
        );
    }

    /**
     * The same audit turned up a second, louder divergence: the CLI kernel did
     * not register the Database either. `\Whity\app(Database::class)` is the
     * documented plugin seam (both in-tree pilot plugins use it) and Database's
     * constructor is private, so a plugin route reached through a whity-cli
     * command threw "not instantiable" while the identical route over HTTP
     * served fine.
     */
    public function testCliEntryPointRegistersTheDatabaseTheHttpHostAlsoRegisters(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?(?:Whity\\\\Database\\\\)?Database::class\s*,\s*\$db\s*\)/',
            $source,
            'The CLI kernel must register the same Database instance its own handlers use, or '
            . 'every plugin route it serves fails on the documented container lookup.'
        );
    }

    private function offsetOf(string $source, string $pattern): int
    {
        $matched = preg_match($pattern, $source, $matches, PREG_OFFSET_CAPTURE);
        self::assertSame(1, $matched, "Expected {$pattern} to match.");

        return (int) $matches[0][1];
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
