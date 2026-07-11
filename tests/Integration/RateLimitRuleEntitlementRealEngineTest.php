<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;

/**
 * Real-engine tests for the plan-driven per-tenant rate rule + platform rule
 * (WC-billing / WC-c0fb3700). The per-tenant budget scales with the tenant's
 * ratelimit.rpm entitlement, falling back to the platform baseline when the plan
 * sets none.
 */
final class RateLimitRuleEntitlementRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const FALLBACK = 10000;

    private PDO $pdo;
    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function rule(): RateLimitRule
    {
        return RateLimitRule::tenantEntitled($this->entitlements, self::FALLBACK, 60);
    }

    private function req(): Request
    {
        return new Request('GET', '/api/v1/things');
    }

    public function testResolvesTenantIdAndSkipsSystemTenant(): void
    {
        $rule = $this->rule();

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        self::assertSame((string) self::TENANT, ($rule->resolve)($this->req()));

        TenantContext::reset();
        TenantContext::setTenantId(0);
        self::assertNull(($rule->resolve)($this->req()), 'system tenant is never rate limited');
    }

    public function testFallsBackToBaselineWhenNoPlanCap(): void
    {
        // Default entitlement is -1 → no plan cap → the platform baseline applies.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        self::assertSame(self::FALLBACK, $this->rule()->limitFor($this->req()));
    }

    public function testUsesThePlansRpmWhenSet(): void
    {
        $this->entitlements->set(self::TENANT, EntitlementRegistry::RATELIMIT_RPM, '500', null);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        self::assertSame(500, $this->rule()->limitFor($this->req()));
    }

    public function testHigherTierRaisesTheBudget(): void
    {
        $this->entitlements->set(self::TENANT, EntitlementRegistry::RATELIMIT_RPM, '250000', null);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        self::assertSame(250000, $this->rule()->limitFor($this->req()));
    }

    public function testPlatformRuleUsesOneSharedCounterAndFixedLimit(): void
    {
        $rule = RateLimitRule::platform(100000, 60);
        self::assertSame('all', ($rule->resolve)($this->req()), 'one shared counter across all requests');
        self::assertSame(100000, $rule->limitFor($this->req()));
        self::assertSame('platform', $rule->name);
    }

    public function testFixedRuleLimitForReturnsFixedLimit(): void
    {
        // A rule without a per-request resolver just returns its fixed limit
        // (existing ip/tenant/principal behaviour is unchanged).
        $rule = RateLimitRule::ip(2000, 60);
        self::assertSame(2000, $rule->limitFor($this->req()));
    }
}
