<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\SubscriptionApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Database\Database;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Plan\PlanService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see SubscriptionApiHandler} (WC-billing): the operator
 * sets a target tenant's billing state (system-tenant gated) and applying a plan
 * materialises its entitlements; the tenant-self read view. Also the cross-tenant
 * escalation guard and error paths.
 */
final class SubscriptionApiHandlerRealEngineTest extends TestCase
{
    private const OPERATOR      = 10; // system tenant 0, admin
    private const TENANT2_ADMIN = 11; // tenant 2 admin (holds perm, NOT system)
    private const SYSTEM_TENANT = 0;
    private const TARGET        = 1;

    private PDO $pdo;
    private EntitlementService $entitlements;
    private PlanService $plans;
    private SubscriptionApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);
        $settings = new SettingsService(new GlobalSettingsRepository($this->pdo), new TenantSettingsRepository($this->pdo));
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->plans = new PlanService(new PlanRepository($this->pdo), $this->entitlements, $this->pdo);
        $subscriptions = new SubscriptionService(new SubscriptionRepository($this->pdo), $settings);
        $this->handler = new SubscriptionApiHandler($subscriptions, $this->plans, new RoleChecker($db, new PermissionRegistry()), $this->pdo);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    private function proPlan(): int
    {
        $id = $this->plans->createPlan('pro', 'Pro');
        $this->plans->setPlanEntitlement($id, EntitlementRegistry::STORAGE_CUSTOM_BACKEND, 'true');
        return $id;
    }

    // ── the system-tenant gate ──────────────────────────────────────────────

    public function testNonSystemAdminIsBlocked(): void
    {
        $get = $this->call('getForTenant', ['id' => (string) self::TARGET], self::TENANT2_ADMIN, 2);
        self::assertSame(403, $get->getStatusCode());

        $put = $this->call('setForTenant', ['id' => (string) self::TARGET], self::TENANT2_ADMIN, 2, ['status' => 'active']);
        self::assertSame(403, $put->getStatusCode());
    }

    // ── operator flow ───────────────────────────────────────────────────────

    public function testOperatorSetsSubscriptionAndApplyingPlanMaterialisesEntitlements(): void
    {
        $planId = $this->proPlan();
        $res = $this->op('setForTenant', ['id' => (string) self::TARGET], [
            'plan_id' => $planId,
            'status' => SubscriptionService::STATUS_ACTIVE,
            'enforcement_mode' => SubscriptionService::MODE_BLOCK_WRITES,
        ]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $data = $this->decode($res)['data'];
        self::assertSame(SubscriptionService::STATUS_ACTIVE, $data['status']);
        self::assertSame('pro', $data['plan']['plan_key']);
        self::assertSame(SubscriptionService::MODE_BLOCK_WRITES, $data['effective_enforcement_mode']);

        // The plan's entitlements were materialised (the tier's feature flags).
        self::assertTrue($this->entitlements->isGranted(self::TARGET, EntitlementRegistry::STORAGE_CUSTOM_BACKEND));
    }

    public function testGetForTenantReflectsState(): void
    {
        $this->op('setForTenant', ['id' => (string) self::TARGET], ['status' => SubscriptionService::STATUS_PAST_DUE]);
        $data = $this->decode($this->op('getForTenant', ['id' => (string) self::TARGET]))['data'];
        self::assertSame(SubscriptionService::STATUS_PAST_DUE, $data['status']);
        // Operator view exposes the enforcement policy fields.
        self::assertArrayHasKey('enforcement_mode', $data);
        self::assertArrayHasKey('external_ref', $data);
    }

    public function testSelfViewIsReadOnlyAndOmitsPolicyFields(): void
    {
        $this->op('setForTenant', ['id' => (string) self::TARGET], [
            'status' => SubscriptionService::STATUS_ACTIVE,
            'external_ref' => 'sub_secret_123',
        ]);

        // The tenant-2 admin views its OWN tenant (2) — but we want tenant 1's
        // self view, so act as a tenant-1 member. Seed one implicitly by context.
        TenantContext::reset();
        TenantContext::setTenantId(self::TARGET);
        $req = new Request('GET', '/api/subscription', [], '');
        $req->user = (object) ['profile_id' => 999, 'active_tenant_id' => self::TARGET];
        $res = $this->handler->getSelf($req);

        self::assertSame(200, $res->getStatusCode());
        $data = $this->decode($res)['data'];
        self::assertSame(SubscriptionService::STATUS_ACTIVE, $data['status']);
        self::assertArrayNotHasKey('enforcement_mode', $data, 'self view omits policy');
        self::assertArrayNotHasKey('external_ref', $data, 'self view omits provider ref');
        self::assertStringNotContainsString('sub_secret_123', $res->getBody());
    }

    // ── error paths ─────────────────────────────────────────────────────────

    public function testSetSystemTenantIs409(): void
    {
        self::assertSame(409, $this->op('setForTenant', ['id' => '0'], ['status' => 'active'])->getStatusCode());
    }

    public function testUnknownTenantIs404(): void
    {
        self::assertSame(404, $this->op('getForTenant', ['id' => '9999'])->getStatusCode());
        self::assertSame(404, $this->op('setForTenant', ['id' => '9999'], ['status' => 'active'])->getStatusCode());
    }

    public function testInvalidStatusIs422(): void
    {
        self::assertSame(422, $this->op('setForTenant', ['id' => (string) self::TARGET], ['status' => 'gremlin'])->getStatusCode());
    }

    public function testUnknownPlanIs422(): void
    {
        self::assertSame(422, $this->op('setForTenant', ['id' => (string) self::TARGET], ['plan_id' => 99999])->getStatusCode());
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
        $req = new Request('PUT', '/api/tenants/x/subscription', [], $body !== [] ? (string) json_encode($body) : '');
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $ctxTenant];

        return $this->handler->{$method}($req, $params);
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
        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'operator',      'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'tenant2-admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 0, 1, 'active', datetime('now')),
                (1001, 11, 2, 1, 'active', datetime('now'))
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
