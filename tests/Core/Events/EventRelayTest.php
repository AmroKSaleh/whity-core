<?php

declare(strict_types=1);

namespace Tests\Core\Events;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Events\DomainEventStore;
use Whity\Core\Events\EventRelay;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;

/**
 * The relay that `dispatchAsync()` never had.
 *
 * `HookManager::dispatchAsync()` persisted an outbox row and ran nothing.
 * `DomainEventStore` carried the entire relay API — reserve, markRelayed, fail,
 * reclaimExpired — and no production code called any of it, so an async event
 * was durably recorded and permanently undelivered (#1063).
 *
 * The growth of `event_outbox` was the visible half. The dangerous half was that
 * a listener bound to an async name would have been written, tested in
 * isolation, merged, and silently done nothing — because everything about the
 * API's shape says "delivered".
 *
 * These run against a real SQLite outbox rather than a mocked store: the
 * claim/retry/dead-letter behaviour IS SQL, and a mock would assert that this
 * test's idea of the store matches itself.
 */
final class EventRelayTest extends TestCase
{
    private PDO $pdo;
    private DomainEventStore $store;
    private HookManager $hooks;
    private EventRelay $relay;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $this->createSchema();

        $this->store = new DomainEventStore($this->pdo);
        $this->hooks = new HookManager();
        $this->relay = new EventRelay($this->store, $this->hooks, null, 30);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function createSchema(): void
    {
        // NOW() is PostgreSQL's; SQLite needs its own spelling, and the store
        // emits NOW() literally. A view-free shim: define NOW() as a function.
        $this->pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));

        $this->pdo->exec(
            'CREATE TABLE domain_events (
                id TEXT PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                event_name TEXT NOT NULL,
                aggregate_type TEXT,
                aggregate_id TEXT,
                actor_user_id INTEGER,
                payload TEXT NOT NULL,
                occurred_at TEXT NOT NULL
            )'
        );
        $this->pdo->exec(
            "CREATE TABLE event_outbox (
                event_id TEXT PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                status TEXT NOT NULL DEFAULT 'pending',
                attempts INTEGER NOT NULL DEFAULT 0,
                max_attempts INTEGER NOT NULL DEFAULT 3,
                available_at TEXT NOT NULL,
                reserved_at TEXT,
                relayed_at TEXT,
                last_error TEXT,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )"
        );
    }

    /** @param array<string, mixed> $payload */
    private function appendEvent(int $tenantId, string $name, array $payload = [], int $maxAttempts = 3): string
    {
        $id = bin2hex(random_bytes(8));
        $now = date('Y-m-d H:i:s');

        $this->pdo->prepare(
            'INSERT INTO domain_events (id, tenant_id, event_name, aggregate_type, aggregate_id, actor_user_id, payload, occurred_at)
             VALUES (:id, :t, :n, NULL, NULL, NULL, :p, :o)'
        )->execute([
            ':id' => $id,
            ':t' => $tenantId,
            ':n' => $name,
            ':p' => json_encode($payload),
            ':o' => $now,
        ]);

        $this->pdo->prepare(
            "INSERT INTO event_outbox (event_id, tenant_id, status, attempts, max_attempts, available_at, created_at, updated_at)
             VALUES (:id, :t, 'pending', 0, :m, :a, :c, :u)"
        )->execute([':id' => $id, ':t' => $tenantId, ':m' => $maxAttempts, ':a' => $now, ':c' => $now, ':u' => $now]);

        return $id;
    }

    /**
     * The outbox row, which must exist.
     *
     * Every caller indexes it immediately, so returning null would turn a
     * missing row into a fatal instead of a named failure. Asserting here also
     * lets the offsets be read directly rather than through a `?? null` that can
     * never fire.
     *
     * @return array<string, mixed>
     */
    private function outboxRow(string $eventId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM event_outbox WHERE event_id = :id');
        $stmt->execute([':id' => $eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        self::assertIsArray($row, "no outbox row for {$eventId}");

        return $row;
    }

    // ── the defect ───────────────────────────────────────────────────────────

    /** THE POINT: a persisted event now reaches its listener. */
    public function testAPersistedEventReachesItsListener(): void
    {
        $seen = [];
        $this->hooks->listen('document.routed.async', static function (array $data) use (&$seen): void {
            $seen[] = $data;
        });

        $this->appendEvent(7, 'document.routed.async', ['id' => 42]);

        self::assertTrue($this->relay->relayNext(), 'an event was due');
        self::assertCount(1, $seen, 'the listener must have run');
        self::assertSame(42, $seen[0]['id'] ?? null);
    }

    public function testARelayedEventIsMarkedRelayedAndNotRepeated(): void
    {
        $ran = 0;
        $this->hooks->listen('thing.happened.async', static function () use (&$ran): void {
            $ran++;
        });

        $id = $this->appendEvent(1, 'thing.happened.async');

        self::assertTrue($this->relay->relayNext());
        self::assertSame('relayed', $this->outboxRow($id)['status']);

        // Nothing left to claim, so the worker is told to sleep rather than spin.
        self::assertFalse($this->relay->relayNext());
        self::assertSame(1, $ran, 'a relayed event must not run twice');
    }

    public function testAnEmptyOutboxReportsNothingToDo(): void
    {
        self::assertFalse($this->relay->relayNext());
    }

    // ── the tenant restore, which is a cross-tenant leak if it is wrong ──────

    /**
     * The relay claims across ALL tenants, so the event's own tenant must be in
     * TenantContext before its listener runs. A listener that read the previous
     * event's tenant would be a cross-tenant leak arriving through the back door
     * of an ordinary-looking event handler.
     */
    public function testTheEventsTenantIsRestoredBeforeItsListenerRuns(): void
    {
        // Each event carries the tenant it was raised in, and the listener
        // records the pair. Asserting PAIRS rather than a sequence because
        // `reserve()` orders by (available_at, event_id) and two events appended
        // in the same second tie on the first — an earlier draft asserted
        // [11, 22] and passed or failed depending on how the random ids sorted.
        // A flaky test is worse than no test, and this is the property anyway:
        // not "in which order", but "did each listener see its own tenant".
        $seen = [];
        $this->hooks->listen('thing.happened.async', static function (array $data) use (&$seen): void {
            $seen[$data['raised_in']] = TenantContext::getTenantId();
        });

        $this->appendEvent(11, 'thing.happened.async', ['raised_in' => 11]);
        $this->appendEvent(22, 'thing.happened.async', ['raised_in' => 22]);

        $this->relay->relayNext();
        $this->relay->relayNext();

        // ksort before assertSame: the pairs are the property, the INSERTION
        // ORDER is not, and assertSame on arrays compares order too. Two events
        // appended in the same second tie on `available_at` and break the tie on
        // a random event id, so which one is claimed first varies per run.
        // Sorting keeps the comparison strict about types while dropping the one
        // thing that legitimately varies.
        ksort($seen);

        self::assertSame(
            [11 => 11, 22 => 22],
            $seen,
            "each listener must see its OWN event's tenant, not the previous one's"
        );
    }

    /** The process's own tenant is put back, so the relay does not leak outwards either. */
    public function testTheCallersTenantIsRestoredAfterwards(): void
    {
        TenantContext::setTenantId(99);
        $this->appendEvent(11, 'thing.happened.async');

        $this->relay->relayNext();

        self::assertSame(99, TenantContext::getTenantId());
    }

    /** And when the caller had none, none is what it gets back. */
    public function testNoTenantIsRestoredWhenTheCallerHadNone(): void
    {
        TenantContext::reset();
        $this->appendEvent(11, 'thing.happened.async');

        $this->relay->relayNext();

        self::assertNull(TenantContext::getTenantId());
    }

    // ── failure: retried, then dead-lettered, never silently dropped ─────────

    public function testAListenerThatThrowsIsRescheduledRatherThanLost(): void
    {
        $this->hooks->listen('thing.happened.async', static function (): void {
            throw new \RuntimeException('listener exploded');
        });

        $id = $this->appendEvent(1, 'thing.happened.async', [], 3);

        self::assertTrue($this->relay->relayNext(), 'the event was claimed');

        $row = $this->outboxRow($id);
        self::assertSame('pending', $row['status'] ?? null, 'a failure must go back to pending, not vanish');
        self::assertStringContainsString('listener exploded', (string) ($row['last_error'] ?? ''));
    }

    /**
     * An \Error is caught too. A listener with a type error raises one, and
     * losing the event to a fatal is exactly the silent drop this replaces.
     */
    public function testAListenerRaisingAnErrorIsAlsoCaught(): void
    {
        $this->hooks->listen('thing.happened.async', static function (): void {
            throw new \TypeError('bad type');
        });

        $id = $this->appendEvent(1, 'thing.happened.async');

        self::assertTrue($this->relay->relayNext());
        self::assertSame('pending', $this->outboxRow($id)['status']);
    }

    public function testAnEventIsDeadLetteredOnceItsAttemptsAreExhausted(): void
    {
        $this->hooks->listen('thing.happened.async', static function (): void {
            throw new \RuntimeException('still broken');
        });

        // max_attempts = 1, so the first failure exhausts it.
        $id = $this->appendEvent(1, 'thing.happened.async', [], 1);

        $this->relay->relayNext();

        $row = $this->outboxRow($id);
        self::assertSame('dead', $row['status'] ?? null, 'an exhausted event is a question for an operator');
        self::assertStringContainsString('still broken', (string) ($row['last_error'] ?? ''));
    }

    /** A failure must not stop the worker: the next event still gets its turn. */
    public function testAFailureDoesNotBlockTheEventsBehindIt(): void
    {
        $delivered = [];
        $this->hooks->listen('bad.async', static function (): void {
            throw new \RuntimeException('nope');
        });
        $this->hooks->listen('good.async', static function (array $data) use (&$delivered): void {
            $delivered[] = $data;
        });

        $this->appendEvent(1, 'bad.async', [], 1);
        $this->appendEvent(1, 'good.async', ['id' => 5]);

        $this->relay->relayNext();
        $this->relay->relayNext();

        self::assertCount(1, $delivered, 'the event behind a failure must still be relayed');
    }

    /**
     * An event nobody listens for is still relayed — it is not an error, and
     * leaving it pending would fill the outbox with events that can never
     * succeed. `HookManager::dispatch()` returning no listeners is a no-op.
     */
    public function testAnEventWithNoListenersIsRelayedRatherThanRetriedForever(): void
    {
        $id = $this->appendEvent(1, 'nobody.listens.async');

        self::assertTrue($this->relay->relayNext());
        self::assertSame('relayed', $this->outboxRow($id)['status']);
    }

    // ── reclaim ──────────────────────────────────────────────────────────────

    /** A worker that died holding an event must not hold it forever. */
    public function testAnAbandonedReservationIsReclaimed(): void
    {
        $id = $this->appendEvent(1, 'thing.happened.async');

        // Claimed, then the worker "dies" without marking it either way.
        $this->store->reserve();
        self::assertSame('reserved', $this->outboxRow($id)['status']);

        $this->pdo->prepare('UPDATE event_outbox SET reserved_at = :old WHERE event_id = :id')
            ->execute([':old' => date('Y-m-d H:i:s', time() - 3600), ':id' => $id]);

        self::assertSame(1, $this->relay->reclaimExpired(300));
        self::assertSame('pending', $this->outboxRow($id)['status']);
    }
}
