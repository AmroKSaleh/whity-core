<?php

declare(strict_types=1);

namespace Whity\Core\Billing\External;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plan\PlanRepository;

/**
 * Move every workspace off a retiring tier — including the ones somebody pays
 * a billing service for.
 *
 * ── Why a local move was not enough ────────────────────────────────────────
 *
 * {@see \Whity\Core\Plan\PlanService::moveSubscribers()} updates `tenant_plan`
 * and is correct for a workspace an operator assigned by hand. For a workspace
 * that PAYS, it is worse than useless.
 *
 * The billing service is authoritative for what somebody is buying:
 * {@see AccessRecorder} takes `plan` straight off the access snapshot and
 * writes it into `tenant_plan`, and {@see AccessReconciliationRun} re-asks every
 * few minutes. So a local tier change is reverted by the next sweep — proved on
 * staging, where three workspaces moved locally were back on the old tier
 * fifteen minutes later, and the sweep was RIGHT to put them back. We had
 * changed our record of what they were paying for without changing what they
 * were paying for.
 *
 * So the move happens THERE first and here second. If the billing service
 * refuses, the local row is left alone: a tenant whose subscription still says
 * `starter` must keep reading as `starter` here, or the two disagree until the
 * next sweep silently resolves it against us.
 *
 * ── It is not a purchase ───────────────────────────────────────────────────
 *
 * Retiring a tier is an OPERATOR's decision. Nobody asked for it, so nobody is
 * charged and no invoice is raised — {@see BillingPortal::PRORATION_NONE} with
 * `invoice: false`. Not even a zero-amount invoice, which still reads to a
 * customer like they bought something.
 *
 * ── Partial failure is expected, not exceptional ───────────────────────────
 *
 * This is N calls to a remote service. One will time out eventually. Each
 * workspace is moved independently and a failure is counted rather than thrown,
 * because the alternative — abandoning the run — leaves a tier half-migrated
 * with no record of which half. Every call carries an idempotency key derived
 * from the move itself, so re-running finishes the job instead of charging
 * anybody twice.
 */
final class TierMigration
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly PlanRepository $plans,
        private readonly BillingPortal $portal,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @return array{moved: int, local: int, failed: int, skipped: int, reasons: list<string>}
     */
    public function move(int $fromPlanId, int $toPlanId, ?int $movedBy = null): array
    {
        $result = ['moved' => 0, 'local' => 0, 'failed' => 0, 'skipped' => 0, 'reasons' => []];

        $from = $this->plans->findById($fromPlanId);
        $to = $this->plans->findById($toPlanId);
        if ($from === null || $to === null || $fromPlanId === $toPlanId) {
            $result['reasons'][] = 'Both tiers must exist and differ.';

            return $result;
        }

        // The destination's handle on the billing side. Needed only for
        // externally-billed workspaces — a deployment that bills nobody moves
        // everyone locally and never looks at this.
        $toPriceRef = $this->externalPriceRefFor($toPlanId);

        foreach ($this->plans->subscriberTenantIds($fromPlanId) as $tenantId) {
            $subscriptionRef = $this->subscriptionRefFor($tenantId);

            if ($subscriptionRef === null) {
                // Nobody is billing this workspace externally; our record IS the
                // record, and a local move is the whole job.
                $this->moveLocally($tenantId, $fromPlanId, $toPlanId, $movedBy, $from, $to);
                $result['local']++;
                continue;
            }

            if ($toPriceRef === null) {
                // The destination is not sold through the billing service, so
                // there is nothing to move this subscription ONTO. Moving it
                // locally would be undone by the next sweep, so it is skipped
                // loudly instead.
                $result['skipped']++;
                $result['reasons'][] = sprintf(
                    'Workspace %d is billed externally but "%s" has no price on the billing service.',
                    $tenantId,
                    (string) $to['plan_key']
                );
                continue;
            }

            try {
                $this->portal->changePlan(
                    $subscriptionRef,
                    $toPriceRef,
                    BillingPortal::PRORATION_NONE,
                    false,
                    // DERIVED, NOT RANDOM. A retry of the same migration must
                    // present the same key or the safety is theoretical: a
                    // random key on every attempt is indistinguishable from a
                    // second, deliberate change.
                    sprintf('tier-move:%d:%d:%d', $tenantId, $fromPlanId, $toPlanId)
                );
            } catch (BillingPortalException $e) {
                // NOT MOVED LOCALLY. Their subscription still says the old tier,
                // so ours must too — otherwise the two disagree until a sweep
                // resolves it against us, and the customer sees the wrong
                // features in between.
                $result['failed']++;
                $result['reasons'][] = sprintf(
                    'Workspace %d could not be moved on the billing service (%s).',
                    $tenantId,
                    $e->reason
                );
                $this->logger->error('A tier migration failed at the billing service', [
                    'tenant_id' => $tenantId,
                    'reason' => $e->reason,
                ]);
                continue;
            }

            $this->moveLocally($tenantId, $fromPlanId, $toPlanId, $movedBy, $from, $to);
            $result['moved']++;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $from
     * @param array<string, mixed> $to
     */
    private function moveLocally(
        int $tenantId,
        int $fromPlanId,
        int $toPlanId,
        ?int $movedBy,
        array $from,
        array $to,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE tenant_plan
                SET plan_id = :to_plan, assigned_by = :moved_by, assigned_at = CURRENT_TIMESTAMP
              WHERE tenant_id = :tenant_id'
        );
        $statement->bindValue(':to_plan', $toPlanId, PDO::PARAM_INT);
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(':moved_by', $movedBy, $movedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->execute();

        // `tenant_plan` holds current state only, so this entry is the only
        // record that the workspace was ever on the old tier.
        $this->audit->record('plan.subscriber.moved', [
            'tenant_id' => $tenantId,
            'actor_user_id' => $movedBy,
            'target_type' => 'plan',
            'target_id' => $toPlanId,
            'metadata' => [
                'from_plan_id' => $fromPlanId,
                'from_plan_key' => $from['plan_key'] ?? null,
                'to_plan_id' => $toPlanId,
                'to_plan_key' => $to['plan_key'] ?? null,
            ],
        ]);
    }

    /**
     * The billing service's handle for this workspace's subscription, or null
     * when nobody is billing it externally.
     */
    private function subscriptionRefFor(int $tenantId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT external_ref FROM tenant_plan WHERE tenant_id = :tenant_id'
        );
        $statement->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
        $statement->execute();
        $ref = $statement->fetchColumn();

        return is_string($ref) && $ref !== '' ? $ref : null;
    }

    /**
     * The billing service's price for a tier, preferring one still on sale.
     *
     * A RETIRED PRICE IS STILL A VALID DESTINATION, which is why inactive ones
     * are not excluded outright — consolidating two retired tiers into one is a
     * legitimate tidy-up, and refusing it would force an operator to put a tier
     * back on sale as a step in cleaning up.
     *
     * @tenant-guard-ignore: the plan catalogue is operator-owned and global by
     * design — there is no tenant column to bind.
     */
    private function externalPriceRefFor(int $planId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT external_ref FROM plan_prices
              WHERE plan_id = :plan_id AND external_ref IS NOT NULL
              ORDER BY is_active DESC, id ASC
              LIMIT 1'
        );
        $statement->bindValue(':plan_id', $planId, PDO::PARAM_INT);
        $statement->execute();
        $ref = $statement->fetchColumn();

        return is_string($ref) && $ref !== '' ? $ref : null;
    }
}
