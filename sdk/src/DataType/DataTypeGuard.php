<?php

declare(strict_types=1);

namespace Whity\Sdk\DataType;

/**
 * Read-only access to the host's REFERENTIAL GUARD and lifecycle state
 * (SDK 1.19, WC-723).
 *
 * Why a plugin wants this
 * ----------------------
 * The host enforces the declared guards on its own generated lifecycle
 * endpoints. A plugin that keeps its own delete route — the escape hatch that
 * must remain open — would otherwise have to re-derive "what still references
 * this?" in hand-written SQL, which is exactly the inconsistent, incomplete,
 * easily-bypassed situation declared guards exist to end. Two enforcement
 * paths that disagree are worse than one.
 *
 * So the same evaluator the host uses is reachable from a plugin handler:
 *
 *     $guard = \Whity\app(\Whity\Sdk\DataType\DataTypeGuard::class);
 *     $blockers = $guard->blockingReferences('acme:record', $tenantId, $id);
 *     if ($blockers !== []) {
 *         return Response::error('Still referenced', 409, ['blockers' => $blockers]);
 *     }
 *
 * Authority
 * ---------
 * READ ONLY. Every method answers a question; none trashes, retires or deletes
 * anything. Holding this object confers no authority a plugin does not already
 * have — the answers concern only types the plugin (or another source) declared,
 * and every query is bound to the tenant the caller passes.
 *
 * Tenant scoping
 * --------------
 * `$tenantId` is the resolved tenant of the current request (0 = the system
 * tenant). A record in another tenant is reported as absent, never as a
 * different tenant's row: an unknown type or an id outside the tenant answers
 * `null` / `[]` / `false` rather than leaking existence.
 */
interface DataTypeGuard
{
    /**
     * The lifecycle state of one record, or null when it does not exist in this
     * tenant (or the type declares no lifecycle column).
     *
     * @param string     $dataType The namespaced type key, e.g. `acme:record`.
     * @param int        $tenantId The resolved tenant id (0 = system tenant).
     * @param int|string $id       The record's primary key value.
     */
    public function stateOf(string $dataType, int $tenantId, int|string $id): ?string;

    /**
     * The declared references that currently BLOCK deleting this record.
     *
     * Empty when nothing points at it — or when the type declares no guards,
     * which is an honest "nothing was declared", not a proof of safety.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's primary key value.
     * @return list<array{table: string, label: string, count: int}> One entry
     *         per guard with at least one referencing row, carrying the
     *         plugin-declared human label for the refusal message.
     */
    public function blockingReferences(string $dataType, int $tenantId, int|string $id): array;

    /**
     * Whether a NEW reference to this record may be created.
     *
     * False once the record is trashed or retired — the single question that
     * distinguishes "closed to the future" from "readable". Existing references
     * are never affected by the answer; this gates only new ones, and is what a
     * plugin's picker or foreign-key validation should consult.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's primary key value.
     */
    public function isReferenceable(string $dataType, int $tenantId, int|string $id): bool;

    /**
     * Whether a hard delete would be permitted right now.
     *
     * False when a guard blocks it, when the record is retired (retirement is
     * permanent), when a trashable type's record has not been trashed first, or
     * when the record does not exist in this tenant.
     *
     * @param string     $dataType The namespaced type key.
     * @param int        $tenantId The resolved tenant id.
     * @param int|string $id       The record's primary key value.
     */
    public function canDelete(string $dataType, int $tenantId, int|string $id): bool;
}
