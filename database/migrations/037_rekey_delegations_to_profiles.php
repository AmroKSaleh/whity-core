<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * RekeyDelegationsToProfiles — forward migration (WC-bc07b6de, migration 037).
 *
 * Re-points `permission_delegations.grantor_user_id` and the user-grantee path
 * from `users.id` to `profiles.id` (ADR 0005 §9 — delegations reference
 * profiles, not users).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Schema changes (up)
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Add `grantor_profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE CASCADE`.
 * 2. Backfill `grantor_profile_id` from the users→profiles mapping established by
 *    migration 035 (migration_035_profile_ids table).
 * 3. Re-key the user-grantee path: for every `grantee_type = 'user'` row, set
 *    `grantee_id` to the mapped profile_id AND flip `grantee_type` to 'profile'
 *    in lock-step. After up() there are NO `grantee_type = 'user'` rows — this is
 *    essential because the resolver queries `grantee_type = 'profile'` only (both
 *    GRANTEE_USER and GRANTEE_PROFILE now equal the string 'profile'), so a
 *    leftover 'user' row would become a permanently-invisible silent permission
 *    drop.
 * 4. Handle orphan grantor rows: any row whose `grantor_user_id` has no mapping
 *    in migration_035_profile_ids would keep `grantor_profile_id = NULL`. Since
 *    migration 035 migrated every non-system user, a remaining orphan is a
 *    dangling/unenforceable grantor — we DELETE such rows (logging the count) so
 *    the NOT NULL constraint below can be enforced honestly.
 * 5. Drop `grantor_user_id` (PostgreSQL via ALTER TABLE DROP COLUMN; SQLite via
 *    the rename-recreate idiom, which also recreates ALL of migration 014's
 *    indexes: idx_pd_resolution, idx_pd_grantor (dropped later), idx_pd_ou).
 * 6. Enforce `grantor_profile_id NOT NULL` (matches the docblock/contract).
 * 7. Widen the grantee_type CHECK from ('role', 'user') to ('role', 'user',
 *    'profile'). The rewrite is quote-escaping robust and, on SQLite, is applied
 *    during the table recreate.
 * 8. Add `idx_pd_grantor_profile (tenant_id, grantor_profile_id)`; drop the old
 *    `idx_pd_grantor`.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Idempotency
 * ─────────────────────────────────────────────────────────────────────────────
 * up() checks column/constraint state before mutating, so a re-run on an
 * already-migrated database is safe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reversibility (down)
 * ─────────────────────────────────────────────────────────────────────────────
 * down() reverses the schema in the correct order:
 * 1. Re-add `grantor_user_id INTEGER NULL REFERENCES users(id) ON DELETE CASCADE`.
 * 2. Reverse-backfill grantor_user_id from the 035 mapping (profile_id → kept
 *    user_id).
 * 3. Convert grantee rows back: 'profile' → 'user' with grantee_id → user_id via
 *    the 035 mapping. This MUST happen BEFORE the CHECK is narrowed (item 5),
 *    otherwise a surviving 'profile' row would violate the narrowed CHECK on PG.
 * 4. Re-add idx_pd_grantor.
 * 5. Narrow the grantee_type CHECK back to ('role', 'user').
 * 6. Drop grantor_profile_id + idx_pd_grantor_profile.
 *
 * WARNING: rows DELETED by up()'s orphan handling (item 4) cannot be restored by
 * down() — the source user rows they referenced no longer have a mapping. down()
 * logs that any such rows are unrecoverable. Also, if a profile was created by
 * 035's collision-collapse (multiple users → one profile), the reverse mapping
 * is ambiguous; the kept_user_id (lowest-id user for that email) is used — the
 * same canonical choice 035 made.
 */
class RekeyDelegationsToProfiles
{
    public static function up(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $pdo->beginTransaction();
        try {
            self::runUp($db, $driver);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function runUp(Database $db, string $driver): void
    {
        // ── 1. Add grantor_profile_id (idempotent) ────────────────────────────
        if (!self::columnExists($db, $driver, 'permission_delegations', 'grantor_profile_id')) {
            $db->exec('
                ALTER TABLE permission_delegations
                ADD COLUMN grantor_profile_id INTEGER NULL
                    REFERENCES profiles(id) ON DELETE CASCADE
            ');
        }

        // ── 2. Widen the grantee_type CHECK to permit 'profile' FIRST ─────────
        // This must precede the grantee re-key (step 3) which writes
        // grantee_type='profile'; otherwise the still-narrow CHECK rejects it.
        // On SQLite this rebuild keeps every column (grantor_user_id is dropped
        // later in step 5); on PG it swaps the constraint in place.
        if ($driver === 'pgsql') {
            self::setGranteeCheckPg($db, ['role', 'user', 'profile']);
        } else {
            self::ensureGranteeCheckSqlite($db, ['role', 'user', 'profile']);
        }

        // ── 3. Backfill grantor + re-key grantee (type AND id) in lock-step ───
        // migration_035_profile_ids maps users.id → profiles.id. When it does not
        // exist (fresh database, no legacy users, no legacy delegation rows) skip
        // the backfill gracefully.
        $hasMapping = self::tableExists($db, $driver, 'migration_035_profile_ids');
        // Only re-key from grantor_user_id when that column still exists (i.e. this
        // is a genuine forward migration of a legacy schema, not a re-run).
        $hasLegacyGrantor = self::columnExists($db, $driver, 'permission_delegations', 'grantor_user_id');

        if ($hasMapping && $hasLegacyGrantor) {
            // Grantor: user_id → profile_id.
            // @tenant-guard-ignore: cross-table migration backfill; updates own table rows using external mapping
            $db->exec('
                UPDATE permission_delegations
                SET grantor_profile_id = (
                    SELECT m.profile_id
                    FROM migration_035_profile_ids m
                    WHERE m.user_id = permission_delegations.grantor_user_id
                    LIMIT 1
                )
                WHERE grantor_profile_id IS NULL
            ');

            // Grantee re-key: for every 'user' grantee row that HAS a mapping,
            // set grantee_id to the profile_id AND flip grantee_type to 'profile'.
            // Doing both together guarantees the resolver (which matches
            // grantee_type='profile') keeps finding the row.
            // @tenant-guard-ignore: cross-table migration backfill; grantee re-keyed user→profile in lock-step
            $db->exec("
                UPDATE permission_delegations
                SET grantee_id = (
                        SELECT m.profile_id
                        FROM migration_035_profile_ids m
                        WHERE m.user_id = permission_delegations.grantee_id
                        LIMIT 1
                    ),
                    grantee_type = 'profile'
                WHERE grantee_type = 'user'
                  AND EXISTS (
                    SELECT 1
                    FROM migration_035_profile_ids m
                    WHERE m.user_id = permission_delegations.grantee_id
                  )
            ");
        }

        // ── 4. Orphan handling ────────────────────────────────────────────────
        // Any row still carrying grantor_profile_id IS NULL after the backfill has
        // a grantor with no profile mapping. Since 035 migrated every non-system
        // user, such a row is a dangling/unenforceable grantor: DELETE it (log the
        // count) so the NOT NULL constraint below is honest.
        //
        // Likewise, any grantee_type='user' row that survived the re-key (its
        // grantee_id had no mapping) is an unresolvable grantee — delete it too so
        // no 'user' rows remain (the resolver could never match them).
        if ($hasLegacyGrantor || self::columnExists($db, $driver, 'permission_delegations', 'grantor_profile_id')) {
            $orphanGrantors = self::countWhere($db, 'grantor_profile_id IS NULL');
            if ($orphanGrantors > 0) {
                // @tenant-guard-ignore: migration cleanup of dangling grantor rows across all tenants
                $db->exec('DELETE FROM permission_delegations WHERE grantor_profile_id IS NULL');
                self::log("migration 037: deleted {$orphanGrantors} delegation row(s) with an unmappable grantor_user_id (no profile mapping).");
            }
        }

        $orphanGrantees = self::countWhere($db, "grantee_type = 'user'");
        if ($orphanGrantees > 0) {
            // @tenant-guard-ignore: migration cleanup of unresolvable user-grantee rows across all tenants
            $db->exec("DELETE FROM permission_delegations WHERE grantee_type = 'user'");
            self::log("migration 037: deleted {$orphanGrantees} delegation row(s) with an unmappable user grantee (no profile mapping).");
        }

        // ── 5. Drop grantor_user_id (recreates all migration 014 indexes on SQLite) ─
        if (self::columnExists($db, $driver, 'permission_delegations', 'grantor_user_id')) {
            if ($driver === 'pgsql') {
                $db->exec('ALTER TABLE permission_delegations DROP COLUMN IF EXISTS grantor_user_id');
            } else {
                // SQLite: rename-recreate. This rebuilds the table WITHOUT
                // grantor_user_id, WITH the widened CHECK, and re-creates every
                // migration-014 index (idx_pd_resolution, idx_pd_ou) plus the new
                // idx_pd_grantor_profile.
                self::rebuildDelegationsSqlite($db, dropColumn: 'grantor_user_id', granteeTypes: ['role', 'user', 'profile']);
            }
        }

        // ── 6. Enforce grantor_profile_id NOT NULL (matches the contract) ─────
        if ($driver === 'pgsql') {
            $db->exec('ALTER TABLE permission_delegations ALTER COLUMN grantor_profile_id SET NOT NULL');
        }
        // SQLite: the recreated DDL keeps grantor_profile_id nullable at the
        // column level (SQLite cannot ALTER a column to NOT NULL in place, and the
        // recreate copies the migration-014 column list). We enforce non-null at
        // the application layer (DelegationRepository::insert always supplies it)
        // and by the orphan-deletion above; a partial-index/trigger is avoided to
        // keep the schema portable. The PG path — the production engine — carries
        // the real NOT NULL guarantee.

        // ── 7. Index for listing by grantor_profile_id ────────────────────────
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_pd_grantor_profile
            ON permission_delegations (tenant_id, grantor_profile_id)
        ');

        // Drop the old grantor-user index if it still exists (idempotent).
        self::dropIndexSafe($db, 'idx_pd_grantor');
    }

    public static function down(Database $db): void
    {
        $pdo    = $db->getPdo();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $pdo->beginTransaction();
        try {
            self::runDown($db, $driver);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    private static function runDown(Database $db, string $driver): void
    {
        // ── 1. Re-add grantor_user_id (idempotent) ────────────────────────────
        if (!self::columnExists($db, $driver, 'permission_delegations', 'grantor_user_id')) {
            $db->exec('
                ALTER TABLE permission_delegations
                ADD COLUMN grantor_user_id INTEGER NULL
                    REFERENCES users(id) ON DELETE CASCADE
            ');
        }

        // ── 2 + 3. Reverse-backfill grantor and convert grantee 'profile'→'user'.
        // The grantee conversion MUST happen before the CHECK is narrowed (step 5)
        // so no 'profile' row survives to violate the narrowed constraint on PG.
        if (self::tableExists($db, $driver, 'migration_035_profile_ids')) {
            // 2. Grantor: profile_id → kept user_id.
            // @tenant-guard-ignore: reverse migration backfill; profile_id → kept user_id
            $db->exec('
                UPDATE permission_delegations
                SET grantor_user_id = (
                    SELECT m.user_id
                    FROM migration_035_profile_ids m
                    WHERE m.profile_id = permission_delegations.grantor_profile_id
                    ORDER BY m.user_id ASC
                    LIMIT 1
                )
                WHERE grantor_user_id IS NULL
            ');

            // 3. Grantee: profile → user (id + type) in lock-step. Only rows that
            // have a reverse mapping are converted; any without a mapping stay
            // 'profile' and are cleaned up below so the narrowed CHECK holds.
            // @tenant-guard-ignore: reverse migration backfill; grantee profile→user in lock-step
            $db->exec("
                UPDATE permission_delegations
                SET grantee_id = (
                        SELECT m.user_id
                        FROM migration_035_profile_ids m
                        WHERE m.profile_id = permission_delegations.grantee_id
                        ORDER BY m.user_id ASC
                        LIMIT 1
                    ),
                    grantee_type = 'user'
                WHERE grantee_type = 'profile'
                  AND EXISTS (
                    SELECT 1
                    FROM migration_035_profile_ids m
                    WHERE m.profile_id = permission_delegations.grantee_id
                  )
            ");
        }

        // Any 'profile' grantee row left without a reverse mapping cannot be
        // represented under the narrowed CHECK — delete it (unrecoverable).
        $unconvertible = self::countWhere($db, "grantee_type = 'profile'");
        if ($unconvertible > 0) {
            // @tenant-guard-ignore: reverse migration cleanup of unconvertible profile-grantee rows
            $db->exec("DELETE FROM permission_delegations WHERE grantee_type = 'profile'");
            self::log("migration 037 down(): deleted {$unconvertible} profile-grantee delegation row(s) with no reverse mapping (unrecoverable).");
        }

        // ── 4. Re-add old grantor index ───────────────────────────────────────
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_pd_grantor
            ON permission_delegations (tenant_id, grantor_user_id)
        ');

        // ── 5. Narrow the grantee_type CHECK back to ('role', 'user') ────────
        if ($driver === 'pgsql') {
            self::setGranteeCheckPg($db, ['role', 'user']);
        } else {
            // SQLite: rebuild WITHOUT grantor_profile_id, WITH the narrow CHECK,
            // re-creating every migration-014 index + the restored idx_pd_grantor.
            self::rebuildDelegationsSqlite($db, dropColumn: 'grantor_profile_id', granteeTypes: ['role', 'user']);
        }

        // ── 6. Drop grantor_profile_id + its index ────────────────────────────
        if ($driver === 'pgsql') {
            $db->exec('DROP INDEX IF EXISTS idx_pd_grantor_profile');
            $db->exec('ALTER TABLE permission_delegations DROP COLUMN IF EXISTS grantor_profile_id');
        } else {
            // The rebuild above already dropped grantor_profile_id; just drop its index.
            self::dropIndexSafe($db, 'idx_pd_grantor_profile');
        }
    }

    // ── Introspection helpers ───────────────────────────────────────────────

    private static function tableExists(Database $db, string $driver, string $table): bool
    {
        $pdo = $db->getPdo();
        if ($driver === 'pgsql') {
            $stmt = $pdo->query("SELECT to_regclass('{$table}') AS name");
            $row  = ($stmt !== false) ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
            if ($stmt !== false) {
                $stmt->closeCursor();
            }
            return $row !== false && $row['name'] !== null;
        }
        // SQLite
        $stmt = $pdo->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute([$table]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row !== false;
    }

    private static function columnExists(Database $db, string $driver, string $table, string $column): bool
    {
        $pdo = $db->getPdo();
        if ($driver === 'pgsql') {
            $stmt = $pdo->prepare(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_name = ? AND column_name = ?"
            );
            $stmt->execute([$table, $column]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return $row !== false;
        }
        // SQLite: PRAGMA table_info
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        if ($stmt === false) {
            return false;
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            if ((string) $row['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    /**
     * Count permission_delegations rows matching a WHERE fragment (no user input;
     * the fragment is a constant literal supplied by this migration only).
     */
    private static function countWhere(Database $db, string $whereFragment): int
    {
        $pdo  = $db->getPdo();
        // @tenant-guard-ignore: migration-internal count over all tenants; fragment is a constant literal
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM permission_delegations WHERE {$whereFragment}");
        if ($stmt === false) {
            return 0;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row !== false ? (int) $row['c'] : 0;
    }

    private static function dropIndexSafe(Database $db, string $index): void
    {
        try {
            $db->exec("DROP INDEX IF EXISTS {$index}");
        } catch (\Throwable $e) {
            // Index may not exist on this engine; ignore.
        }
    }

    /**
     * Emit an operator-facing migration notice. Uses STDOUT like other migrations
     * (e.g. 010/035); the test harness silences stdout via ob_start().
     */
    private static function log(string $message): void
    {
        // Match the convention of migrations 010/035: write to STDOUT via echo so
        // the test harness's ob_start() capture silences it during test runs.
        echo '[migration 037] ' . $message . "\n";
    }

    // ── PostgreSQL CHECK management ─────────────────────────────────────────

    /**
     * Replace the grantee_type CHECK constraint on PostgreSQL with one permitting
     * exactly the given discriminator values.
     *
     * @param list<string> $types
     */
    private static function setGranteeCheckPg(Database $db, array $types): void
    {
        $list = implode(', ', array_map(static fn (string $t): string => "'" . $t . "'", $types));
        $db->exec('
            ALTER TABLE permission_delegations
            DROP CONSTRAINT IF EXISTS chk_permission_delegations_grantee_type
        ');
        $db->exec("
            ALTER TABLE permission_delegations
            ADD CONSTRAINT chk_permission_delegations_grantee_type
            CHECK (grantee_type IN ({$list}))
        ");
    }

    // ── SQLite table rebuild (rename-recreate) ──────────────────────────────

    /**
     * Rebuild permission_delegations on SQLite via the rename-recreate idiom:
     * drops $dropColumn, sets the grantee_type CHECK to permit exactly
     * $granteeTypes, copies the data, and re-creates ALL indexes the table must
     * carry (migration 014's idx_pd_resolution + idx_pd_ou, plus whichever
     * grantor index is appropriate for the resulting column set).
     *
     * @param list<string> $granteeTypes
     */
    private static function rebuildDelegationsSqlite(Database $db, string $dropColumn, array $granteeTypes): void
    {
        $pdo = $db->getPdo();

        // Column list to keep (everything except $dropColumn).
        $info = $pdo->query('PRAGMA table_info(permission_delegations)');
        if ($info === false) {
            return;
        }
        $cols = $info->fetchAll(PDO::FETCH_ASSOC);
        $keep = array_values(array_filter(
            $cols,
            static fn (array $c): bool => (string) $c['name'] !== $dropColumn
        ));
        $keepNames = array_map(static fn (array $c): string => '"' . $c['name'] . '"', $keep);
        $colList   = implode(', ', $keepNames);

        // Deterministically reconstruct the table definition from the known-good
        // column set. Building it ourselves (rather than string-editing the stored
        // DDL) makes the CHECK-widening immune to quote-escaping variations.
        $newDdl = self::buildDelegationsCreateSql($keep, $granteeTypes);

        $pdo->exec('ALTER TABLE "permission_delegations" RENAME TO "permission_delegations_old_037"');
        $pdo->exec($newDdl);
        $pdo->exec("INSERT INTO \"permission_delegations\" ({$colList}) SELECT {$colList} FROM \"permission_delegations_old_037\"");
        $pdo->exec('DROP TABLE "permission_delegations_old_037"');

        // Re-create the indexes that migration 014 defined and that the recreate
        // just dropped. idx_pd_resolution and idx_pd_ou are the delegation
        // hot-path indexes; losing them silently degrades every permission check.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pd_resolution ON permission_delegations (tenant_id, grantee_type, grantee_id, revoked_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pd_ou ON permission_delegations (ou_id)');

        // Grantor index appropriate to the resulting schema.
        $keepColNames = array_map(static fn (array $c): string => (string) $c['name'], $keep);
        if (in_array('grantor_profile_id', $keepColNames, true)) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pd_grantor_profile ON permission_delegations (tenant_id, grantor_profile_id)');
        }
        if (in_array('grantor_user_id', $keepColNames, true)) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pd_grantor ON permission_delegations (tenant_id, grantor_user_id)');
        }
    }

    /**
     * Ensure the grantee_type CHECK on SQLite permits exactly $granteeTypes. If it
     * already does (idempotent re-run), this is a no-op; otherwise the table is
     * rebuilt in place (no column dropped).
     *
     * @param list<string> $granteeTypes
     */
    private static function ensureGranteeCheckSqlite(Database $db, array $granteeTypes): void
    {
        $pdo = $db->getPdo();
        $stmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
        $stmt->execute(['permission_delegations']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        if ($row === false) {
            return;
        }
        $ddl = (string) $row['sql'];

        // Already permits 'profile'? (cheap check for the common widen case)
        $wantsProfile = in_array('profile', $granteeTypes, true);
        $hasProfile   = stripos($ddl, "'profile'") !== false;
        if ($wantsProfile === $hasProfile) {
            return; // nothing to change
        }

        // Rebuild in place, keeping every column.
        $info = $pdo->query('PRAGMA table_info(permission_delegations)');
        if ($info === false) {
            return;
        }
        $cols = $info->fetchAll(PDO::FETCH_ASSOC);
        $keepNames = array_map(static fn (array $c): string => '"' . $c['name'] . '"', $cols);
        $colList   = implode(', ', $keepNames);

        $newDdl = self::buildDelegationsCreateSql($cols, $granteeTypes);

        $pdo->exec('ALTER TABLE "permission_delegations" RENAME TO "permission_delegations_old_037c"');
        $pdo->exec($newDdl);
        $pdo->exec("INSERT INTO \"permission_delegations\" ({$colList}) SELECT {$colList} FROM \"permission_delegations_old_037c\"");
        $pdo->exec('DROP TABLE "permission_delegations_old_037c"');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pd_resolution ON permission_delegations (tenant_id, grantee_type, grantee_id, revoked_at)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pd_ou ON permission_delegations (ou_id)');
        $keepColNames = array_map(static fn (array $c): string => (string) $c['name'], $cols);
        if (in_array('grantor_profile_id', $keepColNames, true)) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pd_grantor_profile ON permission_delegations (tenant_id, grantor_profile_id)');
        }
        if (in_array('grantor_user_id', $keepColNames, true)) {
            $pdo->exec('CREATE INDEX IF NOT EXISTS idx_pd_grantor ON permission_delegations (tenant_id, grantor_user_id)');
        }
    }

    /**
     * Build a deterministic SQLite table-definition statement for
     * permission_delegations from the kept column set and the desired grantee_type
     * discriminator list. Column type/affinity is taken from PRAGMA table_info so
     * we preserve the engine-observed types without parsing the stored DDL.
     *
     * @param array<int, array<string, mixed>> $keepCols PRAGMA table_info rows to keep.
     * @param list<string>                      $granteeTypes
     */
    private static function buildDelegationsCreateSql(array $keepCols, array $granteeTypes): string
    {
        $lines = [];
        foreach ($keepCols as $c) {
            // PRAGMA columns come back as STRINGS under ATTR_STRINGIFY_FETCHES
            // (Postgres-parity mode), so cast the flags explicitly.
            $name    = (string) $c['name'];
            $type    = (string) ($c['type'] ?? '');
            $isPk    = (int) ($c['pk'] ?? 0) > 0;
            $notNull = (int) ($c['notnull'] ?? 0) > 0;
            $default = $c['dflt_value'] ?? null;

            $line = '"' . $name . '" ' . ($type !== '' ? $type : 'INTEGER');
            if ($isPk) {
                // SQLite AUTOINCREMENT primary key for the id column.
                $line .= ' PRIMARY KEY AUTOINCREMENT';
            } elseif ($notNull) {
                $line .= ' NOT NULL';
            }
            if ($default !== null && !$isPk) {
                // Wrap the default in parentheses unless it is already a
                // parenthesised expression. SQLite requires function defaults such
                // as datetime('now') to be parenthesised — DEFAULT datetime('now')
                // is a syntax error — and it also accepts parenthesised literals
                // (DEFAULT (0), DEFAULT ('x')), so wrapping uniformly is always
                // valid and immune to how PRAGMA reports the stored default.
                $defaultSql = (string) $default;
                if ($defaultSql !== '' && $defaultSql[0] !== '(') {
                    $defaultSql = '(' . $defaultSql . ')';
                }
                $line .= ' DEFAULT ' . $defaultSql;
            }
            $lines[] = $line;
        }

        $checkList = implode(', ', array_map(
            static fn (string $t): string => "'" . $t . "'",
            $granteeTypes
        ));
        $lines[] = 'CONSTRAINT chk_permission_delegations_grantee_type '
            . "CHECK (grantee_type IN ({$checkList}))";

        return 'CREATE TABLE "permission_delegations" (' . "\n    "
            . implode(",\n    ", $lines) . "\n)";
    }
}
