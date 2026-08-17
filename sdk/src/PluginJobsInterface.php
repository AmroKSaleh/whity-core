<?php

declare(strict_types=1);

namespace Whity\Sdk;

/**
 * Optional ASYNC-JOB contribution point for plugins (SDK v1.28).
 *
 * A plugin MAY implement this interface — in addition to
 * {@see PluginInterface} — to declare the {@see JobInterface} handlers it
 * contributes to the host's job registry. Like the sibling capability
 * interfaces ({@see PluginRolesInterface}, {@see PluginMcpInterface},
 * {@see PluginFrontendInterface}), this is purely additive: plugins that do not
 * implement it load exactly as before.
 *
 * Why it exists
 * -------------
 * {@see JobInterface} and the durable queue have always been public, but
 * nothing DISCOVERED a plugin's handlers, so the shipped `queue:work` worker
 * knew only the core ones and dead-lettered anything a plugin enqueued as "No
 * handler registered for job". The alternative was for every plugin to ship its
 * own worker command — an operator running one worker process per plugin, each
 * re-registering the core handlers to avoid dropping core's own work.
 *
 * Namespacing
 * -----------
 * Declare BARE names. The host stores them under this plugin's own namespace,
 * so a plugin declaring `sync` is registered — and must be ENQUEUED — as
 * `acme:sync`. Two consequences, both intended:
 *
 *  - two plugins declaring `sync` get DIFFERENT canonical names, so one plugin's
 *    work can never be handed to another plugin's handler;
 *  - a plugin cannot produce a bare name, so it cannot SHADOW a core job such as
 *    `core.notifications.deliver` no matter what it declares.
 *
 * The prefix is derived from the plugin NAME the loader supplies, never from
 * anything returned here: a plugin may declare any name it likes, but it cannot
 * declare who said it. A declared name carrying the `:` separator is refused for
 * that reason — it would be the plugin writing its own prefix.
 *
 * Dependencies
 * ------------
 * The plugin CONSTRUCTS its own handlers here, so its own repositories and
 * collaborators reach them the same way a route handler's do: resolve the host
 * services you need from the host service container at declaration time. Nothing
 * is injected into {@see JobInterface::handle()} beyond the payload — by design,
 * since the host cannot know what a plugin's handler needs.
 *
 * Failure isolation
 * -----------------
 * The worker that runs these handlers also runs CORE's notification delivery and
 * error alerting, so it is infrastructure: one bad plugin must not stop it. A
 * {@see getJobs()} that THROWS, or that returns a malformed declaration, costs
 * that plugin its jobs (logged, and recorded against the plugin's lifecycle) and
 * costs the worker nothing. The refusal is WHOLE-declaration, not per entry: a
 * half-registered plugin would silently dead-letter the jobs that did not make
 * it, which is harder to diagnose than contributing none.
 *
 *     public function getJobs(): array
 *     {
 *         return [
 *             'catalog.sync' => new CatalogSyncJob($this->repository()),
 *         ];
 *     }
 *
 *     public function getSubmittableJobs(): array
 *     {
 *         return ['catalog.sync'];
 *     }
 */
interface PluginJobsInterface
{
    /**
     * The job handlers this plugin contributes, BARE name => handler.
     *
     * A name is lowercase, starts with a letter, and continues with letters,
     * digits, underscores or dots (`sync`, `catalog.sync`) — the shape core's
     * own job names already use. It carries NO colon: that is the namespace
     * separator the host applies.
     *
     * Handlers are constructed once, at plugin-load time, and reused for every
     * job the worker runs — so resolve anything connection-shaped lazily inside
     * {@see JobInterface::handle()} rather than caching a handle in the
     * constructor, exactly as a long-lived route handler would.
     *
     * @return array<string, JobInterface>
     */
    public function getJobs(): array;

    /**
     * Which of the declared jobs may be enqueued through the host's public
     * job-submission API, as BARE names matching keys of {@see getJobs()}.
     *
     * FAIL-CLOSED, mirroring core: a handler is internal — runnable by the
     * worker, not reachable from the API — unless it is listed here. Listing a
     * name this plugin does not ship is refused rather than ignored, since it
     * reads as an exposed job and is not one.
     *
     * Submittable does NOT mean unauthenticated: the host's submission endpoint
     * still requires its own permission, and the job runs under the SUBMITTING
     * tenant. Opting in means "a tenant may ask for this on demand", so the
     * handler must treat its payload as caller-supplied input and validate it.
     *
     * @return list<string>
     */
    public function getSubmittableJobs(): array;
}
