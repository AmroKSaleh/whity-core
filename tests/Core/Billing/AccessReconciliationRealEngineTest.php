<?php

declare(strict_types=1);

namespace Tests\Core\Billing;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Api\FakeBillingPortal;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Billing\External\AccessRecorder;
use Whity\Core\Billing\External\AccessReconciliationRun;
use Whity\Core\Billing\External\AccessSnapshot;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\NullBillingPortal;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;

/**
 * The sweep that makes a missed notification cost latency instead of correctness.
 *
 * Notifications are best-effort with a bounded number of retries. This endpoint
 * WILL be down during a deploy, or behind a certificate that expired on a
 * Sunday. A product whose access control depends only on them arriving will
 * eventually charge someone and not let them in.
 *
 * The property that matters most here is the one about SILENCE. When the billing
 * service cannot be reached, the honest local answer is "I do not know" — and
 * the one thing that must never follow from not knowing is locking out paying
 * customers. Their outage would otherwise become ours, amplified: every tenant
 * revoked at once, at the exact moment nobody can take a payment to fix it.
 */
final class AccessReconciliationRealEngineTest extends TestCase
{
    private PDO $pdo;
    private FakeBillingPortal $portal;
    private SubscriptionService $subscriptions;
    private AccessRecorder $recorder;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'one', 'one')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'two', 'two')");
        SchemaFromMigrations::syncSequences($this->pdo);

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );
        $this->subscriptions = new SubscriptionService(
            new SubscriptionRepository($this->pdo),
            $settings,
        );
        $this->recorder = new AccessRecorder(
            $this->subscriptions,
            new PlanRepository($this->pdo),
            new NullLogger()
        );
        $this->portal = new FakeBillingPortal();
    }

    /**
     * THE TEST THIS JOB EXISTS FOR. A notification was missed, the local copy
     * says the tenant is finished, and the billing service says they paid. The
     * sweep is what lets them back in without anyone noticing there was a
     * problem.
     */
    public function testASweepCorrectsAccessAMissedNotificationLeftStale(): void
    {
        $this->localState(1, 'expired');
        $this->portal->access = $this->snapshot(true, 'active');

        $result = $this->sweeper()->run();

        self::assertSame(1, $result['checked']);
        self::assertSame(1, $result['changed']);
        self::assertSame(SubscriptionService::STATUS_ACTIVE, $this->statusOf(1));
        self::assertTrue($this->subscriptions->decide(1, true)->allowed);
    }

    /**
     * AN OUTAGE MUST NOT REVOKE ANYBODY. Being unable to ask is not being told
     * no, and a sweep that treated it as one would lock out every paying
     * customer simultaneously — precisely when no payment can be taken to undo
     * it.
     */
    public function testAnUnreachableServiceLeavesEveryTenantExactlyAsTheyWere(): void
    {
        $this->localState(1, 'active');
        $this->portal->failWith = BillingPortalException::unreachable('connection refused');

        $result = $this->sweeper()->run();

        self::assertSame(0, $result['checked']);
        self::assertSame(1, $result['unreachable']);
        self::assertSame(
            SubscriptionService::STATUS_ACTIVE,
            $this->statusOf(1),
            'a tenant whose access could not be checked keeps what they have'
        );
        self::assertTrue($this->subscriptions->decide(1, true)->allowed);
    }

    /**
     * A LAPSED TENANT IS STILL SWEPT. Checking only the tenants we believe have
     * access would never notice the payment that brought somebody back — and a
     * customer who paid to return is exactly the one who must not wait on a
     * notification that already failed to arrive once.
     */
    public function testATenantWhoPaidToComeBackIsPickedUp(): void
    {
        $this->localState(1, 'expired');
        $this->portal->access = $this->snapshot(true, 'active');

        $this->sweeper()->run();

        self::assertTrue($this->subscriptions->decide(1, true)->allowed);
    }

    /** Being told "no" is applied immediately — that is an answer, not silence. */
    public function testATenantTheServiceSaysHasLapsedIsRevoked(): void
    {
        $this->localState(1, 'active');
        $this->portal->access = $this->snapshot(false, 'expired');

        $this->sweeper()->run();

        self::assertSame(SubscriptionService::STATUS_EXPIRED, $this->statusOf(1));
    }

    /**
     * A tenant with no billing state at all is not swept — there is nothing to
     * reconcile, and asking about every tenant on a deployment that bills two of
     * them would be a request per tenant per sweep, forever.
     */
    public function testTenantsWithNoBillingStateAreNotAskedAbout(): void
    {
        $this->localState(1, 'active');
        $this->portal->access = $this->snapshot(true, 'active');

        $this->sweeper()->run();

        self::assertSame(1, $this->portal->accessCalls, 'tenant 2 has no billing state to reconcile');
    }

    /**
     * BEING RATE LIMITED IS NOT BEING TOLD NO, and it must not read as one.
     *
     * It arrives as a 4xx, like every genuine refusal — and it is the only 4xx
     * that becomes untrue by waiting. Classified as a refusal it would mark a
     * paying tenant as a failed reconciliation on nothing more than this job
     * having asked too often.
     */
    public function testRateLimitingNeverRevokesAndIsNotCountedAsARefusal(): void
    {
        $this->localState(1, 'active');
        $this->portal->failWith = BillingPortalException::rateLimited('429');

        $result = $this->sweeper()->run();

        self::assertTrue($result['rate_limited']);
        self::assertSame(0, $result['skipped'], 'a rate limit is not a refusal');
        self::assertSame(
            SubscriptionService::STATUS_ACTIVE,
            $this->statusOf(1),
            'a tenant we were not allowed to ask about keeps what they have'
        );
    }

    /**
     * AND THE SWEEP STOPS RATHER THAN HAMMERING. Carrying on would spend a
     * request per remaining tenant against an allowance already exhausted,
     * starving the checkouts and returns a customer is actually waiting on —
     * and none of those tenants would get an answer either.
     */
    public function testTheSweepStopsAtTheFirstRateLimitInsteadOfHammering(): void
    {
        foreach ([1, 2] as $tenantId) {
            $this->localState($tenantId, 'active');
        }
        $this->portal->failWith = BillingPortalException::rateLimited('429');

        $this->sweeper()->run();

        self::assertSame(
            1,
            $this->portal->accessAttempts,
            'the second tenant must not be asked about once the limit is known'
        );
    }

    /** A deployment that bills nobody has nothing to sweep and must not try. */
    public function testADeploymentWithNoBillingServiceSweepsNothing(): void
    {
        $this->localState(1, 'active');

        $run = new AccessReconciliationRun(
            $this->pdo,
            new NullBillingPortal(),
            $this->recorder,
            new NullLogger()
        );

        self::assertSame(
            ['checked' => 0, 'changed' => 0, 'unreachable' => 0, 'skipped' => 0, 'rate_limited' => false],
            $run->run()
        );
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function sweeper(): AccessReconciliationRun
    {
        return new AccessReconciliationRun(
            $this->pdo,
            $this->portal,
            $this->recorder,
            new NullLogger()
        );
    }

    private function snapshot(bool $hasAccess, string $status): AccessSnapshot
    {
        return new AccessSnapshot(
            'tenant-1',
            $hasAccess,
            null,
            $status,
            '2026-12-01T00:00:00+00:00',
            false,
            'sub_01'
        );
    }

    private function localState(int $tenantId, string $status): void
    {
        $this->subscriptions->setSubscription($tenantId, [
            'status' => $status,
            'external_ref' => 'sub_01',
        ]);
    }

    private function statusOf(int $tenantId): ?string
    {
        $statement = $this->pdo->prepare('SELECT status FROM tenant_plan WHERE tenant_id = :t');
        $statement->execute([':t' => $tenantId]);
        $status = $statement->fetchColumn();

        return is_string($status) && $status !== '' ? $status : null;
    }
}
