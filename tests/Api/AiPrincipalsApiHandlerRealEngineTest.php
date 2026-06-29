<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AiPrincipalsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite) tests for {@see AiPrincipalsApiHandler} (WC-0208ce4d).
 *
 * Drives the handler against a genuine SQL engine so the real INSERT/SELECT
 * semantics — tenant scoping, revocation exclusion, pagination — are exercised,
 * not the forgiving behaviour of mocked PDO. STRINGIFY_FETCHES is enabled so
 * integer-vs-string comparison bugs surface as they do under PostgreSQL.
 *
 * Acceptance focus:
 *  - Tenant data isolation: tenant A sees only A's tokens, system tenant (id 0) sees all.
 *  - Revoked tokens are excluded from the listing.
 *  - Expired tokens are excluded from the listing.
 *  - Fail-closed when the tenant context is unresolved.
 *  - Defence-in-depth permission re-check (denied → 403).
 *  - Admin revoke: removes tokens from any user in the tenant; returns 404 for unknown JTI.
 *  - Admin revoke: system tenant may revoke tokens from any tenant.
 *  - Admin revoke: regular tenant may not revoke tokens belonging to another tenant.
 */
final class AiPrincipalsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $_GET = [];
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        $_GET = [];
        TenantContext::reset();
    }

    // ====================== Tenant data isolation ======================

    public function testListReturnsOnlyCurrentTenantTokens(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');
        $this->seedToken('jti-b', 20, 2, 'Bot B');

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 10));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('jti-a', $body['data'][0]['jti']);
        $this->assertSame('Bot A', $body['data'][0]['name']);
    }

    public function testSystemTenantSeesAllTokens(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');
        $this->seedToken('jti-b', 20, 2, 'Bot B');

        TenantContext::setTenantId(0);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 1));

        $body = json_decode($response->getBody(), true);
        $this->assertSame(2, $body['pagination']['total'], 'SYSTEM tenant must see all tokens across all tenants.');
    }

    // ====================== Exclusion: revoked + expired ======================

    public function testRevokedTokensAreExcluded(): void
    {
        $this->seedToken('jti-active', 10, 1, 'Active');
        $this->seedToken('jti-revoked', 10, 1, 'Revoked');
        $this->revokeToken('jti-revoked');

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 10));

        $body = json_decode($response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('jti-active', $body['data'][0]['jti']);
    }

    public function testExpiredTokensAreExcluded(): void
    {
        $this->seedToken('jti-active', 10, 1, 'Active');
        $this->seedExpiredToken('jti-expired', 10, 1, 'Expired');

        TenantContext::setTenantId(1);
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 10));

        $body = json_decode($response->getBody(), true);
        $this->assertCount(1, $body['data']);
        $this->assertSame('jti-active', $body['data'][0]['jti']);
    }

    // ====================== Fail-closed & RBAC ======================

    public function testUnresolvedTenantContextFailsClosed(): void
    {
        $response = $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 10));
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPermissionDeniedReturns403ForList(): void
    {
        TenantContext::setTenantId(1);
        $response = $this->handler(false)->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 10));
        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('mcp:tokens:manage', $body['details']['required']);
    }

    public function testPermissionDeniedReturns403ForRevoke(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');
        TenantContext::setTenantId(1);
        $response = $this->handler(false)->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-a', 10),
            ['jti' => 'jti-a']
        );
        $this->assertSame(403, $response->getStatusCode());
    }

    // ====================== Admin revoke ======================

    public function testRevokeReturns204AndExcludesTokenFromListing(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');

        TenantContext::setTenantId(1);
        $handler = $this->handler();

        $revokeResponse = $handler->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-a', 10),
            ['jti' => 'jti-a']
        );
        $this->assertSame(204, $revokeResponse->getStatusCode());

        // Verify the token is now excluded from the listing.
        $listResponse = $handler->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 10));
        $body = json_decode($listResponse->getBody(), true);
        $this->assertCount(0, $body['data']);
    }

    public function testRevokeReturns404ForUnknownJti(): void
    {
        TenantContext::setTenantId(1);
        $response = $this->handler()->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/nonexistent', 10),
            ['jti' => 'nonexistent']
        );
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testRevokeReturns404ForTokenFromAnotherTenant(): void
    {
        $this->seedToken('jti-other', 20, 2, 'Other Tenant Bot');

        TenantContext::setTenantId(1);
        $response = $this->handler()->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-other', 10),
            ['jti' => 'jti-other']
        );
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSystemTenantCanRevokeTokenFromAnyTenant(): void
    {
        $this->seedToken('jti-any', 20, 2, 'Tenant 2 Bot');

        TenantContext::setTenantId(0);
        $response = $this->handler()->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-any', 1),
            ['jti' => 'jti-any']
        );
        $this->assertSame(204, $response->getStatusCode());
    }

    public function testRevokeIsIdempotentWhenAlreadyRevoked(): void
    {
        $this->seedToken('jti-a', 10, 1, 'Bot A');
        $this->revokeToken('jti-a');

        TenantContext::setTenantId(1);
        // A pre-revoked token is already in revoked_tokens but still in mcp_tokens.
        // The ownership check passes (mcp_tokens row exists and matches the tenant).
        // The INSERT … ON CONFLICT DO NOTHING makes the second revoke a no-op.
        $response = $this->handler()->revoke(
            $this->authedRequest('DELETE', '/api/admin/mcp/tokens/jti-a', 10),
            ['jti' => 'jti-a']
        );
        $this->assertSame(204, $response->getStatusCode());
    }

    // ====================== Public contract shape ======================

    public function testPublicContractFields(): void
    {
        $this->seedToken('jti-x', 10, 1, 'Shape Test');

        TenantContext::setTenantId(1);
        $body = json_decode(
            $this->handler()->list($this->authedRequest('GET', '/api/admin/mcp/tokens', 10))->getBody(),
            true
        );

        $entry = $body['data'][0];
        $this->assertArrayHasKey('id', $entry);
        $this->assertArrayHasKey('jti', $entry);
        $this->assertArrayHasKey('userId', $entry);
        $this->assertArrayHasKey('tenantId', $entry);
        $this->assertArrayHasKey('name', $entry);
        $this->assertArrayHasKey('principalKind', $entry);
        $this->assertArrayHasKey('scope', $entry);
        $this->assertArrayHasKey('expiresAt', $entry);
        $this->assertArrayHasKey('createdAt', $entry);
        $this->assertIsArray($entry['scope']);
        $this->assertSame('jti-x', $entry['jti']);
        $this->assertSame('Shape Test', $entry['name']);
    }

    // ====================== Helpers ======================

    /**
     * Build the handler with a RoleChecker stub that grants (or denies) mcp:tokens:manage.
     */
    private function handler(bool $grant = true): AiPrincipalsApiHandler
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')->willReturn($grant);

        return new AiPrincipalsApiHandler($this->pdo, $roleChecker);
    }

    private function authedRequest(string $method, string $path, int $userId): Request
    {
        $request = new Request($method, $path);
        $request->user = (object) ['user_id' => $userId];
        return $request;
    }

    /**
     * Seed an active token (expires in the future) for the given user and tenant.
     *
     * @param string[] $scope
     */
    private function seedToken(
        string $jti,
        int $userId,
        int $tenantId,
        string $name,
        array $scope = ['tools:call'],
        string $principalKind = 'user',
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO mcp_tokens (jti, user_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime('now', '+90 days'), datetime('now'))
        ");
        $stmt->execute([$jti, $userId, $tenantId, $name, $principalKind, json_encode($scope)]);
    }

    /**
     * Seed an already-expired token for the given user and tenant.
     */
    private function seedExpiredToken(string $jti, int $userId, int $tenantId, string $name): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO mcp_tokens (jti, user_id, tenant_id, name, principal_kind, scope, expires_at, created_at)
            VALUES (?, ?, ?, ?, 'user', '[]', datetime('now', '-1 day'), datetime('now', '-2 days'))
        ");
        $stmt->execute([$jti, $userId, $tenantId, $name]);
    }

    /**
     * Insert a JTI into the revoked_tokens table.
     */
    private function revokeToken(string $jti): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO revoked_tokens (jti, expires_at)
            VALUES (?, datetime('now', '+90 days'))
            ON CONFLICT (jti) DO NOTHING
        ");
        $stmt->execute([$jti]);
    }
}
