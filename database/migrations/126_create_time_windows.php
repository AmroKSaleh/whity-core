<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * CreateTimeWindows migration (#1070) — a named period that can be closed.
 *
 * WHY THIS EXISTS
 * ---------------
 * A NAMED, NON-OVERLAPPING PERIOD that records can be scoped to and rolled up
 * by, and that can be CLOSED the way a set of books is closed, was a primitive
 * the platform did not have. Two independent requesters asked for it within an
 * hour of each other in vocabularies with no word in common, and a downstream
 * consumer had already built its own and threaded it through twelve of its own
 * migrations. That is the shape of a missing platform concept: everyone who
 * needs it either builds one or does without, and two implementations of it
 * disagree the moment both exist — a record filed into one subsystem's period
 * does not match the other's, and nothing reports that they differ.
 *
 * THREE TABLES, AND WHY EACH IS SEPARATE
 * --------------------------------------
 * `time_window_types` — the tenant's own vocabulary of period KINDS, and the
 * nesting between them. Per tenant for the same reason `ou_types` (migration
 * 102) is: an agricultural operation slices time into a `crop_year` and the
 * `growing_season`s inside it; a ceramics works into a `kiln_campaign` and the
 * `firing_run`s inside it. Neither vocabulary belongs in the other's picker, and
 * a core enumeration would have to contain both. `source` records PROVENANCE —
 * `tenant` for a key an administrator authored, `core`, or the plugin slug a
 * declared key came from — so an operator deciding what is safe to rename can
 * tell a key their own team invented from one a plugin's code binds to.
 *
 * `time_windows` — one actual period, with EXPLICIT boundaries. This is the
 * table that refuses to assume the calendar. `starts_on` and `ends_on` are
 * authored, not computed: a period of a given kind need not begin on the first
 * of a month, need not be a fixed fraction of the period containing it, and need
 * not be the same length as its siblings. Nothing in this subsystem derives a
 * boundary from a month or a calendar year, and there is deliberately no
 * column from which one could be derived.
 *
 * `time_window_state_events` — the append-only record of every seal and every
 * unseal: which window, which act, who, when, and why. Separate from
 * `time_windows` because a state column can only hold the CURRENT state, and the
 * reason a period was reopened is exactly the fact an institution needs six
 * months later. The state column is a materialisation of the newest row here;
 * both are written in one transaction by one repository method, and a test pins
 * that they agree.
 *
 * NON-OVERLAP IS ENFORCED IN THE REPOSITORY, NOT BY A CONSTRAINT
 * --------------------------------------------------------------
 * Two windows of the same type in the same tenant may not overlap, and that is
 * checked in {@see \Whity\Core\TimeWindow\TimeWindowRepository} under a row lock
 * rather than declared here. PostgreSQL could express it as an exclusion
 * constraint over a `daterange`, but only with the `btree_gist` extension for
 * the equality parts, and requiring an extension turns a `migrate run` into a
 * privilege question on every deployment. SQLite — the engine the test schema is
 * built on — cannot express it at all, so a constraint here would be a guarantee
 * that exists on one engine and silently does not on the other. One enforcement
 * point that behaves the same everywhere is worth more than two that disagree.
 * The inline `CHECK (starts_on <= ends_on)` IS declared, because a
 * single-row invariant costs nothing and both engines honour it.
 *
 * WHY `ON DELETE CASCADE` FROM A TYPE TO ITS WINDOWS
 * -------------------------------------------------
 * It is the TENANT-TEARDOWN path, not the delete path. Deleting a tenant
 * cascades to its types and must cascade on to its windows, or the teardown
 * stops halfway. Deleting a TYPE that still has windows is refused outright by
 * the repository, exactly as `OuTypeRepository::delete()` refuses to strand
 * units — the FK action is the backstop for the case the application never
 * reaches, and SQLite honours FK actions only under `PRAGMA foreign_keys = ON`
 * in any case.
 *
 * THE FOUR PERMISSIONS
 * --------------------
 * Reading, writing, closing and reopening are four different authorities and the
 * separation is the point. Closing is a control an operator exercises routinely;
 * reopening a sealed period is the most consequential act in the subsystem and
 * an institution will want it held by fewer people than closing. Folding reopen
 * into close would make "may seal the books" and "may unseal them" the same
 * grant, which no institution means.
 *
 * Granted by CAPABILITY, never to the role literally named `admin` — the
 * recorded hazard of that pattern (#834) is that a deployment running a custom
 * administrative role silently loses a capability on upgrade. See
 * {@see AUDIENCES} for which capability identifies each audience and why.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateTimeWindows
{
    /**
     * The four slugs this migration introduces.
     *
     * Descriptions are written for somebody reading a permission picker, so they
     * say what the permission LETS A PERSON DO rather than restating the slug.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        CorePermissions::TIME_WINDOWS_READ =>
            'See the tenant\'s named periods, their boundaries, and whether each is open or closed',
        CorePermissions::TIME_WINDOWS_WRITE =>
            'Define the tenant\'s period vocabulary, and create and adjust the periods themselves',
        CorePermissions::TIME_WINDOWS_CLOSE =>
            'Close a period — seal it, after being told what it still holds unfinished',
        CorePermissions::TIME_WINDOWS_REOPEN =>
            'Reopen a closed period, on the record, with a stated reason',
    ];

    /**
     * Which existing capability identifies the audience for each new slug.
     *
     * A period vocabulary is tenant-level configuration, so the settings
     * capabilities are the honest anchor: whoever a deployment already trusts to
     * see its configuration should see its periods, and whoever it trusts to
     * change its configuration should be able to define them.
     *
     * `reopen` anchors on `settings:manage` rather than `settings:write`
     * deliberately. It is the one act in this subsystem that undoes a seal other
     * people have relied on, and anchoring it to the narrowest existing
     * configuration authority means a deployment that has separated "may adjust
     * settings" from "may govern them" gets that separation here for free.
     *
     * All four anchors are verified to have holders on a database that has only
     * been MIGRATED — not seeded — which is the state this migration runs in.
     * An anchor granted later by the seeder would find an empty audience here
     * and the slug would ship held by nobody.
     *
     * @var array<string, list<string>>
     */
    private const AUDIENCES = [
        CorePermissions::TIME_WINDOWS_READ => [CorePermissions::SETTINGS_READ],
        CorePermissions::TIME_WINDOWS_WRITE => [CorePermissions::SETTINGS_WRITE],
        CorePermissions::TIME_WINDOWS_CLOSE => [CorePermissions::SETTINGS_WRITE],
        CorePermissions::TIME_WINDOWS_REOPEN => [CorePermissions::SETTINGS_MANAGE],
    ];

    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement per table, never a loop over
        // interpolated names — TenantOwnedTablesTest and CoreTablesTest
        // re-derive their registries by scanning this source, so every name has
        // to appear literally. The keyword is spelled hyphenated in prose here
        // and below for the same reason migrations 059, 108, 112 and 122 spell
        // it that way: the schema test scans the raw file text for it and would
        // read a plain one inside a comment as a real table declaration.
        //
        // `type_key` (not `key`) dodges the reserved word across the PostgreSQL
        // and SQLite-test engines, the same dodge `ou_types.type_key` and
        // `tag_groups.group_key` make for the same reason.
        $db->exec("
            CREATE TABLE IF NOT EXISTS time_window_types (
                id             BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id      INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                type_key       VARCHAR(128)  NOT NULL,
                label          VARCHAR(255)  NOT NULL,
                parent_type_id BIGINT        REFERENCES time_window_types(id) ON DELETE SET NULL,
                source         VARCHAR(64)   NOT NULL DEFAULT 'tenant',
                created_at     TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at     TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, type_key)
            )
        ");

        // The vocabulary is always read whole, from the tenant, to render a
        // picker or resolve a key; the parent index serves the "may I delete
        // this type?" check from the other direction.
        $db->exec('CREATE INDEX IF NOT EXISTS idx_time_window_types_tenant_id ON time_window_types(tenant_id)');
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_time_window_types_tenant_parent
                ON time_window_types(tenant_id, parent_type_id)'
        );

        // One period. `window_key` is the stable code a report or an export
        // names; `label` is what a person reads, and either may be renamed
        // without the other changing meaning.
        //
        // `state` is a two-value vocabulary and the CHECK says so. A third state
        // is a schema change on purpose: "open" and "closed" are the whole model
        // — a period is either accruing or sealed — and a status column that can
        // grow silently is how a seal stops meaning one thing.
        $db->exec("
            CREATE TABLE IF NOT EXISTS time_windows (
                id               BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id        INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                window_type_id   BIGINT        NOT NULL REFERENCES time_window_types(id) ON DELETE CASCADE,
                parent_window_id BIGINT        REFERENCES time_windows(id) ON DELETE SET NULL,
                window_key       VARCHAR(128)  NOT NULL,
                label            VARCHAR(255)  NOT NULL,
                starts_on        DATE          NOT NULL,
                ends_on          DATE          NOT NULL,
                state            VARCHAR(16)   NOT NULL DEFAULT 'open',
                created_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at       TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, window_type_id, window_key),
                CHECK (state IN ('open', 'closed')),
                CHECK (starts_on <= ends_on)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_time_windows_tenant_id ON time_windows(tenant_id)');
        // THE RESOLUTION READ. "Which window of this kind contains this date?" is
        // the question every consumer asks, and it is the only reason this
        // composite exists in this order: tenant, then kind, then the boundary
        // the range scan starts from.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_time_windows_tenant_type_dates
                ON time_windows(tenant_id, window_type_id, starts_on, ends_on)'
        );
        // The nesting read: "what is inside this period?", which a close has to
        // ask before it can seal anything.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_time_windows_tenant_parent
                ON time_windows(tenant_id, parent_window_id)'
        );

        // THE SEAL TRAIL. Append-only: rows are inserted and never updated or
        // deleted, so there is no `updated_at` and no actor-of-the-update
        // column. A reopen does not amend the close it undoes; it is a new row
        // that supersedes it, and both remain readable.
        //
        // `reason` is nullable because a close does not need one — sealing a
        // period on schedule is the ordinary case. A REOPEN does need one, and
        // that is enforced at the boundary rather than by a CHECK, because the
        // check would have to be widened the first time a third act is recorded
        // and widening an inline CHECK is impossible on SQLite.
        //
        // `cascaded_from_window_id` distinguishes "somebody closed this period"
        // from "this period was closed as part of closing the one containing
        // it". Without it, an operator reading the trail cannot tell an act they
        // performed from a consequence of one they performed elsewhere.
        $db->exec("
            CREATE TABLE IF NOT EXISTS time_window_state_events (
                id                      BIGSERIAL   NOT NULL PRIMARY KEY,
                tenant_id               INTEGER     NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                time_window_id          BIGINT      NOT NULL REFERENCES time_windows(id) ON DELETE CASCADE,
                action                  VARCHAR(16) NOT NULL,
                actor_profile_id        INTEGER     REFERENCES profiles(id) ON DELETE SET NULL,
                reason                  TEXT,
                cascaded_from_window_id BIGINT      REFERENCES time_windows(id) ON DELETE SET NULL,
                occurred_at             TIMESTAMP   NOT NULL DEFAULT NOW(),
                CHECK (action IN ('closed', 'reopened'))
            )
        ");

        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_time_window_state_events_tenant_id
                ON time_window_state_events(tenant_id)'
        );
        // The trail as a period reads it: oldest first, one period at a time.
        // `id` is ascending and monotonic, so it orders the trail without
        // depending on clock resolution — a cascaded close writes several rows in
        // one transaction, and they share `occurred_at` to the microsecond.
        $db->exec(
            'CREATE INDEX IF NOT EXISTS idx_time_window_state_events_tenant_window
                ON time_window_state_events(tenant_id, time_window_id, id)'
        );

        foreach (self::PERMISSIONS as $name => $description) {
            // Migration 013 seeds the whole CorePermissions list, so on a fresh
            // install these rows already exist by the time this runs; the insert
            // is here so the migration stands on its own against a database
            // whose catalogue drifted, and it can never overwrite a
            // human-written description (ON CONFLICT DO NOTHING).
            $db->query(
                'INSERT INTO permissions (name, description, created_at)
                 VALUES (:name, :description, NOW())
                 ON CONFLICT (name) DO NOTHING',
                [':name' => $name, ':description' => $description]
            );
        }

        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'INSERT INTO role_permissions (role_id, permission_id, created_at)
                     VALUES (:role_id, :permission_id, NOW())
                     ON CONFLICT (role_id, permission_id) DO NOTHING',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }
        }
    }

    public static function down(Database $db): void
    {
        foreach (self::AUDIENCES as $slug => $audiencePermissions) {
            $permissionId = self::permissionId($db, $slug);
            if ($permissionId === null) {
                continue;
            }

            // Resolved the same way up() did. A role granted the anchor
            // capability AFTER this migration ran never received the slug, so it
            // has nothing to take back; a role that lost the anchor in between
            // keeps the slug, which is the conservative direction for a down():
            // it leaves an operator with a permission they may not need rather
            // than removing one they may.
            foreach (self::rolesHoldingAny($db, $audiencePermissions) as $roleId) {
                $db->query(
                    'DELETE FROM role_permissions WHERE role_id = :role_id AND permission_id = :permission_id',
                    [':role_id' => $roleId, ':permission_id' => $permissionId]
                );
            }

            // Only when nothing else holds it. Migration 013 seeded the
            // catalogue and owns its removal; this clause is the safety net for
            // a database where 013's catalogue step did not run.
            $db->query(
                'DELETE FROM permissions
                 WHERE name = :name
                   AND NOT EXISTS (
                       SELECT 1 FROM role_permissions rp WHERE rp.permission_id = permissions.id
                   )',
                [':name' => $slug]
            );
        }

        // Events before windows before types: each holds a foreign key into the
        // one after it. CASCADE on the DROP covers it on PostgreSQL, but SQLite
        // (the test-schema engine) has no such clause, and ordering costs
        // nothing.
        $db->exec('DROP INDEX IF EXISTS idx_time_window_state_events_tenant_window');
        $db->exec('DROP INDEX IF EXISTS idx_time_window_state_events_tenant_id');
        $db->exec('DROP TABLE IF EXISTS time_window_state_events CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_time_windows_tenant_parent');
        $db->exec('DROP INDEX IF EXISTS idx_time_windows_tenant_type_dates');
        $db->exec('DROP INDEX IF EXISTS idx_time_windows_tenant_id');
        $db->exec('DROP TABLE IF EXISTS time_windows CASCADE');

        $db->exec('DROP INDEX IF EXISTS idx_time_window_types_tenant_parent');
        $db->exec('DROP INDEX IF EXISTS idx_time_window_types_tenant_id');
        $db->exec('DROP TABLE IF EXISTS time_window_types CASCADE');
    }

    /**
     * The ids of every role holding ANY of the given permissions DIRECTLY.
     *
     * Direct grants only, the rule migration 110 states: effective resolution
     * walks role inheritance, organizational units and delegations, and a
     * migration that followed those paths would write grant rows onto roles that
     * hold the capability transitively — turning an inherited permission into an
     * independent one and quietly changing what revoking the parent does.
     *
     * @param list<string> $permissions
     * @return list<int>
     */
    private static function rolesHoldingAny(Database $db, array $permissions): array
    {
        $roleIds = [];
        foreach ($permissions as $permission) {
            $rows = $db->query(
                'SELECT rp.role_id
                   FROM role_permissions rp
                   JOIN permissions p ON p.id = rp.permission_id
                  WHERE p.name = :name',
                [':name' => $permission]
            )->fetchAll();

            if ($rows === false) {
                continue;
            }
            foreach ($rows as $row) {
                $roleIds[(int) $row['role_id']] = true;
            }
        }

        return array_map('intval', array_keys($roleIds));
    }

    private static function permissionId(Database $db, string $name): ?int
    {
        $result = $db->query('SELECT id FROM permissions WHERE name = :name', [':name' => $name])->fetch();

        return $result === false ? null : (int) $result['id'];
    }
}
