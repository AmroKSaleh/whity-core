<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PlanPricesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Plan\PlanPriceRepository;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * The operator surface over plan prices.
 *
 * TWO THINGS MATTER MORE THAN THE CRUD.
 *
 * The SYSTEM-TENANT GATE. `plans:manage` is necessary but not sufficient: a
 * regular tenant admin holds it through the global admin role, so without the
 * extra check they could reprice the product for everybody. Mirrors
 * PlansApiHandler, and is asserted here rather than assumed because "the
 * permission was enough" is exactly the mistake this pattern exists to stop.
 *
 * And the DECIMAL REFUSAL. `unit_amount` is minor units; a float arriving as
 * 49.9 for "49.90" would truncate to 49, a hundredfold error that looks like a
 * plausible price. Refused with a message that says what to send instead.
 */
final class PlanPricesApiHandlerRealEngineTest extends TestCase
{
    private const OPERATOR = 10;   // system tenant, admin role
    private const TENANT_ADMIN = 11; // tenant 1, admin role — holds the permission, wrong tenant

    private const SYSTEM_TENANT = 0;
    private const OTHER_TENANT = 1;

    private PDO $pdo;
    private PlanPricesApiHandler $handler;
    private PlanPriceRepository $prices;
    private int $planId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make(true);

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'other', 'other')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $this->pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'operator', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'tenant-admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");
        $this->pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (10, 0, 1, 'active', CURRENT_TIMESTAMP),
                (11, 1, 1, 'active', CURRENT_TIMESTAMP)
        ");

        $db = $this->wrapSqlite($this->pdo);
        $plans = new PlanRepository($this->pdo);
        $this->planId = $plans->createPlan('pro', 'Pro', null, true, 0);
        $this->prices = new PlanPriceRepository($this->pdo);

        $this->handler = new PlanPricesApiHandler(
            $this->prices,
            $plans,
            new RoleChecker($db, new PermissionRegistry())
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── the gate ─────────────────────────────────────────────────────────────

    /**
     * Holding `plans:manage` in a REGULAR tenant is not enough. The tenant admin
     * below has the permission through the global admin role; the catalogue is
     * still not theirs to change.
     */
    public function testATenantAdminHoldingThePermissionIsStillRefused(): void
    {
        $res = $this->handler->list($this->actAs(self::TENANT_ADMIN, self::OTHER_TENANT), ['id' => (string) $this->planId]);

        self::assertSame(403, $res->getStatusCode());
        self::assertStringContainsString('system tenant', (string) $res->getBody());
    }

    public function testTheOperatorInTheSystemTenantMayRead(): void
    {
        $res = $this->handler->list($this->actAs(self::OPERATOR, self::SYSTEM_TENANT), ['id' => (string) $this->planId]);

        self::assertSame(200, $res->getStatusCode());
    }

    // ── minor units ──────────────────────────────────────────────────────────

    public function testAPriceIsCreatedInMinorUnits(): void
    {
        $res = $this->create(['currency' => 'SAR', 'unit_amount' => 4900, 'billing_period' => 'month']);

        self::assertSame(201, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        self::assertSame(4900, $body['data']['unit_amount']);
        self::assertSame('SAR', $body['data']['currency']);
    }

    /**
     * A DECIMAL IS REFUSED. 49.9 meaning "49.90" would truncate to 49 — a
     * hundredfold error producing a perfectly plausible price — so the boundary
     * says what to send instead rather than storing a tenth of it.
     */
    public function testADecimalAmountIsRefusedWithAnExplanation(): void
    {
        $res = $this->create(['currency' => 'SAR', 'unit_amount' => 49.9, 'billing_period' => 'month']);

        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('minor units', (string) $res->getBody());
    }

    public function testAStringAmountIsRefusedToo(): void
    {
        $res = $this->create(['currency' => 'SAR', 'unit_amount' => '4900', 'billing_period' => 'month']);

        self::assertSame(422, $res->getStatusCode());
    }

    public function testABadCurrencyIsRefused(): void
    {
        $res = $this->create(['currency' => 'RIYAL', 'unit_amount' => 4900, 'billing_period' => 'month']);

        self::assertSame(422, $res->getStatusCode());
    }

    // ── one live price per set of terms ──────────────────────────────────────

    /**
     * A SECOND live price on the same terms is a CONFLICT with a reason, not a
     * 500 about an index name. Two of them would make the checkout, the invoice
     * and the price list each pick differently.
     */
    public function testASecondLivePriceOnTheSameTermsIsAConflict(): void
    {
        $this->create(['currency' => 'SAR', 'unit_amount' => 4900, 'billing_period' => 'month']);
        $res = $this->create(['currency' => 'SAR', 'unit_amount' => 5900, 'billing_period' => 'month']);

        self::assertSame(409, $res->getStatusCode());
        self::assertStringContainsString('Retire', (string) $res->getBody());
    }

    public function testDifferentTermsCoexist(): void
    {
        self::assertSame(201, $this->create(['currency' => 'SAR', 'unit_amount' => 4900, 'billing_period' => 'month'])->getStatusCode());
        self::assertSame(201, $this->create(['currency' => 'SAR', 'unit_amount' => 49000, 'billing_period' => 'year'])->getStatusCode());
        self::assertSame(201, $this->create(['currency' => 'USD', 'unit_amount' => 1300, 'billing_period' => 'month'])->getStatusCode());
        self::assertSame(201, $this->create(['currency' => 'SAR', 'unit_amount' => 1900, 'billing_period' => 'month', 'is_per_seat' => true])->getStatusCode());
    }

    // ── retiring ─────────────────────────────────────────────────────────────

    /**
     * RETIRES rather than destroys, and the response proves it: the row comes
     * back, inactive. A 204 would leave a client unable to tell that the record
     * of what somebody was charged still exists.
     */
    public function testRetiringKeepsTheRowAndReturnsIt(): void
    {
        $created = json_decode((string) $this->create([
            'currency' => 'SAR', 'unit_amount' => 4900, 'billing_period' => 'month',
        ])->getBody(), true);
        $priceId = $created['data']['id'];

        $res = $this->handler->retire(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT),
            ['id' => (string) $this->planId, 'priceId' => (string) $priceId]
        );

        self::assertSame(200, $res->getStatusCode());
        $body = json_decode((string) $res->getBody(), true);
        self::assertFalse($body['data']['is_active']);
        self::assertNotNull($this->prices->findById($priceId), 'the row survives');
    }

    /** And the slot is freed, so the replacement can be created at once. */
    public function testRetiringFreesTheSlotForAReplacement(): void
    {
        $created = json_decode((string) $this->create([
            'currency' => 'SAR', 'unit_amount' => 4900, 'billing_period' => 'month',
        ])->getBody(), true);

        $this->handler->retire(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT),
            ['id' => (string) $this->planId, 'priceId' => (string) $created['data']['id']]
        );

        self::assertSame(
            201,
            $this->create(['currency' => 'SAR', 'unit_amount' => 5900, 'billing_period' => 'month'])->getStatusCode()
        );
    }

    /** A price belonging to another plan is not this plan's to retire. */
    public function testAPriceFromAnotherPlanIsNotFound(): void
    {
        $otherPlan = (new PlanRepository($this->pdo))->createPlan('lite', 'Lite', null, true, 1);
        $priceId = $this->prices->create($otherPlan, 'SAR', 1000, 'month');

        $res = $this->handler->retire(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT),
            ['id' => (string) $this->planId, 'priceId' => (string) $priceId]
        );

        self::assertSame(404, $res->getStatusCode());
    }

    // ── reading ──────────────────────────────────────────────────────────────

    /**
     * Retired prices are LISTED. A list of only live ones cannot explain a
     * charge somebody is querying, which is the question the screen exists for.
     */
    public function testTheListingIncludesRetiredPrices(): void
    {
        $created = json_decode((string) $this->create([
            'currency' => 'SAR', 'unit_amount' => 4900, 'billing_period' => 'month',
        ])->getBody(), true);
        $this->handler->retire(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT),
            ['id' => (string) $this->planId, 'priceId' => (string) $created['data']['id']]
        );

        $body = json_decode(
            (string) $this->handler->list($this->actAs(self::OPERATOR, self::SYSTEM_TENANT), ['id' => (string) $this->planId])->getBody(),
            true
        );

        self::assertCount(1, $body['data']);
        self::assertFalse($body['data'][0]['is_active']);
    }

    public function testAnUnknownPlanIsNotFound(): void
    {
        $res = $this->handler->list($this->actAs(self::OPERATOR, self::SYSTEM_TENANT), ['id' => '9999']);

        self::assertSame(404, $res->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function create(array $body): \Whity\Core\Response
    {
        return $this->handler->create(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT, $body),
            ['id' => (string) $this->planId]
        );
    }

    /**
     * The shared harness's way of handing a raw PDO to code that wants a
     * Database — its constructor is private, so a test cannot simply wrap one.
     */
    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * Resets before setting: `setTenantId` LOCKS on first set, so a test making
     * two calls would otherwise fail on the second with "context is locked"
     * rather than on anything it meant to assert. The same pattern the
     * entitlements test uses, and for the same reason.
     */
    private function actAs(int $profileId, int $tenantId, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        $request = new Request('POST', '/api/plans', [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }
}
