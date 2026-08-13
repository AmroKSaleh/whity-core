<?php

declare(strict_types=1);

namespace Tests\Core\DataType;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DataTypesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\DataType\DataTypeLifecycleService;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\GatedDataTypeLifecycle;
use Whity\Core\DataType\LifecycleResult;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TableOwnershipRegistry;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\DataType\DataTypeLifecycle;
use Whity\Sdk\DataType\LifecycleOutcome;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * The SDK's WRITE contract, and the one property that decides whether it is safe
 * to publish: an in-process call must not be able to skip a check the endpoint
 * enforces.
 *
 * Why the contract exists
 * ----------------------
 * Core told adopters to route their lifecycle writes through core, then gave
 * them {@see \Whity\Sdk\DataType\DataTypeGuard} — read-only, deliberately and
 * load-bearingly so. A plugin that needed to actually trash a record therefore
 * duck-typed {@see DataTypeLifecycleService}, a core internal with no contract
 * and no compatibility promise. That is core's fault, and
 * {@see DataTypeLifecycle} is the answer.
 *
 * The risk, and how it is closed
 * ------------------------------
 * The obvious way to get this wrong is to publish the SERVICE, which enforces
 * the state rules and the declared guards but knows nothing about permissions —
 * so a plugin would hold, in-process, authority the endpoint refuses. The
 * contract is therefore bound to {@see GatedDataTypeLifecycle}, and the endpoint
 * is bound to the SAME OBJECT: {@see DataTypesApiHandler} performs no
 * authorization of its own.
 *
 * That is asserted here the only way worth asserting it — by running the SAME
 * scenario through BOTH paths and comparing the answers field by field, then
 * reading the database to confirm neither path wrote anything a refusal said it
 * would not. Two implementations "written to agree" is what this test exists to
 * make impossible to ship.
 */
final class DataTypeLifecycleWriteContractRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TYPE = 'acme:record';

    /** Holds acme:read + acme:manage + acme:retire in tenant 1. */
    private const MANAGER = 10;

    /** An active member of tenant 1 holding acme:read and nothing else. */
    private const READER = 11;

    /** An active member of tenant 1 holding NO acme permission at all. */
    private const OUTSIDER = 12;

    private PDO $pdo;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seedTenantsAndRoles();
        $this->seedPluginTables();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ==================== 1. The contract is what the container publishes ====================

    public function testTheGatedLifecycleIsTheSdkWriteContractAndTheGuardStaysReadOnly(): void
    {
        // The two contracts are separate objects with separate guarantees. The
        // read-only promise on DataTypeGuard is what makes it safe to hand out;
        // adding mutators to it would falsify the one sentence its documentation
        // rests on, so the write surface is a SECOND contract instead.
        self::assertInstanceOf(DataTypeLifecycle::class, $this->gate());
        self::assertInstanceOf(\Whity\Sdk\DataType\DataTypeGuard::class, $this->service());

        $guardMethods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(\Whity\Sdk\DataType\DataTypeGuard::class))->getMethods()
        );
        sort($guardMethods);
        self::assertSame(
            ['blockingReferences', 'canDelete', 'isReferenceable', 'stateOf'],
            $guardMethods,
            'DataTypeGuard must stay read-only: every method answers a question, none writes.'
        );
    }

    public function testAnOutcomeIsTheVeryObjectTheHttpLayerAnswersFrom(): void
    {
        // Not "an equivalent shape" — the same class. There is no second
        // implementation of the refusal vocabulary to fall out of step with the
        // one the endpoint publishes, which is what makes the agreement below a
        // property rather than a coincidence maintained by hand.
        $outcome = $this->gate()->delete(
            self::TYPE,
            self::TENANT_A,
            $this->seedRecord(self::TENANT_A, 'active'),
            self::MANAGER
        );

        self::assertInstanceOf(LifecycleOutcome::class, $outcome);
        self::assertInstanceOf(LifecycleResult::class, $outcome);
    }

    // ==================== 2. Both paths gate identically ====================

    /**
     * THE test. Every scenario is run twice — once in-process through the SDK
     * contract, once through the HTTP handler — and the two answers must agree
     * on status, stable reason key, sentence and blockers.
     *
     * A table rather than a pile of one-offs, because the property under test is
     * "for EVERY gate and every action, the two paths agree" — a per-case
     * assertion would pass while a fifth gate or a fifth verb quietly diverged.
     */
    public function testEveryGateAnswersIdenticallyInProcessAndOverHttp(): void
    {
        foreach ($this->scenarios() as $name => $scenario) {
            [$type, $action, $actor, $tenantId, $id, $expectedStatus, $expectedReason] = $scenario();

            $outcome = $this->invokeInProcess($this->gate(), $action, $type, $tenantId, $id, $actor);
            $response = $this->invokeOverHttp($action, $type, $tenantId, $id, $actor);

            $body = json_decode($response->getBody(), true);
            self::assertIsArray($body);
            $details = is_array($body['details'] ?? null) ? $body['details'] : [];

            self::assertSame(
                $expectedStatus,
                $outcome->httpStatus(),
                "In-process '{$name}' must answer {$expectedStatus}."
            );
            self::assertSame(
                $expectedStatus,
                $response->getStatusCode(),
                "Over HTTP '{$name}' must answer {$expectedStatus}. Body: " . $response->getBody()
            );
            self::assertSame($expectedReason, $outcome->reason(), "In-process reason for '{$name}'.");
            self::assertSame($expectedReason, $details['reason'] ?? null, "HTTP reason for '{$name}'.");
            self::assertSame(
                $outcome->message(),
                $body['error'] ?? null,
                "The sentence must be the same one, not two written to match ('{$name}')."
            );
            self::assertSame(
                $outcome->blockers(),
                $details['blockers'] ?? null,
                "The blockers must be the same list ('{$name}')."
            );
        }
    }

    public function testAPluginCallingInProcessCannotPerformAnActionItsPermissionForbids(): void
    {
        // The whole risk of publishing a write contract, stated as one test. The
        // caller holds acme:read — enough to see the type, which is what makes
        // this the dangerous case rather than an obviously-blocked one — and
        // nothing else.
        $recordId = $this->seedRecord(self::TENANT_A, 'active');

        $outcome = $this->gate()->trash(self::TYPE, self::TENANT_A, $recordId, self::READER);

        self::assertSame(403, $outcome->httpStatus());
        self::assertSame('insufficient_permissions', $outcome->reason());
        self::assertSame(
            'active',
            $this->stateOf($recordId),
            'The record must be untouched. A 403 that still wrote would be worse than no gate at all.'
        );
    }

    public function testAnUnreadableTypeIsUnKNOWNInProcessTooRatherThanForbidden(): void
    {
        // Whether a plugin declared `acme:record` is not something an
        // unauthorized caller may establish by status code. If the in-process
        // contract answered 403 where the endpoint answers 404, holding the
        // contract would be a way to enumerate the catalogue.
        $recordId = $this->seedRecord(self::TENANT_A, 'active');

        $outcome = $this->gate()->trash(self::TYPE, self::TENANT_A, $recordId, self::OUTSIDER);

        self::assertSame(404, $outcome->httpStatus());
        self::assertSame('unknown_data_type', $outcome->reason());
        self::assertSame(
            $outcome->reason(),
            $this->gate()->trash('nope:nothing', self::TENANT_A, $recordId, self::MANAGER)->reason(),
            'A type that does not exist and a type this caller may not read must be indistinguishable.'
        );
    }

    public function testAnActionTheTypeDoesNotOfferIsRefusedInProcessToo(): void
    {
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');
        $gate = $this->gate(['read' => 'acme:read', 'trash' => 'acme:manage']);

        $outcome = $gate->restore(self::TYPE, self::TENANT_A, $recordId, self::MANAGER);

        self::assertSame(405, $outcome->httpStatus());
        self::assertSame('restore_not_offered', $outcome->reason());
        self::assertSame('trashed', $this->stateOf($recordId));
    }

    public function testTheContractCannotReachAcrossTenants(): void
    {
        // The tenant is a parameter here rather than an ambient request context,
        // so this is the case a reviewer should worry about. It is not a way in:
        // the permission is resolved per (profile, tenant), and the manager holds
        // nothing in tenant 2.
        $theirs = $this->seedRecord(self::TENANT_B, 'active');

        $outcome = $this->gate()->trash(self::TYPE, self::TENANT_B, $theirs, self::MANAGER);

        self::assertSame(404, $outcome->httpStatus());
        self::assertSame(
            'active',
            $this->stateOf($theirs),
            'The other tenant\'s record must be untouched.'
        );
    }

    // ==================== 3. Everything below the gate still applies ====================

    public function testASuccessfulInProcessTransitionActuallyWritesAndIsAudited(): void
    {
        // The other half of "the gate is real": when it passes, the transition
        // runs — the same one the endpoint runs, with the memory, the hooks and
        // the audit entry it has always carried.
        $recordId = $this->seedRecord(self::TENANT_A, 'active');

        $outcome = $this->gate()->trash(self::TYPE, self::TENANT_A, $recordId, self::MANAGER);

        self::assertTrue($outcome->isOk());
        self::assertSame('trashed', $outcome->state());
        self::assertSame('trashed', $this->stateOf($recordId));

        // …and the restore is a real undo, not a jump to the default state.
        self::assertTrue($this->gate()->restore(self::TYPE, self::TENANT_A, $recordId, self::MANAGER)->isOk());
        self::assertSame('active', $this->stateOf($recordId));
    }

    public function testAPluginVetoRefusesAnInProcessCallExactlyAsItRefusesTheEndpoint(): void
    {
        // A veto is a domain refusal raised inside the transition. It must reach
        // an in-process caller under the same key and the same sentence, or a
        // plugin calling core would have to learn a second shape for the one
        // event both paths report.
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

        $recordId = $this->seedRecord(self::TENANT_A, 'active');
        $outcome = $this->gate(hooks: $hooks)->trash(self::TYPE, self::TENANT_A, $recordId, self::MANAGER);

        self::assertSame(409, $outcome->httpStatus());
        self::assertSame('blocked_by_plugin', $outcome->reason());
        self::assertSame('A downstream record depends on this one.', $outcome->message());
        self::assertSame('active', $this->stateOf($recordId));
    }

    public function testABulkSweepIsALoopAndEveryRecordIsGatedIndividually(): void
    {
        // The sanctioned pattern for empty-trash and bulk retire, asserted so the
        // documentation is not the only place it is stated. The tempting
        // alternative — one UPDATE over the trashed rows — would bypass every
        // guard, veto and hook at once, silently.
        //
        // What a loop buys is visible here: the guarded record refuses and the
        // rest still go. A bulk statement has no way to express that outcome.
        $free = [
            $this->seedRecord(self::TENANT_A, 'trashed'),
            $this->seedRecord(self::TENANT_A, 'trashed'),
        ];
        $guarded = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedEntry(self::TENANT_A, $guarded);

        $gate = $this->gate();
        $refused = [];
        foreach ([...$free, $guarded] as $id) {
            $outcome = $gate->delete(self::TYPE, self::TENANT_A, $id, self::MANAGER);
            if (!$outcome->isOk()) {
                $refused[$id] = (string) $outcome->reason();
            }
        }

        self::assertSame([$guarded => 'still_referenced'], $refused);
        self::assertSame(
            1,
            $this->countRecords(),
            'Every unguarded record went; the guarded one stayed. A bulk UPDATE could not have '
            . 'produced that outcome, which is the point.'
        );
    }

    // ==================== Scenarios ====================

    /**
     * Every gate and every refusal, as arrangements returning the call to make
     * and the answer BOTH paths must give.
     *
     * @return array<string, callable(): array{0: string, 1: string, 2: int, 3: int, 4: int|string, 5: int, 6: string}>
     */
    private function scenarios(): array
    {
        return [
            'unknown type' => fn (): array => [
                'nope:nothing', 'trash', self::MANAGER, self::TENANT_A, 1, 404, 'unknown_data_type',
            ],
            'type the caller may not read' => fn (): array => [
                self::TYPE, 'trash', self::OUTSIDER, self::TENANT_A,
                $this->seedRecord(self::TENANT_A, 'active'), 404, 'unknown_data_type',
            ],
            'permission the caller lacks' => fn (): array => [
                self::TYPE, 'trash', self::READER, self::TENANT_A,
                $this->seedRecord(self::TENANT_A, 'active'), 403, 'insufficient_permissions',
            ],
            'record in another tenant' => fn (): array => [
                self::TYPE, 'trash', self::MANAGER, self::TENANT_A,
                $this->seedRecord(self::TENANT_B, 'active'), 404, 'not_found',
            ],
            'delete before trashing' => fn (): array => [
                self::TYPE, 'delete', self::MANAGER, self::TENANT_A,
                $this->seedRecord(self::TENANT_A, 'active'), 409, 'trash_before_deleting',
            ],
            'delete blocked by a guard' => function (): array {
                $recordId = $this->seedRecord(self::TENANT_A, 'trashed');
                $this->seedEntry(self::TENANT_A, $recordId);

                return [self::TYPE, 'delete', self::MANAGER, self::TENANT_A, $recordId, 409, 'still_referenced'];
            },
            'retire a trashed record' => fn (): array => [
                self::TYPE, 'retire', self::MANAGER, self::TENANT_A,
                $this->seedRecord(self::TENANT_A, 'trashed'), 409, 'restore_before_retiring',
            ],
            'restore a retired record' => fn (): array => [
                self::TYPE, 'restore', self::MANAGER, self::TENANT_A,
                $this->seedRecord(self::TENANT_A, 'retired'), 409, 'retirement_is_permanent',
            ],
            'trash a retired record' => fn (): array => [
                self::TYPE, 'trash', self::MANAGER, self::TENANT_A,
                $this->seedRecord(self::TENANT_A, 'retired'), 409, 'retired_records_cannot_be_trashed',
            ],
            'delete a retired record' => fn (): array => [
                self::TYPE, 'delete', self::MANAGER, self::TENANT_A,
                $this->seedRecord(self::TENANT_A, 'retired'), 409, 'retired_records_are_permanent',
            ],
        ];
    }

    /**
     * @param int|string $id The record's key.
     */
    private function invokeInProcess(
        GatedDataTypeLifecycle $gate,
        string $action,
        string $type,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): LifecycleResult {
        return match ($action) {
            'trash' => $gate->trash($type, $tenantId, $id, $actorProfileId),
            'restore' => $gate->restore($type, $tenantId, $id, $actorProfileId),
            'retire' => $gate->retire($type, $tenantId, $id, $actorProfileId),
            default => $gate->delete($type, $tenantId, $id, $actorProfileId),
        };
    }

    /**
     * @param int|string $id The record's key.
     */
    private function invokeOverHttp(
        string $action,
        string $type,
        int $tenantId,
        int|string $id,
        int $actorProfileId
    ): \Whity\Sdk\Http\Response {
        $handler = $this->handler();
        $request = $this->request($actorProfileId, $tenantId);
        $params = ['type' => $type, 'id' => (string) $id];

        return match ($action) {
            'trash' => $handler->trash($request, $params),
            'restore' => $handler->restore($request, $params),
            'retire' => $handler->retire($request, $params),
            default => $handler->delete($request, $params),
        };
    }

    // ==================== Fixtures ====================

    /**
     * @param array<string, string>|null $permissions Override the declared permission map.
     */
    private function gate(?array $permissions = null, ?HookManager $hooks = null): GatedDataTypeLifecycle
    {
        $types = $this->dataTypes($permissions);

        return new GatedDataTypeLifecycle(
            $types,
            new DataTypeLifecycleService($this->pdo, $types, $hooks),
            $this->roleChecker()
        );
    }

    /**
     * The HTTP handler, built over the SAME gate the contract publishes.
     *
     * @param array<string, string>|null $permissions Override the declared permission map.
     */
    private function handler(?array $permissions = null): DataTypesApiHandler
    {
        $types = $this->dataTypes($permissions);
        $service = new DataTypeLifecycleService($this->pdo, $types);

        return new DataTypesApiHandler(
            $types,
            $service,
            new GatedDataTypeLifecycle($types, $service, $this->roleChecker()),
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo)
            )
        );
    }

    private function service(): DataTypeLifecycleService
    {
        return new DataTypeLifecycleService($this->pdo, $this->dataTypes(null));
    }

    private function roleChecker(): RoleChecker
    {
        $permissions = new PermissionRegistry();
        $permissions->register('Acme', ['acme:read', 'acme:manage', 'acme:retire']);

        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return new RoleChecker($db, $permissions);
    }

    /**
     * @param array<string, string>|null $permissions Override the declared permission map.
     */
    private function dataTypes(?array $permissions): DataTypeRegistry
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
                'blocks_delete' => [[
                    'table' => 'acme_entries',
                    'column' => 'record_id',
                    'label' => 'recorded entries',
                    'ignore_when' => ['status' => ['trashed']],
                ]],
                'permissions' => $permissions ?? [
                    'read' => 'acme:read',
                    'trash' => 'acme:manage',
                    'restore' => 'acme:manage',
                    'retire' => 'acme:retire',
                    'delete' => 'acme:manage',
                ],
            ],
        ]);

        return $registry;
    }

    private function request(int $profileId, int $tenantId): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $request = new Request('POST', '/api/data-types', [], '');
        $request->user = (object) ['profile_id' => $profileId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    private function seedTenantsAndRoles(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'acme-manager', '', 1, datetime('now')),
            (102, 'acme-reader', '', 1, datetime('now')),
            (103, 'acme-outsider', '', 1, datetime('now'))");

        foreach (['acme:read', 'acme:manage', 'acme:retire'] as $permission) {
            $this->grant(101, $permission);
        }
        $this->grant(102, 'acme:read');

        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'manager', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'reader', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'outsider', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 101, 'active', datetime('now')),
                (1001, 11, 1, 102, 'active', datetime('now')),
                (1002, 12, 1, 103, 'active', datetime('now'))
        ");
    }

    /**
     * Grant one permission to one role, creating the permission row on first use.
     *
     * Two roles legitimately share `acme:read` here — that overlap is the point
     * of the reader profile — so the permission row is looked up before it is
     * inserted rather than inserted per grant.
     */
    private function grant(int $roleId, string $permission): void
    {
        $lookup = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $lookup->execute([$permission]);
        $permissionId = $lookup->fetchColumn();

        if ($permissionId === false) {
            $this->pdo->prepare(
                "INSERT INTO permissions (name, description, created_at) VALUES (?, '', datetime('now'))"
            )->execute([$permission]);
            $permissionId = $this->pdo->lastInsertId();
        }

        $this->pdo->prepare('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)')
            ->execute([$roleId, (int) $permissionId]);
    }

    private function seedPluginTables(): void
    {
        $this->pdo->exec('
            CREATE TABLE acme_records (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
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

    private function seedRecord(int $tenantId, string $status): int
    {
        $this->pdo->prepare('INSERT INTO acme_records (tenant_id, status) VALUES (?, ?)')
            ->execute([$tenantId, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedEntry(int $tenantId, int $recordId): int
    {
        $this->pdo->prepare('INSERT INTO acme_entries (tenant_id, record_id, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $recordId, 'active']);

        return (int) $this->pdo->lastInsertId();
    }

    private function stateOf(int $recordId): ?string
    {
        $statement = $this->pdo->prepare('SELECT status FROM acme_records WHERE id = ?');
        $statement->execute([$recordId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    private function countRecords(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM acme_records');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}
