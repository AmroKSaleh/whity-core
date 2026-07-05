<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * RekeyBackupCodesToProfiles — forward migration (WC-idcut-A, migration 038).
 *
 * Re-points `backup_codes.user_id` from `users.id` to `profiles.id`
 * (ADR 0005 §9 — backup codes reference profiles, not users).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Schema changes (up)
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Add `profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE CASCADE`.
 * 2. Backfill `profile_id` from the users→profiles mapping established by
 *    migration 035 (migration_035_profile_ids table).
 * 3. Delete any rows whose user_id had no mapping in the 035 table (orphans).
 * 4. Set `profile_id NOT NULL`.
 * 5. Drop the three `user_id` indexes: idx_backup_codes_user_id,
 *    idx_backup_codes_user_id_version, idx_backup_codes_user_id_used.
 * 6. Drop the `user_id` column (PostgreSQL: ALTER TABLE DROP COLUMN;
 *    SQLite: table-rebuild, matching the pattern of migration 037).
 * 7. Create idx_backup_codes_profile_id, idx_backup_codes_profile_id_version,
 *    idx_backup_codes_profile_id_used.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Idempotency
 * ─────────────────────────────────────────────────────────────────────────────
 * up() checks column/index state before mutating, so a re-run on an already-
 * migrated schema is safe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reversibility (down)
 * ─────────────────────────────────────────────────────────────────────────────
 * down() reverses the schema: re-adds user_id, reverse-backfills from the 035
 * mapping (profile_id → kept user_id), drops profile_id, and restores the three
 * user_id indexes. Rows whose profile_id maps to no user_id (profile-native
 * accounts) are deleted — they were not representable in the old schema.
 * On SQLite the column drop uses the rename-recreate idiom as in migration 037.
 */
class RekeyBackupCodesToProfiles
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
        // ── 1. Add profile_id (idempotent) ────────────────────────────────────
        if (!self::columnExists($db, $driver, 'backup_codes', 'profile_id')) {
            $db->exec('
                ALTER TABLE backup_codes
                ADD COLUMN profile_id INTEGER NULL
                    REFERENCES profiles(id) ON DELETE CASCADE
            ');
        }

        // ── 2. Backfill from migration_035_profile_ids mapping ────────────────
        // In a fresh schema the mapping table is empty and backup_codes is empty,
        // so the backfill is a no-op — that is expected and correct.
        $hasMapping = self::tableExists($db, $driver, 'migration_035_profile_ids');
        $hasLegacyUserId = self::columnExists($db, $driver, 'backup_codes', 'user_id');

        if ($hasMapping && $hasLegacyUserId) {
            // @tenant-guard-ignore: cross-table migration backfill; updates own table rows using external mapping
            $db->exec('
                UPDATE backup_codes
                SET profile_id = (
                    SELECT m.profile_id
                    FROM migration_035_profile_ids m
                    WHERE m.user_id = backup_codes.user_id
                    LIMIT 1
                )
                WHERE profile_id IS NULL
            ');
        }

        // ── 3. Delete orphan rows (user_id had no mapping) ───────────────────
        // Any row still carrying profile_id IS NULL after the backfill has a
        // user_id with no profile mapping. Migration 035 migrated every non-system
        // user, so a remaining orphan is a dangling/legacy row — delete it so the
        // NOT NULL constraint below can be enforced honestly.
        $orphanCount = self::countWhere($db, 'profile_id IS NULL');
        if ($orphanCount > 0) {
            // @tenant-guard-ignore: migration cleanup of dangling backup-code rows across all tenants
            $db->exec('DELETE FROM backup_codes WHERE profile_id IS NULL');
            self::log("migration 038: deleted {$orphanCount} backup_codes row(s) with no profile mapping (orphaned user_id).");
        }

        // ── 4. Enforce profile_id NOT NULL ────────────────────────────────────
        if ($driver === 'pgsql') {
            $db->exec('ALTER TABLE backup_codes ALTER COLUMN profile_id SET NOT NULL');
        }
        // SQLite: enforced by the table rebuild in step 6 (DDL carries NOT NULL).

        // ── 5 + 6. Drop user_id column (and its indexes) ─────────────────────
        if ($hasLegacyUserId) {
            if ($driver === 'pgsql') {
                // Drop the three user_id indexes before dropping the column.
                self::dropIndexSafe($db, 'idx_backup_codes_user_id');
                self::dropIndexSafe($db, 'idx_backup_codes_user_id_version');
                self::dropIndexSafe($db, 'idx_backup_codes_user_id_used');
                $db->exec('ALTER TABLE backup_codes DROP COLUMN IF EXISTS user_id');
            } else {
                // SQLite: rename-recreate idiom (cannot DROP COLUMN or ALTER to
                // NOT NULL in place). The rebuilt DDL carries profile_id NOT NULL
                // and omits user_id; the three old indexes are dropped implicitly.
                self::rebuildBackupCodesSqlite($db);
            }
        }

        // ── 7. Create new profile_id indexes (idempotent) ────────────────────
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_profile_id ON backup_codes (profile_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_profile_id_version ON backup_codes (profile_id, version)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_profile_id_used ON backup_codes (profile_id, used)');
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
        // ── 1. Re-add user_id (idempotent) ────────────────────────────────────
        if (!self::columnExists($db, $driver, 'backup_codes', 'user_id')) {
            $db->exec('
                ALTER TABLE backup_codes
                ADD COLUMN user_id INTEGER NULL
                    REFERENCES users(id) ON DELETE CASCADE
            ');
        }

        // ── 2. Reverse-backfill user_id from the 035 mapping ─────────────────
        if (self::tableExists($db, $driver, 'migration_035_profile_ids')) {
            // @tenant-guard-ignore: reverse migration backfill; profile_id → kept user_id
            $db->exec('
                UPDATE backup_codes
                SET user_id = (
                    SELECT m.user_id
                    FROM migration_035_profile_ids m
                    WHERE m.profile_id = backup_codes.profile_id
                    ORDER BY m.user_id ASC
                    LIMIT 1
                )
                WHERE user_id IS NULL
            ');
        }

        // Rows whose profile_id maps to no user_id (profile-native accounts)
        // cannot be represented in the old schema — delete them.
        $unmappable = self::countWhere($db, 'user_id IS NULL');
        if ($unmappable > 0) {
            // @tenant-guard-ignore: reverse migration cleanup of profile-native backup-code rows
            $db->exec('DELETE FROM backup_codes WHERE user_id IS NULL');
            self::log("migration 038 down(): deleted {$unmappable} backup_codes row(s) with no reverse mapping (profile-native, unrecoverable).");
        }

        // ── 3. Restore the three user_id indexes ──────────────────────────────
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id ON backup_codes (user_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id_version ON backup_codes (user_id, version)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_backup_codes_user_id_used ON backup_codes (user_id, used)');

        // ── 4. Drop profile_id column (and its indexes) ───────────────────────
        if (self::columnExists($db, $driver, 'backup_codes', 'profile_id')) {
            if ($driver === 'pgsql') {
                self::dropIndexSafe($db, 'idx_backup_codes_profile_id');
                self::dropIndexSafe($db, 'idx_backup_codes_profile_id_version');
                self::dropIndexSafe($db, 'idx_backup_codes_profile_id_used');
                $db->exec('ALTER TABLE backup_codes DROP COLUMN IF EXISTS profile_id');
            } else {
                // SQLite: rebuild WITHOUT profile_id (keeps user_id NOT NULL).
                self::rebuildBackupCodesSqliteDown($db);
                // Drop any profile_id indexes left over (rebuild may not drop them).
                self::dropIndexSafe($db, 'idx_backup_codes_profile_id');
                self::dropIndexSafe($db, 'idx_backup_codes_profile_id_version');
                self::dropIndexSafe($db, 'idx_backup_codes_profile_id_used');
            }
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
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = current_schema()
                   AND table_name = ?
                   AND column_name = ?'
            );
            $stmt->execute([$table, $column]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            $stmt->closeCursor();
            return $row !== false;
        }
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
     * Count backup_codes rows matching a WHERE fragment (no user input;
     * the fragment is a constant literal supplied by this migration only).
     */
    private static function countWhere(Database $db, string $whereFragment): int
    {
        $pdo  = $db->getPdo();
        // @tenant-guard-ignore: migration-internal count over all tenants; fragment is a constant literal
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM backup_codes WHERE {$whereFragment}");
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

    private static function log(string $message): void
    {
        echo '[migration 038] ' . $message . "\n";
    }

    // ── SQLite table rebuild (rename-recreate) ──────────────────────────────

    /**
     * Rebuild backup_codes on SQLite for up(): drops user_id, retains profile_id
     * NOT NULL, re-creates the table DDL and the profile_id indexes.
     */
    private static function rebuildBackupCodesSqlite(Database $db): void
    {
        $pdo = $db->getPdo();

        // Get current column list from PRAGMA.
        $info = $pdo->query('PRAGMA table_info(backup_codes)');
        if ($info === false) {
            return;
        }
        $cols = $info->fetchAll(PDO::FETCH_ASSOC);

        // Keep all columns except user_id; replace profile_id NULL with NOT NULL.
        $keepCols = array_values(array_filter(
            $cols,
            static fn (array $c): bool => (string) $c['name'] !== 'user_id'
        ));

        $colDefs  = [];
        $keepNames = [];
        foreach ($keepCols as $c) {
            $name    = (string) $c['name'];
            $type    = (string) ($c['type'] ?? '');
            $isPk    = (int) ($c['pk'] ?? 0) > 0;
            $notNull = (int) ($c['notnull'] ?? 0) > 0;
            $default = $c['dflt_value'] ?? null;

            // profile_id must be NOT NULL in the rebuilt table.
            if ($name === 'profile_id') {
                $notNull = true;
            }

            $line = '"' . $name . '" ' . ($type !== '' ? $type : 'INTEGER');
            if ($isPk) {
                $line .= ' PRIMARY KEY AUTOINCREMENT';
            } elseif ($notNull) {
                $line .= ' NOT NULL';
            }
            if ($default !== null && !$isPk) {
                $defaultSql = (string) $default;
                if ($defaultSql !== '' && $defaultSql[0] !== '(') {
                    $defaultSql = '(' . $defaultSql . ')';
                }
                $line .= ' DEFAULT ' . $defaultSql;
            }
            $colDefs[]  = $line;
            $keepNames[] = '"' . $name . '"';
        }

        // Foreign key on profile_id.
        $colDefs[] = 'FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE';

        $ddl     = 'CREATE TABLE "backup_codes" (' . "\n    " . implode(",\n    ", $colDefs) . "\n)";
        $colList = implode(', ', $keepNames);

        $pdo->exec('ALTER TABLE "backup_codes" RENAME TO "backup_codes_old_038"');
        $pdo->exec($ddl);
        $pdo->exec("INSERT INTO \"backup_codes\" ({$colList}) SELECT {$colList} FROM \"backup_codes_old_038\"");
        $pdo->exec('DROP TABLE "backup_codes_old_038"');
    }

    /**
     * Rebuild backup_codes on SQLite for down(): drops profile_id, retains
     * user_id NOT NULL (restores the pre-038 schema).
     */
    private static function rebuildBackupCodesSqliteDown(Database $db): void
    {
        $pdo = $db->getPdo();

        $info = $pdo->query('PRAGMA table_info(backup_codes)');
        if ($info === false) {
            return;
        }
        $cols = $info->fetchAll(PDO::FETCH_ASSOC);

        // Keep all columns except profile_id.
        $keepCols = array_values(array_filter(
            $cols,
            static fn (array $c): bool => (string) $c['name'] !== 'profile_id'
        ));

        $colDefs   = [];
        $keepNames = [];
        foreach ($keepCols as $c) {
            $name    = (string) $c['name'];
            $type    = (string) ($c['type'] ?? '');
            $isPk    = (int) ($c['pk'] ?? 0) > 0;
            $notNull = (int) ($c['notnull'] ?? 0) > 0;
            $default = $c['dflt_value'] ?? null;

            // user_id must be NOT NULL in the restored table.
            if ($name === 'user_id') {
                $notNull = true;
            }

            $line = '"' . $name . '" ' . ($type !== '' ? $type : 'INTEGER');
            if ($isPk) {
                $line .= ' PRIMARY KEY AUTOINCREMENT';
            } elseif ($notNull) {
                $line .= ' NOT NULL';
            }
            if ($default !== null && !$isPk) {
                $defaultSql = (string) $default;
                if ($defaultSql !== '' && $defaultSql[0] !== '(') {
                    $defaultSql = '(' . $defaultSql . ')';
                }
                $line .= ' DEFAULT ' . $defaultSql;
            }
            $colDefs[]  = $line;
            $keepNames[] = '"' . $name . '"';
        }

        // Foreign key on user_id.
        $colDefs[] = 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE';

        $ddl     = 'CREATE TABLE "backup_codes" (' . "\n    " . implode(",\n    ", $colDefs) . "\n)";
        $colList = implode(', ', $keepNames);

        $pdo->exec('ALTER TABLE "backup_codes" RENAME TO "backup_codes_old_038d"');
        $pdo->exec($ddl);
        $pdo->exec("INSERT INTO \"backup_codes\" ({$colList}) SELECT {$colList} FROM \"backup_codes_old_038d\"");
        $pdo->exec('DROP TABLE "backup_codes_old_038d"');
    }
}
