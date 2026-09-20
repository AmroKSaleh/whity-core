<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate;

/**
 * Where a referred workspace's payments are read from.
 *
 * An interface because the money genuinely lives in two places. A deployment
 * that bills locally has invoices in its own tables; one that sells through the
 * billing service has them there, reachable only over HTTP. The accrual sweep
 * must not care which — the rules about windows, rates, rounding and idempotency
 * are the same either way, and duplicating them per source is how two of them
 * end up disagreeing after somebody fixes one.
 */
interface ReferredPaymentSource
{
    /**
     * Every payment this workspace has made, oldest or newest first — the
     * caller sorts.
     *
     * THE WHOLE HISTORY, NOT THE NEW ONES. The sweep is idempotent by
     * construction (one commission per payment, enforced by a unique index), so
     * re-reading everything costs a collision rather than a double payment — and
     * it is what lets the accrual converge after a missed notification, a failed
     * run, or a refund that happened while nobody was looking.
     *
     * @return list<ReferredPayment>
     *
     * @throws ReferredPaymentSourceException When the history could not be read
     *         at all. NOT an empty list: a source that cannot answer and a
     *         customer who has never paid look identical from here and need
     *         opposite responses.
     */
    public function paymentsFor(int $tenantId): array;
}
