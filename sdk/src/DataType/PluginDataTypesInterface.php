<?php

declare(strict_types=1);

namespace Whity\Sdk\DataType;

/**
 * Declares the DATA TYPES a plugin owns — `registerDataType` (SDK 1.19, WC-723).
 *
 * A data type names a kind of record the plugin stores in its OWN table and
 * hands the host three things it cannot infer: where the record lives, what
 * lifecycle states it can occupy, and which other rows still point at it.
 *
 * The host never learns what any of it MEANS. It learns that `acme_entries`
 * references `acme_records.id` and that a blocked delete should say "recorded
 * entries" — which is enough to enforce the guard, phrase the refusal, and
 * offer trash / restore / retire without a line of plugin-authored UI.
 *
 * Lifecycle: trashed is NOT retired
 * ---------------------------------
 * The two end-states are routinely conflated and the host keeps them apart:
 *
 *  - **trashed** — "this should not exist": a mistake. Reversible, and
 *    removable for real once nothing references it.
 *  - **retired** — "this served its purpose": not a mistake, and NOT reversible.
 *    The record must persist because rows that already reference it still need
 *    it to resolve. Retirement removes the FUTURE, not the past: no new
 *    references, existing ones untouched. A retired record can never be
 *    restored and can never be deleted, even when nothing references it.
 *
 * Declaring `retirable` without `trashable` is perfectly valid, and so is the
 * reverse. They are independent axes, not two points on one scale.
 *
 * Declaration shape
 * -----------------
 *     public function getDataTypes(): array
 *     {
 *         return [
 *             'record' => [
 *                 'table'     => 'acme_records',
 *                 'key'       => 'id',              // default: 'id'
 *                 'tenant_column' => 'tenant_id',   // default: 'tenant_id'
 *                 'label'     => ['en' => 'Record', 'ar' => 'سجل'],
 *                 'lifecycle' => [
 *                     'column'        => 'status',
 *                     'states'        => ['draft', 'active', 'retired', 'trashed'],
 *                     'default_state' => 'active',
 *                     'trashable'     => true,
 *                     'retirable'     => true,
 *                     'trashed_state' => 'trashed', // default when trashable
 *                     'retired_state' => 'retired', // default when retirable
 *                 ],
 *                 'blocks_delete' => [
 *                     [
 *                         'table'  => 'acme_entries',
 *                         'column' => 'record_id',
 *                         'label'  => 'recorded entries',
 *                         // optional: referencing rows in these states do not block
 *                         'ignore_when' => ['status' => ['trashed']],
 *                     ],
 *                 ],
 *                 'permissions' => [
 *                     'read'   => 'acme:read',
 *                     'trash'  => 'acme:manage',
 *                     'restore'=> 'acme:manage',
 *                     'retire' => 'acme:retire',
 *                     'delete' => 'acme:manage',
 *                 ],
 *             ],
 *         ];
 *     }
 *
 * Verifying what the host accepted
 * --------------------------------
 * `GET /api/data-types` ROUND-TRIPS this declaration: every field above is
 * echoed back as the host accepted it — `ignore_when` included, and with the
 * host's own normalisation (guard values become strings) visible. Checking
 * whether a field took effect is therefore a diff against the entry, never a
 * read of the host's source.
 *
 * The one field that is NOT a copy of the declaration is `actions`, which is
 * filtered per caller — see "Honest degradation" below.
 *
 * Namespacing and ownership
 * -------------------------
 * Declare BARE keys. The host stores them under this plugin's own namespace, so
 * `record` becomes `acme:record` — two plugins may both declare `record`, and
 * none can shadow a core type. The prefix derives from the plugin NAME the
 * loader supplies, never from anything returned here.
 *
 * Every table named — the type's own table AND every `blocks_delete` table —
 * must be one this plugin declared through
 * {@see \Whity\Sdk\Tenant\PluginTablesInterface}, and must be tenant-scoped.
 * A guard over a table the plugin does not own would be a way to count rows in
 * somebody else's data, so it is refused.
 *
 * Honest degradation
 * ------------------
 * An action is offered only when BOTH its lifecycle support and its permission
 * are declared. Omit `permissions['retire']` and no Retire affordance is
 * generated and the retire endpoint refuses — the host never renders a control
 * that would silently do nothing, and never runs an ungated one.
 *
 * The escape hatch stays open: a plugin keeps its own routes and its own
 * screens for everything the generator cannot express. This raises the floor;
 * it does not cap the ceiling.
 *
 * OPTIONAL. Plugins that do not implement it are untouched.
 */
interface PluginDataTypesInterface
{
    /**
     * The data types this plugin owns, keyed by BARE slug.
     *
     * A slug is lowercase, starts with a letter, and contains only letters,
     * digits and underscores — no colon, which is the namespace separator the
     * host applies.
     *
     * An invalid declaration is rejected with a logged warning rather than
     * crashing the host, and rejection is per data type: one malformed entry
     * does not discard the plugin's other types.
     *
     * @return array<string, array<string, mixed>> bare slug => declaration
     */
    public function getDataTypes(): array;
}
