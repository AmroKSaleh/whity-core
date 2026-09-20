<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * LivePlanEntitlements — release the snapshots that made every tier identical.
 *
 * ── What was wrong ─────────────────────────────────────────────────────────
 *
 * Applying a plan used to COPY its entitlement bundle into
 * `tenant_entitlements`. Those rows are the per-tenant override layer — the
 * most specific one there is — so each subscriber carried a frozen copy of
 * their tier taken on the day they joined, outranking the tier itself. Editing
 * what "Pro" includes changed nothing for anybody already on Pro.
 *
 * Resolution now reads the tier live (tenant override ?? tier ?? baseline) and
 * `applyToTenant()` no longer writes the copies. This clears the ones already
 * out there: without it, every tenant who subscribed under the old code would
 * keep their snapshot for good and the new behaviour would reach only new
 * customers — the exact failure being fixed, preserved in data.
 *
 * ── Why it is safe to delete these rows ────────────────────────────────────
 *
 * ONLY ROWS THAT STILL AGREE WITH THE TENANT'S OWN PLAN ARE REMOVED. A row
 * matching what the tier already grants is, by definition, a copy — deleting it
 * changes nothing about what that tenant resolves to, because the tier answers
 * identically a moment later.
 *
 * A row that DISAGREES with the tier is left exactly where it is. That is an
 * operator's deliberate per-tenant grant: a customer given extra storage while
 * a deal was negotiated, a workspace left on an old limit on purpose. The old
 * code could not tell those apart from copies and erased them on every
 * re-apply; this migration refuses to guess, and errs toward keeping what
 * somebody typed on purpose.
 *
 * The consequence is honest and worth stating: a tenant holding a deliberate
 * override keeps it, and their tier will not move that one value until an
 * operator clears it. That is the correct meaning of an override, and it is now
 * visible in the tier editor rather than hidden in a copied row.
 */
class LivePlanEntitlements
{
    public static function up(Database $db): void
    {
        $pdo = $db->getPdo();

        // Delete a tenant's override ONLY where their own plan's bundle carries
        // the identical value for the identical key. The join through
        // `tenant_plan` is what keeps this per-tenant: a value copied from
        // tenant A's plan is never matched against tenant B's row.
        // NO TABLE ALIAS ON THE DELETE TARGET, deliberately: SQLite does not
        // accept one, and the test suite builds its schema from these same
        // migrations. An aliased form passes on PostgreSQL and fails every
        // real-engine test on the other engine — the drift this repo runs a
        // dual-dialect gate to catch.
        $pdo->exec('
            DELETE FROM tenant_entitlements
             WHERE EXISTS (
                   SELECT 1
                     FROM tenant_plan tp
                     JOIN plan_entitlements pe ON pe.plan_id = tp.plan_id
                    WHERE tp.tenant_id = tenant_entitlements.tenant_id
                      AND pe.entitlement_key = tenant_entitlements.entitlement_key
                      AND pe.value = tenant_entitlements.value
             )
        ');
    }

    /**
     * Irreversible, and says so rather than pretending.
     *
     * Going back would mean re-materialising each tenant's bundle into their
     * overrides — which is the bug, and which could not distinguish the rows
     * this migration deliberately kept from the ones it removed. A `down()`
     * that quietly wrote copies over an operator's real grants would lose data
     * that the `up()` went out of its way to protect.
     *
     * Nothing is lost by not reversing it: every deleted row was a duplicate of
     * a value the tier still supplies.
     */
    public static function down(Database $db): void
    {
        // Deliberately empty. See the note above.
    }
}
