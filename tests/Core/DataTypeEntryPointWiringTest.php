<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * WC-723: both production entry points must WIRE table ownership and the
 * data-type catalogue, and both must pass them to the plugin loader.
 *
 * A divergence between public/index.php and BaseCommand::setupKernel() is a
 * recurring bug class in this repo — #717 for the RoleChecker, #724 for the
 * permission and resource-type registries, where the CLI loader was constructed
 * with NO registries at all and a route gated on a plugin permission failed
 * closed under the CLI while working over HTTP. The same omission here would be
 * quieter and worse: a plugin's tables and data types would exist over HTTP and
 * simply not exist under the CLI, so a lifecycle transition run by a command
 * would answer "unregistered type" for a type that is declared.
 *
 * Constructing the registries is not enough — they must reach the LOADER, since
 * that is what stamps ownership. Both facts are pinned separately.
 *
 * Neither entry point can be executed in a unit test (a full worker bootstrap
 * and a live DB connection respectively), so this scans their source, the same
 * technique PermissionResolverEntryPointWiringTest and
 * PluginRoleSeederEntryPointWiringTest use.
 */
final class DataTypeEntryPointWiringTest extends TestCase
{
    // ==================== HTTP ====================

    public function testHttpEntryPointRegistersTheOwnershipAndDataTypeRegistries(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\Tenant\\\\TableOwnershipRegistry::class/',
            $source,
            'Ownership must be resolvable from the container, or a handler asking "who owns this '
            . 'table?" builds a second, EMPTY instance and answers "nobody" for every plugin table.'
        );
        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\DataType\\\\DataTypeRegistry::class/',
            $source,
            'The data-type catalogue must be the SAME instance the loader filled.'
        );
    }

    public function testHttpEntryPointClaimsCoreTablesBeforePluginsLoad(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertStringContainsString(
            '$tableOwnershipRegistry->registerCoreTables()',
            $source,
            'Core must claim its own tables, or the first plugin to ask owns them.'
        );

        $claimAt = strpos($source, '$tableOwnershipRegistry->registerCoreTables()');
        $loadAt = strpos($source, '$pluginLoader->load()');
        self::assertIsInt($claimAt);
        self::assertIsInt($loadAt);
        self::assertLessThan(
            $loadAt,
            $claimAt,
            'Core must claim its tables BEFORE plugins load, so a plugin claiming a core table '
            . 'loses by construction rather than by load order.'
        );
    }

    public function testHttpEntryPointPassesBothRegistriesToThePluginLoader(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$tableOwnershipRegistry,\s*\$dataTypeRegistry\s*\)/s',
            $source,
            'The loader is what STAMPS ownership from $plugin->getName(); a registry that never '
            . 'reaches it is a registry no plugin can ever register with.'
        );
    }

    public function testHttpEntryPointRegistersTheGuardUnderTheSdkContract(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Sdk\\\\DataType\\\\DataTypeGuard::class/',
            $source,
            'A plugin keeping its own delete route resolves the guard through the SDK contract; '
            . 'without this registration the escape hatch is pushed back onto hand-written SQL — '
            . 'a second enforcement path that can disagree with the generated one.'
        );
    }

    public function testHttpEntryPointRegistersTheRestoreStateMemory(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\DataType\\\\LifecycleStateMemory::class/',
            $source,
            'A plugin that hard-deletes a record OUTSIDE core has to clear its remembered restore '
            . 'state, and the container refuses to build the class itself (it takes a PDO). '
            . 'Unregistered, the only remaining option is a hand-written DELETE against a '
            . 'core-owned table — and the row left behind when nobody does it carries no foreign '
            . 'key, so a re-used key inherits a dead record\'s state.'
        );
    }

    public function testHttpEntryPointExposesTheGeneratedLifecycleRoutes(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        foreach (
            [
                "'GET',    '/api/data-types'",
                "'/api/data-types/{type}/{id}'",
                "'/api/data-types/{type}/{id}/trash'",
                "'/api/data-types/{type}/{id}/restore'",
                "'/api/data-types/{type}/{id}/retire'",
            ] as $fragment
        ) {
            self::assertStringContainsString($fragment, $source, "Missing route registration: {$fragment}");
        }
    }

    // ==================== CLI ====================

    public function testCliEntryPointRegistersTheSameRegistries(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\Tenant\\\\TableOwnershipRegistry::class/',
            $source,
            'The CLI kernel must see the same ownership map as a web request.'
        );
        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\DataType\\\\DataTypeRegistry::class/',
            $source,
            'The CLI kernel must see the same data-type catalogue as a web request.'
        );
        self::assertStringContainsString(
            '$tableOwnershipRegistry->registerCoreTables()',
            $source,
            'Core must claim its tables under the CLI too.'
        );
    }

    public function testCliEntryPointPassesBothRegistriesToThePluginLoader(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/new PluginLoader\((?:[^;]*?)\$tableOwnershipRegistry,\s*\$dataTypeRegistry\s*\)/s',
            $source,
            'Same omission as #724: constructing the registries without passing them to the loader '
            . 'leaves the CLI with an empty catalogue while HTTP has a full one.'
        );
    }

    public function testCliEntryPointRegistersTheGuardUnderTheSdkContract(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Sdk\\\\DataType\\\\DataTypeGuard::class/',
            $source,
            'A plugin reached through a CLI command must resolve the same guard contract.'
        );
    }

    public function testCliEntryPointRegistersTheRestoreStateMemoryToo(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Core\\\\DataType\\\\LifecycleStateMemory::class/',
            $source,
            'Registered in one entry point only, "clear the memory" would work over HTTP and throw '
            . 'under the CLI — the divergence bug class #717 and #724 already paid for.'
        );
    }

    /**
     * Both entry points must register the memory the lifecycle service actually
     * uses, not a second instance built beside it.
     *
     * Two handles over one connection would behave identically today, so this
     * is not about behaviour — it is about there being no second object for a
     * later change (a cache, a batched write, a per-request buffer) to make
     * diverge. The accessor makes "which instance?" a question with one answer.
     */
    public function testBothEntryPointsRegisterTheServicesOwnMemoryInstance(): void
    {
        foreach (['/../../public/index.php', '/../../src/Cli/Commands/BaseCommand.php'] as $path) {
            self::assertMatchesRegularExpression(
                '/LifecycleStateMemory::class,\s*\$dataTypeLifecycle->stateMemory\(\)/',
                $this->read(__DIR__ . $path),
                "{$path} must register the lifecycle service's OWN memory."
            );
        }
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
