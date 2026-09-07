<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use PDO;

/**
 * The single access path for `document_route_step_effects` (migration 139).
 *
 * Reads are ordered by `position`, which is the whole reason the declarations
 * are a table rather than a JSONB column: "notify the registry, then notify the
 * archive" is a different instruction from its reverse, and an ORDER BY the
 * database can satisfy beats an array index nothing checks.
 *
 * Every statement carries a literal `tenant_id` predicate, because
 * `ci-tenant-predicate-guard.php` proves isolation by reading this file rather
 * than by watching it run.
 */
final class RouteStepEffectRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Declare an effect on a step.
     *
     * @param array<string, mixed> $config
     */
    public function create(int $tenantId, int $stepId, int $position, string $effectKind, array $config = []): int
    {
        $statement = $this->db->prepare(
            'INSERT INTO document_route_step_effects
                (tenant_id, step_id, position, effect_kind, effect_config, created_at)
             VALUES (:tenant_id, :step_id, :position, :effect_kind, :effect_config, NOW())'
        );
        $statement->execute([
            'tenant_id' => $tenantId,
            'step_id' => $stepId,
            'position' => $position,
            'effect_kind' => $effectKind,
            'effect_config' => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * A step's effects, in the order they were declared to run.
     *
     * @return list<array<string, mixed>>
     */
    public function listForStep(int $stepId, int $tenantId): array
    {
        $statement = $this->db->prepare(
            'SELECT id, tenant_id, step_id, position, effect_kind, effect_config, created_at
               FROM document_route_step_effects
              WHERE tenant_id = :tenant_id AND step_id = :step_id
              ORDER BY position ASC, id ASC'
        );
        $statement->execute(['tenant_id' => $tenantId, 'step_id' => $stepId]);

        return array_map(
            static fn (array $row): array => self::normalize($row),
            $statement->fetchAll(PDO::FETCH_ASSOC)
        );
    }

    /**
     * Decode `effect_config` once, here.
     *
     * PostgreSQL hands back JSONB as a string and SQLite hands back whatever was
     * written; every caller wants an array, and a caller that decoded it itself
     * would be the second place that knows this column is JSON.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $config = $row['effect_config'] ?? null;
        if (is_string($config)) {
            $decoded = json_decode($config, true);
            $config = is_array($decoded) ? $decoded : [];
        }

        $row['id'] = (int) $row['id'];
        $row['tenant_id'] = (int) $row['tenant_id'];
        $row['step_id'] = (int) $row['step_id'];
        $row['position'] = (int) $row['position'];
        $row['effect_config'] = is_array($config) ? $config : [];

        return $row;
    }
}
