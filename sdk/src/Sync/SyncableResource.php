<?php

declare(strict_types=1);

namespace Whity\Sdk\Sync;

/**
 * Describes a plugin table as an offline-first, two-way-syncable resource, so
 * {@see SyncController} can drive its full sync lifecycle — idempotent create,
 * optimistic-concurrency update/delete, soft-delete tombstones and an
 * incremental changes feed — without the plugin re-implementing any of it.
 *
 * A plugin implements this once per syncable table and hands it to a
 * SyncController; everything platform-shaped (tenant scoping, the change
 * sequence, the wire contract the desktop sync engine speaks) lives in the
 * controller. The table MUST carry the standard sync columns
 * ({@see SyncController::SYNC_COLUMNS}) — the same set
 * `AddSyncColumnsToDemoCatalogItems` adds: `version`, `client_uuid`,
 * `deleted_at`, `updated_by`, `change_seq`, `created_at`, `updated_at` — plus a
 * UNIQUE index on `(tenant_id, client_uuid)` for idempotent create.
 *
 * This is the "extract-once / consume-twice" contract generalized from the
 * DemoCatalog pilot (ADR 0003): DemoCatalog was the first consumer; any ported
 * feature (Relations, Taxonomy, …) is the next.
 */
interface SyncableResource
{
    /**
     * The physical table name. Trusted, code-defined (never user input) — it is
     * interpolated into SQL. Must match `^[a-z_][a-z0-9_]*$`.
     */
    public function table(): string;

    /**
     * The platform-wide sequence key that stamps this resource's `change_seq`
     * (e.g. `democatalog:change_seq`). Unique per resource so two resources'
     * feeds never share a cursor space.
     */
    public function sequenceKey(): string;

    /**
     * The resource's own (non-sync) columns, in the order they should appear in
     * SELECT/INSERT/UPDATE — e.g. `['name', 'description', 'status']`. Each is
     * trusted and interpolated into SQL; each must match `^[a-z_][a-z0-9_]*$`.
     *
     * @return list<string>
     */
    public function domainColumns(): array;

    /**
     * Validate a decoded request body and map it to column => value pairs for
     * {@see domainColumns()}. `$requireAll` is true for create/update (all
     * required fields must be present) — a resource may still allow optional
     * fields to default.
     *
     * @param array<string, mixed> $body
     * @return array{ok: true, values: array<string, mixed>}|array{ok: false, error: string}
     */
    public function validate(array $body, bool $requireAll): array;

    /**
     * Map a DB row's DOMAIN fields to the public JSON shape. The controller adds
     * every sync field (id, tenantId, clientUuid, version, deletedAt, updatedBy,
     * createdAt, updatedAt) around this, so return only the resource's own
     * fields (e.g. `['name' => ..., 'status' => ...]`).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function toPublicFields(array $row): array;
}
