<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionException;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;

/**
 * Real-engine tests for {@see SubscriptionService} (WC-billing): the payment-wall
 * decision. Covers the safe-by-construction invariants (system tenant + no
 * subscription never blocked), each status × enforcement mode, the grace window,
 * and the per-tenant-mode-over-global-default precedence.
 */
final class SubscriptionServiceRealEngineTest extends TestCase
{
    private const NOW = 1_000_000_000;
    private const TENANT = 1;

    private PDO $pdo;
    private SettingsService $settings;
    private SubscriptionService $service;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a')");
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->service = new SubscriptionService(
            new SubscriptionRepository($this->pdo),
            $this->settings,
            fn (): int => self::NOW
        );
    }

    private function ts(int $offsetSeconds): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW + $offsetSeconds);
    }

    // ── safe invariants ─────────────────────────────────────────────────────

    public function testSystemTenantIsNeverBlocked(): void
    {
        self::assertTrue($this->service->decide(0, true)->allowed);
    }

    public function testNoSubscriptionIsNeverBlocked(): void
    {
        $d = $this->service->decide(self::TENANT, true);
        self::assertTrue($d->allowed);
        self::assertFalse($d->warn);
        self::assertNull($d->status);
    }

    public function testActiveAndTrialingAllow(): void
    {
        $this->service->setSubscription(self::TENANT, ['status' => SubscriptionService::STATUS_ACTIVE]);
        self::assertTrue($this->service->decide(self::TENANT, true)->allowed);

        $this->service->setSubscription(self::TENANT, ['status' => SubscriptionService::STATUS_TRIALING]);
        self::assertTrue($this->service->decide(self::TENANT, true)->allowed);
    }

    // ── enforcement modes on a lapsed subscription ──────────────────────────

    public function testExpiredBlockAllBlocksReadsAndWrites(): void
    {
        $this->service->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_EXPIRED,
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
        ]);
        self::assertFalse($this->service->decide(self::TENANT, true)->allowed);
        self::assertFalse($this->service->decide(self::TENANT, false)->allowed);
    }

    public function testCanceledBlockWritesBlocksWritesButAllowsReads(): void
    {
        $this->service->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_CANCELED,
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_WRITES,
        ]);
        self::assertFalse($this->service->decide(self::TENANT, true)->allowed, 'writes blocked');
        $read = $this->service->decide(self::TENANT, false);
        self::assertTrue($read->allowed, 'reads allowed');
        self::assertTrue($read->warn, 'reads warned');
    }

    public function testExpiredWarnAllowsWithWarning(): void
    {
        $this->service->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_EXPIRED,
            'enforcement_mode' => SubscriptionService::MODE_WARN,
        ]);
        $d = $this->service->decide(self::TENANT, true);
        self::assertTrue($d->allowed);
        self::assertTrue($d->warn);
        self::assertSame(SubscriptionService::STATUS_EXPIRED, $d->status);
    }

    public function testExpiredOffNeverBlocksOrWarns(): void
    {
        $this->service->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_EXPIRED,
            'enforcement_mode' => SubscriptionService::MODE_OFF,
        ]);
        $d = $this->service->decide(self::TENANT, true);
        self::assertTrue($d->allowed);
        self::assertFalse($d->warn);
    }

    // ── grace window ────────────────────────────────────────────────────────

    public function testPastDueWithinGraceIsAllowed(): void
    {
        $this->service->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_PAST_DUE,
            'grace_until' => $this->ts(86400), // 1 day in the future
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
        ]);
        self::assertTrue($this->service->decide(self::TENANT, true)->allowed);
    }

    public function testPastDueBeyondGraceIsBlocked(): void
    {
        $this->service->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_PAST_DUE,
            'grace_until' => $this->ts(-86400), // 1 day in the past
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_ALL,
        ]);
        self::assertFalse($this->service->decide(self::TENANT, true)->allowed);
    }

    // ── per-tenant mode vs global default ───────────────────────────────────

    public function testPerTenantModeOverridesGlobalDefault(): void
    {
        $this->settings->setGlobal(SettingsRegistry::BILLING_ENFORCEMENT_DEFAULT, SubscriptionService::MODE_BLOCK_ALL);
        // Per-tenant 'off' wins over the strict global default.
        $this->service->setSubscription(self::TENANT, [
            'status' => SubscriptionService::STATUS_EXPIRED,
            'enforcement_mode' => SubscriptionService::MODE_OFF,
        ]);
        self::assertTrue($this->service->decide(self::TENANT, true)->allowed);
    }

    public function testGlobalDefaultAppliesWhenNoPerTenantMode(): void
    {
        $this->settings->setGlobal(SettingsRegistry::BILLING_ENFORCEMENT_DEFAULT, SubscriptionService::MODE_BLOCK_ALL);
        $this->service->setSubscription(self::TENANT, ['status' => SubscriptionService::STATUS_EXPIRED]);
        self::assertFalse($this->service->decide(self::TENANT, true)->allowed);
    }

    public function testDefaultGlobalModeIsWarnSoAFreshDeployIsNeverBlocked(): void
    {
        // No global override set → registry default 'warn' → lapsed tenant allowed.
        $this->service->setSubscription(self::TENANT, ['status' => SubscriptionService::STATUS_EXPIRED]);
        $d = $this->service->decide(self::TENANT, true);
        self::assertTrue($d->allowed);
        self::assertTrue($d->warn);
    }

    // ── writes ──────────────────────────────────────────────────────────────

    public function testSetSubscriptionRejectsSystemTenant(): void
    {
        $this->expectException(SubscriptionException::class);
        $this->service->setSubscription(0, ['status' => SubscriptionService::STATUS_ACTIVE]);
    }

    public function testSetSubscriptionRejectsInvalidStatusAndMode(): void
    {
        try {
            $this->service->setSubscription(self::TENANT, ['status' => 'gremlin']);
            self::fail('expected exception');
        } catch (SubscriptionException) {
        }
        $this->expectException(SubscriptionException::class);
        $this->service->setSubscription(self::TENANT, ['enforcement_mode' => 'nuke']);
    }

    public function testSetSubscriptionMergesFields(): void
    {
        $this->service->setSubscription(self::TENANT, ['status' => SubscriptionService::STATUS_ACTIVE]);
        $this->service->setSubscription(self::TENANT, ['external_ref' => 'sub_ext_123']);

        $sub = $this->service->getSubscription(self::TENANT);
        self::assertNotNull($sub);
        self::assertSame(SubscriptionService::STATUS_ACTIVE, $sub['status'], 'status survives a later partial write');
        self::assertSame('sub_ext_123', $sub['external_ref']);
    }
}
