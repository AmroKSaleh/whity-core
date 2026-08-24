<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Psr\Log\LoggerInterface;
use Throwable;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Router;

/**
 * Registers the PLUGIN-contributed job handlers into a {@see JobRegistry} — the
 * plugin-side sibling of {@see CoreJobs}.
 *
 * Used by callers that have no plugin loader of their own: the `queue:work`
 * worker is a bare CLI process that serves no HTTP and wires no registries, but
 * it is the process that actually RUNS queued work, so it is precisely where a
 * plugin's handlers have to be known. An entry point that already holds a
 * fully-wired loader (public/index.php) calls
 * {@see PluginLoader::collectJobs()} on THAT loader instead — a second, throwaway
 * loader there would re-run every plugin's constructor and answer from a
 * different lifecycle state than the one serving requests.
 *
 * The plugins register into a throwaway Router with no permission registry and
 * no role seeder: nothing in the worker process serves HTTP, so routes and role
 * seeding are not capabilities it needs.
 *
 * A HOOK MANAGER, however, is not an HTTP concern and the worker does need one
 * (#935). Job handlers dispatch events — core CRUD through the hook map
 * {@see AuditLogger::subscribe()} binds, plugins through their declared events
 * ({@see \Whity\Sdk\PluginEventsInterface}) — and with no manager wired here
 * those dispatches reached no listener, so a job wrote no audit row at all.
 * Silently: a dispatch nobody listens to is indistinguishable from one whose
 * listeners did nothing, and a job runs unattended, so there is nobody present
 * to notice the row that never appeared.
 */
final class PluginJobs
{
    /**
     * @param string|null      $pluginDir   Defaults to the host's own plugins directory.
     * @param HookManager|null $hooks       The worker's hook manager, so a job's
     *                                      events reach their listeners (#935).
     * @param AuditLogger|null $auditLogger Writer that plugin-declared events are
     *                                      subscribed to. Wired together with the
     *                                      hook manager or not at all — a logger
     *                                      with no manager has nothing to hear.
     */
    public static function register(
        JobRegistry $registry,
        ?string $pluginDir = null,
        ?LoggerInterface $logger = null,
        ?HookManager $hooks = null,
        ?AuditLogger $auditLogger = null
    ): void {
        $pluginDir ??= dirname(__DIR__, 3) . '/plugins';

        // A worker that cannot load plugins must still run CORE's jobs —
        // notification delivery and error alerting among them. Discovery failing
        // is a degraded worker, never a dead one.
        try {
            $loader = new PluginLoader(
                $pluginDir,
                new Router(''),
                null,
                $hooks,
                $logger,
                null,
                null,
                null,
                null,
                null,
                null,
                $auditLogger
            );
            $loader->load();
            $loader->collectJobs($registry);
        } catch (Throwable $e) {
            $message = '[queue] plugin jobs unavailable, running core handlers only: ' . $e->getMessage();
            if ($logger !== null) {
                $logger->error($message, ['exception' => $e::class]);
            } else {
                error_log($message);
            }
        }
    }
}
