<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Tenant;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\RecordTypeValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantRecordsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Manifest\ManifestValidator;

/**
 * WHIT-762: a record type's rules hold on every write to the record store.
 *
 * Example Bakery: a deposit of at least 30% is needed to confirm an order, the
 * balance is calculated, an order is unpaid unless said otherwise, and the
 * price can't change once the order is confirmed.
 */
final class RecordRulesRealEngineTest extends TestCase
{
    private const ALL = ['cakeorders:read', 'cakeorders:manage'];

    private PDO $pdo;
    private TenantManifestHost $host;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery')");
        $repository = new TenantPluginRepository($this->pdo);
        $this->host = new TenantManifestHost($repository, new PermissionRegistry());
        (new TenantManifestPublisher($this->pdo, $repository, new PluginRoleSeeder($this->pdo), []))
            ->publish(1, self::manifest(), 7);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @return array<string, mixed> */
    public static function manifest(): array
    {
        $m = RecordTypeValidatorTest::manifest();
        $l = static fn (string $en, string $ar): array => ['en' => $en, 'ar' => $ar];
        $order = &$m['records']['order'];
        $order['fields']['deposit'] = ['type' => 'money', 'label' => $l('Deposit', 'العربون'), 'currency' => 'JOD'];
        $order['fields']['balance'] = ['type' => 'money', 'label' => $l('Balance', 'المتبقي'), 'currency' => 'JOD',
            'computed' => ['-' => [['coalesce' => [['field' => 'price'], 0]], ['coalesce' => [['field' => 'deposit'], 0]]]]];
        $order['fields']['paid']['default'] = false;
        $order['fields']['price']['lockedWhen'] = ['==' => [['state' => []], 'confirmed']];
        $order['rules'] = [[
            'when' => ['==' => [['state' => []], 'confirmed']],
            'require' => ['>=' => [['coalesce' => [['field' => 'deposit'], 0]], ['*' => [['field' => 'price'], 0.3]]]],
            'field' => 'deposit',
            'message' => $l('A deposit of at least 30% is needed to confirm.', 'يلزم عربون لا يقل عن 30% لتأكيد الطلب.'),
        ]];

        return $m;
    }

    public function testTheManifestIsValid(): void
    {
        self::assertSame([], ManifestValidator::validate(self::manifest())['errors']);
    }

    public function testDefaultsAndComputedFieldsAreFilledIn(): void
    {
        $record = $this->json($this->call('create', [], ['customer' => 'Example Bakery', 'price' => 50000, 'deposit' => 10000, 'pickup_on' => '2026-11-03']))['data'];

        self::assertFalse($record['paid'], 'default');
        self::assertSame(40000, $record['balance'], 'computed');

        $updated = $this->json($this->call('update', ['id' => (string) $record['id']], ['deposit' => 20000]))['data'];
        self::assertSame(30000, $updated['balance'], 'recalculated on every write');
    }

    public function testAComputedFieldCantBeWritten(): void
    {
        $response = $this->call('create', [], ['customer' => 'X', 'pickup_on' => '2026-11-03', 'balance' => 1]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['balance'], array_column($this->json($response)['issues'], 'column'));
    }

    public function testAMoveIsRefusedWhenARuleFailsInTheNewState(): void
    {
        $id = (string) $this->json($this->call('create', [], ['customer' => 'X', 'price' => 50000, 'deposit' => 10000, 'pickup_on' => '2026-11-03']))['data']['id'];

        $refused = $this->call('move', ['id' => $id, 'move' => 'confirm']);
        self::assertSame(422, $refused->getStatusCode());
        $issue = $this->json($refused)['issues'][0];
        self::assertSame('deposit', $issue['column']);
        self::assertSame('A deposit of at least 30% is needed to confirm.', $issue['message']);
        self::assertSame('يلزم عربون لا يقل عن 30% لتأكيد الطلب.', $issue['messageAr']);

        $this->call('update', ['id' => $id], ['deposit' => 15000]);
        self::assertSame(200, $this->call('move', ['id' => $id, 'move' => 'confirm'])->getStatusCode());
    }

    public function testALockedFieldCantChangeButOthersCan(): void
    {
        $id = (string) $this->json($this->call('create', [], ['customer' => 'X', 'price' => 50000, 'deposit' => 20000, 'pickup_on' => '2026-11-03']))['data']['id'];
        $this->call('move', ['id' => $id, 'move' => 'confirm']);

        $locked = $this->call('update', ['id' => $id], ['price' => 60000]);
        self::assertSame(422, $locked->getStatusCode());
        self::assertSame(['price'], array_column($this->json($locked)['issues'], 'column'));

        self::assertSame(200, $this->call('update', ['id' => $id], ['customer' => 'Example Cafe'])->getStatusCode());
    }

    public function testAnUpdateThatBreaksARuleIsRefused(): void
    {
        $id = (string) $this->json($this->call('create', [], ['customer' => 'X', 'price' => 50000, 'deposit' => 20000, 'pickup_on' => '2026-11-03']))['data']['id'];
        $this->call('move', ['id' => $id, 'move' => 'confirm']);

        $response = $this->call('update', ['id' => $id], ['deposit' => 1000]);
        self::assertSame(422, $response->getStatusCode(), 'the rule holds on edits too, not only on the move');
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, array $params = [], ?array $body = null): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId(1);
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(static fn (int $u, string $p, int $t): bool => in_array($p, self::ALL, true));
        $roleChecker->method('getEffectiveRolesForProfile')->willReturn(['shop_manager']);
        $this->host->resetRequest();
        $handler = new TenantRecordsApiHandler($this->host, new TenantRecordRepository($this->pdo), $roleChecker);
        $request = new Request('POST', '/api/x/cakeorders/order', [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => 7];

        return $handler->{$method}($request, ['plugin' => 'cakeorders', 'type' => 'order'] + $params);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> */
        return json_decode($response->getBody(), true) ?? [];
    }
}
