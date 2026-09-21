<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Api\FakeBillingPortal;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\BillingPortalException;
use Whity\Core\Billing\External\TierMigration;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\Plan\PlanValidationException;

/**
 * Moving a PAYING customer between tiers.
 *
 * ── The failure this exists to prevent ─────────────────────────────────────
 *
 * Moving a workspace by updating `tenant_plan` works, looks right, and is
 * undone within minutes for anybody who actually pays. The billing service is
 * authoritative for what somebody is buying — {@see \Whity\Core\Billing\External\AccessRecorder}
 * writes its `plan` straight into `tenant_plan`, and the reconciliation sweep
 * re-asks constantly — so a local change loses to the next sweep.
 *
 * Observed on staging: three workspaces moved off a retiring tier were back on
 * it fifteen minutes later. The sweep was RIGHT; we had changed our record of
 * what they were paying for without changing what they were paying for.
 *
 * So the move happens at the billing service FIRST, and locally only if that
 * succeeded. The two must never disagree, because when they do, the sweep
 * resolves it against us and the customer sees the wrong features in between.
 */
final class TierMigrationRealEngineTest extends TestCase
{
    private PDO $pdo;
    private PlanRepository $plans;
    private PlanService $service;
    private FakeBillingPortal $portal;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec(
            "INSERT INTO tenants (id, name, slug) VALUES (1,'a','a'), (2,'b','b'), (3,'c','c')"
        );

        $this->plans = new PlanRepository($this->pdo);
        $this->service = new PlanService(
            $this->plans,
            new EntitlementService(new TenantEntitlementRepository($this->pdo)),
            $this->pdo,
            new AuditLogger($this->pdo),
        );
        $this->portal = new FakeBillingPortal();
    }

    // ── The local path refuses what it cannot deliver ───────────────────────

    /**
     * THE HEADLINE. A local move of a paying customer is refused outright,
     * rather than performed and silently reverted.
     */
    public function testALocalMoveOfAnExternallyBilledWorkspaceIsRefused(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->subscribe(1, $old, externalRef: 'sub_live_1');

        try {
            $this->service->moveSubscribers($old, $new);
            self::fail('Moving a paying customer locally must be refused.');
        } catch (PlanValidationException $e) {
            self::assertStringContainsString('billed externally', $e->reason());
        }

        self::assertSame($old, $this->planIdOf(1), 'Nothing may have moved.');
    }

    /** A workspace an operator assigned by hand still moves locally — that path is correct. */
    public function testALocalMoveOfAnOperatorAssignedWorkspaceStillWorks(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->subscribe(1, $old, externalRef: null);

        self::assertSame(1, $this->service->moveSubscribers($old, $new));
        self::assertSame($new, $this->planIdOf(1));
    }

    /** One paying customer blocks the batch — a half-migrated tier is worse than none. */
    public function testOnePayingWorkspaceBlocksTheWholeLocalMove(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->subscribe(1, $old, externalRef: null);
        $this->subscribe(2, $old, externalRef: 'sub_live_2');

        $this->expectException(PlanValidationException::class);
        $this->service->moveSubscribers($old, $new);
    }

    // ── The migration that does work ────────────────────────────────────────

    /** THE BILLING SERVICE FIRST, then us. */
    public function testAPayingWorkspaceIsMovedAtTheBillingServiceAndThenLocally(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->price($new, 'price_pro');
        $this->subscribe(1, $old, externalRef: 'sub_live_1');

        $result = $this->migration()->move($old, $new);

        self::assertSame(1, $result['moved']);
        self::assertSame($new, $this->planIdOf(1));

        self::assertCount(1, $this->portal->planChanges);
        self::assertSame('sub_live_1', $this->portal->planChanges[0]['subscription']);
        self::assertSame('price_pro', $this->portal->planChanges[0]['price']);
    }

    /**
     * A TIER MOVE SAYS WHO ASKED FOR IT.
     *
     * This is the question that was unanswerable the last time this ran for
     * real: three workspaces changed tier and `tenant_plan` holds only current
     * state, so the record of who had been on what had to be rebuilt from a dump
     * taken beforehand. The billing service records the client that called it
     * and nothing finer, so unless we name the person, nobody can.
     */
    public function testAMoveNamesThePersonWhoAskedForIt(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->price($new, 'price_pro');
        $this->subscribe(1, $old, externalRef: 'sub_live_1');

        $this->migration()->move($old, $new, movedBy: 412);

        self::assertSame('profile:412', $this->portal->planChanges[0]['actor']);
    }

    /**
     * AN UNATTRIBUTED MOVE SAYS SO, rather than borrowing a name.
     *
     * Labelling it as an ordinary scheduled job would be an invention in the
     * other direction: whoever read that audit line later could not tell it from
     * a sweep that runs every night. "Nobody was named" is a fact worth
     * recording as itself.
     */
    public function testAnUnattributedMoveDoesNotBorrowAName(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->price($new, 'price_pro');
        $this->subscribe(1, $old, externalRef: 'sub_live_1');

        $this->migration()->move($old, $new);

        self::assertSame(
            'job:tier-migration-unattributed',
            $this->portal->planChanges[0]['actor'],
            'An unnamed mover must not be recorded as a person or as a routine job.'
        );
    }

    /**
     * IT IS NOT A PURCHASE. Nobody asked to be migrated, so nothing is charged
     * and no invoice is raised — not even a zero-amount one, which still reads
     * to a customer like they bought something.
     */
    public function testAMigrationChargesNothingAndRaisesNoInvoice(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->price($new, 'price_pro');
        $this->subscribe(1, $old, externalRef: 'sub_live_1');

        $this->migration()->move($old, $new);

        self::assertSame(BillingPortal::PRORATION_NONE, $this->portal->planChanges[0]['proration']);
        self::assertFalse($this->portal->planChanges[0]['invoice']);
    }

    /**
     * A REFUSAL AT THE BILLING SERVICE LEAVES US ALONE. Their subscription still
     * says the old tier, so ours must too — otherwise the two disagree until a
     * sweep resolves it against us, and the customer sees the wrong features in
     * between.
     */
    public function testAFailedPlanChangeDoesNotMoveTheWorkspaceLocally(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->price($new, 'price_pro');
        $this->subscribe(1, $old, externalRef: 'sub_live_1');
        $this->portal->failPlanChangeWith = BillingPortalException::unreachable('timeout');

        $result = $this->migration()->move($old, $new);

        self::assertSame(1, $result['failed']);
        self::assertSame(0, $result['moved']);
        self::assertSame($old, $this->planIdOf(1), 'A failed remote move must not move us.');
    }

    /** One failure does not abandon the rest — a half-migrated tier needs finishing, not hiding. */
    public function testOneFailureDoesNotStopTheOthers(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->price($new, 'price_pro');
        $this->subscribe(1, $old, externalRef: null);
        $this->subscribe(2, $old, externalRef: 'sub_live_2');

        $result = $this->migration()->move($old, $new);

        self::assertSame(1, $result['local']);
        self::assertSame(1, $result['moved']);
        self::assertSame($new, $this->planIdOf(1));
        self::assertSame($new, $this->planIdOf(2));
    }

    /**
     * A DESTINATION THE BILLING SERVICE DOES NOT SELL IS SKIPPED, LOUDLY. There
     * is nothing to move the subscription onto, and moving it locally would be
     * undone by the next sweep.
     */
    public function testAPayingWorkspaceIsSkippedWhenTheDestinationHasNoExternalPrice(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->subscribe(1, $old, externalRef: 'sub_live_1');

        $result = $this->migration()->move($old, $new);

        self::assertSame(1, $result['skipped']);
        self::assertSame($old, $this->planIdOf(1));
        self::assertStringContainsString('no price on the billing service', $result['reasons'][0]);
        self::assertSame([], $this->portal->planChanges, 'Nothing may be asked of the billing service.');
    }

    /** Every move is recorded, whichever path it took. */
    public function testEveryMigratedWorkspaceIsRecorded(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->price($new, 'price_pro');
        $this->subscribe(1, $old, externalRef: null);
        $this->subscribe(2, $old, externalRef: 'sub_live_2');

        $this->migration()->move($old, $new);

        $statement = $this->pdo->query(
            "SELECT COUNT(*) FROM audit_log WHERE action = 'plan.subscriber.moved'"
        );
        self::assertSame(2, $statement === false ? -1 : (int) $statement->fetchColumn());
    }

    /**
     * Moving a tier onto itself does nothing rather than churning subscriptions.
     *
     * THE SOURCE TIER IS PRICED HERE ON PURPOSE. Without that, this test passed
     * even with the guard removed — the migration would have found no external
     * price to move onto and skipped, so the assertion held for the wrong
     * reason. Found by mutation.
     */
    public function testMovingOntoTheSameTierDoesNothing(): void
    {
        [$old] = $this->twoTiers();
        $this->price($old, 'price_starter');
        $this->subscribe(1, $old, externalRef: 'sub_live_1');

        $result = $this->migration()->move($old, $old);

        self::assertSame(0, $result['moved']);
        self::assertSame([], $this->portal->planChanges, 'Nothing may be asked of the billing service.');
    }

    /**
     * THE RETRY KEY IS DERIVED FROM THE MOVE, not generated per attempt.
     *
     * This is the difference between idempotency that works and idempotency
     * that is theoretical. A random key on every attempt is indistinguishable
     * to the billing service from a second, deliberate change — so a migration
     * half-finished by a timeout could not be safely resumed, which is exactly
     * when it must be.
     */
    public function testTheRetryKeyIsStableAcrossAttempts(): void
    {
        [$old, $new] = $this->twoTiers();
        $this->price($new, 'price_pro');
        $this->subscribe(1, $old, externalRef: 'sub_live_1');

        $this->portal->failPlanChangeWith = BillingPortalException::unreachable('timeout');
        $this->migration()->move($old, $new);

        $this->portal->failPlanChangeWith = null;
        $this->migration()->move($old, $new);

        self::assertCount(1, $this->portal->planChanges, 'Only the successful attempt is recorded.');
        $key = $this->portal->planChanges[0]['idempotency_key'];
        self::assertNotNull($key, 'A migration must carry a retry key.');
        self::assertStringContainsString((string) $old, (string) $key);
        self::assertStringContainsString((string) $new, (string) $key);
        self::assertStringContainsString('1', (string) $key, 'and identify the workspace');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function migration(): TierMigration
    {
        return new TierMigration(
            $this->pdo,
            $this->plans,
            $this->portal,
            new AuditLogger($this->pdo),
            new NullLogger(),
        );
    }

    /** @return array{int, int} */
    private function twoTiers(): array
    {
        return [
            $this->service->createPlan('starter', 'Starter'),
            $this->service->createPlan('pro', 'Pro'),
        ];
    }

    private function subscribe(int $tenantId, int $planId, ?string $externalRef): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO tenant_plan (tenant_id, plan_id, status, external_ref, assigned_at)
             VALUES (:t, :p, :status, :ref, CURRENT_TIMESTAMP)'
        );
        $statement->bindValue(':t', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':p', $planId, PDO::PARAM_INT);
        $statement->bindValue(':status', 'active');
        $statement->bindValue(':ref', $externalRef, $externalRef === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $statement->execute();
    }

    private function price(int $planId, string $externalRef): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_active, external_ref)
             VALUES (:plan, :cur, :amount, :period, :active, :ref)'
        );
        $statement->bindValue(':plan', $planId, PDO::PARAM_INT);
        $statement->bindValue(':cur', 'JOD');
        $statement->bindValue(':amount', 15000, PDO::PARAM_INT);
        $statement->bindValue(':period', 'month');
        $statement->bindValue(':active', true, PDO::PARAM_BOOL);
        $statement->bindValue(':ref', $externalRef);
        $statement->execute();
    }

    private function planIdOf(int $tenantId): ?int
    {
        $statement = $this->pdo->prepare('SELECT plan_id FROM tenant_plan WHERE tenant_id = :t');
        $statement->execute([':t' => $tenantId]);
        $id = $statement->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }
}
