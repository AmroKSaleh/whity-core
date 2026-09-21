<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Affiliate\AffiliateAttribution;

/**
 * Linking a new workspace to whoever sent it.
 *
 * Two properties carry the weight, and they pull against each other:
 *
 *  1. ATTRIBUTION NEVER BREAKS A SIGNUP. A referral code is marketing, not
 *     authentication. A typo, a retired campaign, an affiliate deactivated
 *     between the click and the form — none is a reason to stop somebody
 *     creating an account. A signup lost to protect a commission is a bad trade
 *     in both directions.
 *
 *  2. IT MUST NOT PAY THE WRONG PERSON. Self-service signup is open, so anybody
 *     can create workspaces; attach a commission and somebody will sign up their
 *     own against their own code. The easy version of that is closed here.
 *
 * Everything else is about codes arriving as a human typed them, because that
 * is how a referral link actually reaches somebody: written on a slide, read
 * aloud, retyped.
 */
final class AffiliateAttributionRealEngineTest extends TestCase
{
    private PDO $pdo;
    private AffiliateAttribution $attribution;
    private int $referrerProfile;
    private int $customerProfile;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1,'a','a'), (2,'b','b')");
        // IDS ARE NOT CHOSEN HERE. The schema builder seeds its own profiles —
        // a system administrator among them — so picking 1 and 2 collides with
        // rows this test did not create and knows nothing about.
        $this->referrerProfile = $this->profile('Referrer');
        $this->customerProfile = $this->profile('Customer');

        $this->attribution = new AffiliateAttribution($this->pdo);
    }

    // ── The ordinary case ───────────────────────────────────────────────────

    public function testACodeLinksTheWorkspaceToItsAffiliate(): void
    {
        $affiliateId = $this->affiliate('SPRING26');

        $referralId = $this->attribution->attribute('SPRING26', tenantId: 1, profileId: $this->customerProfile);

        self::assertNotNull($referralId);
        self::assertSame($affiliateId, $this->affiliateOf(1));
    }

    /**
     * A CODE IS TYPED BY A HUMAN. It was written on a slide, read aloud, or
     * pasted with a trailing space — and every one of those must reach the same
     * affiliate as the link did.
     *
     * @dataProvider humanVariations
     */
    public function testACodeIsMatchedAsAHumanWouldType(string $typed): void
    {
        $affiliateId = $this->affiliate('SPRING26');

        self::assertNotNull($this->attribution->attribute($typed, 1, $this->customerProfile));
        self::assertSame($affiliateId, $this->affiliateOf(1));
    }

    /** @return array<string, array{string}> */
    public static function humanVariations(): array
    {
        return [
            'as printed' => ['SPRING26'],
            'all lower' => ['spring26'],
            'mixed case' => ['Spring26'],
            'trailing space' => ['SPRING26 '],
            'leading space' => [' SPRING26'],
        ];
    }

    /** A code stored in lower case is still found when shouted. */
    public function testMatchingWorksWhicheverCaseTheCodeWasStoredIn(): void
    {
        $affiliateId = $this->affiliate('spring26');

        self::assertNotNull($this->attribution->attribute('SPRING26', 1, $this->customerProfile));
        self::assertSame($affiliateId, $this->affiliateOf(1));
    }

    // ── Never breaking a signup ─────────────────────────────────────────────

    /**
     * @dataProvider codesThatEarnNothing
     */
    public function testACodeThatEarnsNothingStillLeavesTheSignupIntact(string $code): void
    {
        $this->affiliate('SPRING26');

        // The contract is a null return, never an exception: the caller is
        // inside the registration transaction and a throw here would roll back
        // somebody's account over a marketing code.
        self::assertNull($this->attribution->attribute($code, 1, $this->customerProfile));
        self::assertNull($this->affiliateOf(1));
    }

    /** @return array<string, array{string}> */
    public static function codesThatEarnNothing(): array
    {
        return [
            'a typo' => ['SPRNIG26'],
            'a code nobody has' => ['NOSUCHCODE'],
            'empty' => [''],
            'only whitespace' => ['   '],
        ];
    }

    /** A retired campaign earns nothing and still lets the person in. */
    public function testADeactivatedAffiliateEarnsNothing(): void
    {
        $this->affiliate('SPRING26', active: false);

        self::assertNull($this->attribution->attribute('SPRING26', 1, $this->customerProfile));
        self::assertNull($this->affiliateOf(1));
    }

    // ── The self-dealing guard ──────────────────────────────────────────────

    /**
     * AN AFFILIATE CANNOT EARN ON A WORKSPACE THEY REGISTERED THEMSELVES.
     * Signup is open and payment-gated, so without this the programme pays
     * anybody willing to create their own workspaces — not as fraud
     * necessarily, but because the system offered.
     */
    public function testAnAffiliateCannotReferThemselves(): void
    {
        $this->affiliate('SPRING26', profileId: $this->referrerProfile);

        self::assertNull($this->attribution->attribute('SPRING26', tenantId: 1, profileId: $this->referrerProfile));
        self::assertNull($this->affiliateOf(1), 'No referral, so nothing to accrue against.');
    }

    /** But they may still refer somebody else, which is the whole job. */
    public function testAnAffiliateMayReferSomebodyElse(): void
    {
        $affiliateId = $this->affiliate('SPRING26', profileId: $this->referrerProfile);

        self::assertNotNull($this->attribution->attribute('SPRING26', tenantId: 1, profileId: $this->customerProfile));
        self::assertSame($affiliateId, $this->affiliateOf(1));
    }

    /** An affiliate with no account here is not accidentally self-referring. */
    public function testAnAffiliateWithoutAProfileIsNotTreatedAsSelfReferral(): void
    {
        $affiliateId = $this->affiliate('SPRING26', profileId: null);

        self::assertNotNull($this->attribution->attribute('SPRING26', 1, $this->customerProfile));
        self::assertSame($affiliateId, $this->affiliateOf(1));
    }

    // ── One referrer, for good ──────────────────────────────────────────────

    /**
     * THE FIRST REFERRER KEEPS THE WORKSPACE. Two affiliates claiming the same
     * customer is not a conflict anybody notices until both are being paid, so
     * the second attempt is refused rather than overwriting.
     */
    public function testAWorkspaceKeepsItsFirstReferrer(): void
    {
        $first = $this->affiliate('FIRST');
        $this->affiliate('SECOND');

        self::assertNotNull($this->attribution->attribute('FIRST', 1, $this->customerProfile));
        self::assertNull($this->attribution->attribute('SECOND', 1, $this->customerProfile));

        self::assertSame($first, $this->affiliateOf(1));
    }

    /** One affiliate can of course refer many workspaces. */
    public function testOneAffiliateCanReferManyWorkspaces(): void
    {
        $affiliateId = $this->affiliate('SPRING26');

        $this->attribution->attribute('SPRING26', 1, $this->customerProfile);
        $this->attribution->attribute('SPRING26', 2, $this->customerProfile);

        self::assertSame($affiliateId, $this->affiliateOf(1));
        self::assertSame($affiliateId, $this->affiliateOf(2));
    }

    // ── The coupon half ─────────────────────────────────────────────────────

    /** Most affiliate codes are also coupons — that is how a referrer persuades anyone. */
    public function testACodeCanCarryADiscount(): void
    {
        $this->pdo->exec("INSERT INTO promotions (id, name, code, percent_off) VALUES (7,'Spring','SPRING26',20)");
        $this->affiliate('SPRING26', promotionId: 7);

        self::assertSame(7, $this->attribution->promotionIdFor('SPRING26'));
    }

    /** And some are pure commission, which is the same kind of affiliate. */
    public function testACommissionOnlyCodeCarriesNoDiscount(): void
    {
        $this->affiliate('SPRING26');

        self::assertNull($this->attribution->promotionIdFor('SPRING26'));
    }

    /**
     * THE DISCOUNT SURVIVES A SELF-REFERRAL. The person is still entitled to
     * whatever the code publicly advertises; what they do not get is a
     * commission for themselves. Two questions, two answers.
     */
    public function testASelfReferrerStillGetsTheAdvertisedDiscount(): void
    {
        $this->pdo->exec("INSERT INTO promotions (id, name, code, percent_off) VALUES (7,'Spring','SPRING26',20)");
        $this->affiliate('SPRING26', profileId: $this->referrerProfile, promotionId: 7);

        self::assertNull($this->attribution->attribute('SPRING26', 1, profileId: $this->referrerProfile));
        self::assertSame(7, $this->attribution->promotionIdFor('SPRING26'));
    }

    public function testAnUnknownCodeCarriesNoDiscount(): void
    {
        self::assertNull($this->attribution->promotionIdFor('NOSUCHCODE'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────────

    private function affiliate(
        string $code,
        bool $active = true,
        ?int $profileId = null,
        ?int $promotionId = null,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO affiliates (code, name, commission_bp, is_active, profile_id, promotion_id)
             VALUES (:code, :name, :bp, :active, :profile, :promo)'
        );
        $statement->bindValue(':code', $code);
        $statement->bindValue(':name', $code);
        $statement->bindValue(':bp', 2000, PDO::PARAM_INT);
        $statement->bindValue(':active', $active, PDO::PARAM_BOOL);
        $statement->bindValue(':profile', $profileId, $profileId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->bindValue(':promo', $promotionId, $promotionId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function profile(string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                                   two_factor_backup_codes_version, token_epoch)
             VALUES (:name, :hash, :off, 0, 0)'
        );
        $statement->bindValue(':name', $name);
        $statement->bindValue(':hash', 'x');
        $statement->bindValue(':off', false, PDO::PARAM_BOOL);
        $statement->execute();

        return (int) $this->pdo->lastInsertId();
    }

    private function affiliateOf(int $tenantId): ?int
    {
        $statement = $this->pdo->prepare('SELECT affiliate_id FROM affiliate_referrals WHERE tenant_id = :t');
        $statement->execute([':t' => $tenantId]);
        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }
}
