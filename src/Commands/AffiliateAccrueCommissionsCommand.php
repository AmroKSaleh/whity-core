<?php

declare(strict_types=1);

namespace Whity\Commands;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Affiliate\CombinedPaymentSources;
use Whity\Core\Affiliate\CommissionAccrualRun;
use Whity\Core\Affiliate\ExternalReceiptPayments;
use Whity\Core\Affiliate\LocalInvoicePayments;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Cron Command: pay affiliates for what their referrals actually paid.
 *
 * WHY A COMMAND AND NOT ONLY A QUEUE JOB. {@see \Whity\Core\Affiliate\Jobs\AccrueAffiliateCommissionsJob}
 * registers the same sweep for the scheduler, which is right on a deployment
 * running `schedule:run` and `queue:work`. Not every deployment does, and on one
 * that does not the job is registered, invocable and never invoked —
 * indistinguishable from working, because an affiliate programme that has earned
 * nothing looks exactly like one nobody has used yet.
 *
 * BOTH SOURCES, ALWAYS. A workspace can have local invoices from before it moved
 * to the billing service and receipts from after; both are real revenue earned by
 * the same referrer. Reading only one would stop paying at the moment of the move
 * and nobody would notice until an affiliate reconciled their own statement.
 *
 * Usage:
 *   php public/index.php affiliate:accrue-commissions [--limit=200]
 *
 * Cron Schedule:
 *   40 3 *\/1 * * php /var/www/whity/public/index.php affiliate:accrue-commissions
 *   (Nightly. Commissions are paid out on a human schedule — monthly, by
 *   somebody approving a payout — so there is nothing to gain from sweeping
 *   hourly, and each pass costs one request per referred workspace against the
 *   same billing-service allowance a customer's checkout is waiting on.)
 */
final class AffiliateAccrueCommissionsCommand
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BillingPortal $portal,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param int|null $limit How many referrals to process this pass.
     *
     * @return int Process exit code.
     */
    public function execute(?int $limit = null): int
    {
        $run = new CommissionAccrualRun(
            $this->pdo,
            new CombinedPaymentSources(
                new LocalInvoicePayments($this->pdo),
                // Answers with nothing rather than failing when no billing
                // service is configured, so a self-hosted deployment that
                // invoices locally still accrues on its own invoices.
                new ExternalReceiptPayments($this->portal, $this->logger),
            ),
            $this->clawsBackOnRefund(),
            $this->logger,
        );

        $result = $run->run(new DateTimeImmutable(), $limit ?? CommissionAccrualRun::DEFAULT_BATCH);

        printf(
            "Accrued affiliate commissions: accrued=%d reversed=%d already=%d skipped=%d outside_window=%d%s%s\n",
            $result['accrued'],
            $result['reversed'],
            $result['already'],
            $result['skipped'],
            $result['outside_window'],
            // Printed only when they happened. A line that says "0" on every
            // run is one an operator learns to skip, and these two are the
            // lines that ask somebody to do something.
            $result['partial_refunds'] > 0
                ? sprintf(' — %d partly refunded, needing a decision', $result['partial_refunds'])
                : '',
            $result['unreachable'] > 0
                ? sprintf(' — %d workspaces unreadable, so these numbers are incomplete', $result['unreachable'])
                : ''
        );

        // AN INCOMPLETE SWEEP IS REPORTED TO THE CALLER, not only to a log. Cron
        // mails a non-zero exit somewhere a human reads, and the failure this
        // catches — a billing service that has been unreachable for a week —
        // otherwise shows up as commissions quietly not being earned.
        return $result['unreachable'] > 0 ? 1 : 0;
    }

    /**
     * Whether a refunded payment claws its commission back.
     *
     * Read from settings rather than decided here: it is a commercial choice,
     * and the operator who makes it is not the one deploying the code.
     */
    private function clawsBackOnRefund(): bool
    {
        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );

        // Defaults to clawing back when the key is absent, matching the
        // registry. Spelled out rather than assumed, because the failure of
        // getting this backwards is paying out money nobody collected.
        return ($settings->getGlobal()[SettingsRegistry::AFFILIATE_CLAWBACK_ON_REFUND] ?? 'true') !== 'false';
    }
}
