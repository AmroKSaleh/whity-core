<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

/**
 * Both places a referred workspace's money can be, read as one history.
 *
 * ── Why this is not a choice between two engines ───────────────────────────
 *
 * It is tempting to pick a source per deployment, or per tenant, from whichever
 * flag says who bills them. That is wrong for the case this feature will
 * actually meet: a workspace that started on local invoices and later moved to
 * the billing service has real revenue in both, earned by the same referrer, and
 * a source that answered with only one of them would quietly stop paying at the
 * moment of the move.
 *
 * DOUBLE-COUNTING IS NOT THE RISK IT LOOKS LIKE. The local billing run stands
 * down for any tenant the billing service holds — that is what `external_ref`
 * decides — so the same period is never invoiced twice, and the historic local
 * invoices are separate payments that really were made. The two sources also
 * carry different `source` values, so even an accidental overlap would be two
 * distinguishable ledger rows rather than one silently doubled commission.
 *
 * ── A PARTIAL HISTORY IS REFUSED, which is the counter-intuitive half ──────
 *
 * When one source fails, the reflex is to accrue what the other returned and
 * report the gap. That is wrong here, and the reason is the earning window: the
 * sweep takes the OLDEST payment it is given to decide when earning started, and
 * writes that date once, permanently. Hand it a history missing the early half
 * and it freezes the window on a date that is merely the oldest thing we could
 * reach — and no later, healthy sweep will correct it, because the window is
 * already set.
 *
 * So every source is still tried, so the log names each one that is down rather
 * than only the first, and then the failure is raised and the whole referral is
 * skipped for this pass. The accrual loses nothing by waiting: the next
 * successful sweep re-reads the entire history and pays everything owed.
 */
final class CombinedPaymentSources implements ReferredPaymentSource
{
    /** @var list<ReferredPaymentSource> */
    private readonly array $sources;

    public function __construct(ReferredPaymentSource ...$sources)
    {
        $this->sources = array_values($sources);
    }

    /**
     * @return list<ReferredPayment>
     */
    public function paymentsFor(int $tenantId): array
    {
        $payments = [];
        $failure = null;

        foreach ($this->sources as $source) {
            try {
                foreach ($source->paymentsFor($tenantId) as $payment) {
                    $payments[] = $payment;
                }
            } catch (ReferredPaymentSourceException $e) {
                // KEPT, NOT RAISED YET. A later source may still answer, and
                // whether it does changes nothing about this failure — but
                // stopping here would let one unreachable service hide the fact
                // that another was fine.
                $failure ??= $e;
            }
        }

        if ($failure !== null) {
            // An incomplete history is not safe to accrue against at all. See
            // the class docblock: the earning window is frozen from the oldest
            // payment, once, and a gap would freeze it wrong for good.
            throw $failure;
        }

        return $payments;
    }
}
