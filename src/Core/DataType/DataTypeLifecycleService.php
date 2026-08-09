<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

use PDO;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Hooks\HookManager;
use Whity\Sdk\DataType\DataTypeGuard;

/**
 * Evaluates declared referential guards and performs declared lifecycle
 * transitions (WC-723, Door 2).
 *
 * This is the ONE enforcement point. The generated endpoints call it, and a
 * plugin keeping its own delete route calls the same object through the SDK's
 * {@see DataTypeGuard} contract — because two enforcement paths that can
 * disagree are worse than one, and "the guard was bypassed through the
 * empty-trash endpoint" is the exact failure mode declared guards exist to
 * eliminate.
 *
 * The state machine, and why it is shaped this way
 * ------------------------------------------------
 *   live ──trash──▶ trashed ──restore──▶ live
 *                      │
 *                      └──delete──▶ gone   (only when no guard blocks)
 *
 *   live ──retire──▶ retired ──▶ (nothing)
 *
 * Four refusals carry the whole distinction between the two end states:
 *
 *  - a RETIRED record cannot be restored — retirement is not a mistake;
 *  - a RETIRED record cannot be deleted, ever, guards or no guards — rows that
 *    already reference it still need it to resolve;
 *  - a RETIRED record cannot be trashed — "this served its purpose" does not
 *    decay into "this should not exist";
 *  - a TRASHED record cannot be retired — restore it first; a mistake is not an
 *    achievement.
 *
 * And one refusal closes the bypass: when a type is trashable, a delete is only
 * legal from the trashed state, so there is no path from live to gone that skips
 * the reversible step.
 *
 * Tenant isolation
 * ----------------
 * Every statement binds the tenant column. A record in another tenant is
 * reported as absent — never as a different tenant's row, and never as a
 * distinguishable "exists but forbidden". Table and column names come only from
 * declarations already validated as strict identifiers by
 * {@see DataTypeRegistry}, and are re-checked here before interpolation.
 */
class DataTypeLifecycleService implements DataTypeGuard
{
    private PDO $pdo;

    private DataTypeRegistry $registry;

    private ?HookManager $hookManager;

    private ?AuditLoggerInterface $auditLogger;

    /**
     * @param PDO                       $pdo         Live database connection.
     * @param DataTypeRegistry          $registry    The catalogue of declared types.
     * @param HookManager|null          $hookManager Announces transitions on the durable spine.
     * @param AuditLoggerInterface|null $auditLogger Records destructive transitions.
     */
    public function __construct(
        PDO $pdo,
        DataTypeRegistry $registry,
        ?HookManager $hookManager = null,
        ?AuditLoggerInterface $auditLogger = null
    ) {
        $this->pdo = $pdo;
        $this->registry = $registry;
        $this->hookManager = $hookManager;
        $this->auditLogger = $auditLogger;
    }

    /**
     * @inheritDoc
     */
    public function stateOf(string $dataType, int $tenantId, int|string $id): ?string
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null) {
            return null;
        }

        return $this->readState($definition, $tenantId, $id);
    }

    /**
     * @inheritDoc
     */
    public function blockingReferences(string $dataType, int $tenantId, int|string $id): array
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null) {
            return [];
        }

        $blockers = [];
        foreach ($definition->guards() as $guard) {
            $count = $this->countReferences($guard, $tenantId, $id);
            if ($count > 0) {
                $blockers[] = [
                    'table' => $guard->table(),
                    'label' => $guard->label(),
                    'count' => $count,
                ];
            }
        }

        return $blockers;
    }

    /**
     * @inheritDoc
     */
    public function isReferenceable(string $dataType, int $tenantId, int|string $id): bool
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null) {
            return false;
        }
        if (!$this->exists($definition, $tenantId, $id)) {
            return false;
        }

        return $definition->lifecycle()->acceptsNewReferences(
            $this->readState($definition, $tenantId, $id)
        );
    }

    /**
     * @inheritDoc
     */
    public function canDelete(string $dataType, int $tenantId, int|string $id): bool
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null) {
            return false;
        }

        return $this->evaluateDelete($definition, $tenantId, $id)->isOk();
    }

    /**
     * Trash a record: reversible, closed to new references, pending removal.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     * @param int|null   $actorId  The acting profile, for audit.
     */
    public function trash(string $dataType, int $tenantId, int|string $id, ?int $actorId = null): LifecycleResult
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null || !$definition->offers(LifecycleAction::TRASH)) {
            return LifecycleResult::unsupported('trash_not_offered');
        }

        $state = $this->readState($definition, $tenantId, $id);
        if (!$this->exists($definition, $tenantId, $id)) {
            return LifecycleResult::notFound();
        }

        $lifecycle = $definition->lifecycle();
        if ($lifecycle->isRetired($state)) {
            return LifecycleResult::refused('retired_records_cannot_be_trashed', $state);
        }
        if ($lifecycle->isTrashed($state)) {
            return LifecycleResult::ok($state);
        }

        $target = (string) $lifecycle->trashedState();
        $this->writeState($definition, $tenantId, $id, $target);
        $this->announce($definition, LifecycleAction::TRASH, $id, $tenantId, $state, $target, $actorId);

        return LifecycleResult::ok($target);
    }

    /**
     * Restore a trashed record to the type's default state.
     *
     * Refused for a retired record: the point of retirement is that there is no
     * way back.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     * @param int|null   $actorId  The acting profile, for audit.
     */
    public function restore(string $dataType, int $tenantId, int|string $id, ?int $actorId = null): LifecycleResult
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null || !$definition->offers(LifecycleAction::RESTORE)) {
            return LifecycleResult::unsupported('restore_not_offered');
        }

        $state = $this->readState($definition, $tenantId, $id);
        if (!$this->exists($definition, $tenantId, $id)) {
            return LifecycleResult::notFound();
        }

        $lifecycle = $definition->lifecycle();
        if ($lifecycle->isRetired($state)) {
            return LifecycleResult::refused('retirement_is_permanent', $state);
        }
        if (!$lifecycle->isTrashed($state)) {
            return LifecycleResult::ok($state);
        }

        $target = $lifecycle->defaultState();
        $this->writeState($definition, $tenantId, $id, $target);
        $this->announce($definition, LifecycleAction::RESTORE, $id, $tenantId, $state, $target, $actorId);

        return LifecycleResult::ok($target);
    }

    /**
     * Retire a record: closed to new references, permanently readable, never
     * deletable, never restorable.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     * @param int|null   $actorId  The acting profile, for audit.
     */
    public function retire(string $dataType, int $tenantId, int|string $id, ?int $actorId = null): LifecycleResult
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null || !$definition->offers(LifecycleAction::RETIRE)) {
            return LifecycleResult::unsupported('retire_not_offered');
        }

        $state = $this->readState($definition, $tenantId, $id);
        if (!$this->exists($definition, $tenantId, $id)) {
            return LifecycleResult::notFound();
        }

        $lifecycle = $definition->lifecycle();
        if ($lifecycle->isRetired($state)) {
            return LifecycleResult::ok($state);
        }
        if ($lifecycle->isTrashed($state)) {
            return LifecycleResult::refused('restore_before_retiring', $state);
        }

        $target = (string) $lifecycle->retiredState();
        $this->writeState($definition, $tenantId, $id, $target);
        $this->announce($definition, LifecycleAction::RETIRE, $id, $tenantId, $state, $target, $actorId);

        return LifecycleResult::ok($target);
    }

    /**
     * Delete a record for real, if every declared guard permits it.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     * @param int|null   $actorId  The acting profile, for audit.
     */
    public function delete(string $dataType, int $tenantId, int|string $id, ?int $actorId = null): LifecycleResult
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null || !$definition->offers(LifecycleAction::DELETE)) {
            return LifecycleResult::unsupported('delete_not_offered');
        }

        $evaluation = $this->evaluateDelete($definition, $tenantId, $id);
        if (!$evaluation->isOk()) {
            return $evaluation;
        }

        $state = $this->readState($definition, $tenantId, $id);
        $table = self::identifier($definition->table());
        $keyColumn = self::identifier($definition->keyColumn());
        $tenantColumn = self::identifier($definition->tenantColumn());

        $sql = "DELETE FROM {$table} WHERE {$keyColumn} = :id AND {$tenantColumn} = :tenant";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':id' => $id, ':tenant' => $tenantId]);

        $this->announce($definition, LifecycleAction::DELETE, $id, $tenantId, $state, null, $actorId);

        return LifecycleResult::ok(null);
    }

    /**
     * Read a record's lifecycle state and the guards currently blocking its
     * deletion — the payload a generated screen renders before offering
     * anything destructive.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     * @return array{state: ?string, referenceable: bool, pending_removal: bool, restorable: bool, deletable: bool, blockers: list<array{table: string, label: string, count: int}>}|null
     *         Null when the record does not exist in this tenant.
     */
    public function describe(string $dataType, int $tenantId, int|string $id): ?array
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null || !$this->exists($definition, $tenantId, $id)) {
            return null;
        }

        $state = $this->readState($definition, $tenantId, $id);
        $lifecycle = $definition->lifecycle();

        return [
            'state' => $state,
            'referenceable' => $lifecycle->acceptsNewReferences($state),
            'pending_removal' => $lifecycle->isPendingRemoval($state),
            'restorable' => $definition->offers(LifecycleAction::RESTORE) && $lifecycle->isTrashed($state),
            'deletable' => $this->evaluateDelete($definition, $tenantId, $id)->isOk(),
            'blockers' => $this->blockingReferences($dataType, $tenantId, $id),
        ];
    }

    /**
     * Decide whether a delete may proceed, without performing it.
     *
     * Shared by {@see self::delete()}, {@see self::canDelete()} and
     * {@see self::describe()} so the button, the pre-flight answer and the
     * enforcement can never disagree about what is permitted.
     *
     * @param int|string $id The record's key.
     */
    private function evaluateDelete(DataTypeDefinition $definition, int $tenantId, int|string $id): LifecycleResult
    {
        if (!$this->exists($definition, $tenantId, $id)) {
            return LifecycleResult::notFound();
        }

        $state = $this->readState($definition, $tenantId, $id);
        $lifecycle = $definition->lifecycle();

        // Retirement is permanent. Not "permanent until nothing references it" —
        // permanent. The record is part of what happened.
        if ($lifecycle->isRetired($state)) {
            return LifecycleResult::refused('retired_records_are_permanent', $state);
        }

        // When a type is trashable there is no live → gone path. This is what
        // stops a delete route (or an empty-trash sweep) from skipping the
        // reversible step the lifecycle promises.
        if ($lifecycle->isTrashable() && !$lifecycle->isTrashed($state)) {
            return LifecycleResult::refused('trash_before_deleting', $state);
        }

        $blockers = $this->blockingReferences($definition->key(), $tenantId, $id);
        if ($blockers !== []) {
            return LifecycleResult::blocked($blockers, $state);
        }

        return LifecycleResult::ok($state);
    }

    /**
     * Whether the record exists in this tenant.
     *
     * @param int|string $id The record's key.
     */
    private function exists(DataTypeDefinition $definition, int $tenantId, int|string $id): bool
    {
        $table = self::identifier($definition->table());
        $keyColumn = self::identifier($definition->keyColumn());
        $tenantColumn = self::identifier($definition->tenantColumn());

        $sql = "SELECT 1 FROM {$table} WHERE {$keyColumn} = :id AND {$tenantColumn} = :tenant";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':id' => $id, ':tenant' => $tenantId]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * The record's lifecycle state, or null when the type declares no state
     * column or the record is absent from this tenant.
     *
     * @param int|string $id The record's key.
     */
    private function readState(DataTypeDefinition $definition, int $tenantId, int|string $id): ?string
    {
        $column = $definition->lifecycle()->column();
        if ($column === null) {
            return null;
        }

        $table = self::identifier($definition->table());
        $stateColumn = self::identifier($column);
        $keyColumn = self::identifier($definition->keyColumn());
        $tenantColumn = self::identifier($definition->tenantColumn());

        $sql = "SELECT {$stateColumn} FROM {$table} WHERE {$keyColumn} = :id AND {$tenantColumn} = :tenant";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':id' => $id, ':tenant' => $tenantId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Write a new lifecycle state, bound to the tenant.
     *
     * @param int|string $id    The record's key.
     * @param string     $state The state to write.
     */
    private function writeState(DataTypeDefinition $definition, int $tenantId, int|string $id, string $state): void
    {
        $table = self::identifier($definition->table());
        $stateColumn = self::identifier((string) $definition->lifecycle()->column());
        $keyColumn = self::identifier($definition->keyColumn());
        $tenantColumn = self::identifier($definition->tenantColumn());

        $sql = "UPDATE {$table} SET {$stateColumn} = :state "
            . "WHERE {$keyColumn} = :id AND {$tenantColumn} = :tenant";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':state' => $state, ':id' => $id, ':tenant' => $tenantId]);
    }

    /**
     * Count the rows one guard says still reference the record.
     *
     * @param int|string $id The referenced record's key.
     */
    private function countReferences(ReferenceGuard $guard, int $tenantId, int|string $id): int
    {
        $table = self::identifier($guard->table());
        $column = self::identifier($guard->column());
        $tenantColumn = self::identifier($guard->tenantColumn());

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = :id AND {$tenantColumn} = :tenant";
        $bindings = [':id' => $id, ':tenant' => $tenantId];

        // A referencing row that is itself trashed must not pin its parent
        // alive, or the guard becomes a leak rather than a protection. Which
        // column expresses that is the plugin's to declare; core only applies it.
        $ignoreIndex = 0;
        foreach ($guard->ignoreWhen() as $ignoreColumn => $values) {
            $name = self::identifier($ignoreColumn);
            $placeholders = [];
            foreach ($values as $value) {
                $placeholder = ':ig' . $ignoreIndex++;
                $placeholders[] = $placeholder;
                $bindings[$placeholder] = $value;
            }
            $sql .= " AND ({$name} IS NULL OR {$name} NOT IN (" . implode(', ', $placeholders) . '))';
        }

        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        return (int) $statement->fetchColumn();
    }

    /**
     * Announce a transition on the hook spine and record it in the audit log.
     *
     * @param int|string  $id      The record's key.
     * @param string|null $from    The state before.
     * @param string|null $to      The state after (null when the row is gone).
     * @param int|null    $actorId The acting profile.
     */
    private function announce(
        DataTypeDefinition $definition,
        string $action,
        int|string $id,
        int $tenantId,
        ?string $from,
        ?string $to,
        ?int $actorId
    ): void {
        $payload = [
            'data_type' => $definition->key(),
            'source' => $definition->source(),
            'table' => $definition->table(),
            'action' => $action,
            'record_id' => $id,
            'tenant_id' => $tenantId,
            'from' => $from,
            'to' => $to,
            'actor_profile_id' => $actorId,
        ];

        $this->hookManager?->dispatch('datatype.lifecycle.changed', $payload);

        $this->auditLogger?->record('data_type.' . $action, [
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorId,
            'target_type' => $definition->key(),
            'target_id' => is_numeric($id) ? (int) $id : null,
            'metadata' => ['from' => $from, 'to' => $to, 'record_id' => (string) $id],
        ]);
    }

    /**
     * Re-assert that an identifier is safe to interpolate.
     *
     * {@see DataTypeRegistry} already validated every name at registration; this
     * is the belt to that pair of braces. A name reaching here that does not
     * match is a bug in the registry, and the right response to that is to stop,
     * not to build the query anyway.
     *
     * @param string $identifier The table or column name.
     * @throws \LogicException When an unvalidated identifier reaches the query builder.
     */
    private static function identifier(string $identifier): string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,62}$/', $identifier) !== 1) {
            throw new \LogicException(
                "Refusing to build SQL with unvalidated identifier '{$identifier}'"
            );
        }

        return $identifier;
    }
}
