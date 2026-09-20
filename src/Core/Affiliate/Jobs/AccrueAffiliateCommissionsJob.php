<?php

declare(strict_types=1);

namespace Whity\Core\Affiliate\Jobs;

use DateTimeImmutable;
use Whity\Core\Affiliate\CommissionAccrualRun;
use Whity\Sdk\JobInterface;

/**
 * The scheduled sweep that turns referred customers' payments into commissions.
 *
 * WITHOUT SOMETHING CALLING THIS, THE PROGRAMME IS A SCHEMA. Affiliates get
 * codes, codes attach to workspaces, workspaces pay — and the commission ledger
 * stays empty, because nothing ever reads the payments. That failure is
 * particularly quiet: every screen works, every test passes, and the only
 * symptom is a balance of zero that looks exactly like a programme nobody has
 * used yet.
 *
 * SAFE TO RUN TWICE, by construction rather than by care. One commission per
 * payment is a UNIQUE index, so a second pass collides and counts rather than
 * paying again — which is what lets this satisfy {@see JobInterface}'s
 * at-least-once contract honestly, and it matters more here than for most jobs
 * because what a careless retry would repeat is money owed to somebody.
 *
 * INTERNAL, NEVER API-SUBMITTABLE. Registered without the submittable flag: an
 * endpoint anyone could POST to would let a caller drive this deployment's whole
 * referral list at the billing service as often as they liked, and this one ends
 * in amounts payable.
 */
final class AccrueAffiliateCommissionsJob implements JobInterface
{
    public const NAME = 'core.affiliate.accrue_commissions';

    public function __construct(private readonly CommissionAccrualRun $run)
    {
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public function handle(array $payload): array
    {
        // The counts are the result, so `GET /api/jobs/{id}` answers "did
        // anybody earn anything" without reading a log. Two of them are worth
        // watching rather than skimming: `unreachable` means the numbers are
        // incomplete this pass, and `partial_refunds` means a commission is
        // standing on money that partly went back and needs a person.
        return $this->run->run(new DateTimeImmutable());
    }
}
