<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Whity\Auth\JwtParser;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\TenantResolutionException;

/**
 * Tests for TenantContext class
 */
class TenantContextTest extends TestCase
{
    /**
     * Reset context (and any injected logger) after each test to avoid
     * state leaking across the persistent-worker style static context.
     */
    protected function tearDown(): void
    {
        TenantContext::reset();
        TenantContext::setLogger(null);
    }

    // ---------------------------------------------------------------------
    // Existing behaviour (backward-compatibility guards)
    // ---------------------------------------------------------------------

    /**
     * Test that setTenantId stores the tenant ID
     */
    public function testSetTenantIdStoresTenantId(): void
    {
        TenantContext::setTenantId(42);
        $this->assertSame(42, TenantContext::getTenantId());
    }

    /**
     * Test that setTenantId locks the context after first set
     */
    public function testSetTenantIdLocksContextAfterFirstSet(): void
    {
        TenantContext::setTenantId(42);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/locked/i');

        TenantContext::setTenantId(99);
    }

    /**
     * Test that hasTenant returns correct status
     */
    public function testHasTenantReturnsTrueWhenSet(): void
    {
        $this->assertFalse(TenantContext::hasTenant());

        TenantContext::setTenantId(42);
        $this->assertTrue(TenantContext::hasTenant());
    }

    /**
     * Test that getTenantId returns null when not set
     */
    public function testGetTenantIdReturnsNullWhenNotSet(): void
    {
        $this->assertNull(TenantContext::getTenantId());
    }

    /**
     * Test that reset clears context and unlocks it
     */
    public function testResetClearsContextAndUnlocks(): void
    {
        TenantContext::setTenantId(42);
        $this->assertTrue(TenantContext::hasTenant());

        TenantContext::reset();
        $this->assertFalse(TenantContext::hasTenant());
        $this->assertNull(TenantContext::getTenantId());

        // Should be able to set again after reset
        TenantContext::setTenantId(99);
        $this->assertSame(99, TenantContext::getTenantId());
    }

    /**
     * The system tenant (id 0) is a valid, settable tenant id and must NOT be
     * confused with the "not set" state. PR #82 relies on getTenantId() === 0.
     */
    public function testSystemTenantZeroIsDistinctFromUnset(): void
    {
        TenantContext::setTenantId(0);
        $this->assertSame(0, TenantContext::getTenantId());
        $this->assertTrue(TenantContext::hasTenant());
    }

    // ---------------------------------------------------------------------
    // getId() alias (AC #1 references getId())
    // ---------------------------------------------------------------------

    /**
     * getId() is an alias of getTenantId() per the acceptance criteria wording.
     */
    public function testGetIdIsAliasOfGetTenantId(): void
    {
        $this->assertNull(TenantContext::getId());
        TenantContext::setTenantId(7);
        $this->assertSame(7, TenantContext::getId());
    }

    // ---------------------------------------------------------------------
    // resolve() from JWT (AC #1, AC #2)
    // ---------------------------------------------------------------------

    /**
     * AC #1: a valid JWT carrying tenant_id makes the tenant id available for
     * the remainder of the request and returns it.
     */
    public function testResolveExtractsTenantIdFromBearerToken(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->with('valid.jwt.token')->willReturn([
            'user_id' => 123,
            'tenant_id' => 42,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer valid.jwt.token',
        ]);

        $resolved = TenantContext::resolve($request, $parser);

        $this->assertSame(42, $resolved);
        $this->assertSame(42, TenantContext::getTenantId());
        $this->assertTrue(TenantContext::hasTenant());
    }

    /**
     * AC #1: tenant id must be extractable from the access_token cookie too,
     * matching the middleware's existing token-extraction behaviour.
     */
    public function testResolveExtractsTenantIdFromCookie(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->with('cookie.jwt.token')->willReturn([
            'user_id' => 5,
            'tenant_id' => 11,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Cookie' => 'foo=bar; access_token=cookie.jwt.token; baz=qux',
        ]);

        $resolved = TenantContext::resolve($request, $parser);

        $this->assertSame(11, TenantContext::getTenantId());
        $this->assertSame(11, $resolved);
    }

    /**
     * AC #1 (codebase deviation): tenant ids are integers; a string numeric
     * claim must be coerced to int (system tenant "0" stays system tenant 0).
     */
    public function testResolveCoercesStringTenantIdToInt(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'user_id' => 1,
            'tenant_id' => '0',
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        $resolved = TenantContext::resolve($request, $parser);

        $this->assertSame(0, $resolved);
        $this->assertSame(0, TenantContext::getTenantId());
    }

    /**
     * AC #2: an unauthenticated request (no token at all) must throw a typed
     * exception, never fall back to a default tenant.
     */
    public function testResolveThrowsWhenNoToken(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $request = new Request('GET', '/api/resource');

        $this->expectException(TenantResolutionException::class);

        TenantContext::resolve($request, $parser);
    }

    /**
     * AC #2: an invalid/expired token (parser returns null) must throw.
     */
    public function testResolveThrowsWhenTokenInvalid(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn(null);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer bad.token.here',
        ]);

        $this->expectException(TenantResolutionException::class);

        TenantContext::resolve($request, $parser);
    }

    /**
     * AC #2: a valid token that lacks the tenant_id claim must throw, and must
     * NOT silently default to any tenant.
     */
    public function testResolveThrowsWhenTenantClaimMissing(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'user_id' => 9,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        try {
            TenantContext::resolve($request, $parser);
            $this->fail('Expected TenantResolutionException');
        } catch (TenantResolutionException $e) {
            $this->assertFalse(TenantContext::hasTenant(), 'No silent fallback tenant');
        }
    }

    /**
     * A non-numeric tenant_id claim is invalid and must throw rather than be
     * coerced to 0 (which would silently grant system access).
     */
    public function testResolveThrowsWhenTenantClaimNotNumeric(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'user_id' => 9,
            'tenant_id' => 'not-a-number',
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        $this->expectException(TenantResolutionException::class);

        TenantContext::resolve($request, $parser);
    }

    /**
     * Lifecycle guard: resolve() must not be silently called twice within the
     * same request without an intervening reset(), since the context is locked.
     */
    public function testResolveTwiceWithoutResetThrows(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'user_id' => 1,
            'tenant_id' => 3,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        TenantContext::resolve($request, $parser);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/locked/i');

        TenantContext::resolve($request, $parser);
    }

    /**
     * After reset(), resolve() works again (FrankenPHP worker reuse).
     */
    public function testResolveAfterResetWorks(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'user_id' => 1,
            'tenant_id' => 3,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        TenantContext::resolve($request, $parser);
        $this->assertSame(3, TenantContext::getTenantId());

        TenantContext::reset();
        $this->assertNull(TenantContext::getTenantId());

        TenantContext::resolve($request, $parser);
        $this->assertSame(3, TenantContext::getTenantId());
    }

    // ---------------------------------------------------------------------
    // Dual-claim window (WC-d4340daf): active_tenant_id preferred over tenant_id
    // ---------------------------------------------------------------------

    /**
     * A new-claims token resolves the tenant from active_tenant_id, even when a
     * legacy tenant_id claim is also present (dual-claim token). The new claim
     * is authoritative — it is the one the tenant switcher will re-mint.
     */
    public function testResolvePrefersActiveTenantIdOverLegacyTenantId(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'profile_id' => 77,
            'active_tenant_id' => 9,
            'user_id' => 1,
            'tenant_id' => 3,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        $resolved = TenantContext::resolve($request, $parser);

        $this->assertSame(9, $resolved);
        $this->assertSame(9, TenantContext::getTenantId());
    }

    /**
     * A legacy token (no active_tenant_id) must keep resolving from tenant_id
     * exactly as before — the dual-window fallback path.
     */
    public function testResolveFallsBackToLegacyTenantIdWhenNoActiveTenantId(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'user_id' => 1,
            'tenant_id' => 3,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        $this->assertSame(3, TenantContext::resolve($request, $parser));
    }

    /**
     * A numeric-string active_tenant_id is coerced to int, mirroring the
     * legacy tenant_id coercion behaviour ("0" = system tenant).
     */
    public function testResolveCoercesStringActiveTenantIdToInt(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'profile_id' => 4,
            'active_tenant_id' => '0',
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        $this->assertSame(0, TenantContext::resolve($request, $parser));
        $this->assertSame(0, TenantContext::getTenantId());
    }

    /**
     * A non-numeric active_tenant_id must throw (typed), never be coerced to 0
     * (which would silently grant system-tenant authority) and never fall back
     * to the legacy tenant_id claim (an attacker could then downgrade-pick).
     */
    public function testResolveThrowsWhenActiveTenantIdNotNumeric(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'profile_id' => 4,
            'active_tenant_id' => 'not-a-number',
            'tenant_id' => 3,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        $this->expectException(TenantResolutionException::class);

        TenantContext::resolve($request, $parser);
    }

    /**
     * A new-claims-only token (post-cutover shape, no legacy tenant_id at all)
     * must resolve from active_tenant_id alone.
     */
    public function testResolveWorksWithNewClaimsOnlyToken(): void
    {
        $parser = $this->createMock(JwtParser::class);
        $parser->method('parse')->willReturn([
            'profile_id' => 4,
            'active_tenant_id' => 12,
            'jti' => 'abc',
            'type' => 'access',
        ]);

        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer x.y.z',
        ]);

        $this->assertSame(12, TenantContext::resolve($request, $parser));
    }

    // ---------------------------------------------------------------------
    // System mode bypass + audit logging (AC #3)
    // ---------------------------------------------------------------------

    /**
     * AC #3: setSystemMode(true) enables scoping bypass.
     */
    public function testSetSystemModeEnablesBypass(): void
    {
        $this->assertFalse(TenantContext::isSystemMode());

        TenantContext::setSystemMode(true, 'migration-cli');
        $this->assertTrue(TenantContext::isSystemMode());

        TenantContext::setSystemMode(false, 'migration-cli');
        $this->assertFalse(TenantContext::isSystemMode());
    }

    /**
     * AC #3: enabling system mode is audit-logged with a structured record
     * including who/what enabled it.
     */
    public function testSetSystemModeAuditLogsActivation(): void
    {
        $records = [];
        $logger = new class ($records) extends AbstractLogger {
            /** @param list<array{level:mixed,message:string,context:array<string,mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        TenantContext::setLogger($logger);
        TenantContext::setSystemMode(true, 'db:migrate', ['reason' => 'schema upgrade']);

        $this->assertCount(1, $records, 'Exactly one audit record on activation');
        $record = $records[0];
        $this->assertStringContainsStringIgnoringCase('system mode', $record['message']);
        $this->assertSame('db:migrate', $record['context']['actor']);
        $this->assertTrue($record['context']['enabled']);
        $this->assertSame('schema upgrade', $record['context']['reason']);
        $this->assertArrayHasKey('tenant_id', $record['context']);
    }

    /**
     * AC #3: disabling system mode is also audit-logged (full lifecycle trail).
     */
    public function testSetSystemModeAuditLogsDeactivation(): void
    {
        $records = [];
        $logger = new class ($records) extends AbstractLogger {
            /** @param list<array<string,mixed>> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        TenantContext::setLogger($logger);
        TenantContext::setSystemMode(true, 'admin');
        TenantContext::setSystemMode(false, 'admin');

        $this->assertCount(2, $records);
        $this->assertFalse($records[1]['context']['enabled']);
    }

    /**
     * reset() must also clear system mode so a worker cannot leak elevated
     * privileges into the next request.
     */
    public function testResetClearsSystemMode(): void
    {
        TenantContext::setSystemMode(true, 'cli');
        $this->assertTrue(TenantContext::isSystemMode());

        TenantContext::reset();
        $this->assertFalse(TenantContext::isSystemMode());
    }
}
