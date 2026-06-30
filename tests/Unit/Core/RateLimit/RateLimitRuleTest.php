<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use Whity\Core\Audit\AuditContext;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;

/**
 * WC-c0fb3700: the standard rate-limit rule factories.
 *
 * ip()/tenant()/principal() wire the three pipeline dimensions to their sources:
 * the request's forwarding headers, the resolved TenantContext, and the
 * authenticated principal recorded in AuditContext. Each resolver returns null
 * when its dimension is absent, so the middleware simply skips it.
 */
final class RateLimitRuleTest extends TestCase
{
    protected function setUp(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        AuditContext::reset();
    }

    public function testIpRuleResolvesClientIp(): void
    {
        $rule = RateLimitRule::ip(100, 60);

        self::assertSame('ip', $rule->name);
        self::assertSame(100, $rule->limit);
        self::assertSame(60, $rule->window);
        self::assertSame('203.0.113.7', ($rule->resolve)(new Request('GET', '/x', ['X-Forwarded-For' => '203.0.113.7'])));
    }

    public function testIpRuleFailsClosedWhenNoClientIp(): void
    {
        // No forwarding headers must NOT skip the dimension — it buckets into a
        // shared sentinel so a flood of header-less requests is still bounded.
        $rule = RateLimitRule::ip(100, 60);

        self::assertSame(RateLimitRule::IP_UNKNOWN, ($rule->resolve)(new Request('GET', '/x')));
    }

    public function testTenantRuleResolvesTenantContext(): void
    {
        $rule = RateLimitRule::tenant(100, 60);
        $req  = new Request('GET', '/x');

        self::assertSame('tenant', $rule->name);
        self::assertNull(($rule->resolve)($req), 'no tenant resolved → skipped');

        TenantContext::setTenantId(42);
        self::assertSame('42', ($rule->resolve)($req));
    }

    public function testTenantRuleSkipsSystemTenant(): void
    {
        $rule = RateLimitRule::tenant(100, 60);
        TenantContext::setTenantId(0); // system tenant — trusted, not rate limited

        self::assertNull(($rule->resolve)(new Request('GET', '/x')));
    }

    public function testPrincipalRuleResolvesAuditActor(): void
    {
        $rule = RateLimitRule::principal(100, 60);
        $req  = new Request('GET', '/x');

        self::assertSame('principal', $rule->name);
        self::assertNull(($rule->resolve)($req), 'no authenticated principal → skipped');

        AuditContext::set(123, '203.0.113.7');
        self::assertSame('123', ($rule->resolve)($req));
    }
}
