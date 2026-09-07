<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Seat;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Seat\SeatService;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Seats: how many people a tenant may have, and whether it may take one more.
 *
 * THE LIMIT WAS ALREADY SOLD AND NEVER ENFORCED. `members.max` is an
 * entitlement, documented as "maximum number of active members the tenant may
 * have", assignable to a plan and asserted in the plans test — and read by
 * nothing. An operator could put 50 on a plan and a tenant could add five
 * thousand.
 *
 * Two things are configurable because instances genuinely differ, and both are
 * exercised below: whether an outstanding INVITATION holds a seat, and what
 * happens at the limit. The second is the question that should never be
 * answered by guessing, so it is an operator setting with the same three-way
 * shape the payment wall uses.
 */
final class SeatServiceRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const SYSTEM = 0;

    private PDO $pdo;
    private SettingsService $settings;
    private EntitlementService $entitlements;
    private SeatService $seats;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'one', 'one')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $this->entitlements = new EntitlementService(new TenantEntitlementRepository($this->pdo));
        $this->seats = new SeatService($this->pdo, $this->entitlements, $this->settings);
    }

    // ── counting ─────────────────────────────────────────────────────────────

    public function testAnEmptyTenantUsesNoSeats(): void
    {
        self::assertSame(0, $this->seats->used(self::TENANT));
    }

    public function testActiveMembersUseSeats(): void
    {
        $this->member(10, MembershipRepository::STATUS_ACTIVE);
        $this->member(11, MembershipRepository::STATUS_ACTIVE);

        self::assertSame(2, $this->seats->used(self::TENANT));
    }

    /**
     * Suspending somebody is how a tenant FREES a seat, so a suspended
     * membership must never hold one.
     */
    public function testSuspendedMembersDoNotUseSeats(): void
    {
        $this->member(10, MembershipRepository::STATUS_ACTIVE);
        $this->member(11, MembershipRepository::STATUS_SUSPENDED);

        self::assertSame(1, $this->seats->used(self::TENANT));
    }

    /**
     * An invitation is a seat somebody has been PROMISED. Counting it is the
     * default because not counting it lets a tenant invite a thousand people
     * past a limit of ten and reach it the moment they accept.
     */
    public function testAnInvitationHoldsASeatByDefault(): void
    {
        $this->member(10, MembershipRepository::STATUS_INVITED);
        self::assertSame(1, $this->seats->used(self::TENANT));
    }

    public function testAnInstanceCanChooseNotToCountInvitations(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SEATS_COUNT_INVITED, 'false');
        $this->member(10, MembershipRepository::STATUS_INVITED);

        self::assertSame(0, $this->seats->used(self::TENANT));
    }

    /**
     * ONE PERSON IS ONE SEAT, and the schema is what guarantees it: `memberships`
     * carries UNIQUE (profile_id, tenant_id), so a person cannot hold two rows
     * in one tenant. The count is written with DISTINCT anyway — the number is
     * what a tenant is billed for, and if that constraint were ever relaxed a
     * plain count would quietly start charging twice.
     */
    public function testTheSchemaItselfKeepsAPersonToOneMembership(): void
    {
        $this->member(10, MembershipRepository::STATUS_ACTIVE);

        $this->expectException(\PDOException::class);
        $this->member(10, MembershipRepository::STATUS_ACTIVE, ouId: 7);
    }

    /**
     * And bringing a suspended member back TAKES a seat, because a suspended one
     * holds none. The same rule read from the other side.
     */
    public function testReactivatingASuspendedMemberTakesASeatAgain(): void
    {
        $this->member(10, MembershipRepository::STATUS_SUSPENDED);
        self::assertSame(0, $this->seats->used(self::TENANT));

        $this->pdo->exec(
            "UPDATE memberships SET status = '" . MembershipRepository::STATUS_ACTIVE . "' WHERE profile_id = 10"
        );
        self::assertSame(1, $this->seats->used(self::TENANT));
    }

    // ── the decision ─────────────────────────────────────────────────────────

    public function testUnlimitedIsTheDefaultAndAllowsAnything(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->member(100 + $i, MembershipRepository::STATUS_ACTIVE);
        }

        $decision = $this->seats->decide(self::TENANT);
        self::assertTrue($decision->allowed);
        self::assertFalse($decision->atLimit);
        self::assertSame(EntitlementRegistry::UNLIMITED, $decision->limit);
    }

    public function testUnderTheLimitIsAllowedAndNotAtLimit(): void
    {
        $this->seatsFor(3);
        $this->member(10, MembershipRepository::STATUS_ACTIVE);

        $decision = $this->seats->decide(self::TENANT);
        self::assertTrue($decision->allowed);
        self::assertFalse($decision->atLimit);
        self::assertSame(3, $decision->limit);
        self::assertSame(1, $decision->used);
    }

    /**
     * THE DIFFERENCE BETWEEN warn AND off. Both allow the addition; only `warn`
     * reports that the limit has been reached. Without that a tenant which has
     * quietly run out of seats looks exactly like one whose instance does not
     * sell them, and nobody is ever told to buy more.
     */
    public function testWarnAllowsButReportsTheLimit(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SEATS_ENFORCEMENT, SeatService::MODE_WARN);
        $this->seatsFor(1);
        $this->member(10, MembershipRepository::STATUS_ACTIVE);

        $decision = $this->seats->decide(self::TENANT);
        self::assertTrue($decision->allowed, 'warn must never refuse');
        self::assertTrue($decision->atLimit, 'warn must still say the limit is reached');
    }

    public function testOffDoesNotEvenCount(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SEATS_ENFORCEMENT, SeatService::MODE_OFF);
        $this->seatsFor(1);
        $this->member(10, MembershipRepository::STATUS_ACTIVE);

        $decision = $this->seats->decide(self::TENANT);
        self::assertTrue($decision->allowed);
        self::assertFalse($decision->atLimit);
    }

    public function testBlockRefusesAtTheLimit(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SEATS_ENFORCEMENT, SeatService::MODE_BLOCK);
        $this->seatsFor(2);
        $this->member(10, MembershipRepository::STATUS_ACTIVE);
        $this->member(11, MembershipRepository::STATUS_ACTIVE);

        $decision = $this->seats->decide(self::TENANT);
        self::assertFalse($decision->allowed);
        self::assertTrue($decision->atLimit);
        self::assertStringContainsString('2 of its 2', $decision->message());
    }

    /**
     * A TENANT CANNOT LOWER ITS OWN ENFORCEMENT, because both seat settings are
     * GLOBAL-ONLY — the property `SettingsApiHandler` refuses a per-tenant write
     * on ("is a global instance setting and cannot be set per-tenant").
     *
     * Asserted as the property rather than by calling `setTenant`, which is the
     * operator-side service and deliberately does not carry that guard: it is
     * the API surface a tenant admin reaches, and the surface is where the rule
     * belongs. Seat policy a tenant could set to `off` would not be policy.
     */
    public function testBothSeatSettingsAreGlobalOnlySoATenantCannotLowerThem(): void
    {
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::SEATS_ENFORCEMENT));
        self::assertTrue(SettingsRegistry::isGlobalOnly(SettingsRegistry::SEATS_COUNT_INVITED));
    }

    /**
     * Somebody who already holds a seat is not taking another one — otherwise
     * giving an existing member a second role would be refused at the limit,
     * which is a change that adds nobody.
     */
    public function testAnExistingMemberDoesNotNeedASpareSeat(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SEATS_ENFORCEMENT, SeatService::MODE_BLOCK);
        $this->seatsFor(1);
        $this->member(10, MembershipRepository::STATUS_ACTIVE);

        self::assertFalse($this->seats->decide(self::TENANT, 99)->allowed, 'a new person is refused');
        self::assertTrue($this->seats->decide(self::TENANT, 10)->allowed, 'an existing one is not');
    }

    /** The system tenant is never seat-limited, as it is never payment-walled. */
    public function testTheSystemTenantIsNeverLimited(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SEATS_ENFORCEMENT, SeatService::MODE_BLOCK);
        // It cannot even be GIVEN a limit — the entitlement service refuses an
        // override layer for tenant 0 — so the short-circuit is the only thing
        // standing between an operator and a locked-out instance.
        self::assertTrue($this->seats->decide(self::SYSTEM)->allowed);
    }

    // ── going over ───────────────────────────────────────────────────────────

    /**
     * NOBODY IS EVER REMOVED. A plan downgraded beneath the headcount leaves
     * every existing member exactly as they are and refuses only the NEXT
     * addition. Auto-suspending to fit would lock real people out of their work
     * to satisfy a number an administrator changed.
     */
    public function testGoingOverTheLimitRemovesNobody(): void
    {
        $this->settings->setGlobal(SettingsRegistry::SEATS_ENFORCEMENT, SeatService::MODE_BLOCK);
        $this->seatsFor(10);
        for ($i = 0; $i < 6; $i++) {
            $this->member(100 + $i, MembershipRepository::STATUS_ACTIVE);
        }

        // The plan shrinks beneath the headcount.
        $this->seatsFor(3);

        $decision = $this->seats->decide(self::TENANT);
        self::assertFalse($decision->allowed, 'no more may be added');
        self::assertSame(6, $decision->used, 'and the six who are here are still here');
        self::assertSame(6, $this->seats->used(self::TENANT));
    }

    /**
     * An unrecognised stored mode falls back to `warn`, never to `block`. A
     * value nobody meant must not be the one that starts refusing people.
     */
    public function testAnUnrecognisedModeFallsBackToWarnNotBlock(): void
    {
        (new GlobalSettingsRepository($this->pdo))->set(SettingsRegistry::SEATS_ENFORCEMENT, 'nonsense');

        self::assertSame(SeatService::MODE_WARN, $this->seats->enforcementMode(self::TENANT));
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seatsFor(int $count): void
    {
        $this->entitlements->set(self::TENANT, EntitlementRegistry::MEMBERS_MAX, (string) $count);
    }

    private function member(int $profileId, string $status, ?int $ouId = null): void
    {
        $this->pdo->exec(
            "INSERT OR IGNORE INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ({$profileId}, 'p{$profileId}', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        );

        $ou = $ouId === null ? 'NULL' : (string) $ouId;
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES ({$profileId}, " . self::TENANT . ", 1, {$ou}, '{$status}', CURRENT_TIMESTAMP)"
        );
    }
}
