<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

/**
 * Two payouts tried to claim the same commissions.
 *
 * The assembly read a balance, and by the time it stamped its own id onto those
 * rows somebody else had taken some of them. The payout's total would no longer
 * describe what it contains, so the whole transaction is rolled back rather than
 * adjusted to fit: a payout whose lines do not add up to its amount is the one
 * kind of error the person receiving it is guaranteed to find.
 *
 * Recoverable by simply assembling again — the losing operator sees a balance
 * that has already moved, which is the truth.
 */
final class PayoutRaceException extends \RuntimeException
{
}
