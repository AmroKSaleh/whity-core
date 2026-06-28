<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\RateLimit;

use PHPUnit\Framework\TestCase;
use Whity\Core\Store\ArraySharedStore;
use Whity\Mcp\RateLimit\McpRateLimitException;
use Whity\Mcp\RateLimit\McpRateLimiter;

/**
 * TDD tests for McpRateLimiter (WC-a89ece0d).
 *
 * Verifies that per-tenant and per-principal fixed-window budgets are enforced
 * independently, that McpRateLimitException is thrown with the correct
 * Retry-After value, and that different tenants / principals share no counter
 * state.
 */
final class McpRateLimiterTest extends TestCase
{
    private const TENANT_ID    = 10;
    private const USER_ID      = 42;
    private const OTHER_TENANT = 20;
    private const OTHER_USER   = 99;

    // ── Below both limits: no exception ──────────────────────────────────────

    public function testCheckAndRecord_doesNotThrow_whenBothLimitsNotReached(): void
    {
        $limiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 5, principalLimit: 5);

        for ($i = 0; $i < 5; $i++) {
            $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
        }

        $this->expectNotToPerformAssertions();
    }

    // ── Tenant limit ──────────────────────────────────────────────────────────

    public function testCheckAndRecord_throwsMcpRateLimitException_whenTenantLimitExceeded(): void
    {
        // tenantLimit = 2; the third call must throw.
        $limiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 2, principalLimit: 100);

        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);

        $this->expectException(McpRateLimitException::class);
        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
    }

    // ── Principal limit ───────────────────────────────────────────────────────

    public function testCheckAndRecord_throwsMcpRateLimitException_whenPrincipalLimitExceeded(): void
    {
        // principalLimit = 2; the third call must throw.
        $limiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 100, principalLimit: 2);

        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);

        $this->expectException(McpRateLimitException::class);
        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
    }

    // ── Retry-After value ─────────────────────────────────────────────────────

    public function testException_carriesWindowSecondsAsRetryAfter(): void
    {
        $limiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 0, principalLimit: 100);

        try {
            $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
            self::fail('Expected McpRateLimitException was not thrown');
        } catch (McpRateLimitException $e) {
            self::assertSame(McpRateLimiter::WINDOW_SECONDS, $e->getRetryAfterSeconds());
        }
    }

    // ── Counter independence ──────────────────────────────────────────────────

    public function testDifferentTenants_haveIndependentCounters(): void
    {
        // tenantLimit = 1; both tenant counters start at 0, so each gets one call.
        $limiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 1, principalLimit: 100);

        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
        // OTHER_TENANT has a fresh counter — must NOT throw.
        $limiter->checkAndRecord(self::OTHER_TENANT, self::USER_ID);

        $this->expectNotToPerformAssertions();
    }

    public function testDifferentPrincipals_haveIndependentCounters(): void
    {
        // principalLimit = 1; both principal counters start at 0.
        $limiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 100, principalLimit: 1);

        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
        // OTHER_USER has a fresh counter — must NOT throw.
        $limiter->checkAndRecord(self::TENANT_ID, self::OTHER_USER);

        $this->expectNotToPerformAssertions();
    }

    // ── Tenant limit checked before principal limit ───────────────────────────

    public function testTenantLimitEnforced_beforePrincipalCheck(): void
    {
        // tenantLimit = 0 means the FIRST call throws on the tenant counter,
        // regardless of the principal counter's value.
        $limiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 0, principalLimit: 100);

        $this->expectException(McpRateLimitException::class);
        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
    }

    // ── Shared tenant counter across principals ───────────────────────────────

    public function testTenantCounter_isSharedAcrossPrincipals(): void
    {
        // tenantLimit = 2; two different principals each call once → tenant
        // counter reaches 2. A third call from any principal must throw.
        $limiter = new McpRateLimiter(new ArraySharedStore(), tenantLimit: 2, principalLimit: 100);

        $limiter->checkAndRecord(self::TENANT_ID, self::USER_ID);
        $limiter->checkAndRecord(self::TENANT_ID, self::OTHER_USER);

        $this->expectException(McpRateLimitException::class);
        $limiter->checkAndRecord(self::TENANT_ID, 999);
    }
}
