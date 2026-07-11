<?php

declare(strict_types=1);

namespace Whity\Core\Plan;

use PDO;

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
     * Portable DB-boolean coercion (PG 't'/'f', SQLite 0/1, in-process bool).
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return !in_array(strtolower(trim((string) $value)), ['', '0', 'f', 'false', 'no'], true);
    }
}
