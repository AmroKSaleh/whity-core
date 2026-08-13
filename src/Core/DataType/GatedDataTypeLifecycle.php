<?php

declare(strict_types=1);

namespace Whity\Core\DataType;

use Whity\Auth\RoleChecker;
use Whity\Sdk\DataType\DataTypeLifecycle;

/**
 * The lifecycle service with the host's AUTHORIZATION in front of it — the
 * object registered under the SDK's {@see DataTypeLifecycle} write contract, and
 * the object the generated endpoints gate themselves with.
 *
 * The gap this closes
 * -------------------
 * Core told adopters to route their lifecycle writes through core, and then
 * published only {@see \Whity\Sdk\DataType\DataTypeGuard} — a contract that
 * answers questions and changes nothing. So a plugin needing to actually trash a
 * record duck-typed {@see DataTypeLifecycleService}, a core internal with no
 * contract and no compatibility promise. That is core's fault, not theirs.
 *
 * The read-only guarantee on `DataTypeGuard` is deliberate and load-bearing, and
 * is untouched: it stays the contract you can hand out knowing it confers no
 * authority. Writes get their own contract instead of being smuggled into that
 * one.
 *
 * ONE authorization implementation, not two that agree
 * ----------------------------------------------------
 * The risk in publishing a write contract is that in-process becomes a way
 * around a check the endpoint enforces. The endpoint and this contract therefore
 * do not merely apply "the same rules": {@see \Whity\Api\DataTypesApiHandler}
 * performs no authorization of its own any more, it calls
 * {@see self::authorize()} and the mutators below. There is one implementation.
 * Two written to agree eventually
 * do not, and the failure is silent in the direction that matters — a plugin
 * quietly holding more authority than the endpoint would grant.
 *
 * The gates, in the order the endpoint hits them
 * ----------------------------------------------
 *  1. the type is unregistered, OR the caller may not READ it → reported as
 *     UNKNOWN, never as forbidden. Whether a plugin declared `acme:record` is
 *     not something an unauthorized caller may establish by status code, and
 *     that has to hold in-process too or the contract becomes a catalogue
 *     enumerator;
 *  2. the type does not OFFER the action (no lifecycle support, or no declared
 *     permission) → 405, the same reason key the preview publishes. An action
 *     with no declared permission is not "open", it was never offered;
 *  3. the caller lacks the action's declared permission → 403 naming it,
 *     resolved through the same {@see RoleChecker} the RBAC middleware uses.
 *
 * Only then does the transition run, and everything it enforces — the state
 * rules, the declared guards, the composition checks, the plugin veto — is
 * unchanged and unreachable from here except through {@see DataTypeLifecycleService}.
 *
 * The actor is REQUIRED
 * ---------------------
 * {@see DataTypeLifecycleService} takes `?int $actorId` because there it is an
 * audit field. Here it is the SUBJECT of the permission check, so it cannot be
 * optional: an omitted actor would have to fail closed (a parameter that always
 * fails is a trap) or run ungated (the bypass this exists to remove).
 *
 * The tenant is passed explicitly rather than read from the ambient request
 * context, exactly as it is on `DataTypeGuard` — an in-process caller may be a
 * queue worker or a CLI command with no request. It is not a way in: the
 * permission is resolved per (profile, tenant), so it holds only where the actor
 * genuinely holds it there.
 *
 * Batches go through the same gates, one record at a time
 * ------------------------------------------------------
 * {@see self::performMany()} is the loop behind `POST /api/data-types/{type}/bulk`
 * (WC-746). It is a public method rather than a member of the SDK's write
 * contract, and it does not shortcut anything: it calls the SAME
 * {@see self::perform()} the four single-record mutators call, per id, so there
 * is no second path on which a check could be skipped. It lives here rather than
 * in the HTTP handler so the batch's semantics — one transaction per record,
 * duplicates collapsed, a refusal skipped and reported — sit beside the gates
 * they are constrained by, instead of in a layer that is only about HTTP.
 */
final class GatedDataTypeLifecycle implements DataTypeLifecycle
{
    private DataTypeRegistry $registry;

    private DataTypeLifecycleService $lifecycle;

    private RoleChecker $roleChecker;

    /**
     * @param DataTypeRegistry         $registry    Catalogue of declared types.
     * @param DataTypeLifecycleService $lifecycle   The single enforcement point.
     * @param RoleChecker              $roleChecker Authoritative permission resolution.
     */
    public function __construct(
        DataTypeRegistry $registry,
        DataTypeLifecycleService $lifecycle,
        RoleChecker $roleChecker
    ) {
        $this->registry = $registry;
        $this->lifecycle = $lifecycle;
        $this->roleChecker = $roleChecker;
    }

    /**
     * @inheritDoc
     */
    public function trash(
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleResult {
        return $this->perform(LifecycleAction::TRASH, $dataType, $tenantId, $id, $actorProfileId);
    }

    /**
     * @inheritDoc
     */
    public function restore(
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleResult {
        return $this->perform(LifecycleAction::RESTORE, $dataType, $tenantId, $id, $actorProfileId);
    }

    /**
     * @inheritDoc
     */
    public function retire(
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleResult {
        return $this->perform(LifecycleAction::RETIRE, $dataType, $tenantId, $id, $actorProfileId);
    }

    /**
     * @inheritDoc
     */
    public function delete(
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleResult {
        return $this->perform(LifecycleAction::DELETE, $dataType, $tenantId, $id, $actorProfileId);
    }

    /**
     * Perform ONE action over MANY records — skipping and reporting, never
     * aborting (WC-746).
     *
     * The semantics, and why they are these
     * -------------------------------------
     * A refusal on record 7 does NOT abort the batch. Clearing 493 of 500 items
     * and reporting why 7 refused is the behaviour adopters need; an "empty
     * trash" that fails entirely because one item is still referenced is the
     * failure mode this exists to remove. So every record is attempted, and
     * every record's verdict is returned.
     *
     * EACH RECORD IS ITS OWN UNIT OF WORK. There is deliberately no transaction
     * around this loop, and adding one would be a bug rather than a
     * strengthening: it would reintroduce all-or-nothing through the back door
     * (a failure on record 500 would undo the 499 the caller was told
     * succeeded), and it would hold one lock across the whole batch. Each call
     * below reaches {@see DataTypeLifecycleService::transactionally()}, which
     * opens and commits per record — so records 1–6 are committed and durable
     * before record 7 is even evaluated.
     *
     * When a CALLER already holds a transaction open, that transaction is the
     * atomicity — exactly as it is for a single call, and for the same reason
     * (PDO has no savepoint nesting, and ending somebody else's unit of work is
     * not core's call). The skip-and-report guarantee then holds only as far as
     * their unit of work does. An in-process bulk sweep should not hold one.
     *
     * The gates are not re-implemented here
     * -------------------------------------
     * Every id goes through {@see self::perform()} — the SAME private method the
     * four single-record mutators use, which applies {@see self::authorize()}
     * and then the same {@see DataTypeLifecycleService} transition. A bulk call
     * therefore cannot skip a check a single call enforces, because there is no
     * second path to skip it on. The veto hook fires per record, unchanged, and
     * one plugin refusing one record has no effect on the others.
     *
     * Duplicates are collapsed, first occurrence wins
     * ----------------------------------------------
     * The same id twice is one attempt and one result entry. Attempting it twice
     * would be wrong in both directions: the second attempt of a successful
     * trash reports a no-op success that the caller reads as a second record,
     * and the second attempt of a delete reports `not_found` for a record the
     * batch itself removed. Comparison is on the string form, since that is the
     * form a path parameter arrives in and the form a JSON body may carry either
     * `7` or `"7"` as.
     *
     * @param string                  $action         A {@see LifecycleAction} constant.
     * @param string                  $dataType       The namespaced type key.
     * @param int                     $tenantId       The resolved tenant id.
     * @param list<int|string>        $ids            The records to act on, in caller order.
     * @param int                     $actorProfileId The profile performing the transitions.
     * @return list<array{id: string, result: LifecycleResult}> One entry per DISTINCT
     *         id, in first-occurrence order.
     */
    public function performMany(
        string $action,
        string $dataType,
        int $tenantId,
        array $ids,
        int $actorProfileId
    ): array {
        $outcomes = [];
        $seen = [];

        foreach ($ids as $id) {
            $key = (string) $id;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $outcomes[] = [
                'id' => $key,
                'result' => $this->perform($action, $dataType, $tenantId, $key, $actorProfileId),
            ];
        }

        return $outcomes;
    }

    /**
     * Whether this caller may perform an action on this type — and if not, the
     * refusal to answer with.
     *
     * Public because the generated endpoints resolve their 404 / 405 / 403
     * through it rather than deriving the same three checks a second time. The
     * record's id is deliberately NOT a parameter: none of these gates depends
     * on it, and asking for one would suggest they do.
     *
     * @param string $dataType       The namespaced type key.
     * @param string $action         A {@see LifecycleAction} constant.
     * @param int    $tenantId       The resolved tenant id.
     * @param int    $actorProfileId The profile asking.
     * @return LifecycleResult|null Null when the caller may proceed.
     */
    public function authorize(string $dataType, string $action, int $tenantId, int $actorProfileId): ?LifecycleResult
    {
        $definition = $this->registry->get($dataType);
        if ($definition === null || !$this->may($definition, LifecycleAction::READ, $actorProfileId, $tenantId)) {
            // One answer for "no such type" and "not yours to read". A caller
            // who cannot read the type must not learn it exists.
            return LifecycleResult::unknownType();
        }

        if (!$definition->offers($action)) {
            return LifecycleResult::unsupported($action . '_not_offered');
        }

        if (!$this->may($definition, $action, $actorProfileId, $tenantId)) {
            return LifecycleResult::forbidden((string) $definition->permissionFor($action));
        }

        return null;
    }

    /**
     * Whether the caller holds the permission a type declares for an action.
     *
     * An action with no declared permission is NOT open — it was never offered,
     * so nobody holds it. Fail closed.
     *
     * @param string $action         A {@see LifecycleAction} constant.
     * @param int    $actorProfileId The profile asking.
     * @param int    $tenantId       The tenant the question is asked in.
     */
    public function may(DataTypeDefinition $definition, string $action, int $actorProfileId, int $tenantId): bool
    {
        $permission = $definition->permissionFor($action);
        if ($permission === null) {
            return false;
        }

        return $this->roleChecker->hasPermissionForProfile($actorProfileId, $permission, $tenantId);
    }

    /**
     * Gate, then transition.
     *
     * One site rather than four, for the same reason the veto hook has one
     * dispatch site: an authorization gate that covers three actions and not the
     * fourth reads as a guarantee and is not one.
     *
     * @param string     $action         A {@see LifecycleAction} constant.
     * @param string     $dataType       The namespaced type key.
     * @param int        $tenantId       The resolved tenant id.
     * @param int|string $id             The record's key.
     * @param int        $actorProfileId The profile performing the transition.
     */
    private function perform(
        string $action,
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleResult {
        $refusal = $this->authorize($dataType, $action, $tenantId, $actorProfileId);
        if ($refusal !== null) {
            return $refusal;
        }

        return match ($action) {
            LifecycleAction::TRASH => $this->lifecycle->trash($dataType, $tenantId, $id, $actorProfileId),
            LifecycleAction::RESTORE => $this->lifecycle->restore($dataType, $tenantId, $id, $actorProfileId),
            LifecycleAction::RETIRE => $this->lifecycle->retire($dataType, $tenantId, $id, $actorProfileId),
            default => $this->lifecycle->delete($dataType, $tenantId, $id, $actorProfileId),
        };
    }
}
