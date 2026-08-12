<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Database\SequenceCounters;
use Whity\Sdk\Schema\SchemaInspector;
use Whity\Sdk\Sql\Upsert;

/**
 * {@see Upsert} and {@see SequenceCounters}, exercised against whichever engine
 * the harness provides — SQLite in the sharded `test` job, real PostgreSQL in
 * the sharded `postgres-integration` job.
 *
 * The concurrency claim is proven separately, in
 * {@see SequenceAllocatorConcurrencyTest}, with two connections and a chosen
 * interleaving. This file covers the single-caller behaviour that must simply
 * be the same on both engines: what the statement returns, what a `DO NOTHING`
 * conflict yields, and that a counter is scoped to its tenant.
 */
final class PortableUpsertDualEngineTest extends TestCase
{
    /** The system tenant, created by migration 010 and always present. */
    private const TENANT = 0;

    /**
     * A second tenant, created by this test.
     *
     * It has to be a REAL tenant row, not just a different integer:
     * `sequence_counters.tenant_id` carries a foreign key to `tenants`, which
     * PostgreSQL enforces and SQLite (foreign_keys off by default) does not —
     * so an invented id passes on one engine and fails on the other. Exactly
     * the class of divergence this file exists to catch, caught here first.
     */
    private const OTHER_TENANT = 4242;

    private static ?PDO $shared = null;

    private PDO $pdo;
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

        $this->pdo->exec('DELETE FROM sequence_counters');
        $this->pdo->exec(
            'INSERT INTO tenants (id, name) VALUES (' . self::OTHER_TENANT . ", 'pu-dual-engine-tenant')"
            . ' ON CONFLICT DO NOTHING'
        );
        $this->pdo->exec('DROP TABLE IF EXISTS pu_items');
        $this->pdo->exec(
            'CREATE TABLE pu_items (
                id INTEGER NOT NULL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                client_uuid VARCHAR(36) NOT NULL,
                name VARCHAR(100),
                seen INTEGER NOT NULL DEFAULT 0
            )'
        );
        $this->pdo->exec('CREATE UNIQUE INDEX idx_pu_items_key ON pu_items(tenant_id, client_uuid)');
    }

    protected function tearDown(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS pu_items');
    }

    /**
     * `PDO::query()` is typed `PDOStatement|false`, and a test that chains
     * straight off it reports a confusing "call on false" instead of naming the
     * statement that failed.
     */
    private function countRows(string $table): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
        self::assertNotFalse($stmt, "Could not count rows in {$table}.");

        return (int) $stmt->fetchColumn();
    }

    // ==================== upsert ====================

    public function testInsertThenUpdateReturnsTheSameRowOnBothEngines(): void
    {
        $first = Upsert::tenantScoped(
            $this->pdo,
            'pu_items',
            self::TENANT,
            ['id' => 1, 'client_uuid' => 'u-1', 'name' => 'first'],
            ['client_uuid'],
            ['name']
        );
        self::assertIsArray($first, $this->driver);
        self::assertSame('first', $first['name']);

        $second = Upsert::tenantScoped(
            $this->pdo,
            'pu_items',
            self::TENANT,
            ['id' => 999, 'client_uuid' => 'u-1', 'name' => 'second'],
            ['client_uuid'],
            ['name']
        );
        self::assertIsArray($second, $this->driver);
        self::assertSame('second', $second['name']);
        self::assertSame(
            1,
            (int) $second['id'],
            'The conflict updated the existing row; `id` was not in the update list, so it kept its value.'
        );

        self::assertSame(1, $this->countRows('pu_items'));
    }

    public function testDoNothingReturnsNothingOnBothEngines(): void
    {
        // Worth pinning on both: a caller that treats null as failure would
        // retry forever on PostgreSQL and on SQLite alike.
        Upsert::tenantScoped(
            $this->pdo,
            'pu_items',
            self::TENANT,
            ['id' => 1, 'client_uuid' => 'u-1', 'name' => 'first'],
            ['client_uuid'],
            []
        );

        $skipped = Upsert::tenantScoped(
            $this->pdo,
            'pu_items',
            self::TENANT,
            ['id' => 2, 'client_uuid' => 'u-1', 'name' => 'ignored'],
            ['client_uuid'],
            []
        );

        self::assertNull($skipped, $this->driver);
        self::assertSame(1, $this->countRows('pu_items'));
    }

    public function testNamedReturningColumnsComeBackOnBothEngines(): void
    {
        $row = Upsert::tenantScoped(
            $this->pdo,
            'pu_items',
            self::TENANT,
            ['id' => 1, 'client_uuid' => 'u-1', 'name' => 'first'],
            ['client_uuid'],
            ['name'],
            ['id', 'name']
        );

        self::assertIsArray($row);
        self::assertSame(['id', 'name'], array_keys($row), $this->driver);
    }

    public function testTheSameKeyUnderAnotherTenantIsASeparateRowOnBothEngines(): void
    {
        // The scenario an unscoped conflict target would turn into silent
        // cross-tenant data loss.
        Upsert::tenantScoped(
            $this->pdo,
            'pu_items',
            self::TENANT,
            ['id' => 1, 'client_uuid' => 'shared', 'name' => 'system'],
            ['client_uuid'],
            ['name']
        );
        Upsert::tenantScoped(
            $this->pdo,
            'pu_items',
            self::OTHER_TENANT,
            ['id' => 2, 'client_uuid' => 'shared', 'name' => 'other'],
            ['client_uuid'],
            ['name']
        );

        self::assertSame(2, $this->countRows('pu_items'), $this->driver);

        $stmt = $this->pdo->prepare('SELECT name FROM pu_items WHERE tenant_id = :t AND client_uuid = :u');
        $stmt->execute([':t' => self::TENANT, ':u' => 'shared']);
        self::assertSame('system', $stmt->fetchColumn());
    }

    // ==================== sequence allocator ====================

    public function testCounterStartsAtOneAndAdvancesOnBothEngines(): void
    {
        $allocator = new SequenceCounters($this->pdo);

        self::assertSame(0, $allocator->peek(self::TENANT, 'invoice'), 'An unused counter reads 0.');
        self::assertSame(1, $allocator->next(self::TENANT, 'invoice'), $this->driver);
        self::assertSame(2, $allocator->next(self::TENANT, 'invoice'), $this->driver);
        self::assertSame(2, $allocator->peek(self::TENANT, 'invoice'), 'peek() must not allocate.');
    }

    public function testCountersAreScopedPerTenantAndPerNameOnBothEngines(): void
    {
        $allocator = new SequenceCounters($this->pdo);

        self::assertSame(1, $allocator->next(self::TENANT, 'invoice'));
        self::assertSame(1, $allocator->next(self::TENANT, 'quote'), 'A different name is a different counter.');
        self::assertSame(1, $allocator->next(self::OTHER_TENANT, 'invoice'), 'A different tenant is a different counter.');

        self::assertSame(2, $allocator->next(self::TENANT, 'invoice'));
        self::assertSame(1, $allocator->peek(self::OTHER_TENANT, 'invoice'), "The other tenant's counter did not move.");
    }

    public function testBlockAllocationReservesAContiguousRangeOnBothEngines(): void
    {
        $allocator = new SequenceCounters($this->pdo);

        self::assertSame(['first' => 1, 'last' => 10], $allocator->nextBlock(self::TENANT, 'import', 10));
        self::assertSame(11, $allocator->next(self::TENANT, 'import'), 'The next single allocation follows the block.');
    }

    public function testPlatformWideCounterLivesUnderTheSystemTenantOnBothEngines(): void
    {
        $allocator = new SequenceCounters($this->pdo);

        self::assertSame(1, $allocator->nextPlatformWide('change_seq'));
        self::assertSame(2, $allocator->nextPlatformWide('change_seq'));

        // Stored in the same tenant-predicated table as everything else, under
        // the system tenant — not in a second, unpoliceable global table.
        self::assertSame(2, $allocator->peek(0, 'change_seq'), $this->driver);
    }

    public function testAStepOfMoreThanOneAdvancesByThatMuchOnBothEngines(): void
    {
        $allocator = new SequenceCounters($this->pdo);

        self::assertSame(5, $allocator->next(self::TENANT, 'invoice', 5));
        self::assertSame(8, $allocator->next(self::TENANT, 'invoice', 3));
    }

    /**
     * @dataProvider badArguments
     */
    public function testMalformedArgumentsAreRefused(string $name, int $step): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new SequenceCounters($this->pdo))->next(self::TENANT, $name, $step);
    }

    /**
     * @return array<string, array{string, int}>
     */
    public static function badArguments(): array
    {
        return [
            'uppercase name' => ['Invoice', 1],
            'name with a space' => ['invoice number', 1],
            'name starting with a digit' => ['1invoice', 1],
            'empty name' => ['', 1],
            // A counter that can go backwards re-issues numbers it has already
            // handed out, which is the bug this class exists to prevent.
            'zero step' => ['invoice', 0],
            'negative step' => ['invoice', -1],
        ];
    }
}
