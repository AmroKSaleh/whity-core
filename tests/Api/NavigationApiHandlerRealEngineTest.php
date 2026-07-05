<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\NavigationApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * WC-175 (#191): GET /api/navigation — the host's caller-aware, RBAC-filtered
 * listing of registered navigation items.
 *
 * Mirrors {@see FrontendFeaturesApiHandlerRealEngineTest}: a real HookManager
 * collects representative items (a public item, a role-gated item, and a
 * permission-gated item) and a mocked RoleChecker makes each caller's effective
 * RBAC precise. Acceptance focus:
 *
 *  - per-item server-side filtering against the authoritative RoleChecker:
 *    a role-gated item appears only for a caller holding that role; a
 *    permission-gated item only for a caller holding that permission; an
 *    ungated item is always present;
 *  - the gate is checked against the RESOLVED tenant id;
 *  - fail-closed on unresolved tenant (403), missing user (403), and a
 *    malformed (non-int) user id (403);
 *  - the existing sort (group then order) is preserved.
 */
final class NavigationApiHandlerRealEngineTest extends TestCase
{
    protected function setUp(): void
    {
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== server-side RBAC filtering ====================

    public function testCallerWithRoleSeesRoleGatedItem(): void
    {
        TenantContext::setTenantId(1);

        $handler = $this->handler(['admin'], []);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $ids = $this->ids($response);

        $this->assertContains('reports', $ids, 'A caller with the gating role sees the role-gated item');
    }

    public function testCallerWithoutRoleDoesNotSeeRoleGatedItem(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler([], [])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotContains('reports', $this->ids($response));
    }

    public function testCallerWithPermissionSeesPermissionGatedItem(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler([], ['audit:read'])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('audit-logs', $this->ids($response));
    }

    public function testCallerWithoutPermissionDoesNotSeePermissionGatedItem(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler([], [])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotContains('audit-logs', $this->ids($response));
    }

    public function testUngatedItemIsAlwaysPresent(): void
    {
        TenantContext::setTenantId(1);

        // A caller holding nothing still sees the public, ungated item.
        $response = $this->handler([], [])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains('settings', $this->ids($response));
    }

    public function testGatesAreCheckedAgainstTheResolvedTenant(): void
    {
        TenantContext::setTenantId(7);

        $roleArgs = [];
        $permArgs = [];
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasRole')
            ->willReturnCallback(function (int $userId, string $role, int $tenantId) use (&$roleArgs): bool {
                $roleArgs[] = [$userId, $role, $tenantId];
                return true;
            });
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(function (int $userId, string $permission, int $tenantId) use (&$permArgs): bool {
                $permArgs[] = [$userId, $permission, $tenantId];
                return true;
            });

        $handler = new NavigationApiHandler($this->hookManager(), $roleChecker);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains([42, 'admin', 7], $roleArgs);
        $this->assertContains([42, 'audit:read', 7], $permArgs);
    }

    // ==================== response shape & ordering ====================

    public function testSortOrderGroupThenOrderIsPreserved(): void
    {
        TenantContext::setTenantId(1);

        // Caller holds everything so all three items appear.
        $response = $this->handler(['admin'], ['audit:read'])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());

        // Groups: 'admin' (reports, audit-logs) sorts before default ('settings').
        // Within admin: reports order=2 then audit-logs order=6.
        $this->assertSame(['reports', 'audit-logs', 'settings'], $this->ids($response));
    }

    // ==================== fail-closed ====================

    public function testUnresolvedTenantContextFailsClosed(): void
    {
        // No TenantContext set.
        $response = $this->handler(['admin'], ['audit:read'])->list($this->authedRequest(42));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMissingAuthenticatedUserFailsClosed(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['admin'], [])->list(new Request('GET', '/api/navigation'));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMalformedUserIdFailsClosed(): void
    {
        TenantContext::setTenantId(1);

        $request = new Request('GET', '/api/navigation');
        $request->user = (object) ['profile_id' => 'not-an-int'];

        $response = $this->handler(['admin'], [])->list($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    // ==================== helpers ====================

    /**
     * A HookManager seeded with a representative mix of nav items: a public
     * (ungated) item, a role-gated item, and a permission-gated item.
     */
    private function hookManager(): HookManager
    {
        $hookManager = new HookManager();
        $hookManager->listen('navigation.register', static function (array $data): array {
            $items = $data['items'] ?? [];
            // Permission-gated, group=admin, order=6.
            $items[] = [
                'id' => 'audit-logs',
                'label' => 'Audit Logs',
                'href' => '/admin/audit-logs',
                'group' => 'admin',
                'order' => 6,
                'requiredPermission' => 'audit:read',
            ];
            // Ungated, default group, order=100.
            $items[] = [
                'id' => 'settings',
                'label' => 'Settings',
                'href' => '/settings',
                'order' => 100,
            ];
            // Role-gated, group=admin, order=2.
            $items[] = [
                'id' => 'reports',
                'label' => 'Reports',
                'href' => '/admin/reports',
                'group' => 'admin',
                'order' => 2,
                'requiredRole' => 'admin',
            ];
            return ['items' => $items];
        });

        return $hookManager;
    }

    /**
     * Build the handler with a RoleChecker stub granting exactly the given
     * roles and permissions.
     *
     * @param array<int, string> $roles       Roles the caller holds.
     * @param array<int, string> $permissions Permissions the caller holds.
     */
    private function handler(array $roles, array $permissions): NavigationApiHandler
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasRole')
            ->willReturnCallback(
                static fn (int $userId, string $role, int $tenantId): bool => in_array($role, $roles, true)
            );
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(
                static fn (int $userId, string $permission, int $tenantId): bool => in_array($permission, $permissions, true)
            );

        return new NavigationApiHandler($this->hookManager(), $roleChecker);
    }

    private function authedRequest(int $userId): Request
    {
        $request = new Request('GET', '/api/navigation');
        $request->user = (object) ['profile_id' => $userId];

        return $request;
    }

    /**
     * @return array<int, string> The item ids in response order.
     */
    private function ids(Response $response): array
    {
        $body = json_decode($response->getBody(), true);

        return array_column($body['data'], 'id');
    }
}
