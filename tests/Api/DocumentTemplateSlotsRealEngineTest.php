<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\DocumentSlotValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentSourcesApiHandler;
use Whity\Api\DocumentTemplateSlotsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Slots\SlotBindings;
use Whity\Core\Document\Slots\TemplateSlot;
use Whity\Core\Document\Slots\TemplateSlotRegistry;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Manifest\PluginManifest;

/**
 * Template slots and the workspace's bindings (WHIT-742,
 * DOCUMENT-AUTOMATION.md 3.2): slots come from switched-on workspace plugins
 * (and code plugins), and a binding must name a published, workspace-shared
 * template of the slot's kind and record type. Runs on SQLite by default and
 * on PostgreSQL when PHPUNIT_PG_DSN is set.
 */
final class DocumentTemplateSlotsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;
    private const OWNER = 10;
    private const SLOT = 'CakeOrders.order_confirmation';
    private const RECORD = 'CakeOrders.order';

    private PDO $pdo;
    private SettingsService $settings;
    private DocumentTemplateSlotsApiHandler $handler;
    private TemplateSlotRegistry $registry;
    private DocumentSourcesApiHandler $sources;

    /** @var array<int, list<PluginManifest>> */
    private array $manifests = [];

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $roleChecker = new RoleChecker($this->wrap($this->pdo), new PermissionRegistry());
        $sources = $this->sources = new DocumentSourcesApiHandler(
            new DocumentSourceRepository($this->pdo),
            new DocumentAccessPolicy(),
            $roleChecker,
            new OuReachResolver($this->pdo, new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())),
            null,
            new DocumentSourceVersionRepository($this->pdo),
        );
        $this->manifests = [self::TENANT => [PluginManifest::fromArray(DocumentSlotValidatorTest::manifest())]];
        $this->registry = new TemplateSlotRegistry(fn (int $tenantId): array => $this->manifests[$tenantId] ?? []);
        $this->handler = new DocumentTemplateSlotsApiHandler($this->registry, $this->settings, $sources);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    public function testTheWorkspaceListsItsPluginsSlotsUnbound(): void
    {
        $res = $this->call('list', 'GET', '/api/document-template-slots');

        self::assertSame(200, $res->getStatusCode());
        $data = $this->json($res)['data'];
        self::assertSame(['CakeOrders.order_confirmation', 'CakeOrders.order_label'], array_column($data, 'key'));
        self::assertSame(self::RECORD, $data[0]['recordType']);
        self::assertSame(['template'], $data[0]['kinds']);
        self::assertSame('تأكيد الطلب', $data[0]['labelAr']);
        self::assertNull($data[0]['starter'], 'workspace plugins have no starters yet');
        self::assertNull($data[0]['binding']);
        self::assertSame(SlotBindings::etag([]), self::header($res, 'ETag'));
    }

    public function testAnotherWorkspaceDoesNotSeeThesePluginsSlots(): void
    {
        self::assertSame([], $this->registry->forTenant(self::OTHER_TENANT));
        self::assertSame([], $this->registry->forTenant(0), 'the system tenant runs no workspace plugins');
    }

    public function testAPublishedWorkspaceTemplateBindsAndTheEtagMoves(): void
    {
        $id = $this->template();

        $res = $this->bind(self::SLOT, $id);

        self::assertSame(200, $res->getStatusCode(), (string) $res->getBody());
        $slot = $this->json($res)['data'];
        self::assertSame($id, $slot['binding']['template_id']);
        self::assertSame('Order confirmation', $slot['binding']['title']);
        self::assertSame(1, $slot['binding']['published_version']);
        self::assertNull($slot['binding']['problem']);
        self::assertSame(SlotBindings::etag([self::SLOT => $id]), self::header($res, 'ETag'));
        self::assertSame(
            '{"CakeOrders.order_confirmation":' . $id . '}',
            $this->settings->effective(self::TENANT)[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS],
            'read back from the setting',
        );

        $listed = $this->json($this->call('list', 'GET', '/api/document-template-slots'))['data'];
        self::assertSame($id, $listed[0]['binding']['template_id']);
    }

    public function testNullUnbinds(): void
    {
        $id = $this->template();
        $this->bind(self::SLOT, $id);

        $res = $this->bind(self::SLOT, null);

        self::assertSame(200, $res->getStatusCode());
        self::assertNull($this->json($res)['data']['binding']);
        self::assertSame('', $this->settings->effective(self::TENANT)[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS]);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function misfits(): iterable
    {
        yield 'a label sheet in a template slot' => [['kind' => 'label'], 'kind_mismatch'];
        yield 'another record type' => [['record_type' => 'CakeOrders.task'], 'record_type_mismatch'];
        yield 'no record type' => [['record_type' => null], 'record_type_mismatch'];
        yield 'a personal template' => [['scope' => 'personal'], 'personal_template'];
        yield 'never published' => [['published' => false], 'unpublished'];
    }

    /** @param array<string, mixed> $with */
    #[\PHPUnit\Framework\Attributes\DataProvider('misfits')]
    public function testATemplateThatDoesntFitIsRefusedWithWhy(array $with, string $code): void
    {
        $id = $this->template($with);

        $res = $this->bind(self::SLOT, $id);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame($code, $this->json($res)['details']['code'] ?? $this->json($res)['code'] ?? null, (string) $res->getBody());
        self::assertSame('', $this->settings->effective(self::TENANT)[SettingsRegistry::DOCUMENTS_SLOT_BINDINGS], 'nothing was bound');
    }

    public function testIfMatchIsRequiredAndAStaleOneIsRefused(): void
    {
        $id = $this->template();

        $missing = $this->call('bind', 'PUT', '/api/document-template-slots/' . self::SLOT, ['template_id' => $id], ['key' => self::SLOT]);
        self::assertSame(428, $missing->getStatusCode());

        $stale = $this->call('bind', 'PUT', '/api/document-template-slots/' . self::SLOT, ['template_id' => $id], ['key' => self::SLOT], ['If-Match' => '"0000000000000000"']);
        self::assertSame(409, $stale->getStatusCode());
        self::assertSame(SlotBindings::etag([]), self::header($stale, 'ETag'), 'the current ETag comes back');
    }

    public function testAnUnknownSlotOrOneWhosePluginIsOffIsNotFound(): void
    {
        $id = $this->template();
        $this->manifests = [];

        self::assertSame(404, $this->bind(self::SLOT, $id)->getStatusCode());
    }

    public function testAnotherWorkspacesTemplateIsNotFound(): void
    {
        $theirs = $this->template(['tenant' => self::OTHER_TENANT]);

        self::assertSame(404, $this->bind(self::SLOT, $theirs)->getStatusCode());
    }

    public function testCodePluginSlotsAreListedAndOnlyUnderTheirOwnPrefix(): void
    {
        $this->registry->register('Invoicing', [
            new TemplateSlot('Invoicing.invoice', 'Invoicing', ['en' => 'Invoice', 'ar' => 'فاتورة'], 'Invoicing.invoice'),
            new TemplateSlot('CakeOrders.invoice', 'Invoicing', ['en' => 'Squatted', 'ar' => 'x'], 'Invoicing.invoice'),
        ]);

        $keys = array_keys($this->registry->forTenant(self::TENANT));
        self::assertSame(['CakeOrders.order_confirmation', 'CakeOrders.order_label', 'Invoicing.invoice'], $keys);

        $this->registry->unregister('Invoicing');
        self::assertArrayNotHasKey('Invoicing.invoice', $this->registry->forTenant(self::TENANT));
    }

    public function testTheBindingsSettingOnlyTakesAMapOfSlotToTemplateId(): void
    {
        self::assertNull(SlotBindings::validate(''));
        self::assertNull(SlotBindings::validate('{"CakeOrders.order_confirmation":4}'));
        self::assertNotNull(SlotBindings::validate('[4]'));
        self::assertNotNull(SlotBindings::validate('{"order_confirmation":4}'));
        self::assertNotNull(SlotBindings::validate('{"CakeOrders.order_confirmation":"4"}'));
        self::assertNotNull(SlotBindings::validate('{"CakeOrders.order_confirmation":0}'));
        self::assertSame([], SlotBindings::parse('not json'));
    }

    /**
     * A workspace plugin's record type can be 129 characters (`<64>.<64>`),
     * so a template for it saves (migration 178); a longer one is a 422, not
     * a database error.
     */
    public function testATemplateTakesARecordTypeUpTo160Characters(): void
    {
        $long = str_repeat('P', 64) . '.' . str_repeat('t', 64);
        $ok = $this->createTemplate(['kind' => 'template', 'title' => 'Long', 'record_type' => $long]);
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame($long, $this->json($ok)['data']['record_type']);

        self::assertSame(422, $this->createTemplate(['kind' => 'template', 'title' => 'Too long', 'record_type' => str_repeat('x', 161)])->getStatusCode());
        self::assertSame(422, $this->createTemplate(['kind' => 'template', 'title' => 'Not a type', 'record_type' => 42])->getStatusCode());
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function createTemplate(array $body): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request('POST', '/api/document-sources', [], (string) json_encode($body));
        $request->user = (object) ['profile_id' => self::OWNER, 'active_tenant_id' => self::TENANT];

        return $this->sources->create($request);
    }

    private function bind(string $key, ?int $templateId): Response
    {
        $etag = (string) self::header($this->call('list', 'GET', '/api/document-template-slots'), 'ETag');

        return $this->call('bind', 'PUT', '/api/document-template-slots/' . $key, ['template_id' => $templateId], ['key' => $key], ['If-Match' => $etag]);
    }

    /**
     * A template row, published at version 1 unless `published` is false.
     *
     * @param array<string, mixed> $with
     */
    private function template(array $with = []): int
    {
        $repo = new DocumentSourceRepository($this->pdo);
        $id = $repo->create((int) ($with['tenant'] ?? self::TENANT), [
            'kind' => $with['kind'] ?? 'template',
            'title' => 'Order confirmation',
            'lang' => 'en',
            'default_view' => 'page',
            'record_type' => array_key_exists('record_type', $with) ? $with['record_type'] : self::RECORD,
            'blocks' => [],
            'scope' => $with['scope'] ?? DocumentAccessPolicy::SCOPE_TENANT,
            'created_by' => self::OWNER,
        ]);
        if (($with['published'] ?? true) === true) {
            $this->pdo->exec("UPDATE document_sources SET published_version = 1 WHERE id = {$id}");
        }

        return $id;
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $params
     * @param array<string, string> $headers
     */
    private function call(string $method, string $verb, string $path, ?array $body = null, array $params = [], array $headers = []): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
        $request = new Request($verb, $path, $headers, $body === null ? '' : (string) json_encode((object) $body));
        $request->user = (object) ['profile_id' => self::OWNER, 'active_tenant_id' => self::TENANT];

        return $params === [] ? $this->handler->{$method}($request) : $this->handler->{$method}($request, $params);
    }

    /** @return array<string, mixed> */
    private function json(Response $res): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $res->getBody(), true, 64, JSON_THROW_ON_ERROR);
    }

    private static function header(Response $res, string $name): ?string
    {
        foreach ($res->getHeaders() as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'owner', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (20, 'other', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 1, 1, 'active', datetime('now')),
                (2000, 20, 2, 1, 'active', datetime('now'))
        ");

        return $pdo;
    }

    private function wrap(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
