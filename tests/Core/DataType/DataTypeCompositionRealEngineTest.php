<?php

declare(strict_types=1);

namespace Tests\Core\DataType;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\DataType\DataTypeLifecycleService;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\LifecycleResult;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TableOwnershipRegistry;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * A record's PARTS go with it — the orphaning an adopter proved in production.
 *
 * The defect
 * ----------
 * `DataTypeLifecycleService::delete()` ran exactly one statement:
 *
 *     DELETE FROM <table> WHERE <key> AND <tenant>
 *
 * `blocks_delete` declares what must OUTLIVE a record. Nothing declared what
 * dies WITH it, and with no foreign keys between plugin tables — the convention
 * here, and the reason declared guards exist at all — nothing at the database
 * level removed the children either. So deleting a record through the core route
 * left its own child rows behind, pointing at an id that no longer resolves, in
 * a state no screen lists and no guard protects. It reported 200.
 *
 * Why this is asserted against a real engine and read back with SQL
 * ----------------------------------------------------------------
 * The failure mode is precisely "the response and the database disagree", so a
 * 200 proves nothing here and neither does a mocked PDO. Every assertion below
 * counts rows with its own query. The cascade is also a multi-statement
 * transaction whose whole promise is atomicity, and a stubbed connection would
 * assert only that the code calls the methods it calls.
 *
 * What is pinned
 * --------------
 *  1. the composition is deleted, and NOTHING else is — not a sibling's rows,
 *     not another tenant's, not a table nobody declared;
 *  2. the cascade is refused rather than performed half-way whenever it would
 *     defeat a guarantee core makes elsewhere (nesting, a retired part, a part
 *     somebody's guard protects);
 *  3. it is atomic with the parent delete and with the pre-transition hook, and
 *     joins a transaction the caller already opened;
 *  4. the preview publishes what would go, and predicts every refusal exactly.
 */
final class DataTypeCompositionRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TYPE = 'acme:record';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        // Four plugin-owned tables, no foreign key anywhere between them.
        $this->pdo->exec('
            CREATE TABLE acme_records (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
        // Rows that must OUTLIVE a record: the `blocks_delete` half.
        $this->pdo->exec('
            CREATE TABLE acme_entries (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                record_id INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
        // Rows that must DIE WITH it: the `cascade_delete` half. Identical shape
        // to the table above, deliberately — nothing but the declaration says
        // which is which.
        $this->pdo->exec('
            CREATE TABLE acme_lines (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                record_id INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
        // Rows pointing at the OWNED table, so a cascade can be shown to defeat
        // somebody else's guard if it is allowed to run.
        $this->pdo->exec('
            CREATE TABLE acme_line_notes (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                line_id INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
    }

    // ==================== 1. The composition goes, and nothing else ====================

    public function testDeletingARecordDeletesTheRowsItOwns(): void
    {
        // THE reported bug. Before `cascade_delete` these three rows survived
        // their parent and nothing said so.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLine(self::TENANT_A, $recordId);

        self::assertTrue($this->service()->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());

        self::assertSame(0, $this->countRecords(), 'The record itself must be gone.');
        self::assertSame(
            0,
            $this->countLines(),
            'And so must the rows it owned. A surviving child points at an id that no longer '
            . 'resolves, which is the orphaning this exists to close.'
        );
    }

    public function testACascadeTouchesOnlyTheRowsTheDeletedRecordOwned(): void
    {
        // The other half of "delete the children": a cascade that over-reaches
        // is a worse bug than the one it fixes, and both are invisible in a 200.
        $doomed = $this->seedRecord(self::TENANT_A, 'doomed', 'trashed');
        $sibling = $this->seedRecord(self::TENANT_A, 'sibling', 'active');
        $this->seedLine(self::TENANT_A, $doomed);
        $siblingLine = $this->seedLine(self::TENANT_A, $sibling);

        self::assertTrue($this->service()->delete(self::TYPE, self::TENANT_A, $doomed)->isOk());

        self::assertSame([$siblingLine], $this->lineIds(), 'Only the sibling\'s line may remain.');
        self::assertSame(1, $this->countRecords());
    }

    public function testACascadeCannotReachAcrossTenants(): void
    {
        // The cascade is the most destructive statement core generates, so an
        // unscoped one would delete another tenant's rows for a record that
        // tenant cannot even see. Both records carry the same key value here, so
        // an unbound tenant predicate would take both sets of lines.
        $this->pdo->exec("INSERT INTO acme_records (id, tenant_id, name, status) VALUES (500, 1, 'ours', 'trashed')");
        $this->pdo->exec("INSERT INTO acme_records (id, tenant_id, name, status) VALUES (501, 2, 'theirs', 'active')");
        $this->pdo->prepare('INSERT INTO acme_lines (tenant_id, record_id) VALUES (?, ?)')
            ->execute([self::TENANT_A, 500]);
        $theirs = $this->seedLine(self::TENANT_B, 500);

        self::assertTrue($this->service()->delete(self::TYPE, self::TENANT_A, 500)->isOk());

        self::assertSame(
            [$theirs],
            $this->lineIds(),
            'A row in another tenant that happens to carry the same record_id must survive.'
        );
    }

    public function testARecordWithNoCompositionIsUnaffected(): void
    {
        // The regression guard: adding a cascade must not change a delete that
        // has nothing to cascade.
        $recordId = $this->seedRecord(self::TENANT_A, 'childless', 'trashed');

        self::assertTrue($this->service()->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame(0, $this->countRecords());
    }

    public function testATypeThatDeclaresNoCompositionStillDeletesExactlyOneRow(): void
    {
        // The pre-existing behaviour for every type that never declares a
        // composition, unchanged and asserted so it stays that way.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $lineId = $this->seedLine(self::TENANT_A, $recordId);

        $service = $this->service(cascade: false);
        self::assertTrue($service->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());

        self::assertSame(0, $this->countRecords());
        self::assertSame(
            [$lineId],
            $this->lineIds(),
            'Undeclared composition is not deleted. Core removes what was declared, never what it '
            . 'guessed.'
        );
    }

    // ==================== 2. Refusals rather than a half-done cascade ====================

    public function testADeleteBlockedByAGuardCascadesNothing(): void
    {
        // The refusal ordering matters: a delete refused for the record's own
        // references must not have already taken the composition with it.
        $recordId = $this->seedRecord(self::TENANT_A, 'guarded', 'trashed');
        $this->seedEntry(self::TENANT_A, $recordId);
        $lineId = $this->seedLine(self::TENANT_A, $recordId);

        $result = $this->service()->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(LifecycleResult::BLOCKED, $result->outcome());
        self::assertSame('still_referenced', $result->reason());
        self::assertSame(1, $this->countRecords());
        self::assertSame([$lineId], $this->lineIds(), 'A refused delete must write nothing at all.');
    }

    public function testARefusedStatePolicyCascadesNothing(): void
    {
        $recordId = $this->seedRecord(self::TENANT_A, 'live', 'active');
        $lineId = $this->seedLine(self::TENANT_A, $recordId);

        $result = $this->service()->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame('trash_before_deleting', $result->reason());
        self::assertSame([$lineId], $this->lineIds());
    }

    public function testAnOwnedRowThatSomebodySGuardProtectsRefusesTheDelete(): void
    {
        // Design question 2, pinned. The owned table is ITSELF a declared type
        // with its own `blocks_delete`. Cascading would delete a row that type's
        // guard exists to protect — defeating a declared guard by approaching it
        // from above, which is exactly the "bypassed through a secondary path"
        // failure declared guards were introduced to end. Core refuses.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $lineId = $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLineNote(self::TENANT_A, $lineId);

        $result = $this->serviceWithOwnedType()->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(LifecycleResult::BLOCKED, $result->outcome());
        self::assertSame(409, $result->httpStatus());
        self::assertSame(
            'composition_still_referenced',
            $result->reason(),
            'A distinct key from `still_referenced`: nothing points at the RECORD, so telling the '
            . 'caller to detach its references would send them looking for something that is not '
            . 'there.'
        );
        self::assertSame(
            [['table' => 'acme_line_notes', 'label' => 'line annotations', 'count' => 1]],
            $result->blockers(),
            'The blockers carry the declaring plugin\'s own label, exactly as they do for the '
            . 'record\'s own references.'
        );
        self::assertStringContainsString('1 line annotations', $result->message());

        self::assertSame(1, $this->countRecords(), 'and nothing was deleted');
        self::assertSame(1, $this->countLines());
    }

    public function testAnOwnedRowThatIsItselfTrashedStillDoesNotBlockThroughIgnoreWhen(): void
    {
        // The guard's own `ignore_when` travels with it: a note that does not
        // pin its line alive must not pin the line's parent alive either, or the
        // filter would hold on one path and leak on the other.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $lineId = $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLineNote(self::TENANT_A, $lineId, 'trashed');

        self::assertTrue($this->serviceWithOwnedType()->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame(0, $this->countLines());
    }

    public function testAGuardedRowInAnotherTenantDoesNotRefuseTheCascade(): void
    {
        // Both halves of the composition check bind their own tenant column. An
        // unscoped one would let another tenant's rows veto this tenant's delete
        // — and disclose their existence by doing so.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $lineId = $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLineNote(self::TENANT_B, $lineId);

        self::assertTrue($this->serviceWithOwnedType()->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame(0, $this->countLines());
    }

    public function testARetiredOwnedRowRefusesTheDelete(): void
    {
        // "A retired record is never deleted" is the strongest promise this
        // lifecycle makes. A cascade that quietly removed one would make it
        // conditional on nobody having declared a composition over its table.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $this->seedLine(self::TENANT_A, $recordId, 'retired');

        $result = $this->serviceWithOwnedType()->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(LifecycleResult::REFUSED, $result->outcome());
        self::assertSame('composition_is_permanent', $result->reason());
        self::assertSame(1, $this->countRecords());
        self::assertSame(1, $this->countLines());
    }

    public function testANestedCompositionIsRefusedRatherThanSilentlyDoneOneLevel(): void
    {
        // Design question 1, pinned. The owned table is itself a type declaring
        // its OWN cascade, so deleting the parent one level down would orphan
        // the level below — the identical bug this mechanism exists to close,
        // moved one step further away and made harder to notice. Core refuses.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $lineId = $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLineNote(self::TENANT_A, $lineId);

        $result = $this->serviceWithNestedType()->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(LifecycleResult::REFUSED, $result->outcome());
        self::assertSame('cascade_would_nest', $result->reason());
        self::assertSame(1, $this->countRecords());
        self::assertSame(1, $this->countLines());
        self::assertSame(1, $this->countLineNotes(), 'The grandchild is the row that would have been orphaned.');
    }

    public function testTheNestingRefusalDoesNotDependOnThereBeingAnyRowsAtAll(): void
    {
        // It is a fact about DECLARATIONS, not about data. Refusing only when a
        // grandchild happens to exist would make the same declaration work or
        // fail depending on what was in the tables that morning.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');

        $result = $this->serviceWithNestedType()->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame('cascade_would_nest', $result->reason());
        self::assertSame(1, $this->countRecords());
    }

    // ==================== 3. Atomicity ====================

    public function testAVetoLeavesTheCompositionExactlyWhereItWas(): void
    {
        // The cascade runs inside the transition's own unit of work, after the
        // pre-transition hook. A veto therefore stops it before a single child
        // row is touched — not "usually", but because the statements have not
        // run when the veto is raised.
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGING,
            static function (array $data): array {
                throw HookVetoException::forEvent(
                    DataTypeLifecycleService::HOOK_CHANGING,
                    'A downstream record depends on this one.'
                );
            }
        );

        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $lineId = $this->seedLine(self::TENANT_A, $recordId);

        $result = $this->service(hooks: $hooks)->delete(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame('blocked_by_plugin', $result->reason());
        self::assertSame(1, $this->countRecords());
        self::assertSame([$lineId], $this->lineIds(), 'A vetoed delete must leave the composition intact.');
    }

    public function testTheCascadeJoinsATransactionTheCallerOpenedRatherThanCommittingInsideIt(): void
    {
        // `transactionally()` joins an outer transaction rather than nesting, so
        // a caller who opened one owns the commit — and their rollback must take
        // the whole cascade with it. A cascade that committed on its own would
        // survive the rollback and leave a record with its parts removed.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLine(self::TENANT_A, $recordId);

        $this->pdo->beginTransaction();
        self::assertTrue($this->service()->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame(0, $this->countLines(), 'Inside the transaction the cascade is visible.');
        $this->pdo->rollBack();

        self::assertSame(1, $this->countRecords(), 'The caller\'s rollback restores the record…');
        self::assertSame(2, $this->countLines(), '…and every row it owned, together.');
    }

    // ==================== 4. The preview ====================

    public function testThePreviewPublishesWhatTheDeleteWouldAlsoRemove(): void
    {
        // A record with four owned rows and a record with none are identical in
        // every other field of this payload, and one of them is about to take
        // four rows with it. Publishing the difference is what lets a
        // confirmation dialog say so instead of destroying them silently.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLine(self::TENANT_A, $recordId);

        $view = $this->service()->describe(self::TYPE, self::TENANT_A, $recordId);
        self::assertIsArray($view);

        self::assertSame(
            [['table' => 'acme_lines', 'label' => 'line items', 'count' => 2]],
            $view['cascade']
        );
        self::assertTrue(
            $view['deletable'],
            'Composition does not make a record undeletable — it is a warning, not a blocker.'
        );
        self::assertSame([], $view['blockers'], 'and it is NOT a blocker: nothing is in the way.');
        self::assertArrayNotHasKey(
            'delete',
            $view['refusals'],
            'and it is NOT a refusal either: the delete is available, it merely takes more with it '
            . 'than the caller might expect.'
        );
    }

    public function testAnEdgeWithNoRowsIsOmittedFromThePreview(): void
    {
        // `cascade: []` means "nothing else goes", which is what a renderer needs
        // to decide whether to warn at all — rather than a list of noughts it
        // has to filter first. Same rule `blockers` follows.
        $view = $this->service()->describe(
            self::TYPE,
            self::TENANT_A,
            $this->seedRecord(self::TENANT_A, 'childless', 'trashed')
        );
        self::assertIsArray($view);

        self::assertSame([], $view['cascade']);
    }

    public function testThePreviewCountsOnlyTheRecordsOwnRowsAndOnlyInThisTenant(): void
    {
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $other = $this->seedRecord(self::TENANT_A, 'other', 'active');
        $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLine(self::TENANT_A, $other);
        $this->seedLine(self::TENANT_B, $recordId);

        $view = $this->service()->describe(self::TYPE, self::TENANT_A, $recordId);
        self::assertIsArray($view);

        self::assertSame(1, $view['cascade'][0]['count']);
    }

    public function testEveryCompositionRefusalIsPredictedExactlyByThePreview(): void
    {
        // The property #731 established, extended to the new causes: one
        // evaluator serves the screen and the endpoint, so a control greyed out
        // for a stated reason and the click that is refused cannot drift into
        // giving different answers. Asserted as a table rather than three
        // one-offs, so a fourth cause cannot be added and quietly skipped.
        $cases = [
            'composition_still_referenced' => function (): array {
                $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
                $this->seedLineNote(self::TENANT_A, $this->seedLine(self::TENANT_A, $recordId));

                return [$this->serviceWithOwnedType(), $recordId];
            },
            'composition_is_permanent' => function (): array {
                $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
                $this->seedLine(self::TENANT_A, $recordId, 'retired');

                return [$this->serviceWithOwnedType(), $recordId];
            },
            'cascade_would_nest' => function (): array {
                $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
                $this->seedLine(self::TENANT_A, $recordId);

                return [$this->serviceWithNestedType(), $recordId];
            },
        ];

        foreach ($cases as $expected => $arrange) {
            [$service, $recordId] = $arrange();

            $view = $service->describe(self::TYPE, self::TENANT_A, $recordId);
            self::assertIsArray($view);
            self::assertFalse($view['deletable'], "A '{$expected}' record is not deletable.");
            self::assertSame($expected, $view['refusals']['delete']['reason'] ?? null);

            $result = $service->delete(self::TYPE, self::TENANT_A, $recordId);
            self::assertSame(
                $result->reason(),
                $view['refusals']['delete']['reason'],
                "The preview of a delete on a '{$expected}' record must match what the transition did."
            );
            self::assertSame($result->message(), $view['refusals']['delete']['message']);
        }
    }

    public function testACompositionRefusalNeverAppearsOnTheOtherThreeActions(): void
    {
        // Composition is about the record's EXISTENCE, not its state. Trashing a
        // record with a retired part is not refused, because nothing is being
        // removed — and a plugin wanting children to follow their parent into
        // the trash has the `changing` hook for it.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'active');
        $this->seedLine(self::TENANT_A, $recordId, 'retired');

        $service = $this->serviceWithOwnedType();
        $view = $service->describe(self::TYPE, self::TENANT_A, $recordId);
        self::assertIsArray($view);

        self::assertArrayNotHasKey('trash', $view['refusals']);
        self::assertArrayNotHasKey('retire', $view['refusals']);
        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertSame(1, $this->countLines(), 'A trash writes nothing to the composition.');
    }

    // ==================== 5. The remembered restore state of owned rows ====================

    public function testHardDeletingARecordForgetsTheRememberedStateOfEveryRowItOwned(): void
    {
        // `data_type_restore_states.record_id` carries no foreign key and never
        // can — the table it points into varies by data type — so no cascade
        // will ever fire for it. A memory left behind for a deleted OWNED row is
        // inherited by whatever row next occupies that key, exactly the id-reuse
        // hazard the memory's own delete-time cleanup answers one level up.
        $recordId = $this->seedRecord(self::TENANT_A, 'parent', 'trashed');
        $lineId = $this->seedLine(self::TENANT_A, $recordId);

        $service = $this->serviceWithOwnedType();
        $service->stateMemory()->remember('acme:line', self::TENANT_A, $lineId, 'active');
        self::assertSame('active', $service->stateMemory()->recall('acme:line', self::TENANT_A, $lineId));

        self::assertTrue($service->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());

        self::assertNull(
            $service->stateMemory()->recall('acme:line', self::TENANT_A, $lineId),
            'The owned row is gone, so its remembered state must be gone with it.'
        );
    }

    // ==================== Helpers ====================

    /**
     * The parent type: guarded by `acme_entries`, composed of `acme_lines`.
     *
     * @param bool             $cascade Whether to declare the composition at all.
     * @param HookManager|null $hooks   Hook spine, when a veto is under test.
     */
    private function service(bool $cascade = true, ?HookManager $hooks = null): DataTypeLifecycleService
    {
        return new DataTypeLifecycleService($this->pdo, $this->registry($cascade), $hooks);
    }

    /**
     * The same parent type, plus `acme:line` over the OWNED table — declared
     * with its own guard and its own retirable lifecycle, which is what gives
     * core something to weigh before cascading.
     */
    private function serviceWithOwnedType(): DataTypeLifecycleService
    {
        $registry = $this->registry(true);
        $registry->register('Acme', [
            'line' => [
                'table' => 'acme_lines',
                'label' => ['en' => 'Line'],
                'lifecycle' => [
                    'column' => 'status',
                    'states' => ['active', 'retired', 'trashed'],
                    'default_state' => 'active',
                    'trashable' => true,
                    'retirable' => true,
                ],
                'blocks_delete' => [
                    [
                        'table' => 'acme_line_notes',
                        'column' => 'line_id',
                        'label' => 'line annotations',
                        'ignore_when' => ['status' => ['trashed']],
                    ],
                ],
                'permissions' => ['read' => 'acme:read', 'delete' => 'acme:manage'],
            ],
        ]);

        return new DataTypeLifecycleService($this->pdo, $registry);
    }

    /**
     * The parent type, plus an `acme:line` that declares a composition OF ITS
     * OWN — the nesting core refuses.
     */
    private function serviceWithNestedType(): DataTypeLifecycleService
    {
        $registry = $this->registry(true);
        $registry->register('Acme', [
            'line' => [
                'table' => 'acme_lines',
                'label' => ['en' => 'Line'],
                'cascade_delete' => [
                    ['table' => 'acme_line_notes', 'column' => 'line_id', 'label' => 'line annotations'],
                ],
                'permissions' => ['read' => 'acme:read', 'delete' => 'acme:manage'],
            ],
        ]);

        return new DataTypeLifecycleService($this->pdo, $registry);
    }

    private function registry(bool $cascade): DataTypeRegistry
    {
        $tables = new TableOwnershipRegistry();
        $tables->register('Acme', [
            'acme_records' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_entries' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_lines' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_line_notes' => TableOwnershipRegistry::SCOPE_TENANT,
        ]);

        $declaration = [
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
            'permissions' => [
                'read' => 'acme:read',
                'trash' => 'acme:manage',
                'restore' => 'acme:manage',
                'retire' => 'acme:retire',
                'delete' => 'acme:manage',
            ],
        ];
        if ($cascade) {
            $declaration['cascade_delete'] = [
                ['table' => 'acme_lines', 'column' => 'record_id', 'label' => 'line items'],
            ];
        }

        $registry = new DataTypeRegistry($tables);
        $registry->register('Acme', ['record' => $declaration]);

        return $registry;
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

    private function seedLine(int $tenantId, int $recordId, string $status = 'active'): int
    {
        $this->pdo->prepare('INSERT INTO acme_lines (tenant_id, record_id, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $recordId, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedLineNote(int $tenantId, int $lineId, string $status = 'active'): int
    {
        $this->pdo->prepare('INSERT INTO acme_line_notes (tenant_id, line_id, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $lineId, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function countRecords(): int
    {
        return $this->countRows('acme_records');
    }

    private function countLines(): int
    {
        return $this->countRows('acme_lines');
    }

    private function countLineNotes(): int
    {
        return $this->countRows('acme_line_notes');
    }

    private function countRows(string $table): int
    {
        $statement = $this->pdo->query("SELECT COUNT(*) FROM {$table}");

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    /**
     * @return list<int>
     */
    private function lineIds(): array
    {
        $statement = $this->pdo->query('SELECT id FROM acme_lines ORDER BY id');
        if ($statement === false) {
            return [];
        }

        return array_map(static fn (mixed $id): int => (int) $id, $statement->fetchAll(PDO::FETCH_COLUMN));
    }
}
