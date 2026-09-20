<?php

declare(strict_types=1);

namespace Tests\Integration;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Affiliate\PayoutAssembler;
use Whity\Core\Affiliate\PayoutRaceException;
use Whity\Core\Affiliate\PayoutStateException;

/**
 * Turning what an affiliate is owed into a payment somebody can make.
 *
 * ── Why these tests are harsher than the ledger's ──────────────────────────
 *
 * The ledger records what is owed and can be re-derived; a payout is the moment
 * money leaves the company, and it is the last point at which an error is
 * cheap. Every failure here costs cash in one direction or the other:
 *
 *   paying a balance twice        money out that cannot be recalled
 *   claiming rows and not paying  an affiliate whose balance silently vanished
 *   a total that is not its lines the one error the recipient is guaranteed
 *                                 to find
 *
 * So the properties pinned below are the ones a happy-path test never reaches:
 * two operators assembling at the same instant, a balance that has gone
 * negative through clawbacks, a currency mixed in by accident, and a payout
 * somebody tries to pay twice.
 */
final class PayoutAssemblerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private PayoutAssembler $payouts;
    private int $affiliateId;

    /** One referrer per workspace is a UNIQUE constraint; each earning gets its own. */
    private int $nextWorkspace = 100;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->payouts = new PayoutAssembler($this->pdo);
        $this->affiliateId = $this->affiliate('SPRING26');
    }

    // ── Assembling ──────────────────────────────────────────────────────────

    public function testEverythingUnpaidInOneCurrencyBecomesOnePayout(): void
    {
        $this->earn(3000);
        $this->earn(2000);

        $result = $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNotNull($result);
        self::assertSame(2, $result['commissions']);
        self::assertSame(5000, $result['total_minor']);
    }

    /**
     * A PAYOUT CLAIMS ITS COMMISSIONS, which is the property everything else
     * rests on. Without the claim, the balance still reads as owed after the
     * money has gone and the next assembly pays it again.
     */
    public function testAssembledCommissionsAreNoLongerOwed(): void
    {
        $this->earn(3000);
        $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertSame(0, $this->unclaimedTotal('JOD'), 'Nothing is owed once it is in a payout.');
    }

    /** So a second assembly finds nothing, rather than paying the same money again. */
    public function testAssemblingTwiceDoesNotPayTheSameMoneyTwice(): void
    {
        $this->earn(3000);
        $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNull($this->payouts->assemble($this->affiliateId, 'JOD'));
        self::assertSame(1, $this->payoutCount());
    }

    /** New earnings after a payout are a new balance, and a new payout. */
    public function testEarningsAfterAPayoutBecomeTheNextOne(): void
    {
        $this->earn(3000);
        $this->payouts->assemble($this->affiliateId, 'JOD');

        $this->earn(1500);
        $second = $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNotNull($second);
        self::assertSame(1500, $second['total_minor']);
        self::assertSame(2, $this->payoutCount());
    }

    public function testAnAffiliateOwedNothingGetsNoPayout(): void
    {
        self::assertNull($this->payouts->assemble($this->affiliateId, 'JOD'));
        self::assertSame(0, $this->payoutCount());
    }

    // ── One currency at a time ──────────────────────────────────────────────

    /**
     * A PAYOUT SPANNING CURRENCIES WOULD HAVE TO INVENT A RATE. Nobody stored
     * one, so the dollars stay owed until somebody assembles them separately —
     * which is what a payment actually is.
     */
    public function testAPayoutTakesOnlyItsOwnCurrency(): void
    {
        $this->earn(3000, currency: 'JOD');
        $this->earn(500, currency: 'USD');

        $result = $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNotNull($result);
        self::assertSame(3000, $result['total_minor']);
        self::assertSame(500, $this->unclaimedTotal('USD'), 'The dollars are untouched.');
    }

    /** And the other currency assembles into its own payout afterwards. */
    public function testTheOtherCurrencyAssemblesSeparately(): void
    {
        $this->earn(3000, currency: 'JOD');
        $this->earn(500, currency: 'USD');
        $this->payouts->assemble($this->affiliateId, 'JOD');

        $usd = $this->payouts->assemble($this->affiliateId, 'USD');

        self::assertNotNull($usd);
        self::assertSame(500, $usd['total_minor']);
    }

    /** A currency typed in the wrong case is the same currency. */
    public function testTheCurrencyIsMatchedRegardlessOfCase(): void
    {
        $this->earn(3000, currency: 'JOD');

        $result = $this->payouts->assemble($this->affiliateId, 'jod');

        self::assertNotNull($result);
        self::assertSame(3000, $result['total_minor']);
    }

    // ── Clawbacks, and a balance that has gone the wrong way ────────────────

    /** A reversal reduces what is payable, because it is a row in the same sum. */
    public function testAClawbackReducesThePayout(): void
    {
        $this->earn(3000);
        $this->earn(-1000, source: 'external:reversal', status: 'reversed');

        $result = $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNotNull($result);
        self::assertSame(2000, $result['total_minor']);
    }

    /**
     * A NEGATIVE BALANCE IS CARRIED FORWARD, NOT PAID — and emphatically not
     * collected. An affiliate whose referred customers refunded more than they
     * bought owes nothing back; the next period's accruals net against it. The
     * rows must stay UNCLAIMED, or the debt disappears and the affiliate is
     * quietly forgiven at the company's expense.
     */
    public function testANegativeBalanceIsNotAPayoutAndStaysOwing(): void
    {
        $this->earn(1000);
        $this->earn(-3000, source: 'external:reversal', status: 'reversed');

        self::assertNull($this->payouts->assemble($this->affiliateId, 'JOD'));
        self::assertSame(0, $this->payoutCount());
        self::assertSame(-2000, $this->unclaimedTotal('JOD'), 'The debt is still there to net against.');
    }

    /** A balance that nets to exactly zero is not a payment either. */
    public function testAZeroBalanceIsNotAPayout(): void
    {
        $this->earn(3000);
        $this->earn(-3000, source: 'external:reversal', status: 'reversed');

        self::assertNull($this->payouts->assemble($this->affiliateId, 'JOD'));
        self::assertSame(0, $this->payoutCount());
    }

    /** And a later earning nets against the carried debt rather than ignoring it. */
    public function testALaterEarningNetsAgainstACarriedDebt(): void
    {
        $this->earn(1000);
        $this->earn(-3000, source: 'external:reversal', status: 'reversed');
        $this->payouts->assemble($this->affiliateId, 'JOD');

        $this->earn(5000);
        $result = $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNotNull($result);
        self::assertSame(3000, $result['total_minor'], '5000 earned against 2000 still owed.');
    }

    // ── Withholding ─────────────────────────────────────────────────────────

    /**
     * WHAT LEAVES THE BANK IS NOT WHAT THE AFFILIATE EARNED. Recording one and
     * calling it the other is how a payout register stops reconciling with a
     * bank statement — and the gap is exactly the tax, so it reads as a rounding
     * problem until somebody adds it up.
     */
    public function testWithholdingSplitsTheGrossFromTheNet(): void
    {
        $this->earn(10000);

        $result = $this->payouts->assemble($this->affiliateId, 'JOD', withholdingBp: 500);

        self::assertNotNull($result);
        self::assertSame(10000, $result['total_minor'], 'What they earned.');
        self::assertSame(500, $result['withholding_minor'], 'What is kept back.');
        self::assertSame(9500, $result['net_minor'], 'What actually moves.');
    }

    /**
     * ROUNDED HALF UP, matching how invoices compute sales tax. Two tax
     * roundings in one codebase disagree at exactly the amounts somebody queries.
     *
     * @dataProvider withholdingRoundings
     */
    public function testWithholdingRoundsTheSameWayInvoiceTaxDoes(int $gross, int $rateBp, int $expected): void
    {
        self::assertSame($expected, PayoutAssembler::withholdingOn($gross, $rateBp));
    }

    /** @return array<string, array{int, int, int}> */
    public static function withholdingRoundings(): array
    {
        return [
            'exact' => [10000, 500, 500],
            'rounds up at the half' => [1000, 505, 51],   // 50.5 -> 51
            'rounds down below it' => [1000, 504, 50],    // 50.4 -> 50
            'no rate withholds nothing' => [10000, 0, 0],
            'a negative rate withholds nothing' => [10000, -500, 0],
            'nothing earned withholds nothing' => [0, 500, 0],
            'capped at the whole amount' => [10000, 20000, 10000],
        ];
    }

    /** Zero is the default and it is recorded as a deliberate zero, not a gap. */
    public function testAZeroRateIsRecordedOnTheRowRatherThanLeftBlank(): void
    {
        $this->earn(10000);
        $result = $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNotNull($result);
        self::assertSame(0, $result['withholding_minor']);
        self::assertSame(10000, $result['net_minor']);

        $row = $this->payouts->listFor($this->affiliateId)[0];
        self::assertSame(0, $row['withholding_bp'], 'The rate it was assembled at, stated.');
    }

    /**
     * THE RATE IS SNAPSHOT. Changing the setting later must not restate a
     * payout already made — the same rule invoices follow for sales tax, and for
     * the same reason: a document that says something different depending on
     * when it is opened is not evidence of anything.
     */
    public function testChangingTheRateLaterDoesNotRestateAnOlderPayout(): void
    {
        $this->earn(10000);
        $this->payouts->assemble($this->affiliateId, 'JOD', withholdingBp: 500);

        $this->earn(10000);
        $this->payouts->assemble($this->affiliateId, 'JOD', withholdingBp: 1000);

        $rows = $this->payouts->listFor($this->affiliateId);
        $rates = array_map(static fn (array $r): int => (int) $r['withholding_bp'], $rows);
        sort($rates);

        self::assertSame([500, 1000], $rates, 'Each payout keeps the rate it was assembled at.');
    }

    // ── Paying ──────────────────────────────────────────────────────────────

    public function testMarkingPaidRecordsTheTransfer(): void
    {
        $this->earn(3000);
        $result = $this->payouts->assemble($this->affiliateId, 'JOD');
        self::assertNotNull($result);

        $this->payouts->markPaid($result['payout_id'], 'BANK-REF-991', new DateTimeImmutable('2026-04-01 10:00:00'));

        $row = $this->payouts->listFor($this->affiliateId)[0];
        self::assertSame('paid', $row['status']);
        self::assertSame('BANK-REF-991', $row['reference']);
        self::assertStringStartsWith('2026-04-01', (string) $row['paid_at']);
    }

    /**
     * ALREADY PAID IS REFUSED, NOT RE-APPLIED. Re-marking would move `paid_at`
     * and overwrite the bank reference of a transfer that really happened,
     * destroying the only record of which payment settled it.
     */
    public function testAPayoutCannotBePaidTwice(): void
    {
        $this->earn(3000);
        $result = $this->payouts->assemble($this->affiliateId, 'JOD');
        self::assertNotNull($result);
        $this->payouts->markPaid($result['payout_id'], 'BANK-REF-991');

        $this->expectException(PayoutStateException::class);
        $this->payouts->markPaid($result['payout_id'], 'BANK-REF-DIFFERENT');
    }

    /** And the original reference survives the attempt. */
    public function testTheOriginalReferenceSurvivesASecondAttempt(): void
    {
        $this->earn(3000);
        $result = $this->payouts->assemble($this->affiliateId, 'JOD');
        self::assertNotNull($result);
        $this->payouts->markPaid($result['payout_id'], 'BANK-REF-991');

        try {
            $this->payouts->markPaid($result['payout_id'], 'BANK-REF-DIFFERENT');
        } catch (PayoutStateException) {
            // Expected; the assertion is about what did not change.
        }

        self::assertSame('BANK-REF-991', $this->payouts->listFor($this->affiliateId)[0]['reference']);
    }

    public function testPayingSomethingThatDoesNotExistIsRefused(): void
    {
        $this->expectException(PayoutStateException::class);
        $this->payouts->markPaid(9999, 'BANK-REF-991');
    }

    // ── Discarding a draft ──────────────────────────────────────────────────

    /**
     * A DRAFT ASSEMBLED BY MISTAKE MUST NOT STRAND THE MONEY IT CLAIMED. If
     * discarding left the commissions pointing at a deleted payout they would be
     * invisible to the balance and unpayable forever — the worst available
     * outcome, because the affiliate is still owed it and nothing says so.
     */
    public function testDiscardingADraftPutsTheMoneyBack(): void
    {
        $this->earn(3000);
        $result = $this->payouts->assemble($this->affiliateId, 'JOD');
        self::assertNotNull($result);
        self::assertSame(0, $this->unclaimedTotal('JOD'));

        $this->payouts->discardDraft($result['payout_id']);

        self::assertSame(3000, $this->unclaimedTotal('JOD'), 'Owed again.');
        self::assertSame(0, $this->payoutCount());
    }

    /** And it can then be assembled again, which is the point of discarding. */
    public function testMoneyPutBackCanBeAssembledAgain(): void
    {
        $this->earn(3000);
        $first = $this->payouts->assemble($this->affiliateId, 'JOD');
        self::assertNotNull($first);
        $this->payouts->discardDraft($first['payout_id']);

        $second = $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNotNull($second);
        self::assertSame(3000, $second['total_minor']);
    }

    /**
     * A PAID PAYOUT IS NEVER DISCARDED. The money has gone; a record of it that
     * can be deleted is not a record — and releasing its commissions would put
     * money that was already paid back onto the balance, to be paid again.
     */
    public function testAPaidPayoutCannotBeDiscarded(): void
    {
        $this->earn(3000);
        $result = $this->payouts->assemble($this->affiliateId, 'JOD');
        self::assertNotNull($result);
        $this->payouts->markPaid($result['payout_id'], 'BANK-REF-991');

        try {
            $this->payouts->discardDraft($result['payout_id']);
            self::fail('Discarding a paid payout should be refused.');
        } catch (PayoutStateException) {
            // Expected.
        }

        self::assertSame(1, $this->payoutCount());
        self::assertSame(0, $this->unclaimedTotal('JOD'), 'The money is still settled.');
    }

    // ── The race between reading a balance and claiming it ──────────────────

    /**
     * TWO OPERATORS ASSEMBLING AT THE SAME INSTANT MUST NOT PRODUCE A PAYOUT
     * THAT LIES ABOUT ITSELF.
     *
     * The assembler reads a balance, then stamps its id onto the rows that made
     * it. If somebody claims some of those rows in between, the payout's stored
     * total no longer describes what it contains — and a payout whose amount
     * does not match its own lines is the one error the recipient is guaranteed
     * to find.
     *
     * This interleaving cannot happen by accident in a single-threaded test, so
     * it is built: a PDO that performs the theft at exactly the moment the claim
     * is prepared. Found by mutation — deleting the check left every other test
     * passing, because none of them could create the situation it exists for.
     */
    public function testAPayoutIsRefusedWhenSomebodyClaimsItsRowsMidAssembly(): void
    {
        $this->earn(3000);
        $this->earn(2000);

        $thief = new ClaimJumpingPdo($this->pdo);
        $racing = new PayoutAssembler($thief);

        try {
            $racing->assemble($this->affiliateId, 'JOD');
            self::fail('A payout whose lines were stolen mid-assembly should be refused.');
        } catch (PayoutRaceException) {
            // Expected.
        }

        // AND NOTHING IS LEFT BEHIND. The rollback matters as much as the
        // refusal: a half-assembled payout would claim money nobody is going to
        // pay, and it would vanish from the balance silently.
        self::assertSame(0, $this->payoutCount(), 'The refused payout was rolled back.');
    }

    // ── Two affiliates ──────────────────────────────────────────────────────

    /** One affiliate's payout never reaches another's balance. */
    public function testAnotherAffiliatesEarningsAreUntouched(): void
    {
        $other = $this->affiliate('AUTUMN26');
        $this->earn(3000);
        $this->earn(7000, affiliateId: $other);

        $result = $this->payouts->assemble($this->affiliateId, 'JOD');

        self::assertNotNull($result);
        self::assertSame(3000, $result['total_minor']);
        self::assertSame(7000, $this->unclaimedTotal('JOD', $other));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function affiliate(string $code): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO affiliates (code, name, commission_bp, window_months) VALUES (:c, :n, :bp, :w)'
        );
        $statement->execute([':c' => $code, ':n' => $code, ':bp' => 2000, ':w' => 12]);

        return (int) $this->pdo->lastInsertId();
    }

    private function earn(
        int $amountMinor,
        string $currency = 'JOD',
        string $source = 'external',
        string $status = 'accrued',
        ?int $affiliateId = null,
    ): void {
        $affiliateId ??= $this->affiliateId;

        $workspace = $this->nextWorkspace++;
        $tenant = $this->pdo->prepare('INSERT INTO tenants (id, name, slug) VALUES (:id, :n, :s)');
        $tenant->execute([':id' => $workspace, ':n' => 'w' . $workspace, ':s' => 'w' . $workspace]);

        $referral = $this->pdo->prepare(
            'INSERT INTO affiliate_referrals (affiliate_id, tenant_id, referred_at, first_paid_at)
             VALUES (:a, :t, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $referral->execute([':a' => $affiliateId, ':t' => $workspace]);
        $referralId = (int) $this->pdo->lastInsertId();

        $statement = $this->pdo->prepare(
            'INSERT INTO affiliate_commissions
                (referral_id, affiliate_id, source, source_ref, base_minor, rate_bp,
                 amount_minor, currency, status, occurred_at)
             VALUES (:r, :a, :source, :ref, :base, :rate, :amount, :currency, :status, CURRENT_TIMESTAMP)'
        );
        $statement->bindValue(':r', $referralId, PDO::PARAM_INT);
        $statement->bindValue(':a', $affiliateId, PDO::PARAM_INT);
        $statement->bindValue(':source', $source);
        $statement->bindValue(':ref', 'INV-' . $workspace);
        $statement->bindValue(':base', 15000, PDO::PARAM_INT);
        $statement->bindValue(':rate', 2000, PDO::PARAM_INT);
        $statement->bindValue(':amount', $amountMinor, PDO::PARAM_INT);
        $statement->bindValue(':currency', $currency);
        $statement->bindValue(':status', $status);
        $statement->execute();
    }

    private function unclaimedTotal(string $currency, ?int $affiliateId = null): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COALESCE(SUM(amount_minor), 0) FROM affiliate_commissions
              WHERE affiliate_id = :a AND currency = :c AND payout_id IS NULL'
        );
        $statement->execute([':a' => $affiliateId ?? $this->affiliateId, ':c' => $currency]);

        return (int) $statement->fetchColumn();
    }

    private function payoutCount(): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM affiliate_payouts');

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }
}

/**
 * A database that lets somebody else in at the worst possible moment.
 *
 * ── Why a fake connection rather than a second thread ──────────────────────
 *
 * The property under test is an INTERLEAVING: a balance is read, and before the
 * rows that made it are claimed, another payout takes some. Threads would make
 * that non-deterministic, and SQLite in-memory has exactly one connection, so
 * there is no second one to race with. Hooking the moment the claim is prepared
 * reproduces the same interleaving exactly, every run.
 *
 * It steals ONE row, so the assembly claims fewer than its balance counted —
 * which is precisely the state where a payout's stored total stops describing
 * its own lines.
 *
 * Only the six methods {@see PayoutAssembler} actually uses are delegated. The
 * parent constructor is deliberately not called: this is a decorator wearing
 * PDO's type, not a connection, and any method it forgets should fail loudly
 * rather than quietly reach a second database.
 */
final class ClaimJumpingPdo extends PDO
{
    private bool $stolen = false;

    public function __construct(private readonly PDO $inner)
    {
        // No parent::__construct() on purpose — see the class docblock.
    }

    /** @param array<int, mixed> $options */
    public function prepare(string $query, array $options = []): \PDOStatement|false
    {
        // The claim is the one UPDATE that stamps a payout onto commissions.
        if (!$this->stolen && str_contains($query, 'UPDATE affiliate_commissions') && str_contains($query, 'payout_id = :payout')) {
            $this->stolen = true;
            $this->steal();
        }

        return $this->inner->prepare($query, $options);
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->inner->lastInsertId($name);
    }

    public function inTransaction(): bool
    {
        return $this->inner->inTransaction();
    }

    public function beginTransaction(): bool
    {
        return $this->inner->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->inner->commit();
    }

    public function rollBack(): bool
    {
        return $this->inner->rollBack();
    }

    /**
     * Another payout takes one of the rows this assembly was counting on.
     *
     * `net_minor` IS SET EXPLICITLY, and the first version of this did not —
     * which PostgreSQL rejected outright on `affiliate_payouts_amounts_add_up`
     * while SQLite, which cannot express the constraint, accepted it. The
     * constraint was doing exactly its job: a rival payout claiming one minor
     * unit and transferring zero does not add up. Worth leaving noted, because
     * the fixture looked perfectly fine on the engine it was written against.
     */
    private function steal(): void
    {
        $this->inner->exec(
            "INSERT INTO affiliate_payouts
                 (affiliate_id, total_minor, withholding_minor, net_minor, currency, status, created_at, updated_at)
             SELECT affiliate_id, 1, 0, 1, currency, 'draft', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
               FROM affiliate_commissions WHERE payout_id IS NULL LIMIT 1"
        );
        $rival = (int) $this->inner->lastInsertId();

        $this->inner->exec(
            'UPDATE affiliate_commissions SET payout_id = ' . $rival .
            ' WHERE id = (SELECT id FROM affiliate_commissions WHERE payout_id IS NULL ORDER BY id ASC LIMIT 1)'
        );
    }
}
