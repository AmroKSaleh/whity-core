<?php

declare(strict_types=1);

namespace Whity\Sdk\DataType;

/**
 * The supported way to PERFORM a lifecycle transition in-process — trash,
 * restore, retire, delete (SDK 1.23).
 *
 * Why this exists
 * ---------------
 * The host told adopters to route their lifecycle writes through core, and then
 * handed them a read-only contract. {@see DataTypeGuard} answers questions and
 * changes nothing — that guarantee is deliberate and load-bearing, and it is not
 * being weakened here. But a plugin that needs to actually trash a record had
 * nowhere supported to go, so adopters duck-typed a host-internal service:
 * something with no contract, no compatibility promise, and no obligation to
 * keep the shape it had last release. That is the host's fault, not theirs, and
 * this is the answer.
 *
 * Reads keep their guarantee; writes get a supported path. Resolve whichever you
 * need:
 *
 *     $guard     = \Whity\app(\Whity\Sdk\DataType\DataTypeGuard::class);      // asks
 *     $lifecycle = \Whity\app(\Whity\Sdk\DataType\DataTypeLifecycle::class);  // acts
 *
 *     $outcome = $lifecycle->trash('acme:record', $tenantId, $id, $actorProfileId);
 *     if (!$outcome->isOk()) {
 *         return Response::error($outcome->message(), $outcome->httpStatus(), [
 *             'reason'   => $outcome->reason(),
 *             'blockers' => $outcome->blockers(),
 *         ]);
 *     }
 *
 * The same gates the endpoint enforces
 * ------------------------------------
 * Calling in-process is NOT a way around a check. Every method here applies the
 * host's own authorization in the same order the generated endpoint does — the
 * type must exist, the caller must be able to READ it (a type they may not read
 * is reported as unknown, never as forbidden, so its existence is not
 * discoverable), the type must OFFER the action, and the caller must hold the
 * permission that action declares. The host runs one implementation for both
 * paths rather than two written to agree, because two that must be kept in step
 * eventually are not.
 *
 * This is why `$actorProfileId` is REQUIRED and not optional. A write has to be
 * attributable to someone: that profile is what the permission check runs
 * against and what the audit entry records. An optional actor would have to
 * either fail closed (a parameter that always fails is a trap) or run ungated
 * (the bypass this contract exists to remove).
 *
 * `$tenantId` is passed explicitly, exactly as it is on {@see DataTypeGuard} —
 * an in-process caller may be a queue worker or a CLI command with no ambient
 * request. Passing another tenant's id is not a way in: the permission check is
 * per (profile, tenant), so it succeeds only where that actor genuinely holds
 * the permission in that tenant, and every generated statement binds the tenant
 * column. A record in another tenant is reported absent, never forbidden.
 *
 * Bulk operations: loop over these calls
 * --------------------------------------
 * Emptying a trash or retiring a selection is a LOOP over single-record calls,
 * and that is the sanctioned pattern rather than a stopgap. The tempting
 * alternative — one `UPDATE … WHERE status = 'trashed'` — bypasses every guard,
 * every veto and every hook at once, silently, and is exactly the "bypassed
 * through a secondary path" failure declared guards exist to end. A loop is
 * slower and correct; check each outcome as you go, since a record that refuses
 * is normal.
 *
 * There is deliberately no bulk method yet. It needs a decision this contract
 * has not made — does one veto abort the batch, or is it skipped and reported?
 * — and shipping either answer as an implicit default would be worse than not
 * shipping one.
 *
 * Idempotent, not destructive-by-accident
 * ---------------------------------------
 * Trashing an already-trashed record succeeds and writes nothing. A delete
 * removes the record AND the rows it declared as its composition, and is refused
 * outright while a declared guard blocks it — this contract cannot be used to
 * force one through.
 */
interface DataTypeLifecycle
{
    /**
     * Trash a record: reversible, closed to new references, pending removal.
     *
     * The state it currently holds is remembered, so a later restore returns it
     * there rather than to the type's default.
     *
     * @param string     $dataType        The namespaced type key, e.g. `acme:record`.
     * @param int        $tenantId        The resolved tenant id (0 = system tenant).
     * @param int|string $id              The record's primary key value.
     * @param int        $actorProfileId  The profile performing the transition; gated and audited.
     */
    public function trash(
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleOutcome;

    /**
     * Restore a trashed record to THE STATE IT HELD before it was trashed —
     * falling back to the type's default state only when that state was never
     * remembered or is no longer one the type declares.
     *
     * Refused for a retired record: retirement has no way back.
     *
     * @param string     $dataType        The namespaced type key.
     * @param int        $tenantId        The resolved tenant id.
     * @param int|string $id              The record's primary key value.
     * @param int        $actorProfileId  The profile performing the transition.
     */
    public function restore(
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleOutcome;

    /**
     * Retire a record: closed to new references, permanently readable, never
     * deletable and never restorable.
     *
     * Not a stronger trash. Retirement removes the future and leaves the past
     * intact, which is why rows that already reference the record keep
     * resolving against it.
     *
     * @param string     $dataType        The namespaced type key.
     * @param int        $tenantId        The resolved tenant id.
     * @param int|string $id              The record's primary key value.
     * @param int        $actorProfileId  The profile performing the transition.
     */
    public function retire(
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleOutcome;

    /**
     * Delete a record for real — and the rows it declared as its composition —
     * if every declared guard permits it.
     *
     * Refused while anything the type declared in `blocks_delete` still points
     * at the record, when the record is retired, and (on a trashable type) until
     * it has been trashed first. The composition is removed in the same
     * transaction, so a refusal leaves everything exactly where it was.
     *
     * @param string     $dataType        The namespaced type key.
     * @param int        $tenantId        The resolved tenant id.
     * @param int|string $id              The record's primary key value.
     * @param int        $actorProfileId  The profile performing the transition.
     */
    public function delete(
        string $dataType,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleOutcome;
}
