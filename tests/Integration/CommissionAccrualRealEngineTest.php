<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Affiliate\CommissionAccrualRun;
use Whity\Core\Affiliate\ReferredPayment;
use Whity\Core\Affiliate\ReferredPaymentSource;
use Whity\Core\Affiliate\ReferredPaymentSourceException;

/**
 * The ledger of money owed to people outside the company.
 *
 * ── Why these tests are harsher than usual ─────────────────────────────────
 *
 * Every other money table here records what a customer owes US, and the two
 * directions fail differently. An over-accrual is money paid out that cannot be
 * recalled. An under-accrual is an affiliate who stops promoting the product and
 * tells people why. Neither is discovered by the code being wrong in an obvious
 * way — both surface months later as a statement that does not match.
 *
 * So the properties pinned here are the ones that only fail under conditions a
 * happy-path test never creates: a sweep that runs twice, a refund that arrives
 * after the money was counted, a rate renegotiated mid-window, a payment landing
 * on the exact boundary, and a customer who keeps paying after the arrangement
 * ended.
 */
final class CommissionAccrualRealEngineTest extends TestCase
{
    private PDO $pdo;
    private FakePayments $payments;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1,'a','a'), (2,'b','b')");
        $this->payments = new FakePayments();
    }

    // ── Accruing ────────────────────────────────────────────────────────────

    public function testAPaymentEarnsTheAffiliatesRate(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));

        $result = $this->sweep();

        self::assertSame(1, $result['accrued']);
        self::assertSame(3000, $this->totalOwed());
    }

    /** The base is what the customer paid after discount, not the list price. */
    public function testTheBaseIsAfterDiscount(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        // A 20%-off coupon on a 15.000 JOD plan: the affiliate earns on 12.000.
        $this->payments->add(1, $this->payment('INV-1', 12000, '2026-03-01'));

        $this->sweep();

        self::assertSame(2400, $this->totalOwed());
    }

    /** Several payments across the window each earn. */
    public function testEveryPaymentInsideTheWindowEarns(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000, windowMonths: 12);
        foreach (['2026-03-01', '2026-04-01', '2026-05-01'] as $i => $date) {
            $this->payments->add(1, $this->payment('INV-' . $i, 15000, $date));
        }

        $result = $this->sweep();

        self::assertSame(3, $result['accrued']);
        self::assertSame(9000, $this->totalOwed());
    }

    // ── Idempotency: the property the whole design rests on ─────────────────

    /**
     * THE SWEEP RE-READS EVERYTHING, SO RUNNING IT TWICE MUST PAY ONCE. This is
     * not a nicety: the accrual converges after a missed notification precisely
     * because it re-reads the whole history, and that is only safe if a second
     * pass collides instead of paying again.
     */
    public function testRunningTheSweepTwicePaysOnce(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));

        $this->sweep();
        $second = $this->sweep();

        self::assertSame(0, $second['accrued']);
        self::assertSame(1, $second['already']);
        self::assertSame(3000, $this->totalOwed(), 'The balance must not move on a re-run.');
        self::assertSame(1, $this->commissionCount());
    }

    /** Ten sweeps, one commission. */
    public function testManySweepsStillPayOnce(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));

        for ($i = 0; $i < 10; $i++) {
            $this->sweep();
        }

        self::assertSame(3000, $this->totalOwed());
        self::assertSame(1, $this->commissionCount());
    }

    /**
     * TWO WORKSPACES PAYING THE SAME INVOICE NUMBER DO NOT COLLIDE. Invoice
     * numbering is per-tenant, so 'INV-1' exists for everybody — a uniqueness
     * rule keyed on the reference alone would pay the first and silently swallow
     * the rest.
     */
    public function testTheSameInvoiceNumberForDifferentWorkspacesBothEarn(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->referral(tenantId: 2, rateBp: 2000, code: 'B');
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->payments->add(2, $this->payment('INV-1', 15000, '2026-03-01'));

        $result = $this->sweep();

        self::assertSame(2, $result['accrued']);
        self::assertSame(6000, $this->totalOwed());
    }

    // ── The window ──────────────────────────────────────────────────────────

    /** The window opens on the FIRST payment, not on the referral. */
    public function testTheWindowOpensOnTheFirstPaymentNotTheReferral(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000, windowMonths: 12, referredAt: '2026-01-01');
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));

        $this->sweep();

        $row = $this->referralRow(1);
        self::assertStringStartsWith('2026-03-01', (string) $row['first_paid_at']);
        self::assertStringStartsWith('2027-03-01', (string) $row['window_ends_at']);
    }

    /** Payments after the window earn nothing, and are reported. */
    public function testPaymentsAfterTheWindowEarnNothing(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000, windowMonths: 12);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->payments->add(1, $this->payment('INV-LATE', 15000, '2027-04-01'));

        $result = $this->sweep();

        self::assertSame(1, $result['accrued']);
        self::assertSame(1, $result['outside_window']);
        self::assertSame(3000, $this->totalOwed());
    }

    /** A renewal on the exact closing instant still counts. */
    public function testAPaymentOnTheClosingInstantStillEarns(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000, windowMonths: 12);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01 10:00:00'));
        $this->payments->add(1, $this->payment('INV-LAST', 15000, '2027-03-01 10:00:00'));

        $result = $this->sweep();

        self::assertSame(2, $result['accrued'], 'The boundary is inclusive.');
    }

    /**
     * THE WINDOW IS FROZEN ONCE OPEN. Renegotiating an affiliate's terms must
     * not silently reopen a window that closed, nor close one early — both
     * parties agreed a period, and changing it retroactively is not a
     * configuration change, it is a different deal.
     */
    public function testChangingTheWindowLaterDoesNotMoveAnOpenOne(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000, windowMonths: 12);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        $this->pdo->exec('UPDATE affiliates SET window_months = 1');
        $this->payments->add(1, $this->payment('INV-2', 15000, '2026-09-01'));
        $result = $this->sweep();

        self::assertSame(1, $result['accrued'], 'Still inside the window that was agreed.');
        self::assertStringStartsWith('2027-03-01', (string) $this->referralRow(1)['window_ends_at']);
    }

    /**
     * THE RATE IS COPIED, NOT JOINED FOR. An earnings report that rewrites
     * itself when somebody renegotiates is not a report — last month's
     * commission was earned at last month's rate.
     */
    public function testAnEarlierCommissionKeepsTheRateItWasEarnedAt(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        $this->pdo->exec('UPDATE affiliates SET commission_bp = 500');
        $this->payments->add(1, $this->payment('INV-2', 15000, '2026-04-01'));
        $this->sweep();

        $amounts = $this->commissionAmounts();
        self::assertSame([3000, 750], $amounts, 'The old earning keeps 20%; the new one takes 5%.');

        // THE STORED RATE IS ASSERTED TOO, not just the amount. Found by
        // mutation: writing a garbage `rate_bp` left every amount correct,
        // because the amount is computed at accrual time from a fresh read. The
        // column is what an affiliate's statement quotes back when they ask why
        // a figure is what it is, so a wrong one is a dispute nobody can settle.
        $statement = $this->pdo->query('SELECT rate_bp FROM affiliate_commissions ORDER BY id ASC');
        $rates = $statement === false
            ? []
            : array_map(static fn ($v): int => (int) $v, $statement->fetchAll(PDO::FETCH_COLUMN));
        self::assertSame([2000, 500], $rates, 'Each row records the rate it was earned at.');
    }

    // ── Clawback ────────────────────────────────────────────────────────────

    /**
     * A REFUND REVERSES AS A NEW NEGATIVE ROW, never as an edit. The affiliate
     * may already have seen the original on a statement; erasing it would make
     * their records and ours disagree with no way to tell why.
     */
    public function testARefundReversesTheCommissionWithoutErasingIt(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();
        self::assertSame(3000, $this->totalOwed());

        $this->payments->replace(1, [$this->payment('INV-1', 15000, '2026-03-01', refunded: true)]);
        $result = $this->sweep();

        self::assertSame(1, $result['reversed']);
        self::assertSame(0, $this->totalOwed(), 'The balance nets to nothing.');
        self::assertSame(2, $this->commissionCount(), 'Both rows survive — the history is intact.');
    }

    /** A reversal, like an accrual, happens exactly once however often the sweep runs. */
    public function testARefundIsNotClawedBackTwice(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        $this->payments->replace(1, [$this->payment('INV-1', 15000, '2026-03-01', refunded: true)]);
        $this->sweep();
        $this->sweep();
        $this->sweep();

        self::assertSame(0, $this->totalOwed());
        self::assertSame(2, $this->commissionCount());
    }

    /**
     * THE CLAWBACK RETURNS WHAT WAS PAID, NOT WHAT IT WOULD EARN TODAY. If the
     * rate changed between the earning and the refund, reversing at the new rate
     * would leave a balance that is wrong in whichever direction the rate moved.
     */
    public function testTheClawbackReturnsTheOriginalAmountAfterARateChange(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        $this->pdo->exec('UPDATE affiliates SET commission_bp = 500');
        $this->payments->replace(1, [$this->payment('INV-1', 15000, '2026-03-01', refunded: true)]);
        $this->sweep();

        self::assertSame(0, $this->totalOwed(), 'Reversed at the rate it was earned at, so it nets to zero.');
    }

    /** Absorbed instead, when the company decides to. */
    public function testClawbackCanBeTurnedOffAndTheCommissionStands(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        $this->payments->replace(1, [$this->payment('INV-1', 15000, '2026-03-01', refunded: true)]);
        $this->sweep(clawback: false);

        self::assertSame(3000, $this->totalOwed());
        self::assertSame(1, $this->commissionCount());
    }

    /** Refunding something that never earned takes nothing back. */
    public function testRefundingAPaymentThatNeverEarnedDoesNothing(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000, windowMonths: 12);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        // A refund of a payment from beyond the window — never accrued.
        $this->payments->add(1, $this->payment('INV-LATE', 15000, '2027-06-01', refunded: true));
        $result = $this->sweep();

        self::assertSame(0, $result['reversed']);
        self::assertSame(3000, $this->totalOwed());
    }

    /**
     * A REFUND CLAWS BACK EVEN WHEN ITS DATE FALLS OUTSIDE THE WINDOW, and this
     * is the test that stops a bug that was live for an afternoon.
     *
     * The billing service CLEARS an invoice's payment date when it is refunded
     * to zero. This side has to date the reversal from something, falls back to
     * the issue date, and that date can land the wrong side of a window
     * boundary — at which point a window check that ran BEFORE the refund check
     * would count the whole thing as "past the window" and leave the commission
     * standing on money that went back. Forever, and quietly: the sweep re-reads
     * the same history every run and reaches the same wrong conclusion.
     *
     * Reversing first is safe because a clawback finds its original by reference
     * rather than by date, so a refund of something never accrued is already a
     * no-op — as the test below this one pins.
     */
    public function testARefundIsClawedBackEvenWhenItsDateFallsOutsideTheWindow(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000, windowMonths: 12);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();
        self::assertSame(3000, $this->totalOwed(), 'Earned inside the window.');

        // The same invoice comes back refunded, carrying a date past the window
        // because the payment date is gone and only the issue date remains.
        $this->payments->replace(1, [$this->payment('INV-1', 15000, '2027-09-01', refunded: true)]);
        $result = $this->sweep();

        self::assertSame(1, $result['reversed'], 'What was earned inside is taken back.');
        self::assertSame(0, $result['outside_window'], 'A refund is never merely "past the window".');
        self::assertSame(0, $this->totalOwed());
    }

    // ── A partial refund: the case the ledger cannot express ────────────────

    /**
     * A PARTLY REFUNDED PAYMENT IS REPORTED AND LEFT ALONE.
     *
     * Both available answers are wrong. Reversing the whole commission takes
     * back everything over a customer who kept most of what they bought;
     * ignoring it pays on money that was returned. The ledger holds one entry
     * per payment and reverses it whole, so it cannot split the difference.
     *
     * What it must not do is decide silently. The commission stands — the
     * smaller error in the common case of a small refund — and the count says a
     * person has to settle it.
     */
    public function testAPartlyRefundedPaymentIsCountedAndLeftForAPerson(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        $this->payments->replace(1, [$this->payment('INV-1', 15000, '2026-03-01', partlyRefunded: true)]);
        $result = $this->sweep();

        self::assertSame(1, $result['partial_refunds'], 'Reported, so somebody can act on it.');
        self::assertSame(0, $result['reversed'], 'Not clawed back whole: most of the sale stands.');
        self::assertSame(3000, $this->totalOwed());
    }

    /**
     * A PARTIAL REFUND ON THE FIRST-EVER SWEEP STILL ACCRUES. Found by writing
     * the reporting branch with a `continue` in it: a payment partly refunded
     * before any sweep saw it would have been counted and then never paid at
     * all, which is the opposite of the intended erring-towards-the-affiliate.
     */
    public function testAPaymentPartlyRefundedBeforeTheFirstSweepStillEarns(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01', partlyRefunded: true));

        $result = $this->sweep();

        self::assertSame(1, $result['partial_refunds']);
        self::assertSame(1, $result['accrued'], 'The commission is written, then flagged.');
        self::assertSame(3000, $this->totalOwed());
    }

    /** A full refund is a clawback, not a partial one — the two never both fire. */
    public function testAFullRefundIsNotAlsoReportedAsPartial(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        $this->payments->replace(1, [$this->payment('INV-1', 15000, '2026-03-01', refunded: true)]);
        $result = $this->sweep();

        self::assertSame(1, $result['reversed']);
        self::assertSame(0, $result['partial_refunds']);
    }

    // ── When a payment history cannot be read ───────────────────────────────

    /**
     * AN UNREADABLE HISTORY IS COUNTED, NOT MISTAKEN FOR "NEVER PAID".
     *
     * The two look identical from here — both produce no payments — and they
     * need opposite responses. A sweep that reported success while the billing
     * service was down would under-accrue every night, and the only person
     * positioned to notice is the affiliate, months later, reading a statement
     * that does not match what they sold.
     */
    public function testAWorkspaceWhosePaymentsCannotBeReadIsCounted(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->fail(1);

        $result = $this->sweep();

        self::assertSame(1, $result['unreachable']);
        self::assertSame(0, $result['accrued']);
        self::assertSame(0, $this->commissionCount());
    }

    /** One unreachable workspace must not cost every workspace after it. */
    public function testOneUnreadableWorkspaceDoesNotStopTheSweep(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->referral(tenantId: 2, rateBp: 2000, code: 'B');
        $this->payments->fail(1);
        $this->payments->add(2, $this->payment('INV-1', 15000, '2026-03-01'));

        $result = $this->sweep();

        self::assertSame(1, $result['unreachable']);
        self::assertSame(1, $result['accrued'], 'The reachable workspace still earns.');
        self::assertSame(3000, $this->totalOwed());
    }

    /**
     * AN UNREADABLE PASS MUST NOT FREEZE THE EARNING WINDOW. The window is
     * written once, from the oldest payment the sweep is handed — so a pass that
     * saw a truncated history, or none, would set a start date that no later
     * healthy sweep can correct.
     */
    public function testAnUnreadablePassLeavesTheWindowUnopened(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000, windowMonths: 12);
        $this->payments->fail(1);
        $this->sweep();

        self::assertNull($this->referralRow(1)['first_paid_at'], 'Nothing was learned, so nothing was decided.');

        // The service comes back, carrying the history that was there all along.
        $this->payments->recover(1);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $result = $this->sweep();

        self::assertSame(1, $result['accrued']);
        self::assertStringStartsWith(
            '2026-03-01',
            (string) $this->referralRow(1)['first_paid_at'],
            'The window opens on the real first payment, not on when we could next reach it.'
        );
    }

    /** And the accrual converges: nothing is lost by a pass that could not read. */
    public function testEverythingOwedIsPaidOnceTheSourceRecovers(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->fail(1);
        $this->sweep();
        $this->sweep();

        $this->payments->recover(1);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->payments->add(1, $this->payment('INV-2', 15000, '2026-04-01'));
        $this->sweep();

        self::assertSame(6000, $this->totalOwed(), 'Both payments, in full, on the first pass that could see them.');
    }

    // ── What earns nothing ──────────────────────────────────────────────────

    /** A fully discounted invoice earns nothing, and leaves no puzzling zero row. */
    public function testAFullyDiscountedInvoiceLeavesNoRow(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-FREE', 0, '2026-03-01'));

        $result = $this->sweep();

        self::assertSame(0, $result['accrued']);
        self::assertSame(1, $result['skipped']);
        self::assertSame(0, $this->commissionCount());
    }

    /**
     * DEACTIVATING AN AFFILIATE STOPS FUTURE EARNING AND TOUCHES NOTHING EARNED.
     * Money already owed is owed whatever happens to the arrangement.
     */
    public function testDeactivatingAnAffiliateStopsEarningButKeepsTheBalance(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01'));
        $this->sweep();

        $this->pdo->exec('UPDATE affiliates SET is_active = false');
        $this->payments->add(1, $this->payment('INV-2', 15000, '2026-04-01'));
        $result = $this->sweep();

        self::assertSame(0, $result['accrued']);
        self::assertSame(3000, $this->totalOwed(), 'What was earned is still owed.');
    }

    /** A referral whose customer never paid has no window and no earnings. */
    public function testAReferralThatNeverConvertedHasNoWindow(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);

        $this->sweep();

        self::assertNull($this->referralRow(1)['first_paid_at']);
        self::assertSame(0, $this->commissionCount());
    }

    /** Commissions are recorded in the currency the customer actually paid. */
    public function testTheCommissionKeepsThePaymentsCurrency(): void
    {
        $this->referral(tenantId: 1, rateBp: 2000);
        $this->payments->add(1, $this->payment('INV-1', 15000, '2026-03-01', currency: 'USD'));

        $this->sweep();

        $statement = $this->pdo->query('SELECT currency FROM affiliate_commissions');
        self::assertSame('USD', $statement === false ? '' : (string) $statement->fetchColumn());
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    /**
     * Named `sweep` rather than `run`: PHPUnit's TestCase::run() is final, and
     * a helper called run() here is a fatal error rather than a warning.
     *
     * @return array{accrued: int, reversed: int, skipped: int, already: int,
     *               outside_window: int, partial_refunds: int, unreachable: int}
     */
    private function sweep(bool $clawback = true): array
    {
        return (new CommissionAccrualRun($this->pdo, $this->payments, $clawback))->run();
    }

    private function payment(
        string $ref,
        int $baseMinor,
        string $paidAt,
        bool $refunded = false,
        string $currency = 'JOD',
        bool $partlyRefunded = false,
    ): ReferredPayment {
        return new ReferredPayment(
            ReferredPayment::SOURCE_EXTERNAL,
            $ref,
            $baseMinor,
            $currency,
            new DateTimeImmutable($paidAt),
            $refunded,
            $partlyRefunded,
        );
    }

    private function referral(
        int $tenantId,
        int $rateBp,
        int $windowMonths = 12,
        string $code = 'A',
        string $referredAt = '2026-01-01',
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO affiliates (code, name, commission_bp, window_months) VALUES (:c, :n, :bp, :w)'
        );
        $statement->execute([':c' => $code, ':n' => $code, ':bp' => $rateBp, ':w' => $windowMonths]);
        $affiliateId = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO affiliate_referrals (affiliate_id, tenant_id, referred_at) VALUES (:a, :t, :r)'
        );
        $statement->execute([':a' => $affiliateId, ':t' => $tenantId, ':r' => $referredAt]);
    }

    /** The affiliate balance: a SUM, which is only meaningful if reversals are rows. */
    private function totalOwed(): int
    {
        $statement = $this->pdo->query('SELECT COALESCE(SUM(amount_minor), 0) FROM affiliate_commissions');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    private function commissionCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM affiliate_commissions');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    /** @return list<int> */
    private function commissionAmounts(): array
    {
        $statement = $this->pdo->query('SELECT amount_minor FROM affiliate_commissions ORDER BY id ASC');

        return $statement === false
            ? []
            : array_map(static fn ($v): int => (int) $v, $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed> */
    private function referralRow(int $tenantId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM affiliate_referrals WHERE tenant_id = :t');
        $statement->execute([':t' => $tenantId]);

        /** @var array<string, mixed> $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row;
    }
}

/**
 * Payments a test decides on.
 *
 * The real sources read a table or call the billing service; neither is the
 * subject here. What is being tested is what the sweep DOES with a payment
 * history, including histories no live system would produce on demand — a
 * refund appearing on the second pass, a payment on the exact boundary.
 */
final class FakePayments implements ReferredPaymentSource
{
    /** @var array<int, list<ReferredPayment>> */
    private array $byTenant = [];

    /** @var array<int, true> */
    private array $unreadable = [];

    public function add(int $tenantId, ReferredPayment $payment): void
    {
        $this->byTenant[$tenantId][] = $payment;
    }

    /** @param list<ReferredPayment> $payments */
    public function replace(int $tenantId, array $payments): void
    {
        $this->byTenant[$tenantId] = $payments;
    }

    /**
     * This workspace's history cannot be read — the billing service is down.
     *
     * DELIBERATELY NOT THE SAME AS AN EMPTY LIST, which is the whole point of
     * the tests that use it.
     */
    public function fail(int $tenantId): void
    {
        $this->unreadable[$tenantId] = true;
    }

    /** The service comes back. */
    public function recover(int $tenantId): void
    {
        unset($this->unreadable[$tenantId]);
    }

    /** @return list<ReferredPayment> */
    public function paymentsFor(int $tenantId): array
    {
        if (isset($this->unreadable[$tenantId])) {
            throw new ReferredPaymentSourceException('The test made this workspace unreadable.');
        }

        return $this->byTenant[$tenantId] ?? [];
    }
}
