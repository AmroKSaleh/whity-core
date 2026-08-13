<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

use PDO;

/**
 * Remembers the state a record held before it was trashed, so a restore can
 * return it there instead of to a fixed one.
 *
 * The bug this exists to close
 * ----------------------------
 * `DataTypeLifecycleService::restore()` used to write the type's declared
 * `default_state` — a CONSTANT — over whatever the record actually was. Two
 * records both `approved`, both trashed, both restored, came back `draft`. On a
 * type with an approval gate that silently returns unreviewed-looking records to
 * circulation, and the 200 announcing it is byte-for-byte a successful undo.
 *
 * The prior state was never unknown. `trash()` already read it, to publish as
 * the transition hook's `from`. It was read, announced, and dropped.
 *
 * Why the memory lives in a CORE table, not on the record
 * ------------------------------------------------------
 * The natural place is a column beside the plugin's own `status`. Core does not
 * own plugin tables, and making every adopter of every trashable type ship a
 * migration to fix a core defect turns one core deploy into N plugin deploys —
 * with the bug surviving in every plugin that skips it.
 *
 * So the fact lives in `data_type_restore_states`, keyed
 * `(tenant_id, data_type, record_id)`: the same shape this platform already
 * uses for polymorphic per-record facts in `resource_role_assignments` and
 * `entity_tags`. No plugin migration exists to be forgotten.
 *
 * DERIVED, never authoritative
 * ----------------------------
 * Nothing but a restore reads this table, and a restore that finds nothing
 * falls back to the declared default — which is exactly the pre-fix behaviour,
 * and therefore exactly what every record already sitting in an adopter's trash
 * when this shipped will get. That fallback is the documented migration path,
 * not an accident of a missing row.
 *
 * Because it is derived, this class NEVER decides policy: it stores and returns
 * a string. Whether a recalled state is still legal to write is the lifecycle's
 * question and is answered in {@see DataTypeLifecycleService::restoreTarget()}.
 *
 * Cleanup is this class's job, because no foreign key can do it
 * ------------------------------------------------------------
 * `record_id` points into a table that varies by `data_type`, so it carries no
 * FK and no cascade will ever fire for it — the same limitation
 * `resource_role_assignments.resource_id` carries. Two calls therefore matter as
 * much as the write: {@see self::forget()} on a hard delete, so a later record
 * landing on a reused primary key cannot inherit a dead record's state, and
 * again on a spent restore, so a consumed memory is not left behind as stale
 * data. `tenant_id` does have a cascade, so dropping a tenant takes its
 * memories with it.
 *
 * Every statement binds `tenant_id`, and the id is stored and compared in its
 * published string form so the key written by a trash is the key read by the
 * restore.
 */
final class LifecycleStateMemory
{
    /**
     * The core-owned side table. Interpolated into no query — it is a literal in
     * every statement below, which is what lets the tenant-predicate scanner
     * read them.
     */
    public const TABLE = 'data_type_restore_states';

    private PDO $pdo;

    /**
     * @param PDO $pdo Live database connection, shared with the lifecycle service.
     */
    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * Record the state a record is leaving as it enters the trash.
     *
     * Replaces any earlier memory for the same key rather than failing on it: a
     * key can legitimately be re-used by a later record (nothing here has a
     * foreign key to cascade), and the newest trash is always the truthful one.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     * @param string     $state    The state being left behind.
     */
    public function remember(string $dataType, int $tenantId, int|string $id, string $state): void
    {
        // DELETE + INSERT rather than an upsert: `ON CONFLICT … DO UPDATE`
        // names its conflict target in dialect-specific ways, and this pair is
        // exactly as atomic in the transaction its caller opens while staying
        // literal enough for the tenant-predicate scanner to verify.
        $this->forget($dataType, $tenantId, $id);

        $statement = $this->pdo->prepare(
            'INSERT INTO data_type_restore_states (tenant_id, data_type, record_id, state)
             VALUES (:tenant, :type, :id, :state)'
        );
        $statement->execute([
            ':tenant' => $tenantId,
            ':type' => $dataType,
            ':id' => (string) $id,
            ':state' => $state,
        ]);
    }

    /**
     * The state remembered for a record, or null when nothing is remembered.
     *
     * Null is an ordinary answer, not an error: it is what every record trashed
     * before this table existed reports, and what a record whose memory was
     * spent by an earlier restore reports.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     */
    public function recall(string $dataType, int $tenantId, int|string $id): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT state FROM data_type_restore_states
             WHERE tenant_id = :tenant AND data_type = :type AND record_id = :id'
        );
        $statement->execute([
            ':tenant' => $tenantId,
            ':type' => $dataType,
            ':id' => (string) $id,
        ]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Drop the memory for a record.
     *
     * Called when a memory is SPENT (a restore consumed it) and when the record
     * is GONE (a hard delete). The second is the load-bearing one: with no
     * foreign key to cascade, a row left behind here would hand its state to
     * whatever record next occupies that primary key.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     */
    public function forget(string $dataType, int $tenantId, int|string $id): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM data_type_restore_states
             WHERE tenant_id = :tenant AND data_type = :type AND record_id = :id'
        );
        $statement->execute([
            ':tenant' => $tenantId,
            ':type' => $dataType,
            ':id' => (string) $id,
        ]);
    }
}
