<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\PaymentWall;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Integration tests for {@see PaymentWall} (WC-billing) over a real subscription
 * store. Proves the never-block invariants (system tenant, public/unauth, billing
 * routes), the 402 short-circuit + headers, block_writes read/write asymmetry, the
 * warn header, and the master off switch.
 */
final class PaymentWallRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const BILLING_URL = 'https://app.example/billing';

    private PDO $pdo;
    private SubscriptionService $subscriptions;

    /** @var callable(Request): Response A spy $next that records whether it ran. */
    private $next;
    private bool $nextCalled = false;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->subscriptions = new SubscriptionService(new SubscriptionRepository($this->pdo), $settings);

        $this->next = function (Request $r): Response {
            $this->nextCalled = true;
            return new Response(200, 'ok');
        };
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function wall(bool $enabled = true): PaymentWall
    {
        return new PaymentWall(
            $this->subscriptions,
            enabled: $enabled,
            exemptPrefixes: ['/api/v1/subscription'],
            billingUrl: self::BILLING_URL,
        );
    }

    private function dispatch(string $method, string $path, ?int $ctxTenant): Response
    {
        TenantContext::reset();
        if ($ctxTenant !== null) {
            TenantContext::setTenantId($ctxTenant);
        }
        $this->nextCalled = false;
        return $this->wall()->handle(new Request($method, $path), $this->next);
    }

    private function lapse(string $mode): void
    {
        $this->subscriptions->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_EXPIRED,
            'enforcement_mode' => $mode,
        ]);
    }

    // ── the deadlock list, as the app actually wires it ─────────────────────

    /**
     * EVERY EXEMPT PATH IS A PATH A LOCKED TENANT MUST REACH.
     *
     * The tests below build a wall with their own exempt list, which means none
     * of them can see the list the APPLICATION uses. That gap shipped a bug: the
     * billing page was exempt and reachable, and the endpoint it asks "may this
     * caller pay?" was not — so the owner of a locked workspace was told they
     * did not have permission to pay.
     *
     * So this exercises the real constant, through the wall, on the harshest
     * enforcement mode there is.
     */
    public function testEveryDeadlockExemptPathSurvivesTheStrictestWall(): void
    {
        $this->lapse(SubscriptionService::MODE_BLOCK_ALL);

        foreach (PaymentWall::DEADLOCK_EXEMPT_PREFIXES as $prefix) {
            TenantContext::reset();
            TenantContext::setTenantId(self::TENANT);
            $this->nextCalled = false;

            $wall = new PaymentWall(
                $this->subscriptions,
                enabled: true,
                exemptPrefixes: PaymentWall::DEADLOCK_EXEMPT_PREFIXES,
                billingUrl: self::BILLING_URL,
            );
            $res = $wall->handle(new Request('GET', $prefix), $this->next);

            self::assertSame(
                200,
                $res->getStatusCode(),
                "a locked tenant must still reach {$prefix}, or the wall is a deadlock"
            );
        }
    }

    /**
     * AND THE ONE THAT SHIPPED BROKEN, NAMED. A regression here is not a failing
     * request — it is a billing page that loads, lists prices, and refuses to
     * let the workspace owner buy any of them.
     */
    public function testALockedTenantCanStillLearnWhatItMayDo(): void
    {
        $this->lapse(SubscriptionService::MODE_BLOCK_ALL);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $this->nextCalled = false;

        $wall = new PaymentWall(
            $this->subscriptions,
            enabled: true,
            exemptPrefixes: PaymentWall::DEADLOCK_EXEMPT_PREFIXES,
            billingUrl: self::BILLING_URL,
        );
        $res = $wall->handle(new Request('GET', '/api/v1/me/capabilities'), $this->next);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->nextCalled, 'the request must actually reach the handler');
    }

    /**
     * THE LIST STAYS NARROW. These are matched as PREFIXES, so an entry of
     * `/api/v1/me` would unwall notifications, emails, inbox and preferences in
     * one stroke — a slice of the product nobody reviewed, opened by a string
     * that looks like a typo fix.
     */
    public function testTheExemptListDoesNotUnwallTheRestOfTheProduct(): void
    {
        $this->lapse(SubscriptionService::MODE_BLOCK_ALL);

        foreach (['/api/v1/me/notifications', '/api/v1/me/emails', '/api/v1/me', '/api/v1/documents'] as $path) {
            TenantContext::reset();
            TenantContext::setTenantId(self::TENANT);
            $this->nextCalled = false;

            $wall = new PaymentWall(
                $this->subscriptions,
                enabled: true,
                exemptPrefixes: PaymentWall::DEADLOCK_EXEMPT_PREFIXES,
                billingUrl: self::BILLING_URL,
            );
            $res = $wall->handle(new Request('GET', $path), $this->next);

            self::assertSame(402, $res->getStatusCode(), "{$path} must stay behind the wall");
        }
    }

    // ── never-block invariants ──────────────────────────────────────────────

    public function testSystemTenantIsNeverWalled(): void
    {
        // Even with a (hypothetical) lapsed state, ctx tenant 0 always passes.
        $res = $this->dispatch('POST', '/api/v1/things', 0);
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->nextCalled);
    }

    public function testPublicRouteWithUnresolvedTenantPasses(): void
    {
        $res = $this->dispatch('POST', '/api/v1/login', null);
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->nextCalled);
    }

    public function testNoSubscriptionPasses(): void
    {
        $res = $this->dispatch('POST', '/api/v1/things', self::TENANT);
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->nextCalled);
    }

    public function testBillingRouteAlwaysReachableEvenWhenBlocked(): void
    {
        $this->lapse(SubscriptionService::MODE_BLOCK_ALL);
        $res = $this->dispatch('POST', '/api/v1/subscription/upgrade', self::TENANT);
        self::assertSame(200, $res->getStatusCode(), 'the upgrade path must never be behind the wall');
        self::assertTrue($this->nextCalled);
    }

    // ── enforcement ─────────────────────────────────────────────────────────

    public function testBlockAllReturns402WithHeaders(): void
    {
        $this->lapse(SubscriptionService::MODE_BLOCK_ALL);
        $res = $this->dispatch('GET', '/api/v1/things', self::TENANT);

        self::assertSame(402, $res->getStatusCode());
        self::assertFalse($this->nextCalled, 'downstream handler must not run');
        $headers = $res->getHeaders();
        self::assertSame(SubscriptionService::STATUS_EXPIRED, $headers['x-subscription-status'] ?? null);
        self::assertStringContainsString(self::BILLING_URL, $headers['link'] ?? '');
    }

    public function testBlockWritesBlocksWritesButAllowsReads(): void
    {
        $this->lapse(SubscriptionService::MODE_BLOCK_WRITES);

        self::assertSame(402, $this->dispatch('POST', '/api/v1/things', self::TENANT)->getStatusCode());

        $read = $this->dispatch('GET', '/api/v1/things', self::TENANT);
        self::assertSame(200, $read->getStatusCode());
        self::assertTrue($this->nextCalled);
        self::assertSame(SubscriptionService::STATUS_EXPIRED, $read->getHeaders()['x-subscription-status'] ?? null);
    }

    public function testWarnModeAllowsAndStampsHeader(): void
    {
        $this->lapse(SubscriptionService::MODE_WARN);
        $res = $this->dispatch('POST', '/api/v1/things', self::TENANT);

        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->nextCalled);
        self::assertSame(SubscriptionService::STATUS_EXPIRED, $res->getHeaders()['x-subscription-status'] ?? null);
    }

    public function testActiveSubscriptionPasses(): void
    {
        $this->subscriptions->setSubscription(self::TENANT, ['status' => SubscriptionService::STATUS_ACTIVE]);
        $res = $this->dispatch('POST', '/api/v1/things', self::TENANT);
        self::assertSame(200, $res->getStatusCode());
        self::assertArrayNotHasKey('x-subscription-status', $res->getHeaders());
    }

    public function testDisabledWallPassesEverything(): void
    {
        $this->lapse(SubscriptionService::MODE_BLOCK_ALL);
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $this->nextCalled = false;

        $res = $this->wall(enabled: false)->handle(new Request('POST', '/api/v1/things'), $this->next);
        self::assertSame(200, $res->getStatusCode());
        self::assertTrue($this->nextCalled);
    }
}
