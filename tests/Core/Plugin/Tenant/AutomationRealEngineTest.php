<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Tenant;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\AutomationValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantAutomationsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssueRefused;
use Whity\Core\Document\Issuing\IssueInput;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Plugin\Tenant\Automation\AutomationRunner;
use Whity\Core\Plugin\Tenant\Automation\AutomationRunRepository;
use Whity\Core\Plugin\Tenant\Automation\AutomationTriggers;
use Whity\Core\Plugin\Tenant\Automation\RunPluginAutomationJob;
use Whity\Core\Plugin\Tenant\RecordActor;
use Whity\Core\Plugin\Tenant\RecordWriter;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * WHIT-763: workspace plugin automations, from a person's write to a
 * finished run, on the real schema.
 *
 * Example Bakery's cake orders: confirming an order stamps it, creates a
 * baking task linked to it and tells the bakers.
 */
final class AutomationRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const READ = 'cakeorders:read';
    private const MANAGE = 'cakeorders:manage';
    private const MANAGER = 7;
    private const BAKERS = [8, 9];
    private const CASHIER = 10;

    private PDO $pdo;
    private TenantPluginRepository $plugins;
    private TenantManifestHost $host;
    private TenantRecordRepository $records;
    private AutomationRunRepository $runs;

    /** @var array<int, list<string>> Permissions each profile holds. */
    private array $granted = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery'), (2, 'example-cafe')");
        $roleId = (int) $this->q('SELECT id FROM roles ORDER BY id LIMIT 1')->fetchColumn();
        foreach ([self::MANAGER, ...self::BAKERS, self::CASHIER] as $profile) {
            $this->pdo->exec("INSERT INTO profiles (id, display_name, password_hash) VALUES ({$profile}, 'p{$profile}', 'x')");
            $this->pdo->exec("INSERT INTO memberships (profile_id, tenant_id, role_id) VALUES ({$profile}, 1, {$roleId})");
        }
        $this->granted = [
            self::MANAGER => [self::READ, self::MANAGE],
            8 => [self::READ],
            9 => [self::READ],
            self::CASHIER => [],
        ];

        $this->plugins = new TenantPluginRepository($this->pdo);
        $this->host = new TenantManifestHost($this->plugins, new PermissionRegistry());
        $this->records = new TenantRecordRepository($this->pdo);
        $this->runs = new AutomationRunRepository($this->pdo);
        (new TenantManifestPublisher($this->pdo, $this->plugins, new PluginRoleSeeder($this->pdo), []))
            ->publish(self::A, self::manifest(), self::MANAGER);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $m = AutomationValidatorTest::manifest();
        unset($m['automations']['on_change']);
        $m['automations']['grow'] = [
            'label' => ['en' => 'Keeps growing', 'ar' => 'يكبر باستمرار'],
            'on' => 'order.changed.weight_kg',
            'steps' => [['set_fields' => ['values' => ['weight_kg' => ['+' => [['field' => 'weight_kg'], 1]]]]]],
        ];

        return $m;
    }

    public function testConfirmingAnOrderRunsTheConfirmationOnceEvenWhenRetried(): void
    {
        $order = $this->createOrder();
        self::assertSame([], $this->runRows(), 'creating an order triggers nothing');

        self::assertTrue($this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER))['ok']);
        $run = $this->onlyRun();
        self::assertSame(['confirmation', 'order.moved.confirmed', 'queued', 1, self::MANAGER], [$run['automation'], $run['event'], $run['status'], $run['depth'], $run['actor_profile_id']]);
        self::assertSame(1, $this->scalar("SELECT COUNT(*) FROM jobs WHERE name = '" . RunPluginAutomationJob::NAME . "'"), 'the steps run in the background');
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM notifications'), 'nothing happens in the request');

        self::assertSame(['status' => 'done'], $this->runner()->run(self::A, $run['id']));

        $stamped = $this->order($order);
        self::assertSame(gmdate('Y-m-d'), $stamped['data']['confirmed_on']);
        $tasks = $this->records->list(self::A, 'CakeOrders', 'task', null, null, 10);
        self::assertCount(1, $tasks);
        self::assertEquals(['title' => 'Example Bakery', 'order' => $order], $tasks[0]['data'], 'JSONB keeps no key order');
        self::assertSame(self::BAKERS, $this->notified(), 'the bakers are told, and only they');
        self::assertSame('New order for 2026-11-03', $this->q('SELECT body FROM notifications LIMIT 1')->fetchColumn());

        $trace = $this->runRow($run['id'])['trace'];
        self::assertSame(['set_fields', 'create_record', 'notify', 'notify'], array_column($trace, 'kind'));
        self::assertSame(['done', 'done', 'done', 'done'], array_column($trace, 'status'));

        // A retry (the job delivered again, or a worker that died after the
        // last step) issues nothing more.
        $this->pdo->exec("UPDATE plugin_automation_runs SET status = 'running' WHERE id = {$run['id']}");
        self::assertSame(['status' => 'done'], $this->runner()->run(self::A, $run['id']));
        self::assertSame(['status' => 'done'], $this->runner()->run(self::A, $run['id']));
        self::assertCount(1, $this->records->list(self::A, 'CakeOrders', 'task', null, null, 10));
        self::assertSame(self::BAKERS, $this->notified());
    }

    public function testASelfTriggeringAutomationStopsAtTheDepthLimitAndSaysWhy(): void
    {
        $order = $this->createOrder();
        $this->writer()->update(self::A, $this->manifestFor(self::A), 'order', $order, ['weight_kg' => 3], RecordActor::person(self::MANAGER));

        $ran = 0;
        while (($next = $this->nextQueued()) !== null) {
            self::assertSame('done', $this->runner()->run(self::A, $next)['status']);
            self::assertLessThan(10, ++$ran, 'the loop must stop by itself');
        }

        $runs = array_reverse($this->runRows());
        self::assertSame([1, 2, 3, 4], array_column($runs, 'depth'));
        self::assertSame(['done', 'done', 'done', 'stopped'], array_column($runs, 'status'));
        self::assertSame('depth_limit', $runs[3]['reason']);
        self::assertSame(6, $this->order($order)['data']['weight_kg'], 'three runs, one step each');
    }

    public function testARunActsOnlyWithWhatThePersonItActsForMayDo(): void
    {
        $order = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));
        $run = $this->onlyRun();
        // The manager loses the right to edit orders before the run starts.
        $this->granted[self::MANAGER] = [self::READ];

        $result = $this->runner()->run(self::A, $run['id']);

        self::assertSame(['status' => 'failed', 'reason' => 'not_permitted'], $result);
        self::assertArrayNotHasKey('confirmed_on', $this->order($order)['data']);
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM notifications'), 'a failed step stops the run');
        self::assertSame('not_permitted', $this->runRow($run['id'])['trace'][0]['reason']);
    }

    public function testSwitchedOffAutomationsStartNothingAndAQueuedRunStops(): void
    {
        $order = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));
        $queued = $this->onlyRun();

        $this->plugins->setAutomationsEnabled(self::A, 'CakeOrders', false);
        self::assertSame(['status' => 'stopped', 'reason' => 'automations_off'], $this->runner()->run(self::A, $queued['id']));

        $second = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $second, 'confirm', RecordActor::person(self::MANAGER));
        self::assertCount(1, $this->runRows(), 'no new run while switched off');
    }

    public function testWhenMustHoldForARunToStart(): void
    {
        $order = $this->createOrder(['weight_kg' => null]);
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));

        self::assertSame([], $this->runRows(), 'no weight, so the confirmation doesn\'t apply');
    }

    public function testRunsAndTracesStayInTheirWorkspace(): void
    {
        $order = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));
        $run = $this->onlyRun();

        self::assertSame(['status' => 'missing'], $this->runner()->run(self::B, $run['id']));
        self::assertSame('queued', $this->runRow($run['id'])['status']);

        $handler = new TenantAutomationsApiHandler($this->host, $this->plugins, $this->runs, $this->roleChecker());
        $request = new Request('GET', '/api/workspace-plugins/cakeorders/automation-runs');
        $request->user = (object) ['profile_id' => self::MANAGER];

        TenantContext::reset();
        TenantContext::setTenantId(self::A);
        $listed = json_decode($handler->runs($request, ['plugin' => 'cakeorders'])->getBody(), true)['data'];
        self::assertSame([$run['id']], array_column($listed, 'id'));
        self::assertSame(200, $handler->run($request, ['plugin' => 'cakeorders', 'id' => (string) $run['id']])->getStatusCode());

        TenantContext::reset();
        TenantContext::setTenantId(self::B);
        $this->host->resetRequest();
        self::assertSame(404, $handler->runs($request, ['plugin' => 'cakeorders'])->getStatusCode());
        self::assertSame(404, $handler->run($request, ['plugin' => 'cakeorders', 'id' => (string) $run['id']])->getStatusCode());
    }

    public function testATraceIsShownOnlyToSomeoneWhoCanReadTheRecordsItCarries(): void
    {
        $order = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));
        $run = $this->onlyRun();
        TenantContext::reset();
        TenantContext::setTenantId(self::A);

        $handler = new TenantAutomationsApiHandler($this->host, $this->plugins, $this->runs, $this->roleChecker());
        $request = new Request('GET', '/api/workspace-plugins/cakeorders/automation-runs');
        // The cashier passed the route's plugins:read, but can't read orders.
        $request->user = (object) ['profile_id' => self::CASHIER];

        self::assertSame([], json_decode($handler->runs($request, ['plugin' => 'cakeorders'])->getBody(), true)['data']);
        self::assertSame(404, $handler->run($request, ['plugin' => 'cakeorders', 'id' => (string) $run['id']])->getStatusCode());

        // A baker can read orders and the tasks this automation creates.
        $request->user = (object) ['profile_id' => 8];
        self::assertSame(200, $handler->run($request, ['plugin' => 'cakeorders', 'id' => (string) $run['id']])->getStatusCode());
    }

    public function testAChainOfAutomationsStopsAtItsRunBudget(): void
    {
        $order = $this->createOrder();
        $root = $this->runs->create(self::A, 'CakeOrders', 'grow', 'order.changed', 'order', $order, 1, self::MANAGER, null, 'x', 'done');
        for ($i = 1; $i < AutomationTriggers::MAX_RUNS_PER_CHAIN; $i++) {
            $this->runs->create(self::A, 'CakeOrders', 'grow', 'order.changed', 'order', $order, 2, self::MANAGER, $root, 'x', 'done');
        }

        $this->writer()->update(self::A, $this->manifestFor(self::A), 'order', $order, ['weight_kg' => 9], RecordActor::automation(self::MANAGER, 1, $root, 'grow', $root));

        $latest = $this->runRows()[0];
        self::assertSame(['stopped', 'run_budget', $root], [$latest['status'], $latest['reason'], $latest['root_run_id']]);
        self::assertNull($this->nextQueued());
    }

    public function testARunWhoseAutomationChangedStops(): void
    {
        $order = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));
        $run = $this->onlyRun();
        $this->pdo->exec("UPDATE plugin_automation_runs SET automation_hash = 'an-older-version' WHERE id = {$run['id']}");

        self::assertSame(['status' => 'stopped', 'reason' => 'automation_changed'], $this->runner()->run(self::A, $run['id']));
        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM notifications'));
    }

    /**
     * @param array<string, mixed> $override
     */
    private function createOrder(array $override = []): int
    {
        $values = $override + [
            'customer' => 'Example Bakery', 'cake_type' => 'wedding', 'price' => 50000,
            'pickup_on' => '2026-11-03', 'weight_kg' => 2,
        ];
        $values = array_filter($values, static fn (mixed $v): bool => $v !== null);
        $result = $this->writer()->create(self::A, $this->manifestFor(self::A), 'order', $values, RecordActor::person(self::MANAGER));
        self::assertTrue($result['ok'], json_encode($result) ?: '');

        return $result['id'];
    }

    private function manifestFor(int $tenantId): PluginManifest
    {
        $this->host->resetRequest();

        return $this->host->manifestsFor($tenantId)[0];
    }

    private function writer(): RecordWriter
    {
        return new RecordWriter(
            $this->pdo,
            $this->records,
            $this->roleChecker(),
            null,
            new AutomationTriggers($this->runs, $this->plugins, new QueueService(new JobRepository($this->pdo))),
        );
    }

    private function runner(?\Closure $issueDocument = null): AutomationRunner
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::A);
        $queue = new QueueService(new JobRepository($this->pdo));

        return new AutomationRunner(
            $this->pdo,
            $this->runs,
            $this->host,
            $this->plugins,
            $this->records,
            $this->writer(),
            $this->roleChecker(),
            new NotificationDispatcher(new NotificationRepository($this->pdo), CoreTransports::make(), $queue),
            $issueDocument,
        );
    }

    /**
     * Republish the plugin with the confirmation issuing a document from its
     * slot, and return an issuer standing in for TemplateIssuing: it keeps
     * the origin-key contract (a key it has seen returns that document,
     * `replayed`) and records each call.
     *
     * @param list<array{slot: ?string, record: mixed, values: mixed, originKey: mixed, actor: int}> $calls
     * @param array<string, int> $issued
     */
    private function withDocumentStep(array &$calls, array &$issued, ?DocumentIssueRefused $refuse = null): \Closure
    {
        $m = self::manifest();
        $m['plugin']['version'] = '1.1.0';
        $m['automations']['confirmation']['steps'] = [['issue_document' => [
            'slot' => 'order_confirmation',
            'values' => ['template.note' => ['concat' => ['Pickup ', ['field' => 'pickup_on']]]],
        ]]];
        (new TenantManifestPublisher($this->pdo, $this->plugins, new PluginRoleSeeder($this->pdo), []))->publish(self::A, $m, self::MANAGER);

        return static function (ActingAs $actor, IssueInput $input) use (&$calls, &$issued, $refuse): array {
            $calls[] = ['slot' => $input->slot, 'record' => $input->record, 'values' => $input->values, 'originKey' => $input->originKey, 'actor' => $actor->profileId];
            if ($refuse !== null) {
                throw $refuse;
            }
            $key = (string) $input->originKey;
            $replayed = isset($issued[$key]);
            $issued[$key] ??= 900 + count($issued);

            return ['document' => ['id' => $issued[$key]], 'artifacts' => [], 'render' => ['stored' => true], 'replayed' => $replayed];
        };
    }

    public function testConfirmingAnOrderIssuesItsDocumentOnceEvenWhenRetried(): void
    {
        $calls = [];
        $issued = [];
        $issuer = $this->withDocumentStep($calls, $issued);
        $this->granted[self::MANAGER][] = 'documents:issue';
        $order = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));
        $run = $this->onlyRun();

        self::assertSame(['status' => 'done'], $this->runner($issuer)->run(self::A, $run['id']));
        self::assertSame([[
            'slot' => 'CakeOrders.order_confirmation',
            'record' => 'CakeOrders.order:' . $order,
            'values' => ['template.note' => 'Pickup 2026-11-03'],
            'originKey' => 'automation:' . $run['id'] . ':0',
            'actor' => self::MANAGER,
        ]], $calls);
        self::assertSame(['document_id' => 900, 'replayed' => false, 'rendered' => true], $this->runRow($run['id'])['trace'][0]['outcome']);

        // A worker that died after issuing but before its trace was saved:
        // the retry issues under the same origin key and gets the same document.
        $this->pdo->exec("UPDATE plugin_automation_runs SET status = 'running', trace = '[]' WHERE id = {$run['id']}");
        self::assertSame(['status' => 'done'], $this->runner($issuer)->run(self::A, $run['id']));
        self::assertCount(1, $issued, 'one document');
        self::assertTrue($this->runRow($run['id'])['trace'][0]['outcome']['replayed']);

        // A trace that has the step skips it without calling the issuer at all.
        $this->pdo->exec("UPDATE plugin_automation_runs SET status = 'running' WHERE id = {$run['id']}");
        $this->runner($issuer)->run(self::A, $run['id']);
        self::assertCount(2, $calls);
    }

    public function testAnIssueRefusalFailsTheRunWithItsReason(): void
    {
        $calls = [];
        $issued = [];
        $issuer = $this->withDocumentStep($calls, $issued, new DocumentIssueRefused('slot_unbound', 'No template is bound to this slot', 409));
        $this->granted[self::MANAGER][] = 'documents:issue';
        $order = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));
        $run = $this->onlyRun();

        self::assertSame(['status' => 'failed', 'reason' => 'slot_unbound'], $this->runner($issuer)->run(self::A, $run['id']));
        self::assertSame('No template is bound to this slot', $this->runRow($run['id'])['trace'][0]['outcome']['message']);
    }

    public function testIssuingNeedsThePersonToHoldDocumentsIssue(): void
    {
        $calls = [];
        $issued = [];
        $issuer = $this->withDocumentStep($calls, $issued);
        $order = $this->createOrder();
        $this->writer()->move(self::A, $this->manifestFor(self::A), 'order', $order, 'confirm', RecordActor::person(self::MANAGER));
        $run = $this->onlyRun();

        self::assertSame(['status' => 'failed', 'reason' => 'not_permitted'], $this->runner($issuer)->run(self::A, $run['id']));
        self::assertSame([], $calls, 'the issuer is never called');
    }

    private function roleChecker(): RoleChecker
    {
        $checker = $this->createMock(RoleChecker::class);
        $checker->method('hasPermissionForProfile')->willReturnCallback(
            fn (int $profile, string $permission, int $tenant): bool => $tenant === self::A && in_array($permission, $this->granted[$profile] ?? [], true)
        );
        $checker->method('hasRoleForProfile')->willReturnCallback(
            static fn (int $profile, string $role, int $tenant): bool => $role === 'baker' && in_array($profile, [...self::BAKERS, self::CASHIER], true)
        );
        $checker->method('getEffectiveRolesForProfile')->willReturn([]);

        return $checker;
    }

    /** @return list<array<string, mixed>> Newest first. */
    private function runRows(): array
    {
        return $this->runs->list(self::A, 'CakeOrders', null, 100);
    }

    /** @return array<string, mixed> */
    private function onlyRun(): array
    {
        $runs = $this->runRows();
        self::assertCount(1, $runs);

        return $runs[0];
    }

    private function nextQueued(): ?int
    {
        $id = $this->q("SELECT MIN(id) FROM plugin_automation_runs WHERE status = 'queued'")->fetchColumn();

        return $id === null || $id === false ? null : (int) $id;
    }

    /** @return list<int> */
    private function notified(): array
    {
        $ids = $this->q('SELECT recipient_profile_id FROM notifications ORDER BY recipient_profile_id')->fetchAll(PDO::FETCH_COLUMN);

        return array_map('intval', $ids);
    }

    private function q(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt;
    }

    /** @return array<string, mixed> */
    private function order(int $id): array
    {
        $row = $this->records->find(self::A, 'CakeOrders', 'order', $id);
        self::assertNotNull($row);

        return $row;
    }

    /** @return array<string, mixed> */
    private function runRow(int $id): array
    {
        $row = $this->runs->find(self::A, $id);
        self::assertNotNull($row);

        return $row;
    }

    private function scalar(string $sql): int
    {
        return (int) $this->q($sql)->fetchColumn();
    }
}
