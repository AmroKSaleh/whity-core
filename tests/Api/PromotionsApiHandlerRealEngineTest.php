<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PromotionsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Promotion\PromotionRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * The operator surface over promotions.
 *
 * ONE ENDPOINT FOR THREE THINGS, because an early bird, an offer and a promo
 * code differ only in whether a `code` is present. The tests below create both
 * shapes through the same call and assert the difference is exactly that field.
 *
 * The GATE is `plans:manage` AND the system tenant. A promotion is a
 * platform-wide commercial fact: a tenant admin holding the permission through
 * the global admin role could otherwise mint themselves a hundred-per-cent
 * discount, which is the sharpest version of why the permission alone is not
 * sufficient anywhere in this area.
 */
final class PromotionsApiHandlerRealEngineTest extends TestCase
{
    private const OPERATOR = 10;
    private const TENANT_ADMIN = 11;

    private const SYSTEM_TENANT = 0;
    private const OTHER_TENANT = 1;
    private const THIRD_TENANT = 2;

    private PDO $pdo;
    private PromotionsApiHandler $handler;
    private PromotionRepository $promotions;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make(true);

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        // Two non-system tenants, because "this early bird was taken twice" is
        // only representable with two distinct takers. Both are real rows: the
        // redemption ledger has a real foreign key, and SQLite does not enforce
        // one unless asked, so a fabricated tenant id passes locally and fails
        // on the PostgreSQL dialect shard.
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'other', 'other')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'third', 'third')");
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

        $this->promotions = new PromotionRepository($this->pdo);
        $this->handler = new PromotionsApiHandler(
            $this->promotions,
            new RoleChecker($this->wrapSqlite($this->pdo), new PermissionRegistry())
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── the gate ─────────────────────────────────────────────────────────────

    /**
     * The sharpest version of "the permission is not sufficient": this tenant
     * admin holds `plans:manage` through the global admin role and would
     * otherwise be able to mint themselves a hundred-per-cent discount.
     */
    public function testATenantAdminCannotMintThemselvesADiscount(): void
    {
        $res = $this->handler->create($this->actAs(self::TENANT_ADMIN, self::OTHER_TENANT, [
            'name' => 'Free for me',
            'percent_off' => 100,
            'code' => 'MINE',
        ]));

        self::assertSame(403, $res->getStatusCode());
        self::assertStringContainsString('system tenant', (string) $res->getBody());
        self::assertSame([], $this->promotions->listAll(), 'and nothing was created');
    }

    // ── one object, two discoveries ──────────────────────────────────────────

    public function testACodedPromotionIsCreatedWithItsCode(): void
    {
        $res = $this->create(['name' => 'Summer', 'percent_off' => 20, 'code' => 'summer24']);

        self::assertSame(201, $res->getStatusCode());
        $row = json_decode((string) $res->getBody(), true)['data'];
        // Upper-cased, so a customer cannot fail to redeem it by not matching
        // the operator's shift key.
        self::assertSame('SUMMER24', $row['code']);
    }

    /** No code = an early bird. The SAME endpoint, one field lighter. */
    public function testAnEarlyBirdIsTheSameCallWithoutACode(): void
    {
        $res = $this->create(['name' => 'First fifty', 'percent_off' => 30, 'max_redemptions' => 50]);

        self::assertSame(201, $res->getStatusCode());
        $row = json_decode((string) $res->getBody(), true)['data'];
        self::assertNull($row['code']);
        self::assertSame(50, $row['max_redemptions']);
    }

    /** An empty-string code is an absent one, not a promotion coded "". */
    public function testAnEmptyCodeIsTreatedAsAbsent(): void
    {
        $row = json_decode((string) $this->create([
            'name' => 'Blank', 'percent_off' => 10, 'code' => '   ',
        ])->getBody(), true)['data'];

        self::assertNull($row['code']);
    }

    // ── percentage or amount, never both ─────────────────────────────────────

    public function testAFixedAmountNeedsACurrency(): void
    {
        $res = $this->create(['name' => 'Fifty off', 'amount_off' => 5000]);

        self::assertSame(422, $res->getStatusCode());
    }

    /**
     * A DECIMAL IS REFUSED. 50.5 meaning "50.50" would truncate to 50 — a
     * hundredth of the intended discount, and plausible-looking.
     */
    public function testADecimalAmountIsRefused(): void
    {
        $res = $this->create(['name' => 'Bad', 'amount_off' => 50.5, 'currency' => 'SAR']);

        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('minor units', (string) $res->getBody());
    }

    public function testAPromotionWithNeitherKindIsRefused(): void
    {
        $res = $this->create(['name' => 'Nothing']);

        self::assertSame(422, $res->getStatusCode());
    }

    public function testAPercentageOutsideTheRangeIsRefused(): void
    {
        self::assertSame(422, $this->create(['name' => 'Too much', 'percent_off' => 101])->getStatusCode());
        self::assertSame(422, $this->create(['name' => 'None', 'percent_off' => 0])->getStatusCode());
    }

    // ── codes are unique among live promotions ───────────────────────────────

    public function testASecondLivePromotionCannotReuseACode(): void
    {
        $this->create(['name' => 'First', 'percent_off' => 20, 'code' => 'DUP']);
        $res = $this->create(['name' => 'Second', 'percent_off' => 30, 'code' => 'DUP']);

        self::assertSame(409, $res->getStatusCode());
        self::assertStringContainsString('Retire it first', (string) $res->getBody());
    }

    /**
     * And retiring frees it, because operators re-run the same seasonal name
     * every year.
     */
    public function testRetiringFreesTheCodeForNextYear(): void
    {
        $first = json_decode((string) $this->create([
            'name' => 'Summer 2025', 'percent_off' => 20, 'code' => 'SUMMER',
        ])->getBody(), true)['data'];

        $retired = $this->handler->retire(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT),
            ['id' => (string) $first['id']]
        );
        self::assertSame(200, $retired->getStatusCode());
        self::assertFalse(json_decode((string) $retired->getBody(), true)['data']['is_active']);

        self::assertSame(
            201,
            $this->create(['name' => 'Summer 2026', 'percent_off' => 25, 'code' => 'SUMMER'])->getStatusCode()
        );
    }

    // ── the listing ──────────────────────────────────────────────────────────

    /**
     * The count travels WITH the row. Asking per row would be one request each
     * — forty campaigns becoming forty-one round trips, which is how a
     * management screen becomes one nobody opens.
     */
    public function testTheListingCarriesRedemptionCounts(): void
    {
        $row = json_decode((string) $this->create([
            'name' => 'First fifty', 'percent_off' => 50, 'max_redemptions' => 50,
        ])->getBody(), true)['data'];

        $this->promotions->recordRedemption($row['id'], self::OTHER_TENANT);
        $this->promotions->recordRedemption($row['id'], self::THIRD_TENANT);

        $listed = json_decode(
            (string) $this->handler->list($this->actAs(self::OPERATOR, self::SYSTEM_TENANT))->getBody(),
            true
        )['data'];

        self::assertCount(1, $listed);
        self::assertSame(2, $listed[0]['redemption_count']);
        self::assertSame(50, $listed[0]['max_redemptions']);
    }

    /** Retired ones are listed: a campaign that ended explains a live discount. */
    public function testTheListingIncludesRetiredPromotions(): void
    {
        $row = json_decode((string) $this->create([
            'name' => 'Old', 'percent_off' => 20, 'code' => 'OLD',
        ])->getBody(), true)['data'];
        $this->handler->retire($this->actAs(self::OPERATOR, self::SYSTEM_TENANT), ['id' => (string) $row['id']]);

        $listed = json_decode(
            (string) $this->handler->list($this->actAs(self::OPERATOR, self::SYSTEM_TENANT))->getBody(),
            true
        )['data'];

        self::assertCount(1, $listed);
        self::assertFalse($listed[0]['is_active']);
    }

    public function testRetiringSomethingThatIsNotThereIs404(): void
    {
        $res = $this->handler->retire($this->actAs(self::OPERATOR, self::SYSTEM_TENANT), ['id' => '9999']);

        self::assertSame(404, $res->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $body */
    private function create(array $body): \Whity\Core\Response
    {
        return $this->handler->create($this->actAs(self::OPERATOR, self::SYSTEM_TENANT, $body));
    }

    /**
     * @param array<string, mixed>|null $body
     *
     * Resets before setting: `setTenantId` locks on first set, so a test making
     * two calls would otherwise fail on the second with "context is locked"
     * rather than on anything it meant to assert.
     */
    private function actAs(int $profileId, int $tenantId, ?array $body = null): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);

        $request = new Request('POST', '/api/promotions', [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /** The harness's way of handing a raw PDO to code that wants a Database. */
    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
