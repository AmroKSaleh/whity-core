<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Linking a new workspace to whoever sent it.
 *
 * ── Attribution never breaks a signup ──────────────────────────────────────
 *
 * Every refusal here returns null rather than throwing. A referral code is
 * MARKETING, not authentication: a typo, a retired campaign, an affiliate
 * deactivated between the click and the form — none of those are reasons to
 * stop somebody creating an account, and a signup that fails because a link was
 * stale is a customer lost to protect a commission.
 *
 * So the code either produces a referral or it does not, and the person
 * registering is never told which. They did not choose the code and cannot fix
 * it. The refusal is logged, where whoever runs the programme can see it.
 *
 * ── The self-dealing guard ─────────────────────────────────────────────────
 *
 * Self-service signup is open and payment-gated, which means anybody can create
 * workspaces. Attach a commission to that and somebody will sign up their own
 * workspaces against their own code and farm it — not as fraud, necessarily, but
 * because the system offered.
 *
 * The guard here catches the obvious version: an affiliate cannot earn on a
 * workspace they registered themselves. It does not catch a friend signing up
 * on their behalf, and no amount of software will; that is what the earnings
 * report and a human are for. What it does mean is that the easy version is
 * closed, and the remaining version requires a second person to be complicit.
 */
final class AffiliateAttribution
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Record that this workspace came from a referral code.
     *
     * MUST BE CALLED INSIDE THE REGISTRATION TRANSACTION. The referral and the
     * workspace are one fact: a referral pointing at a tenant whose creation
     * rolled back would be a commission owed for a customer that does not
     * exist.
     *
     * @param string $code      Whatever arrived from the link. Trimmed and
     *                          matched case-insensitively — somebody will have
     *                          written it on a slide and somebody else will have
     *                          typed it back in the wrong case.
     * @param int    $tenantId  The workspace just created.
     * @param int    $profileId Who registered it, for the self-dealing guard.
     *
     * @return int|null The referral id, or null when the code produced nothing.
     */
    public function attribute(string $code, int $tenantId, int $profileId): ?int
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $affiliate = $this->activeAffiliateByCode($code);
        if ($affiliate === null) {
            // A typo, a retired campaign, or a deactivated affiliate. Logged
            // rather than raised: whoever runs the programme wants to know a
            // live link is producing nothing, and the person signing up does
            // not.
            $this->logger->info('A referral code matched no active affiliate', [
                'code' => $code,
                'tenant_id' => $tenantId,
            ]);

            return null;
        }

        if ($affiliate['profile_id'] !== null && (int) $affiliate['profile_id'] === $profileId) {
            // SELF-REFERRAL. The signup proceeds — they are entitled to an
            // account — but it earns nobody anything.
            $this->logger->warning('An affiliate registered a workspace against their own code', [
                'affiliate_id' => (int) $affiliate['id'],
                'tenant_id' => $tenantId,
                'profile_id' => $profileId,
            ]);

            return null;
        }

        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO affiliate_referrals (affiliate_id, tenant_id, referred_at)
                 VALUES (:affiliate, :tenant, CURRENT_TIMESTAMP)'
            );
            $statement->execute([
                ':affiliate' => (int) $affiliate['id'],
                ':tenant' => $tenantId,
            ]);
        } catch (\PDOException $e) {
            // ONE REFERRER PER WORKSPACE is a UNIQUE constraint, and losing that
            // race is not an error: the workspace already has a referrer, and
            // the first one keeps it. Anything else is a real fault and must be
            // heard — a blanket catch here is what turned a schema problem into
            // silence elsewhere in this feature.
            if (!self::isDuplicate($e)) {
                throw $e;
            }

            $this->logger->info('A workspace already had a referrer; the code was ignored', [
                'tenant_id' => $tenantId,
            ]);

            return null;
        }

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The discount a code carries, if it carries one.
     *
     * Most affiliate codes are also coupons — that is how a referrer persuades
     * somebody to use theirs. Returned separately from attribution because the
     * two can succeed independently: a self-referral earns no commission but the
     * person is still entitled to whatever public discount the code advertises.
     */
    public function promotionIdFor(string $code): ?int
    {
        $affiliate = $this->activeAffiliateByCode(trim($code));

        return $affiliate === null || $affiliate['promotion_id'] === null
            ? null
            : (int) $affiliate['promotion_id'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeAffiliateByCode(string $code): ?array
    {
        // LOWER() on both sides, matching the functional unique index the table
        // carries. Comparing raw would let 'SPRING26' miss a row stored as
        // 'spring26' — and the link somebody typed by hand is exactly the case
        // this feature exists to serve.
        $statement = $this->pdo->prepare(
            'SELECT id, profile_id, promotion_id FROM affiliates
              WHERE LOWER(code) = LOWER(:code) AND is_active = :on'
        );
        $statement->bindValue(':code', $code);
        $statement->bindValue(':on', true, PDO::PARAM_BOOL);
        $statement->execute();

        /** @var array<string, mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private static function isDuplicate(\PDOException $e): bool
    {
        $sqlState = $e->getCode();

        return $sqlState === '23505' || $sqlState === '23000';
    }
}
