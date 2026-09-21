<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Data-access layer for the subscription-plan catalog (WC-plans, ADR 0010).
 *
 * `plans` and `plan_entitlements` are GLOBAL platform catalogs (no tenant_id,
 * like `permissions`) — their queries carry no tenant predicate. `tenant_plan`
 * is TENANT-OWNED: every statement against it binds an explicit `tenant_id`
 * predicate, so a tenant's plan can never be read or mutated under another.
 *
 * All SQL lives here so services/handlers issue none directly.
 */
final class PlanRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ── plans (global catalog) ──────────────────────────────────────────────

    public function createPlan(string $key, string $name, ?string $description, bool $isActive, int $sortOrder): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO plans (plan_key, name, description, is_active, sort_order, created_at, updated_at)
             VALUES (:key, :name, :description, :is_active, :sort_order, NOW(), NOW())'
        );
        $stmt->execute([
            ':key'         => $key,
            ':name'        => $name,
            ':description' => $description,
            ':is_active'   => $isActive ? 1 : 0,
            ':sort_order'  => $sortOrder,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update mutable plan fields. Only the supplied keys are changed.
     *
     * @param array{name?: string, description?: ?string, is_active?: bool, sort_order?: int} $fields
     * @return int Rows affected.
     */
    public function updatePlan(int $id, array $fields): int
    {
        $set = [];
        $params = [':id' => $id];
        if (array_key_exists('name', $fields)) {
            $set[] = 'name = :name';
            $params[':name'] = $fields['name'];
        }
        if (array_key_exists('description', $fields)) {
            $set[] = 'description = :description';
            $params[':description'] = $fields['description'];
        }
        if (array_key_exists('is_active', $fields)) {
            $set[] = 'is_active = :is_active';
            $params[':is_active'] = $fields['is_active'] ? 1 : 0;
        }
        if (array_key_exists('sort_order', $fields)) {
            $set[] = 'sort_order = :sort_order';
            $params[':sort_order'] = $fields['sort_order'];
        }
        if ($set === []) {
            return 0;
        }
        $set[] = 'updated_at = NOW()';

        $stmt = $this->db->prepare('UPDATE plans SET ' . implode(', ', $set) . ' WHERE id = :id');
        $stmt->execute($params);

        return $stmt->rowCount();
    }

    /**
     * What still points at this tier.
     *
     * ONE QUERY, not five round trips: the caller asks this before every delete
     * and before rendering every row of the tier screen, and five scalar
     * subqueries against small indexed tables is one plan either way.
     *
     * @tenant-guard-ignore: the plan catalogue is operator-owned and global by
     * design, and this counts ACROSS tenants on purpose — "how many workspaces
     * are on this tier" is the question being asked. It returns counts and reads
     * no tenant data.
     */
    public function usageFor(int $planId): PlanUsage
    {
        $stmt = $this->db->prepare('
            SELECT
                (SELECT COUNT(*) FROM tenant_plan       WHERE plan_id = :p1) AS subscribers,
                (SELECT COUNT(*) FROM invoices          WHERE plan_id = :p2) AS invoices,
                (SELECT COUNT(*) FROM plan_prices       WHERE plan_id = :p3) AS prices,
                (SELECT COUNT(*) FROM plan_entitlements WHERE plan_id = :p4) AS limits,
                (SELECT COUNT(*) FROM promotion_plans   WHERE plan_id = :p5) AS promotions
        ');
        foreach (['p1', 'p2', 'p3', 'p4', 'p5'] as $name) {
            $stmt->bindValue(':' . $name, $planId, PDO::PARAM_INT);
        }
        $stmt->execute();

        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return new PlanUsage(0, 0, 0, 0, 0);
        }

        return new PlanUsage(
            (int) $row['subscribers'],
            (int) $row['invoices'],
            (int) $row['prices'],
            (int) $row['limits'],
            (int) $row['promotions'],
        );
    }

    /**
     * Which workspaces on a tier are billed by an external service.
     *
     * Those cannot be moved by changing our own row: the billing service is
     * authoritative for what they are paying for, and the reconciliation sweep
     * will put them back.
     *
     * @return list<int>
     */
    public function externallyBilledSubscribers(int $planId): array
    {
        // @tenant-guard-ignore: the same cross-tenant question as usageFor() — who is on this tier — asked by an operator screen; it returns ids and reads no tenant data.
        $stmt = $this->db->prepare(
            'SELECT tenant_id FROM tenant_plan
              WHERE plan_id = :plan_id AND external_ref IS NOT NULL AND external_ref <> :empty'
        );
        $stmt->bindValue(':plan_id', $planId, PDO::PARAM_INT);
        $stmt->bindValue(':empty', '');
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /**
     * Move every workspace on one tier to another.
     *
     * A PLAIN UPDATE, not a loop of applyToTenant(). The bundle is read live
     * now, so moving a workspace between tiers IS this single write — there is
     * no per-tenant state to re-materialise, and doing it row by row would only
     * add a way for the operation to half-finish.
     *
     * `assigned_at` is refreshed because the assignment genuinely changed; an
     * untouched timestamp would report that these workspaces had been on the new
     * tier since whenever they joined the old one.
     *
     * @return int How many workspaces moved.
     *
     * @tenant-guard-ignore: moving every workspace off a retiring tier is an
     * operator action across tenants by definition — the tier is the subject,
     * not any one tenant. The predicate that scopes it is `plan_id`.
     */
    public function moveSubscribers(int $fromPlanId, int $toPlanId, ?int $movedBy = null): int
    {
        $stmt = $this->db->prepare('
            UPDATE tenant_plan
               SET plan_id = :to_plan, assigned_by = :moved_by, assigned_at = CURRENT_TIMESTAMP
             WHERE plan_id = :from_plan
        ');
        $stmt->bindValue(':to_plan', $toPlanId, PDO::PARAM_INT);
        $stmt->bindValue(':from_plan', $fromPlanId, PDO::PARAM_INT);
        $stmt->bindValue(':moved_by', $movedBy, $movedBy === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Which workspaces are on a tier, so a move can be recorded per tenant.
     *
     * @return list<int>
     *
     * @tenant-guard-ignore: the same cross-tenant question as usageFor() — who
     * is on this tier — asked by an operator screen so the move can be audited
     * tenant by tenant.
     */
    public function subscriberTenantIds(int $planId): array
    {
        $stmt = $this->db->prepare('SELECT tenant_id FROM tenant_plan WHERE plan_id = :plan_id');
        $stmt->bindValue(':plan_id', $planId, PDO::PARAM_INT);
        $stmt->execute();

        $ids = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    public function deletePlan(int $id): int
    {
        $stmt = $this->db->prepare('DELETE FROM plans WHERE id = :id');
        $stmt->execute([':id' => $id]);

        return $stmt->rowCount();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plans WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizePlan($row) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM plans WHERE plan_key = :key');
        $stmt->execute([':key' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $this->normalizePlan($row) : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPlans(bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM plans';
        if ($activeOnly) {
            $sql .= ' WHERE is_active = true';
        }
        $sql .= ' ORDER BY sort_order ASC, plan_key ASC';

        $stmt = $this->db->query($sql);
        if ($stmt === false) {
            return [];
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->normalizePlan(...), $rows);
    }

    // ── plan_entitlements (global) ──────────────────────────────────────────

    /**
     * A plan's entitlement bundle as a key => stored-value map.
     *
     * @return array<string, string>
     */
    public function getEntitlements(int $planId): array
    {
        $stmt = $this->db->prepare(
            'SELECT entitlement_key, value FROM plan_entitlements WHERE plan_id = :plan_id'
        );
        $stmt->execute([':plan_id' => $planId]);
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

    public function setEntitlement(int $planId, string $key, string $value): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO plan_entitlements (plan_id, entitlement_key, value)
             VALUES (:plan_id, :key, :value)
             ON CONFLICT (plan_id, entitlement_key) DO UPDATE SET value = EXCLUDED.value'
        );
        $stmt->execute([':plan_id' => $planId, ':key' => $key, ':value' => $value]);
    }

    public function deleteEntitlement(int $planId, string $key): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM plan_entitlements WHERE plan_id = :plan_id AND entitlement_key = :key'
        );
        $stmt->execute([':plan_id' => $planId, ':key' => $key]);

        return $stmt->rowCount();
    }

    // ── tenant_plan (tenant-owned) ──────────────────────────────────────────

    /**
     * How many tenants are currently on a plan.
     *
     * Exists for the downgrade guard: when somebody reduces what a tier grants,
     * the refusal names how many workspaces it would restrict. A number in that
     * sentence is the difference between a confirmation somebody reads and one
     * they click through.
     *
     * @tenant-guard-ignore: "how many tenants does this pricing change affect"
     * is inherently cross-tenant and is asked by an operator screen. It returns
     * a count and reads no tenant data.
     */
    public function countTenantsOnPlan(int $planId): int
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) FROM tenant_plan WHERE plan_id = :plan_id');
        $stmt->execute([':plan_id' => $planId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getTenantPlan(int $tenantId): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM tenant_plan WHERE tenant_id = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return [
            'tenant_id'   => (int) $row['tenant_id'],
            'plan_id'     => $row['plan_id'] !== null ? (int) $row['plan_id'] : null,
            'assigned_by' => $row['assigned_by'] !== null ? (int) $row['assigned_by'] : null,
            'assigned_at' => (string) $row['assigned_at'],
        ];
    }

    public function setTenantPlan(int $tenantId, int $planId, ?int $assignedBy): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tenant_plan (tenant_id, plan_id, assigned_by, assigned_at)
             VALUES (:tenant_id, :plan_id, :assigned_by, NOW())
             ON CONFLICT (tenant_id) DO UPDATE SET
                 plan_id = EXCLUDED.plan_id, assigned_by = EXCLUDED.assigned_by, assigned_at = NOW()'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':plan_id' => $planId, ':assigned_by' => $assignedBy]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizePlan(array $row): array
    {
        return [
            'id'          => (int) $row['id'],
            'plan_key'    => (string) $row['plan_key'],
            'name'        => (string) $row['name'],
            'description' => $row['description'] !== null ? (string) $row['description'] : null,
            'is_active'   => self::toBool($row['is_active']),
            'sort_order'  => (int) $row['sort_order'],
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }

        /**
     * Coerce a DB boolean column to a real bool.
     *
     * Delegates to the canonical coercion (#891). {@see DbBool} records which
     * spellings each driver actually returns — measured on the PHP this
     * platform ships, not assumed — and why a bare `(bool)` cast is not an
     * equivalent substitute for it.
     */
    private static function toBool(mixed $value): bool
    {
        return DbBool::of($value);
    }
}
