<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Psr\Log\LoggerInterface;
use Throwable;
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
 * The plugins register into a throwaway Router with no permission registry, hook
 * manager or role seeder, mirroring {@see \Whity\Cli\Commands\HealthWatchCommand}:
 * nothing in the worker process serves HTTP, so the only capability being
 * harvested is the job declaration.
 */
final class PluginJobs
{
    /**
     * @param string|null $pluginDir Defaults to the host's own plugins directory.
     */
    public static function register(
        JobRegistry $registry,
        ?string $pluginDir = null,
        ?LoggerInterface $logger = null
    ): void {
        $pluginDir ??= dirname(__DIR__, 3) . '/plugins';

        // A worker that cannot load plugins must still run CORE's jobs —
        // notification delivery and error alerting among them. Discovery failing
        // is a degraded worker, never a dead one.
        try {
            $loader = new PluginLoader($pluginDir, new Router(''), null, null, $logger);
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
