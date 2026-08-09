<?php

declare(strict_types=1);

namespace Whity\Core\Health;

use Throwable;
use Whity\Core\Hooks\HookManager;
use Whity\Sdk\Health\HealthProbeDefinition;

/**
 * The catalogue of COMPONENTS this deployment samples for the status page.
 *
 * Why a registry rather than a hardcoded list
 * -------------------------------------------
 * {@see HealthProbe} used to iterate a literal
 * `['database', 'queue', 'scheduler', 'render']`. Everything else about the
 * status page was already extensible-shaped — the `health_samples` series is
 * keyed by an arbitrary component string, {@see StatusReport} renders whatever
 * it is given, the collector runs as its own process — but that one array meant
 * a plugin owning a real dependency (a directory server, a payment gateway, a
 * device fleet) had no way to have it watched. Its only options were a private
 * status surface nobody looks at, or nothing. This registry is the seam.
 *
 * Deliberately an INSTANCE service, mirroring
 * {@see \Whity\Core\RBAC\ResourceTypeRegistry}
 * -------------------------------------------
 * Not a static catalogue. Process-level statics are per FrankenPHP worker, so a
 * registration performed while serving one request is invisible to the other
 * workers — the hazard that produced the stale-permission bug in PR #701. An
 * instance resolved from the container is rebuilt per boot from the same plugin
 * bootstrap every worker runs, so every worker agrees.
 *
 * Core probes stay core
 * ---------------------
 * The four core components are registered here as BARE keys but carry NO
 * definition: their implementation stays inside {@see HealthProbe}, which
 * matches on them by name before it ever consults a contributed definition. So
 * this class widens WHAT is sampled without touching HOW the existing four are
 * sampled.
 */
class HealthProbeRegistry
{
    /** Source name for probes shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Separates a plugin's namespace from its key: `acme:ldap`.
     *
     * A colon, matching {@see \Whity\Core\RBAC\ResourceTypeRegistry}. Core
     * probes stay BARE (`database`) — they are the reserved, unprefixed
     * namespace, and `database` is already written that way in
     * `health_samples`, so namespacing plugins rewrites no history and does not
     * orphan the uptime figures the status page computes from it.
     */
    public const NAMESPACE_SEPARATOR = ':';

    /**
     * The width of `health_samples.component` (migration 085).
     *
     * A canonical key longer than the column cannot be stored, and because the
     * collector swallows write failures (it must — the database being down is
     * one of the things it reports), an over-long key would fail SILENTLY on
     * every pass forever. Rejecting it at declaration time turns that into one
     * logged warning when the plugin loads.
     */
    public const MAX_KEY_LENGTH = 64;

    public const PROBE_DATABASE = 'database';
    public const PROBE_QUEUE = 'queue';
    public const PROBE_SCHEDULER = 'scheduler';
    public const PROBE_RENDER = 'render';

    /**
     * The components core samples, in the order it has always sampled them.
     *
     * This list IS the array {@see HealthProbe::runAll()} used to hold. Order is
     * preserved and pinned by test, because the collector writes one sample per
     * key per pass and an operator reading the CLI output reads them in order.
     *
     * @var list<string>
     */
    public const CORE_PROBES = [
        self::PROBE_DATABASE,
        self::PROBE_QUEUE,
        self::PROBE_SCHEDULER,
        self::PROBE_RENDER,
    ];

    /**
     * Canonical probe keys grouped by source (plugin name, or {@see CORE_SOURCE}).
     *
     * @var array<string, list<string>>
     */
    protected array $keysBySource = [];

    /**
     * The contributed probes themselves, canonical key => definition.
     *
     * Core keys are absent by design: {@see HealthProbe} implements them.
     *
     * @var array<string, HealthProbeDefinition>
     */
    private array $definitions = [];

    /**
     * Display labels for contributed probes, canonical key => label.
     *
     * @var array<string, string>
     */
    private array $labels = [];

    private bool $coreRegistered = false;

    private ?HookManager $hookManager = null;

    public function __construct(?HookManager $hookManager = null)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * Register the probes core owns. Idempotent and bootstrap-safe.
     */
    public function registerCoreProbes(): void
    {
        if ($this->coreRegistered) {
            return;
        }

        // Set first so the dispatch hook cannot recurse back into lazy core
        // registration (same guard as PermissionRegistry/ResourceTypeRegistry).
        $this->coreRegistered = true;
        $this->keysBySource[self::CORE_SOURCE] = self::CORE_PROBES;
        $this->dispatch(self::CORE_SOURCE, self::CORE_PROBES);
    }

    /**
     * Register the probes declared by one source.
     *
     * Every entry is validated before ANY is stored: a malformed declaration
     * aborts the whole registration rather than leaving a half-applied
     * catalogue in which some of a plugin's components are watched and others
     * silently are not.
     *
     * NAMESPACING. A plugin's probes are stored under its own prefix — a plugin
     * declaring `ldap` is registered as `acme:ldap`. Two consequences, both
     * intended:
     *
     *  - two plugins declaring `ldap` get DIFFERENT canonical components, so
     *    their samples never mix in `health_samples` (which would make both
     *    components' uptime figures fiction);
     *  - a plugin cannot produce a bare core key, so it cannot SHADOW
     *    `database` or any other core probe no matter what it declares. Even if
     *    it could, {@see HealthProbe} matches core names before consulting this
     *    catalogue — two independent reasons the core four cannot be hijacked.
     *
     * The prefix comes from the SOURCE, which the loader supplies from
     * `$plugin->getName()` — never from the plugin's own return value. A plugin
     * may declare any key it likes; it cannot declare who said it. Same
     * attribution model as {@see \Whity\Core\RBAC\PermissionRegistry::register()}.
     *
     * `core` is RESERVED: only {@see registerCoreProbes()} may use it, so a
     * plugin cannot register itself as core and mint bare keys.
     *
     * @param string             $source      A plugin name; `core` is reserved.
     * @param array<int, mixed>  $definitions {@see HealthProbeDefinition} instances.
     *
     * @throws InvalidHealthProbeException If any entry is malformed, or a caller
     *                                     other than core claims the `core` source.
     */
    public function register(string $source, array $definitions): void
    {
        if ($source === self::CORE_SOURCE) {
            throw InvalidHealthProbeException::forReservedSource($source);
        }

        $prefix = self::sourcePrefix($source);
        if ($prefix === null) {
            throw InvalidHealthProbeException::forSource($source);
        }

        // Validate the whole batch first — nothing is stored until all of it is
        // known good.
        $canonical = [];
        foreach ($definitions as $definition) {
            if (!$definition instanceof HealthProbeDefinition) {
                throw InvalidHealthProbeException::forDefinition($definition);
            }
            if (!self::isValidProbeKey($definition->key)) {
                throw InvalidHealthProbeException::forProbeKey($definition->key);
            }

            $key = $prefix . self::NAMESPACE_SEPARATOR . $definition->key;
            if (strlen($key) > self::MAX_KEY_LENGTH) {
                throw InvalidHealthProbeException::forOversizedKey($key);
            }
            if (isset($canonical[$key])) {
                throw InvalidHealthProbeException::forDuplicateKey($definition->key);
            }

            $canonical[$key] = $definition;
        }

        $this->ensureCoreRegistered();

        $existing = $this->keysBySource[$source] ?? [];
        $merged = [];
        foreach ($existing as $key) {
            $merged[$key] = true;
        }
        foreach ($canonical as $key => $definition) {
            $merged[$key] = true;
            $this->definitions[$key] = $definition;
            $this->labels[$key] = $definition->label;
        }
        $this->keysBySource[$source] = array_keys($merged);

        $this->dispatch($source, array_keys($canonical));
    }

    /**
     * Every component to sample, core first, then contributions in
     * registration order.
     *
     * Core first is not cosmetic: it is the order the collector writes samples
     * in, and it keeps a slow plugin probe from delaying the database sample —
     * the one an operator looks at first during an incident.
     *
     * @return list<string>
     */
    public function getAll(): array
    {
        $this->ensureCoreRegistered();

        $all = [];
        foreach ($this->keysBySource[self::CORE_SOURCE] ?? [] as $key) {
            $all[$key] = true;
        }
        foreach ($this->keysBySource as $source => $keys) {
            if ($source === self::CORE_SOURCE) {
                continue;
            }
            foreach ($keys as $key) {
                $all[$key] = true;
            }
        }

        return array_keys($all);
    }

    /**
     * The contributed probe registered under a canonical key, if any.
     *
     * Null for a core key — core probes are implemented by {@see HealthProbe}
     * itself — and null for anything unregistered.
     */
    public function definitionFor(string $key): ?HealthProbeDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /** Whether a component is registered by any source. */
    public function exists(string $key): bool
    {
        $this->ensureCoreRegistered();

        foreach ($this->keysBySource as $keys) {
            if (in_array($key, $keys, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The components registered by one source.
     *
     * @return list<string>
     */
    public function getBySource(string $source): array
    {
        $this->ensureCoreRegistered();

        return array_values($this->keysBySource[$source] ?? []);
    }

    /**
     * Display labels for the CONTRIBUTED components, canonical key => label.
     *
     * Core labels are not here: {@see StatusReport} owns them, along with the
     * display order of the components core has always shown.
     *
     * @return array<string, string>
     */
    public function contributedLabels(): array
    {
        return $this->labels;
    }

    /**
     * The canonical key a given source's bare key resolves to.
     *
     * Callers holding a bare key and a source use this rather than
     * concatenating by hand, so the namespacing rule lives in one place.
     */
    public static function canonicalKey(string $source, string $key): string
    {
        if ($source === self::CORE_SOURCE) {
            return $key;
        }

        $prefix = self::sourcePrefix($source);

        return $prefix === null ? $key : $prefix . self::NAMESPACE_SEPARATOR . $key;
    }

    /**
     * A valid key: lowercase, starts with a letter, then letters/digits/underscore.
     *
     * No colon — that is the namespace separator the host applies, and a key
     * containing one would let a plugin write its own prefix.
     */
    public static function isValidProbeKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $key) === 1;
    }

    /**
     * Normalise a source name into a slug usable as a namespace prefix.
     *
     * Plugin names are PHP-ish (`DemoCatalog`, `Acme\Widgets\Plugin`), so the
     * last segment is lowercased and non-slug characters collapse to
     * underscores. Returns null when nothing usable survives, so a nameless
     * plugin is rejected rather than silently registering unprefixed probes.
     */
    private static function sourcePrefix(string $source): ?string
    {
        $segments = explode('\\', $source);
        $last = (string) end($segments);
        $slug = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', $last) ?? '');
        $slug = trim($slug, '_');

        return $slug === '' || !preg_match('/^[a-z]/', $slug) ? null : $slug;
    }

    /**
     * Lazily register core probes on first read, so a reader never sees a
     * catalogue missing `database` merely because bootstrap order changed.
     */
    private function ensureCoreRegistered(): void
    {
        if (!$this->coreRegistered) {
            $this->registerCoreProbes();
        }
    }

    /**
     * Announce a registration on the durable event spine.
     *
     * A listener throwing must not take the catalogue down with it: the probes
     * are already stored by the time this runs, and a failed announcement is
     * strictly less bad than a deployment whose status page stops sampling.
     *
     * @param list<string> $keys
     */
    private function dispatch(string $source, array $keys): void
    {
        try {
            $this->hookManager?->dispatch('health.probes.registered', [
                'source' => $source,
                'probes' => $keys,
            ]);
        } catch (Throwable) {
            // Best effort by design — see above.
        }
    }
}
