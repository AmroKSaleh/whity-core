<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * RekeyPersonsToProfiles — forward migration (WC-idcut-D, migration 041).
 *
 * Re-points `persons.user_id` from `users.id` to `profiles.id`
 * (ADR 0005 §9 — persons reference profiles, not users).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Schema changes (up)
 * ─────────────────────────────────────────────────────────────────────────────
 * 1. Add `profile_id INTEGER NULL REFERENCES profiles(id) ON DELETE SET NULL`.
 * 2. Backfill `profile_id` from the users→profiles mapping established by
 *    migration 035 (migration_035_profile_ids table). Only rows where
 *    user_id IS NOT NULL are eligible; pure-relative rows stay NULL.
 * 3. Drop the UNIQUE constraint on `user_id` (PostgreSQL: DROP CONSTRAINT;
 *    SQLite: handled by the table rebuild).
 * 4. Drop the `user_id` column (PostgreSQL: ALTER TABLE DROP COLUMN;
 *    SQLite: table-rebuild, matching the pattern of migration 037/038).
 * 5. Add UNIQUE(profile_id) — nullable unique: at most one shadow person per
 *    profile, but NULL rows are not constrained (SQL standard: each NULL is
 *    distinct under a UNIQUE index).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Idempotency
 * ─────────────────────────────────────────────────────────────────────────────
 * up() checks column/constraint state before mutating, so a re-run on an
 * already-migrated schema is safe.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * Reversibility (down)
 * ─────────────────────────────────────────────────────────────────────────────
 * down() reverses the schema:
 * 1. Re-add `user_id INTEGER NULL REFERENCES users(id) ON DELETE SET NULL`.
 * 2. Reverse-backfill user_id from the 035 mapping (profile_id → kept user_id).
 *    Rows whose profile_id has no mapping (profile-native accounts) cannot be
 *    represented in the old schema — their user_id stays NULL.
 * 3. Drop UNIQUE(profile_id).
 * 4. Drop profile_id column.
 * 5. Re-add UNIQUE(user_id) on the restored user_id column.
 *
 * WARNING: rows where profile_id had no user_id reverse mapping (profile-native
 * accounts) will have user_id = NULL after down() — they survive but lose their
 * profile link. This is acceptable because there is no user base; such rows were
 * created by the new code path only.
 */
class RekeyPersonsToProfiles
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
        if (!self::columnExists($db, $driver, 'persons', 'profile_id')) {
            $db->exec('
                ALTER TABLE persons
                ADD COLUMN profile_id INTEGER NULL
                    REFERENCES profiles(id) ON DELETE SET NULL
            ');
        }

        // ── 2. Backfill from migration_035_profile_ids mapping ────────────────
        // In a fresh schema the mapping table may be empty (or absent) and
        // persons.user_id is all NULL — backfill is a no-op in that case.
        $hasMapping      = self::tableExists($db, $driver, 'migration_035_profile_ids');
        $hasLegacyUserId = self::columnExists($db, $driver, 'persons', 'user_id');

        if ($hasMapping && $hasLegacyUserId) {
            // @tenant-guard-ignore: cross-table migration backfill; updates own table rows using external mapping
            $db->exec('
                UPDATE persons
                SET profile_id = (
                    SELECT m.profile_id
                    FROM migration_035_profile_ids m
                    WHERE m.user_id = persons.user_id
                    LIMIT 1
                )
                WHERE user_id IS NOT NULL
                  AND profile_id IS NULL
            ');
        }

        // ── 3 + 4. Drop user_id column (and the UNIQUE constraint it carries) ─
        if ($hasLegacyUserId) {
            if ($driver === 'pgsql') {
                // PostgreSQL: the UNIQUE constraint on user_id is backed by an
                // index named persons_user_id_key (auto-generated). DROP COLUMN
                // removes the column and its dependent constraint/index together.
                $db->exec('ALTER TABLE persons DROP COLUMN IF EXISTS user_id');
            } else {
                // SQLite: rename-recreate idiom (cannot DROP COLUMN or drop a
                // UNIQUE constraint in place). The rebuilt DDL omits user_id.
                self::rebuildPersonsSqlite($db, dropColumn: 'user_id', keepUniqueOn: 'profile_id');
            }
        }

        // ── 5. Add UNIQUE(profile_id) (idempotent) ────────────────────────────
        // A nullable-unique index: each NULL is distinct (SQL standard), so
        // pure-relative rows (profile_id IS NULL) are not constrained; at most
        // one shadow person may exist per profile.
        if ($driver === 'pgsql') {
            // Check whether the unique constraint already exists before adding.
            if (!self::uniqueIndexExists($db, 'persons', 'profile_id')) {
                $db->exec('
                    ALTER TABLE persons
                    ADD CONSTRAINT persons_profile_id_key UNIQUE (profile_id)
                ');
            }
        }
        // SQLite: the rebuild above already bakes UNIQUE into the DDL; a
        // CREATE UNIQUE INDEX would duplicate it — skip.
        if ($driver !== 'pgsql') {
            // Only create the index if the rebuild did not already do it.
            // The rebuild carries UNIQUE in the column DDL, so an extra index is
            // harmless but unnecessary. We create it anyway for idempotency on a
            // fresh DB where no rebuild ran.
            $db->exec('
                CREATE UNIQUE INDEX IF NOT EXISTS idx_persons_profile_id
                ON persons (profile_id)
                WHERE profile_id IS NOT NULL
            ');
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
        // ── 1. Re-add user_id (idempotent) ────────────────────────────────────
        if (!self::columnExists($db, $driver, 'persons', 'user_id')) {
            $db->exec('
                ALTER TABLE persons
                ADD COLUMN user_id INTEGER NULL
                    REFERENCES users(id) ON DELETE SET NULL
            ');
        }

        // ── 2. Reverse-backfill user_id from the 035 mapping ─────────────────
        if (self::tableExists($db, $driver, 'migration_035_profile_ids')) {
            // @tenant-guard-ignore: reverse migration backfill; profile_id → kept user_id
            $db->exec('
                UPDATE persons
                SET user_id = (
                    SELECT m.user_id
                    FROM migration_035_profile_ids m
                    WHERE m.profile_id = persons.profile_id
                    ORDER BY m.user_id ASC
                    LIMIT 1
                )
                WHERE profile_id IS NOT NULL
                  AND user_id IS NULL
            ');
        }
        // Rows whose profile_id has no reverse mapping (profile-native accounts)
        // are left with user_id = NULL — they survive as account-less relatives,
        // consistent with the original schema allowing NULL user_id.

        // ── 3. Drop UNIQUE(profile_id) ────────────────────────────────────────
        if ($driver === 'pgsql') {
            $db->exec('ALTER TABLE persons DROP CONSTRAINT IF EXISTS persons_profile_id_key');
        } else {
            self::dropIndexSafe($db, 'idx_persons_profile_id');
        }

        // ── 4. Drop profile_id column ─────────────────────────────────────────
        if (self::columnExists($db, $driver, 'persons', 'profile_id')) {
            if ($driver === 'pgsql') {
                $db->exec('ALTER TABLE persons DROP COLUMN IF EXISTS profile_id');
            } else {
                // SQLite: rename-recreate idiom. Rebuild WITHOUT profile_id,
                // keeping all other columns including the restored user_id.
                self::rebuildPersonsSqlite($db, dropColumn: 'profile_id', keepUniqueOn: 'user_id');
            }
        }

        // ── 5. Re-add UNIQUE(user_id) ─────────────────────────────────────────
        // On PostgreSQL the rebuild in step 4 already dropped profile_id; the
        // UNIQUE on user_id was never there (we dropped it in up()'s step 4 via
        // DROP COLUMN which implicitly dropped its constraint). Re-add it now.
        if ($driver === 'pgsql') {
            if (!self::uniqueIndexExists($db, 'persons', 'user_id')) {
                $db->exec('
                    ALTER TABLE persons
                    ADD CONSTRAINT persons_user_id_key UNIQUE (user_id)
                ');
            }
        }
        // SQLite: the rebuild above bakes UNIQUE into the user_id column DDL.
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
     * Check whether a named UNIQUE index/constraint already covers a column
     * on the given table (PostgreSQL only). Used for idempotent up/down.
     */
    private static function uniqueIndexExists(Database $db, string $table, string $column): bool
    {
        $pdo  = $db->getPdo();
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM pg_index i
             JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
             JOIN pg_class c ON c.oid = i.indrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = ?
               AND a.attname = ?
               AND i.indisunique = true
               AND n.nspname = current_schema()"
        );
        $stmt->execute([$table, $column]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt->closeCursor();
        return $row !== false;
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
        echo '[migration 041] ' . $message . "\n";
    }

    // ── SQLite table rebuild (rename-recreate) ──────────────────────────────

    /**
     * Rebuild persons on SQLite via the rename-recreate idiom.
     *
     * Drops $dropColumn from the table and bakes UNIQUE into $keepUniqueOn.
     * Re-creates the idx_persons_tenant_id index (migration 018) and the
     * partial UNIQUE index on $keepUniqueOn (when non-null rows are expected).
     *
     * @param string      $dropColumn    Column to omit from the rebuilt table.
     * @param string|null $keepUniqueOn  Column to add UNIQUE to in the rebuilt DDL (null = none).
     */
    private static function rebuildPersonsSqlite(
        Database $db,
        string $dropColumn,
        ?string $keepUniqueOn = null
    ): void {
        $pdo = $db->getPdo();

        // Get the current column list.
        $info = $pdo->query('PRAGMA table_info(persons)');
        if ($info === false) {
            return;
        }
        $cols = $info->fetchAll(PDO::FETCH_ASSOC);

        // Get the existing FK constraints so we can re-bake them into the rebuilt DDL.
        // PRAGMA table_info() does not carry FK info; PRAGMA foreign_key_list() does.
        $fkInfo = $pdo->query('PRAGMA foreign_key_list(persons)');
        $fkMap  = []; // column name → ['table' => ..., 'to' => ..., 'on_delete' => ...]
        if ($fkInfo !== false) {
            foreach ($fkInfo->fetchAll(PDO::FETCH_ASSOC) as $fk) {
                $col = (string) $fk['from'];
                if ($col !== $dropColumn) {
                    $fkMap[$col] = [
                        'table'     => (string) $fk['table'],
                        'to'        => (string) $fk['to'],
                        'on_delete' => strtoupper((string) ($fk['on_delete'] ?? 'NO ACTION')),
                    ];
                }
            }
        }

        // Drop the target column.
        $keepCols = array_values(array_filter(
            $cols,
            static fn (array $c): bool => (string) $c['name'] !== $dropColumn
        ));

        $colDefs   = [];
        $keepNames = [];

        foreach ($keepCols as $c) {
            $name    = (string) $c['name'];
            $type    = (string) ($c['type'] ?? '');
            $isPk    = (int) ($c['pk'] ?? 0) > 0;
            $notNull = (int) ($c['notnull'] ?? 0) > 0;
            $default = $c['dflt_value'] ?? null;

            $line = '"' . $name . '" ' . ($type !== '' ? $type : 'INTEGER');
            if ($isPk) {
                $line .= ' PRIMARY KEY AUTOINCREMENT';
            } else {
                if ($notNull) {
                    $line .= ' NOT NULL';
                }
                // Bake UNIQUE into the column definition for $keepUniqueOn.
                if ($keepUniqueOn !== null && $name === $keepUniqueOn) {
                    $line .= ' UNIQUE';
                }
                // Re-bake the original FK reference if this column had one.
                if (isset($fkMap[$name])) {
                    $fk   = $fkMap[$name];
                    $line .= ' REFERENCES ' . $fk['table'] . '(' . $fk['to'] . ')';
                    if ($fk['on_delete'] !== '' && $fk['on_delete'] !== 'NO ACTION') {
                        $line .= ' ON DELETE ' . $fk['on_delete'];
                    }
                }
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

        $ddl     = 'CREATE TABLE "persons" (' . "\n    " . implode(",\n    ", $colDefs) . "\n)";
        $colList = implode(', ', $keepNames);

        // SQLite 3.26.0+ auto-updates FK references in other tables when a table
        // is renamed (ALTER TABLE RENAME TO). This causes relations.from_person_id
        // to silently point to persons_old_041 after the rename, breaking FK checks
        // once persons_old_041 is dropped. PRAGMA legacy_alter_table = ON restores
        // the old behaviour (no auto-update), which is exactly what rename-recreate
        // needs: the other tables keep pointing to "persons", and once the new
        // "persons" table is created they resolve correctly.
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('PRAGMA legacy_alter_table = ON');
        $pdo->exec('ALTER TABLE "persons" RENAME TO "persons_old_041"');
        $pdo->exec($ddl);
        $pdo->exec("INSERT INTO \"persons\" ({$colList}) SELECT {$colList} FROM \"persons_old_041\"");
        $pdo->exec('DROP TABLE "persons_old_041"');
        $pdo->exec('PRAGMA legacy_alter_table = OFF');
        $pdo->exec('PRAGMA foreign_keys = ON');

        // Re-create the tenant index from migration 018.
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_persons_tenant_id ON persons (tenant_id)');
    }
}
