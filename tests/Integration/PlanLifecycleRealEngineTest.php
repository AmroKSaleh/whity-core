<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\Plan\PlanValidationException;

/**
 * Retiring a tier without losing what customers were on.
 *
 * ── The bug ────────────────────────────────────────────────────────────────
 *
 * `deletePlan()` was an unconditional `DELETE FROM plans`, and the schema made
 * that look safe. Both columns carrying commercial history are
 * `ON DELETE SET NULL`:
 *
 *     tenant_plan.plan_id -> NULL    live subscribers keep a subscription and
 *                                    forget which tier it is for
 *     invoices.plan_id    -> NULL    a PAID invoice forgets what it bought
 *
 * So tidying up an old tier detached its customers and blanked it out of paid
 * invoices, with no error and nothing in a log. The first test here fails
 * against that, which is the point of it.
 *
 * ── The rule under test ────────────────────────────────────────────────────
 *
 *   nothing ever used it          delete
 *   workspaces on it now          refuse — move them, or retire it
 *   invoices raised against it    refuse forever — retire only
 *
 * Prices, limits and promotion links do NOT block: they are the tier's own
 * configuration, not a record of what a customer did, and they cascade.
 */
final class PlanLifecycleRealEngineTest extends TestCase
{
    private PDO $pdo;
    private PlanService $service;
    private PlanRepository $plans;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");

        $this->plans = new PlanRepository($this->pdo);
        $this->service = new PlanService(
            $this->plans,
            new EntitlementService(new TenantEntitlementRepository($this->pdo)),
            $this->pdo,
            new AuditLogger($this->pdo),
        );
    }

    // ── Deleting what is genuinely unused ───────────────────────────────────

    /** A tier nobody ever bought goes, taking its own settings with it. */
    public function testATierNothingEverUsedIsDeleted(): void
    {
        $id = $this->service->createPlan('enterprise', 'Enterprise');

        self::assertTrue($this->service->deletePlan($id));
        self::assertNull($this->plans->findById($id));
    }

    /** Its own price rows and limits do not protect it — they cascade. */
    public function testItsOwnConfigurationDoesNotBlockTheDelete(): void
    {
        $id = $this->service->createPlan('enterprise', 'Enterprise');
        $this->service->setPlanEntitlement($id, 'members.max', '5');
        $this->price($id);

        $usage = $this->service->usageFor($id);
        self::assertSame(1, $usage->prices);
        self::assertSame(1, $usage->limits);
        self::assertTrue($usage->isDeletable(), 'A tier nobody bought may take its own settings with it.');

        self::assertTrue($this->service->deletePlan($id));
    }

    public function testDeletingAnUnknownTierReportsNotFound(): void
    {
        self::assertFalse($this->service->deletePlan(99999));
    }

    // ── Refusing to destroy history ─────────────────────────────────────────

    /**
     * THE TEST THIS FILE EXISTS FOR. A tier with live subscribers is refused,
     * and — the part the old code got wrong — those subscribers still have it
     * afterwards.
     */
    public function testATierWithSubscribersIsRefusedAndTheyKeepIt(): void
    {
        $id = $this->service->createPlan('starter', 'Starter');
        $this->service->applyToTenant($id, 1);
        $this->service->applyToTenant($id, 2);

        try {
            $this->service->deletePlan($id);
            self::fail('Deleting a tier with subscribers must be refused.');
        } catch (PlanValidationException $e) {
            self::assertStringContainsString('2 workspace', $e->reason());
        }

        self::assertNotNull($this->plans->findById($id));
        self::assertSame($id, $this->planIdOf(1), 'The subscriber must still be on the tier.');
        self::assertSame($id, $this->planIdOf(2));
    }

    /**
     * AND AN INVOICE IS FOREVER. Money changed hands against this tier; an
     * invoice naming nothing is not a record, so no amount of tidying makes it
     * deletable.
     */
    public function testATierWithInvoicesIsRefusedEvenWithNoSubscribers(): void
    {
        $id = $this->service->createPlan('legacy', 'Legacy');
        $this->invoice($id, tenantId: 1);

        self::assertSame(0, $this->service->usageFor($id)->subscribers);
        self::assertTrue($this->service->usageFor($id)->isPermanentlyUndeletable());

        try {
            $this->service->deletePlan($id);
            self::fail('A tier with invoices must never be deleted.');
        } catch (PlanValidationException $e) {
            self::assertStringContainsString('invoice', $e->reason());
        }

        self::assertNotNull($this->plans->findById($id));
        self::assertSame($id, $this->invoicePlanIdOf(1), 'The invoice must still name its tier.');
    }

    /** The refusal names both problems when a tier has both. */
    public function testTheRefusalNamesEveryReason(): void
    {
        $id = $this->service->createPlan('legacy', 'Legacy');
        $this->service->applyToTenant($id, 1);
        $this->invoice($id, tenantId: 1);

        $reason = (string) $this->service->usageFor($id)->refusalReason();

        self::assertStringContainsString('1 workspace', $reason);
        self::assertStringContainsString('1 invoice', $reason);
    }

    // ── The remedies ────────────────────────────────────────────────────────

    /** Moving everybody off is what makes retiring a tier honest. */
    public function testMovingSubscribersEmptiesTheTier(): void
    {
        $old = $this->service->createPlan('starter', 'Starter');
        $new = $this->service->createPlan('plus', 'Plus');
        $this->service->applyToTenant($old, 1);
        $this->service->applyToTenant($old, 2);

        $moved = $this->service->moveSubscribers($old, $new);

        self::assertSame(2, $moved);
        self::assertSame(0, $this->service->usageFor($old)->subscribers);
        self::assertSame($new, $this->planIdOf(1));
        self::assertSame($new, $this->planIdOf(2));
    }

    /**
     * A MOVE CHANGES WHAT THEY GET, immediately — which is the whole reason the
     * tier bundle is read live. A workspace moved to a smaller tier is on the
     * smaller tier's limits from that moment.
     */
    public function testAMovedWorkspaceResolvesToItsNewTiersLimits(): void
    {
        $old = $this->service->createPlan('starter', 'Starter');
        $new = $this->service->createPlan('plus', 'Plus');
        $this->service->setPlanEntitlement($old, 'members.max', '50');
        $this->service->setPlanEntitlement($new, 'members.max', '3');
        $this->service->applyToTenant($old, 1);

        $entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        self::assertSame(50, $entitlements->limit(1, 'members.max'));

        $this->service->moveSubscribers($old, $new);

        self::assertSame(3, $entitlements->limit(1, 'members.max'));
    }

    /** An emptied tier with no invoices becomes deletable. */
    public function testAnEmptiedTierWithNoHistoryCanThenBeDeleted(): void
    {
        $old = $this->service->createPlan('starter', 'Starter');
        $new = $this->service->createPlan('plus', 'Plus');
        $this->service->applyToTenant($old, 1);

        $this->service->moveSubscribers($old, $new);

        self::assertTrue($this->service->deletePlan($old));
    }

    /** But an emptied tier WITH invoices still cannot be — history outlives the move. */
    public function testAnEmptiedTierWithInvoicesStillCannotBeDeleted(): void
    {
        $old = $this->service->createPlan('legacy', 'Legacy');
        $new = $this->service->createPlan('plus', 'Plus');
        $this->service->applyToTenant($old, 1);
        $this->invoice($old, tenantId: 1);

        $this->service->moveSubscribers($old, $new);

        $this->expectException(PlanValidationException::class);
        $this->service->deletePlan($old);
    }

    /**
     * THE MOVE IS RECORDED, PER WORKSPACE.
     *
     * `tenant_plan` holds current state only: a move overwrites which tier a
     * workspace was on and stamps `assigned_at` over the top, so without this
     * entry the database can no longer say they were ever on the old tier —
     * destroying the exact history that moving them (rather than deleting the
     * tier) exists to protect.
     *
     * Found the hard way: the first version shipped without it, and three real
     * workspaces were moved before anybody checked whether the move had been
     * written down. It had not.
     */
    public function testEachMovedWorkspaceIsRecordedWithBothTiers(): void
    {
        $old = $this->service->createPlan('starter', 'Starter');
        $new = $this->service->createPlan('pro', 'Pro');
        $this->service->applyToTenant($old, 1);
        $this->service->applyToTenant($old, 2);

        $this->service->moveSubscribers($old, $new);

        $rows = $this->auditRows('plan.subscriber.moved');
        self::assertCount(2, $rows, 'One entry per workspace, not one for the batch.');

        $tenants = array_map(static fn (array $r): int => (int) $r['tenant_id'], $rows);
        sort($tenants);
        self::assertSame([1, 2], $tenants);

        // The entry has to name the tier they CAME FROM — that is the fact the
        // move destroys, and the only reason to record anything.
        $metadata = (string) $rows[0]['metadata'];
        self::assertStringContainsString('starter', $metadata);
        self::assertStringContainsString('pro', $metadata);
    }

    /**
     * WITH NOWHERE TO RECORD IT, THE MOVE IS REFUSED rather than performed
     * unrecorded. A silent move is worse than no move: the tier still has its
     * subscribers and can be tried again, whereas an unrecorded one has already
     * thrown the history away.
     */
    public function testAMoveWithNoAuditLogIsRefusedAndChangesNothing(): void
    {
        $unaudited = new PlanService(
            $this->plans,
            new EntitlementService(new TenantEntitlementRepository($this->pdo)),
            $this->pdo,
        );
        $old = $this->service->createPlan('starter', 'Starter');
        $new = $this->service->createPlan('pro', 'Pro');
        $this->service->applyToTenant($old, 1);

        try {
            $unaudited->moveSubscribers($old, $new);
            self::fail('A move with nowhere to record it must be refused.');
        } catch (PlanValidationException $e) {
            self::assertStringContainsString('audit', $e->reason());
        }

        self::assertSame($old, $this->planIdOf(1), 'The workspace must not have moved.');
    }

    public function testMovingOntoTheSameTierIsRefused(): void
    {
        $id = $this->service->createPlan('starter', 'Starter');

        $this->expectException(PlanValidationException::class);
        $this->service->moveSubscribers($id, $id);
    }

    public function testMovingToAnUnknownTierIsRefused(): void
    {
        $id = $this->service->createPlan('starter', 'Starter');
        $this->service->applyToTenant($id, 1);

        try {
            $this->service->moveSubscribers($id, 99999);
            self::fail('An unknown destination must be refused.');
        } catch (PlanValidationException) {
            // The subscriber must not have been stranded by the attempt.
            self::assertSame($id, $this->planIdOf(1));
        }
    }

    /**
     * CONSOLIDATING TWO RETIRED TIERS IS ALLOWED. Refusing an inactive
     * destination would force an operator to put a tier back ON SALE as a step
     * in cleaning up, which is the opposite of what they are doing.
     */
    public function testMovingOntoARetiredTierIsAllowed(): void
    {
        $old = $this->service->createPlan('starter', 'Starter');
        $new = $this->service->createPlan('legacy', 'Legacy', null, false);
        $this->service->applyToTenant($old, 1);

        self::assertSame(1, $this->service->moveSubscribers($old, $new));
    }

    /** Retiring takes a tier off sale and leaves every reference intact. */
    public function testRetiringKeepsTheTierAndItsSubscribers(): void
    {
        $id = $this->service->createPlan('starter', 'Starter');
        $this->service->applyToTenant($id, 1);

        self::assertTrue($this->service->retirePlan($id));

        $plan = $this->plans->findById($id);
        self::assertNotNull($plan);
        self::assertFalse((bool) $plan['is_active']);
        self::assertSame($id, $this->planIdOf(1), 'Retiring must not move anybody.');
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * @return list<array<string, mixed>>
     */
    private function auditRows(string $action): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM audit_log WHERE action = :a ORDER BY id ASC');
        $statement->execute([':a' => $action]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    private function planIdOf(int $tenantId): ?int
    {
        $statement = $this->pdo->prepare('SELECT plan_id FROM tenant_plan WHERE tenant_id = :t');
        $statement->execute([':t' => $tenantId]);
        $id = $statement->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }

    private function invoicePlanIdOf(int $tenantId): ?int
    {
        $statement = $this->pdo->prepare('SELECT plan_id FROM invoices WHERE tenant_id = :t ORDER BY id DESC LIMIT 1');
        $statement->execute([':t' => $tenantId]);
        $id = $statement->fetchColumn();

        return $id === false || $id === null ? null : (int) $id;
    }

    private function price(int $planId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO plan_prices (plan_id, currency, unit_amount, billing_period, is_active)
             VALUES (:plan, :cur, :amount, :period, :active)'
        );
        $statement->bindValue(':plan', $planId, PDO::PARAM_INT);
        $statement->bindValue(':cur', 'JOD');
        $statement->bindValue(':amount', 5000, PDO::PARAM_INT);
        $statement->bindValue(':period', 'month');
        $statement->bindValue(':active', true, PDO::PARAM_BOOL);
        $statement->execute();
    }

    /**
     * An invoice naming this tier — the durable record a delete would blank.
     *
     * CARRIES A NUMBER, because the schema insists: a paired CHECK says a draft
     * has none and anything past draft has one. A numberless "paid" invoice is
     * refused, and rightly — it is how a sequence acquires holes.
     */
    private function invoice(int $planId, int $tenantId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO invoices (tenant_id, plan_id, status, number, currency, subtotal_minor, total_minor)
             VALUES (:t, :plan, :status, :number, :cur, :sub, :total)'
        );
        $statement->bindValue(':t', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':plan', $planId, PDO::PARAM_INT);
        $statement->bindValue(':status', 'paid');
        $statement->bindValue(':number', 'INV-2026-' . str_pad((string) $planId, 5, '0', STR_PAD_LEFT));
        $statement->bindValue(':cur', 'JOD');
        $statement->bindValue(':sub', 5000, PDO::PARAM_INT);
        $statement->bindValue(':total', 5000, PDO::PARAM_INT);
        $statement->execute();
    }
}
