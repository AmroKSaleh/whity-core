<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DataTypesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\DataType\DataTypeLifecycleService;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\GatedDataTypeLifecycle;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TableOwnershipRegistry;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Http\Response;

/**
 * WC-723 Door 2, at the PUBLISHED BOUNDARY: what an adopter can actually read
 * back out of `/api/data-types`.
 *
 * The service tests pin the behaviour; these pin the CONTRACT, which is a
 * different thing and was the thinner of the two. Two properties matter here
 * and neither is observable from inside the service:
 *
 *  1. the published entry ROUND-TRIPS the declaration — every field a plugin
 *     declared can be reconstructed from the response, so "did the host honour
 *     my `ignore_when`?" is a diff rather than a read of core's source;
 *  2. an unavailable action EXPLAINS itself — every action-shaped boolean is
 *     exactly `!refusals[action]`, so neither `restorable` nor `deletable` ever
 *     arrives bare, and the explanation is a stable key a renderer can branch
 *     on, kept separate from `blockers` so the row count stays answerable.
 *
 * And one property that must NOT have changed: a caller who may not read a type
 * gets 404, never 403. Existence is not something a reason string may leak.
 */
final class DataTypesApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    /** Holds acme:read + acme:manage + acme:retire in tenant 1. */
    private const MANAGER_A = 10;

    /** An active member of tenant 1 holding NO acme permission at all. */
    private const OUTSIDER_A = 11;

    private PDO $pdo;

    private DataTypesApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->seedTenantsAndRoles();
        $this->seedPluginTables();

        $this->handler = $this->handlerFor();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ==================== 1. The entry round-trips the declaration ====================

    public function testThePublishedEntryRoundTripsTheGuardsIgnoreWhenFilter(): void
    {
        // `ignore_when` is parsed, validated and enforced, and was the one part
        // of a declaration the entry did not echo. Its absence is not readable
        // as "correct and quiet" — it reads as "silently not enforced", and the
        // only way to tell the two apart was to go and read core's source.
        $response = $this->handler->list($this->request(self::MANAGER_A, self::TENANT_A));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        $entry = $this->onlyEntry($response);

        self::assertSame(
            [[
                'table' => 'acme_entries',
                'column' => 'record_id',
                'label' => 'recorded entries',
                'ignore_when' => ['status' => ['trashed', 'void']],
            ]],
            $entry['blocks_delete'],
            'The declaration must be reconstructable from the response, filter included.'
        );
    }

    public function testTheWholeDeclarationIsReconstructableFromTheResponse(): void
    {
        // Round-trippability is the guarantee, not one field of it: an adopter
        // diffs the entry against what they wrote and needs every part of it.
        $entry = $this->onlyEntry($this->handler->list($this->request(self::MANAGER_A, self::TENANT_A)));

        self::assertSame('acme:record', $entry['key']);
        self::assertSame('Acme', $entry['source']);
        self::assertSame(['en' => 'Record'], $entry['label']);
        self::assertSame('status', $entry['lifecycle']['column']);
        self::assertSame(['draft', 'active', 'retired', 'trashed'], $entry['lifecycle']['states']);
        self::assertSame('active', $entry['lifecycle']['default_state']);
        self::assertSame('trashed', $entry['lifecycle']['trashed_state']);
        self::assertSame('retired', $entry['lifecycle']['retired_state']);
        self::assertSame(
            ['read' => 'acme:read', 'trash' => 'acme:manage', 'restore' => 'acme:manage',
                'retire' => 'acme:retire', 'delete' => 'acme:manage'],
            $entry['permissions']
        );
        self::assertSame(['read', 'trash', 'restore', 'retire', 'delete'], $entry['actions']);
    }

    public function testThePublishedEntryRoundTripsTheCompositionBesideTheGuards(): void
    {
        // The two lists are only readable together: `blocks_delete` names rows
        // that must OUTLIVE the record, `cascade_delete` names rows that die
        // WITH it, and the tables are shaped identically. A reader shown one and
        // not the other draws the wrong conclusion about their own schema.
        $entry = $this->onlyEntry($this->handler->list($this->request(self::MANAGER_A, self::TENANT_A)));

        self::assertSame(
            [['table' => 'acme_lines', 'column' => 'record_id', 'label' => 'line items']],
            $entry['cascade_delete'],
            'The composition must be reconstructable from the response, exactly as the guards are.'
        );
        self::assertArrayNotHasKey(
            'ignore_when',
            $entry['cascade_delete'][0],
            'and it must not sprout a field that cannot be declared: an empty filter reads as one '
            . 'that matched nothing rather than one this declaration does not have.'
        );
    }

    // ==================== 2. An unavailable action explains itself ====================

    public function testTheRecordPayloadPublishesWhatADeleteWouldAlsoRemove(): void
    {
        // A composition is invisible in every other field of this payload, and
        // invisible destruction is the worst kind. This is what lets a generated
        // confirmation say "and 2 line items" instead of taking them silently.
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedLine(self::TENANT_A, $recordId);
        $this->seedLine(self::TENANT_A, $recordId);

        $body = $this->data($this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        ));

        self::assertSame(
            [['table' => 'acme_lines', 'label' => 'line items', 'count' => 2]],
            $body['cascade']
        );
        self::assertTrue($body['deletable'], 'Composition is a warning, not a blocker.');
        self::assertSame([], $body['blockers'], 'and it stays out of the field that answers a different question');
    }

    public function testARecordThatOwnsNothingPublishesAnEmptyCascade(): void
    {
        $body = $this->data($this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $this->seedRecord(self::TENANT_A, 'trashed')]
        ));

        self::assertSame([], $body['cascade']);
    }

    public function testDeletingThroughTheEndpointRemovesTheCompositionWithTheRecord(): void
    {
        // End to end at the boundary an adopter actually calls, read back with
        // SQL: the 200 is not the evidence here, the row counts are.
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedLine(self::TENANT_A, $recordId);
        $survivor = $this->seedLine(self::TENANT_A, $this->seedRecord(self::TENANT_A, 'active'));

        $response = $this->handler->delete(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        );

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $remaining = $this->pdo->query('SELECT id FROM acme_lines');
        self::assertNotFalse($remaining);
        self::assertSame(
            [(string) $survivor],
            array_map(static fn (mixed $id): string => (string) $id, $remaining->fetchAll(PDO::FETCH_COLUMN)),
            'The deleted record\'s line is gone and the other record\'s line is untouched.'
        );
    }

    public function testAPolicyRefusalIsPublishedWithItsReasonAndAnEmptyBlockerList(): void
    {
        // The reported case: `deletable: false, blockers: []` and nothing saying
        // why. It was `trash_before_deleting` behaving exactly as designed, but
        // that was only discoverable by reading `evaluateDelete()`.
        $recordId = $this->seedRecord(self::TENANT_A, 'active');

        $body = $this->data($this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        ));

        self::assertFalse($body['deletable']);
        self::assertSame([], $body['blockers'], 'A policy refusal is not a reference and must not fake one.');
        self::assertSame('trash_before_deleting', $body['refusals']['delete']['reason']);
        self::assertNotSame('', $body['refusals']['delete']['message']);
    }

    public function testAReferenceBlockedDeleteStillPublishesItsBlockers(): void
    {
        // Unchanged behaviour, asserted so the new field cannot quietly displace
        // the old one: blockers still answer "how many rows point at this?".
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedEntry(self::TENANT_A, $recordId);

        $body = $this->data($this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        ));

        self::assertFalse($body['deletable']);
        self::assertSame(
            [['table' => 'acme_entries', 'label' => 'recorded entries', 'count' => 1]],
            $body['blockers']
        );
        self::assertSame('still_referenced', $body['refusals']['delete']['reason']);
    }

    public function testTheSiblingActionsExplainTheirRefusalsToo(): void
    {
        // Fixing delete alone would leave restore and retire in exactly the state
        // that caused the report — a dead control with no explanation beside it.
        $retired = $this->seedRecord(self::TENANT_A, 'retired');
        $trashed = $this->seedRecord(self::TENANT_A, 'trashed');

        $retiredView = $this->data($this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $retired]
        ));
        self::assertFalse($retiredView['restorable']);
        self::assertSame('retirement_is_permanent', $retiredView['refusals']['restore']['reason']);
        self::assertSame('retired_records_cannot_be_trashed', $retiredView['refusals']['trash']['reason']);
        self::assertSame('retired_records_are_permanent', $retiredView['refusals']['delete']['reason']);

        $trashedView = $this->data($this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $trashed]
        ));
        self::assertSame('restore_before_retiring', $trashedView['refusals']['retire']['reason']);
    }

    public function testTheInvariantHoldsAcrossEveryStateAtThePublishedBoundary(): void
    {
        // The service test pins the rule; this pins that the rule SURVIVES
        // serialisation, which is the only form an adopter ever sees it in.
        $records = [
            'active' => $this->seedRecord(self::TENANT_A, 'active'),
            'draft' => $this->seedRecord(self::TENANT_A, 'draft'),
            'trashed' => $this->seedRecord(self::TENANT_A, 'trashed'),
            'retired' => $this->seedRecord(self::TENANT_A, 'retired'),
        ];
        $referenced = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedEntry(self::TENANT_A, $referenced);
        $records['trashed-and-referenced'] = $referenced;

        foreach ($records as $label => $recordId) {
            $body = $this->data($this->handler->show(
                $this->request(self::MANAGER_A, self::TENANT_A),
                ['type' => 'acme:record', 'id' => (string) $recordId]
            ));

            foreach (['restorable' => 'restore', 'deletable' => 'delete'] as $field => $action) {
                self::assertSame(
                    !isset($body['refusals'][$action]),
                    $body[$field],
                    "Published '{$field}' on a '{$label}' record must be exactly !refusals['{$action}']."
                );
            }

            // The properties are the deliberate exception, and `state` is the
            // explanation they are published with — see the "Why an action is
            // unavailable" section of docs/wiki/Plugin-Data-Types.md.
            self::assertSame(
                [],
                array_intersect(['referenceable', 'pending_removal'], array_keys($body['refusals'])),
                'A property is not an action: it has no control to disable and no refusal to carry.'
            );
            self::assertArrayHasKey('state', $body);
        }
    }

    public function testAnActionTheTypeDoesNotOfferIsPublishedAsFalseWithItsReason(): void
    {
        // The uniformity gap: `deletable` used to ignore whether the type offered
        // delete at all, so a trashed record on a type with no delete permission
        // published `deletable: true` while DELETE answered 405 — the payload
        // promising an affordance the endpoint does not have. It now answers the
        // question its name asks, with the reason the 405 itself carries.
        $handler = $this->handlerFor(['read' => 'acme:read', 'trash' => 'acme:manage']);
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');

        $body = $this->data($handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        ));

        self::assertFalse($body['deletable']);
        self::assertSame('delete_not_offered', $body['refusals']['delete']['reason']);
        self::assertNotSame('', $body['refusals']['delete']['message']);
        self::assertFalse($body['restorable']);
        self::assertSame('restore_not_offered', $body['refusals']['restore']['reason']);
        self::assertSame([], $body['blockers'], 'Not-offered is not a reference, and must not fake one.');
    }

    public function testTheStatusCodesOfANonOfferedMutationAreUnchanged(): void
    {
        // The regression guard for the design constraint: the `offers()` check
        // belongs to the PREVIEW only. Had it moved into the state evaluator the
        // mutators share, these 405s would have become 409s — a change to the
        // mutation surface nobody asked for, breaking anything that branches on
        // the status to tell "this type has no such action" from "not from this
        // state".
        $handler = $this->handlerFor(['read' => 'acme:read', 'trash' => 'acme:manage']);
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');
        $params = ['type' => 'acme:record', 'id' => (string) $recordId];
        $request = $this->request(self::MANAGER_A, self::TENANT_A);

        foreach (['restore', 'retire', 'delete'] as $action) {
            $response = match ($action) {
                'restore' => $handler->restore($request, $params),
                'retire' => $handler->retire($request, $params),
                default => $handler->delete($request, $params),
            };

            self::assertSame(405, $response->getStatusCode(), "'{$action}' must stay a 405, never a 409.");
            $decoded = json_decode($response->getBody(), true);
            self::assertIsArray($decoded);
            self::assertIsArray($decoded['details']);
            self::assertSame(
                $action . '_not_offered',
                $decoded['details']['reason'],
                'And the preview quotes this very key, so the two cannot drift.'
            );
        }

        // A trash IS offered here, and is unaffected.
        self::assertSame(200, $handler->trash($this->request(self::MANAGER_A, self::TENANT_A), $params)->getStatusCode());
    }

    public function testAnAvailableActionIsGivenNoReason(): void
    {
        // The reason map describes refusals and nothing else; a record that can
        // be deleted must not carry an explanation for a delete that is allowed.
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');

        $body = $this->data($this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        ));

        self::assertTrue($body['deletable']);
        self::assertArrayNotHasKey('delete', $body['refusals']);
    }

    // ==================== 3. The gates are unchanged ====================

    public function testACallerWhoMayNotReadTheTypeStillGets404AndNever403(): void
    {
        // Whether a plugin declared `acme:record` is not something an
        // unauthorized caller may probe by status code — and a refusal reason is
        // not a new place for that to leak.
        $recordId = $this->seedRecord(self::TENANT_A, 'active');

        $show = $this->handler->show(
            $this->request(self::OUTSIDER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        );
        self::assertSame(404, $show->getStatusCode());
        self::assertStringNotContainsString('trash_before_deleting', $show->getBody());
        self::assertStringNotContainsString('acme_entries', $show->getBody());

        $delete = $this->handler->delete(
            $this->request(self::OUTSIDER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        );
        self::assertSame(404, $delete->getStatusCode());

        $list = $this->handler->list($this->request(self::OUTSIDER_A, self::TENANT_A));
        self::assertSame(200, $list->getStatusCode());
        self::assertSame([], $this->decode($list)['data'], 'A type the caller may not read is not advertised.');
    }

    public function testAnUnknownTypeAndAnotherTenantsRecordAreBoth404(): void
    {
        $foreign = $this->seedRecord(self::TENANT_B, 'active');

        self::assertSame(404, $this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'nope:nothing', 'id' => '1']
        )->getStatusCode());

        self::assertSame(404, $this->handler->show(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $foreign]
        )->getStatusCode());
    }

    // ==================== 4. A plugin veto is a refusal, not a crash ====================

    /**
     * A listener refusing a transition reaches the caller as `409` carrying the
     * veto's own sentence — for ALL FOUR mutating actions — and the record is
     * UNCHANGED in the database.
     *
     * Asserted against the row rather than the response, because the failure
     * being prevented is precisely a response and a database that disagree: a
     * 409 over a write that happened anyway is worse than no veto at all, since
     * the caller now believes nothing changed.
     */
    public function testAVetoedTransitionIs409WithTheVetosReasonAndChangesNothing(): void
    {
        foreach (
            [
                'trash' => 'active',
                'restore' => 'trashed',
                'retire' => 'active',
                'delete' => 'trashed',
            ] as $action => $startingState
        ) {
            $handler = $this->handlerFor(null, $this->vetoingHooks());
            $recordId = $this->seedRecord(self::TENANT_A, $startingState);
            $params = ['type' => 'acme:record', 'id' => (string) $recordId];
            $request = $this->request(self::MANAGER_A, self::TENANT_A);

            $response = match ($action) {
                'trash' => $handler->trash($request, $params),
                'restore' => $handler->restore($request, $params),
                'retire' => $handler->retire($request, $params),
                default => $handler->delete($request, $params),
            };

            self::assertSame(409, $response->getStatusCode(), "A vetoed '{$action}' must be a 409.");

            $body = json_decode($response->getBody(), true);
            self::assertIsArray($body);
            self::assertIsArray($body['details']);
            self::assertSame(
                'blocked_by_plugin',
                $body['details']['reason'],
                'One stable key, so a client branches on `reason` exactly as it does for a core refusal.'
            );
            self::assertSame(
                'A downstream record depends on this one.',
                $body['error'],
                "The plugin's own client-safe sentence is the message; core has nothing better to say."
            );
            self::assertSame([], $body['details']['blockers'], 'A veto is not a reference.');
            self::assertSame($startingState, $body['details']['state']);

            self::assertSame(
                $startingState,
                $this->statusOf($recordId),
                "The row must be untouched after a vetoed '{$action}' — a 409 is not evidence that it is."
            );
        }
    }

    /**
     * The raw exception text never reaches the client.
     *
     * `HookVetoException::getMessage()` prefixes the reason with
     * `Hook listener vetoed "<event>": …`. #715 established that only `reason()`
     * crosses to a response (the WC-186 leak guard); this pins that the data-type
     * surface honours the same boundary rather than re-leaking it.
     */
    public function testTheRawVetoExceptionMessageNeverReachesTheClient(): void
    {
        $handler = $this->handlerFor(null, $this->vetoingHooks());
        $recordId = $this->seedRecord(self::TENANT_A, 'active');

        $body = $handler->trash(
            $this->request(self::MANAGER_A, self::TENANT_A),
            ['type' => 'acme:record', 'id' => (string) $recordId]
        )->getBody();

        self::assertStringNotContainsString('Hook listener vetoed', $body);
        self::assertStringNotContainsString('datatype.lifecycle.changing', $body);
    }

    /**
     * The documented gap, pinned so it cannot be closed by accident: the
     * pre-flight preview predicts CORE's refusals and cannot predict a veto —
     * and it must NOT dispatch the hook to find out, because a `GET` running
     * plugin listeners is surprising and potentially side-effecting.
     *
     * So an action the preview publishes as available may still be refused when
     * attempted. That narrowing is real, and it is documented in
     * docs/wiki/Plugin-Data-Types.md rather than papered over; this test is the
     * executable half of that note.
     */
    public function testThePreviewDoesNotDispatchTheHookAndCannotPredictAVeto(): void
    {
        $dispatched = 0;
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGING,
            static function (array $data) use (&$dispatched): array {
                $dispatched++;

                throw HookVetoException::forEvent(
                    DataTypeLifecycleService::HOOK_CHANGING,
                    'A downstream record depends on this one.'
                );
            }
        );

        $handler = $this->handlerFor(null, $hooks);
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');
        $params = ['type' => 'acme:record', 'id' => (string) $recordId];

        $preview = $this->data($handler->show($this->request(self::MANAGER_A, self::TENANT_A), $params));

        self::assertSame(0, $dispatched, 'A GET must never run plugin listeners.');
        self::assertTrue(
            $preview['deletable'],
            'The preview answers for core\'s rules, and by those the delete is available.'
        );
        self::assertArrayNotHasKey('delete', $preview['refusals']);

        // And the attempt is nevertheless refused — the gap, made concrete.
        $response = $handler->delete($this->request(self::MANAGER_A, self::TENANT_A), $params);
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(1, $dispatched, 'The hook runs at ATTEMPT time, which is the only honest moment.');
        self::assertSame('trashed', $this->statusOf($recordId));
    }

    // ==================== Helpers ====================

    private function vetoingHooks(): HookManager
    {
        $hooks = new HookManager();
        $hooks->listen(DataTypeLifecycleService::HOOK_CHANGING, static function (array $data): array {
            throw HookVetoException::forEvent(
                DataTypeLifecycleService::HOOK_CHANGING,
                'A downstream record depends on this one.'
            );
        });

        return $hooks;
    }

    private function statusOf(int $recordId): ?string
    {
        $statement = $this->pdo->prepare('SELECT status FROM acme_records WHERE id = ?');
        $statement->execute([$recordId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }


    /**
     * A handler over the declared type, optionally with a REDUCED permission
     * map — the way a type stops offering an action — and optionally with a
     * live hook manager, the way a plugin gets to veto a transition.
     *
     * @param array<string, string>|null $permissions Override the declaration's permissions.
     * @param HookManager|null           $hooks       Listeners the lifecycle consults.
     */
    private function handlerFor(?array $permissions = null, ?HookManager $hooks = null): DataTypesApiHandler
    {
        $registry = new PermissionRegistry();
        $registry->register('Acme', ['acme:read', 'acme:manage', 'acme:retire']);

        $types = $this->dataTypes($permissions);

        $service = new DataTypeLifecycleService($this->pdo, $types, $hooks);

        return new DataTypesApiHandler(
            $types,
            $service,
            new GatedDataTypeLifecycle($types, $service, new RoleChecker($this->wrap($this->pdo), $registry))
        );
    }

    /**
     * @param array<string, string>|null $permissions Override the declaration's permissions.
     */
    private function dataTypes(?array $permissions = null): DataTypeRegistry
    {
        $tables = new TableOwnershipRegistry();
        $tables->register('Acme', [
            'acme_records' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_entries' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_lines' => TableOwnershipRegistry::SCOPE_TENANT,
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
                    'ignore_when' => ['status' => ['trashed', 'void']],
                ]],
                'cascade_delete' => [[
                    'table' => 'acme_lines',
                    'column' => 'record_id',
                    'label' => 'line items',
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

    private function seedTenantsAndRoles(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
            (101, 'acme-manager-a', '', 1, datetime('now')),
            (102, 'acme-outsider-a', '', 1, datetime('now'))");

        foreach (['acme:read', 'acme:manage', 'acme:retire'] as $permission) {
            $this->grant(101, $permission);
        }

        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'manager-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'outsider-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 101, 'active', datetime('now')),
                (1001, 11, 1, 102, 'active', datetime('now'))
        ");
    }

    private function seedPluginTables(): void
    {
        // No foreign key between them — the convention the declared guard exists
        // to compensate for.
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
        // The composition half: rows that are PART of a record and die with it.
        // Shaped exactly like the table above, deliberately — nothing but the
        // declaration says which of the two a plugin meant.
        $this->pdo->exec('
            CREATE TABLE acme_lines (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                record_id INTEGER NOT NULL
            )
        ');
    }

    private function seedLine(int $tenantId, int $recordId): int
    {
        $this->pdo->prepare('INSERT INTO acme_lines (tenant_id, record_id) VALUES (?, ?)')
            ->execute([$tenantId, $recordId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function grant(int $roleId, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $select = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $select->execute([$permission]);
        $this->pdo
            ->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, (int) $select->fetchColumn()]);
    }

    private function seedRecord(int $tenantId, string $status): int
    {
        $this->pdo->prepare('INSERT INTO acme_records (tenant_id, status) VALUES (?, ?)')
            ->execute([$tenantId, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedEntry(int $tenantId, int $recordId, string $status = 'active'): int
    {
        $this->pdo->prepare('INSERT INTO acme_entries (tenant_id, record_id, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $recordId, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function wrap(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function request(int $profileId, int $tenantId): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $request = new Request('GET', '/api/data-types', [], '');
        $request->user = (object) ['profile_id' => $profileId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    /**
     * The single published type entry, asserted to be the only one.
     *
     * @return array<string, mixed>
     */
    private function onlyEntry(Response $response): array
    {
        $decoded = $this->decode($response);
        self::assertCount(1, $decoded['data']);
        self::assertIsArray($decoded['data'][0]);

        return $decoded['data'][0];
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $decoded = $this->decode($response);
        self::assertIsArray($decoded['data']);

        return $decoded['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('data', $decoded);

        return $decoded;
    }
}
