<?php

declare(strict_types=1);

namespace Whity\Core\Queue;

use Whity\Core\Container\HostWiredService;
use Whity\Core\Support\SourceSlug;
use Whity\Sdk\JobInterface;

/**
 * Maps a job NAME to the handler that runs it. Core services and plugins
 * register their handlers at boot; {@see JobRunner} resolves a reserved job's
 * `name` to its handler. A job whose name has no registered handler is
 * dead-lettered (it can never run).
 *
 * Two doors, because the two kinds of registration answer to different rules.
 * {@see register()} is core's: it takes the name verbatim, because core owns the
 * `core.`-prefixed namespace and is trusted to spell it. {@see registerFromSource()}
 * is a plugin's: the caller supplies the plugin NAME beside the declaration and
 * the registry stamps the prefix, so a plugin can declare any name it likes but
 * cannot declare who said it — neither shadowing a core handler nor taking over
 * another plugin's.
 *
 * {@see HostWiredService}: an improvised, empty instance would dead-letter
 * EVERY job and report every handler as non-submittable — both of which are
 * ordinary answers for an unknown job name, so the caller could not tell an
 * unwired container from a genuinely unknown job. An unregistered lookup throws
 * instead.
 */
final class JobRegistry implements HostWiredService
{
    /** Source name for handlers shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Separates a plugin's namespace from its declared name: `acme:sync`.
     *
     * A colon, matching {@see \Whity\Core\RBAC\ResourceTypeRegistry} and
     * {@see \Whity\Core\Health\HealthProbeRegistry}. Core jobs stay in their own
     * `core.`-prefixed dotted namespace (`core.notifications.deliver`) — no
     * colon, so namespacing plugins renames nothing already enqueued and no
     * plugin declaration can produce a core name.
     */
    public const NAMESPACE_SEPARATOR = ':';

    /**
     * The width of `jobs.name` (migration 065).
     *
     * A canonical name wider than the column can be registered but never
     * enqueued — a handler that exists and can never run. Rejecting it at
     * declaration time turns that into one logged warning when the plugin loads.
     */
    public const MAX_NAME_LENGTH = 191;

    /**
     * A declared job name: lowercase, starts with a letter, dot-separated
     * segments allowed because core's own names already have that shape.
     *
     * Deliberately has NO colon — that is the separator the host applies, and a
     * declaration containing one would be a plugin writing its own prefix.
     */
    private const NAME_PATTERN = '/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /** @var array<string, JobInterface> */
    private array $handlers = [];

    /**
     * Which SOURCE registered each canonical name (plugin registrations only).
     *
     * Kept so a re-registration can tell "this plugin is declaring its own name
     * again" (a reload — fine) from "a second plugin is claiming a name that is
     * already taken" (refused). Core registrations are absent, which is what
     * makes a plugin unable to overwrite one even if a future core name grew a
     * colon.
     *
     * @var array<string, string>
     */
    private array $sourceByName = [];

    /**
     * Job names that may be enqueued through the public POST /api/jobs API.
     * FAIL-CLOSED: a handler is NOT API-submittable unless it explicitly opts in
     * (register(..., submittable: true)). This stops an authenticated caller
     * from triggering arbitrary INTERNAL handlers (notification, webhook, GC,
     * …) via the generic submission endpoint — only handlers deliberately
     * exposed as tenant-invokable are accepted.
     *
     * @var array<string, true>
     */
    private array $submittable = [];

    public function register(string $name, JobInterface $handler, bool $submittable = false): void
    {
        $this->handlers[$name] = $handler;
        if ($submittable) {
            $this->submittable[$name] = true;
        }
    }

    /**
     * Register the handlers declared by one plugin, under that plugin's namespace.
     *
     * NAMESPACING. A plugin's handlers are stored under its own prefix — a
     * plugin declaring `sync` is registered as `acme:sync`. Two consequences,
     * both intended:
     *
     *  - two plugins declaring `sync` get DIFFERENT canonical names, so a job
     *    one plugin enqueued can never be handed to the other's handler;
     *  - a plugin cannot produce a bare or `core.`-prefixed name, so it cannot
     *    SHADOW `core.notifications.deliver` or any other core job no matter
     *    what it declares.
     *
     * The prefix comes from the SOURCE, which the loader supplies from
     * `$plugin->getName()` — never from the plugin's own return value. A plugin
     * may declare any name it likes; it cannot declare who said it. Same
     * attribution model as {@see \Whity\Core\RBAC\PermissionRegistry::register()}.
     *
     * `core` is RESERVED: only {@see CoreJobs::register()} registers core
     * handlers, and it goes through the plain {@see register()}, so a plugin
     * cannot register itself as core and mint unprefixed names.
     *
     * Every entry is validated before ANY is stored. A half-registered plugin
     * would silently DEAD-LETTER the jobs that did not make it — the exact
     * failure this seam exists to remove — so a malformed declaration costs the
     * plugin all of its jobs rather than an arbitrary subset.
     *
     * @param string                $source      A plugin name; `core` is reserved.
     * @param array<string, mixed>  $jobs        Bare name => {@see JobInterface}.
     * @param list<string>          $submittable Bare names that may be enqueued via the public API.
     *
     * @return list<string> The canonical names registered.
     *
     * @throws InvalidPluginJobException If any entry is malformed, the source is
     *                                   unusable, or a canonical name is already
     *                                   owned by a different source.
     */
    public function registerFromSource(string $source, array $jobs, array $submittable = []): array
    {
        if ($source === self::CORE_SOURCE) {
            throw InvalidPluginJobException::forReservedSource($source);
        }

        $prefix = SourceSlug::from($source);
        if ($prefix === null) {
            throw InvalidPluginJobException::forSource($source);
        }

        // Validate the whole batch first — nothing is stored until all of it is
        // known good.
        $canonical = [];
        foreach ($jobs as $name => $handler) {
            $name = (string) $name;
            if (preg_match(self::NAME_PATTERN, $name) !== 1) {
                throw InvalidPluginJobException::forJobName($name);
            }
            if (!$handler instanceof JobInterface) {
                throw InvalidPluginJobException::forHandler($name, $handler);
            }

            $key = $prefix . self::NAMESPACE_SEPARATOR . $name;
            if (strlen($key) > self::MAX_NAME_LENGTH) {
                throw InvalidPluginJobException::forOversizedName($key);
            }
            // Owned by somebody else — two plugin names can reduce to the same
            // slug, so namespacing makes this rare, not impossible.
            if (isset($this->handlers[$key]) && ($this->sourceByName[$key] ?? null) !== $source) {
                throw InvalidPluginJobException::forTakenName($key, $source);
            }

            $canonical[$name] = $key;
        }

        foreach ($submittable as $bare) {
            if (!isset($canonical[(string) $bare])) {
                throw InvalidPluginJobException::forUnknownSubmittable((string) $bare);
            }
        }

        $exposed = array_flip(array_map('strval', $submittable));
        foreach ($canonical as $name => $key) {
            // Cleared first: register() only ever SETS the flag, so a plugin
            // that withdrew a job from its submittable list on reload would
            // otherwise stay exposed on the strength of a previous declaration.
            unset($this->submittable[$key]);
            $this->register($key, $jobs[$name], isset($exposed[$name]));
            $this->sourceByName[$key] = $source;
        }

        return array_values($canonical);
    }

    /**
     * The canonical name a given source's bare name resolves to.
     *
     * Callers holding a bare name and a source — a plugin enqueuing its own job
     * — use this rather than concatenating by hand, so the namespacing rule
     * lives in exactly one place.
     */
    public static function canonicalName(string $source, string $bareName): string
    {
        if ($source === self::CORE_SOURCE) {
            return $bareName;
        }

        $prefix = SourceSlug::from($source);

        return $prefix === null ? $bareName : $prefix . self::NAMESPACE_SEPARATOR . $bareName;
    }

    public function has(string $name): bool
    {
        return isset($this->handlers[$name]);
    }

    /**
     * Whether `$name` may be enqueued via the public job-submission API.
     */
    public function isSubmittable(string $name): bool
    {
        return isset($this->submittable[$name]);
    }

    /**
     * The job names exposed to the public submission API (fail-closed allowlist).
     *
     * @return list<string>
     */
    public function submittableNames(): array
    {
        return array_keys($this->submittable);
    }

    public function get(string $name): ?JobInterface
    {
        return $this->handlers[$name] ?? null;
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->handlers);
    }
}
