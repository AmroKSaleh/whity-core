<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Whity\Core\Queue\Jobs\EchoJob;

/**
 * Registers the core (non-plugin) job handlers into a {@see JobRegistry}.
 *
 * Called at BOTH ends of the queue so producer and consumer agree on what
 * exists and what is publicly submittable: the web request path (index.php)
 * uses it so {@see \Whity\Api\JobsApiHandler} can validate submittable names,
 * and the `queue:work` worker uses it so it can actually RUN those jobs. Plugins
 * layer their own handlers onto the same registry.
 */
final class CoreJobs
{
    public static function register(JobRegistry $registry): void
    {
        // The diagnostic echo job is currently the only API-submittable core job
        // (it opts in via the third arg). Internal core jobs would register here
        // WITHOUT that flag so they can run but not be submitted from the API.
        $registry->register(EchoJob::NAME, new EchoJob(), true);
    }
}
