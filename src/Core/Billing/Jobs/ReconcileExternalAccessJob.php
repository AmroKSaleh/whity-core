<?php

declare(strict_types=1);

namespace Whity\Core\Billing\Jobs;

use Whity\Core\Billing\External\AccessReconciliationRun;
use Whity\Sdk\JobInterface;

/**
 * The scheduled sweep that re-asks the billing service about everyone.
 *
 * WITHOUT THIS, THE RECONCILIATION CLASS IS DECORATION. A sweep nobody invokes
 * has exactly the reliability of the notifications it exists to backstop, and
 * the whole argument for it — that a missed delivery should cost latency rather
 * than correctness — depends on something calling it on a clock.
 *
 * SAFE TO RUN TWICE, and by construction rather than by care: it reads the
 * authoritative answer and writes what it read, so a second pass over the same
 * tenants writes the same values. That is what lets it satisfy
 * {@see JobInterface}'s at-least-once contract honestly.
 *
 * INTERNAL, NEVER API-SUBMITTABLE. It is registered without the submittable
 * flag — an endpoint anyone could POST to would let a caller aim this
 * deployment's whole tenant list at a third party, one request per tenant, as
 * often as they liked.
 *
 * IT REVOKES NOBODY ON SILENCE. That property lives in the run itself, and it is
 * the reason this can be scheduled aggressively without an outage at the billing
 * service turning into a mass lockout here.
 */
final class ReconcileExternalAccessJob implements JobInterface
{
    public const NAME = 'core.billing.reconcile_access';

    public function __construct(private readonly AccessReconciliationRun $run)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        // The counts are the result, so `GET /api/jobs/{id}` answers "did the
        // sweep find anything" without anybody reading a log — and a `changed`
        // that is persistently non-zero is the signal that notifications are not
        // arriving, which is worth noticing long before a customer complains.
        return $this->run->run();
    }
}
