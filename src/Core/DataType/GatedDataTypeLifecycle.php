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
