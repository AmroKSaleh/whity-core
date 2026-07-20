<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * WC-527 regression: the production entry point must actually wire a
 * PluginRoleSeeder into PluginLoader.
 *
 * PluginLoader's $roleSeeder constructor parameter is optional (default
 * null) so unit tests that construct their own loader can omit it — but that
 * same optionality let public/index.php construct the REAL loader without a
 * seeder for a long time, silently no-opping every PluginRolesInterface
 * plugin's role seeding in every real deployment (#527). Nothing in the
 * existing test suite could catch this, because tests build their own
 * PluginLoader directly and never exercise the actual bootstrap file.
 *
 * public/index.php can't be executed in a unit test (it's a full worker
 * bootstrap expecting a live FrankenPHP/CLI-server request context, real env
 * vars, etc.), so this pins the wiring the same way
 * TenantOwnedTablesTest/RouteCatalogueCompletenessTest pin other
 * drift-prone conventions: by scanning the entry point's own source.
 */
final class PluginRoleSeederEntryPointWiringTest extends TestCase
{
    public function testProductionEntryPointConstructsAndPassesARoleSeeder(): void
    {
        $source = file_get_contents(__DIR__ . '/../../public/index.php');
        self::assertIsString($source, 'Could not read public/index.php.');

        self::assertMatchesRegularExpression(
            '/new\s+PluginLoader\s*\(.*?new\s+PluginRoleSeeder\s*\(/s',
            $source,
            'public/index.php must construct a PluginRoleSeeder and pass it into the '
                . 'PluginLoader constructor — otherwise every plugin implementing '
                . 'PluginRolesInterface silently gets no role seeding in a real '
                . 'deployment (#527).'
        );
    }
}
