<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

use PDO;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\Hooks\HookManager;
use Whity\Sdk\DataType\DataTypeGuard;
use Whity\Sdk\Hooks\HookVetoException;

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
 * What a delete removes: the record, and the record's PARTS
 * ---------------------------------------------------------
 * A delete used to be one statement over one row. With no foreign keys between
 * plugin tables, that left every child row a record owned pointing at an id that
 * no longer resolves — invisibly, and reported as a 200. `blocks_delete` could
 * not catch it because it answers the opposite question: it names rows that must
 * OUTLIVE the record. So composition is declared too, as `cascade_delete`, and
 * {@see self::delete()} removes it — children first, parent second, one
 * transaction, tenant-bound at every statement.
 *
 * Core refuses rather than cascading in three cases, all evaluated before any
 * write and all predicted by {@see self::describe()}: an owned table that owns
 * rows of its OWN (nesting is not supported in this pass), an owned row that is
 * retired, and an owned row somebody's `blocks_delete` protects. Each of those
 * would otherwise be a way to defeat a guarantee core makes elsewhere by
 * approaching it from above. See {@see self::evaluateDelete()}.
 *
 * A restore is an UNDO, and an undo needs a memory
 * ------------------------------------------------
 * `restore` returns a record to the state it actually held, which means that
 * state has to survive the time it spends in the trash. It does, in
 * {@see LifecycleStateMemory} — a core-owned side table keyed
 * `(tenant_id, data_type, record_id)`, so no plugin has to add a column to get
 * a correct undo. `trash` writes the memory it was already reading (to publish
 * as the hook's `from`); `restore` spends it; `delete` forgets it, because
 * nothing else will.
 *
 * `defaultState()` is the FALLBACK, used when nothing is remembered or when what
 * was remembered is no longer a state the type declares. It is not "where a
 * restore goes".
 *
 * Two hooks, and why one of them can say no
 * -----------------------------------------
 * A transition announces itself twice, and the difference between the two is
 * the difference between being told and being asked:
 *
 *   `datatype.lifecycle.changing`  BEFORE the write — VETOABLE
 *   `datatype.lifecycle.changed`   AFTER  the write — observation only
 *
 * `changed` alone was not enough, and the gap was not cosmetic. Core's refusals
 * are the ones core can DERIVE: a declared `blocks_delete` guard counts rows,
 * and the state rules below know that retirement is permanent. Neither can know
 * a domain rule — that a record is depended on by something that would become
 * unusable without it, that a particular type must never be trashed once
 * retired by a rule the type does not declare, that a parent may not move while
 * its children are mid-flight. Those are not foreign keys, and `blocks_delete`
 * is about DELETE, not about trash. Without a veto point an adopter's only
 * option was a parallel route in front of core's — which means two lifecycle
 * memories for one record, and a restore that disagrees with core's about what
 * state the record returns to. That split brain is a correctness bug that
 * reports success, so the veto exists to make the parallel route unnecessary.
 *
 * A listener aborts by throwing {@see HookVetoException} — the one Throwable
 * the host's per-plugin error boundary re-throws instead of swallowing (SDK
 * 1.15). It arrives here, the write never happens, and the caller gets a 409
 * carrying the veto's `reason()`. Every OTHER Throwable stays isolated by that
 * boundary and the transition proceeds, exactly as before: a plugin that
 * crashes must not become a plugin that blocks.
 *
 * The hook fires for ALL FOUR mutating actions, not just delete. An adopter who
 * can refuse a delete but not a trash has not been given a veto, because a trash
 * is what their own route was refusing.
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
    /**
     * Dispatched BEFORE the write, for every mutating action. A listener
     * throwing {@see HookVetoException} aborts the transition.
     */
    public const HOOK_CHANGING = 'datatype.lifecycle.changing';

    /**
     * Dispatched AFTER a transition that actually happened. Observation only —
     * throwing here cannot undo anything, because there is nothing left to undo.
     */
    public const HOOK_CHANGED = 'datatype.lifecycle.changed';

    private PDO $pdo;

    private DataTypeRegistry $registry;

    private ?HookManager $hookManager;

    private ?AuditLoggerInterface $auditLogger;

    /**
     * Where a trashed record's prior state waits for its restore.
     *
     * Built from the same connection rather than injected: it is an
     * implementation detail of how this service keeps its promise that a
     * restore is an undo, not a collaborator a caller chooses. Injecting it
     * would put the same object in every wiring site (the HTTP entry point, the
     * CLI bootstrap, every test) with no decision to make there — and a caller
     * that passed a different one could only make the undo wrong.
     */
    private LifecycleStateMemory $memory;

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
        $this->memory = new LifecycleStateMemory($pdo);
    }

    /**
     * The restore-state memory this service keeps — the SAME object, not a
     * second one over the same connection.
     *
     * Exposed so the host entry points can register it in the container. A
     * plugin that hard-deletes a record OUTSIDE core (its own route, its own
     * SQL, a bulk purge) has to clear the record's memory row, and until this
     * was reachable it had exactly two options: import a core class the
     * container refused to build, or `DELETE` from a core-owned table by hand.
     * Both are worse than a service lookup, and the consequence of doing
     * neither is not cosmetic — `record_id` carries no foreign key, so a
     * client-supplied key that a later record re-uses inherits the dead
     * record's state and can be restored into a state it never held.
     *
     * A READ accessor, deliberately, not an injection point: {@see self::$memory}
     * explains why a caller must not be able to substitute a different memory,
     * and returning the one in use keeps that true while removing the "which
     * instance?" question entirely.
     */
    public function stateMemory(): LifecycleStateMemory
    {
        return $this->memory;
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
     * Reversible means the state being left has to be kept, so this is also
     * where it is remembered — the same value that has always been published as
     * the transition's `from`.
     *
     * The memory is written only on a REAL trash. Trashing an already-trashed
     * record returns early (below) and never reaches the write, which is what
     * stops a second click from remembering `trashed` as the state to restore
     * to and turning the undo into a no-op.
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
        $veto = $this->apply(
            $definition,
            LifecycleAction::TRASH,
            $tenantId,
            $id,
            $state,
            $target,
            $actorId,
            function () use ($definition, $tenantId, $id, $state, $target): void {
                // Remembered inside the same transaction as the state change, so
                // the pair is all-or-nothing: a trashed record with no memory would
                // silently restore to the default, and a memory for a record that
                // never moved would misdirect its next real restore.
                if ($state !== null) {
                    $this->memory->remember($definition->key(), $tenantId, $id, $state);
                }
                $this->writeState($definition, $tenantId, $id, $target);
            }
        );
        if ($veto !== null) {
            return $veto;
        }

        return LifecycleResult::ok($target);
    }

    /**
     * Restore a trashed record to THE STATE IT HELD before it was trashed.
     *
     * Not to the type's default state. That is what this used to do, and it was
     * a data-loss bug rather than a simplification: a record trashed while
     * `approved` came back `draft`, so anything with an approval gate quietly
     * re-entered circulation looking unreviewed — reported as a 200 that is
     * indistinguishable from a correct undo. The prior state was never unknown;
     * {@see self::trash()} read it to announce it and now also keeps it.
     *
     * The declared default is the FALLBACK, in exactly two situations, both
     * resolved by {@see self::restoreTarget()}:
     *
     *  - nothing is remembered — every record already in the trash when this
     *    shipped, which is the migration path and therefore documented
     *    behaviour, not an accident;
     *  - what was remembered is no longer a state the type declares, or the
     *    type has since repurposed it as its trashed or retired state.
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

        $target = self::restoreTarget($lifecycle, $this->memory->recall($definition->key(), $tenantId, $id));
        $veto = $this->apply(
            $definition,
            LifecycleAction::RESTORE,
            $tenantId,
            $id,
            $state,
            $target,
            $actorId,
            function () use ($definition, $tenantId, $id, $target): void {
                $this->writeState($definition, $tenantId, $id, $target);
                // The memory is SPENT. Left behind it is stale — the record is no
                // longer trashed — and the next real trash would overwrite it
                // anyway, so keeping it buys nothing and can only mislead.
                $this->memory->forget($definition->key(), $tenantId, $id);
            }
        );
        if ($veto !== null) {
            return $veto;
        }

        return LifecycleResult::ok($target);
    }

    /**
     * Where a restore should put the record: the remembered state when it is
     * still a legal destination, and the declared default otherwise.
     *
     * Validating the remembered state is not paperwork. A type's state
     * vocabulary is a DECLARATION, not a schema — it can change on any deploy,
     * between the trash and the restore — and three ways of writing it back
     * would each be worse than the bug this fixes:
     *
     *  - a state the type no longer declares puts the row outside its own
     *    vocabulary, where nothing matches on it and no screen can render it;
     *  - a state the type has since repurposed as its RETIRED one walks the
     *    record into the single state the lifecycle promises is unreachable by
     *    restore, through the restore endpoint;
     *  - a state now meaning TRASHED makes the restore a silent no-op that
     *    reports success.
     *
     * The default is guaranteed to be none of those: {@see DataTypeRegistry}
     * refuses a declaration whose `default_state` is the trashed or retired
     * state, for this exact reason.
     *
     * @param string|null $remembered The state recalled for this record, if any.
     */
    private static function restoreTarget(Lifecycle $lifecycle, ?string $remembered): string
    {
        if ($remembered === null || !in_array($remembered, $lifecycle->states(), true)) {
            return $lifecycle->defaultState();
        }

        if ($lifecycle->isTrashed($remembered) || $lifecycle->isRetired($remembered)) {
            return $lifecycle->defaultState();
        }

        return $remembered;
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
        $veto = $this->apply(
            $definition,
            LifecycleAction::RETIRE,
            $tenantId,
            $id,
            $state,
            $target,
            $actorId,
            function () use ($definition, $tenantId, $id, $target): void {
                $this->writeState($definition, $tenantId, $id, $target);
            }
        );
        if ($veto !== null) {
            return $veto;
        }

        return LifecycleResult::ok($target);
    }

    /**
     * Delete a record for real — and the rows it OWNS — if every declared guard
     * permits it.
     *
     * The composition is deleted here, not left to the database
     * ---------------------------------------------------------
     * This used to be one statement: `DELETE FROM <table> WHERE <key> AND
     * <tenant>`, exactly one row. With no foreign keys between plugin tables —
     * the established convention here, and the reason `blocks_delete` exists at
     * all — nothing else was ever going to remove a record's parts, so a record
     * with children left them behind: rows pointing at an id that no longer
     * resolves, in a state no screen lists and no guard protects. An adopter
     * proved it live. It reported 200.
     *
     * `blocks_delete` could not have caught it, because it answers the OPPOSITE
     * question. A guard names rows that must OUTLIVE the record; a composition
     * names rows that must DIE WITH it. Nothing about a table's shape says which
     * a plugin means, so the second half had to be declarable too — see
     * {@see CascadeEdge}.
     *
     * Order and atomicity
     * -------------------
     * Children first, then the parent, all inside the SAME unit of work as the
     * pre-transition hook (see {@see self::apply()} and
     * {@see self::transactionally()}) — so a veto, or a failure part-way through
     * the cascade, leaves the whole composition exactly where it was rather than
     * half-removed. When a caller already holds a transaction open, that one is
     * the atomicity; core never ends a unit of work it did not begin.
     *
     * The order is not arbitrary even though both statements are in one
     * transaction: it is the order that stays correct under a caller who does
     * NOT hold one open and whose connection dies mid-flight.
     *
     * What core refuses rather than cascades
     * -------------------------------------
     * Three conditions are checked BEFORE anything is written, in
     * {@see self::evaluateDelete()}, and each of them refuses instead of doing a
     * partial job — a cascade that silently defeats another guarantee would be
     * worse than the orphans it replaced. See there for the reasoning.
     *
     * Also forgets any state remembered for the record AND for every owned row
     * that is itself a declared type. That is not tidiness: the memory's
     * `record_id` points into a table that varies by data type, so it carries no
     * foreign key and no cascade will ever fire for it. A row left behind would
     * be inherited by whatever record next occupies that primary key — the same
     * id-reuse hazard the taxonomy delete guard had to answer.
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

        // Counted BEFORE the write, because afterwards there is nothing left to
        // count. The audit entry is the only durable record that a delete took
        // four other rows with it.
        $cascaded = $this->composition($definition, $tenantId, $id);

        $sql = "DELETE FROM {$table} WHERE {$keyColumn} = :id AND {$tenantColumn} = :tenant";
        $veto = $this->apply(
            $definition,
            LifecycleAction::DELETE,
            $tenantId,
            $id,
            $state,
            null,
            $actorId,
            function () use ($sql, $definition, $tenantId, $id): void {
                $this->cascade($definition, $tenantId, $id);

                $statement = $this->pdo->prepare($sql);
                $statement->execute([':id' => $id, ':tenant' => $tenantId]);
                $this->memory->forget($definition->key(), $tenantId, $id);
            },
            $cascaded === [] ? [] : ['cascaded' => $cascaded]
        );
        if ($veto !== null) {
            return $veto;
        }

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
     * WHAT THIS PREVIEW CANNOT PREDICT: a plugin veto
     * ----------------------------------------------
     * Read this before relying on "preview and enforcement agree", because that
     * guarantee is NARROWER than it was, and the narrowing is real rather than
     * theoretical.
     *
     * Everything above is derived from core's own rules — the type's
     * declaration, the record's state, the declared reference counts — so it
     * predicts core's refusals exactly, down to the reason key. It does NOT and
     * CANNOT predict a listener on {@see self::HOOK_CHANGING} refusing the
     * transition. An action reported here as available may still answer 409 with
     * `blocked_by_plugin` when it is attempted.
     *
     * That is a deliberate choice, not an oversight, and the alternative is
     * worse. Predicting a veto would mean DISPATCHING the hook from this method
     * — that is, running arbitrary plugin code inside a `GET`. A read that
     * executes plugin listeners is surprising (nothing about a preview suggests
     * it), potentially side-effecting (a listener may write, enqueue, or call
     * out; nothing in the hook contract forbids it, and `changing` listeners are
     * written expecting a mutation is underway), and would double every
     * listener's invocations for a screen that merely rendered. It would also be
     * a lie of a different kind: a veto is evaluated against the state at the
     * moment of the attempt, so a "no veto" prediction expires immediately.
     *
     * So the honest statement of the property, which a client should design to:
     *
     *   this preview predicts CORE's rules completely and exactly;
     *   a plugin veto is discoverable only by attempting the transition.
     *
     * A generated screen should therefore keep rendering from `refusals` — it is
     * still the complete account of what CORE will refuse — while remaining
     * able to surface a 409 that arrives from an action it had shown as
     * available. Silently swallowing that 409, or treating it as an unexpected
     * error, is the failure mode this note exists to prevent.
     *
     * A THIRD question, kept apart from the other two: `cascade`
     * ---------------------------------------------------------
     * `blockers` says what stops this delete. `refusals` says which actions are
     * unavailable and why. `cascade` says what ELSE this delete would remove —
     * the rows the record owns, with the plugin's own label and a count.
     *
     * It is published because a composition is invisible otherwise, and
     * invisible destruction is the worst kind. A record with four owned rows and
     * a record with none are identical in every other field of this payload, yet
     * one of them is about to take four rows with it. A generated screen can now
     * say "this will also delete 4 line items" before the user clicks, which is
     * the entire reason a confirmation dialog exists.
     *
     * It is NOT a refusal and NOT a blocker: a record with composition is still
     * deletable, and folding it into either would break the property #731/#732
     * pinned — that `blockers` answers a row count and every action-shaped false
     * carries a refusal. A composition entry answers neither question.
     *
     * Zero-count edges are omitted, exactly as `blockers` omits guards with
     * nothing behind them: `cascade: []` then means "nothing else goes", which is
     * what a renderer needs to decide whether to warn at all, rather than a list
     * of noughts it has to filter.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's key.
     * @return array{state: ?string, referenceable: bool, pending_removal: bool, restorable: bool, deletable: bool, blockers: list<array{table: string, label: string, count: int}>, cascade: list<array{table: string, label: string, count: int}>, refusals: array<string, array{reason: string, message: string}>}|null
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
        $nesting = $this->cascadeNesting($definition, $state);
        $owned = $nesting !== null ? null : $this->compositionRefusal($definition, $tenantId, $id, $state);

        $refusals = [];
        foreach (LifecycleAction::mutating() as $action) {
            $result = self::availability($definition, $action, $state, $blockers, $nesting, $owned);
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
            'cascade' => $this->composition($definition, $tenantId, $id),
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
     *  3. for a delete, the declared composition cannot be removed at all —
     *     `cascade_would_nest`, a fact about DECLARATIONS that no change to the
     *     data can clear. It is reported ahead of the row-level causes for
     *     exactly that reason: telling a user to detach three references first,
     *     when the delete would still be refused afterwards, is worse than
     *     saying nothing;
     *  4. for a delete, rows still reference the record itself;
     *  5. for a delete, rows still reference something the record OWNS.
     *
     * The last two are separate causes, not one with two spellings. "Detach what
     * points at this record" and "something points at one of its parts" send the
     * reader to different places, and a caller shown the first for the second
     * goes looking for references that do not exist.
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
     * @param string                                                $action    A {@see LifecycleAction} constant.
     * @param string|null                                           $state     The record's current state.
     * @param list<array{table: string, label: string, count: int}> $blockers  Rows already counted by the caller.
     * @param LifecycleResult|null                                  $nesting   The structural composition refusal, if any.
     * @param LifecycleResult|null                                  $owned     The row-level composition refusal, if any.
     */
    private static function availability(
        DataTypeDefinition $definition,
        string $action,
        ?string $state,
        array $blockers,
        ?LifecycleResult $nesting = null,
        ?LifecycleResult $owned = null
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

        if ($action === LifecycleAction::DELETE) {
            if ($nesting !== null) {
                return $nesting;
            }
            if ($blockers !== []) {
                return LifecycleResult::blocked($blockers, $state);
            }
            if ($owned !== null) {
                return $owned;
            }
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
     * The three composition checks live here, ahead of any write, so a cascade
     * that cannot be performed cleanly is REFUSED rather than performed
     * half-way. Each replaces a silent defeat of a guarantee core makes
     * elsewhere:
     *
     *  - {@see self::cascadeNesting()} — an owned table that owns rows of its
     *    own. Core deletes ONE level, so the level below would be orphaned:
     *    precisely the bug this whole mechanism exists to close, one step down;
     *  - a retired owned row. "A retired record is never deleted" is the
     *    strongest promise this lifecycle makes, and a cascade that quietly
     *    removed one would make it conditional on nobody having declared a
     *    composition over its table;
     *  - an owned row that somebody's `blocks_delete` protects. Deleting it
     *    through the parent would be a way to defeat a declared guard by
     *    approaching from above, which is exactly the "bypassed through a
     *    secondary path" failure declared guards exist to eliminate.
     *
     * The order matters and mirrors {@see self::availability()} exactly, so the
     * refusal a screen predicts is the refusal a click gets: state, then the
     * structural composition fault, then the record's own references, then its
     * composition's.
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

        $nesting = $this->cascadeNesting($definition, $state);
        if ($nesting !== null) {
            return $nesting;
        }

        $blockers = $this->blockingReferences($definition->key(), $tenantId, $id);
        if ($blockers !== []) {
            return LifecycleResult::blocked($blockers, $state);
        }

        $owned = $this->compositionRefusal($definition, $tenantId, $id, $state);
        if ($owned !== null) {
            return $owned;
        }

        return LifecycleResult::ok($state);
    }

    /**
     * Refuse a delete whose composition is itself composed of something.
     *
     * Nesting is NOT supported in this pass, and this is where that is enforced
     * rather than silently approximated. If an owned table is itself a declared
     * data type that declares its OWN `cascade_delete`, deleting the parent
     * would remove the children and leave the grandchildren pointing at ids that
     * no longer resolve — the identical orphaning this mechanism exists to
     * close, moved one level down and made harder to notice.
     *
     * Why this is refused HERE rather than at registration
     * ---------------------------------------------------
     * It is a fact about TWO declarations, and the second one may not exist yet
     * when the first is registered — types arrive from different plugins in load
     * order. Refusing at registration would therefore reject whichever
     * declaration happened to arrive second, so which plugin lost its entire
     * lifecycle surface would change with load order. {@see DataTypeRegistry}
     * deliberately avoids exactly that: ownership never depends on iteration
     * order.
     *
     * By delete time the catalogue is complete, so the answer is deterministic
     * for the install — and because it is derived purely from declarations, it
     * is a fact core owns and {@see self::describe()} predicts it exactly, like
     * every other core refusal.
     *
     * Nesting is a real requirement and this is not a claim that it is
     * unnecessary. It needs decisions this pass does not make — depth limits,
     * cycles, and whether a veto at one level aborts the levels above it — and
     * refusing loudly is the only honest interim answer.
     *
     * @param string|null $state The record's current state, carried into the refusal.
     * @return LifecycleResult|null Null when the composition is flat.
     */
    private function cascadeNesting(DataTypeDefinition $definition, ?string $state): ?LifecycleResult
    {
        foreach ($definition->cascades() as $edge) {
            foreach ($this->typesOver($edge->table()) as $owned) {
                if ($owned->cascades() !== []) {
                    return LifecycleResult::refused('cascade_would_nest', $state);
                }
            }
        }

        return null;
    }

    /**
     * Refuse a delete whose owned rows are not core's to remove.
     *
     * Only rows that are THEMSELVES a declared data type can produce a refusal
     * here, and that is not a gap: the two promises being protected — "a retired
     * record is never deleted" and "a declared guard refuses a delete" — are
     * both properties a table only has by being declared. An undeclared owned
     * table has neither, and core has nothing to weigh.
     *
     * Permanence is checked before references because it can never be cleared:
     * telling a caller to detach three rows when the delete would still be
     * refused afterwards is worse than telling them nothing.
     *
     * @param int|string  $id    The owning record's key.
     * @param string|null $state The record's current state, carried into the refusal.
     * @return LifecycleResult|null Null when the composition may be removed.
     */
    private function compositionRefusal(
        DataTypeDefinition $definition,
        int $tenantId,
        int|string $id,
        ?string $state
    ): ?LifecycleResult {
        foreach ($definition->cascades() as $edge) {
            foreach ($this->typesOver($edge->table()) as $owned) {
                $retired = $owned->lifecycle()->retiredState();
                $column = $owned->lifecycle()->column();
                if (
                    $retired !== null
                    && $column !== null
                    && $this->countOwnedRowsInState($edge, $column, $retired, $tenantId, $id) > 0
                ) {
                    return LifecycleResult::refused('composition_is_permanent', $state);
                }
            }
        }

        // Collected across every edge rather than returned on the first hit: a
        // refusal that names one of the three things in the way, and goes quiet
        // about the other two, produces a user who fixes one and clicks again.
        $blockers = [];
        foreach ($definition->cascades() as $edge) {
            foreach ($this->typesOver($edge->table()) as $owned) {
                foreach ($owned->guards() as $guard) {
                    $count = $this->countReferencesToOwnedRows($edge, $owned, $guard, $tenantId, $id);
                    if ($count > 0) {
                        $blockers[] = [
                            'table' => $guard->table(),
                            'label' => $guard->label(),
                            'count' => $count,
                        ];
                    }
                }
            }
        }

        return $blockers === [] ? null : LifecycleResult::compositionBlocked($blockers, $state);
    }

    /**
     * Every registered type stored in a given table.
     *
     * A list rather than one definition because nothing forbids two types over
     * one table (a plugin may declare narrower views of the same rows), and a
     * check that consulted only the first would enforce whichever declaration
     * happened to register earliest.
     *
     * @param string $table The table to look up.
     * @return list<DataTypeDefinition>
     */
    private function typesOver(string $table): array
    {
        $found = [];
        foreach ($this->registry->all() as $definition) {
            if ($definition->table() === $table) {
                $found[] = $definition;
            }
        }

        return $found;
    }

    /**
     * What this record OWNS right now: one entry per declared edge that
     * currently has rows, with the plugin's own label for them.
     *
     * Used twice, for the two moments it is answerable: by
     * {@see self::describe()} to warn before the click, and by
     * {@see self::delete()} to record in the audit log what the delete took with
     * it. After the write there is nothing left to count, which is exactly why
     * the audit entry has to carry it.
     *
     * @param int|string $id The owning record's key.
     * @return list<array{table: string, label: string, count: int}>
     */
    private function composition(DataTypeDefinition $definition, int $tenantId, int|string $id): array
    {
        $owned = [];
        foreach ($definition->cascades() as $edge) {
            $count = $this->countOwnedRows($edge, $tenantId, $id);
            if ($count > 0) {
                $owned[] = [
                    'table' => $edge->table(),
                    'label' => $edge->label(),
                    'count' => $count,
                ];
            }
        }

        return $owned;
    }

    /**
     * Delete the rows this record owns, one declared edge at a time.
     *
     * Called only from inside {@see self::delete()}'s write closure, so it runs
     * in the transition's unit of work: a failure here rolls the parent back
     * with it, and a veto raised before it means it never runs at all.
     *
     * Every statement binds the tenant column. A cascade is the most destructive
     * statement core generates, and an unscoped one would delete another
     * tenant's rows for a record it cannot even see.
     *
     * The memory rows go first, and only for owned tables that are themselves
     * declared types — those are the only rows that can have one. It is done
     * per row rather than by a join because `data_type_restore_states.record_id`
     * carries no foreign key and lives in a core table the owned table knows
     * nothing about; the alternative is leaving the same id-reuse hazard
     * {@see LifecycleStateMemory} exists to close, one level down.
     *
     * @param int|string $id The owning record's key.
     */
    private function cascade(DataTypeDefinition $definition, int $tenantId, int|string $id): void
    {
        foreach ($definition->cascades() as $edge) {
            foreach ($this->typesOver($edge->table()) as $owned) {
                foreach ($this->ownedIds($edge, $owned, $tenantId, $id) as $ownedId) {
                    $this->memory->forget($owned->key(), $tenantId, $ownedId);
                }
            }

            $table = self::identifier($edge->table());
            $column = self::identifier($edge->column());
            $tenantColumn = self::identifier($edge->tenantColumn());

            $sql = "DELETE FROM {$table} WHERE {$column} = :id AND {$tenantColumn} = :tenant";
            $statement = $this->pdo->prepare($sql);
            $statement->execute([':id' => $id, ':tenant' => $tenantId]);
        }
    }

    /**
     * How many rows one declared edge currently owns.
     *
     * @param int|string $id The owning record's key.
     */
    private function countOwnedRows(CascadeEdge $edge, int $tenantId, int|string $id): int
    {
        $table = self::identifier($edge->table());
        $column = self::identifier($edge->column());
        $tenantColumn = self::identifier($edge->tenantColumn());

        $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = :id AND {$tenantColumn} = :tenant";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':id' => $id, ':tenant' => $tenantId]);

        return (int) $statement->fetchColumn();
    }

    /**
     * How many of the owned rows sit in a given lifecycle state.
     *
     * @param string     $stateColumn The owned type's lifecycle column.
     * @param string     $state       The state being looked for.
     * @param int|string $id          The owning record's key.
     */
    private function countOwnedRowsInState(
        CascadeEdge $edge,
        string $stateColumn,
        string $state,
        int $tenantId,
        int|string $id
    ): int {
        $table = self::identifier($edge->table());
        $column = self::identifier($edge->column());
        $tenantColumn = self::identifier($edge->tenantColumn());
        $lifecycleColumn = self::identifier($stateColumn);

        $sql = "SELECT COUNT(*) FROM {$table} "
            . "WHERE {$column} = :id AND {$tenantColumn} = :tenant AND {$lifecycleColumn} = :state";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':id' => $id, ':tenant' => $tenantId, ':state' => $state]);

        return (int) $statement->fetchColumn();
    }

    /**
     * The keys of the rows one edge owns.
     *
     * @param DataTypeDefinition $owned The type stored in the owned table, whose
     *                                  key column names the rows.
     * @param int|string         $id    The owning record's key.
     * @return list<string>
     */
    private function ownedIds(CascadeEdge $edge, DataTypeDefinition $owned, int $tenantId, int|string $id): array
    {
        $table = self::identifier($edge->table());
        $column = self::identifier($edge->column());
        $tenantColumn = self::identifier($edge->tenantColumn());
        $keyColumn = self::identifier($owned->keyColumn());

        $sql = "SELECT {$keyColumn} FROM {$table} WHERE {$column} = :id AND {$tenantColumn} = :tenant";
        $statement = $this->pdo->prepare($sql);
        $statement->execute([':id' => $id, ':tenant' => $tenantId]);

        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $value) {
            if (is_scalar($value)) {
                $ids[] = (string) $value;
            }
        }

        return $ids;
    }

    /**
     * How many rows one guard of the OWNED type still points at rows this record
     * owns.
     *
     * One statement rather than a guard evaluation per owned row: the answer is
     * "does anything block any of them", and a per-row loop would turn a
     * pre-flight check into an N+1 that grows with the composition.
     *
     * Both halves bind their own tenant column — the outer guard table and the
     * inner owned table — under DISTINCT placeholder names. Reusing one name
     * twice is handled differently by PDO's named-parameter rewriting depending
     * on driver and emulation, and a cascade check that throws on PostgreSQL
     * while passing on SQLite would be worse than no check at all.
     *
     * @param DataTypeDefinition $owned The type stored in the owned table.
     * @param ReferenceGuard     $guard One of that type's declared guards.
     * @param int|string         $id    The owning record's key.
     */
    private function countReferencesToOwnedRows(
        CascadeEdge $edge,
        DataTypeDefinition $owned,
        ReferenceGuard $guard,
        int $tenantId,
        int|string $id
    ): int {
        $guardTable = self::identifier($guard->table());
        $guardColumn = self::identifier($guard->column());
        $guardTenant = self::identifier($guard->tenantColumn());

        $ownedTable = self::identifier($edge->table());
        $ownedColumn = self::identifier($edge->column());
        $ownedTenant = self::identifier($edge->tenantColumn());
        $ownedKey = self::identifier($owned->keyColumn());

        $sql = "SELECT COUNT(*) FROM {$guardTable} "
            . "WHERE {$guardTenant} = :gtenant AND {$guardColumn} IN ("
            . "SELECT {$ownedKey} FROM {$ownedTable} "
            . "WHERE {$ownedColumn} = :id AND {$ownedTenant} = :tenant)";
        $bindings = [':id' => $id, ':tenant' => $tenantId, ':gtenant' => $tenantId];

        // The same filter the guard applies on its own type: a referencing row
        // that is itself trashed does not pin its parent alive, and it must not
        // pin a grandparent alive either.
        $ignoreIndex = 0;
        foreach ($guard->ignoreWhen() as $ignoreColumn => $values) {
            $name = self::identifier($ignoreColumn);
            $placeholders = [];
            foreach ($values as $value) {
                $placeholder = ':cig' . $ignoreIndex++;
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
     * Run a transition's statements as one unit.
     *
     * Every transition that touches the memory touches the record too, and the
     * two halves are only meaningful together: a trashed record with no memory
     * restores to the wrong state, and a memory for a record that never moved
     * misdirects its next restore. A hard delete has the sharper version — a
     * surviving memory row is inherited by the next record on that id, and a
     * cascade that removed a record's parts without removing the record leaves
     * a row whose composition has been emptied out from under it.
     *
     * The pre-transition hook is dispatched INSIDE this unit too (see
     * {@see self::apply()}), so a veto raised after a listener has already
     * written takes that write down with it rather than leaving half a cleanup
     * behind.
     *
     * Joins an OUTER transaction rather than nesting inside it: PDO has no
     * savepoint-based nesting, so a `beginTransaction()` here would throw when a
     * caller already opened one, and a commit here would end THEIR unit of work
     * early. When one is already open, that transaction is the atomicity.
     *
     * @param callable(): void $work The statements to run together.
     */
    private function transactionally(callable $work): void
    {
        if ($this->pdo->inTransaction()) {
            $work();

            return;
        }

        $this->pdo->beginTransaction();
        try {
            $work();
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();

            throw $e;
        }
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
     * ASK, then write — as one unit — and announce only what actually happened.
     *
     * The single dispatch site for {@see self::HOOK_CHANGING}, shared by all
     * four mutators. One site rather than four is not tidiness: a veto point
     * that exists on three actions and not the fourth is worse than none,
     * because it reads as a guarantee and is not one.
     *
     * Where it sits in the sequence, and why
     * --------------------------------------
     * AFTER every core check (the type offers the action, the record exists in
     * this tenant, the state rules permit it, no declared guard blocks a delete)
     * and AFTER the idempotent no-op returns. So:
     *
     *  - core's own refusals keep their existing reason keys and never become
     *    "blocked by a plugin"; plugin code is not consulted about a transition
     *    core would refuse anyway;
     *  - a no-op writes nothing, so there is nothing to veto and nothing to
     *    announce — trashing an already-trashed record fires NEITHER hook,
     *    exactly as it fires no `changed` today.
     *
     * And BEFORE the write, inside the same transaction as the write. That
     * pairing is what makes "a veto leaves no partial write" true rather than
     * merely likely:
     *
     *  - the transition's own statements have not run when the veto is raised;
     *  - a listener that wrote before a LATER listener vetoed is rolled back
     *    with it, so the unit is all-or-nothing rather than "core's half".
     *
     * When a caller already holds a transaction open, {@see self::transactionally()}
     * joins it rather than nesting, and the veto propagates out with nothing of
     * ours written. Rolling back somebody else's unit of work is not this
     * method's call to make — but it has also written nothing that would need
     * rolling back.
     *
     * {@see self::announce()} runs only on success, and outside the
     * transaction, so `changed` can never report a transition that did not
     * commit.
     *
     * @param string           $action  A {@see LifecycleAction} constant.
     * @param int|string       $id      The record's key.
     * @param string|null      $from    The state before.
     * @param string|null      $to      The state after (null when the row is gone).
     * @param int|null             $actorId  The acting profile.
     * @param callable(): void     $write    The statements this transition performs.
     * @param array<string, mixed> $metadata Extra audit detail this transition carries.
     * @return LifecycleResult|null Null when the transition went through; the
     *         refusal to return when a listener vetoed it.
     */
    private function apply(
        DataTypeDefinition $definition,
        string $action,
        int $tenantId,
        int|string $id,
        ?string $from,
        ?string $to,
        ?int $actorId,
        callable $write,
        array $metadata = []
    ): ?LifecycleResult {
        $payload = self::payload($definition, $action, $id, $tenantId, $from, $to, $actorId);

        try {
            $this->transactionally(function () use ($payload, $write): void {
                $this->hookManager?->dispatch(self::HOOK_CHANGING, $payload);
                $write();
            });
        } catch (HookVetoException $e) {
            // `reason()`, never `getMessage()` — the client-safe subset, per the
            // WC-186 leak guard. The state reported is the one the record still
            // holds, because it never moved.
            return LifecycleResult::vetoed($e->reason(), $from);
        }

        $this->announce($definition, $action, $id, $tenantId, $from, $to, $actorId, $metadata);

        return null;
    }

    /**
     * The payload both lifecycle hooks carry.
     *
     * Built once and shared so `changing` and `changed` describe the same
     * transition in the same words: a listener that vetoes on `changing` and one
     * that reacts on `changed` must not have to read two different shapes to
     * answer the same question. On `changing` the values are the INTENT (`to` is
     * where the record is about to go); on `changed` they are the record.
     *
     * @param string      $action  A {@see LifecycleAction} constant.
     * @param int|string  $id      The record's key.
     * @param string|null $from    The state before.
     * @param string|null $to      The state after (null when the row is gone).
     * @param int|null    $actorId The acting profile.
     * @return array<string, mixed>
     */
    private static function payload(
        DataTypeDefinition $definition,
        string $action,
        int|string $id,
        int $tenantId,
        ?string $from,
        ?string $to,
        ?int $actorId
    ): array {
        return [
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
    }

    /**
     * Announce a transition that HAPPENED on the hook spine, and record it in
     * the audit log.
     *
     * Called only from {@see self::apply()}, only after the write committed —
     * so a vetoed transition produces no `changed` event and no audit entry,
     * because nothing changed and nothing was audited into existence.
     *
     * The audit metadata carries anything the transition removed BESIDES the
     * record — `cascaded`, for a delete that took a composition with it. After
     * the write those rows cannot be counted from anywhere, so the audit entry
     * is the only place the blast radius survives.
     *
     * The HOOK payload is deliberately not widened with it. It is the shape
     * `changing` and `changed` share and that listeners are written against, and
     * a listener already owns the tables in question — it can count them itself,
     * with an answer that is current rather than sampled.
     *
     * @param int|string           $id       The record's key.
     * @param string|null          $from     The state before.
     * @param string|null          $to       The state after (null when the row is gone).
     * @param int|null             $actorId  The acting profile.
     * @param array<string, mixed> $metadata Extra audit detail.
     */
    private function announce(
        DataTypeDefinition $definition,
        string $action,
        int|string $id,
        int $tenantId,
        ?string $from,
        ?string $to,
        ?int $actorId,
        array $metadata = []
    ): void {
        $this->hookManager?->dispatch(
            self::HOOK_CHANGED,
            self::payload($definition, $action, $id, $tenantId, $from, $to, $actorId)
        );

        $this->auditLogger?->record('data_type.' . $action, [
            'tenant_id' => $tenantId,
            'actor_user_id' => $actorId,
            'target_type' => $definition->key(),
            'target_id' => is_numeric($id) ? (int) $id : null,
            'metadata' => ['from' => $from, 'to' => $to, 'record_id' => (string) $id] + $metadata,
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
