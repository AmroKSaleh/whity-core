<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\ActiveTenantMembershipGuard;
use Whity\Auth\JwtParser;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;

/**
 * WC-d4340daf: HTTP-layer active_tenant_id membership enforcement.
 *
 * When the middleware is wired with an {@see ActiveTenantMembershipGuard} and
 * the request token carries the new {profile_id, active_tenant_id} claims, the
 * declared active tenant must be backed by a live 'active' membership row (or
 * system-tenant authority, id 0). A revoked/suspended membership is refused at
 * the HTTP layer with a typed 403 — never a raw exception (ADR 0005 §5).
 *
 * LEGACY tokens (no new claims) bypass the gate entirely: during the dual
 * window pre-migration users have no membership rows and must keep working.
 */
final class EnforceTenantIsolationMembershipTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private JwtParser&\PHPUnit\Framework\MockObject\MockObject $jwtParser;
    private MembershipRepository $memberships;
    private EnforceTenantIsolation $middleware;

    private int $profileId;

    protected function setUp(): void
    {
        TenantContext::reset();

        $this->pdo = SchemaFromMigrations::make();
        $this->jwtParser = $this->createMock(JwtParser::class);
        $this->memberships = new MembershipRepository($this->pdo);

        $this->middleware = new EnforceTenantIsolation(
            $this->jwtParser,
            null,
            new ActiveTenantMembershipGuard($this->pdo),
        );

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'tenant-b', datetime('now'))");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");

        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Gate', 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->profileId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function dispatch(array $payload): \Whity\Sdk\Http\Response
    {
        $this->jwtParser->method('parse')->willReturn($payload);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer some.jwt.token',
        ]);

        return $this->middleware->handle($request, fn (Request $r) => new Response(200, 'OK'));
    }

    public function testMemberWithActiveMembershipPasses(): void
    {
        $this->memberships->insert($this->profileId, self::TENANT_A, 1);

        $response = $this->dispatch([
            'profile_id' => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
            'user_id' => 5,
            'tenant_id' => self::TENANT_A,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(self::TENANT_A, TenantContext::getTenantId());
    }

    public function testNonMemberIsRefusedWithTyped403(): void
    {
        // Membership only in tenant B; token declares active tenant A.
        $this->memberships->insert($this->profileId, self::TENANT_B, 1);

        $response = $this->dispatch([
            'profile_id' => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
            'user_id' => 5,
            'tenant_id' => self::TENANT_A,
        ]);

        self::assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body, 'Refusal must be a typed JSON error, never raw exception text.');
    }

    public function testSuspendedMembershipIsRefusedWith403(): void
    {
        $id = $this->memberships->insert($this->profileId, self::TENANT_A, 1);
        $this->memberships->suspend($id, self::TENANT_A);

        $response = $this->dispatch([
            'profile_id' => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
        ]);

        self::assertSame(403, $response->getStatusCode());
    }

    public function testSystemTenantZeroIsUnscoped(): void
    {
        // No membership rows: active_tenant_id 0 carries system authority.
        $response = $this->dispatch([
            'profile_id' => $this->profileId,
            'active_tenant_id' => 0,
        ]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, TenantContext::getTenantId());
    }

    public function testLegacyTokenBypassesMembershipGate(): void
    {
        // Post-cutover (WC-idcut-E): the dual-claim window is gone. Tokens without
        // {profile_id, active_tenant_id} are rejected at the membership gate with
        // 401 — there is no longer a legacy pass-through path.
        $response = $this->dispatch([
            'user_id' => 5,
            'tenant_id' => self::TENANT_A,
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testMiddlewareWithoutGuardKeepsCurrentBehaviour(): void
    {
        // Constructed WITHOUT a guard (CLI wiring): no membership enforcement.
        $middleware = new EnforceTenantIsolation($this->jwtParser);
        $this->jwtParser->method('parse')->willReturn([
            'profile_id' => $this->profileId,
            'active_tenant_id' => self::TENANT_A,
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer some.jwt.token',
        ]);

        $response = $middleware->handle($request, fn (Request $r) => new Response(200, 'OK'));

        self::assertSame(200, $response->getStatusCode());
    }
}
