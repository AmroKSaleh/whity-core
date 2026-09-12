<?php

declare(strict_types=1);

namespace Whity\Commands;

use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Billing\External\AccessReconciliationRun;
use Whity\Core\Billing\External\AccessRecorder;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Plan\PlanRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Subscription\SubscriptionRepository;
use Whity\Core\Subscription\SubscriptionService;

/**
 * Cron Command: re-ask the billing service who may use paid features.
 *
 * WHY A COMMAND AND NOT ONLY A QUEUE JOB. {@see \Whity\Core\Billing\Jobs\ReconcileExternalAccessJob}
 * registers the same sweep for the scheduler, and that is the right home for it
 * on a deployment running `schedule:run` and `queue:work`. Not every deployment
 * does. On one that does not, the job is registered, invocable, and never
 * invoked — which is indistinguishable from working until a customer pays and
 * is not let in.
 *
 * So the sweep is also reachable the way this repo's other operational sweeps
 * are: a plain command a cron can call, needing no queue, no worker and no
 * scheduler row. Mirrors revoked-tokens:cleanup and form-uploads:sweep.
 *
 * THIS IS THE SAFETY NET, NOT AN OPTIMISATION. Notifications from the billing
 * service are best-effort with bounded retries; this is what makes a missed one
 * cost a delay instead of a customer who paid and cannot get in. Scheduling it
 * is therefore not optional on a deployment that sells anything.
 *
 * It never revokes on silence — see {@see AccessReconciliationRun} — so running
 * it often is safe even when the billing service is having a bad day.
 *
 * Usage:
 *   php public/index.php billing:reconcile-access [--limit=100]
 *
 * Cron Schedule:
 *   *\/15 * * * * php /var/www/whity/public/index.php billing:reconcile-access
 *   (Every 15 minutes: a missed notification then costs at most that long. The
 *   sweep is bounded and stops early if the billing service rate limits it, so
 *   a short interval does not spend the request budget a checkout needs.)
 */
final class BillingReconcileAccessCommand
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BillingPortal $portal,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param int|null $limit How many tenants to re-check this pass.
     *
     * @return int Process exit code.
     */
    public function execute(?int $limit = null): int
    {
        if (!$this->portal->isConfigured()) {
            // Not an error: a deployment that bills nobody has nothing to
            // reconcile, and a cron that failed every quarter-hour on a
            // self-hosted install would teach an operator to ignore it.
            echo "No billing service configured; nothing to reconcile.\n";

            return 0;
        }

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo),
        );

        $run = new AccessReconciliationRun(
            $this->pdo,
            $this->portal,
            new AccessRecorder(
                new SubscriptionService(new SubscriptionRepository($this->pdo), $settings),
                new PlanRepository($this->pdo),
                $this->logger,
            ),
            $this->logger,
        );

        $result = $run->run($limit ?? AccessReconciliationRun::DEFAULT_BATCH);

        printf(
            "Reconciled access: checked=%d changed=%d unreachable=%d skipped=%d%s\n",
            $result['checked'],
            $result['changed'],
            $result['unreachable'],
            $result['skipped'],
            $result['rate_limited'] ? ' (stopped early: rate limited)' : ''
        );

        // A sweep that could not finish is reported to the CALLER, not only to a
        // log: cron mails a non-zero exit somewhere a human reads, and a sweep
        // that keeps stopping early never reaches the tail of the tenant list.
        return $result['rate_limited'] ? 1 : 0;
    }
}
