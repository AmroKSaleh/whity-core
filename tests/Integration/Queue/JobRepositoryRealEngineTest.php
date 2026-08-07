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

    public function testMarkCompletedRemovesATransientJob(): void
    {
        $id = (int) $this->repo->enqueue(self::TENANT_A, 'x', []);
        $this->repo->reserve();
        $this->repo->markCompleted($id);
        self::assertSame(0, $this->countJobs(), 'a fire-and-forget job leaves no row');
    }

    public function testMarkCompletedRetainsAResultJobAsCompleted(): void
    {
        $id = (int) $this->repo->enqueue(self::TENANT_A, 'x', [], ['retain_result' => true]);
        $this->repo->reserve();
        $this->repo->markCompleted($id, ['answer' => 42]);

        $job = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($job, 'a retained job is kept, not deleted');
        self::assertSame('completed', $job['status']);
        self::assertSame(100, $job['progress']);
        self::assertSame(['answer' => 42], $job['result']);
        self::assertNotNull($job['completed_at']);
        self::assertNull($this->repo->reserve(), 'a completed job is not re-claimable');
    }

    public function testFindIsTenantScopedAndDoesNotLeakAcrossTenants(): void
    {
        $id = (int) $this->repo->enqueue(self::TENANT_A, 'x', ['a' => 1], ['retain_result' => true]);

        $own = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($own);
        self::assertSame(['a' => 1], $own['payload']);

        self::assertNull($this->repo->find(self::TENANT_B, $id), "another tenant's job is invisible (no cross-tenant leak)");
        self::assertNull($this->repo->find(self::TENANT_A, 999999), 'a missing id is null');
    }

    public function testFindByIdempotencyKeyIsTenantScoped(): void
    {
        $this->repo->enqueue(self::TENANT_A, 'x', [], ['idempotency_key' => 'k-1', 'retain_result' => true]);

        $found = $this->repo->findByIdempotencyKey(self::TENANT_A, 'k-1');
        self::assertNotNull($found);

        self::assertNull($this->repo->findByIdempotencyKey(self::TENANT_B, 'k-1'), 'key lookup is tenant-scoped');
        self::assertNull($this->repo->findByIdempotencyKey(self::TENANT_A, 'nope'));
    }

    public function testListAndCountAreTenantScopedAndFilterAndPaginate(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->repo->enqueue(self::TENANT_A, 'x', ['i' => $i], ['queue' => 'emails']);
        }
        $this->repo->enqueue(self::TENANT_A, 'x', [], ['queue' => 'default']);
        $this->repo->enqueue(self::TENANT_B, 'x', []); // another tenant — must never appear

        self::assertSame(6, $this->repo->countForTenant(self::TENANT_A, null, null));
        self::assertSame(5, $this->repo->countForTenant(self::TENANT_A, 'emails', null));
        self::assertSame(6, $this->repo->countForTenant(self::TENANT_A, null, 'pending'));

        // Newest first (id DESC), page size 2.
        $page1 = $this->repo->listForTenant(self::TENANT_A, null, null, 2, 0);
        self::assertCount(2, $page1);
        self::assertGreaterThan($page1[1]['id'], $page1[0]['id'], 'ordered newest-first');

        $page2 = $this->repo->listForTenant(self::TENANT_A, null, null, 2, 2);
        self::assertCount(2, $page2);
        self::assertLessThan($page1[1]['id'], $page2[0]['id']);

        // Tenant B sees only its own.
        self::assertSame(1, $this->repo->countForTenant(self::TENANT_B, null, null));
    }

    public function testPruneCompletedRemovesOnlyOldCompletedJobs(): void
    {
        $old = (int) $this->repo->enqueue(self::TENANT_A, 'x', [], ['retain_result' => true]);
        $this->repo->reserve();
        $this->repo->markCompleted($old, []);
        // Age the completion well past the retention window.
        $this->pdo->prepare('UPDATE jobs SET completed_at = :old WHERE id = :id')
            ->execute([':old' => date('Y-m-d H:i:s', time() - 7200), ':id' => $old]);

        $fresh = (int) $this->repo->enqueue(self::TENANT_A, 'x', [], ['retain_result' => true]);
        $this->repo->reserve();
        $this->repo->markCompleted($fresh, []); // completed just now

        $pending = (int) $this->repo->enqueue(self::TENANT_A, 'x', []); // still pending

        self::assertSame(1, $this->repo->pruneCompleted(3600), 'only the old completed job is pruned');
        self::assertNull($this->repo->find(self::TENANT_A, $old), 'the aged completed job is gone');
        self::assertNotNull($this->repo->find(self::TENANT_A, $fresh), 'a recently completed job is kept');
        self::assertNotNull($this->repo->find(self::TENANT_A, $pending), 'a pending job is never pruned');
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
