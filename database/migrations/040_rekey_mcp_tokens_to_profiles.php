<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * RekeyMcpTokensToProfiles — forward migration (WC-idcut-C, migration 040).
 *
 * Re-points `mcp_tokens.user_id` from `users.id` to `profiles.id`
 * (ADR 0005 §9 — MCP tokens reference profiles, not users).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Schema changes (up)
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Add `profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE CASCADE`.
 * 2. Backfill `profile_id` from the users→profiles mapping established by
 *    migration 035 (migration_035_profile_ids table).
 *    In a fresh schema the mapping table is empty and mcp_tokens is empty, so
 *    the backfill is a no-op — that is expected and correct.
 * 3. Delete orphan rows: any row whose user_id had no mapping in the 035 table.
 *    Migration 035 migrated every non-system user; a remaining orphan is a
 *    dangling/legacy row — delete it so the NOT NULL constraint is honest.
 * 4. Set `profile_id NOT NULL`.
 * 5. Drop `user_id` + its index `idx_mcp_tokens_user_tenant`
 *    (PostgreSQL: ALTER TABLE DROP COLUMN; SQLite: table-rebuild).
 * 6. Create `idx_mcp_tokens_profile_tenant (profile_id, tenant_id)` (mirrors
 *    the old `idx_mcp_tokens_user_tenant`).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Idempotency
 * ─────────────────────────────────────────────────────────────────────────────
 * up() checks column state before mutating, so a re-run on an already-migrated
 * database is safe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reversibility (down)
 * ─────────────────────────────────────────────────────────────────────────────
 * down() reverses the schema: re-adds user_id, reverse-backfills from the 035
 * mapping (profile_id → kept user_id), drops profile_id, and restores
 * idx_mcp_tokens_user_tenant. Rows whose profile_id maps to no user_id
 * (profile-native accounts) are deleted — they were not representable in the
 * old schema.
 * On SQLite the column drop uses the rename-recreate idiom as in migration 038.
 */
class RekeyMcpTokensToProfiles
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
        if (!self::columnExists($db, $driver, 'mcp_tokens', 'profile_id')) {
            $db->exec('
                ALTER TABLE mcp_tokens
                ADD COLUMN profile_id INTEGER NULL
                    REFERENCES profiles(id) ON DELETE CASCADE
            ');
        }

        // ── 2. Backfill from migration_035_profile_ids mapping ────────────────
        $hasMapping      = self::tableExists($db, $driver, 'migration_035_profile_ids');
        $hasLegacyUserId = self::columnExists($db, $driver, 'mcp_tokens', 'user_id');

        if ($hasMapping && $hasLegacyUserId) {
            // @tenant-guard-ignore: cross-table migration backfill; updates own table rows using external mapping
            $db->exec('
                UPDATE mcp_tokens
                SET profile_id = (
                    SELECT m.profile_id
                    FROM migration_035_profile_ids m
                    WHERE m.user_id = mcp_tokens.user_id
                    LIMIT 1
                )
                WHERE profile_id IS NULL
            ');
        }

        // ── 3. Delete orphan rows (user_id had no mapping) ───────────────────
        $orphanCount = self::countWhere($db, 'profile_id IS NULL');
        if ($orphanCount > 0) {
            // @tenant-guard-ignore: migration cleanup of dangling mcp_tokens rows across all tenants
            $db->exec('DELETE FROM mcp_tokens WHERE profile_id IS NULL');
            self::log("migration 040: deleted {$orphanCount} mcp_tokens row(s) with no profile mapping (orphaned user_id).");
        }

        // ── 4. Enforce profile_id NOT NULL ────────────────────────────────────
        if ($driver === 'pgsql') {
            $db->exec('ALTER TABLE mcp_tokens ALTER COLUMN profile_id SET NOT NULL');
        }
        // SQLite: enforced by the table rebuild in step 5 (DDL carries NOT NULL).

        // ── 5. Drop user_id column (and its composite index) ─────────────────
        if ($hasLegacyUserId) {
            if ($driver === 'pgsql') {
                self::dropIndexSafe($db, 'idx_mcp_tokens_user_tenant');
                $db->exec('ALTER TABLE mcp_tokens DROP COLUMN IF EXISTS user_id');
            } else {
                // SQLite: rename-recreate idiom (cannot DROP COLUMN or ALTER to
                // NOT NULL in place). The rebuilt DDL carries profile_id NOT NULL
                // and omits user_id; the old index is dropped implicitly.
                self::rebuildMcpTokensSqliteUp($db);
            }
        }

        // ── 6. Create new profile_id + tenant_id index (idempotent) ──────────
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_mcp_tokens_profile_tenant
                ON mcp_tokens (profile_id, tenant_id)
        ');
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
        if (!self::columnExists($db, $driver, 'mcp_tokens', 'user_id')) {
            $db->exec('
                ALTER TABLE mcp_tokens
                ADD COLUMN user_id INTEGER NULL
                    REFERENCES users(id) ON DELETE CASCADE
            ');
        }

        // ── 2. Reverse-backfill user_id from the 035 mapping ─────────────────
        if (self::tableExists($db, $driver, 'migration_035_profile_ids')) {
            // @tenant-guard-ignore: reverse migration backfill; profile_id → kept user_id
            $db->exec('
                UPDATE mcp_tokens
                SET user_id = (
                    SELECT m.user_id
                    FROM migration_035_profile_ids m
                    WHERE m.profile_id = mcp_tokens.profile_id
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
            // @tenant-guard-ignore: reverse migration cleanup of profile-native mcp_tokens rows
            $db->exec('DELETE FROM mcp_tokens WHERE user_id IS NULL');
            self::log("migration 040 down(): deleted {$unmappable} mcp_tokens row(s) with no reverse mapping (profile-native, unrecoverable).");
        }

        // ── 3. Drop profile_id column (and its index) ─────────────────────────
        if (self::columnExists($db, $driver, 'mcp_tokens', 'profile_id')) {
            if ($driver === 'pgsql') {
                self::dropIndexSafe($db, 'idx_mcp_tokens_profile_tenant');
                $db->exec('ALTER TABLE mcp_tokens DROP COLUMN IF EXISTS profile_id');
            } else {
                // SQLite: rebuild WITHOUT profile_id (keeps user_id NOT NULL).
                // The rebuild drops all indexes implicitly, so idx_mcp_tokens_user_tenant
                // is recreated in step 4 below (after the rebuild).
                self::rebuildMcpTokensSqliteDown($db);
                self::dropIndexSafe($db, 'idx_mcp_tokens_profile_tenant');
            }
        }

        // ── 4. Restore idx_mcp_tokens_user_tenant (idempotent) ───────────────
        // Created AFTER the profile_id drop because on SQLite the table rebuild
        // in step 3 drops all indexes implicitly.
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_mcp_tokens_user_tenant
                ON mcp_tokens (user_id, tenant_id)
        ');
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
     * Count mcp_tokens rows matching a WHERE fragment (no user input;
     * the fragment is a constant literal supplied by this migration only).
     */
    private static function countWhere(Database $db, string $whereFragment): int
    {
        $pdo  = $db->getPdo();
        // @tenant-guard-ignore: migration-internal count over all tenants; fragment is a constant literal
        $stmt = $pdo->query("SELECT COUNT(*) AS c FROM mcp_tokens WHERE {$whereFragment}");
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
        echo '[migration 040] ' . $message . "\n";
    }

    // ── SQLite table rebuild (rename-recreate) ──────────────────────────────

    /**
     * Rebuild mcp_tokens on SQLite for up(): drops user_id, retains profile_id
     * NOT NULL, re-creates the table DDL and the expires_at index.
     */
    private static function rebuildMcpTokensSqliteUp(Database $db): void
    {
        $pdo = $db->getPdo();

        $info = $pdo->query('PRAGMA table_info(mcp_tokens)');
        if ($info === false) {
            return;
        }
        $cols = $info->fetchAll(PDO::FETCH_ASSOC);

        // Keep all columns except user_id; ensure profile_id is NOT NULL.
        $keepCols = array_values(array_filter(
            $cols,
            static fn (array $c): bool => (string) $c['name'] !== 'user_id'
        ));

        $colDefs   = [];
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
            $colDefs[]   = $line;
            $keepNames[] = '"' . $name . '"';
        }

        // Unique constraint on jti + FKs.
        $colDefs[] = 'UNIQUE (jti)';
        $colDefs[] = 'FOREIGN KEY (profile_id) REFERENCES profiles(id) ON DELETE CASCADE';
        $colDefs[] = 'FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE';

        $ddl     = 'CREATE TABLE "mcp_tokens" (' . "\n    " . implode(",\n    ", $colDefs) . "\n)";
        $colList = implode(', ', $keepNames);

        $pdo->exec('ALTER TABLE "mcp_tokens" RENAME TO "mcp_tokens_old_040"');
        $pdo->exec($ddl);
        $pdo->exec("INSERT INTO \"mcp_tokens\" ({$colList}) SELECT {$colList} FROM \"mcp_tokens_old_040\"");
        $pdo->exec('DROP TABLE "mcp_tokens_old_040"');

        // Restore the expires_at index (was present in migration 033).
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_tokens_expires_at ON mcp_tokens (expires_at)');
    }

    /**
     * Rebuild mcp_tokens on SQLite for down(): drops profile_id, retains
     * user_id NOT NULL (restores the pre-040 schema).
     */
    private static function rebuildMcpTokensSqliteDown(Database $db): void
    {
        $pdo = $db->getPdo();

        $info = $pdo->query('PRAGMA table_info(mcp_tokens)');
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
            $colDefs[]   = $line;
            $keepNames[] = '"' . $name . '"';
        }

        $colDefs[] = 'UNIQUE (jti)';
        $colDefs[] = 'FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE';
        $colDefs[] = 'FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE';

        $ddl     = 'CREATE TABLE "mcp_tokens" (' . "\n    " . implode(",\n    ", $colDefs) . "\n)";
        $colList = implode(', ', $keepNames);

        $pdo->exec('ALTER TABLE "mcp_tokens" RENAME TO "mcp_tokens_old_040d"');
        $pdo->exec($ddl);
        $pdo->exec("INSERT INTO \"mcp_tokens\" ({$colList}) SELECT {$colList} FROM \"mcp_tokens_old_040d\"");
        $pdo->exec('DROP TABLE "mcp_tokens_old_040d"');

        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_mcp_tokens_expires_at ON mcp_tokens (expires_at)');
    }
}
