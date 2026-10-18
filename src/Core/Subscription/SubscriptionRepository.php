<?php

declare(strict_types=1);

namespace Whity\Core\Subscription;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Data-access for the billing lifecycle columns of `tenant_plan` (WC-billing).
 *
 * `tenant_plan` is TENANT-OWNED: every statement binds an explicit `tenant_id`
 * predicate. This repo owns the subscription columns (status / period / grace /
 * enforcement_mode / external_ref); {@see \Whity\Core\Plan\PlanRepository} owns
 * the plan-assignment columns. Both bind tenant_id, so a tenant's subscription
 * can never be read or written under another.
 */
final class SubscriptionRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The tenant's subscription state (billing columns + plan_id), or null when
     * the tenant has no `tenant_plan` row at all.
     *
     * @return array<string, mixed>|null
     */
    public function findForTenant(int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT tenant_id, plan_id, status, current_period_end, grace_until, enforcement_mode, external_ref, billed_externally
             FROM tenant_plan WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'tenant_id'          => (int) $row['tenant_id'],
            'plan_id'            => $row['plan_id'] !== null ? (int) $row['plan_id'] : null,
            'status'             => $row['status'] !== null ? (string) $row['status'] : null,
            'current_period_end' => $row['current_period_end'] !== null ? (string) $row['current_period_end'] : null,
            'grace_until'        => $row['grace_until'] !== null ? (string) $row['grace_until'] : null,
            'enforcement_mode'   => $row['enforcement_mode'] !== null ? (string) $row['enforcement_mode'] : null,
            'external_ref'       => $row['external_ref'] !== null ? (string) $row['external_ref'] : null,
            // DbBool, not a bare cast: the same logical value arrives as a native bool,
            // '1'/'0' or 't'/'f' depending on the driver, and a bare (bool) reads 'f' as true.
            'billed_externally'  => DbBool::of($row['billed_externally'] ?? false),
        ];
    }

    /**
     * Upsert the full billing state for a tenant. Creates the `tenant_plan` row if
     * absent (plan_id left null; a plan is assigned separately via PlanService).
     *
     * @param array{status: ?string, current_period_end: ?string, grace_until: ?string,
     *              enforcement_mode: ?string, external_ref: ?string,
     *              billed_externally: bool} $state
     */
    public function upsert(int $tenantId, array $state): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tenant_plan
                 (tenant_id, status, current_period_end, grace_until, enforcement_mode, external_ref,
                  billed_externally, assigned_at)
             VALUES
                 (:tenant_id, :status, :period_end, :grace_until, :enforcement_mode, :external_ref,
                  :billed_externally, NOW())
             ON CONFLICT (tenant_id) DO UPDATE SET
                 status             = EXCLUDED.status,
                 current_period_end = EXCLUDED.current_period_end,
                 grace_until        = EXCLUDED.grace_until,
                 enforcement_mode   = EXCLUDED.enforcement_mode,
                 external_ref       = EXCLUDED.external_ref,
                 billed_externally  = EXCLUDED.billed_externally'
        );
        $stmt->execute([
            ':tenant_id'        => $tenantId,
            ':status'           => $state['status'],
            ':period_end'       => $state['current_period_end'],
            ':grace_until'      => $state['grace_until'],
            ':enforcement_mode' => $state['enforcement_mode'],
            ':external_ref'     => $state['external_ref'],
            ':billed_externally' => $state['billed_externally'] ? 1 : 0,
        ]);
    }
}
