<?php

declare(strict_types=1);

namespace Whity\Core\Billing;

use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * The sweep that chases unpaid invoices, and eventually withdraws access.
 *
 * {@see DunningService} decides what ONE invoice warrants; this walks all of
 * them and does it. The split is the same one that class draws internally, for
 * the same reason: this is the code path that takes away somebody's access, and
 * a function that both finds and punishes cannot be examined without committing
 * to its conclusions.
 *
 * IT DOES NOT ATTEMPT PAYMENTS, and that is not an omission. Whether an
 * unattended charge is possible at all is a property of the rail
 * ({@see \Whity\Core\Payment\PaymentCapabilities}), and on CliQ — the only rail
 * wired today — the answer is no: only the payer can move the money. A sweep
 * that charged anyway would manufacture a failure per cycle for a customer who
 * has done nothing wrong, and lock them out on schedule for it.
 *
 * So an `attempt` decision is REPORTED rather than acted on, and the count is
 * what a future card provider will hook into. Reporting it now rather than
 * leaving the branch out means the schedule is exercised — a deployment can see
 * that attempt two came due on the fourth — before anything can act on it.
 *
 * LOCKING IS IDEMPOTENT because it is a state, not an event: setting a tenant
 * that is already `past_due` to `past_due` changes nothing, so a sweep that
 * runs twice locks nobody twice and sends nobody a second notice.
 *
 * A RUN NEVER STOPS ON ONE TENANT. A misconfigured schedule, an invoice with an
 * unreadable due date, a subscription row that has gone strange — each is
 * logged and skipped, because the alternative is that one bad row means nobody
 * else is chased and nobody notices for a month.
 */
final class DunningRun
{
    /** How many overdue invoices one tick will consider. */
    private const MAX_PER_RUN = 500;

    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly DunningService $dunning,
        private readonly SettingsService $settings,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Walk every overdue invoice.
     *
     * @return array{considered: int, attempts_due: int, locked: int, skipped: int}
     */
    public function run(DateTimeImmutable $now): array
    {
        $considered = 0;
        $attemptsDue = 0;
        $locked = 0;
        $skipped = 0;

        // Tenants already locked on this pass, so a tenant with three overdue
        // invoices is locked once rather than three times.
        $lockedTenants = [];

        foreach ($this->invoices->overdueOpenAcrossTenants($now, self::MAX_PER_RUN) as $invoice) {
            $considered++;
            $tenantId = (int) $invoice['tenant_id'];

            try {
                $decision = $this->dunning->assess($invoice, $this->scheduleFor($tenantId), $now);
            } catch (\Throwable $e) {
                $this->logger->error('Could not assess an overdue invoice', [
                    'invoice_id' => $invoice['id'] ?? null,
                    'tenant_id' => $tenantId,
                    'reason' => $e->getMessage(),
                ]);
                $skipped++;
                continue;
            }

            if ($decision['action'] === DunningService::ACTION_ATTEMPT) {
                // Reported, not acted on — see the class docblock.
                $attemptsDue++;
                $this->logger->info('A payment attempt is due', [
                    'invoice_id' => $invoice['id'] ?? null,
                    'tenant_id' => $tenantId,
                    'attempt' => $decision['attempt'],
                    'balance_minor' => $decision['balance_minor'],
                ]);
                continue;
            }

            if ($decision['action'] === DunningService::ACTION_LOCK && !isset($lockedTenants[$tenantId])) {
                $this->dunning->lock($tenantId, $now);
                $lockedTenants[$tenantId] = true;
                $locked++;

                $this->logger->warning('Withdrew access for an unpaid subscription', [
                    'tenant_id' => $tenantId,
                    'invoice_id' => $invoice['id'] ?? null,
                    'balance_minor' => $decision['balance_minor'],
                ]);
            }
        }

        return [
            'considered' => $considered,
            'attempts_due' => $attemptsDue,
            'locked' => $locked,
            'skipped' => $skipped,
        ];
    }

    /**
     * The retry policy as it applies to this tenant.
     *
     * Per-tenant on purpose: a deployment chases its enterprise customers
     * differently from its self-service ones, and a single platform-wide
     * schedule would make that impossible without a code change.
     */
    private function scheduleFor(int $tenantId): DunningSchedule
    {
        $effective = $this->settings->effective($tenantId);

        return DunningSchedule::fromSettings(
            (string) ($effective[SettingsRegistry::DUNNING_RETRY_SCHEDULE_DAYS]
                ?? SettingsRegistry::defaultFor(SettingsRegistry::DUNNING_RETRY_SCHEDULE_DAYS)),
            (int) ($effective[SettingsRegistry::DUNNING_LOCK_AFTER_DAYS]
                ?? SettingsRegistry::defaultFor(SettingsRegistry::DUNNING_LOCK_AFTER_DAYS)),
        );
    }
}
