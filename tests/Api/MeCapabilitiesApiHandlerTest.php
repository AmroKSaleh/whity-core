<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\MeCapabilitiesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * WC-176 (#205): GET /api/me/capabilities — the caller's effective permission
 * slugs, so a bespoke admin page can hide write controls the caller lacks.
 *
 * Mirrors {@see NavigationApiHandlerRealEngineTest}: a mocked {@see RoleChecker}
 * makes the caller's effective permission set precise, the handler fakes the
 * {@see TenantContext} and {@see Request::$user} the same way, and the role
 * assertions against a real engine live in
 * {@see MeCapabilitiesApiHandlerRealEngineTest}. Acceptance focus here:
 *
 *  - fail-closed on an unresolved tenant context (403);
 *  - fail-closed on a missing authenticated user (403);
 *  - fail-closed on a malformed (non-int) user id (403);
 *  - happy path: 200 with `data.permissions` being the RoleChecker result,
 *    sorted deterministically and scoped to the RESOLVED tenant id.
 */
final class MeCapabilitiesApiHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== happy path ====================

    public function testReturnsTheCallersEffectivePermissionsSorted(): void
    {
        TenantContext::setTenantId(1);

        // RoleChecker returns an UNsorted set; the handler must sort it.
        $handler = $this->handler(['relations:read', 'audit:read', 'relations:manage']);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertSame(
            ['permissions' => ['audit:read', 'relations:manage', 'relations:read']],
            $this->data($response)
        );
    }

    public function testEmptyPermissionSetIsAValid200(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler([])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['permissions' => []], $this->data($response));
    }

    public function testPermissionsAreResolvedAgainstTheResolvedTenantAndCaller(): void
    {
        TenantContext::setTenantId(7);

        $seen = [];
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('getEffectivePermissionsForUser')
            ->willReturnCallback(function (int $userId, int $tenantId) use (&$seen): array {
                $seen = [$userId, $tenantId];
                return ['relations:read'];
            });

        $response = (new MeCapabilitiesApiHandler($roleChecker))->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([42, 7], $seen, 'The resolver must be called with the caller id and the resolved tenant id.');
    }

    // ==================== fail-closed ====================

    public function testUnresolvedTenantContextFailsClosed(): void
    {
        // No TenantContext set.
        $response = $this->handler(['relations:read'])->list($this->authedRequest(42));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMissingAuthenticatedUserFailsClosed(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['relations:read'])->list(new Request('GET', '/api/me/capabilities'));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMalformedUserIdFailsClosed(): void
    {
        TenantContext::setTenantId(1);

        $request = new Request('GET', '/api/me/capabilities');
        $request->user = (object) ['user_id' => 'not-an-int'];

        $response = $this->handler(['relations:read'])->list($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testInternalFailureReturnsAGeneric500WithoutLeakingDetails(): void
    {
        TenantContext::setTenantId(1);

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('getEffectivePermissionsForUser')
            ->willThrowException(new \RuntimeException('secret internal detail'));

        $response = (new MeCapabilitiesApiHandler($roleChecker))->list($this->authedRequest(42));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('secret internal detail', $response->getBody());
    }

    // ==================== helpers ====================

    /**
     * Build the handler with a RoleChecker stub returning exactly the given
     * effective permission set for any caller/tenant.
     *
     * @param array<int, string> $permissions The caller's effective permissions.
     */
    private function handler(array $permissions): MeCapabilitiesApiHandler
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('getEffectivePermissionsForUser')
            ->willReturn($permissions);

        return new MeCapabilitiesApiHandler($roleChecker);
    }

    private function authedRequest(int $userId): Request
    {
        $request = new Request('GET', '/api/me/capabilities');
        $request->user = (object) ['user_id' => $userId];

        return $request;
    }

    /**
     * @return array<string, mixed> The decoded `data` envelope.
     */
    private function data(Response $response): array
    {
        $body = json_decode($response->getBody(), true);

        return $body['data'];
    }
}
