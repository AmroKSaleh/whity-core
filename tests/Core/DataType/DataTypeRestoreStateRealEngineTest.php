<?php

declare(strict_types=1);

namespace Tests\Core\DataType;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\DataType\DataTypeLifecycleService;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TableOwnershipRegistry;

/**
 * A restore returns a record to the state it actually held, not to a fixed one.
 *
 * The bug this pins, reported by an adopter
 * -----------------------------------------
 * `restore()` used to write `$lifecycle->defaultState()` — a CONSTANT — over
 * whatever the record was before it was trashed. Two records both `approved`,
 * both trashed, came back `draft`. For anything carrying an approval gate that
 * is not cosmetic: the record silently re-enters circulation looking unreviewed,
 * and the 200 that reports it reads exactly like a successful undo.
 *
 * The value was never unknown — `trash()` already read it, to publish as the
 * hook's `from`. It was read and then thrown away.
 *
 * Where the answer lives now, and why it is a CORE table
 * -----------------------------------------------------
 * `data_type_restore_states`, keyed by `(tenant_id, data_type, record_id)`.
 * Core does not own plugin tables, so a fix that demanded every adopter add a
 * column to every trashable table would make a core bug into everyone's
 * migration. The side table needs no plugin migration at all, and it is keyed
 * the same way resource-scoped grants are.
 *
 * `record_id` carries no foreign key — the target table varies by data type, so
 * no single FK can express it. Cleanup is therefore core's own job, and the
 * hard-delete case below asserts it AT THE DATABASE, not through an API that
 * would happily report success over a leaked row.
 *
 * Run against a genuine SQL engine (SQLite in CI, real PostgreSQL when
 * PHPUNIT_PG_DSN is set), seeded from the real migrations: the memory row IS a
 * migrated table, and a test that mocked it would prove nothing about whether
 * the migration created it.
 */
final class DataTypeRestoreStateRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TYPE = 'acme:record';

    /** The vocabulary the adopter's type declares. `active` is the default. */
    private const STATES = ['draft', 'archived', 'active', 'retired', 'trashed'];

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $this->pdo->exec('
            CREATE TABLE acme_records (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                name VARCHAR(255) NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
    }

    // ==================== The reported case ====================

    public function testARestoreReturnsTheRecordToTheStateItHeldBeforeBeingTrashed(): void
    {
        // The adopter's control pair, reproduced: two records in the SAME
        // non-default state, both trashed, both restored. Before the fix both
        // came back `active` — the declared default — and the difference between
        // "reviewed" and "not reviewed" was gone with no error anywhere.
        $service = $this->service();
        $first = $this->seedRecord(self::TENANT_A, 'first', 'archived');
        $second = $this->seedRecord(self::TENANT_A, 'second', 'archived');

        foreach ([$first, $second] as $recordId) {
            self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());
            self::assertSame('trashed', $service->stateOf(self::TYPE, self::TENANT_A, $recordId));
        }

        foreach ([$first, $second] as $recordId) {
            $result = $service->restore(self::TYPE, self::TENANT_A, $recordId);

            self::assertTrue($result->isOk());
            self::assertSame(
                'archived',
                $result->state(),
                'A restore is an undo. Returning a fixed state instead of the one the record held '
                . 'is a silent state change wearing the costume of an undo.'
            );
            self::assertSame('archived', $this->statusOf($recordId), 'And the row itself must say so.');
        }
    }

    public function testARecordTrashedFromTheDefaultStateStillComesBackToIt(): void
    {
        // The case that always worked, kept honest: remembering the real state
        // must not perturb the record whose real state IS the default.
        $service = $this->service();
        $recordId = $this->seedRecord(self::TENANT_A, 'plain', 'active');

        $service->trash(self::TYPE, self::TENANT_A, $recordId);
        self::assertSame('active', $service->restore(self::TYPE, self::TENANT_A, $recordId)->state());
    }

    public function testEveryNonTerminalStateSurvivesATrashAndRestoreRoundTrip(): void
    {
        // The property, not one instance of it: a round trip through the trash
        // is the identity function on every state a record may legitimately
        // occupy. A per-state one-off would pass while a sixth state broke it.
        $service = $this->service();

        foreach (['draft', 'archived', 'active'] as $state) {
            $recordId = $this->seedRecord(self::TENANT_A, 'thing', $state);

            $service->trash(self::TYPE, self::TENANT_A, $recordId);
            $service->restore(self::TYPE, self::TENANT_A, $recordId);

            self::assertSame($state, $this->statusOf($recordId), "A '{$state}' record must come back '{$state}'.");
        }
    }

    // ==================== Edge case 1: nothing remembered ====================

    public function testWithNothingRememberedTheRestoreFallsBackToTheDefaultState(): void
    {
        // THE MIGRATION PATH, and therefore documented behaviour rather than an
        // accident: every record already sitting in an adopter's trash when this
        // shipped was trashed before anything could be remembered. There is no
        // honest answer for those beyond the declared default — but "no answer"
        // must degrade to the old behaviour, never to a failure or a null state.
        $recordId = $this->seedRecord(self::TENANT_A, 'legacy', 'trashed');
        self::assertSame(0, $this->countRemembered(), 'Precondition: nothing was ever remembered for it.');

        $result = $this->service()->restore(self::TYPE, self::TENANT_A, $recordId);

        self::assertTrue($result->isOk());
        self::assertSame('active', $result->state());
        self::assertSame('active', $this->statusOf($recordId));
    }

    // ==================== Edge case 2: the remembered state is gone ====================

    public function testARememberedStateTheTypeNoLongerDeclaresFallsBackToTheDefaultState(): void
    {
        // A plugin may change its state vocabulary between the trash and the
        // restore — that is a declaration, not a schema migration, so it can
        // change on any deploy. Writing back a state the type no longer declares
        // would put the row outside its own vocabulary: worse than the bug, and
        // invisible until something later failed to match on it.
        $recordId = $this->seedRecord(self::TENANT_A, 'thing', 'draft');
        $this->service()->trash(self::TYPE, self::TENANT_A, $recordId);
        self::assertSame('draft', $this->rememberedState($recordId));

        // Redeployed without `draft`.
        $narrowed = $this->service(states: ['archived', 'active', 'retired', 'trashed']);
        $result = $narrowed->restore(self::TYPE, self::TENANT_A, $recordId);

        self::assertTrue($result->isOk());
        self::assertSame('active', $result->state());
        self::assertSame('active', $this->statusOf($recordId));
    }

    public function testARememberedStateThatNowMEANSRetiredFallsBackToTheDefaultState(): void
    {
        // Sharper than the case above, and the reason validation is not a bare
        // `in_array()`: the state is still declared, but the type has since
        // promoted it to its RETIRED state. Restoring into it would walk the
        // record into the one state the lifecycle promises is unreachable by
        // restore — through the restore endpoint. The same reasoning covers the
        // trashed state, where a "restore" would silently be a no-op.
        $recordId = $this->seedRecord(self::TENANT_A, 'thing', 'archived');
        $this->service()->trash(self::TYPE, self::TENANT_A, $recordId);
        self::assertSame('archived', $this->rememberedState($recordId));

        $repurposed = $this->service(retiredState: 'archived');
        $result = $repurposed->restore(self::TYPE, self::TENANT_A, $recordId);

        self::assertTrue($result->isOk());
        self::assertSame('active', $result->state());
        self::assertSame(
            'active',
            $this->statusOf($recordId),
            'A restore may never land a record in the state its type now calls retired.'
        );
    }

    // ==================== Edge case 3: idempotence ====================

    public function testRestoringARecordThatIsNotInTheTrashStaysAnIdempotentNoOp(): void
    {
        $service = $this->service();
        $recordId = $this->seedRecord(self::TENANT_A, 'live', 'draft');

        $result = $service->restore(self::TYPE, self::TENANT_A, $recordId);

        self::assertTrue($result->isOk(), 'Unchanged: this has always been a 200 no-op, not a refusal.');
        self::assertSame('draft', $result->state());
        self::assertSame('draft', $this->statusOf($recordId));
        self::assertSame(0, $this->countRemembered(), 'A no-op remembers nothing and forgets nothing.');
    }

    public function testASecondRestoreIsANoOpAndDoesNotReDeriveAState(): void
    {
        $service = $this->service();
        $recordId = $this->seedRecord(self::TENANT_A, 'thing', 'archived');

        $service->trash(self::TYPE, self::TENANT_A, $recordId);
        self::assertSame('archived', $service->restore(self::TYPE, self::TENANT_A, $recordId)->state());
        self::assertSame('archived', $service->restore(self::TYPE, self::TENANT_A, $recordId)->state());
        self::assertSame('archived', $this->statusOf($recordId));
    }

    // ==================== Edge case 4: trash is not re-entrant ====================

    public function testTrashingAnAlreadyTrashedRecordDoesNotClobberTheRememberedState(): void
    {
        // The obvious way to get this wrong: remember the state read at trash
        // time unconditionally. The second trash reads `trashed`, writes it as
        // the memory, and the restore now returns the record to the trash — an
        // undo that undoes nothing, reachable by double-clicking.
        $service = $this->service();
        $recordId = $this->seedRecord(self::TENANT_A, 'thing', 'archived');

        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());

        self::assertSame('archived', $this->rememberedState($recordId), 'The FIRST trash owns the memory.');
        self::assertSame('archived', $service->restore(self::TYPE, self::TENANT_A, $recordId)->state());
    }

    // ==================== Cleanup: a hard delete forgets ====================

    public function testAHardDeleteForgetsTheRememberedStateInTheDatabase(): void
    {
        // Asserted at the DATABASE deliberately. `record_id` carries no foreign
        // key — the target table varies by type — so nothing but this code
        // removes the row, and a 200 from the delete endpoint is not evidence
        // that it did. The consequence of not doing it is the id-reuse hazard
        // the taxonomy delete guard already had to deal with.
        $service = $this->service();
        $recordId = $this->seedRecord(self::TENANT_A, 'doomed', 'archived');

        $service->trash(self::TYPE, self::TENANT_A, $recordId);
        self::assertSame('archived', $this->rememberedState($recordId));

        self::assertTrue($service->delete(self::TYPE, self::TENANT_A, $recordId)->isOk());

        self::assertNull($this->rememberedState($recordId));
        self::assertSame(0, $this->countRemembered(), 'A deleted record leaves nothing behind to inherit.');
    }

    public function testALaterRecordReusingADeletedIdDoesNotInheritItsState(): void
    {
        // What the cleanup is FOR, stated as behaviour: primary keys are reused
        // (SQLite reuses freely; a restored PostgreSQL dump can too), and a
        // stale memory row would hand a brand-new record the state of the one
        // that used to hold its id.
        $service = $this->service();
        $recordId = $this->seedRecord(self::TENANT_A, 'doomed', 'archived');
        $service->trash(self::TYPE, self::TENANT_A, $recordId);
        $service->delete(self::TYPE, self::TENANT_A, $recordId);

        // A new record lands on the same id.
        $this->pdo->prepare('INSERT INTO acme_records (id, tenant_id, name, status) VALUES (?, ?, ?, ?)')
            ->execute([$recordId, self::TENANT_A, 'reused', 'trashed']);

        self::assertSame(
            'active',
            $service->restore(self::TYPE, self::TENANT_A, $recordId)->state(),
            'A new record must fall back to the default, never inherit the dead one\'s state.'
        );
    }

    public function testTheMemoryIsForgottenOnceItHasBeenSpent(): void
    {
        // A consumed memory is stale data: the record is no longer trashed, so
        // the row answers a question nobody may ask again until the next trash
        // writes a fresh one.
        $service = $this->service();
        $recordId = $this->seedRecord(self::TENANT_A, 'thing', 'archived');

        $service->trash(self::TYPE, self::TENANT_A, $recordId);
        self::assertSame(1, $this->countRemembered());

        $service->restore(self::TYPE, self::TENANT_A, $recordId);
        self::assertSame(0, $this->countRemembered());
    }

    // ==================== Tenant isolation ====================

    public function testTheMemoryIsTenantScoped(): void
    {
        // The memory is keyed by (tenant, type, record) and every statement
        // binds the tenant: two tenants whose records share an id must not read
        // each other's remembered state — which would be a cross-tenant read of
        // exactly the kind the platform's #1 invariant forbids.
        $service = $this->service();
        $mine = $this->seedRecord(self::TENANT_A, 'mine', 'archived');
        $this->pdo->prepare('INSERT INTO acme_records (id, tenant_id, name, status) VALUES (?, ?, ?, ?)')
            ->execute([$mine + 1000, self::TENANT_B, 'theirs', 'draft']);
        $theirs = $mine + 1000;

        $service->trash(self::TYPE, self::TENANT_A, $mine);
        $service->trash(self::TYPE, self::TENANT_B, $theirs);

        // Same id, other tenant: the row is absent, so the restore is a 404 and
        // reads nothing.
        self::assertSame('archived', $this->rememberedState($mine, self::TENANT_A));
        self::assertSame('draft', $this->rememberedState($theirs, self::TENANT_B));
        self::assertNull($this->rememberedState($mine, self::TENANT_B));

        self::assertSame('archived', $service->restore(self::TYPE, self::TENANT_A, $mine)->state());
        self::assertSame('draft', $service->restore(self::TYPE, self::TENANT_B, $theirs)->state());
    }

    // ==================== The announcement still tells the truth ====================

    public function testTheHookPayloadReportsTheTransitionThatActuallyHappened(): void
    {
        // `from`/`to` are the durable spine's account of what happened, and the
        // restore's `to` is precisely the value this change alters. A payload
        // still reporting the default while the row got its real state back
        // would leave every subscriber acting on a fiction.
        $payloads = [];
        $hooks = new HookManager();
        $hooks->listen(
            'datatype.lifecycle.changed',
            static function (array $data) use (&$payloads): array {
                $payloads[] = $data;

                return $data;
            }
        );

        $service = $this->service(hooks: $hooks);
        $recordId = $this->seedRecord(self::TENANT_A, 'thing', 'archived');

        $service->trash(self::TYPE, self::TENANT_A, $recordId);
        $service->restore(self::TYPE, self::TENANT_A, $recordId);

        self::assertCount(2, $payloads);

        self::assertSame('trash', $payloads[0]['action']);
        self::assertSame('archived', $payloads[0]['from']);
        self::assertSame('trashed', $payloads[0]['to']);

        self::assertSame('restore', $payloads[1]['action']);
        self::assertSame('trashed', $payloads[1]['from']);
        self::assertSame(
            'archived',
            $payloads[1]['to'],
            'The announcement must name where the record actually went.'
        );
    }

    public function testTheFallbackIsAnnouncedAsTheFallbackAndNotAsTheOriginal(): void
    {
        $payloads = [];
        $hooks = new HookManager();
        $hooks->listen(
            'datatype.lifecycle.changed',
            static function (array $data) use (&$payloads): array {
                $payloads[] = $data;

                return $data;
            }
        );

        // Nothing remembered — the pre-existing-trash path.
        $recordId = $this->seedRecord(self::TENANT_A, 'legacy', 'trashed');
        $this->service(hooks: $hooks)->restore(self::TYPE, self::TENANT_A, $recordId);

        self::assertCount(1, $payloads);
        self::assertSame('trashed', $payloads[0]['from']);
        self::assertSame('active', $payloads[0]['to']);
    }

    // ==================== Helpers ====================

    /**
     * @param list<string>|null $states       Override the declared state vocabulary.
     * @param string|null       $retiredState Override which state means retired.
     */
    private function service(
        ?array $states = null,
        ?string $retiredState = null,
        ?HookManager $hooks = null
    ): DataTypeLifecycleService {
        $tables = new TableOwnershipRegistry();
        $tables->register('Acme', ['acme_records' => TableOwnershipRegistry::SCOPE_TENANT]);

        $registry = new DataTypeRegistry($tables);
        $registry->register('Acme', [
            'record' => [
                'table' => 'acme_records',
                'key' => 'id',
                'tenant_column' => 'tenant_id',
                'label' => ['en' => 'Record'],
                'lifecycle' => [
                    'column' => 'status',
                    'states' => $states ?? self::STATES,
                    'default_state' => 'active',
                    'trashable' => true,
                    'retirable' => true,
                    'trashed_state' => 'trashed',
                    'retired_state' => $retiredState ?? 'retired',
                ],
                'permissions' => [
                    'read' => 'acme:read',
                    'trash' => 'acme:manage',
                    'restore' => 'acme:manage',
                    'retire' => 'acme:retire',
                    'delete' => 'acme:manage',
                ],
            ],
        ]);

        return new DataTypeLifecycleService($this->pdo, $registry, $hooks);
    }

    private function seedRecord(int $tenantId, string $name, string $status): int
    {
        $this->pdo->prepare('INSERT INTO acme_records (tenant_id, name, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $name, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function statusOf(int $recordId): ?string
    {
        $statement = $this->pdo->prepare('SELECT status FROM acme_records WHERE id = ?');
        $statement->execute([$recordId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * Read the remembered state straight out of the core side table — the only
     * assertion that can tell "cleaned up" from "reported success".
     */
    private function rememberedState(int $recordId, int $tenantId = self::TENANT_A): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT state FROM data_type_restore_states
             WHERE tenant_id = ? AND data_type = ? AND record_id = ?'
        );
        $statement->execute([$tenantId, self::TYPE, (string) $recordId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function countRemembered(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM data_type_restore_states');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
