<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\AffiliatesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Affiliate\AffiliateRepository;
use Whity\Core\Affiliate\PayoutAssembler;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * The operator surface over the people who send us customers.
 *
 * ── What this endpoint is actually for ─────────────────────────────────────
 *
 * Every other part of the affiliate programme was unreachable without it: the
 * attribution on signup, the commission ledger and the nightly accrual all
 * depend on a row in `affiliates` that nothing else could create. So the first
 * thing pinned here is simply that a code can be brought into existence — the
 * kind of gap that produces a feature which looks finished and earns nobody
 * anything.
 *
 * ── The gate, and why the permission alone is not enough ───────────────────
 *
 * `plans:manage` AND the system tenant. A rate here is an instruction to pay
 * somebody real money out of the platform's own revenue, so a tenant admin
 * holding the permission through the global admin role must not reach it — they
 * could mint themselves a code and refer their own workspaces. That is the same
 * reasoning as promotions, one step sharper: a discount costs margin, a
 * commission costs cash.
 *
 * ── Basis points, refused rather than coerced ──────────────────────────────
 *
 * A caller sending 20 meaning "twenty per cent" would create a 0.2% affiliate.
 * Nothing would error, the screen would show a number, and the mistake would
 * surface in somebody's first statement. The refusal states the units.
 */
final class AffiliatesApiHandlerRealEngineTest extends TestCase
{
    private const OPERATOR = 10;
    private const TENANT_ADMIN = 11;

    private const SYSTEM_TENANT = 0;
    private const OTHER_TENANT = 1;

    private PDO $pdo;
    private AffiliatesApiHandler $handler;
    private AffiliateRepository $affiliates;
    private PayoutAssembler $assembler;

    /**
     * ONE REFERRER PER WORKSPACE IS A UNIQUE CONSTRAINT, so a fixture that
     * earned twice against the same tenant collided on the schema rather than
     * on anything it meant to assert. Each referral gets its own workspace, as
     * it would in life.
     */
    private int $nextWorkspace = 100;

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

        $this->affiliates = new AffiliateRepository($this->pdo);
        $this->assembler = new PayoutAssembler($this->pdo);
        $this->handler = new AffiliatesApiHandler(
            $this->affiliates,
            new RoleChecker($this->wrapSqlite($this->pdo), new PermissionRegistry()),
            $this->assembler
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ── The gate ────────────────────────────────────────────────────────────

    /**
     * THE SHARPEST VERSION OF "THE PERMISSION IS NOT SUFFICIENT". This tenant
     * admin holds `plans:manage` through the global admin role. Without the
     * system-tenant check they could give themselves a fifty-per-cent code and
     * refer their own workspaces — and unlike a discount, which costs margin,
     * this one ends in a payment leaving the company.
     */
    public function testATenantAdminCannotMintThemselvesACommission(): void
    {
        $res = $this->handler->create($this->actAs(self::TENANT_ADMIN, self::OTHER_TENANT, [
            'code' => 'MINE',
            'name' => 'Me',
            'commission_bp' => 5000,
        ]));

        self::assertSame(403, $res->getStatusCode());
        self::assertStringContainsString('system tenant', (string) $res->getBody());
        self::assertSame([], $this->affiliates->listAll(), 'and nothing was created');
    }

    public function testATenantAdminCannotReadWhoIsBeingPaid(): void
    {
        $res = $this->handler->list($this->actAs(self::TENANT_ADMIN, self::OTHER_TENANT));

        self::assertSame(403, $res->getStatusCode());
    }

    // ── Creating one ────────────────────────────────────────────────────────

    public function testAnAffiliateCanBeCreated(): void
    {
        $res = $this->create(['code' => 'SPRING26', 'name' => 'A partner', 'commission_bp' => 2000]);

        self::assertSame(201, $res->getStatusCode());
        $row = $this->dataOf($res);
        self::assertSame('SPRING26', $row['code']);
        self::assertSame(2000, $row['commission_bp']);
        self::assertTrue($row['is_active']);
    }

    /** Twelve months unless somebody says otherwise — the commonest term. */
    public function testTheWindowDefaultsToAYear(): void
    {
        $row = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 1000]));

        self::assertSame(12, $row['window_months']);
    }

    public function testTheOptionalDetailsAreKept(): void
    {
        $this->pdo->exec("INSERT INTO promotions (id, name, code, percent_off) VALUES (7,'Spring','SPRING26',20)");

        $row = $this->dataOf($this->create([
            'code' => 'SPRING26',
            'name' => 'A partner',
            'commission_bp' => 2000,
            'window_months' => 24,
            'email' => 'partner@example.test',
            'promotion_id' => 7,
        ]));

        self::assertSame(24, $row['window_months']);
        self::assertSame('partner@example.test', $row['email']);
        self::assertSame(7, $row['promotion_id']);
    }

    // ── What a code may be ──────────────────────────────────────────────────

    /**
     * A CODE TRAVELS IN A URL AND IS TYPED BY HAND. Anything needing escaping
     * produces a link that breaks in somebody's email client, and the affiliate
     * is the last person to find out.
     *
     * @dataProvider codesThatWouldBreakALink
     */
    public function testACodeThatWouldNotSurviveALinkIsRefused(string $code): void
    {
        $res = $this->create(['code' => $code, 'name' => 'A', 'commission_bp' => 1000]);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame([], $this->affiliates->listAll());
    }

    /** @return array<string, array{string}> */
    public static function codesThatWouldBreakALink(): array
    {
        return [
            'a space' => ['SPRING 26'],
            'an ampersand' => ['A&B'],
            'a question mark' => ['A?B'],
            'a slash' => ['A/B'],
            'a hash' => ['A#B'],
            'a percent' => ['A%20B'],
            'angle brackets' => ['<script>'],
        ];
    }

    public function testTheOrdinaryPunctuationInACodeIsAllowed(): void
    {
        $res = $this->create(['code' => 'spring-26_v.2', 'name' => 'A', 'commission_bp' => 1000]);

        self::assertSame(201, $res->getStatusCode());
    }

    public function testACodeIsRequired(): void
    {
        $res = $this->create(['name' => 'A partner', 'commission_bp' => 2000]);

        self::assertSame(422, $res->getStatusCode());
    }

    public function testANameIsRequired(): void
    {
        $res = $this->create(['code' => 'A', 'commission_bp' => 2000]);

        self::assertSame(422, $res->getStatusCode());
    }

    /**
     * TWO CODES DIFFERING ONLY IN CASE ARE ONE CODE. Attribution matches
     * case-insensitively, because somebody writes the code on a slide and
     * somebody else types it back — so allowing both would mean every referral
     * going to whichever row the index happened to return.
     */
    public function testACodeCannotBeTakenTwiceEvenInADifferentCase(): void
    {
        $this->create(['code' => 'SPRING26', 'name' => 'First', 'commission_bp' => 2000]);

        $res = $this->create(['code' => 'spring26', 'name' => 'Second', 'commission_bp' => 1000]);

        self::assertSame(409, $res->getStatusCode());
        self::assertCount(1, $this->affiliates->listAll());
    }

    // ── The rate ────────────────────────────────────────────────────────────

    /**
     * BASIS POINTS, AND THE REFUSAL SAYS SO. A caller sending 20 for "twenty
     * per cent" creates a 0.2% affiliate — nothing errors, the screen shows a
     * number, and it surfaces in somebody's first statement.
     *
     * @dataProvider ratesThatAreNotRates
     */
    public function testARateOutsideTheAllowedRangeIsRefused(mixed $rate): void
    {
        $res = $this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => $rate]);

        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('basis points', (string) $res->getBody());
    }

    /** @return array<string, array{mixed}> */
    public static function ratesThatAreNotRates(): array
    {
        return [
            'zero, which earns nothing' => [0],
            'negative' => [-100],
            'the whole price' => [10000],
            'more than the price' => [20000],
            'past the ceiling' => [5001],
            'a decimal' => [20.5],
            'a string' => ['2000'],
            'missing' => [null],
        ];
    }

    /** Fifty per cent is the most anybody can be paid, and it is allowed. */
    public function testTheCeilingItselfIsAccepted(): void
    {
        self::assertSame(201, $this->create([
            'code' => 'A', 'name' => 'A', 'commission_bp' => 5000,
        ])->getStatusCode());
    }

    /**
     * @dataProvider windowsThatAreNotWindows
     */
    public function testAnImpossibleWindowIsRefused(mixed $window): void
    {
        $res = $this->create([
            'code' => 'A', 'name' => 'A', 'commission_bp' => 2000, 'window_months' => $window,
        ]);

        self::assertSame(422, $res->getStatusCode());
    }

    /** @return array<string, array{mixed}> */
    public static function windowsThatAreNotWindows(): array
    {
        return [
            'zero months' => [0],
            'negative' => [-1],
            'longer than any deal' => [61],
            'a decimal' => [1.5],
        ];
    }

    // ── Renegotiating ───────────────────────────────────────────────────────

    public function testTheTermsOfADealCanBeChanged(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];

        $res = $this->update($id, ['commission_bp' => 2500, 'window_months' => 24]);

        self::assertSame(200, $res->getStatusCode());
        $row = $this->dataOf($res);
        self::assertSame(2500, $row['commission_bp']);
        self::assertSame(24, $row['window_months']);
    }

    /**
     * A NEW RATE IS NOT RETROACTIVE, and this is the property somebody would
     * most reasonably expect to be otherwise. Each commission copied the rate it
     * was earned at onto its own row, so renegotiating moves only what has not
     * happened yet — an earnings report that rewrote itself when terms changed
     * would not be a report.
     */
    public function testChangingTheRateLeavesWhatWasAlreadyEarnedAlone(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000);

        $this->update($id, ['commission_bp' => 500]);

        self::assertSame(
            [['currency' => 'JOD', 'amount_minor' => 3000]],
            $this->affiliate($id)['balances'],
            'What was earned at 20% is still owed at 20%.'
        );
    }

    /** Detaching the discount a code carried is a thing done at a campaign's end. */
    public function testADiscountCanBeDetachedFromACode(): void
    {
        $this->pdo->exec("INSERT INTO promotions (id, name, code, percent_off) VALUES (7,'Spring','SPRING26',20)");
        $id = $this->dataOf($this->create([
            'code' => 'A', 'name' => 'A', 'commission_bp' => 2000, 'promotion_id' => 7,
        ]))['id'];

        // EXPLICIT NULL IS A VALUE, not an omission — the handler has to tell
        // "remove it" apart from "leave it alone", and `??` alone cannot.
        $row = $this->dataOf($this->update($id, ['promotion_id' => null]));

        self::assertNull($row['promotion_id']);
    }

    /** Omitting it entirely leaves it where it was. */
    public function testAnOmittedDiscountIsLeftAlone(): void
    {
        $this->pdo->exec("INSERT INTO promotions (id, name, code, percent_off) VALUES (7,'Spring','SPRING26',20)");
        $id = $this->dataOf($this->create([
            'code' => 'A', 'name' => 'A', 'commission_bp' => 2000, 'promotion_id' => 7,
        ]))['id'];

        $row = $this->dataOf($this->update($id, ['commission_bp' => 2500]));

        self::assertSame(7, $row['promotion_id']);
    }

    public function testRenegotiatingToAnImpossibleRateIsRefused(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];

        $res = $this->update($id, ['commission_bp' => 9000]);

        self::assertSame(422, $res->getStatusCode());
        self::assertSame(2000, $this->affiliate($id)['commission_bp'], 'unchanged');
    }

    public function testUpdatingSomethingThatDoesNotExistIs404(): void
    {
        self::assertSame(404, $this->update(9999, ['commission_bp' => 2000])->getStatusCode());
    }

    // ── Ending an arrangement ───────────────────────────────────────────────

    /**
     * DEACTIVATING STOPS FUTURE EARNING AND TOUCHES NOTHING EARNED. Money
     * already owed is owed whatever happens to the arrangement, and the rows
     * proving it point at this one — which is why there is no delete at all.
     */
    public function testDeactivatingKeepsTheBalance(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000);

        $row = $this->dataOf($this->update($id, ['is_active' => false]));

        self::assertFalse($row['is_active']);
        self::assertSame([['currency' => 'JOD', 'amount_minor' => 3000]], $row['balances']);
    }

    public function testADeactivatedAffiliateCanBeBroughtBack(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->update($id, ['is_active' => false]);

        self::assertTrue($this->dataOf($this->update($id, ['is_active' => true]))['is_active']);
    }

    // ── The listing ─────────────────────────────────────────────────────────

    /**
     * THE BALANCE IS A SUM OVER THE LEDGER, so a clawback reduces it. A listing
     * that filtered to accrued rows would report what was earned and quietly
     * ignore what was given back — and somebody would be paid it.
     */
    public function testAClawbackReducesWhatIsOwed(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $this->earn($id, amountMinor: -3000, rateBp: 2000, ref: 'INV-1', source: 'external:reversal', status: 'reversed');

        self::assertSame(
            [['currency' => 'JOD', 'amount_minor' => 0]],
            $this->affiliate($id)['balances']
        );
    }

    /**
     * CURRENCIES ARE NOT ADDED TOGETHER. Commissions are recorded in whatever
     * the customer paid in, and a single total across dinars and dollars is a
     * number that is wrong in a way nobody can see on the screen.
     */
    public function testEachCurrencyIsReportedSeparately(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $this->earn($id, amountMinor: 500, rateBp: 2000, ref: 'INV-2', currency: 'USD');

        self::assertSame(
            [
                ['currency' => 'JOD', 'amount_minor' => 3000],
                ['currency' => 'USD', 'amount_minor' => 500],
            ],
            $this->affiliate($id)['balances']
        );
    }

    /**
     * WHAT HAS ALREADY BEEN PAID OUT IS NOT STILL OWED. A balance that included
     * settled commissions would grow forever and be read, by whoever assembles
     * the next payout, as money outstanding — which is how somebody gets paid
     * twice for the same month.
     */
    public function testCommissionsAlreadyPaidOutAreNotStillOwed(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $this->earn($id, amountMinor: 2000, rateBp: 2000, ref: 'INV-2');
        $this->payOut($id, ['INV-1']);

        self::assertSame(
            [['currency' => 'JOD', 'amount_minor' => 2000]],
            $this->affiliate($id)['balances'],
            'Only the unpaid one is still owed.'
        );
    }

    /** How many workspaces they sent, and how many of those ever paid. */
    public function testTheListingCountsReferralsAndConversions(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->refer($id, tenantId: 1, converted: true);

        $row = $this->affiliate($id);
        self::assertSame(1, $row['referral_count']);
        self::assertSame(1, $row['converted_count']);
    }

    /** A referral that never paid is counted as sent, not as converted. */
    public function testAReferralThatNeverPaidIsNotACOnversion(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->refer($id, tenantId: 1, converted: false);

        $row = $this->affiliate($id);
        self::assertSame(1, $row['referral_count']);
        self::assertSame(0, $row['converted_count']);
    }

    public function testAnAffiliateWhoHasEarnedNothingHasNoBalance(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];

        self::assertSame([], $this->affiliate($id)['balances']);
    }

    // ── Payouts ─────────────────────────────────────────────────────────────

    public function testAPayoutGathersWhatIsOwed(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $this->earn($id, amountMinor: 2000, rateBp: 2000, ref: 'INV-2');

        $res = $this->assemble($id, 'JOD');

        self::assertSame(201, $res->getStatusCode());
        $data = $this->dataOf($res);
        self::assertSame(5000, $data['total_minor']);
        self::assertSame(2, $data['commissions']);
    }

    /** And the balance is then zero, because a payout claims what it covers. */
    public function testAssemblingClearsTheBalance(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $this->assemble($id, 'JOD');

        self::assertSame([], $this->affiliate($id)['balances'], 'Nothing is owed once it is in a payout.');
    }

    /**
     * NOTHING PAYABLE IS A 422 THAT SAYS WHY, not an empty success. A balance
     * that refunds took to zero or below carries forward, and an operator who
     * saw a 201 with no money in it would reasonably think a payout had been
     * made.
     */
    public function testAnEmptyBalanceIsRefusedWithAReason(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];

        $res = $this->assemble($id, 'JOD');

        self::assertSame(422, $res->getStatusCode());
        self::assertStringContainsString('carries forward', (string) $res->getBody());
    }

    /** A currency that is not a currency code is refused before anything is claimed. */
    public function testAPayoutNeedsAThreeLetterCurrency(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');

        foreach ([null, '', 'JODX', 'J0D', 'dinars'] as $bad) {
            $res = $this->assemble($id, $bad);
            self::assertSame(422, $res->getStatusCode(), sprintf('%s should be refused', var_export($bad, true)));
        }

        self::assertSame(
            [['currency' => 'JOD', 'amount_minor' => 3000]],
            $this->affiliate($id)['balances'],
            'and nothing was claimed by a refused request'
        );
    }

    public function testAssemblingForSomebodyWhoDoesNotExistIs404(): void
    {
        self::assertSame(404, $this->assemble(9999, 'JOD')->getStatusCode());
    }

    // ── Settling ────────────────────────────────────────────────────────────

    public function testRecordingTheTransferSettlesThePayout(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $payoutId = $this->dataOf($this->assemble($id, 'JOD'))['payout_id'];

        $res = $this->settle($payoutId, ['reference' => 'BANK-REF-991']);

        self::assertSame(200, $res->getStatusCode());
        self::assertSame('paid', $this->assembler->listFor($id)[0]['status']);
    }

    /**
     * A REFERENCE IS REQUIRED. It is the only thing connecting the row to a real
     * bank movement, and it is what gets quoted back when an affiliate asks
     * where their money went.
     */
    public function testSettlingWithoutAReferenceIsRefused(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $payoutId = $this->dataOf($this->assemble($id, 'JOD'))['payout_id'];

        foreach ([[], ['reference' => ''], ['reference' => '   ']] as $body) {
            self::assertSame(422, $this->settle($payoutId, $body)->getStatusCode());
        }

        self::assertSame('draft', $this->assembler->listFor($id)[0]['status'], 'still unpaid');
    }

    public function testSettlingTwiceIsRefused(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $payoutId = $this->dataOf($this->assemble($id, 'JOD'))['payout_id'];
        $this->settle($payoutId, ['reference' => 'BANK-REF-991']);

        $res = $this->settle($payoutId, ['reference' => 'BANK-REF-DIFFERENT']);

        self::assertSame(409, $res->getStatusCode());
        self::assertSame('BANK-REF-991', $this->assembler->listFor($id)[0]['reference'], 'the real one survives');
    }

    // ── Discarding ──────────────────────────────────────────────────────────

    public function testDiscardingADraftPutsTheMoneyBackOnTheBalance(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $payoutId = $this->dataOf($this->assemble($id, 'JOD'))['payout_id'];

        $res = $this->handler->discardPayout(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT),
            ['id' => (string) $payoutId]
        );

        self::assertSame(200, $res->getStatusCode());
        self::assertSame([['currency' => 'JOD', 'amount_minor' => 3000]], $this->affiliate($id)['balances']);
    }

    public function testDiscardingAPaidPayoutIsRefused(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');
        $payoutId = $this->dataOf($this->assemble($id, 'JOD'))['payout_id'];
        $this->settle($payoutId, ['reference' => 'BANK-REF-991']);

        $res = $this->handler->discardPayout(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT),
            ['id' => (string) $payoutId]
        );

        self::assertSame(409, $res->getStatusCode());
        self::assertSame([], $this->affiliate($id)['balances'], 'the money stays settled');
    }

    // ── The gate, again — this one moves money ──────────────────────────────

    public function testATenantAdminCannotAssembleAPayout(): void
    {
        $id = $this->dataOf($this->create(['code' => 'A', 'name' => 'A', 'commission_bp' => 2000]))['id'];
        $this->earn($id, amountMinor: 3000, rateBp: 2000, ref: 'INV-1');

        $res = $this->handler->assemblePayout(
            $this->actAs(self::TENANT_ADMIN, self::OTHER_TENANT, ['currency' => 'JOD']),
            ['id' => (string) $id]
        );

        self::assertSame(403, $res->getStatusCode());
        self::assertSame(
            [['currency' => 'JOD', 'amount_minor' => 3000]],
            $this->affiliate($id)['balances'],
            'and nothing was claimed'
        );
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * The affiliate as it now stands, asserted into existence.
     *
     * `findById` is legitimately nullable, and a test dereferencing it directly
     * both fails static analysis and turns "the row vanished" into a confusing
     * null-offset notice rather than a named failure.
     *
     * @return array<string, mixed>
     */
    private function affiliate(int $id): array
    {
        $row = $this->affiliates->findById($id);
        self::assertNotNull($row, 'The affiliate should still exist.');

        return $row;
    }

    /** @param array<string, mixed> $body */
    private function create(array $body): \Whity\Core\Response
    {
        return $this->handler->create($this->actAs(self::OPERATOR, self::SYSTEM_TENANT, $body));
    }

    /** @param array<string, mixed> $body */
    private function update(int $id, array $body): \Whity\Core\Response
    {
        return $this->handler->update(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT, $body),
            ['id' => (string) $id]
        );
    }

    private function assemble(int $affiliateId, mixed $currency): \Whity\Core\Response
    {
        $body = $currency === null ? [] : ['currency' => $currency];

        return $this->handler->assemblePayout(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT, $body),
            ['id' => (string) $affiliateId]
        );
    }

    /** @param array<string, mixed> $body */
    private function settle(int $payoutId, array $body): \Whity\Core\Response
    {
        return $this->handler->settlePayout(
            $this->actAs(self::OPERATOR, self::SYSTEM_TENANT, $body),
            ['id' => (string) $payoutId]
        );
    }

    /** @return array<string, mixed> */
    private function dataOf(\Whity\Core\Response $response): array
    {
        /** @var array{data: array<string, mixed>} $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded['data'];
    }

    private function refer(int $affiliateId, int $tenantId, bool $converted): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO affiliate_referrals (affiliate_id, tenant_id, referred_at, first_paid_at)
             VALUES (:a, :t, CURRENT_TIMESTAMP, :paid)'
        );
        $statement->bindValue(':a', $affiliateId, PDO::PARAM_INT);
        $statement->bindValue(':t', $tenantId, PDO::PARAM_INT);
        $statement->bindValue(
            ':paid',
            $converted ? '2026-03-01 00:00:00' : null,
            $converted ? PDO::PARAM_STR : PDO::PARAM_NULL
        );
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function earn(
        int $affiliateId,
        int $amountMinor,
        int $rateBp,
        string $ref = 'INV-1',
        string $currency = 'JOD',
        string $source = 'external',
        string $status = 'accrued',
        ?int $referralId = null,
    ): void {
        $referralId ??= $this->refer($affiliateId, $this->workspace(), converted: true);

        $statement = $this->pdo->prepare(
            'INSERT INTO affiliate_commissions
                (referral_id, affiliate_id, source, source_ref, base_minor, rate_bp,
                 amount_minor, currency, status, occurred_at)
             VALUES (:r, :a, :source, :ref, :base, :rate, :amount, :currency, :status, CURRENT_TIMESTAMP)'
        );
        $statement->bindValue(':r', $referralId, PDO::PARAM_INT);
        $statement->bindValue(':a', $affiliateId, PDO::PARAM_INT);
        $statement->bindValue(':source', $source);
        $statement->bindValue(':ref', $ref);
        $statement->bindValue(':base', 15000, PDO::PARAM_INT);
        $statement->bindValue(':rate', $rateBp, PDO::PARAM_INT);
        $statement->bindValue(':amount', $amountMinor, PDO::PARAM_INT);
        $statement->bindValue(':currency', $currency);
        $statement->bindValue(':status', $status);
        $statement->execute();
    }

    /** A fresh workspace, because a workspace can only ever have one referrer. */
    private function workspace(): int
    {
        $id = $this->nextWorkspace++;
        $statement = $this->pdo->prepare('INSERT INTO tenants (id, name, slug) VALUES (:id, :n, :s)');
        $statement->bindValue(':id', $id, PDO::PARAM_INT);
        $statement->bindValue(':n', 'w' . $id);
        $statement->bindValue(':s', 'w' . $id);
        $statement->execute();

        return $id;
    }

    /** @param list<string> $refs Which commissions the payout settles. */
    private function payOut(int $affiliateId, array $refs): void
    {
        // `net_minor` IS SET, not left to its default. Migration 155 adds a
        // CHECK that a payout adds up (`net = total - withholding`), and a
        // fixture that skipped it was rejected by PostgreSQL while SQLite —
        // which cannot express the constraint — accepted it happily. The
        // constraint was right: a payout claiming 3000 and transferring nothing
        // is not a payout.
        $statement = $this->pdo->prepare(
            'INSERT INTO affiliate_payouts
                (affiliate_id, total_minor, withholding_minor, net_minor, currency, status, created_at)
             VALUES (:a, :amount, 0, :amount, :currency, :status, CURRENT_TIMESTAMP)'
        );
        $statement->bindValue(':a', $affiliateId, PDO::PARAM_INT);
        $statement->bindValue(':amount', 3000, PDO::PARAM_INT);
        $statement->bindValue(':currency', 'JOD');
        $statement->bindValue(':status', 'paid');
        $statement->execute();
        $payoutId = (int) $this->pdo->lastInsertId();

        foreach ($refs as $ref) {
            $statement = $this->pdo->prepare(
                'UPDATE affiliate_commissions SET payout_id = :p
                  WHERE affiliate_id = :a AND source_ref = :ref'
            );
            $statement->bindValue(':p', $payoutId, PDO::PARAM_INT);
            $statement->bindValue(':a', $affiliateId, PDO::PARAM_INT);
            $statement->bindValue(':ref', $ref);
            $statement->execute();
        }
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

        $request = new Request('POST', '/api/affiliates', [], $body === null ? '' : (string) json_encode($body));
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
