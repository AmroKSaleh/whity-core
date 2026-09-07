<?php

declare(strict_types=1);

namespace Whity\Core\Billing\Jobs;

use DateTimeImmutable;
use Whity\Core\Billing\SubscriptionBillingRun;
use Whity\Sdk\JobInterface;

/**
 * The scheduled billing run.
 *
 * INTERNAL, NEVER API-SUBMITTABLE. It is registered without the submittable
 * flag, which is not a formality: a job that issues invoices is a job that
 * spends the invoice-number sequence and puts debts in front of customers, and
 * an endpoint anybody could POST to would let a caller advance every
 * subscription's period at will.
 *
 * IT TAKES `now` FROM THE CLOCK, NOT FROM THE PAYLOAD. A billable moment
 * supplied by a caller would let one arrive in the future and bill periods that
 * have not happened. The run has no reason to be told what time it is.
 *
 * SAFE TO RUN TWICE, and by construction rather than by care — the unique index
 * from migration 145 makes a duplicate period collide. That property is what
 * lets this satisfy {@see JobInterface}'s at-least-once contract honestly: a
 * retry after a timeout re-runs the whole sweep, and the tenants already billed
 * come back as `skipped`.
 */
final class RunSubscriptionBillingJob implements JobInterface
{
    public const NAME = 'core.billing.run';

    public function __construct(
        private readonly SubscriptionBillingRun $run,
        private readonly ?\Closure $clock = null,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        // The result is the run's counts, so `GET /api/jobs/{id}` answers "what
        // did last night's billing do" without anybody reading a log.
        return $this->run->run($this->now());
    }

    private function now(): DateTimeImmutable
    {
        if ($this->clock !== null) {
            /** @var DateTimeImmutable $moment */
            $moment = ($this->clock)();

            return $moment;
        }

        return new DateTimeImmutable();
    }
}
