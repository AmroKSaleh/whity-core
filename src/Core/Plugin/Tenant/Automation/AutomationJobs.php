<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant\Automation;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssueRefused;
use Whity\Core\Document\Issuing\IssueInput;
use Whity\Core\Document\Issuing\TemplateIssuing;
use Whity\Core\Notification\NotificationDispatcher;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Notification\TransportRegistry;
use Whity\Core\Plugin\Tenant\RecordWriter;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\Queue\JobRegistry;
use Whity\Core\Queue\JobRepository;
use Whity\Core\Queue\QueueService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Database\Database;

/**
 * Registers the job that runs workspace plugin automations (WHIT-763), with
 * everything it needs built on the worker's own connection. Internal: only
 * the triggers enqueue it.
 */
final class AutomationJobs
{
    private function __construct()
    {
    }

    public static function register(JobRegistry $registry, PDO $pdo, TransportRegistry $transports, ?LoggerInterface $logger = null): void
    {
        $permissions = new PermissionRegistry();
        $permissions->registerCorePermissions();
        $roleChecker = new RoleChecker(Database::withFactory(static fn (): PDO => $pdo), $permissions, $logger);
        $plugins = new TenantPluginRepository($pdo);
        $records = new TenantRecordRepository($pdo);
        $runs = new AutomationRunRepository($pdo);
        $queue = new QueueService(new JobRepository($pdo));
        $audit = new AuditLogger($pdo, $logger);

        $registry->register(
            RunPluginAutomationJob::NAME,
            new RunPluginAutomationJob(new AutomationRunner(
                $pdo,
                $runs,
                new TenantManifestHost($plugins, $permissions, $logger),
                $plugins,
                $records,
                new RecordWriter($pdo, $records, $roleChecker, $audit, new AutomationTriggers($runs, $plugins, $queue)),
                $roleChecker,
                new NotificationDispatcher(new NotificationRepository($pdo), $transports, $queue, null, $logger, null, $audit),
                // The kernel registers the issuing service (WHIT-742); resolved
                // on the first issue, so a worker that never issues never needs it.
                static function (ActingAs $actor, IssueInput $input): array {
                    try {
                        $issuing = \Whity\app(TemplateIssuing::class);
                    } catch (\RuntimeException) {
                        throw new DocumentIssueRefused('documents_unavailable', 'Documents can\'t be issued on this instance', 503);
                    }

                    return $issuing->issue($actor, $input);
                },
            )),
            false
        );
    }
}
