<?php

declare(strict_types=1);

namespace Tests\Integration\Scheduler;

use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Scheduler\ScheduledJobRepository;

/**
 * Real-engine tests for {@see ScheduledJobRepository} (WC-scheduler): idempotent
 * register/upsert, tenant-scoped CRUD (no cross-tenant leak), and the
 * system-infra due-claim + markRan tick primitives.
 */
final class ScheduledJobRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private ScheduledJobRepository $repo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->repo = new ScheduledJobRepository($this->pdo);
    }

    public function testRegisterComputesNextRunAndUpsertsByName(): void
    {
        $id = $this->repo->register(self::TENANT_A, 'cleanup', '0 0 * * *', ['k' => 'v'], ['queue' => 'maint']);
        self::assertGreaterThan(0, $id);

        $row = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($row);
        self::assertSame('cleanup', $row['name']);
        self::assertSame('0 0 * * *', $row['cron_expression']);
        self::assertSame(['k' => 'v'], $row['payload']);
        self::assertSame('maint', $row['queue']);
        self::assertTrue($row['enabled']);
        self::assertNotNull($row['next_run_at']);
        self::assertNull($row['last_run_at']);

        // Re-register the same (tenant, name) → upsert, SAME id, cron updated.
        $id2 = $this->repo->register(self::TENANT_A, 'cleanup', '*/30 * * * *');
        self::assertSame($id, $id2, 'register is an idempotent upsert by (tenant, name)');
        $updated = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($updated);
        self::assertSame('*/30 * * * *', $updated['cron_expression']);
    }

    public function testRegisterRejectsInvalidCron(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repo->register(self::TENANT_A, 'bad', 'not a cron');
    }

    public function testFindAndListAreTenantScoped(): void
    {
        $idA = $this->repo->register(self::TENANT_A, 'a', '0 0 * * *');
        $this->repo->register(self::TENANT_B, 'b', '0 0 * * *');

        self::assertNotNull($this->repo->find(self::TENANT_A, $idA));
        self::assertNull($this->repo->find(self::TENANT_B, $idA), "another tenant's schedule is invisible");

        self::assertCount(1, $this->repo->listForTenant(self::TENANT_A));
        self::assertCount(1, $this->repo->listForTenant(self::TENANT_B));
    }

    public function testSetEnabledAndDeleteAreTenantScoped(): void
    {
        $id = $this->repo->register(self::TENANT_A, 'a', '0 0 * * *');

        self::assertFalse($this->repo->setEnabled(self::TENANT_B, $id, false), 'cannot toggle another tenant\'s schedule');
        self::assertTrue($this->repo->setEnabled(self::TENANT_A, $id, false));
        $disabled = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($disabled);
        self::assertFalse($disabled['enabled']);

        self::assertFalse($this->repo->delete(self::TENANT_B, $id), 'cannot delete another tenant\'s schedule');
        self::assertTrue($this->repo->delete(self::TENANT_A, $id));
        self::assertNull($this->repo->find(self::TENANT_A, $id));
    }

    public function testClaimDueReturnsOnlyEnabledDueRowsAcrossTenants(): void
    {
        $dueA = $this->repo->register(self::TENANT_A, 'due-a', '0 0 * * *');
        $dueB = $this->repo->register(self::TENANT_B, 'due-b', '0 0 * * *');
        $this->repo->register(self::TENANT_A, 'future', '0 0 * * *'); // stays in the future → never claimed
        $disabled = $this->repo->register(self::TENANT_A, 'disabled', '0 0 * * *', [], ['enabled' => false]);

        // Make dueA/dueB/disabled due (backdate next_run_at); leave `future` in the future.
        $this->backdate($dueA);
        $this->backdate($dueB);
        $this->backdate($disabled);

        $claimed = $this->repo->claimDue(gmdate('Y-m-d H:i:s'));
        $names = array_map(static fn (array $r): string => $r['name'], $claimed);

        self::assertContains('due-a', $names);
        self::assertContains('due-b', $names, 'the tick claims across tenants');
        self::assertNotContains('future', $names, 'a not-yet-due schedule is excluded');
        self::assertNotContains('disabled', $names, 'a disabled schedule is never claimed');
        // Each claim carries its origin tenant.
        $tenantByName = [];
        foreach ($claimed as $r) {
            $tenantByName[$r['name']] = $r['tenant_id'];
        }
        self::assertSame(self::TENANT_A, $tenantByName['due-a']);
        self::assertSame(self::TENANT_B, $tenantByName['due-b']);
    }

    public function testMarkRanRecordsLastAndAdvancesNext(): void
    {
        $id = $this->repo->register(self::TENANT_A, 'a', '0 0 * * *');
        $this->repo->markRan($id, '2026-01-05 00:00:00', '2026-01-06 00:00:00');

        $row = $this->repo->find(self::TENANT_A, $id);
        self::assertNotNull($row);
        self::assertSame('2026-01-05 00:00:00', $row['last_run_at']);
        self::assertSame('2026-01-06 00:00:00', $row['next_run_at']);
    }

    private function backdate(int $id): void
    {
        $this->pdo->prepare('UPDATE scheduled_jobs SET next_run_at = :past WHERE id = :id')
            ->execute([':past' => gmdate('Y-m-d H:i:s', time() - 3600), ':id' => $id]);
    }
}
