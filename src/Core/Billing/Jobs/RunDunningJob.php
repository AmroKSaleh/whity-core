<?php

declare(strict_types=1);

namespace Whity\Core\Billing\Jobs;

use DateTimeImmutable;
use Whity\Core\Billing\DunningRun;
use Whity\Sdk\JobInterface;

/**
 * The scheduled dunning sweep.
 *
 * INTERNAL, NEVER API-SUBMITTABLE, and the reason is sharper here than for the
 * billing run: this job WITHDRAWS ACCESS. An endpoint anybody could POST to
 * would be a way to lock tenants out on demand — and worse, a way to do it
 * without appearing anywhere as an administrative action, because from the
 * outside the lock is indistinguishable from one the schedule reached on its
 * own.
 *
 * IT TAKES `now` FROM THE CLOCK. A moment supplied by a caller would let one
 * arrive far enough in the future that every overdue invoice was past its lock
 * day — which is the same as the paragraph above with an extra step.
 *
 * SAFE TO RUN TWICE, because locking is a STATE and not an event: setting a
 * tenant that is already `past_due` to `past_due` changes nothing. A retry
 * after a timeout re-walks the same invoices and locks nobody a second time.
 */
final class RunDunningJob implements JobInterface
{
    public const NAME = 'core.billing.dunning';

    public function __construct(
        private readonly DunningRun $run,
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
