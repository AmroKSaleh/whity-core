<?php

declare(strict_types=1);

namespace Tests\Integration\Queue;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Queue\JobRepository;

/**
 * Real-engine tests for {@see JobRepository} (WC-queue): idempotent enqueue, the
 * atomic reserve/claim (ordering, due-time, once-only), retry-with-backoff,
 * dead-letter, and lease-expiry reclaim. Runs against the migration-built schema
 * (SQLite locally, Postgres in the postgres-integration CI job — where the
 * reserve additionally exercises FOR UPDATE SKIP LOCKED).
 */
final class JobRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private JobRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->repo = new JobRepository($this->pdo);
    }

    public function testEnqueueThenReserveClaimsTheJobWithItsPayloadAndTenant(): void
    {
        $id = $this->repo->enqueue(self::TENANT_A, 'send.email', ['to' => 'x@y.z']);
        self::assertNotNull($id);

        $job = $this->repo->reserve();
        self::assertNotNull($job);
        self::assertSame($id, $job['id']);
        self::assertSame(self::TENANT_A, $job['tenant_id']);
        self::assertSame('send.email', $job['name']);
        self::assertSame(['to' => 'x@y.z'], $job['payload']);
        self::assertSame(1, $job['attempts']); // incremented on claim

        // A reserved job is not claimable again.
        self::assertNull($this->repo->reserve());
    }

    public function testEnqueueIsIdempotentPerTenantKey(): void
    {
        self::assertNotNull($this->repo->enqueue(self::TENANT_A, 'x', [], ['idempotency_key' => 'k1']));
        // Same (tenant, key) → deduped.
        self::assertNull($this->repo->enqueue(self::TENANT_A, 'x', [], ['idempotency_key' => 'k1']));
        // Same key, different tenant → allowed.
        self::assertNotNull($this->repo->enqueue(self::TENANT_B, 'x', [], ['idempotency_key' => 'k1']));
        // Keyless enqueues are never deduped.
        self::assertNotNull($this->repo->enqueue(self::TENANT_A, 'x', []));
        self::assertNotNull($this->repo->enqueue(self::TENANT_A, 'x', []));
    }

    public function testReserveReturnsNullWhenEmptyOrNotYetDue(): void
    {
        self::assertNull($this->repo->reserve(), 'empty queue');

        $this->repo->enqueue(self::TENANT_A, 'later', [], ['delay' => 3600]);
        self::assertNull($this->repo->reserve(), 'delayed job is not yet due');
    }

    public function testReserveClaimsEachJobExactlyOnceInEnqueueOrder(): void
    {
        $a = (int) $this->repo->enqueue(self::TENANT_A, 'first', []);
        $b = (int) $this->repo->enqueue(self::TENANT_A, 'second', []);

        self::assertSame($a, (int) ($this->repo->reserve()['id'] ?? 0));
        self::assertSame($b, (int) ($this->repo->reserve()['id'] ?? 0));
        self::assertNull($this->repo->reserve());
    }

    public function testLowerPriorityNumberIsClaimedFirst(): void
    {
        $low = (int) $this->repo->enqueue(self::TENANT_A, 'low', [], ['priority' => 10]);
        $high = (int) $this->repo->enqueue(self::TENANT_A, 'high', [], ['priority' => 0]);

        self::assertSame($high, (int) ($this->repo->reserve()['id'] ?? 0));
        self::assertSame($low, (int) ($this->repo->reserve()['id'] ?? 0));
    }

    public function testQueuesAreIsolated(): void
    {
        $this->repo->enqueue(self::TENANT_A, 'x', [], ['queue' => 'emails']);
        self::assertNull($this->repo->reserve('default'), 'a job on another queue is not claimed');
        self::assertNotNull($this->repo->reserve('emails'));
    }

    public function testMarkCompletedRemovesTheJob(): void
    {
        $id = (int) $this->repo->enqueue(self::TENANT_A, 'x', []);
        $this->repo->reserve();
        $this->repo->markCompleted($id);
        self::assertSame(0, $this->countJobs());
    }

    public function testRetryReschedulesPendingWithBackoffAndError(): void
    {
        $id = (int) $this->repo->enqueue(self::TENANT_A, 'x', []);
        $this->repo->reserve();
        $this->repo->retry($id, 3600, 'boom');

        // Back to pending but delayed → not immediately claimable.
        self::assertNull($this->repo->reserve());
        $row = $this->row($id);
        self::assertSame('pending', $row['status']);
        self::assertSame('boom', $row['last_error']);
        self::assertNull($row['reserved_at']);
    }

    public function testDeadLetterMarksDeadAndUnclaimable(): void
    {
        $id = (int) $this->repo->enqueue(self::TENANT_A, 'x', []);
        $this->repo->reserve();
        $this->repo->deadLetter($id, 'fatal');

        self::assertNull($this->repo->reserve());
        $row = $this->row($id);
        self::assertSame('dead', $row['status']);
        self::assertSame('fatal', $row['last_error']);
    }

    public function testReclaimExpiredReturnsStuckReservedJobsToPending(): void
    {
        $id = (int) $this->repo->enqueue(self::TENANT_A, 'x', []);
        $this->repo->reserve(); // reserved now

        // A fresh lease is not reclaimed.
        self::assertSame(0, $this->repo->reclaimExpired(60));

        // Simulate a crashed worker: age the lease well past the window.
        $this->pdo->prepare('UPDATE jobs SET reserved_at = :old WHERE id = :id')
            ->execute([':old' => date('Y-m-d H:i:s', time() - 7200), ':id' => $id]);

        self::assertSame(1, $this->repo->reclaimExpired(3600));

        $job = $this->repo->reserve();
        self::assertNotNull($job, 'reclaimed job is claimable again');
        self::assertSame($id, $job['id']);
        self::assertSame(2, $job['attempts'], 'a re-claim consumes another attempt');
    }

    private function countJobs(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM jobs');

        return $stmt === false ? 0 : (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>
     */
    private function row(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM jobs WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? [] : $row;
    }
}
