<?php

declare(strict_types=1);

namespace Tests\Core\DataType;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\DataType\DataTypeLifecycleService;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\LifecycleResult;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\PluginState;
use Whity\Core\Router;
use Whity\Core\Tenant\TableOwnershipRegistry;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * A plugin can REFUSE a lifecycle transition, not merely watch one happen.
 *
 * The gap this closes, reported by an adopter
 * -------------------------------------------
 * `datatype.lifecycle.changed` fires AFTER the write, with `from` and `to`
 * already decided. It can observe a transition; it can never refuse one. Core's
 * own refusals are the ones core can DERIVE — a declared `blocks_delete` guard
 * counts rows, the state rules know retirement is permanent — and neither
 * reaches a DOMAIN rule: a record something downstream would be unusable
 * without, a per-type rule that a retired record is not trashable, a parent
 * whose children are mid-flight. Those are not foreign keys, and `blocks_delete`
 * governs DELETE rather than trash.
 *
 * So an adopter with such a rule kept their own trash route in front of core's,
 * and paid for it with TWO lifecycle memories for one record: core's
 * `data_type_restore_states` and theirs, disagreeing about which state a restore
 * returns the record to. A silent, correctness-affecting split brain that
 * reports 200 either way. The veto exists to make that parallel route
 * unnecessary, which is why the hook fires for all FOUR mutating actions and not
 * just the one delete guards already covered.
 *
 * What is asserted here, and why at the database
 * ---------------------------------------------
 * A 409 is not evidence that a row is untouched — the whole failure mode being
 * prevented is a response and a database that disagree. Every "unchanged"
 * assertion below therefore reads the row (and the memory table) with its own
 * query rather than believing the result object.
 *
 * Run against a genuine SQL engine (SQLite in CI, real PostgreSQL when
 * PHPUNIT_PG_DSN is set), seeded from the real migrations: `transactionally()`
 * and its rollback are the mechanism under test, and a mocked PDO would assert
 * only that the code calls the methods it calls.
 */
final class DataTypeLifecycleVetoRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TYPE = 'acme:record';

    /** What the vetoing listener says, echoed to the caller verbatim. */
    private const REASON = 'A downstream record depends on this one.';

    private PDO $pdo;

    /** @var list<string> Temporary plugin directories to clean up. */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a')");
        $this->pdo->exec('
            CREATE TABLE acme_records (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
        // Stands in for a plugin\'s private store: no foreign key, no cascade,
        // written only by a listener. The shape a rollback has to cover.
        $this->pdo->exec('
            CREATE TABLE acme_listener_notes (
                id SERIAL PRIMARY KEY,
                note VARCHAR(255) NOT NULL
            )
        ');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $dir) {
            $this->removeDirectory($dir);
        }
    }

    // ==================== 1. Every action can be refused ====================

    /**
     * The headline property, for all FOUR actions: the caller is refused with
     * 409 carrying the veto's own words, and the record is EXACTLY as it was.
     *
     * Asserted per action rather than once for delete, because a veto that works
     * on delete and not on trash leaves the adopter's parallel trash route
     * exactly where it was — which is the thing being removed.
     */
    public function testAVetoRefusesEveryMutatingActionAndLeavesTheRecordUntouched(): void
    {
        foreach (
            [
                'trash' => 'active',
                'restore' => 'trashed',
                'retire' => 'active',
                'delete' => 'trashed',
            ] as $action => $startingState
        ) {
            $hooks = $this->hooksVetoing();
            $service = $this->service($hooks);
            $recordId = $this->seedRecord($startingState);

            $result = $this->invoke($service, $action, $recordId);

            self::assertFalse($result->isOk(), "A vetoed '{$action}' must not report success.");
            self::assertSame(409, $result->httpStatus(), "A vetoed '{$action}' is a 409 Conflict.");
            self::assertSame(
                'blocked_by_plugin',
                $result->reason(),
                'The reason stays a STABLE key clients branch on; the plugin\'s prose goes in the message.'
            );
            self::assertSame(
                self::REASON,
                $result->message(),
                'The veto\'s reason() is surfaced verbatim — it is the only actionable part of the refusal.'
            );
            self::assertSame(
                $startingState,
                $result->state(),
                'The state reported is the one the record still holds, because it never moved.'
            );

            // At the database, not through the result object.
            self::assertSame(
                $startingState,
                $this->statusOf($recordId),
                "A vetoed '{$action}' must leave the row's state exactly as it was."
            );
            self::assertSame(1, $this->countRecords($recordId), 'And the row itself must still exist.');
        }
    }

    /**
     * The veto text reaching the caller is `reason()`, never `getMessage()`.
     *
     * `getMessage()` is raw exception text ("Hook listener vetoed …: …") that
     * the WC-186 leak guard keeps out of client responses; `reason()` is the
     * trimmed, control-character-stripped, length-capped subset a plugin author
     * wrote FOR the client. #715 drew that line deliberately and this pins it,
     * because the two differ by exactly the prefix that would leak.
     */
    public function testTheRefusalCarriesTheVetosReasonAndNotItsRawMessage(): void
    {
        $veto = HookVetoException::forEvent(DataTypeLifecycleService::HOOK_CHANGING, self::REASON);
        $service = $this->service($this->hooksVetoing());
        $recordId = $this->seedRecord('active');

        $message = $service->trash(self::TYPE, self::TENANT_A, $recordId)->message();

        self::assertSame($veto->reason(), $message);
        self::assertNotSame($veto->getMessage(), $message);
        self::assertStringNotContainsString('Hook listener vetoed', $message);
    }

    /**
     * A veto is published as an ordinary REFUSED outcome, in the same envelope
     * as every state refusal — so a client branches on ONE contract rather than
     * learning a second shape for "this did not happen, and here is why".
     */
    public function testTheRefusalUsesTheSameVocabularyAsACoreRefusal(): void
    {
        $service = $this->service($this->hooksVetoing());
        $vetoed = $service->trash(self::TYPE, self::TENANT_A, $this->seedRecord('active'))->toArray();

        // A core refusal on the same surface, for comparison.
        $core = $this->service()->retire(self::TYPE, self::TENANT_A, $this->seedRecord('trashed'))->toArray();

        self::assertSame(array_keys($core), array_keys($vetoed), 'Same fields, in the same shape.');
        self::assertSame('refused', $vetoed['outcome'], 'Same outcome as a state refusal.');
        self::assertSame('refused', $core['outcome']);
        self::assertSame([], $vetoed['blockers'], 'A veto is not a reference and must not fake one.');
        self::assertIsString($vetoed['reason']);
        self::assertNotSame('', $vetoed['message']);
    }

    // ==================== 2. No partial write ====================

    /**
     * The strongest form of "nothing was written": the veto point is BEFORE the
     * transition's own statements, and inside the same transaction as them.
     *
     * A trash writes two things — the memory row and the state column — and a
     * veto must leave neither. A memory row written for a record that never
     * moved is not harmless: it misdirects that record's next real restore.
     */
    public function testAVetoedTrashWritesNeitherTheStateNorTheMemory(): void
    {
        $service = $this->service($this->hooksVetoing());
        $recordId = $this->seedRecord('archived');

        self::assertSame(409, $service->trash(self::TYPE, self::TENANT_A, $recordId)->httpStatus());

        self::assertSame('archived', $this->statusOf($recordId));
        self::assertSame(0, $this->countRemembered(), 'A vetoed trash remembers nothing.');
    }

    /**
     * The same property with a transaction ALREADY open around the call — the
     * shape a plugin's own handler produces when it wraps several operations in
     * one unit of work.
     *
     * `transactionally()` joins an outer transaction rather than nesting (PDO
     * has no savepoints), so the service must not — and does not — roll the
     * caller's transaction back. It does not need to: it has written nothing.
     * The caller's transaction is left usable, and committing it commits exactly
     * what the caller wrote and nothing the vetoed transition would have.
     */
    public function testAVetoInsideAnOpenTransactionLeavesNoPartialWrite(): void
    {
        $service = $this->service($this->hooksVetoing());
        $recordId = $this->seedRecord('active');

        $this->pdo->beginTransaction();
        $this->pdo->prepare('INSERT INTO acme_listener_notes (note) VALUES (?)')->execute(['caller-own-write']);

        $result = $service->trash(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(409, $result->httpStatus());
        self::assertTrue(
            $this->pdo->inTransaction(),
            'The service must not end a transaction it did not open — that would close the '
            . "caller's unit of work out from under them."
        );

        $this->pdo->commit();

        self::assertSame('active', $this->statusOf($recordId), 'Nothing of the transition was committed.');
        self::assertSame(0, $this->countRemembered());
        self::assertSame(
            1,
            $this->countNotes(),
            "The caller's own write is untouched: the veto refuses a transition, it does not "
            . 'discard unrelated work.'
        );
    }

    /**
     * A vetoed DELETE inside an open transaction leaves the row present and its
     * memory intact — the sharper case, since a committed half here is a record
     * that is gone with its state still remembered for whatever next occupies
     * its id.
     */
    public function testAVetoedDeleteInsideAnOpenTransactionLeavesTheRowAndItsMemory(): void
    {
        $service = $this->service($this->hooksVetoing());
        $recordId = $this->seedRecord('archived');
        // Trash it through a service with no listeners, so a real memory exists.
        $this->service()->trash(self::TYPE, self::TENANT_A, $recordId);
        self::assertSame(1, $this->countRemembered());

        $this->pdo->beginTransaction();
        $result = $service->delete(self::TYPE, self::TENANT_A, $recordId);
        $this->pdo->commit();

        self::assertSame(409, $result->httpStatus());
        self::assertSame(1, $this->countRecords($recordId), 'The row survives a vetoed delete.');
        self::assertSame(1, $this->countRemembered(), 'And so does its remembered state.');
    }

    /**
     * A listener that WROTE before a later listener vetoed is rolled back with
     * the transition.
     *
     * This is why the hook is dispatched inside the transaction rather than in
     * front of it. Half a cleanup — the first listener's rows gone, the record
     * still there — is precisely the orphaning the atomic delete unit (#715) was
     * built to prevent, one surface over.
     */
    public function testAWriteByAnEarlierListenerIsRolledBackWithTheVeto(): void
    {
        $hooks = new HookManager();
        $hooks->listen(DataTypeLifecycleService::HOOK_CHANGING, function (array $data): array {
            $this->pdo->prepare('INSERT INTO acme_listener_notes (note) VALUES (?)')->execute(['first-listener']);

            return $data;
        }, 5);
        $hooks->listen(DataTypeLifecycleService::HOOK_CHANGING, static function (array $data): array {
            throw HookVetoException::forEvent(DataTypeLifecycleService::HOOK_CHANGING, self::REASON);
        }, 10);

        $recordId = $this->seedRecord('active');
        $result = $this->service($hooks)->trash(self::TYPE, self::TENANT_A, $recordId);

        self::assertSame(409, $result->httpStatus());
        self::assertSame('active', $this->statusOf($recordId));
        self::assertSame(
            0,
            $this->countNotes(),
            "The first listener's write must roll back together with the transition it was part of."
        );
    }

    // ==================== 3. The `changed` announcement ====================

    /**
     * A successful transition still announces itself exactly once; a vetoed one
     * announces nothing.
     *
     * The second half matters more than it looks: a `changed` event for a
     * transition that did not happen would put every subscriber — the durable
     * spine included — permanently out of step with the database, and no error
     * would ever surface.
     */
    public function testASuccessfulTransitionFiresChangedOnceAndAVetoedOneFiresNone(): void
    {
        $announced = [];
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGED,
            static function (array $data) use (&$announced): array {
                $announced[] = $data;

                return $data;
            }
        );

        $service = $this->service($hooks);
        $recordId = $this->seedRecord('active');

        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());
        self::assertCount(1, $announced, 'A real transition announces itself exactly once.');
        self::assertSame('trash', $announced[0]['action']);

        // Now add a vetoing listener on the pre-transition hook and try again.
        $hooks->listen(DataTypeLifecycleService::HOOK_CHANGING, static function (array $data): array {
            throw HookVetoException::forEvent(DataTypeLifecycleService::HOOK_CHANGING, self::REASON);
        });

        self::assertSame(409, $service->restore(self::TYPE, self::TENANT_A, $recordId)->httpStatus());
        self::assertCount(1, $announced, 'A vetoed transition must announce nothing at all.');
    }

    /**
     * `changing` describes the transition ABOUT to happen, in the same words
     * `changed` uses for the one that did — so a listener does not have to read
     * two shapes to answer the same question.
     */
    public function testTheChangingPayloadDescribesTheIntendedTransition(): void
    {
        $seen = [];
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGING,
            static function (array $data) use (&$seen): array {
                $seen[] = $data;

                return $data;
            }
        );

        $recordId = $this->seedRecord('archived');
        $service = $this->service($hooks);
        $service->trash(self::TYPE, self::TENANT_A, $recordId, 77);
        $service->restore(self::TYPE, self::TENANT_A, $recordId, 77);
        // A separate record, already in the trash: a trashable type has no
        // live → gone path, so a delete has to start from there.
        $service->delete(self::TYPE, self::TENANT_A, $this->seedRecord('trashed'), 77);

        self::assertCount(3, $seen);

        self::assertSame(self::TYPE, $seen[0]['data_type']);
        self::assertSame('trash', $seen[0]['action']);
        self::assertSame((string) $recordId, (string) $seen[0]['record_id']);
        self::assertSame(self::TENANT_A, $seen[0]['tenant_id']);
        self::assertSame('archived', $seen[0]['from']);
        self::assertSame('trashed', $seen[0]['to']);
        self::assertSame(77, $seen[0]['actor_profile_id']);

        self::assertSame('restore', $seen[1]['action']);
        self::assertSame('trashed', $seen[1]['from']);
        self::assertSame(
            'archived',
            $seen[1]['to'],
            'A listener deciding whether to allow a restore needs to know WHERE it would land.'
        );

        self::assertSame('delete', $seen[2]['action']);
        self::assertNull($seen[2]['to'], 'A delete has no destination state.');
    }

    /**
     * The hook fires BEFORE the write, which is the only thing that makes an
     * informed veto possible: a listener reading the record must see it as it
     * still is, not as the transition intends it.
     */
    public function testTheChangingListenerSeesTheRecordBeforeTheWrite(): void
    {
        $observed = null;
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGING,
            function (array $data) use (&$observed): array {
                $observed = $this->statusOf((int) $data['record_id']);

                return $data;
            }
        );

        $recordId = $this->seedRecord('active');
        self::assertTrue($this->service($hooks)->trash(self::TYPE, self::TENANT_A, $recordId)->isOk());

        self::assertSame('active', $observed, 'The row must still read as it was when the hook runs.');
        self::assertSame('trashed', $this->statusOf($recordId), 'and the write must then happen.');
    }

    /**
     * An idempotent no-op fires NEITHER hook: nothing is about to be written, so
     * there is nothing to veto and nothing to announce.
     *
     * Dispatching on a no-op would be worse than noise — a listener would be
     * asked to approve a transition that is not happening, and refusing it would
     * turn today's 200 into a 409 for a call that changes nothing.
     */
    public function testAnIdempotentNoOpFiresNeitherHook(): void
    {
        $fired = [];
        $hooks = new HookManager();
        foreach ([DataTypeLifecycleService::HOOK_CHANGING, DataTypeLifecycleService::HOOK_CHANGED] as $event) {
            $hooks->listen($event, static function (array $data) use (&$fired, $event): array {
                $fired[] = $event;

                return $data;
            });
        }

        $service = $this->service($hooks);

        // Already trashed: a second trash changes nothing.
        self::assertTrue($service->trash(self::TYPE, self::TENANT_A, $this->seedRecord('trashed'))->isOk());
        // Not trashed: there is nothing to restore.
        self::assertTrue($service->restore(self::TYPE, self::TENANT_A, $this->seedRecord('active'))->isOk());
        // Already retired: retiring again changes nothing.
        self::assertTrue($service->retire(self::TYPE, self::TENANT_A, $this->seedRecord('retired'))->isOk());

        self::assertSame([], $fired);
    }

    // ==================== 4. Core still decides first ====================

    /**
     * Core's own refusals outrank the hook and are reached without dispatching
     * it: a plugin is never asked to approve a transition core would refuse
     * anyway, and a core refusal keeps its own reason key rather than being
     * relabelled "blocked by a plugin".
     */
    public function testACoreRefusalIsReachedWithoutConsultingTheHook(): void
    {
        $dispatched = 0;
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGING,
            static function (array $data) use (&$dispatched): array {
                $dispatched++;

                throw HookVetoException::forEvent(DataTypeLifecycleService::HOOK_CHANGING, self::REASON);
            }
        );

        $service = $this->service($hooks);

        // Retired: none of these are legal from that state, by core's own rules.
        $retired = $this->seedRecord('retired');
        self::assertSame('retired_records_cannot_be_trashed', $service->trash(self::TYPE, self::TENANT_A, $retired)->reason());
        self::assertSame('retirement_is_permanent', $service->restore(self::TYPE, self::TENANT_A, $retired)->reason());
        self::assertSame('retired_records_are_permanent', $service->delete(self::TYPE, self::TENANT_A, $retired)->reason());

        // Live on a trashable type: delete has no live → gone path.
        self::assertSame(
            'trash_before_deleting',
            $service->delete(self::TYPE, self::TENANT_A, $this->seedRecord('active'))->reason()
        );

        self::assertSame(0, $dispatched, 'No plugin code runs for a transition core refuses on its own.');
    }

    /**
     * A record in another tenant, or none at all, is still 404 — the hook is not
     * a new place for existence to leak.
     */
    public function testAMissingRecordIsStillNotFoundWithoutConsultingTheHook(): void
    {
        $dispatched = 0;
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGING,
            static function (array $data) use (&$dispatched): array {
                $dispatched++;

                return $data;
            }
        );

        $result = $this->service($hooks)->trash(self::TYPE, self::TENANT_A, 999999);

        self::assertSame(404, $result->httpStatus());
        self::assertSame(0, $dispatched);
    }

    // ==================== 5. The plugin error boundary ====================

    /**
     * A veto must NOT tick the plugin failure breaker.
     *
     * A plugin refusing a transition is a HEALTHY plugin doing exactly what the
     * contract invites it to do. Counting it as a fault would trip the
     * three-strikes breaker and DISABLE the plugin for working correctly — after
     * which its next veto would not be honoured at all, silently re-opening the
     * hole this whole change closes. #715 set the precedent on the deletion
     * paths; this pins that it holds on the lifecycle surface too.
     */
    public function testAVetoDoesNotTickThePluginFailureBreaker(): void
    {
        $hooks = new HookManager();
        $loader = $this->loadPluginVetoing($hooks);
        $service = $this->service($hooks);

        for ($i = 0; $i < 5; $i++) {
            $recordId = $this->seedRecord('active');
            self::assertSame(409, $service->trash(self::TYPE, self::TENANT_A, $recordId)->httpStatus());
            self::assertSame('active', $this->statusOf($recordId));
        }

        $lifecycle = $loader->getLifecycle('Whity\\Plugins\\LifecycleVetoPlugin');
        self::assertNotNull($lifecycle, 'The vetoing plugin must have been loaded.');
        self::assertSame(0, $lifecycle->getConsecutiveErrors(), 'A veto is not an error.');
        self::assertSame(
            PluginState::Active,
            $lifecycle->getState(),
            'Five correct refusals must not disable the plugin — a disabled plugin stops vetoing, '
            . 'which turns a working guard into a silently absent one.'
        );
    }

    /**
     * A listener throwing anything OTHER than a veto behaves exactly as it does
     * today: the per-plugin boundary swallows it, the error counter ticks, and
     * the transition PROCEEDS.
     *
     * That boundary is deliberate and this change must not quietly widen it. A
     * plugin that crashes is a broken plugin, not an objection — promoting every
     * exception to a veto would let one bad deploy freeze an install's entire
     * lifecycle surface with no way to tell "refused" from "broken".
     */
    public function testANonVetoThrowableIsStillSwallowedAndTheTransitionProceeds(): void
    {
        $hooks = new HookManager();
        $loader = $this->loadPluginThrowing($hooks);

        $recordId = $this->seedRecord('active');
        $result = $this->service($hooks)->trash(self::TYPE, self::TENANT_A, $recordId);

        self::assertTrue($result->isOk(), 'A crashing listener must not block the transition.');
        self::assertSame('trashed', $this->statusOf($recordId), 'and the write must have happened.');

        $lifecycle = $loader->getLifecycle('Whity\\Plugins\\LifecycleBombPlugin');
        self::assertNotNull($lifecycle);
        self::assertSame(
            1,
            $lifecycle->getConsecutiveErrors(),
            'A crash IS an error and still counts toward the breaker — the distinction the veto '
            . 'path exists to preserve.'
        );
    }

    // ==================== Helpers ====================

    private function invoke(DataTypeLifecycleService $service, string $action, int $recordId): LifecycleResult
    {
        return match ($action) {
            'trash' => $service->trash(self::TYPE, self::TENANT_A, $recordId),
            'restore' => $service->restore(self::TYPE, self::TENANT_A, $recordId),
            'retire' => $service->retire(self::TYPE, self::TENANT_A, $recordId),
            default => $service->delete(self::TYPE, self::TENANT_A, $recordId),
        };
    }

    private function hooksVetoing(): HookManager
    {
        $hooks = new HookManager();
        $hooks->listen(DataTypeLifecycleService::HOOK_CHANGING, static function (array $data): array {
            throw HookVetoException::forEvent(DataTypeLifecycleService::HOOK_CHANGING, self::REASON);
        });

        return $hooks;
    }

    private function service(?HookManager $hooks = null): DataTypeLifecycleService
    {
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
                    'states' => ['draft', 'archived', 'active', 'retired', 'trashed'],
                    'default_state' => 'active',
                    'trashable' => true,
                    'retirable' => true,
                    'trashed_state' => 'trashed',
                    'retired_state' => 'retired',
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

    /**
     * Load a real plugin whose `changing` listener vetoes, THROUGH PluginLoader
     * — the only way to exercise the per-plugin error boundary, which is where
     * the breaker decision lives. A bare HookManager listener would bypass it
     * and prove nothing about the counter.
     */
    private function loadPluginVetoing(HookManager $hooks): PluginLoader
    {
        return $this->loadPlugin($hooks, 'LifecycleVetoPlugin', sprintf(
            'throw \\Whity\\Sdk\\Hooks\\HookVetoException::forEvent(%s, %s);',
            var_export(DataTypeLifecycleService::HOOK_CHANGING, true),
            var_export(self::REASON, true)
        ));
    }

    private function loadPluginThrowing(HookManager $hooks): PluginLoader
    {
        return $this->loadPlugin($hooks, 'LifecycleBombPlugin', "throw new \\RuntimeException('listener blew up');");
    }

    private function loadPlugin(HookManager $hooks, string $class, string $body): PluginLoader
    {
        $dir = sys_get_temp_dir() . '/whity_lifecycle_veto_' . uniqid();
        mkdir($dir, 0755, true);
        $this->tempDirs[] = $dir;

        $event = DataTypeLifecycleService::HOOK_CHANGING;
        $code = <<<PHP
<?php

namespace Whity\\Plugins;

use Whity\\Sdk\\PluginInterface;

class {$class} implements PluginInterface
{
    public function getName(): string { return '{$class}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array { return []; }
    public function getPermissions(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getHooks(): array
    {
        return ['{$event}' => [\$this, 'onChanging']];
    }
    public function onChanging(array \$data, array \$context): array
    {
        {$body}
    }
}
PHP;
        file_put_contents($dir . '/' . $class . '.php', $code);

        // A logger is passed so the boundary logs THERE rather than to
        // error_log, which would spray a stack trace across the test output.
        $loader = new PluginLoader($dir, new Router(''), null, $hooks, $this->createMock(LoggerInterface::class));
        $loader->load();

        return $loader;
    }

    private function seedRecord(string $status): int
    {
        $this->pdo->prepare('INSERT INTO acme_records (tenant_id, status) VALUES (?, ?)')
            ->execute([self::TENANT_A, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function statusOf(int $recordId): ?string
    {
        $statement = $this->pdo->prepare('SELECT status FROM acme_records WHERE id = ?');
        $statement->execute([$recordId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    private function countRecords(int $recordId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM acme_records WHERE id = ?');
        $statement->execute([$recordId]);

        return (int) $statement->fetchColumn();
    }

    private function countRemembered(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM data_type_restore_states');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    private function countNotes(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM acme_listener_notes');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDirectory($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
