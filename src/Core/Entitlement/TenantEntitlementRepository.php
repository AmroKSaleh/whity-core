<?php

declare(strict_types=1);

namespace Whity\Core\Entitlement;

use PDO;

/**
 * Data-access layer for `tenant_entitlements` — the operator-granted per-tenant
 * capabilities/limits (WC-ent).
 *
 * `tenant_entitlements` is a TENANT-OWNED table (see
 * {@see \Whity\Core\Tenant\TenantOwnedTables}): every row belongs to exactly one
 * tenant and the platform's #1 isolation invariant requires every
 * SELECT/UPDATE/DELETE to bind an explicit, parameterised `tenant_id` predicate.
 * Every method here takes the target tenant id and binds it, so a row written
 * for one tenant can never be read or mutated under another.
 *
 * The one deliberately cross-tenant caller is the OPERATOR entitlements API,
 * which passes the target tenant id from the URL path (system-tenant gated) —
 * the same sanctioned pattern RegistrationsApiHandler uses to mutate another
 * tenant's memberships. That still flows a concrete `tenant_id` into every
 * statement here; the predicate is never dropped.
 *
 * All SQL touching the table lives here so handlers never issue raw queries
 * (project convention). Values persist as TEXT; {@see EntitlementRegistry} owns
 * the typed contract.
 */
final class TenantEntitlementRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * What this tenant's TIER grants, as a key => value map.
     *
     * THE LAYER THAT MAKES A PRICE LIST MEAN SOMETHING. Without it a tier is a
     * row with a name and an amount: every plan granted identical access,
     * because nothing ever read the bundle at the moment access was decided.
     *
     * READ LIVE, NOT COPIED. The bundle used to be materialised into
     * `tenant_entitlements` when a plan was applied, which made a tier's
     * definition a snapshot taken at subscribe time — so marketing could change
     * what "Pro" includes and every existing Pro customer would keep the old
     * deal until somebody re-applied their plan by hand. Reading it here means
     * an edit reaches the people it is for. The write side is where a REDUCTION
     * is stopped, not here (see PlanService::setEntitlement).
     *
     * Empty for a tenant with no plan, which is the ordinary state of a tenant
     * on a deployment that sells nothing — they fall through to the registry
     * defaults, exactly as before.
     *
     * @return array<string, string>
     */
    public function planBundleFor(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT pe.entitlement_key, pe.value
               FROM tenant_plan tp
               JOIN plan_entitlements pe ON pe.plan_id = tp.plan_id
              WHERE tp.tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['entitlement_key'] ?? '');
            if ($key !== '') {
                $out[$key] = (string) ($row['value'] ?? '');
            }
        }

        return $out;
    }

    /**
     * All stored entitlement overrides for one tenant as a key => value map.
     * Keys absent here fall back to the registry default at the service layer.
     *
     * @return array<string, string>
     */
    public function allForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT entitlement_key, value FROM tenant_entitlements WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['entitlement_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[$key] = (string) ($row['value'] ?? '');
        }

        return $out;
    }

    /**
     * Upsert a single entitlement override for one tenant.
     *
     * @param int      $tenantId  The target tenant.
     * @param string   $key       The entitlement key.
     * @param string   $value     The override value (TEXT).
     * @param int|null $updatedBy The operator profile id making the change (audit).
     */
    public function set(int $tenantId, string $key, string $value, ?int $updatedBy): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tenant_entitlements (tenant_id, entitlement_key, value, updated_by, updated_at)
             VALUES (:tenant_id, :key, :value, :updated_by, NOW())
             ON CONFLICT (tenant_id, entitlement_key)
             DO UPDATE SET value = EXCLUDED.value, updated_by = EXCLUDED.updated_by, updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id'  => $tenantId,
            ':key'        => $key,
            ':value'      => $value,
            ':updated_by' => $updatedBy,
        ]);
    }

    /**
     * Delete a single entitlement override for one tenant, so the registry
     * default (baseline grant) applies again. Returns rows removed (0 when absent).
     */
    public function delete(int $tenantId, string $key): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM tenant_entitlements WHERE tenant_id = :tenant_id AND entitlement_key = :key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':key' => $key]);

        return $stmt->rowCount();
    }
}
