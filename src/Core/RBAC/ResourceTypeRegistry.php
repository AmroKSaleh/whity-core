<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use Whity\Core\Hooks\HookManager;

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
class ResourceTypeRegistry
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

    /** Source name for resource types shipped by core. */
    public const CORE_SOURCE = 'core';

    /**
     * The organizational unit — the ONE resource type core ships.
     *
     * OU grants predate this registry (`ou_role_assignments`, migration 008) and
     * are now the `resource_type = 'ou'` case of the general table, so the OU
     * remains addressable exactly as before while sharing one storage model.
     */
    public const TYPE_OU = 'ou';

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
     * @param string             $source The source (`core` or a plugin name).
     * @param array<int, string> $types  Slugs, e.g. `['document', 'catalog_item']`.
     *
     * @throws InvalidResourceTypeException If any slug is malformed.
     */
    public function register(string $source, array $types): void
    {
        foreach ($types as $type) {
            if (!self::isValidResourceType($type)) {
                throw InvalidResourceTypeException::forResourceType($type);
            }
        }

        $this->storeAndDispatch($source, array_values($types));
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
        $this->storeAndDispatch(self::CORE_SOURCE, [self::TYPE_OU]);
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
