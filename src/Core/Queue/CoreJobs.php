<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Notification\CoreTransports;
use Whity\Core\Notification\Jobs\SendNotificationDeliveryJob;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\TransportRegistry;
use Whity\Core\Observability\ErrorGroupRepository;
use Whity\Core\Observability\Jobs\NotifyErrorGroupJob;
use Whity\Core\Queue\Jobs\EchoJob;

/**
 * Registers the core (non-plugin) job handlers into a {@see JobRegistry}.
 *
 * Called at BOTH ends of the queue so producer and consumer agree on what
 * exists and what is publicly submittable: the web request path (index.php)
 * uses it so {@see \Whity\Api\JobsApiHandler} can validate submittable names,
 * and the `queue:work` worker uses it so it can actually RUN those jobs. Plugins
 * layer their own handlers onto the same registry.
 *
 * Some core jobs need runtime deps (a PDO / transports) and so are only
 * registered when a `$pdo` is supplied (the worker + the web boot path); a
 * dep-free caller (e.g. a unit test that only checks the submittable allowlist)
 * still gets a valid registry with the stateless jobs.
 */
final class CoreJobs
{
    public static function register(
        JobRegistry $registry,
        ?PDO $pdo = null,
        ?TransportRegistry $transports = null,
        ?LoggerInterface $logger = null
    ): void {
        // The diagnostic echo job is currently the only API-submittable core job
        // (it opts in via the third arg). Internal core jobs register WITHOUT that
        // flag so they can run but not be submitted from the API.
        $registry->register(EchoJob::NAME, new EchoJob(), true);

        if ($pdo !== null) {
            // The notification-delivery job (WC-notifications) is internal: the
            // dispatcher enqueues it, never a public caller. It needs the
            // notification data layer + the transport registry (defaulting to the
            // built-in log transports so a fresh deployment never hard-fails).
            $transports ??= CoreTransports::make($logger);
            $registry->register(
                SendNotificationDeliveryJob::NAME,
                new SendNotificationDeliveryJob(
                    new NotificationRepository($pdo),
                    $transports,
                    $logger,
                    // Non-PII lifecycle audit of each delivery outcome (WC-notifications #4d40cc1c).
                    new \Whity\Core\Audit\AuditLogger($pdo, $logger)
                ),
                false
            );

            // WC-error-tracking: emails platform operators about a NEW or
            // REGRESSED error. Internal — capture enqueues it, never a public
            // caller. It needs the notification dispatcher so alerts honour the
            // same channels, preferences and transports as every other
            // notification instead of reaching for SMTP directly.
            $registry->register(
                NotifyErrorGroupJob::NAME,
                new NotifyErrorGroupJob(
                    $pdo,
                    new ErrorGroupRepository($pdo),
                    new NotificationDispatcher(
                        new NotificationRepository($pdo),
                        $transports,
                        new QueueService(new JobRepository($pdo)),
                        null,
                        $logger
                    ),
                    $logger,
                    is_string($_ENV['WHITY_PUBLIC_URL'] ?? null) ? (string) $_ENV['WHITY_PUBLIC_URL'] : null
                ),
                false
            );

            // #billing — the two scheduled runs. INTERNAL, both of them, and
            // registered without the submittable flag deliberately: one issues
            // invoices (so it spends the number sequence and puts debts in
            // front of customers) and the other WITHDRAWS ACCESS. An endpoint
            // anybody could POST to would be a way to lock tenants out on
            // demand, and to do it without appearing anywhere as an
            // administrative action.
            //
            // Both are safe to run twice, which is what makes them honest
            // members of an at-least-once queue: the billing run collides on
            // migration 145's unique index rather than double-billing, and
            // locking is a state rather than an event.
            $billingSettings = new \Whity\Core\Settings\SettingsService(
                new \Whity\Core\Settings\GlobalSettingsRepository($pdo),
                new \Whity\Core\Settings\TenantSettingsRepository($pdo)
            );
            $billingInvoices = new \Whity\Core\Billing\InvoiceRepository($pdo);
            $billingSubscriptions = new \Whity\Core\Subscription\SubscriptionService(
                new \Whity\Core\Subscription\SubscriptionRepository($pdo),
                $billingSettings
            );

            $registry->register(
                \Whity\Core\Billing\Jobs\RunSubscriptionBillingJob::NAME,
                new \Whity\Core\Billing\Jobs\RunSubscriptionBillingJob(
                    new \Whity\Core\Billing\SubscriptionBillingRun(
                        $pdo,
                        $billingInvoices,
                        new \Whity\Core\Billing\InvoiceNumberAllocator(
                            new \Whity\Database\SequenceCounters($pdo)
                        ),
                        $billingSubscriptions,
                        $billingSettings,
                        $logger ?? new \Psr\Log\NullLogger()
                    )
                ),
                false
            );

            $registry->register(
                \Whity\Core\Billing\Jobs\RunDunningJob::NAME,
                new \Whity\Core\Billing\Jobs\RunDunningJob(
                    new \Whity\Core\Billing\DunningRun(
                        $billingInvoices,
                        new \Whity\Core\Billing\DunningService(
                            $billingInvoices,
                            new \Whity\Core\Payment\PaymentLedger($pdo),
                            $billingSubscriptions
                        ),
                        $billingSettings,
                        $logger ?? new \Psr\Log\NullLogger()
                    )
                ),
                false
            );
        }
    }
}
