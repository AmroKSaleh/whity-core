<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Core\Container\HostWiredService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Support\SourceSlug;

/**
 * The catalogue of RESOURCE TYPES that may carry a role grant (WC-712 §2).
 *
 * Why a registry rather than a free string
 * ----------------------------------------
 * `resource_role_assignments.resource_type` addresses a grant at a KIND of
 * record ("this document", "this catalogue item"). If that column accepted any
 * string, two things follow immediately: a typo (`documnet`) silently creates a
 * grant nothing will ever resolve — authorization that fails CLOSED but is
 * invisible to the operator who wrote it — and two plugins can collide on one
 * name with no way to tell whose rows are whose.
 *
 * Validating writes against a declared catalogue makes both impossible. An
 * unregistered type is rejected at the boundary rather than stored and ignored.
 *
 * Deliberately an INSTANCE service, mirroring {@see PermissionRegistry}
 * ---------------------------------------------------------------------
 * Not a static catalogue. Process-level statics are per FrankenPHP worker, so a
 * registration performed while serving one request is invisible to the other
 * workers — the hazard that produced the stale-permission bug in PR #701. An
 * instance resolved from the container is rebuilt per request from the same
 * plugin bootstrap every worker runs, so every worker agrees.
 *
 * Shared surface
 * --------------
 * #713 (resource-scoped extension data) and #714 (tag applicability) need the
 * SAME vocabulary. This registry is the one place it is defined; both consume it
 * rather than each declaring a private copy, which is precisely the
 * second-source-of-truth problem resource-scoped grants exist to avoid.
 */
class ResourceTypeRegistry implements HostWiredService
{
    /**
     * Resource types organized by source (plugin id, or {@see self::CORE_SOURCE}).
     *
     * Structure: ['source' => ['ou', 'document']]
     *
     * @var array<string, array<int, string>>
     */
    protected array $typesBySource = [];

    /**
     * Whether the core resource types have already been registered.
     */
    private bool $coreRegistered = false;

    private ?HookManager $hookManager = null;

    /** Source name for resource types shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Separates a plugin's namespace from its slug: `acme:record`.
     *
     * A colon, matching the shape #723 already uses for data-type keys. Core
     * types stay BARE (`ou`) — they are the reserved, unprefixed namespace, and
     * `ou` is already stored that way in `resource_role_assignments`, so
     * namespacing plugins does not rewrite data already written.
     */
    public const NAMESPACE_SEPARATOR = ':';

    /**
     * The organizational unit — the ONE resource type core ships.
     *
     * OU grants predate this registry (`ou_role_assignments`, migration 008) and
     * are now the `resource_type = 'ou'` case of the general table, so the OU
     * remains addressable exactly as before while sharing one storage model.
     */
    public const TYPE_OU = 'ou';

    /**
     * The issued DOCUMENT — the second resource type core ships (#947 item 3).
     *
     * #947 names `resource_role_assignments` as the answer to "who may act on a
     * document", and until now no such type was registered: every write to that
     * table passes through {@see exists()}, so a grant naming a document was
     * refused outright and the composition #947 describes could not be written
     * down at all.
     *
     * It qualifies on the same test `ou` does. A document is a first-class core
     * record with its own table, its own id and its own permissions (migrations
     * 108/109/113) — not a plugin's idea of a record — so grants against it are
     * core's to define. It is also what an `inbox` block's `scopedPermission`
     * resolves against, since the thing a person holds authority over is the
     * document rather than the assignment row that mentions it.
     */
    public const TYPE_DOCUMENT = 'document';

    public function __construct(?HookManager $hookManager = null)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * Register resource types under a source.
     *
     * Every type is validated before ANY is stored: a malformed entry aborts the
     * whole registration rather than leaving a half-applied catalogue.
     *
     * NAMESPACING. A plugin's types are stored under its own prefix — a plugin
     * declaring `record` is registered as `acme:record`. Two consequences, both
     * intended:
     *
     *  - two plugins declaring `record` get DIFFERENT canonical types, so they
     *    cannot silently share (or steal) each other's grants;
     *  - a plugin cannot produce a bare core key, so it cannot SHADOW `ou` or
     *    any future core type no matter what it declares.
     *
     * The prefix comes from the SOURCE, which the loader supplies from
     * `$plugin->getName()` — never from the plugin's own data. A plugin may
     * declare any slug it likes; it cannot declare who said it. That is the same
     * attribution model as {@see PermissionRegistry::register()}.
     *
     * `core` is RESERVED: only {@see registerCoreResourceTypes()} may use it, so
     * a plugin cannot register itself as core and mint bare keys.
     *
     * @param string             $source The source (a plugin name; `core` is reserved).
     * @param array<int, string> $types  Bare slugs, e.g. `['record', 'catalog_item']`.
     *
     * @throws InvalidResourceTypeException If any slug is malformed, or a caller
     *                                      other than core claims the `core` source.
     */
    public function register(string $source, array $types): void
    {
        if ($source === self::CORE_SOURCE) {
            throw InvalidResourceTypeException::forReservedSource($source);
        }

        $prefix = self::sourcePrefix($source);
        if ($prefix === null) {
            throw InvalidResourceTypeException::forSource($source);
        }

        $canonical = [];
        foreach ($types as $type) {
            if (!self::isValidResourceType($type)) {
                throw InvalidResourceTypeException::forResourceType($type);
            }
            $canonical[] = $prefix . self::NAMESPACE_SEPARATOR . $type;
        }

        $this->storeAndDispatch($source, $canonical);
    }

    /**
     * The canonical key a given source's slug resolves to.
     *
     * Callers that hold a bare slug and a source (a plugin granting on its own
     * type) use this rather than concatenating by hand, so the namespacing rule
     * lives in exactly one place.
     */
    public static function canonicalKey(string $source, string $type): string
    {
        if ($source === self::CORE_SOURCE) {
            return $type;
        }

        $prefix = self::sourcePrefix($source);

        return $prefix === null ? $type : $prefix . self::NAMESPACE_SEPARATOR . $type;
    }

    /**
     * Normalise a source name into a slug usable as a namespace prefix.
     *
     * Delegates to {@see SourceSlug}, which the table-ownership registry
     * (WC-723) uses for the same purpose: two registries deriving "who is this
     * plugin?" with two slightly different rules would let one call a plugin
     * `acme` while the other calls it `acme_widgets`, so the rule lives once.
     * Returns null when nothing usable survives, so a nameless plugin is
     * rejected rather than silently registering unprefixed types.
     */
    private static function sourcePrefix(string $source): ?string
    {
        return SourceSlug::from($source);
    }

    /**
     * Register the resource types core owns. Idempotent and bootstrap-safe.
     */
    public function registerCoreResourceTypes(): void
    {
        if ($this->coreRegistered) {
            return;
        }

        // Set first so the dispatch hook cannot recurse back into lazy core
        // registration (same guard as PermissionRegistry).
        $this->coreRegistered = true;
        $this->storeAndDispatch(self::CORE_SOURCE, [self::TYPE_OU, self::TYPE_DOCUMENT]);
    }

    /**
     * Whether a resource type is registered by any source.
     *
     * The gate every write to `resource_role_assignments` passes through.
     */
    public function exists(string $resourceType): bool
    {
        $this->ensureCoreRegistered();

        foreach ($this->typesBySource as $types) {
            if (in_array($resourceType, $types, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every registered resource type, across all sources, distinct.
     *
     * @return list<string>
     */
    public function getAll(): array
    {
        $this->ensureCoreRegistered();

        $all = [];
        foreach ($this->typesBySource as $types) {
            foreach ($types as $type) {
                $all[$type] = true;
            }
        }

        return array_keys($all);
    }

    /**
     * The resource types registered by one source.
     *
     * @return list<string>
     */
    public function getBySource(string $source): array
    {
        $this->ensureCoreRegistered();

        return array_values($this->typesBySource[$source] ?? []);
    }

    /**
     * A valid slug: lowercase, starts with a letter, then letters/digits/underscore.
     *
     * Intentionally has NO colon, unlike a permission slug (`resource:action`) —
     * a resource type names a KIND of record, not a capability, and allowing the
     * permission shape here would invite passing one where the other is meant.
     */
    public static function isValidResourceType(string $resourceType): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $resourceType) === 1;
    }

    /**
     * Store under a source and announce it, de-duplicating within that source.
     *
     * @param array<int, string> $types
     */
    private function storeAndDispatch(string $source, array $types): void
    {
        $existing = $this->typesBySource[$source] ?? [];
        $merged = [];
        foreach ([...$existing, ...$types] as $type) {
            $merged[$type] = true;
        }
        $this->typesBySource[$source] = array_keys($merged);

        $this->hookManager?->dispatch('rbac.resource_types.registered', [
            'source' => $source,
            'resource_types' => $types,
        ]);
    }

    /**
     * Lazily register core types on first read, so a reader never sees a
     * catalogue missing `ou` merely because bootstrap order changed.
     */
    private function ensureCoreRegistered(): void
    {
        if (!$this->coreRegistered) {
            $this->registerCoreResourceTypes();
        }
    }
}
