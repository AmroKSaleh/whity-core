<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * WC-91f2: integration tests for DatabaseSharedStore.
 *
 * Replays the SharedStoreInterface behavioural contract against a real SQLite
 * engine built from the full migration set, proving that DatabaseSharedStore
 * honours the same semantics as ArraySharedStore.
 */
final class DatabaseSharedStoreTest extends TestCase
{
    private PDO $pdo;
    private DatabaseSharedStore $store;

    protected function setUp(): void
    {
        $this->pdo   = SchemaFromMigrations::make(true);
        $this->store = new DatabaseSharedStore($this->pdo);
    }

    // ── increment ────────────────────────────────────────────────────────────

    public function testFirstIncrementReturnsOne(): void
    {
        self::assertSame(1, $this->store->increment('k1', 60));
    }

    public function testSubsequentIncrementsCountUp(): void
    {
        $this->store->increment('k1', 60);
        $this->store->increment('k1', 60);
        self::assertSame(3, $this->store->increment('k1', 60));
    }

    public function testIncrementOnExpiredKeyResetsToOne(): void
    {
        $this->store->increment('k1', -1);
        self::assertSame(1, $this->store->increment('k1', 60));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $this->store->increment('a', 60);
        $this->store->increment('a', 60);
        $this->store->increment('b', 60);

        self::assertSame(2, $this->store->count('a'));
        self::assertSame(1, $this->store->count('b'));
    }

    // ── count ────────────────────────────────────────────────────────────────

    public function testCountReturnZeroForMissingKey(): void
    {
        self::assertSame(0, $this->store->count('missing'));
    }

    public function testCountReflectsCurrentValue(): void
    {
        $this->store->increment('k1', 60);
        $this->store->increment('k1', 60);
        self::assertSame(2, $this->store->count('k1'));
    }

    public function testCountReturnsZeroForExpiredKey(): void
    {
        $this->store->increment('k1', -1);
        self::assertSame(0, $this->store->count('k1'));
    }

    public function testCountDoesNotModifyTheValue(): void
    {
        $this->store->increment('k1', 60);
        $this->store->count('k1');
        $this->store->count('k1');
        self::assertSame(1, $this->store->count('k1'));
    }

    // ── delete ───────────────────────────────────────────────────────────────

    public function testDeleteEvictsKey(): void
    {
        $this->store->increment('k1', 60);
        $this->store->delete('k1');
        self::assertSame(0, $this->store->count('k1'));
    }

    public function testDeleteOnMissingKeyIsNoOp(): void
    {
        $this->store->delete('ghost');
        self::assertSame(0, $this->store->count('ghost'));
    }

    public function testDeleteOnlyRemovesTargetKey(): void
    {
        $this->store->increment('k1', 60);
        $this->store->increment('k2', 60);
        $this->store->delete('k1');

        self::assertSame(0, $this->store->count('k1'));
        self::assertSame(1, $this->store->count('k2'));
    }

    // ── prune ────────────────────────────────────────────────────────────────

    public function testPruneRemovesExpiredEntries(): void
    {
        $this->store->increment('live',    60);
        $this->store->increment('expired', -1);

        $removed = $this->store->prune();

        self::assertSame(1, $removed);
        self::assertSame(1, $this->store->count('live'));
        self::assertSame(0, $this->store->count('expired'));
    }

    public function testPruneReturnsZeroWhenNothingExpired(): void
    {
        $this->store->increment('live', 60);
        self::assertSame(0, $this->store->prune());
    }

    public function testPruneRemovesMultipleExpiredEntries(): void
    {
        $this->store->increment('e1', -1);
        $this->store->increment('e2', -1);
        $this->store->increment('live', 60);

        self::assertSame(2, $this->store->prune());
        self::assertSame(0, $this->store->count('e1'));
        self::assertSame(0, $this->store->count('e2'));
        self::assertSame(1, $this->store->count('live'));
    }

    public function testIncrementAfterDeleteStartsFresh(): void
    {
        $this->store->increment('k1', 60);
        $this->store->increment('k1', 60);
        $this->store->delete('k1');
        self::assertSame(1, $this->store->increment('k1', 60));
    }

    // ── schema ───────────────────────────────────────────────────────────────

    public function testSharedStoreTableExists(): void
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='shared_store'"
        );
        self::assertNotFalse($stmt);
        self::assertNotFalse($stmt->fetch());
    }
}
