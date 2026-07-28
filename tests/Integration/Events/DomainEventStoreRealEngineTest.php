<?php

declare(strict_types=1);

namespace Tests\Integration\Events;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Events\DomainEventStore;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for the durable event spine (#154): append writes the
 * immutable event + its pending outbox row atomically (joining the caller's
 * transaction when there is one — the transactional-outbox guarantee), and the
 * relay drain reserve/markRelayed/fail/reclaim behave like the jobs queue.
 */
final class DomainEventStoreRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private DomainEventStore $store;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $this->store = new DomainEventStore($this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testAppendWritesEventAndPendingOutboxRowAndReturnsUlid(): void
    {
        $id = $this->store->append(self::TENANT_A, 'user.created.async', ['id' => 42, 'email' => 'a@x.test'], [
            'aggregate_type' => 'user',
            'aggregate_id'   => '42',
            'actor_user_id'  => 7,
        ]);

        self::assertSame(26, strlen($id), 'append returns the new ULID');

        $event = $this->fetchOne('SELECT * FROM domain_events WHERE id = :id', [':id' => $id]);
        self::assertNotNull($event);
        self::assertSame(self::TENANT_A, (int) $event['tenant_id']);
        self::assertSame('user.created.async', $event['event_name']);
        self::assertSame('user', $event['aggregate_type']);
        self::assertSame('42', (string) $event['aggregate_id']);
        self::assertSame(7, (int) $event['actor_user_id']);
        self::assertSame(['id' => 42, 'email' => 'a@x.test'], json_decode((string) $event['payload'], true), 'payload round-trips');

        $outbox = $this->fetchOne('SELECT * FROM event_outbox WHERE event_id = :id', [':id' => $id]);
        self::assertNotNull($outbox);
        self::assertSame('pending', $outbox['status']);
        self::assertSame(self::TENANT_A, (int) $outbox['tenant_id'], 'outbox denormalises the event tenant');
    }

    public function testAppendWithoutMetaDefaultsAggregateAndActorToNull(): void
    {
        $id = $this->store->append(self::TENANT_A, 'system.ping', ['n' => 1]);

        $event = $this->fetchOne('SELECT * FROM domain_events WHERE id = :id', [':id' => $id]);
        self::assertNotNull($event);
        self::assertNull($event['aggregate_type']);
        self::assertNull($event['aggregate_id']);
        self::assertNull($event['actor_user_id']);
    }

    public function testAppendJoinsAnOpenTransactionSoRollbackDropsBothRows(): void
    {
        // The transactional-outbox contract: when the caller owns a transaction,
        // append must NOT commit on its own — a rollback of the business write
        // must also erase the event + its outbox row.
        $this->pdo->beginTransaction();
        $id = $this->store->append(self::TENANT_A, 'order.placed', ['x' => 1]);
        self::assertTrue($this->pdo->inTransaction(), 'append did not steal/close the caller transaction');
        $this->pdo->rollBack();

        self::assertNull($this->fetchOne('SELECT * FROM domain_events WHERE id = :id', [':id' => $id]));
        self::assertNull($this->fetchOne('SELECT * FROM event_outbox WHERE event_id = :id', [':id' => $id]));
    }

    public function testReserveClaimsPendingEventWithItsContentAndTenant(): void
    {
        $id = $this->store->append(self::TENANT_B, 'tenant.deleted.async', ['id' => 2], ['aggregate_type' => 'tenant', 'aggregate_id' => '2']);

        $claim = $this->store->reserve();
        self::assertNotNull($claim);
        self::assertSame($id, $claim['event_id']);
        self::assertSame(self::TENANT_B, $claim['tenant_id'], 'relay learns the origin tenant to restore');
        self::assertSame('tenant.deleted.async', $claim['event_name']);
        self::assertSame(['id' => 2], $claim['payload']);
        self::assertSame(1, $claim['attempts'], 'claim consumes an attempt');

        $outbox = $this->fetchOne('SELECT status FROM event_outbox WHERE event_id = :id', [':id' => $id]);
        self::assertNotNull($outbox);
        self::assertSame('reserved', $outbox['status']);
    }

    public function testReserveReturnsNullWhenNothingPending(): void
    {
        self::assertNull($this->store->reserve());
    }

    public function testMarkRelayedMovesOutboxToRelayedAndKeepsTheImmutableEvent(): void
    {
        $id = $this->store->append(self::TENANT_A, 'role.created.async', []);
        $this->store->reserve();

        $this->store->markRelayed($id);

        $outbox = $this->fetchOne('SELECT status, relayed_at FROM event_outbox WHERE event_id = :id', [':id' => $id]);
        self::assertNotNull($outbox);
        self::assertSame('relayed', $outbox['status']);
        self::assertNotNull($outbox['relayed_at']);
        self::assertNotNull($this->fetchOne('SELECT id FROM domain_events WHERE id = :id', [':id' => $id]), 'the event log row is never removed');
    }

    public function testFailBelowMaxAttemptsReschedulesWithBackoffAndIsNotImmediatelyDue(): void
    {
        $id = $this->store->append(self::TENANT_A, 'ou.updated.async', []);
        $claim = $this->store->reserve();
        self::assertNotNull($claim);

        $this->store->fail($id, $claim['attempts'], $claim['max_attempts'], 60, 'transport down');

        $outbox = $this->fetchOne('SELECT status, last_error FROM event_outbox WHERE event_id = :id', [':id' => $id]);
        self::assertNotNull($outbox);
        self::assertSame('pending', $outbox['status']);
        self::assertSame('transport down', $outbox['last_error']);
        self::assertNull($this->store->reserve(), 'the backed-off row is not due yet');
    }

    public function testFailAtMaxAttemptsDeadLetters(): void
    {
        $id = $this->store->append(self::TENANT_A, 'ou.deleted.async', []);

        // Simulate the final attempt: attempts == max_attempts.
        $this->store->fail($id, 25, 25, 60, 'permanent failure');

        $outbox = $this->fetchOne('SELECT status FROM event_outbox WHERE event_id = :id', [':id' => $id]);
        self::assertNotNull($outbox);
        self::assertSame('dead', $outbox['status']);
    }

    public function testReclaimExpiredReturnsStuckReservedRowsToPending(): void
    {
        $id = $this->store->append(self::TENANT_A, 'user.created.async', []);
        $this->store->reserve(); // now 'reserved' with reserved_at = now

        // Backdate the lease so it is demonstrably expired. reclaimExpired floors
        // visibility at 1s (like the jobs reaper), so a just-reserved row is never
        // reclaimed — only one whose reserved_at predates the cutoff.
        $past = date('Y-m-d H:i:s', time() - 400);
        $backdate = $this->pdo->prepare('UPDATE event_outbox SET reserved_at = :t WHERE event_id = :id');
        $backdate->execute([':t' => $past, ':id' => $id]);

        $reclaimed = $this->store->reclaimExpired(300);
        self::assertSame(1, $reclaimed);

        $outbox = $this->fetchOne('SELECT status FROM event_outbox WHERE event_id = :id', [':id' => $id]);
        self::assertNotNull($outbox);
        self::assertSame('pending', $outbox['status']);
        self::assertNotNull($this->store->reserve(), 'a reclaimed event is claimable again');
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    private function fetchOne(string $sql, array $params): ?array
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }
}
