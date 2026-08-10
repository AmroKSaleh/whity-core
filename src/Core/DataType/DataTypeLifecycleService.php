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

        if (!$this->exists($definition, $tenantId, $id)) {
            return LifecycleResult::notFound();
        }

        $state = $this->readState($definition, $tenantId, $id);
        $evaluation = self::statePolicy($definition, LifecycleAction::TRASH, $state);
        if (!$evaluation->isOk()) {
            return $evaluation;
        }

        $lifecycle = $definition->lifecycle();
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

        if (!$this->exists($definition, $tenantId, $id)) {
            return LifecycleResult::notFound();
        }

        $state = $this->readState($definition, $tenantId, $id);
        $evaluation = self::statePolicy($definition, LifecycleAction::RESTORE, $state);
        if (!$evaluation->isOk()) {
            return $evaluation;
        }

        $lifecycle = $definition->lifecycle();
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

        if (!$this->exists($definition, $tenantId, $id)) {
            return LifecycleResult::notFound();
        }

        $state = $this->readState($definition, $tenantId, $id);
        $evaluation = self::statePolicy($definition, LifecycleAction::RETIRE, $state);
        if (!$evaluation->isOk()) {
            return $evaluation;
        }

        $lifecycle = $definition->lifecycle();
        if ($lifecycle->isRetired($state)) {
            return LifecycleResult::ok($state);
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
     * Read a record's lifecycle state, the guards currently blocking its
     * deletion, and WHY each unavailable action is unavailable — the payload a
     * generated screen renders before offering anything destructive.
     *
     * Refusals are reported separately from blockers, and both are reported
     * ------------------------------------------------------------------------
     * `blockers` answers one question and only one: how many rows point at this
     * record. `refusals` answers a different one: which actions are unavailable
     * on this record right now, and under what stable reason key. Folding a
     * refusal into `blockers` as a synthetic entry would make the row-count
     * question unanswerable, so the two stay apart.
     *
     * The invariant worth relying on, stated precisely
     * -----------------------------------------------
     * It covers the ACTION-shaped booleans, and each of them is EXACTLY
     * `!isset($refusals[$action])` — one expression, one meaning, no field
     * answering a subtly different question than its neighbour:
     *
     *   - `restorable` === `!isset($refusals['restore'])`
     *   - `deletable`  === `!isset($refusals['delete'])`
     *
     * So a `false` on an action is never unexplained, whatever the cause: a
     * reference (`still_referenced`, with `blockers` populated), the record's
     * state (`trash_before_deleting`, with `blockers` empty), or the type not
     * offering the action at all (`delete_not_offered` — the same key its 405
     * carries). A renderer can always say why a control is disabled instead of
     * showing a dead button.
     *
     * `referenceable` and `pending_removal` are NOT actions and carry no
     * refusal. They are properties read straight off the state — there is no
     * control to disable and nothing to refuse, and `state` (which the caller
     * already has, beside them) is the whole explanation. Inventing a refusal
     * for them would mean inventing an action that does not exist.
     *
     * Nothing new is exposed: every reason is a pure function of `state` and the
     * type's declared lifecycle and permissions, all of which the caller can
     * already read.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     * @return array{state: ?string, referenceable: bool, pending_removal: bool, restorable: bool, deletable: bool, blockers: list<array{table: string, label: string, count: int}>, refusals: array<string, array{reason: string, message: string}>}|null
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
        $blockers = $this->blockingReferences($dataType, $tenantId, $id);

        $refusals = [];
        foreach (LifecycleAction::mutating() as $action) {
            $result = self::availability($definition, $action, $state, $blockers);
            if (!$result->isOk()) {
                $refusals[$action] = [
                    'reason' => (string) $result->reason(),
                    'message' => $result->message(),
                ];
            }
        }

        return [
            'state' => $state,
            'referenceable' => $lifecycle->acceptsNewReferences($state),
            'pending_removal' => $lifecycle->isPendingRemoval($state),
            'restorable' => !isset($refusals[LifecycleAction::RESTORE]),
            'deletable' => !isset($refusals[LifecycleAction::DELETE]),
            'blockers' => $blockers,
            'refusals' => $refusals,
        ];
    }

    /**
     * Whether one action is available on THIS record right now — and if not,
     * which single cause to report.
     *
     * This is the preview's evaluator, and the only place the three causes of
     * unavailability are ordered. They are checked in the order the ENDPOINT
     * would hit them, so the reason a screen shows is the reason a click would
     * get:
     *
     *  1. the type does not offer the action — the router refuses it with 405
     *     before any record is read, so this outranks anything about the record;
     *  2. the record's state forbids it — {@see self::statePolicy()}, the same
     *     pure evaluator the mutators consult;
     *  3. for a delete, rows still reference it.
     *
     * Why the `offers()` check is HERE and not in {@see self::statePolicy()}
     * ---------------------------------------------------------------------
     * `statePolicy()` is shared with the mutators, and a refusal there is a 409.
     * Teaching it about `offers()` would silently turn every "this type has no
     * such action" 405 into a 409 — a change to the mutation surface that no
     * caller asked for and that anything branching on status would break on.
     * The preview needs the union of all three causes; the mutators need only
     * the state rule. So the state rule stays pure and the preview layers the
     * other two on top.
     *
     * Restore is the one action where "already there" IS unavailability
     * ----------------------------------------------------------------
     * An idempotent no-op is normally not a refusal: retiring an already-retired
     * record succeeds and gets no entry. But a record that is not in the trash
     * has nothing to restore, and `restorable` has always reported `false` for
     * it — silently. That silence is the unexplained `false` this fixes; the
     * verdict is unchanged, it now says `nothing_to_restore`. The mutator is
     * untouched and still answers such a call with an idempotent success.
     *
     * @param string                                                $action   A {@see LifecycleAction} constant.
     * @param string|null                                           $state    The record's current state.
     * @param list<array{table: string, label: string, count: int}> $blockers Rows already counted by the caller.
     */
    private static function availability(
        DataTypeDefinition $definition,
        string $action,
        ?string $state,
        array $blockers
    ): LifecycleResult {
        if (!$definition->offers($action)) {
            // The SAME key the 405 body carries, deliberately: the preview
            // predicts what the endpoint would say, down to the reason.
            return LifecycleResult::unsupported($action . '_not_offered');
        }

        $policy = self::statePolicy($definition, $action, $state);
        if (!$policy->isOk()) {
            return $policy;
        }

        if ($action === LifecycleAction::RESTORE && !$definition->lifecycle()->isTrashed($state)) {
            return LifecycleResult::refused('nothing_to_restore', $state);
        }

        if ($action === LifecycleAction::DELETE && $blockers !== []) {
            return LifecycleResult::blocked($blockers, $state);
        }

        return LifecycleResult::ok($state);
    }

    /**
     * Decide whether a delete may proceed, without performing it.
     *
     * Shared by {@see self::delete()} and {@see self::canDelete()} so the
     * pre-flight answer and the enforcement can never disagree about what is
     * permitted. {@see self::describe()} reaches the same verdict through
     * {@see self::availability()} from a state and a blocker count it has
     * already read, rather than reading them a second time.
     *
     * @param int|string $id The record's key.
     */
    private function evaluateDelete(DataTypeDefinition $definition, int $tenantId, int|string $id): LifecycleResult
    {
        if (!$this->exists($definition, $tenantId, $id)) {
            return LifecycleResult::notFound();
        }

        $state = $this->readState($definition, $tenantId, $id);

        $policy = self::statePolicy($definition, LifecycleAction::DELETE, $state);
        if (!$policy->isOk()) {
            return $policy;
        }

        $blockers = $this->blockingReferences($definition->key(), $tenantId, $id);
        if ($blockers !== []) {
            return LifecycleResult::blocked($blockers, $state);
        }

        return LifecycleResult::ok($state);
    }

    /**
     * What the record's CURRENT STATE permits, before any reference is counted.
     *
     * The five state rules of the lifecycle live here and nowhere else. Every
     * mutator consults it before writing, and {@see self::describe()} consults
     * it to explain a disabled control — which is the point: a refusal a screen
     * predicts and a refusal an endpoint delivers are the same function, so they
     * cannot drift into disagreeing.
     *
     * Pure, and deliberately unaware of {@see DataTypeDefinition::offers()}: an
     * action the type never offered is not "refused by this record's state", it
     * was never on the table, and the mutators reject that case earlier as
     * UNSUPPORTED. Keep it that way — a refusal returned from here is a 409, so
     * teaching this function about `offers()` would turn every 405 the mutation
     * surface answers today into a 409 and break anything branching on status.
     * The preview needs the wider question; it asks it in
     * {@see self::availability()}, which layers `offers()` and the reference
     * count on top of this without changing what any mutator sees.
     *
     * @param string      $action A {@see LifecycleAction} constant.
     * @param string|null $state  The record's current state.
     */
    private static function statePolicy(
        DataTypeDefinition $definition,
        string $action,
        ?string $state
    ): LifecycleResult {
        $lifecycle = $definition->lifecycle();

        return match ($action) {
            // "This served its purpose" does not decay into "this should not exist".
            LifecycleAction::TRASH => $lifecycle->isRetired($state)
                ? LifecycleResult::refused('retired_records_cannot_be_trashed', $state)
                : LifecycleResult::ok($state),

            // Retirement is not a mistake, so there is no way back from it.
            LifecycleAction::RESTORE => $lifecycle->isRetired($state)
                ? LifecycleResult::refused('retirement_is_permanent', $state)
                : LifecycleResult::ok($state),

            // A mistake is not an achievement — restore before retiring.
            LifecycleAction::RETIRE => $lifecycle->isTrashed($state)
                ? LifecycleResult::refused('restore_before_retiring', $state)
                : LifecycleResult::ok($state),

            LifecycleAction::DELETE => match (true) {
                // Retirement is permanent. Not "permanent until nothing
                // references it" — permanent. The record is part of what happened.
                $lifecycle->isRetired($state)
                    => LifecycleResult::refused('retired_records_are_permanent', $state),
                // When a type is trashable there is no live → gone path. This is
                // what stops a delete route (or an empty-trash sweep) from
                // skipping the reversible step the lifecycle promises.
                $lifecycle->isTrashable() && !$lifecycle->isTrashed($state)
                    => LifecycleResult::refused('trash_before_deleting', $state),
                default => LifecycleResult::ok($state),
            },

            default => LifecycleResult::ok($state),
        };
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
