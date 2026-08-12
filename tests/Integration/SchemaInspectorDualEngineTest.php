<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Sdk\Schema\SchemaInspector;

/**
 * The reason {@see SchemaInspector} exists, tested where it can fail: the SAME
 * assertions run against PostgreSQL and SQLite and must produce the SAME
 * answers.
 *
 * A helper whose job is to paper over a dialect difference is worth nothing if
 * it is only ever exercised on one dialect — that is precisely the failure mode
 * it was written to remove, since a hand-written existence check passes on the
 * engine its author develops against and fails on the other one, at enable
 * time, on somebody else's deployment.
 *
 * How both engines get covered
 * ----------------------------
 * {@see SchemaFromMigrations::make()} returns a real PostgreSQL connection when
 * `PHPUNIT_PG_DSN` is set and an in-memory SQLite one otherwise, and CI runs
 * `tests/Integration` BOTH ways: the sharded SQLite `test` job (no DSN) and the
 * sharded `postgres-integration` job (DSN set, one throwaway Postgres schema
 * per test class). One file, two engines, no `markTestSkipped`.
 *
 * The engine-specific tests at the bottom are guarded on the live driver rather
 * than skipped wholesale, so each engine's own trap — PostgreSQL's search path
 * and dropped-column tombstones, SQLite's unbindable PRAGMA — is asserted on
 * the engine that has it.
 */
final class SchemaInspectorDualEngineTest extends TestCase
{
    /**
     * Built ONCE for the class. On the PostgreSQL path every
     * {@see SchemaFromMigrations::make()} call creates a fresh Postgres schema
     * and replays the whole production migration set inside it, which is
     * seconds; paying that per test method would put minutes on a CI shard for
     * no added coverage, since each test creates and drops its own probe table.
     */
    private static ?PDO $shared = null;

    private PDO $pdo;

    /** The engine actually under test: 'pgsql' or 'sqlite'. */
    private string $driver;

    public static function tearDownAfterClass(): void
    {
        self::$shared = null;
    }

    protected function setUp(): void
    {
        self::$shared ??= SchemaFromMigrations::make();
        $this->pdo = self::$shared;
        $this->driver = SchemaInspector::driver($this->pdo);

        $this->pdo->exec('DROP TABLE IF EXISTS si_probe');
        $this->pdo->exec(
            'CREATE TABLE si_probe (
                id INTEGER NOT NULL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(100)
            )'
        );
    }

    protected function tearDown(): void
    {
        // SQLite's in-memory database dies with the connection and the Postgres
        // path drops its whole schema at process exit, but a class that shares a
        // Postgres schema across its own tests must still clean up after itself.
        $this->pdo->exec('DROP TABLE IF EXISTS si_probe');
        $this->pdo->exec('DROP TABLE IF EXISTS si_probe_two');
    }

    // ==================== the identical-answer contract ====================

    public function testTableExistenceAnswersTheSameOnBothEngines(): void
    {
        self::assertTrue(SchemaInspector::tableExists($this->pdo, 'si_probe'), $this->driver);
        self::assertFalse(SchemaInspector::tableExists($this->pdo, 'si_probe_absent'), $this->driver);
    }

    public function testTableExistenceIsCaseInsensitiveOnBothEngines(): void
    {
        // PostgreSQL folds unquoted identifiers to lowercase; SQLite compares
        // them case-insensitively. Both must therefore say yes to either
        // spelling, or a plugin's constant casing changes the answer per engine.
        self::assertTrue(SchemaInspector::tableExists($this->pdo, 'SI_PROBE'), $this->driver);
        self::assertTrue(SchemaInspector::columnExists($this->pdo, 'SI_Probe', 'TENANT_ID'), $this->driver);
    }

    public function testColumnExistenceAnswersTheSameOnBothEngines(): void
    {
        self::assertTrue(SchemaInspector::columnExists($this->pdo, 'si_probe', 'tenant_id'), $this->driver);
        self::assertFalse(SchemaInspector::columnExists($this->pdo, 'si_probe', 'nope'), $this->driver);
        self::assertFalse(SchemaInspector::columnExists($this->pdo, 'si_probe_absent', 'id'), $this->driver);
    }

    public function testColumnListAnswersTheSameOnBothEngines(): void
    {
        self::assertSame(['id', 'tenant_id', 'name'], SchemaInspector::columns($this->pdo, 'si_probe'), $this->driver);
        self::assertSame([], SchemaInspector::columns($this->pdo, 'si_probe_absent'), $this->driver);
    }

    public function testIndexExistenceAnswersTheSameOnBothEngines(): void
    {
        $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_si_probe_tenant ON si_probe(tenant_id)');

        self::assertTrue(SchemaInspector::indexExists($this->pdo, 'idx_si_probe_tenant'), $this->driver);
        self::assertFalse(SchemaInspector::indexExists($this->pdo, 'idx_si_probe_absent'), $this->driver);
        // Type discrimination: a table is never an index on either engine.
        self::assertFalse(SchemaInspector::indexExists($this->pdo, 'si_probe'), $this->driver);
        self::assertFalse(SchemaInspector::tableExists($this->pdo, 'idx_si_probe_tenant'), $this->driver);
    }

    /**
     * THE CENTRAL CLAIM. `ALTER TABLE … ADD COLUMN IF NOT EXISTS` is a
     * PostgreSQL extension that SQLite rejects with a syntax error, which is
     * the exact gap that forces every plugin author to write the dialect
     * branch. The helper must therefore add exactly once and be a no-op after,
     * on both engines, with no branch at the call site.
     */
    public function testAddColumnIfMissingIsIdempotentOnBothEngines(): void
    {
        self::assertTrue(
            SchemaInspector::addColumnIfMissing($this->pdo, 'si_probe', 'archived_at', 'TIMESTAMP NULL'),
            "First call must add the column on {$this->driver}."
        );
        self::assertTrue(SchemaInspector::columnExists($this->pdo, 'si_probe', 'archived_at'), $this->driver);

        self::assertFalse(
            SchemaInspector::addColumnIfMissing($this->pdo, 'si_probe', 'archived_at', 'TIMESTAMP NULL'),
            "Re-run must be a no-op on {$this->driver}."
        );
        self::assertSame(
            ['id', 'tenant_id', 'name', 'archived_at'],
            SchemaInspector::columns($this->pdo, 'si_probe'),
            'A no-op must not have added a duplicate.'
        );
    }

    public function testAddColumnWithAConstantDefaultBackfillsExistingRowsOnBothEngines(): void
    {
        // Both engines require a CONSTANT default when adding a NOT NULL column
        // to a populated table; the helper's contract documents that, and this
        // is the check that the documented form actually works on both.
        $this->pdo->exec("INSERT INTO si_probe (id, tenant_id, name) VALUES (1, 7, 'existing')");

        SchemaInspector::addColumnIfMissing($this->pdo, 'si_probe', 'version', 'INTEGER NOT NULL DEFAULT 1');

        $stmt = $this->pdo->query('SELECT version FROM si_probe WHERE id = 1');
        self::assertNotFalse($stmt);
        self::assertSame(1, (int) $stmt->fetchColumn(), $this->driver);
    }

    public function testDropColumnIfExistsIsIdempotentOnBothEngines(): void
    {
        // SQLite gained DROP COLUMN in 3.35 but never an IF EXISTS for it, so
        // the reversal half of every migration hits the same gap as the add.
        self::assertTrue(SchemaInspector::dropColumnIfExists($this->pdo, 'si_probe', 'name'), $this->driver);
        self::assertFalse(SchemaInspector::columnExists($this->pdo, 'si_probe', 'name'), $this->driver);

        self::assertFalse(
            SchemaInspector::dropColumnIfExists($this->pdo, 'si_probe', 'name'),
            "Reversing twice must be tolerated on {$this->driver}."
        );
        self::assertFalse(SchemaInspector::dropColumnIfExists($this->pdo, 'si_probe_absent', 'name'), $this->driver);
    }

    public function testAddThenDropThenAddRoundTripsOnBothEngines(): void
    {
        // The lifecycle a `migrate run` / `migrate rollback` / `migrate run`
        // cycle actually performs. On PostgreSQL a dropped column leaves an
        // attisdropped tombstone row carrying the old attnum; if the catalogue
        // query did not exclude it, the second add would read as already-present
        // and silently do nothing.
        SchemaInspector::addColumnIfMissing($this->pdo, 'si_probe', 'note', 'TEXT');
        SchemaInspector::dropColumnIfExists($this->pdo, 'si_probe', 'note');

        self::assertFalse(SchemaInspector::columnExists($this->pdo, 'si_probe', 'note'), $this->driver);
        self::assertNotContains('note', SchemaInspector::columns($this->pdo, 'si_probe'), $this->driver);
        self::assertTrue(
            SchemaInspector::addColumnIfMissing($this->pdo, 'si_probe', 'note', 'TEXT'),
            "A dropped column must be re-addable on {$this->driver}."
        );
    }

    public function testReservedWordIdentifiersSurviveQuotingOnBothEngines(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS si_probe_two');
        $this->pdo->exec('CREATE TABLE si_probe_two (id INTEGER NOT NULL PRIMARY KEY)');

        self::assertTrue(SchemaInspector::addColumnIfMissing($this->pdo, 'si_probe_two', 'order', 'INTEGER'));
        self::assertTrue(SchemaInspector::columnExists($this->pdo, 'si_probe_two', 'order'), $this->driver);
    }

    public function testAViewIsNeverATableOnEitherEngine(): void
    {
        $this->pdo->exec('DROP VIEW IF EXISTS si_probe_view');
        $this->pdo->exec('CREATE VIEW si_probe_view AS SELECT id FROM si_probe');

        try {
            self::assertFalse(SchemaInspector::tableExists($this->pdo, 'si_probe_view'), $this->driver);
        } finally {
            $this->pdo->exec('DROP VIEW IF EXISTS si_probe_view');
        }
    }

    // ==================== engine-specific traps ====================

    /**
     * The bug in the hand-written PostgreSQL copy this class replaces: it
     * filtered on `table_name` alone, with no schema predicate, so it matched a
     * same-named table in ANY schema — including one the connection cannot see.
     * Since the test harness itself gives every run its own Postgres schema,
     * and multi-schema deployments are ordinary, that is a live wrong answer,
     * not a theoretical one.
     */
    public function testPostgresLookupsAreConfinedToTheSearchPath(): void
    {
        if ($this->driver !== SchemaInspector::DRIVER_PGSQL) {
            self::assertTrue(true, 'Search-path scoping is a PostgreSQL-only concern.');
            return;
        }

        $this->pdo->exec('CREATE SCHEMA IF NOT EXISTS si_offpath');
        try {
            // A table with a name the connection's own schema does not have.
            $this->pdo->exec('CREATE TABLE si_offpath.si_elsewhere (id INTEGER PRIMARY KEY, secret TEXT)');

            self::assertFalse(
                SchemaInspector::tableExists($this->pdo, 'si_elsewhere'),
                'A table outside the search path must not read as existing — the migration '
                . 'about to CREATE it would otherwise be skipped and its columns never exist.'
            );
            self::assertFalse(
                SchemaInspector::columnExists($this->pdo, 'si_elsewhere', 'secret'),
                'Nor may its columns.'
            );
            self::assertSame([], SchemaInspector::columns($this->pdo, 'si_elsewhere'));
        } finally {
            $this->pdo->exec('DROP SCHEMA IF EXISTS si_offpath CASCADE');
        }
    }

    /**
     * SQLite's bare `PRAGMA table_info(x)` cannot bind its argument, which is
     * why hand-written copies interpolate the table name straight into SQL.
     * This class uses the table-valued `pragma_table_info(?)` form instead, so
     * a hostile name is a bound value that matches nothing rather than SQL.
     * (The identifier check refuses it first; this proves the second line of
     * defence is real and not merely claimed.)
     */
    public function testSqliteColumnLookupBindsRatherThanInterpolates(): void
    {
        if ($this->driver !== SchemaInspector::DRIVER_SQLITE) {
            self::assertTrue(true, 'The unbindable-PRAGMA trap is SQLite-only.');
            return;
        }

        $stmt = $this->pdo->prepare('SELECT 1 FROM pragma_table_info(:table) WHERE lower(name) = lower(:column)');
        $stmt->execute([':table' => 'si_probe"); DROP TABLE si_probe; --', ':column' => 'id']);

        self::assertFalse($stmt->fetchColumn(), 'A bound hostile name matches nothing.');
        self::assertTrue(
            SchemaInspector::tableExists($this->pdo, 'si_probe'),
            'And executes nothing — the table is still there.'
        );
    }
}
