<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PlansApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Database\Database;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see PlansApiHandler} (WC-plans): the operator plan
 * admin surface. Proves the system-tenant gate (a non-system admin is blocked),
 * plan CRUD + bundle validation, and — the core flow — applying a plan to a
 * target tenant materialises its entitlements. Also verifies migration 056's grant.
 */
final class PlansApiHandlerRealEngineTest extends TestCase
{
    private const OPERATOR      = 10; // system tenant 0, admin role
    private const TENANT2_ADMIN = 11; // tenant 2 admin (holds the perm, NOT system)
    private const SYS_NOPERM    = 12; // system tenant 0, no-perm role

    private const SYSTEM_TENANT = 0;
    private const TARGET_TENANT = 1;

    private PDO $pdo;
    private EntitlementService $entitlements;
    private PlansApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $planService = new PlanService(new PlanRepository($this->pdo), $this->entitlements, $this->pdo);
        $this->handler = new PlansApiHandler($planService, new RoleChecker($db, new PermissionRegistry()), $this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ── the system-tenant gate ──────────────────────────────────────────────

    public function testNonSystemAdminIsBlocked(): void
    {
        // tenant-2 admin holds plans:manage (global admin role) but is NOT system.
        $list = $this->call('list', [], self::TENANT2_ADMIN, 2);
        self::assertSame(403, $list->getStatusCode());

        $create = $this->call('create', [], self::TENANT2_ADMIN, 2, ['plan_key' => 'x', 'name' => 'X']);
        self::assertSame(403, $create->getStatusCode());
    }

    public function testCallerWithoutPermissionIs403(): void
    {
        self::assertSame(403, $this->call('list', [], self::SYS_NOPERM, self::SYSTEM_TENANT)->getStatusCode());
    }

    // ── plan CRUD + bundle ──────────────────────────────────────────────────

    public function testCreateShowAndListPlan(): void
    {
        $res = $this->op('create', [], ['plan_key' => 'pro', 'name' => 'Pro', 'sort_order' => 5]);
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $id = $this->decode($res)['data']['id'];

        $show = $this->op('show', ['id' => (string) $id]);
        self::assertSame(200, $show->getStatusCode());
        self::assertSame('pro', $this->decode($show)['data']['plan_key']);

        $list = $this->decode($this->op('list', []))['data'];
        self::assertCount(1, $list);
    }

    public function testCreateRejectsDuplicateAndMalformedKey(): void
    {
        $this->op('create', [], ['plan_key' => 'pro', 'name' => 'Pro']);
        self::assertSame(422, $this->op('create', [], ['plan_key' => 'pro', 'name' => 'Dup'])->getStatusCode());
        self::assertSame(422, $this->op('create', [], ['plan_key' => 'Bad Key!', 'name' => 'X'])->getStatusCode());
    }

    public function testSetEntitlementsBundleAndValidation(): void
    {
        $id = $this->decode($this->op('create', [], ['plan_key' => 'pro', 'name' => 'Pro']))['data']['id'];

        $ok = $this->op('setEntitlements', ['id' => (string) $id], [
            'entitlements' => [
                EntitlementRegistry::STORAGE_CUSTOM_BACKEND => true,
                EntitlementRegistry::MEMBERS_MAX => 50,
            ],
        ]);
        self::assertSame(200, $ok->getStatusCode(), $ok->getBody());
        $bundle = $this->decode($ok)['data']['entitlements'];
        self::assertTrue($bundle[EntitlementRegistry::STORAGE_CUSTOM_BACKEND]);
        self::assertSame(50, $bundle[EntitlementRegistry::MEMBERS_MAX]);

        // Unknown key → 422.
        $bad = $this->op('setEntitlements', ['id' => (string) $id], ['entitlements' => ['made.up' => 'true']]);
        self::assertSame(422, $bad->getStatusCode());
    }

    public function testDeletePlanThen404(): void
    {
        $id = $this->decode($this->op('create', [], ['plan_key' => 'pro', 'name' => 'Pro']))['data']['id'];
        self::assertSame(204, $this->op('destroy', ['id' => (string) $id])->getStatusCode());
        self::assertSame(404, $this->op('show', ['id' => (string) $id])->getStatusCode());
    }

    // ── apply to tenant (materialise) ───────────────────────────────────────

    public function testApplyPlanToTenantMaterialisesEntitlements(): void
    {
        $id = $this->decode($this->op('create', [], ['plan_key' => 'pro', 'name' => 'Pro']))['data']['id'];
        $this->op('setEntitlements', ['id' => (string) $id], [
            'entitlements' => [EntitlementRegistry::STORAGE_CUSTOM_BACKEND => true, EntitlementRegistry::MEMBERS_MAX => 50],
        ]);

        $apply = $this->op('applyToTenant', ['id' => (string) self::TARGET_TENANT], ['plan_id' => $id]);
        self::assertSame(200, $apply->getStatusCode(), $apply->getBody());
        self::assertSame($id, $this->decode($apply)['data']['plan_id']);

        // The runtime gate now reflects the plan for the target tenant.
        self::assertTrue($this->entitlements->isGranted(self::TARGET_TENANT, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
        self::assertSame(50, $this->entitlements->limit(self::TARGET_TENANT, EntitlementRegistry::MEMBERS_MAX));

        // GET reflects it.
        $get = $this->op('getTenantPlan', ['id' => (string) self::TARGET_TENANT]);
        self::assertSame($id, $this->decode($get)['data']['plan_id']);
    }

    public function testApplyUnknownPlanIs422(): void
    {
        self::assertSame(422, $this->op('applyToTenant', ['id' => (string) self::TARGET_TENANT], ['plan_id' => 99999])->getStatusCode());
    }

    public function testApplyMissingPlanIdIs422(): void
    {
        self::assertSame(422, $this->op('applyToTenant', ['id' => (string) self::TARGET_TENANT], [])->getStatusCode());
    }

    public function testApplyToUnknownTenantIs404(): void
    {
        $id = $this->decode($this->op('create', [], ['plan_key' => 'pro', 'name' => 'Pro']))['data']['id'];
        self::assertSame(404, $this->op('applyToTenant', ['id' => '99999'], ['plan_id' => $id])->getStatusCode());
    }

    public function testApplyToSystemTenantIs422(): void
    {
        $id = $this->decode($this->op('create', [], ['plan_key' => 'pro', 'name' => 'Pro']))['data']['id'];
        self::assertSame(422, $this->op('applyToTenant', ['id' => '0'], ['plan_id' => $id])->getStatusCode());
    }

    public function testGetTenantPlanNullWhenUnassigned(): void
    {
        $res = $this->op('getTenantPlan', ['id' => (string) self::TARGET_TENANT]);
        self::assertSame(200, $res->getStatusCode());
        self::assertNull($this->decode($res)['data']);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $body
     */
    private function op(string $method, array $params, array $body = []): \Whity\Sdk\Http\Response
    {
        return $this->call($method, $params, self::OPERATOR, self::SYSTEM_TENANT, $body);
    }

    /**
     * @param array<string, string> $params
     * @param array<string, mixed> $body
     */
    private function call(string $method, array $params, int $userId, int $ctxTenant, array $body = []): \Whity\Sdk\Http\Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($ctxTenant);
        $request = new Request('POST', '/api/plans', [], $body !== [] ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $ctxTenant];

        return $params === []
            ? $this->handler->{$method}($request)
            : $this->handler->{$method}($request, $params);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $d = json_decode($res->getBody(), true);
        self::assertIsArray($d, $res->getBody());
        return $d;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'target', 'target'), (2, 'other', 'other')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (101, 'no-perm', '', 0, datetime('now'))");
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'operator',      'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'tenant2-admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'sys-noperm',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 0, 1,   'active', datetime('now')),
                (1001, 11, 2, 1,   'active', datetime('now')),
                (1002, 12, 0, 101, 'active', datetime('now'))
        ");
        return $pdo;
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        return $db;
    }
}
