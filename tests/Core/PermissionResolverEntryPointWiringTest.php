<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * WC-712: both production entry points must actually WIRE the permission
 * resolver, over the DELEGATION-AWARE checker.
 *
 * A read-only resolver contract is worthless if no host registers one — plugins
 * would fall back to hand-written SQL, which is the defect the contract exists
 * to remove. And registering the wrong CHECKER is worse than not registering at
 * all: the delegation-unaware bounding checker (which exists only to bound what
 * a grantor may delegate, WC-34) would answer "no" for a permission the RBAC
 * middleware grants through a live delegation, so a plugin would deny access the
 * platform allows — a silent, hard-to-trace divergence between two answers to
 * one question.
 *
 * public/index.php and BaseCommand::setupKernel() cannot be executed in a unit
 * test (a full worker bootstrap and a live DB connection respectively), so this
 * pins the wiring by scanning their source — the same technique
 * PluginRoleSeederEntryPointWiringTest, TenantOwnedTablesTest and
 * RouteCatalogueCompletenessTest use for other drift-prone conventions.
 */
final class PermissionResolverEntryPointWiringTest extends TestCase
{
    public function testHttpEntryPointRegistersTheResolverInTheServiceContainer(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\\\\?Whity\\\\Sdk\\\\Rbac\\\\PermissionResolver::class/',
            $source,
            'public/index.php must register the resolver under the SDK interface name, or '
            . '\Whity\app(PermissionResolver::class) throws and every plugin is pushed back '
            . 'onto hand-written permission SQL (#712 §3).'
        );
    }

    public function testHttpEntryPointBuildsTheResolverOverTheDelegationAwareChecker(): void
    {
        $source = $this->read(__DIR__ . '/../../public/index.php');

        self::assertMatchesRegularExpression(
            '/new\s+\\\\?Whity\\\\Core\\\\RBAC\\\\RoleCheckerPermissionResolver\(\s*\$roleChecker\s*,/',
            $source,
            'The resolver must wrap $roleChecker (the delegation-aware instance also passed to '
            . 'RbacMiddleware) — NOT $baseRoleChecker, whose delegation-blind answers would '
            . 'diverge from what the middleware enforces.'
        );
    }

    public function testCliEntryPointRegistersTheResolver(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/register_service\(\s*\n?\s*PermissionResolver::class/',
            $source,
            'The CLI kernel must register the same resolver contract, so plugin code reached '
            . 'through a CLI command resolves permissions identically to a web request.'
        );
    }

    /**
     * The CLI kernel used to build a RoleChecker with NO delegation resolver,
     * so its simulated API enforced a different policy from the HTTP API: a
     * permission held only through a live delegation opened a route over HTTP
     * and was invisible over the CLI. Pin the fix.
     */
    public function testCliEntryPointEnforcesWithADelegationAwareChecker(): void
    {
        $source = $this->read(__DIR__ . '/../../src/Cli/Commands/BaseCommand.php');

        self::assertMatchesRegularExpression(
            '/\$roleChecker\s*=\s*new\s+RoleChecker\(\s*\$db\s*,\s*\$permissionRegistry\s*,\s*null\s*,\s*\$delegationService\s*\)/',
            $source,
            'The CLI kernel must enforce with a delegation-AWARE RoleChecker, exactly as '
            . 'public/index.php does; otherwise the same caller is authorized differently '
            . 'depending on which entry point asks.'
        );

        self::assertMatchesRegularExpression(
            '/new\s+RbacMiddleware\(\s*\$jwtParser\s*,\s*\$roleChecker\s*\)/',
            $source,
            'The CLI RbacMiddleware must be given the delegation-aware checker, not the bounding one.'
        );
    }

    private function read(string $path): string
    {
        $source = file_get_contents($path);
        self::assertIsString($source, "Could not read {$path}.");

        return $source;
    }
}
