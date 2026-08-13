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
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TableOwnershipRegistry;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\Http\Response;

/**
 * WC-746: `POST /api/data-types/{type}/bulk` — one action over many records,
 * SKIPPING AND REPORTING.
 *
 * Four properties decide whether this endpoint is safe to publish, and each one
 * is the answer to a way the feature could have been built wrong:
 *
 *  1. **A refusal does not abort the batch, and the survivors are COMMITTED.**
 *     Not "reported as committed" — actually written. Every assertion about what
 *     moved is a direct read of the table, because a 200 with a per-record
 *     report is exactly the shape a bug would also have.
 *  2. **Each record is its own unit of work.** Pinned from inside a `changed`
 *     listener, which runs after the record's transaction has committed: if the
 *     batch were wrapped in one transaction, that listener would observe an open
 *     one. It observes none, for every record, which is what "records 1–3 are
 *     durable before record 4 is evaluated" means operationally.
 *  3. **Bulk and single gate IDENTICALLY.** Asserted case by case over the whole
 *     refusal vocabulary rather than argued from the call graph, because "it
 *     routes through the same object" is precisely the kind of claim that is
 *     true when written and false two refactors later.
 *  4. **The batch is bounded, and the bound is a setting.** Exceeding it is a
 *     refusal that names the limit, never a truncation.
 */
final class DataTypeBulkLifecycleRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TYPE = 'acme:record';

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

    // ============ 1. A refusal skips ONE record, and the rest are committed ============

    /**
     * The headline promise, read back with SQL.
     *
     * Six trashed records are submitted for deletion and the fourth is still
     * referenced. The other five must be GONE from the table and the fourth must
     * still be there — and the report must say exactly that, per record, with the
     * blockers attached to the one that refused.
     *
     * The row counts are the evidence. A batch that answered this report and
     * rolled everything back would produce an identical response body, which is
     * why the response alone proves nothing.
     */
    public function testOneRefusedRecordDoesNotStopTheOthersFromBeingCommitted(): void
    {
        $ids = [];
        foreach (range(1, 6) as $n) {
            $ids[] = $this->seedRecord(self::TENANT_A, 'trashed');
        }
        // The fourth is still referenced, so its delete is refused.
        $this->seedEntry(self::TENANT_A, $ids[3]);

        $response = $this->bulk('delete', $ids);
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        $data = $this->data($response);
        self::assertSame(
            ['requested' => 6, 'unique' => 6, 'ok' => 5, 'refused' => 1],
            $data['counts'],
            'A client renders "5 done, 1 refused" from this without walking the results.'
        );

        // …and the same story per record, in the order submitted.
        $outcomes = array_column($data['results'], 'outcome', 'id');
        self::assertSame(
            [
                (string) $ids[0] => 'ok',
                (string) $ids[1] => 'ok',
                (string) $ids[2] => 'ok',
                (string) $ids[3] => 'blocked',
                (string) $ids[4] => 'ok',
                (string) $ids[5] => 'ok',
            ],
            $outcomes
        );

        $refused = $this->entryFor($data, $ids[3]);
        self::assertSame(409, $refused['status'], 'The status the single-record call would have answered.');
        self::assertSame('still_referenced', $refused['reason']);
        self::assertSame(
            [['table' => 'acme_entries', 'label' => 'recorded entries', 'count' => 1]],
            $refused['blockers'],
            'A bulk refusal carries the blockers a single one does — the same object built it.'
        );

        // THE assertion. Not the response: the table.
        self::assertSame(
            [(string) $ids[3]],
            $this->recordIds(),
            'Records 1-3 and 5-6 must be really, durably gone, and only the refused one may remain.'
        );
    }

    /**
     * Each record is its own transaction — pinned from where it is observable.
     *
     * `changed` is dispatched AFTER {@see DataTypeLifecycleService::transactionally()}
     * returns. With no outer transaction that means the record's own transaction
     * has just COMMITTED, so `inTransaction()` is false. Wrap the batch in a
     * single transaction and the same listener would observe an open one for
     * every record, because `transactionally()` joins rather than nests.
     *
     * So this assertion fails the moment somebody "improves" the batch by making
     * it atomic — which is the change this design most needs protecting from,
     * since it would silently restore all-or-nothing.
     */
    public function testEveryRecordIsCommittedBeforeTheNextIsAttempted(): void
    {
        $openTransactions = [];
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGED,
            function (array $data) use (&$openTransactions): array {
                $openTransactions[(string) $data['record_id']] = $this->pdo->inTransaction();

                return $data;
            }
        );

        $handler = $this->handlerFor(null, $hooks);
        $ids = [
            $this->seedRecord(self::TENANT_A, 'trashed'),
            $this->seedRecord(self::TENANT_A, 'trashed'),
            $this->seedRecord(self::TENANT_A, 'trashed'),
        ];

        $this->bulk('delete', $ids, $handler);

        self::assertCount(3, $openTransactions, 'Every record must have reached the post-commit hook.');
        foreach ($openTransactions as $id => $open) {
            self::assertFalse(
                $open,
                "Record {$id} was announced with a transaction still open, which means the batch is "
                . 'wrapped in one unit of work — all-or-nothing through the back door.'
            );
        }
        self::assertFalse($this->pdo->inTransaction(), 'And nothing may be left open afterwards.');
    }

    // ==================== 2. A plugin veto is per record ====================

    /**
     * A listener refusing ONE record must not touch the others.
     *
     * The hook fires per record, exactly as it does for a single call, and the
     * veto's own sentence reaches the caller under the existing
     * `blocked_by_plugin` key — no new vocabulary for the batch case.
     */
    public function testAPluginVetoOnOneRecordLeavesTheRestOfTheBatchAlone(): void
    {
        $ids = [
            $this->seedRecord(self::TENANT_A, 'active'),
            $this->seedRecord(self::TENANT_A, 'active'),
            $this->seedRecord(self::TENANT_A, 'active'),
        ];
        $vetoed = $ids[1];

        $dispatched = 0;
        $hooks = new HookManager();
        $hooks->listen(
            DataTypeLifecycleService::HOOK_CHANGING,
            static function (array $data) use ($vetoed, &$dispatched): array {
                $dispatched++;
                if ((string) $data['record_id'] === (string) $vetoed) {
                    throw HookVetoException::forEvent(
                        DataTypeLifecycleService::HOOK_CHANGING,
                        'A downstream record depends on this one.'
                    );
                }

                return $data;
            }
        );

        $response = $this->bulk('trash', $ids, $this->handlerFor(null, $hooks));
        self::assertSame(200, $response->getStatusCode(), $response->getBody());

        $data = $this->data($response);
        self::assertSame(3, $dispatched, 'The veto point exists once per record, not once per batch.');
        self::assertSame(['requested' => 3, 'unique' => 3, 'ok' => 2, 'refused' => 1], $data['counts']);

        $entry = $this->entryFor($data, $vetoed);
        self::assertSame(409, $entry['status']);
        self::assertSame('blocked_by_plugin', $entry['reason'], 'The EXISTING key, not a bulk-specific one.');
        self::assertSame('A downstream record depends on this one.', $entry['message']);
        self::assertSame([], $entry['blockers'], 'A veto is not a reference.');

        // And in the table: two moved, the vetoed one did not.
        self::assertSame('trashed', $this->statusOf($ids[0]));
        self::assertSame('active', $this->statusOf($vetoed), 'A 409 is not evidence the row is untouched.');
        self::assertSame('trashed', $this->statusOf($ids[2]));
    }

    public function testTheRawVetoExceptionMessageNeverReachesABulkCaller(): void
    {
        // The WC-186 leak guard, re-asserted on the new surface: `reason()`
        // crosses to a response, `getMessage()` never does.
        $hooks = new HookManager();
        $hooks->listen(DataTypeLifecycleService::HOOK_CHANGING, static function (array $data): array {
            throw HookVetoException::forEvent(
                DataTypeLifecycleService::HOOK_CHANGING,
                'A downstream record depends on this one.'
            );
        });

        $body = $this->bulk(
            'trash',
            [$this->seedRecord(self::TENANT_A, 'active')],
            $this->handlerFor(null, $hooks)
        )->getBody();

        self::assertStringNotContainsString('Hook listener vetoed', $body);
        self::assertStringNotContainsString('datatype.lifecycle.changing', $body);
    }

    // ==================== 3. Gating parity with the single-record path ====================

    /**
     * For EVERY per-record verdict core can reach, the bulk path answers the
     * same status, the same stable reason key, the same sentence, the same
     * blockers and the same state as the single-record path does for an
     * identically-arranged record.
     *
     * A table rather than a dozen one-offs, so a new reason key cannot be added
     * to the vocabulary and quietly skipped by the bulk surface. Two identical
     * records are seeded per case — one for each path — because the verdict must
     * be observed on a record in the same condition, and the path that ran first
     * would otherwise have moved it.
     *
     * The table includes a case that is NOT a refusal (`already_trashed`), and
     * deliberately: parity is a claim about the whole verdict vocabulary, not
     * only its unhappy half. A bulk surface that turned an idempotent no-op into
     * a refusal would break the "empty the trash on a stale selection" case in
     * exactly the way that is hardest to notice from a green test suite.
     *
     * This is the property the whole design rests on: an adopter must be able to
     * branch on ONE vocabulary whether they called singly or in bulk.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('verdictCases')]
    public function testEveryPerRecordVerdictReadsIdenticallyInBulkAndAlone(string $case, string $arranger): void
    {
        /** @var array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} $arranged */
        $arranged = $this->{$arranger}();
        [$handler, $action, $singleId, $bulkId] = $arranged;

        $single = $this->single($action, $singleId, $handler);
        $singleBody = $this->body($single);
        $entry = $this->entryFor($this->data($this->bulk($action, [$bulkId], $handler)), $bulkId);

        self::assertSame(
            $single->getStatusCode(),
            $entry['status'],
            "'{$case}': the bulk entry must carry the status the single call answered."
        );

        if ($single->getStatusCode() === 200) {
            // The single path answers `{data: {key, outcome, state, reason,
            // message, blockers}}`; the bulk entry is the same result object
            // rendered one level down, so every field of it must still agree.
            self::assertIsArray($singleBody['data']);
            foreach (['outcome', 'state', 'reason', 'message', 'blockers'] as $field) {
                self::assertSame($singleBody['data'][$field], $entry[$field], "'{$case}': {$field} must agree.");
            }

            return;
        }

        self::assertIsArray($singleBody['details'] ?? null, "'{$case}' must be a refusal on the single path.");
        self::assertSame(
            $singleBody['details']['reason'],
            $entry['reason'],
            "'{$case}': ONE vocabulary. A caller must not have to learn a second set of keys for bulk."
        );
        self::assertSame($singleBody['error'], $entry['message'], "'{$case}': the same sentence.");
        self::assertSame($singleBody['details']['blockers'], $entry['blockers'], "'{$case}': the same blockers.");
        self::assertSame($singleBody['details']['state'], $entry['state'], "'{$case}': the same state.");
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function verdictCases(): array
    {
        $cases = [
            'still_referenced',
            'composition_still_referenced',
            'composition_is_permanent',
            'cascade_would_nest',
            'trash_before_deleting',
            'retired_records_are_permanent',
            'retired_records_cannot_be_trashed',
            'retirement_is_permanent',
            'restore_before_retiring',
            'blocked_by_plugin',
            'not_found',
            'another_tenants_record',
            // Not a refusal on either path, and that agreement is the point. A
            // restore of a record that is not in the trash is an idempotent
            // no-op — `nothing_to_restore` is a PREVIEW-only key, published by
            // `GET /api/data-types/{type}/{id}` and never by a mutation.
            'not_trashed_so_restore_is_a_no_op',
            'already_trashed',
        ];

        $provided = [];
        foreach ($cases as $case) {
            $provided[$case] = [$case, 'arrange' . str_replace(' ', '', ucwords(str_replace('_', ' ', $case)))];
        }

        return $provided;
    }

    /**
     * The three gates that are per (type, action, caller) rather than per record
     * are answered ONCE for the batch — in the same envelope, with the same
     * status and the same reason key the single-record call publishes.
     *
     * They belong on the envelope rather than on every result line because they
     * do not depend on any id: a caller who lacks `acme:manage` lacks it for the
     * whole batch, and repeating that verdict 500 times would say nothing extra
     * while inviting a client to think some rows might have gone through.
     */
    public function testTheBatchLevelGatesAnswerExactlyAsTheSingleRecordPathDoes(): void
    {
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');

        $cases = [
            // An unknown type — and a type the caller may not READ, which must be
            // indistinguishable from it.
            'unknown_data_type' => [$this->handler, 'delete', 'nope:nothing', self::MANAGER_A],
            'unreadable_type_is_unknown' => [$this->handler, 'delete', self::TYPE, self::OUTSIDER_A],
            // An action the type does not offer at all.
            'delete_not_offered' => [
                $this->handlerFor(['read' => 'acme:read', 'trash' => 'acme:manage']),
                'delete',
                self::TYPE,
                self::MANAGER_A,
            ],
            // An action the caller holds no permission for. `retire` is declared
            // under acme:retire, which the outsider… also lacks, so use a manager
            // on a type whose retire permission nobody was granted.
            'insufficient_permissions' => [
                $this->handlerFor([
                    'read' => 'acme:read',
                    'trash' => 'acme:manage',
                    'restore' => 'acme:manage',
                    'retire' => 'acme:retire',
                    'delete' => 'acme:ungranted',
                ]),
                'delete',
                self::TYPE,
                self::MANAGER_A,
            ],
        ];

        foreach ($cases as $case => [$handler, $action, $type, $profileId]) {
            $single = $this->single($action, $recordId, $handler, $type, $profileId);
            $bulk = $this->bulk($action, [$recordId], $handler, $type, $profileId);

            self::assertSame(
                $single->getStatusCode(),
                $bulk->getStatusCode(),
                "'{$case}': the batch must be refused with the status one record would have been."
            );
            self::assertSame(
                $this->details($single),
                $this->details($bulk),
                "'{$case}': the same envelope, down to `reason` and `required`."
            );
        }

        self::assertSame(
            [(string) $recordId],
            $this->recordIds(),
            'And a batch refused at the gate must not have touched anything.'
        );
    }

    // ==================== 4. All refused, duplicates, and no-ops ====================

    /**
     * A batch in which EVERY record refuses is still a 200.
     *
     * The status describes the operation, and the operation — attempt these and
     * report — succeeded. Deriving the envelope status from the outcomes would
     * make "did my request run?" and "did every record move?" the same question,
     * and a client would have to parse the body to tell a rejected request from a
     * fully-reported one anyway.
     */
    public function testAnAllRefusedBatchIsA200ThatReportsEveryRefusal(): void
    {
        // Three ACTIVE records on a trashable type: a delete is refused for each
        // with `trash_before_deleting`, and nothing is written.
        $ids = [
            $this->seedRecord(self::TENANT_A, 'active'),
            $this->seedRecord(self::TENANT_A, 'active'),
            $this->seedRecord(self::TENANT_A, 'active'),
        ];

        $response = $this->bulk('delete', $ids);
        self::assertSame(
            200,
            $response->getStatusCode(),
            'The batch reported faithfully; that every record refused is the report, not a failure of it.'
        );

        $data = $this->data($response);
        self::assertSame(['requested' => 3, 'unique' => 3, 'ok' => 0, 'refused' => 3], $data['counts']);
        foreach ($data['results'] as $entry) {
            self::assertSame('refused', $entry['outcome']);
            self::assertSame(409, $entry['status']);
            self::assertSame('trash_before_deleting', $entry['reason']);
        }

        self::assertCount(3, $this->recordIds(), 'Nothing may have moved.');
    }

    public function testDuplicateIdsAreCollapsedIntoOneAttemptAndOneResult(): void
    {
        // Attempting a duplicate twice is wrong in both directions: the second
        // attempt of a delete reports `not_found` for a record the batch itself
        // removed, and the second attempt of a trash reports a no-op success the
        // caller reads as a second record.
        $first = $this->seedRecord(self::TENANT_A, 'trashed');
        $second = $this->seedRecord(self::TENANT_A, 'trashed');

        $data = $this->data($this->bulk('delete', [$first, $second, $first, (string) $first, $second]));

        self::assertSame(
            ['requested' => 5, 'unique' => 2, 'ok' => 2, 'refused' => 0],
            $data['counts'],
            '`requested` vs `unique` is what makes the de-duplication visible rather than mysterious.'
        );
        self::assertSame(
            [(string) $first, (string) $second],
            array_column($data['results'], 'id'),
            'One entry per distinct id, in first-occurrence order.'
        );
        self::assertSame([], $this->recordIds());
    }

    public function testAnIdempotentNoOpIsReportedAsSuccessRatherThanARefusal(): void
    {
        // Unchanged single-record behaviour, and it must stay unchanged here:
        // trashing an already-trashed record succeeds and writes nothing. An
        // "empty the trash" screen that re-submitted a stale selection would
        // otherwise show refusals for records that are exactly where the user
        // wanted them.
        $alreadyTrashed = $this->seedRecord(self::TENANT_A, 'trashed');
        $active = $this->seedRecord(self::TENANT_A, 'active');

        $data = $this->data($this->bulk('trash', [$alreadyTrashed, $active]));

        self::assertSame(['requested' => 2, 'unique' => 2, 'ok' => 2, 'refused' => 0], $data['counts']);
        $noop = $this->entryFor($data, $alreadyTrashed);
        self::assertSame('ok', $noop['outcome']);
        self::assertSame(200, $noop['status']);
        self::assertSame('trashed', $noop['state']);
        self::assertSame('trashed', $this->statusOf($active));
    }

    // ==================== 5. The batch is bounded, by a setting ====================

    public function testABatchLargerThanTheCeilingIsRefusedClearlyAndChangesNothing(): void
    {
        $this->settings()->setTenant(self::TENANT_A, SettingsRegistry::DATA_TYPES_BULK_MAX_IDS, '3');

        $ids = [];
        foreach (range(1, 4) as $n) {
            $ids[] = $this->seedRecord(self::TENANT_A, 'trashed');
        }

        $response = $this->bulk('delete', $ids);

        self::assertSame(422, $response->getStatusCode());
        $details = $this->details($response);
        self::assertSame('batch_too_large', $details['reason']);
        self::assertSame(3, $details['limit'], 'The limit is named, so a client can split the batch itself.');
        self::assertSame(4, $details['requested']);
        self::assertStringContainsString('max 3', $this->body($response)['error']);

        self::assertCount(
            4,
            $this->recordIds(),
            'Refused, NOT truncated — a client silently given 3 of its 4 has no way to notice the fourth.'
        );
    }

    public function testTheCeilingResolvesTenantOverrideThenGlobalThenRegistryDefault(): void
    {
        // "No hardcoded values": the bound is a setting an operator can tune per
        // tenant, and the registry default is the floor a deployment that never
        // touches it still has.
        $settings = $this->settings();

        self::assertSame(
            '500',
            SettingsRegistry::defaultFor(SettingsRegistry::DATA_TYPES_BULK_MAX_IDS),
            'The registry default is what an untouched deployment enforces.'
        );
        self::assertSame(
            '500',
            $settings->effective(self::TENANT_A)[SettingsRegistry::DATA_TYPES_BULK_MAX_IDS]
        );

        $settings->setGlobal(SettingsRegistry::DATA_TYPES_BULK_MAX_IDS, '2');
        self::assertSame(2, $this->effectiveLimit(), 'A global override beats the registry default…');

        $settings->setTenant(self::TENANT_A, SettingsRegistry::DATA_TYPES_BULK_MAX_IDS, '1');
        self::assertSame(1, $this->effectiveLimit(), '…and a tenant override beats the global.');

        // And it is genuinely the value the endpoint enforces, not just the value
        // the settings service reports.
        $ids = [$this->seedRecord(self::TENANT_A, 'trashed'), $this->seedRecord(self::TENANT_A, 'trashed')];
        self::assertSame(422, $this->bulk('delete', $ids)->getStatusCode());
        self::assertSame(200, $this->bulk('delete', [$ids[0]])->getStatusCode());
    }

    public function testTheCeilingIsCountedBeforeDeduplication(): void
    {
        // The bound exists to cap the REQUEST, and a request is as large as it
        // is: 4,000 ids that happen to name 3 records still had to be parsed,
        // held in memory and read off the wire.
        $this->settings()->setTenant(self::TENANT_A, SettingsRegistry::DATA_TYPES_BULK_MAX_IDS, '2');
        $recordId = $this->seedRecord(self::TENANT_A, 'trashed');

        $response = $this->bulk('delete', [$recordId, $recordId, $recordId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(3, $this->details($response)['requested']);
    }

    public function testAnUnusableStoredCeilingFallsBackToTheRegistryDefault(): void
    {
        // Validation refuses a non-positive value through the settings API, so
        // this can only arrive by a hand-edited row — and a hand-edited row must
        // not be able to turn every bulk call into a refusal nobody can explain.
        $this->pdo
            ->prepare('INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES (?, ?, ?, NOW())')
            ->execute([self::TENANT_A, SettingsRegistry::DATA_TYPES_BULK_MAX_IDS, '0']);

        self::assertSame(
            200,
            $this->bulk('delete', [$this->seedRecord(self::TENANT_A, 'trashed')])->getStatusCode()
        );
    }

    // ==================== 6. A request this endpoint cannot act on ====================

    /**
     * @param mixed $ids The `ids` value under test.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableIdLists')]
    public function testAnUnusableIdListIsRefusedRatherThanQuietlyShrunk(string $case, mixed $ids): void
    {
        $response = $this->post(['action' => 'delete', 'ids' => $ids]);

        self::assertSame(400, $response->getStatusCode(), "'{$case}' must be refused.");
        self::assertSame('invalid_ids', $this->details($response)['reason']);
    }

    /**
     * @return array<string, array{0: string, 1: mixed}>
     */
    public static function unusableIdLists(): array
    {
        return [
            // Dropping the unusable element would make the batch quietly smaller
            // than the caller asked for — the same "truncation nobody notices"
            // the size ceiling refuses rather than performs.
            'missing' => ['missing', null],
            'empty' => ['empty', []],
            'a JSON object' => ['a JSON object', ['a' => 1]],
            'a nested array' => ['a nested array', [[1]]],
            'a null element' => ['a null element', [1, null]],
            'a boolean element' => ['a boolean element', [1, true]],
            'a float element' => ['a float element', [1, 1.5]],
            'a blank element' => ['a blank element', [1, '   ']],
            'not an array at all' => ['not an array at all', 'seven'],
        ];
    }

    public function testAnUnknownActionIsRefusedAndNamesTheOnesThatExist(): void
    {
        foreach (['', 'read', 'archive', 'DELETE'] as $action) {
            $response = $this->post(['action' => $action, 'ids' => [1]]);

            self::assertSame(400, $response->getStatusCode(), "'{$action}' is not a bulk action.");
            $details = $this->details($response);
            self::assertSame('unknown_action', $details['reason']);
            self::assertSame(
                ['trash', 'restore', 'retire', 'delete'],
                $details['actions'],
                'The four mutating actions, so a client need not guess.'
            );
        }
    }

    /**
     * A caller who may not use the action must not learn the tenant's configured
     * ceiling, or anything else, by submitting a deliberately bad body.
     *
     * The gate runs BEFORE the batch is read, so both malformed-body refusals
     * come back as the authorization refusal instead.
     */
    public function testTheAuthorizationGateIsAppliedBeforeTheBodyIsEvenRead(): void
    {
        $response = $this->post(
            ['action' => 'delete', 'ids' => 'nonsense'],
            self::TYPE,
            self::OUTSIDER_A
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('unknown_data_type', $this->details($response)['reason']);
        self::assertStringNotContainsString('invalid_ids', $response->getBody());
    }

    // ==================== 7. Tenant isolation ====================

    public function testABatchCannotReachAnotherTenantsRecords(): void
    {
        $ours = $this->seedRecord(self::TENANT_A, 'trashed');
        $theirs = $this->seedRecord(self::TENANT_B, 'trashed');

        $data = $this->data($this->bulk('delete', [$ours, $theirs]));

        self::assertSame(['requested' => 2, 'unique' => 2, 'ok' => 1, 'refused' => 1], $data['counts']);
        self::assertSame(
            'not_found',
            $this->entryFor($data, $theirs)['reason'],
            'Reported absent, never as a different tenant\'s row and never as forbidden.'
        );
        self::assertSame([(string) $theirs], $this->recordIds());
    }

    // ==================== Refusal-case arrangers ====================

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeStillReferenced(): array
    {
        $single = $this->seedRecord(self::TENANT_A, 'trashed');
        $bulk = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedEntry(self::TENANT_A, $single);
        $this->seedEntry(self::TENANT_A, $bulk);

        return [$this->handler, 'delete', $single, $bulk];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeCompositionStillReferenced(): array
    {
        $handler = $this->handlerFor(null, null, 'owned');
        $single = $this->seedRecord(self::TENANT_A, 'trashed');
        $bulk = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedLineNote(self::TENANT_A, $this->seedLine(self::TENANT_A, $single));
        $this->seedLineNote(self::TENANT_A, $this->seedLine(self::TENANT_A, $bulk));

        return [$handler, 'delete', $single, $bulk];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeCompositionIsPermanent(): array
    {
        $handler = $this->handlerFor(null, null, 'owned');
        $single = $this->seedRecord(self::TENANT_A, 'trashed');
        $bulk = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedLine(self::TENANT_A, $single, 'retired');
        $this->seedLine(self::TENANT_A, $bulk, 'retired');

        return [$handler, 'delete', $single, $bulk];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeCascadeWouldNest(): array
    {
        $handler = $this->handlerFor(null, null, 'nested');
        $single = $this->seedRecord(self::TENANT_A, 'trashed');
        $bulk = $this->seedRecord(self::TENANT_A, 'trashed');
        $this->seedLine(self::TENANT_A, $single);
        $this->seedLine(self::TENANT_A, $bulk);

        return [$handler, 'delete', $single, $bulk];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeTrashBeforeDeleting(): array
    {
        return [
            $this->handler,
            'delete',
            $this->seedRecord(self::TENANT_A, 'active'),
            $this->seedRecord(self::TENANT_A, 'active'),
        ];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeRetiredRecordsArePermanent(): array
    {
        return [
            $this->handler,
            'delete',
            $this->seedRecord(self::TENANT_A, 'retired'),
            $this->seedRecord(self::TENANT_A, 'retired'),
        ];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeRetiredRecordsCannotBeTrashed(): array
    {
        return [
            $this->handler,
            'trash',
            $this->seedRecord(self::TENANT_A, 'retired'),
            $this->seedRecord(self::TENANT_A, 'retired'),
        ];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeRetirementIsPermanent(): array
    {
        return [
            $this->handler,
            'restore',
            $this->seedRecord(self::TENANT_A, 'retired'),
            $this->seedRecord(self::TENANT_A, 'retired'),
        ];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeRestoreBeforeRetiring(): array
    {
        return [
            $this->handler,
            'retire',
            $this->seedRecord(self::TENANT_A, 'trashed'),
            $this->seedRecord(self::TENANT_A, 'trashed'),
        ];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeNotTrashedSoRestoreIsANoOp(): array
    {
        return [
            $this->handler,
            'restore',
            $this->seedRecord(self::TENANT_A, 'active'),
            $this->seedRecord(self::TENANT_A, 'active'),
        ];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeAlreadyTrashed(): array
    {
        return [
            $this->handler,
            'trash',
            $this->seedRecord(self::TENANT_A, 'trashed'),
            $this->seedRecord(self::TENANT_A, 'trashed'),
        ];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeBlockedByPlugin(): array
    {
        $hooks = new HookManager();
        $hooks->listen(DataTypeLifecycleService::HOOK_CHANGING, static function (array $data): array {
            throw HookVetoException::forEvent(
                DataTypeLifecycleService::HOOK_CHANGING,
                'A downstream record depends on this one.'
            );
        });

        return [
            $this->handlerFor(null, $hooks),
            'trash',
            $this->seedRecord(self::TENANT_A, 'active'),
            $this->seedRecord(self::TENANT_A, 'active'),
        ];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeNotFound(): array
    {
        return [$this->handler, 'delete', 999_001, 999_002];
    }

    /** @return array{0: DataTypesApiHandler, 1: string, 2: int, 3: int} */
    private function arrangeAnotherTenantsRecord(): array
    {
        return [
            $this->handler,
            'delete',
            $this->seedRecord(self::TENANT_B, 'trashed'),
            $this->seedRecord(self::TENANT_B, 'trashed'),
        ];
    }

    // ==================== Calling the two paths ====================

    /**
     * @param list<int|string> $ids
     */
    private function bulk(
        string $action,
        array $ids,
        ?DataTypesApiHandler $handler = null,
        string $type = self::TYPE,
        int $profileId = self::MANAGER_A
    ): Response {
        return $this->post(['action' => $action, 'ids' => $ids], $type, $profileId, $handler);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(
        array $body,
        string $type = self::TYPE,
        int $profileId = self::MANAGER_A,
        ?DataTypesApiHandler $handler = null
    ): Response {
        $request = $this->request($profileId, self::TENANT_A, (string) json_encode($body));

        return ($handler ?? $this->handler)->bulk($request, ['type' => $type]);
    }

    private function single(
        string $action,
        int|string $id,
        ?DataTypesApiHandler $handler = null,
        string $type = self::TYPE,
        int $profileId = self::MANAGER_A
    ): Response {
        $handler ??= $this->handler;
        $request = $this->request($profileId, self::TENANT_A);
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
     * @param array<string, string>|null $permissions Override the declaration's permissions.
     * @param HookManager|null           $hooks       Listeners the lifecycle consults.
     * @param string                     $variant     'plain', 'owned' (acme:line is declared) or
     *                                                'nested' (acme:line owns rows of its own).
     */
    private function handlerFor(
        ?array $permissions = null,
        ?HookManager $hooks = null,
        string $variant = 'plain'
    ): DataTypesApiHandler {
        $registry = new PermissionRegistry();
        $registry->register('Acme', ['acme:read', 'acme:manage', 'acme:retire', 'acme:ungranted']);

        $types = $this->dataTypes($permissions, $variant);
        $service = new DataTypeLifecycleService($this->pdo, $types, $hooks);

        return new DataTypesApiHandler(
            $types,
            $service,
            new GatedDataTypeLifecycle($types, $service, new RoleChecker($this->wrap($this->pdo), $registry)),
            $this->settings()
        );
    }

    private function settings(): SettingsService
    {
        return new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
    }

    private function effectiveLimit(): int
    {
        return (int) $this->settings()->effective(self::TENANT_A)[SettingsRegistry::DATA_TYPES_BULK_MAX_IDS];
    }

    /**
     * @param array<string, string>|null $permissions Override the declaration's permissions.
     */
    private function dataTypes(?array $permissions, string $variant): DataTypeRegistry
    {
        $tables = new TableOwnershipRegistry();
        $tables->register('Acme', [
            'acme_records' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_entries' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_lines' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_line_notes' => TableOwnershipRegistry::SCOPE_TENANT,
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

        if ($variant === 'owned') {
            // The owned table declared as a type of its own, with a retirable
            // lifecycle and a guard — which is what gives core something to weigh
            // before cascading, and the only way the two composition refusals are
            // reachable at all.
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
                    'blocks_delete' => [[
                        'table' => 'acme_line_notes',
                        'column' => 'line_id',
                        'label' => 'line annotations',
                    ]],
                    'permissions' => ['read' => 'acme:read', 'delete' => 'acme:manage'],
                ],
            ]);
        }

        if ($variant === 'nested') {
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
        }

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
        $this->pdo->exec('
            CREATE TABLE acme_lines (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                record_id INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
        $this->pdo->exec('
            CREATE TABLE acme_line_notes (
                id SERIAL PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                line_id INTEGER NOT NULL,
                status VARCHAR(50) NOT NULL DEFAULT \'active\'
            )
        ');
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

    private function seedLine(int $tenantId, int $recordId, string $status = 'active'): int
    {
        $this->pdo->prepare('INSERT INTO acme_lines (tenant_id, record_id, status) VALUES (?, ?, ?)')
            ->execute([$tenantId, $recordId, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedLineNote(int $tenantId, int $lineId): int
    {
        $this->pdo->prepare('INSERT INTO acme_line_notes (tenant_id, line_id) VALUES (?, ?)')
            ->execute([$tenantId, $lineId]);

        return (int) $this->pdo->lastInsertId();
    }

    // ==================== Reading back ====================

    /**
     * Every surviving record id, as strings, ascending. The evidence for what a
     * batch really did.
     *
     * @return list<string>
     */
    private function recordIds(): array
    {
        $statement = $this->pdo->query('SELECT id FROM acme_records ORDER BY id');
        self::assertNotFalse($statement);

        return array_map(
            static fn (mixed $id): string => (string) $id,
            $statement->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    private function statusOf(int $recordId): ?string
    {
        $statement = $this->pdo->prepare('SELECT status FROM acme_records WHERE id = ?');
        $statement->execute([$recordId]);
        $value = $statement->fetchColumn();

        return $value === false ? null : (string) $value;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function entryFor(array $data, int|string $id): array
    {
        self::assertIsArray($data['results']);
        foreach ($data['results'] as $entry) {
            self::assertIsArray($entry);
            if ($entry['id'] === (string) $id) {
                return $entry;
            }
        }

        self::fail("No result entry for id {$id}.");
    }

    /**
     * @return array<string, mixed>
     */
    private function data(Response $response): array
    {
        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = $this->body($response);
        self::assertIsArray($body['data']);

        return $body['data'];
    }

    /**
     * @return array<string, mixed>
     */
    private function details(Response $response): array
    {
        $body = $this->body($response);
        self::assertIsArray($body['details'] ?? null, $response->getBody());

        return $body['details'];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function wrap(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function request(int $profileId, int $tenantId, string $body = ''): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $request = new Request('POST', '/api/data-types/acme:record/bulk', [], $body);
        $request->user = (object) ['profile_id' => $profileId, 'active_tenant_id' => $tenantId];

        return $request;
    }
}
