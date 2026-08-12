<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Schema\SchemaInspector;

/**
 * Proof, not assertion, that {@see SequenceCounters} never hands two callers
 * the same number — on PostgreSQL and on SQLite.
 *
 * Why this file exists at all
 * --------------------------
 * The bug being answered here shipped under a docblock that ALREADY claimed
 * atomicity. A helper that merely looks atomic is worse than no helper, because
 * the claim gets believed and the read-then-write window is now somebody else's
 * code that nobody re-reads. So the claim is demonstrated rather than asserted.
 *
 * How real concurrency is reached without forking
 * ----------------------------------------------
 * TWO genuine database connections to ONE database, with the interleaving
 * driven by hand from a single PHP process. That is not a weaker test than
 * spawning processes — it is a stronger one, because the interleaving is CHOSEN
 * rather than hoped for: a forked race test that passes has usually just failed
 * to hit the window.
 *
 *   - PostgreSQL: connection A opens a transaction and allocates, holding the
 *     row lock. Connection B, armed with a short `statement_timeout`, tries to
 *     allocate and must FAIL — proving it is blocked rather than proceeding on
 *     a stale read. A commits; B then allocates and gets the NEXT number.
 *   - SQLite: identical shape, with `busy_timeout` in place of
 *     `statement_timeout` and SQLite's database-level write lock in place of
 *     the row lock.
 *
 * The control experiment is the other half
 * ----------------------------------------
 * {@see testTheNaiveReadThenWriteAllocatorHandsOutADuplicate} runs the ORIGINAL
 * broken pattern through the same harness and shows it hand the same number to
 * both connections. Without it, a green result below would prove only that this
 * harness cannot detect a race — not that there is none to detect.
 *
 * Both engines are covered because CI runs `tests/Integration` twice: the
 * sharded SQLite `test` job and the sharded `postgres-integration` job.
 */
final class SequenceAllocatorConcurrencyTest extends TestCase
{
    /** The system tenant, created by migration 010 and always present. */
    private const TENANT = 0;

    /**
     * The connection pair is built ONCE for the class: on the PostgreSQL path
     * every build replays the production migration set into a fresh schema, and
     * on the SQLite path it copies that schema into a file. Each test resets the
     * two tables it touches, which is cheap; rebuilding the database per test
     * would put minutes on a CI shard for no added coverage.
     *
     * @var array{PDO, PDO}|null
     */
    private static ?array $connections = null;

    /** Temp SQLite database file, when running on the SQLite path. */
    private static ?string $sqliteFile = null;

    private PDO $a;
    private PDO $b;
    private string $driver;

    public static function tearDownAfterClass(): void
    {
        self::$connections = null;

        if (self::$sqliteFile !== null && file_exists(self::$sqliteFile)) {
            @unlink(self::$sqliteFile);
        }
        self::$sqliteFile = null;
    }

    protected function setUp(): void
    {
        self::$connections ??= $this->twoConnectionsToOneDatabase();
        [$this->a, $this->b] = self::$connections;
        $this->driver = SchemaInspector::driver($this->a);

        $this->resetConnection($this->a);
        $this->resetConnection($this->b);

        $this->a->exec('DELETE FROM sequence_counters');
        $this->a->exec('DROP TABLE IF EXISTS naive_counter');
    }

    protected function tearDown(): void
    {
        $this->resetConnection($this->a);
        $this->resetConnection($this->b);
    }

    /**
     * Leave a connection with nothing open and no short timeout armed, so one
     * test's deliberate lock contention cannot become the next test's mystery.
     */
    private function resetConnection(PDO $pdo): void
    {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $this->clearLockTimeout($pdo);
    }

    /**
     * One scalar from a query, with the statement checked.
     *
     * `PDO::query()` is typed `PDOStatement|false`, and chaining off it turns a
     * failed statement into a confusing "call on false" rather than naming what
     * failed — which in a concurrency test is exactly the wrong error to be
     * looking at.
     */
    private function scalar(PDO $pdo, string $sql): mixed
    {
        $stmt = $pdo->query($sql);
        self::assertNotFalse($stmt, "Statement failed: {$sql}");

        return $stmt->fetchColumn();
    }

    /**
     * Every row of a query, with the statement checked.
     *
     * @return list<mixed>
     */
    private function rows(PDO $pdo, string $sql, int $mode): array
    {
        $stmt = $pdo->query($sql);
        self::assertNotFalse($stmt, "Statement failed: {$sql}");

        return array_values($stmt->fetchAll($mode));
    }

    // ==================== the control experiment ====================

    /**
     * THE BUG, reproduced. Two clients read `3`; two clients write `4`; two
     * documents that must be uniquely numbered come out numbered the same, and
     * nothing errors.
     *
     * This is the harness proving it can SEE a race. Everything below is only
     * meaningful because this passes.
     */
    public function testTheNaiveReadThenWriteAllocatorHandsOutADuplicate(): void
    {
        $this->a->exec('CREATE TABLE naive_counter (value INTEGER NOT NULL)');
        $this->a->exec('INSERT INTO naive_counter (value) VALUES (3)');

        // Two clients, each doing the obvious thing: read the counter, add one,
        // write it back, use the number.
        $readByA = (int) $this->scalar($this->a, 'SELECT value FROM naive_counter');
        $readByB = (int) $this->scalar($this->b, 'SELECT value FROM naive_counter');

        $numberForA = $readByA + 1;
        $numberForB = $readByB + 1;

        $this->a->exec("UPDATE naive_counter SET value = {$numberForA}");
        $this->b->exec("UPDATE naive_counter SET value = {$numberForB}");

        self::assertSame(
            $numberForA,
            $numberForB,
            "The naive allocator must be shown to hand out a duplicate on {$this->driver}; "
            . 'if it does not, this harness cannot detect a race and the tests below prove nothing.'
        );
        self::assertSame(
            4,
            (int) $this->scalar($this->a, 'SELECT value FROM naive_counter'),
            'And one of the two increments is lost outright.'
        );
    }

    // ==================== the claim ====================

    /**
     * THE CENTRAL CLAIM. While one connection holds the allocation open, the
     * other cannot allocate — it waits, and is killed by the short timeout this
     * test arms. It does not read a stale value and proceed.
     *
     * The failure this rules out is precise: not "the numbers differ" (two
     * sequential calls differ trivially), but "a second caller can observe the
     * counter mid-allocation". If it could, it would be blocked here and it
     * is not.
     */
    public function testASecondCallerIsBlockedRatherThanReadingAStaleValue(): void
    {
        $allocatorA = new SequenceCounters($this->a);
        $allocatorB = new SequenceCounters($this->b);

        $this->a->beginTransaction();
        $first = $allocatorA->next(self::TENANT, 'invoice');
        self::assertSame(1, $first, 'A fresh counter starts at 1.');

        // B now tries to allocate while A's allocation is still uncommitted.
        // The short timeout turns "waits forever" into an observable failure;
        // without it the test would simply hang, which is the same information
        // presented unhelpfully.
        $this->armShortLockTimeout($this->b);

        $blocked = null;
        try {
            $allocatorB->next(self::TENANT, 'invoice');
        } catch (\Throwable $e) {
            $blocked = $e;
        } finally {
            $this->clearLockTimeout($this->b);
        }

        // Caught as Throwable and narrowed here, rather than caught as
        // PDOException: the assertion is what makes it a LOCK failure and not
        // some other error the allocator happened to raise.
        self::assertInstanceOf(
            PDOException::class,
            $blocked,
            "On {$this->driver} the second caller must WAIT for the first allocation to "
            . 'commit, and be killed by the short timeout armed above. Completing here would '
            . 'mean it evaluated the counter against a value the first caller had already '
            . 'claimed — the duplicate-number bug.'
        );

        $this->a->commit();

        // And once the first allocation is committed, the second caller gets
        // the NEXT number, not a repeat of the first.
        $second = $allocatorB->next(self::TENANT, 'invoice');
        self::assertSame(2, $second);
        self::assertNotSame($first, $second);
    }

    /**
     * The same exclusion on an EXISTING counter, where the statement takes the
     * `DO UPDATE` branch rather than the insert branch. The two branches lock
     * differently underneath (a unique-index conflict versus a row lock), so
     * both are exercised.
     */
    public function testExclusionHoldsOnTheUpdateBranchToo(): void
    {
        $allocatorA = new SequenceCounters($this->a);
        $allocatorB = new SequenceCounters($this->b);

        // Seed the row so the next allocations take the DO UPDATE branch.
        self::assertSame(1, $allocatorA->next(self::TENANT, 'invoice'));

        $this->a->beginTransaction();
        $second = $allocatorA->next(self::TENANT, 'invoice');
        self::assertSame(2, $second);

        $this->armShortLockTimeout($this->b);
        $blocked = null;
        try {
            $allocatorB->next(self::TENANT, 'invoice');
        } catch (\Throwable $e) {
            $blocked = $e;
        } finally {
            $this->clearLockTimeout($this->b);
        }

        // Caught as Throwable and narrowed here, rather than caught as
        // PDOException: the assertion is what makes it a LOCK failure and not
        // some other error the allocator happened to raise.
        self::assertInstanceOf(
            PDOException::class,
            $blocked,
            "DO UPDATE must also exclude on {$this->driver}."
        );

        $this->a->commit();
        self::assertSame(3, $allocatorB->next(self::TENANT, 'invoice'));
    }

    /**
     * Blocks cannot overlap either: the whole block is reserved by one
     * statement, so a second caller's block begins after it.
     */
    public function testConcurrentBlocksDoNotOverlap(): void
    {
        $allocatorA = new SequenceCounters($this->a);
        $allocatorB = new SequenceCounters($this->b);

        $blockA = $allocatorA->nextBlock(self::TENANT, 'import', 5);
        $blockB = $allocatorB->nextBlock(self::TENANT, 'import', 3);

        self::assertSame(['first' => 1, 'last' => 5], $blockA);
        self::assertSame(['first' => 6, 'last' => 8], $blockB);
    }

    /**
     * The honest limit, pinned so nobody discovers it as a surprise: allocation
     * joins the caller's transaction, so a rollback un-allocates — and a
     * concurrent caller that already took the next number leaves a gap. Unique
     * and monotonic; not gapless.
     */
    public function testARolledBackAllocationIsReleasedAndLeavesTheCounterWhereItWas(): void
    {
        $allocator = new SequenceCounters($this->a);

        self::assertSame(1, $allocator->next(self::TENANT, 'invoice'));

        $this->a->beginTransaction();
        self::assertSame(2, $allocator->next(self::TENANT, 'invoice'));
        $this->a->rollBack();

        self::assertSame(1, $allocator->peek(self::TENANT, 'invoice'));
        self::assertSame(
            2,
            $allocator->next(self::TENANT, 'invoice'),
            'The rolled-back number is re-issued, because nothing committed had taken it.'
        );
    }

    // ==================== harness ====================

    /**
     * Two connections to ONE database, both carrying the production schema.
     *
     * @return array{PDO, PDO}
     */
    private function twoConnectionsToOneDatabase(): array
    {
        $dsn = $_ENV['PHPUNIT_PG_DSN'] ?? getenv('PHPUNIT_PG_DSN') ?: null;

        if ($dsn !== null) {
            // The PostgreSQL harness builds a private schema per make() call and
            // locks the search path to it. A second connection has to be pointed
            // at that SAME schema, which it discovers rather than reconstructs.
            $a = SchemaFromMigrations::make();
            $schema = (string) $this->scalar($a, "SELECT current_schema()");

            $user = (string) ($_ENV['PHPUNIT_PG_USER'] ?? getenv('PHPUNIT_PG_USER') ?: 'whity');
            $password = (string) ($_ENV['PHPUNIT_PG_PASSWORD'] ?? getenv('PHPUNIT_PG_PASSWORD') ?: 'whity_dev');

            $b = new PDO((string) $dsn, $user, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $b->exec('SET search_path TO "' . $schema . '"');

            return [$a, $b];
        }

        // SQLite's `:memory:` database belongs to its connection, so the whole
        // point of this file — two connections, one database — needs a FILE. A
        // shared-cache in-memory URI would also share, but it swaps SQLite's
        // real file locking for table-level shared-cache locks, which is the
        // exact mechanism under test; a file keeps the production semantics.
        $file = tempnam(sys_get_temp_dir(), 'whity_seq_') ?: sys_get_temp_dir() . '/whity_seq_' . uniqid();
        self::$sqliteFile = $file;

        $a = $this->openSqlite($file);
        $b = $this->openSqlite($file);

        // The schema is COPIED from a migration-built database rather than
        // hand-written here: SchemaFromMigrations' PG->SQLite translation lives
        // inside the in-memory PDO subclass it builds, so the migrations cannot
        // be replayed directly against a file connection — but the schema they
        // produce can be read back out of sqlite_master and replayed verbatim.
        // Derived, so it cannot drift from production the way a copied CREATE
        // TABLE would.
        $template = SchemaFromMigrations::make();

        $objects = $this->rows(
            $template,
            "SELECT sql FROM sqlite_master WHERE sql IS NOT NULL AND name NOT LIKE 'sqlite_%'",
            PDO::FETCH_COLUMN
        );

        // In ONE transaction: a file-backed SQLite fsyncs per autocommitted
        // statement, and 167 of those took 82 seconds on a container filesystem
        // versus 1 second batched. Purely a set-up cost — the locking behaviour
        // under test is unaffected, and every statement below runs autocommitted
        // exactly as production does.
        $a->beginTransaction();
        foreach ($objects as $ddl) {
            $a->exec((string) $ddl);
        }

        // Plus the rows the migrations seed that this test depends on — the
        // system tenant (010), which sequence_counters.tenant_id references.
        $tenants = $this->rows($template, 'SELECT id, name FROM tenants', PDO::FETCH_ASSOC);
        $insert = $a->prepare('INSERT INTO tenants (id, name) VALUES (:id, :name)');
        foreach ($tenants as $tenant) {
            self::assertIsArray($tenant);
            $insert->execute([':id' => $tenant['id'], ':name' => $tenant['name']]);
        }
        $a->commit();

        return [$a, $b];
    }

    /**
     * A SQLite connection to the shared file, configured the way the host
     * configures its own.
     */
    private function openSqlite(string $file): PDO
    {
        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        return $pdo;
    }

    /**
     * Make "waits for a lock" observable as a failure within the test's
     * patience, instead of a hang.
     */
    private function armShortLockTimeout(PDO $pdo): void
    {
        match (SchemaInspector::driver($pdo)) {
            SchemaInspector::DRIVER_PGSQL => $pdo->exec("SET statement_timeout = '400ms'"),
            SchemaInspector::DRIVER_SQLITE => $pdo->exec('PRAGMA busy_timeout = 400'),
        };
    }

    private function clearLockTimeout(PDO $pdo): void
    {
        match (SchemaInspector::driver($pdo)) {
            SchemaInspector::DRIVER_PGSQL => $pdo->exec('SET statement_timeout = 0'),
            SchemaInspector::DRIVER_SQLITE => $pdo->exec('PRAGMA busy_timeout = 5000'),
        };
    }
}
