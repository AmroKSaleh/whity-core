<?php

declare(strict_types=1);

namespace Tests\Core\DataType;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\DataType\DataTypeLifecycleService;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\LifecycleResult;
use Whity\Core\Tenant\TableOwnershipRegistry;

/**
 * WC-723 Door 2, against a genuine SQL engine (SQLite in CI; real PostgreSQL
 * when PHPUNIT_PG_DSN is set), seeded from the REAL migrations plus two
 * plugin-owned tables.
 *
 * Three things are pinned here, and they are the three the issue says matter:
 *
 *  1. a declared guard REFUSES a delete that would orphan referencing rows —
 *     the enforcement that today every plugin hand-writes, inconsistently;
 *  2. TRASHED and RETIRED are distinguishable states with different semantics,
 *     not two spellings of "soft deleted";
 *  3. every statement binds a tenant predicate, so a record in another tenant
 *     is absent rather than reachable.
 *
 * Deliberately exercised through the SERVICE rather than a mock: the guard is a
 * COUNT over the referencing table, so a test that stubs the database proves
 * nothing about whether the count is scoped, filtered or even correct.
 */
final class DataTypeLifecycleRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TYPE = 'acme:record';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        // Two plugin-owned tables. No foreign key between them — that is the
        // convention this guard exists to compensate for.
        $this->pdo->exec('
            CREATE TABLE acme_records (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
        $this->pdo->exec('
            CREATE TABLE acme_entries (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                record_id INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
    }

    // ==================== 1. A guard refuses a delete that would orphan rows ====================

    public function testADeclaredGuardRefusesADeleteThatWouldOrphanReferencingRows(): void
    {
        $recordId = $this->seedRecord(self::TENANT_A, 'guarded', 'trashed');
        $this->seedEntry(self::TENANT_A, $recordId);

        $service = $this->service();
        $result = $service->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(LifecycleResult::BLOCKED, $result->outcome());
        self::assertSame(409, $result->httpStatus());
        self::assertSame(
            [['table' => 'acme_entries', 'label' => 'recorded entries', 'count' => 1]],
            $result->blockers(),
            'The refusal must carry the plugin-declared label — core never learns what an entry IS, '
            . 'only what to call it.'
        );
        self::assertStringContainsString('1 recorded entries', $result->message());

        self::assertSame(
            1,
            $this->countRecords(),
            'A blocked delete must leave the row exactly where it was.'
        );
    }

    public function testTheSameGuardPermitsTheDeleteOnceNothingReferencesTheRecord(): void
    {
        $recordId = $this->seedRecord(self::TENANT_A, 'guarded', 'trashed');
        $entryId = $this->seedEntry(self::TENANT_A, $recordId);

        $service = $this->service();
        self::assertFalse($service->canDelete(self::TYPE, self::TENANT_A, $recordId));

        $this->pdo->prepare('DELETE FROM acme_entries WHERE id = ?')->execute([$entryId]);

        self::assertTrue($service->canDelete(self::TYPE, self::TENANT_A, $recordId));
        self::assertTrue($service->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame(0, $this->countRecords());
    }

    public function testAReferencingRowThatIsItselfTrashedDoesNotBlock(): void
    {
        // Without `ignore_when` a trashed child would pin its parent forever and
        // the guard would be a leak rather than a protection.
        $recordId = $this->seedRecord(self::TENANT_A, 'guarded', 'trashed');
        $this->seedEntry(self::TENANT_A, $recordId, 'trashed');

        self::assertSame([], $this->service()->blockingReferences(self::TYPE, self::TENANT_A, $recordId));
        self::assertTrue($this->service()->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());
    }

    public function testAReferencingRowInANOTHERTenantDoesNotBlock(): void
    {
        // The guard is a COUNT; an unscoped one would let another tenant's rows
        // veto this tenant's delete — and disclose their existence by doing so.
        $recordId = $this->seedRecord(self::TENANT_A, 'guarded', 'trashed');
        $this->seedEntry(self::TENANT_B, $recordId);

        self::assertSame([], $this->service()->blockingReferences(self::TYPE, self::TENANT_A, $recordId));
        self::assertTrue($this->service()->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());
    }

    public function testDeleteCannotSkipTheTrashOnATrashableType(): void
    {
        // Closes the bypass the issue names explicitly: a delete path that never
        // passes through the reversible state.
        $recordId = $this->seedRecord(self::TENANT_A, 'live', 'active');

        $result = $this->service()->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(LifecycleResult::REFUSED, $result->outcome());
        self::assertSame('trash_before_deleting', $result->reason());
        self::assertSame(1, $this->countRecords());
    }

    public function testGuardEvaluationIsReachableThroughTheSdkContract(): void
    {
        // A plugin keeping its own delete route must reach the SAME evaluator,
        // or the escape hatch becomes a second, divergent enforcement path.
        $recordId = $this->seedRecord(self::TENANT_A, 'guarded', 'trashed');
        $this->seedEntry(self::TENANT_A, $recordId);

        $guard = $this->service();
        self::assertInstanceOf(\Whity\Sdk\DataType\DataTypeGuard::class, $guard);
        self::assertSame(
            [['table' => 'acme_entries', 'label' => 'recorded entries', 'count' => 1]],
            $guard->blockingReferences(self::TYPE, self::TENANT_A, $recordId)
        );
    }

    // ==================== 2. Retired is not trashed ====================

    public function testTrashedIsReversibleButRetiredIsNot(): void
    {
        $trashed = $this->seedRecord(self::TENANT_A, 'mistake', 'active');
        $retired = $this->seedRecord(self::TENANT_A, 'finished', 'active');
        $service = $this->service();

        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $trashed)->isOk());
        self::assertTrue($service->retire(self::TYPE, self::TENANT_A, $retired)->isOk());

        self::assertSame('trashed', $service->stateOf(self::TYPE, self::TENANT_A, $trashed));
        self::assertSame('retired', $service->stateOf(self::TYPE, self::TENANT_A, $retired));

        // The trashed record comes back.
        $restored = $service->restore(self::TYPE, self::TENANT_A, $trashed);
        self::assertTrue($restored->isOk());
        self::assertSame('active', $restored->state());

        // The retired one does not, and the refusal says why.
        $refused = $service->restore(self::TYPE, self::TENANT_A, $retired);
        self::assertSame(LifecycleResult::REFUSED, $refused->outcome());
        self::assertSame('retirement_is_permanent', $refused->reason());
        self::assertSame(
            'retired',
            $service->stateOf(self::TYPE, self::TENANT_A, $retired),
            'A refused restore must not move the record.'
        );
    }

    public function testARetiredRecordIsNeverDeletableEvenWhenNothingReferencesIt(): void
    {
        // This is the sharpest difference. An unreferenced TRASHED record is
        // removable; an unreferenced RETIRED record is still permanent, because
        // what retirement protects is not the row's referents but the record of
        // what happened.
        $retired = $this->seedRecord(self::TENANT_A, 'finished', 'active');
        $service = $this->service();
        $service->retire(self::TYPE, self::TENANT_A, $retired);

        self::assertSame([], $service->blockingReferences(self::TYPE, self::TENANT_A, $retired));
        self::assertFalse($service->canDelete(self::TYPE, self::TENANT_A, $retired));

        $result = $service->delete(self::TYPE, self::TENANT_A, $retired);
        self::assertSame(LifecycleResult::REFUSED, $result->outcome());
        self::assertSame('retired_records_are_permanent', $result->reason());
        self::assertSame(1, $this->countRecords());

        // …whereas the trashed equivalent, equally unreferenced, goes.
        $trashed = $this->seedRecord(self::TENANT_A, 'mistake', 'trashed');
        self::assertTrue($service->canDelete(self::TYPE, self::TENANT_A, $trashed));
    }

    public function testARetiredRecordCannotBeTrashed(): void
    {
        $retired = $this->seedRecord(self::TENANT_A, 'finished', 'retired');

        $result = $this->service()->trash(self::TYPE, self::TENANT_A, $retired);

        self::assertSame(LifecycleResult::REFUSED, $result->outcome());
        self::assertSame('retired_records_cannot_be_trashed', $result->reason());
        self::assertSame('retired', $this->service()->stateOf(self::TYPE, self::TENANT_A, $retired));
    }

    public function testATrashedRecordCannotBeRetiredWithoutBeingRestoredFirst(): void
    {
        $trashed = $this->seedRecord(self::TENANT_A, 'mistake', 'trashed');

        $result = $this->service()->retire(self::TYPE, self::TENANT_A, $trashed);

        self::assertSame(LifecycleResult::REFUSED, $result->outcome());
        self::assertSame('restore_before_retiring', $result->reason());
    }

    public function testBothStatesCloseTheRecordToNewReferencesButOnlyOneIsPendingRemoval(): void
    {
        $trashed = $this->seedRecord(self::TENANT_A, 'mistake', 'trashed');
        $retired = $this->seedRecord(self::TENANT_A, 'finished', 'retired');
        $live = $this->seedRecord(self::TENANT_A, 'live', 'active');
        $service = $this->service();

        // The one axis on which they AGREE.
        self::assertFalse($service->isReferenceable(self::TYPE, self::TENANT_A, $trashed));
        self::assertFalse($service->isReferenceable(self::TYPE, self::TENANT_A, $retired));
        self::assertTrue($service->isReferenceable(self::TYPE, self::TENANT_A, $live));

        // The axes on which they DIFFER.
        $trashedView = $service->describe(self::TYPE, self::TENANT_A, $trashed);
        $retiredView = $service->describe(self::TYPE, self::TENANT_A, $retired);
        self::assertIsArray($trashedView);
        self::assertIsArray($retiredView);

        self::assertTrue($trashedView['pending_removal']);
        self::assertFalse($retiredView['pending_removal']);
        self::assertTrue($trashedView['restorable']);
        self::assertFalse($retiredView['restorable']);
        self::assertTrue($trashedView['deletable']);
        self::assertFalse($retiredView['deletable']);
    }

    public function testTransitionsAreIdempotent(): void
    {
        $recordId = $this->seedRecord(self::TENANT_A, 'thing', 'active');
        $service = $this->service();

        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame('trashed', $service->stateOf(self::TYPE, self::TENANT_A, $recordId));

        self::assertTrue($service->restore(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertTrue($service->restore(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame('active', $service->stateOf(self::TYPE, self::TENANT_A, $recordId));

        self::assertTrue($service->retire(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertTrue($service->retire(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame('retired', $service->stateOf(self::TYPE, self::TENANT_A, $recordId));
    }

    // ==================== 3. Tenant isolation ====================

    public function testARecordInAnotherTenantIsReportedAsAbsentNotForbidden(): void
    {
        $recordId = $this->seedRecord(self::TENANT_B, 'theirs', 'active');
        $service = $this->service();

        self::assertNull($service->stateOf(self::TYPE, self::TENANT_A, $recordId));
        self::assertNull($service->describe(self::TYPE, self::TENANT_A, $recordId));
        self::assertSame(
            LifecycleResult::NOT_FOUND,
            $service->trash(self::TYPE, self::TENANT_A, $recordId)->outcome()
        );
        self::assertSame(
            'active',
            $service->stateOf(self::TYPE, self::TENANT_B, $recordId),
            'The other tenant\'s record must be untouched.'
        );
    }

    public function testADeleteCannotReachAcrossTenants(): void
    {
        $recordId = $this->seedRecord(self::TENANT_B, 'theirs', 'trashed');

        self::assertSame(
            LifecycleResult::NOT_FOUND,
            $this->service()->delete(self::TYPE, self::TENANT_A, $recordId)->outcome()
        );
        self::assertSame(1, $this->countRecords());
    }

    // ==================== Unknown / unoffered ====================

    public function testAnUnknownTypeAnswersNothingRatherThanThrowing(): void
    {
        $service = $this->service();

        self::assertNull($service->stateOf('nope:nothing', self::TENANT_A, 1));
        self::assertSame([], $service->blockingReferences('nope:nothing', self::TENANT_A, 1));
        self::assertFalse($service->canDelete('nope:nothing', self::TENANT_A, 1));
        self::assertSame(
            LifecycleResult::UNSUPPORTED,
            $service->trash('nope:nothing', self::TENANT_A, 1)->outcome()
        );
    }

    public function testAnActionWithNoDeclaredPermissionIsUnsupportedRatherThanUngated(): void
    {
        $recordId = $this->seedRecord(self::TENANT_A, 'thing', 'active');
        // A registry whose type declares a retirable lifecycle but NO retire
        // permission: the action must not run.
        $service = $this->service(['read' => 'acme:read', 'trash' => 'acme:manage']);

        $result = $service->retire(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(LifecycleResult::UNSUPPORTED, $result->outcome());
        self::assertSame(405, $result->httpStatus());
        self::assertSame('active', $service->stateOf(self::TYPE, self::TENANT_A, $recordId));
    }

    // ==================== Helpers ====================

    /**
     * @param array<string, string>|null $permissions Override the declared permissions.
     */
    private function service(?array $permissions = null): DataTypeLifecycleService
    {
        $tables = new TableOwnershipRegistry();
        $tables->register('Acme', [
            'acme_records' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_entries' => TableOwnershipRegistry::SCOPE_TENANT,
        ]);

        $registry = new DataTypeRegistry($tables);
        $registry->register('Acme', [
            'record' => [
                'table' => 'acme_records',
                'key' => 'id',
                'tenant_column' => 'tenant_id',
                'label' => ['en' => 'Record'],
                'lifecycle' => [
                    'column' => 'status',
                    'states' => ['draft', 'active', 'retired', 'trashed'],
                    'default_state' => 'active',
                    'trashable' => true,
                    'retirable' => true,
                ],
                'blocks_delete' => [
                    [
                        'table' => 'acme_entries',
                        'column' => 'record_id',
                        'label' => 'recorded entries',
                        'ignore_when' => ['status' => ['trashed']],
                    ],
                ],
                'permissions' => $permissions ?? [
                    'read' => 'acme:read',
                    'trash' => 'acme:manage',
                    'restore' => 'acme:manage',
                    'retire' => 'acme:retire',
                    'delete' => 'acme:manage',
                ],
            ],
        ]);

        return new DataTypeLifecycleService($this->pdo, $registry);
    }

    private function seedRecord(int $tenantId, string $name, string $status): int
    {
        $this->pdo->prepare('INSERT INTO acme_records (tenant_id, name, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $name, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedEntry(int $tenantId, int $recordId, string $status = 'active'): int
    {
        $this->pdo->prepare('INSERT INTO acme_entries (tenant_id, record_id, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $recordId, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function countRecords(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM acme_records');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
