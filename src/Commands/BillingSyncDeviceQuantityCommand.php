<?php

declare(strict_types=1);

namespace Whity\Commands;

use DateTimeImmutable;
use PDO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Whity\Core\Billing\External\BillingPortal;
use Whity\Core\Billing\External\DeviceQuantitySyncRun;
use Whity\Core\Billing\LicensedDeviceCount;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Cron Command: bill per-device subscriptions for the devices that exist.
 *
 * WHY A COMMAND AND NOT ONLY A QUEUE JOB. {@see \Whity\Core\Billing\Jobs\SyncDeviceQuantityJob}
 * registers the same sweep for the scheduler, which is the right home for it on
 * a deployment running `schedule:run` and `queue:work`. Not every deployment
 * does. On one that does not, the job is registered, invocable, and never
 * invoked — indistinguishable from working until a customer's fleet doubles and
 * their invoice does not.
 *
 * So the sweep is also reachable the way this repo's other operational sweeps
 * are: a plain command a cron can call, needing no queue, no worker and no
 * scheduler row. Mirrors billing:reconcile-access.
 *
 * THIS IS WHAT MAKES "PER DEVICE" TRUE. Until something calls it, a per-device
 * subscription charges whatever quantity it was opened with, for as long as it
 * lives.
 *
 * It never resizes on silence, and it never sets a quantity to zero — see
 * {@see DeviceQuantitySyncRun} — so running it often is safe even when the
 * billing service is having a bad day.
 *
 * Usage:
 *   php public/index.php billing:sync-device-quantity [--limit=100]
 *
 * Cron Schedule:
 *   17 *\/6 * * * php /var/www/whity/public/index.php billing:sync-device-quantity
 *   (Four times a day. Devices are billed monthly, so hours of drift cost
 *   nothing — and each pass makes one request per candidate tenant against the
 *   same allowance a customer's checkout is waiting on, which is a reason to be
 *   less eager here than with the access sweep, not more.)
 */
final class BillingSyncDeviceQuantityCommand
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly BillingPortal $portal,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * @param int|null $limit How many tenants to check this pass.
     *
     * @return int Process exit code.
     */
    public function execute(?int $limit = null): int
    {
        if (!$this->portal->isConfigured()) {
            // Not an error: a deployment that bills nobody has no subscription
            // to resize, and a cron failing four times a day on a self-hosted
            // install would teach an operator to ignore it.
            echo "No billing service configured; nothing to sync.\n";

            return 0;
        }

        $run = new DeviceQuantitySyncRun(
            $this->pdo,
            $this->portal,
            new LicensedDeviceCount(
                $this->pdo,
                new SettingsService(
                    new GlobalSettingsRepository($this->pdo),
                    new TenantSettingsRepository($this->pdo),
                ),
            ),
            $this->logger,
        );

        $result = $run->run(new DateTimeImmutable(), $limit ?? DeviceQuantitySyncRun::DEFAULT_BATCH);

        printf(
            "Synced device quantities: checked=%d changed=%d unchanged=%d skipped=%d unreachable=%d%s%s\n",
            $result['checked'],
            $result['changed'],
            $result['unchanged'],
            $result['skipped'],
            $result['unreachable'],
            // Printed only when it happened, because it is the line that asks
            // somebody to go and cancel a subscription — and a "zero_devices=0"
            // on every run would train them not to read it.
            $result['zero_devices'] > 0
                ? sprintf(' — %d paying for devices they no longer run', $result['zero_devices'])
                : '',
            $result['rate_limited'] ? ' (stopped early: rate limited)' : ''
        );

        // A sweep that could not finish is reported to the CALLER, not only to a
        // log: cron mails a non-zero exit somewhere a human reads, and a sweep
        // that keeps stopping early never reaches the tail of the tenant list.
        return $result['rate_limited'] ? 1 : 0;
    }
}
