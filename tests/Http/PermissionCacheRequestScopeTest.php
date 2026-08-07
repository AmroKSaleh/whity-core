<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Auth\RoleChecker;

/**
 * Guard: the effective-permission cache must not outlive a request.
 *
 * {@see RoleChecker::$effectiveUserPermissionCache} is a PROCESS-level static,
 * so under FrankenPHP worker mode it survives from one request to the next.
 * Every mutating write (DelegationsApiHandler, RolesApiHandler, UsersApiHandler,
 * OusApiHandler) calls {@see RoleChecker::clearCache()} — but that only clears
 * the ONE worker that served the write. With a pool of workers the others keep
 * answering from a stale set, which means a freshly granted permission can stay
 * invisible and, worse, a REVOKED permission can stay live until the worker
 * recycles.
 *
 * Reproduced live (WC-221 e2e): an admin granted `plugins:read` to a delegate,
 * the delegate logged in 1.7s later, and GET /api/v1/me/capabilities came back
 * without it — twice, two seconds apart — because that request was served by a
 * different worker than the grant.
 *
 * The fix is to scope the cache to the request by clearing it in the same
 * teardown that resets TenantContext and AuditContext. index.php's worker loop
 * is not unit-testable in isolation (it is the entry script), so this asserts
 * the wiring at the source level — the same approach
 * {@see \Tests\OpenAPI\RouteCatalogueCompletenessTest} takes for route
 * registration.
 */
final class PermissionCacheRequestScopeTest extends TestCase
{
    private static function indexPhp(): string
    {
        $source = file_get_contents(__DIR__ . '/../../public/index.php');
        self::assertIsString($source, 'Could not read public/index.php');

        return $source;
    }

    /**
     * Every per-request teardown that resets AuditContext must also drop the
     * permission cache; otherwise one of the two request paths (worker loop /
     * non-worker fallback) leaks a stale set into the next request.
     */
    public function testEveryRequestTeardownClearsThePermissionCache(): void
    {
        $source = self::indexPhp();

        $auditResets = substr_count($source, 'AuditContext::reset();');
        $cacheClears = substr_count($source, 'RoleChecker::clearCache();');

        self::assertGreaterThan(0, $auditResets, 'Expected per-request teardowns in index.php');
        self::assertGreaterThanOrEqual(
            $auditResets,
            $cacheClears,
            "public/index.php resets AuditContext {$auditResets}x but clears the effective-permission "
            . "cache only {$cacheClears}x. A request teardown that forgets RoleChecker::clearCache() "
            . 'leaves the NEXT request on that worker answering from the previous one\'s permission '
            . 'set — stale grants, and revocations that keep working.'
        );
    }

    /**
     * clearCache() must actually empty the caches it claims to own, so the
     * teardown above is not a no-op.
     */
    public function testClearCacheEmptiesTheStaticCaches(): void
    {
        $reflection = new \ReflectionClass(RoleChecker::class);

        foreach (['effectivePermissionCache', 'effectiveUserPermissionCache'] as $name) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            $property->setValue(null, ['sentinel' => ['seeded']]);
        }

        RoleChecker::clearCache();

        foreach (['effectivePermissionCache', 'effectiveUserPermissionCache'] as $name) {
            $property = $reflection->getProperty($name);
            $property->setAccessible(true);
            self::assertSame(
                [],
                $property->getValue(),
                "RoleChecker::clearCache() left {$name} populated."
            );
        }
    }
}
