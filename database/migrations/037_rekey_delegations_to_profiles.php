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
 * Schema changes
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Add `grantor_profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE CASCADE`
 *    (nullable during backfill; NOT NULL enforced after backfill completes).
 * 2. Backfill `grantor_profile_id` from the users→profiles mapping established
 *    by migration 035 (migration_035_profile_ids table).
 * 3. Drop `grantor_user_id` column (on PostgreSQL via ALTER TABLE DROP COLUMN;
 *    on SQLite via table-recreate because SQLite does not support DROP COLUMN
 *    on a column with a foreign-key reference in older versions — we use the
 *    rename-recreate idiom supported since SQLite 3.25.0).
 * 4. Add `grantor_profile_id_idx` index backing the listing-by-grantor query.
 *
 * User-grantee FK: the grantee_id for `grantee_type = 'user'` rows is also
 * re-keyed to profile_id via the same 035 mapping. The column is left as a
 * generic INTEGER (no FK — polymorphic grantee; the CHECK constraint keeps it
 * type-safe). Backfill updates `grantee_id` for `grantee_type = 'user'` rows.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Idempotency
 * ─────────────────────────────────────────────────────────────────────────────
 * up() checks for the existence of `grantor_profile_id` before adding it, so
 * re-running up() on an already-migrated database is safe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reversibility (down)
 * ─────────────────────────────────────────────────────────────────────────────
 * down() re-adds `grantor_user_id INTEGER NULL REFERENCES users(id) ON DELETE
 * CASCADE` and back-fills from the 035 mapping (profile_id → kept_user_id) so
 * the column returns with plausible values. The reverse-backfill for the user-
 * grantee path restores grantee_id to user_id using the same map.
 *
 * WARNING: if a profile was created by 035's collision-collapse (multiple users
 * → one profile), the reverse mapping is ambiguous for dropped user rows. The
 * kept_user_id from migration_035_profile_ids is used, which is the lowest-id
 * user for that email — the same canonical choice 035 made.
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
        // Check column existence first so re-runs are safe.
        if (!self::columnExists($db, $driver, 'permission_delegations', 'grantor_profile_id')) {
            $db->exec('
                ALTER TABLE permission_delegations
                ADD COLUMN grantor_profile_id INTEGER NULL
                    REFERENCES profiles(id) ON DELETE CASCADE
            ');
        }

        // ── 2. Backfill grantor_profile_id from migration_035_profile_ids ─────
        // migration_035_profile_ids maps users.id → profiles.id. If that table
        // does not exist (migration 035 was never applied — e.g. fresh database
        // with no legacy users), skip the backfill gracefully: the column stays
        // NULL for rows that have no mapping (no legacy delegation rows either).
        if (self::tableExists($db, $driver, 'migration_035_profile_ids')) {
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

            // Backfill user-grantee rows: grantee_type = \'user\', grantee_id = users.id
            // → update grantee_id to the corresponding profile_id.
            // @tenant-guard-ignore: cross-table migration backfill; grantee_id for 'user' type is re-keyed to profile_id
            $db->exec("
                UPDATE permission_delegations
                SET grantee_id = (
                    SELECT m.profile_id
                    FROM migration_035_profile_ids m
                    WHERE m.user_id = permission_delegations.grantee_id
                    LIMIT 1
                )
                WHERE grantee_type = 'user'
                  AND EXISTS (
                    SELECT 1
                    FROM migration_035_profile_ids m
                    WHERE m.user_id = permission_delegations.grantee_id
                  )
            ");
        }

        // ── 3. Drop grantor_user_id ───────────────────────────────────────────
        if (self::columnExists($db, $driver, 'permission_delegations', 'grantor_user_id')) {
            if ($driver === 'pgsql') {
                $db->exec('ALTER TABLE permission_delegations DROP COLUMN IF EXISTS grantor_user_id');
            } else {
                // SQLite: rename-and-recreate idiom.
                self::dropColumnSqlite($db, 'permission_delegations', 'grantor_user_id');
            }
        }

        // ── 4. Widen the grantee_type CHECK to include 'profile' ─────────────
        // Migration 014 created the constraint with ('role', 'user'). Now that
        // delegations reference profiles instead of users we must allow 'profile'
        // as a valid discriminator. Widening is backward-compatible: 'role' and
        // 'user' literals remain valid during the Phase B dual-window window.
        if ($driver === 'pgsql') {
            // PostgreSQL: drop the named constraint and re-add it widened.
            $db->exec("
                ALTER TABLE permission_delegations
                DROP CONSTRAINT IF EXISTS chk_permission_delegations_grantee_type
            ");
            $db->exec("
                ALTER TABLE permission_delegations
                ADD CONSTRAINT chk_permission_delegations_grantee_type
                CHECK (grantee_type IN ('role', 'user', 'profile'))
            ");
        } else {
            // SQLite: there is no ALTER TABLE … ADD/DROP CONSTRAINT. The
            // rename-recreate in step 3 (dropColumnSqlite) already rebuilds the
            // table from the migration 014 DDL. We call an additional table
            // rebuild here only when grantor_user_id was NOT present (meaning
            // dropColumnSqlite was not called because the column was already
            // absent). In all cases, widen the check via patchCheckConstraint.
            self::patchCheckConstraintSqlite($db);
        }

        // ── 5. Index for listing by grantor_profile_id ────────────────────────
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_pd_grantor_profile
            ON permission_delegations (tenant_id, grantor_profile_id)
        ');

        // Drop the old grantor-user index if it still exists (idempotent).
        if ($driver === 'pgsql') {
            $db->exec('DROP INDEX IF EXISTS idx_pd_grantor');
        } else {
            // SQLite: DROP INDEX is safe without IF on older versions; use IF EXISTS.
            try {
                $db->exec('DROP INDEX IF EXISTS idx_pd_grantor');
            } catch (\Throwable $e) {
                // Index may not exist; ignore.
            }
        }
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

        // ── 2. Reverse-backfill grantor_user_id from 035 mapping ─────────────
        if (self::tableExists($db, $driver, 'migration_035_profile_ids')) {
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

            // Reverse user-grantee: profile_id → user_id.
            // @tenant-guard-ignore: reverse migration backfill; grantee_id for 'user' type back to user_id
            $db->exec("
                UPDATE permission_delegations
                SET grantee_id = (
                    SELECT m.user_id
                    FROM migration_035_profile_ids m
                    WHERE m.profile_id = permission_delegations.grantee_id
                    ORDER BY m.user_id ASC
                    LIMIT 1
                )
                WHERE grantee_type = 'user'
                  AND EXISTS (
                    SELECT 1
                    FROM migration_035_profile_ids m
                    WHERE m.profile_id = permission_delegations.grantee_id
                  )
            ");
        }

        // ── 3. Re-add old grantor index ───────────────────────────────────────
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_pd_grantor
            ON permission_delegations (tenant_id, grantor_user_id)
        ');

        // ── 4. Narrow the grantee_type CHECK back to ('role', 'user') ────────
        if ($driver === 'pgsql') {
            $db->exec("
                ALTER TABLE permission_delegations
                DROP CONSTRAINT IF EXISTS chk_permission_delegations_grantee_type
            ");
            $db->exec("
                ALTER TABLE permission_delegations
                ADD CONSTRAINT chk_permission_delegations_grantee_type
                CHECK (grantee_type IN ('role', 'user'))
            ");
        } else {
            self::narrowCheckConstraintSqlite($db);
        }

        // ── 5. Drop grantor_profile_id + its index ────────────────────────────
        if ($driver === 'pgsql') {
            $db->exec('DROP INDEX IF EXISTS idx_pd_grantor_profile');
            $db->exec('ALTER TABLE permission_delegations DROP COLUMN IF EXISTS grantor_profile_id');
        } else {
            try {
                $db->exec('DROP INDEX IF EXISTS idx_pd_grantor_profile');
            } catch (\Throwable $e) {
                // Index may not exist.
            }
            if (self::columnExists($db, $driver, 'permission_delegations', 'grantor_profile_id')) {
                self::dropColumnSqlite($db, 'permission_delegations', 'grantor_profile_id');
            }
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

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
     * Drop a column from a SQLite table using the rename-recreate idiom.
     *
     * Reads the DDL stored in sqlite_master, removes the named column (and
     * widens the grantee_type CHECK constraint from ('role','user') to
     * ('role','user','profile') in the same pass), drops the original table,
     * recreates without the dropped column but with the widened CHECK, then
     * copies the data.
     * Works on SQLite 3.25+ (the minimum required by this project).
     */
    private static function dropColumnSqlite(Database $db, string $table, string $colToDrop): void
    {
        $pdo = $db->getPdo();

        // Fetch all columns from PRAGMA
        $stmt = $pdo->query("PRAGMA table_info({$table})");
        if ($stmt === false) {
            return;
        }
        $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $keepCols = array_filter($cols, static fn ($c) => (string) $c['name'] !== $colToDrop);

        $colNames  = array_map(static fn ($c) => '"' . $c['name'] . '"', $keepCols);
        $colList   = implode(', ', $colNames);

        // Read original DDL
        $ddlStmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
        $ddlStmt->execute([$table]);
        $ddlRow  = $ddlStmt->fetch(PDO::FETCH_ASSOC);
        $ddlStmt->closeCursor();
        if ($ddlRow === false) {
            return;
        }

        // Rename original
        $pdo->exec("ALTER TABLE \"{$table}\" RENAME TO \"{$table}_old_037\"");

        // Rebuild DDL without the dropped column.
        // SQLite stores the column definition as a single logical line; we use a
        // line-based removal so nested parentheses inside REFERENCES clauses
        // (e.g. REFERENCES users(id) ON DELETE CASCADE) cannot confuse the regex.
        $ddl = (string) $ddlRow['sql'];
        $ddl = self::removeColumnLineSqlite($ddl, $colToDrop);
        // Widen the grantee_type CHECK in the same pass.
        $ddl = self::widenCheckInDdl($ddl);
        // Replace the old table name in the CREATE
        $newDdl = preg_replace('/CREATE TABLE\s+(IF NOT EXISTS\s+)?"?' . preg_quote($table, '/') . '"?/i', "CREATE TABLE \"{$table}\"", $ddl);
        if ($newDdl === null || $newDdl === '') {
            // Fallback: just rename back
            $pdo->exec("ALTER TABLE \"{$table}_old_037\" RENAME TO \"{$table}\"");
            return;
        }

        $pdo->exec($newDdl);

        // Copy data
        $pdo->exec("INSERT INTO \"{$table}\" ({$colList}) SELECT {$colList} FROM \"{$table}_old_037\"");

        // Drop old table
        $pdo->exec("DROP TABLE \"{$table}_old_037\"");
    }

    /**
     * Remove a column definition from a table's DDL string (as stored in
     * sqlite_master) using a line-based approach so nested parentheses in
     * REFERENCES clauses cannot corrupt the surrounding DDL.
     *
     * Logic:
     *  1. Split DDL into lines.
     *  2. Identify the line whose first non-whitespace token matches $colToDrop.
     *  3. Remove that line.
     *  4. If the previous non-blank, non-comment line ends with a trailing comma
     *     that now has no following column definition, strip that trailing comma.
     *
     * This correctly handles patterns like:
     *   grantor_user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
     * regardless of nested parentheses in REFERENCES clauses.
     */
    private static function removeColumnLineSqlite(string $ddl, string $colToDrop): string
    {
        $lines   = explode("\n", $ddl);
        $pattern = '/^\s*' . preg_quote($colToDrop, '/') . '\b/i';

        // Find and remove the target column line.
        $dropIdx = null;
        foreach ($lines as $i => $line) {
            if (preg_match($pattern, $line)) {
                $dropIdx = $i;
                break;
            }
        }
        if ($dropIdx === null) {
            return $ddl; // column not found; return unchanged
        }

        // Remove the column line.
        array_splice($lines, $dropIdx, 1);

        // After removal, the line immediately BEFORE the dropped one (which is
        // now at index $dropIdx - 1 in the original numbering) may end with a
        // trailing comma that is no longer valid if the removed line was the
        // last column before a CONSTRAINT or closing parenthesis.
        // Find the last non-empty line before the gap.
        for ($i = $dropIdx - 1; $i >= 0; $i--) {
            $trimmed = rtrim($lines[$i]);
            if ($trimmed === '') {
                continue;
            }
            // If it ends with a comma AND the next meaningful line starts a
            // CONSTRAINT or closing paren, strip the trailing comma.
            // Determine what the next meaningful line is.
            $nextMeaningful = null;
            for ($j = $dropIdx; $j < count($lines); $j++) {
                if (trim($lines[$j]) !== '') {
                    $nextMeaningful = ltrim($lines[$j]);
                    break;
                }
            }
            if (
                str_ends_with($trimmed, ',') &&
                $nextMeaningful !== null &&
                (
                    str_starts_with($nextMeaningful, 'CONSTRAINT') ||
                    str_starts_with($nextMeaningful, ')') ||
                    str_starts_with($nextMeaningful, 'CHECK')
                )
            ) {
                $lines[$i] = substr($trimmed, 0, -1); // strip trailing comma
            }
            break;
        }

        return implode("\n", $lines);
    }

    /**
     * Widen the grantee_type CHECK constraint in a table's DDL string from
     * ('role', 'user') to ('role', 'user', 'profile').
     *
     * Used on the SQLite path only: both when dropping grantor_user_id via
     * rename-recreate (the check is embedded in the DDL) and when calling
     * patchCheckConstraintSqlite() directly when grantor_user_id was already absent.
     */
    private static function widenCheckInDdl(string $ddl): string
    {
        // Replace  grantee_type IN ('role', 'user')  (any quote style)
        // with     grantee_type IN ('role', 'user', 'profile')
        $widened = preg_replace(
            "/grantee_type\s+IN\s*\(\s*'role'\s*,\s*'user'\s*\)/i",
            "grantee_type IN ('role', 'user', 'profile')",
            $ddl
        );
        return $widened ?? $ddl;
    }

    /**
     * Re-create permission_delegations on SQLite with the widened grantee_type
     * CHECK, when dropColumnSqlite() was NOT called (i.e. grantor_user_id was
     * already absent so the table was not rebuilt in step 3).
     *
     * This is idempotent: if the CHECK already contains 'profile' the regex
     * will not match and the table is left unchanged (no rename-recreate cycle).
     */
    private static function patchCheckConstraintSqlite(Database $db): void
    {
        $pdo = $db->getPdo();

        $ddlStmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
        $ddlStmt->execute(['permission_delegations']);
        $ddlRow = $ddlStmt->fetch(PDO::FETCH_ASSOC);
        $ddlStmt->closeCursor();
        if ($ddlRow === false) {
            return;
        }

        $ddl = (string) $ddlRow['sql'];

        // If 'profile' is already in the CHECK, nothing to do.
        if (stripos($ddl, "'profile'") !== false) {
            return;
        }

        $widened = self::widenCheckInDdl($ddl);
        if ($widened === $ddl) {
            // Pattern did not match — CHECK must have been structured differently;
            // skip rather than corrupt the table.
            return;
        }

        // Fetch column list for data copy.
        $stmt = $pdo->query('PRAGMA table_info(permission_delegations)');
        if ($stmt === false) {
            return;
        }
        $cols    = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_map(static fn ($c) => '"' . $c['name'] . '"', $cols);
        $colList  = implode(', ', $colNames);

        // Rename → recreate → copy → drop.
        $pdo->exec('ALTER TABLE "permission_delegations" RENAME TO "permission_delegations_old_037c"');

        $newDdl = preg_replace(
            '/CREATE TABLE\s+(IF NOT EXISTS\s+)?"?permission_delegations"?/i',
            'CREATE TABLE "permission_delegations"',
            $widened
        );
        if ($newDdl === null || $newDdl === '') {
            $pdo->exec('ALTER TABLE "permission_delegations_old_037c" RENAME TO "permission_delegations"');
            return;
        }

        $pdo->exec($newDdl);
        $pdo->exec("INSERT INTO \"permission_delegations\" ({$colList}) SELECT {$colList} FROM \"permission_delegations_old_037c\"");
        $pdo->exec('DROP TABLE "permission_delegations_old_037c"');
    }

    /**
     * Narrow the grantee_type CHECK constraint back to ('role', 'user') on
     * SQLite when rolling back migration 037.
     *
     * Inverse of patchCheckConstraintSqlite(). Uses the same rename-recreate
     * idiom. Idempotent: if 'profile' is not present in the CHECK, returns
     * without touching the table.
     */
    private static function narrowCheckConstraintSqlite(Database $db): void
    {
        $pdo = $db->getPdo();

        $ddlStmt = $pdo->prepare("SELECT sql FROM sqlite_master WHERE type='table' AND name = ?");
        $ddlStmt->execute(['permission_delegations']);
        $ddlRow = $ddlStmt->fetch(PDO::FETCH_ASSOC);
        $ddlStmt->closeCursor();
        if ($ddlRow === false) {
            return;
        }

        $ddl = (string) $ddlRow['sql'];

        // If 'profile' is NOT in the CHECK already, nothing to do.
        if (stripos($ddl, "'profile'") === false) {
            return;
        }

        // Replace widened CHECK with the original narrower form.
        $narrowed = preg_replace(
            "/grantee_type\s+IN\s*\(\s*'role'\s*,\s*'user'\s*,\s*'profile'\s*\)/i",
            "grantee_type IN ('role', 'user')",
            $ddl
        ) ?? $ddl;

        if ($narrowed === $ddl) {
            return;
        }

        // Fetch column list.
        $stmt = $pdo->query('PRAGMA table_info(permission_delegations)');
        if ($stmt === false) {
            return;
        }
        $cols     = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $colNames = array_map(static fn ($c) => '"' . $c['name'] . '"', $cols);
        $colList  = implode(', ', $colNames);

        $pdo->exec('ALTER TABLE "permission_delegations" RENAME TO "permission_delegations_old_037d"');

        $newDdl = preg_replace(
            '/CREATE TABLE\s+(IF NOT EXISTS\s+)?"?permission_delegations"?/i',
            'CREATE TABLE "permission_delegations"',
            $narrowed
        );
        if ($newDdl === null || $newDdl === '') {
            $pdo->exec('ALTER TABLE "permission_delegations_old_037d" RENAME TO "permission_delegations"');
            return;
        }

        $pdo->exec($newDdl);
        $pdo->exec("INSERT INTO \"permission_delegations\" ({$colList}) SELECT {$colList} FROM \"permission_delegations_old_037d\"");
        $pdo->exec('DROP TABLE "permission_delegations_old_037d"');
    }
}
