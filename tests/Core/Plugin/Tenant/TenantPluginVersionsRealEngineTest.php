<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Tenant;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\RecordTypeValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantPluginVersionsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Plugin\Tenant\ManifestImpact;
use Whity\Core\Plugin\Tenant\ManifestTestRunner;
use Whity\Core\Plugin\Tenant\RecordWriter;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-764: a workspace's plugin versions through the API, on the real
 * schema: validate, plan, publish, refuse a destructive publish until it is
 * confirmed, list history and roll back.
 */
final class TenantPluginVersionsRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const MANAGER = 7;

    private PDO $pdo;
    private TenantPluginRepository $plugins;
    private TenantRecordRepository $records;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery'), (2, 'example-cafe')");
        $this->plugins = new TenantPluginRepository($this->pdo);
        $this->records = new TenantRecordRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testValidateNamesEveryErrorAndReservesInstalledNames(): void
    {
        $ok = $this->json($this->call('validate', self::A, ['manifest' => RecordTypeValidatorTest::manifest()]));
        self::assertSame(['ok' => true, 'errors' => [], 'plugin' => 'CakeOrders', 'version' => '1.0.0'], $ok['data']);

        $broken = RecordTypeValidatorTest::manifest();
        $broken['records']['order']['permissions']['read'] = 'someone:else';
        $result = $this->json($this->call('validate', self::A, ['manifest' => $broken]))['data'];
        self::assertFalse($result['ok']);
        self::assertContains('records.order.permissions.read: required, a permission this manifest declares', $result['errors']);

        $taken = $this->json($this->call('validate', self::A, ['manifest' => RecordTypeValidatorTest::manifest()], installed: ['CakeOrders']))['data'];
        self::assertFalse($taken['ok'], 'a plugin installed for every workspace keeps its name');
    }

    public function testPublishPlanRefuseConfirmAndRollBack(): void
    {
        $v1 = RecordTypeValidatorTest::manifest();
        $first = $this->call('publish', self::A, ['manifest' => $v1, 'notes' => 'First version']);
        self::assertSame(201, $first->getStatusCode(), $first->getBody());
        $first = $this->json($first)['data'];
        self::assertSame('safe', $first['impact']['level']);
        self::assertSame(['new_plugin'], array_column($first['impact']['changes'], 'change'));

        $this->records->create(self::A, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Bakery', 'paid' => true, 'pickup_on' => '2026-11-03'], self::MANAGER);
        $this->records->create(self::A, 'CakeOrders', 'order', 'draft', ['customer' => 'Example Café', 'pickup_on' => '2026-11-04'], self::MANAGER);

        $v2 = $v1;
        $v2['plugin']['version'] = '1.1.0';
        unset($v2['records']['order']['fields']['paid']);
        $v2['records']['order']['fields']['phone'] = ['type' => 'text', 'label' => ['en' => 'Phone', 'ar' => 'الهاتف'], 'required' => true];

        $plan = $this->json($this->call('impact', self::A, ['manifest' => $v2]))['data'];
        self::assertSame('destructive', $plan['level']);
        $byPath = array_column($plan['changes'], null, 'path');
        self::assertSame(['destructive', 1], [$byPath['records.order.fields.paid']['effect'], $byPath['records.order.fields.paid']['records']], 'one order has a value in "paid"');
        self::assertSame(['data', 2], [$byPath['records.order.fields.phone']['effect'], $byPath['records.order.fields.phone']['records']], 'neither order has a phone yet');
        self::assertNotSame('', $byPath['records.order.fields.paid']['messageAr']);

        $refused = $this->call('publish', self::A, ['manifest' => $v2]);
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('destructive_unconfirmed', $this->json($refused)['details']['reason']);
        self::assertSame(1, $this->auditCount(TenantPluginVersionsApiHandler::AUDIT_REFUSED));
        self::assertCount(1, $this->plugins->history(self::A, 'CakeOrders'), 'nothing was published');

        $second = $this->call('publish', self::A, ['manifest' => $v2, 'confirmDestructive' => true]);
        self::assertSame(201, $second->getStatusCode(), $second->getBody());
        $secondId = $this->json($second)['data']['version_id'];

        $history = $this->json($this->call('versions', self::A, params: ['plugin' => 'cakeorders']))['data'];
        self::assertSame([[$secondId, '1.1.0', true], [$first['version_id'], '1.0.0', false]], array_map(
            static fn (array $v): array => [$v['id'], $v['version'], $v['live']],
            $history,
        ));
        self::assertSame('First version', $history[1]['notes']);

        $one = $this->json($this->call('version', self::A, params: ['plugin' => 'cakeorders', 'id' => (string) $first['version_id']]))['data'];
        self::assertArrayHasKey('paid', $one['manifest']['records']['order']['fields']);

        // Rolling back serves "paid" again: nothing was deleted from the store.
        $back = $this->call('activate', self::A, params: ['plugin' => 'cakeorders', 'id' => (string) $first['version_id']]);
        self::assertSame(200, $back->getStatusCode(), $back->getBody());
        self::assertSame('safe', $this->json($back)['data']['impact']['level'], 'removing "phone" again loses nothing: no order has one');
        self::assertSame($first['version_id'], $this->plugins->liveVersionId(self::A, 'CakeOrders'));
        self::assertSame(1, $this->auditCount(TenantManifestPublisher::AUDIT_ACTIVATED));
    }

    public function testAWorkspaceSeesAndActivatesOnlyItsOwnVersions(): void
    {
        $published = $this->json($this->call('publish', self::A, ['manifest' => RecordTypeValidatorTest::manifest()]))['data'];

        self::assertSame([], $this->json($this->call('list', self::B))['data']);
        self::assertSame(404, $this->call('versions', self::B, params: ['plugin' => 'cakeorders'])->getStatusCode());
        self::assertSame(404, $this->call('version', self::B, params: ['plugin' => 'cakeorders', 'id' => (string) $published['version_id']])->getStatusCode());
        self::assertSame(404, $this->call('activate', self::B, params: ['plugin' => 'cakeorders', 'id' => (string) $published['version_id']])->getStatusCode());

        $listed = $this->json($this->call('list', self::A))['data'];
        self::assertSame([['cakeorders', 'CakeOrders', '1.0.0', true]], array_map(
            static fn (array $p): array => [$p['slug'], $p['plugin'], $p['version'], $p['enabled']],
            $listed,
        ));
    }

    public function testAVersionsOwnTestsRunRolledBackAndGatePublishing(): void
    {
        $l = static fn (string $en, string $ar): array => ['en' => $en, 'ar' => $ar];
        $manifest = RecordRulesRealEngineTest::manifest();
        $manifest['roles'] = ['manager' => ['label' => $l('Manager', 'مدير'), 'permissions' => ['cakeorders:manage']]];
        $manifest['tests'] = [
            [
                'label' => $l('A low deposit can\'t be confirmed', 'لا يمكن تأكيد طلب بعربون قليل'),
                'record' => 'order',
                'given' => ['customer' => 'Example Bakery', 'price' => 50000, 'deposit' => 10000, 'pickup_on' => '2026-11-03'],
                'state' => 'draft',
                'move' => 'confirm',
                'expect' => ['refused' => true, 'issues' => ['deposit']],
            ],
            [
                'label' => $l('The balance is worked out', 'يُحسب المتبقي'),
                'record' => 'order',
                'as' => ['manager'],
                'create' => ['customer' => 'Example Bakery', 'price' => 50000, 'deposit' => 20000, 'pickup_on' => '2026-11-03'],
                'expect' => ['refused' => false, 'fields' => ['balance' => 30000, 'paid' => false], 'state' => 'draft'],
            ],
            [
                'label' => $l('The price is locked once confirmed', 'يُقفل السعر بعد التأكيد'),
                'record' => 'order',
                'given' => ['customer' => 'Example Bakery', 'price' => 50000, 'deposit' => 20000, 'pickup_on' => '2026-11-03'],
                'state' => 'confirmed',
                'update' => ['price' => 60000],
                'expect' => ['refused' => true, 'issues' => ['price']],
            ],
        ];

        $ran = $this->json($this->call('test', self::A, ['manifest' => $manifest]))['data'];
        self::assertSame([3, 0], [$ran['passed'], $ran['failed']], (string) json_encode($ran));
        $left = $this->pdo->query('SELECT COUNT(*) FROM plugin_records');
        self::assertNotFalse($left);
        self::assertSame(0, (int) $left->fetchColumn(), 'tests write nothing that stays');

        // A test that expects the wrong answer fails, and the version can't be published.
        $manifest['tests'][1]['expect']['fields']['balance'] = 99;
        $refused = $this->call('publish', self::A, ['manifest' => $manifest]);
        self::assertSame(422, $refused->getStatusCode());
        $details = $this->json($refused)['details'];
        self::assertSame('tests_failed', $details['reason']);
        $failed = $details['tests']['results'][1];
        self::assertSame(['field_differs', 'balance', 30000], [$failed['reason'], $failed['got']['field'], $failed['got']['fields']['balance']]);
        self::assertSame([], $this->plugins->history(self::A, 'CakeOrders'), 'nothing was published');
        self::assertSame(1, $this->auditCount(TenantPluginVersionsApiHandler::AUDIT_REFUSED));

        $manifest['tests'][1]['expect']['fields']['balance'] = 30000;
        self::assertSame(201, $this->call('publish', self::A, ['manifest' => $manifest])->getStatusCode());
    }

    public function testTheSystemTenantHasNoWorkspacePlugins(): void
    {
        self::assertSame(403, $this->call('publish', 0, ['manifest' => RecordTypeValidatorTest::manifest()])->getStatusCode());
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $params
     * @param list<string> $installed
     */
    private function call(string $method, int $tenantId, ?array $body = null, array $params = [], array $installed = []): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $audit = new AuditLogger($this->pdo);
        $handler = new TenantPluginVersionsApiHandler(
            $this->plugins,
            new TenantManifestPublisher($this->pdo, $this->plugins, new PluginRoleSeeder($this->pdo), $installed, $audit),
            new ManifestImpact($this->records),
            new ManifestTestRunner($this->pdo, $this->records, new RecordWriter($this->pdo, $this->records, $this->createMock(RoleChecker::class))),
            $audit,
        );
        $request = new Request($body === null ? 'GET' : 'POST', '/api/workspace-plugins', [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::MANAGER];

        return in_array($method, ['list', 'validate', 'impact', 'test', 'publish'], true)
            ? $handler->{$method}($request)
            : $handler->{$method}($request, $params);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> */
        return json_decode($response->getBody(), true) ?? [];
    }

    private function auditCount(string $action): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $stmt->execute([':action' => $action]);

        return (int) $stmt->fetchColumn();
    }
}
