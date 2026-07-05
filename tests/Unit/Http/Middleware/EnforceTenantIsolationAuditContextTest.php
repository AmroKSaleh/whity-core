<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Middleware;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;

/**
 * WC-c35c4ce0 security follow-up (a): EnforceTenantIsolation must stamp a
 * NON-NULL audit actor for post-cutover tokens that identify the caller by
 * profile_id only (no legacy user_id).
 *
 * The middleware populates the request-scoped {@see AuditContext} via
 * userIdFromPayload($payload), which falls back to profile_id when user_id is
 * absent. Before the fix it read payload['user_id'] inline, so a profile-only
 * token stamped a NULL actor and every audit entry for that request lost the
 * acting identity. These tests pin the fallback.
 */
final class EnforceTenantIsolationAuditContextTest extends TestCase
{
    private EnforceTenantIsolation $middleware;
    /** @var JwtParser&\PHPUnit\Framework\MockObject\MockObject */
    private JwtParser $mockJwtParser;

    protected function setUp(): void
    {
        $this->mockJwtParser = $this->createMock(JwtParser::class);
        $this->middleware = new EnforceTenantIsolation($this->mockJwtParser);
        TenantContext::reset();
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    /**
     * A profile-only token ({profile_id, active_tenant_id}, NO user_id/tenant_id)
     * must stamp the profile_id as the audit actor — never null.
     */
    public function testProfileOnlyTokenStampsProfileIdAsAuditActor(): void
    {
        $token = 'profile.only.jwt';
        $payload = [
            'profile_id'       => 777,
            'active_tenant_id' => 42,
            'email'            => 'profile-native@example.com',
            'role'             => 'user',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$token}"]);
        $next = fn(Request $req) => new Response(200, 'ok');

        $this->mockJwtParser->method('parse')->with($token)->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(42, TenantContext::getTenantId(), 'active_tenant_id must resolve the tenant.');
        self::assertSame(
            777,
            AuditContext::getActorUserId(),
            'A profile-only token must stamp profile_id (777) as the audit actor, not null.'
        );
    }

    /**
     * A dual-claim token (carrying both profile_id and user_id) stamps profile_id
     * as the audit actor. Post-cutover, profile_id is the canonical identity
     * (ADR 0005 §1) and userIdFromPayload() returns profile_id exclusively.
     */
    public function testLegacyTokenStampsUserIdAsAuditActor(): void
    {
        $token = 'legacy.dual.jwt';
        $payload = [
            'user_id'          => 123,
            'tenant_id'        => 42,
            'profile_id'       => 777,
            'active_tenant_id' => 42,
            'email'            => 'dual@example.com',
        ];

        $request = new Request('GET', '/api/resource', ['Authorization' => "Bearer {$token}"]);
        $next = fn(Request $req) => new Response(200, 'ok');

        $this->mockJwtParser->method('parse')->with($token)->willReturn($payload);

        $response = $this->middleware->handle($request, $next);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            777,
            AuditContext::getActorUserId(),
            'Post-cutover: userIdFromPayload() returns profile_id (777) as the canonical audit actor.'
        );
    }
}
