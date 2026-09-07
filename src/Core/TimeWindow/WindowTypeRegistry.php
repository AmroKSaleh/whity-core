<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

use Whity\Core\Container\HostWiredService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Ou\OuTypeRegistry;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Support\SourceSlug;

/**
 * The catalogue of declarable TIME-WINDOW TYPES (#1070).
 *
 * The problem this exists to solve
 * -------------------------------
 * A named, non-overlapping period that records are scoped to and rolled up by,
 * and that can be CLOSED like a set of books, is a primitive the platform did
 * not have. Every subsystem that needed one either built its own or did without,
 * and two implementations of it disagree the moment they exist: a record filed
 * into one subsystem's period does not match the other's, and nothing reports
 * that they differ.
 *
 * Two halves, and both are needed
 * -------------------------------
 * A type has a KEY and a LABEL, and they live in different places on purpose —
 * the same split {@see OuTypeRegistry} makes for the same reason.
 *
 *  - The KEY is what code binds to. A report phrased as "per window of kind
 *    `growing_season`" must mean the same thing on every install, so the key is
 *    governed here, exactly as {@see ResourceTypeRegistry} governs resource
 *    types.
 *  - The LABEL is tenant data. An agricultural operation slices time into a
 *    `crop_year` and its `growing_season`s; a ceramics works into a
 *    `kiln_campaign` and its `firing_run`s. Neither vocabulary belongs in the
 *    other's picker, and a core enumeration would have to contain both — which
 *    is why the VOCABULARY itself lives per tenant in `time_window_types`
 *    (migration 126) rather than here.
 *
 * So this registry is not the whole vocabulary. It governs which keys a PLUGIN
 * may contribute, and supplies the defaults a tenant starts from when it adopts
 * one. A tenant may equally author its own key that no code declared — that is
 * the normal case, and it is why `time_window_types` is a table and not an enum.
 *
 * NESTING IS PART OF THE DECLARATION, BOUNDARIES ARE NOT
 * -----------------------------------------------------
 * A declaration may name the type it nests inside, because "a sub-period sits
 * inside a period" is structural and knowable at declaration time. It may NOT
 * say when a period starts, how long it lasts, or what fraction of its parent it
 * occupies — none of those is knowable, and assuming them is the specific
 * mistake this subsystem exists to avoid. A crop year does not begin on the
 * first of January and a firing run is not a fixed share of a kiln campaign.
 * Every boundary is authored per instance, in dates, by somebody who knows.
 *
 * A plugin may only nest inside its OWN declared types. It does not own another
 * source's type and cannot know whether a given tenant adopted it, so a
 * cross-source parent would let one plugin reshape a hierarchy its author never
 * saw. Once a tenant has ADOPTED types, it may re-parent its own rows freely —
 * the declaration is a starting point, not a constraint on tenant data.
 *
 * The namespace rule, and what it guarantees
 * ------------------------------------------
 * Plugin keys are PREFIXED from the source the loader supplies
 * (`$plugin->getName()`), never from anything the plugin returns:
 * `acme:growing_season`. Two consequences, both intended and both identical to
 * {@see OuTypeRegistry::register()}:
 *
 *  - two plugins may each declare `growing_season` and get DIFFERENT canonical
 *    keys, so neither can adopt into (or shadow) the other's rows;
 *  - a plugin can never produce a BARE key. The unprefixed namespace belongs to
 *    core and to the tenant's own vocabulary, so an install-wide plugin can
 *    never squat on a name a tenant might want.
 *
 * `core` and `tenant` are RESERVED as sources for exactly that reason. And, as
 * with the other catalogues, namespacing does NOT rewrite data already written —
 * an existing bare key in a tenant's `time_window_types` keeps meaning what it
 * meant.
 *
 * Why core declares NO types today
 * --------------------------------
 * {@see coreWindowTypes()} is deliberately empty, for the same reason
 * {@see OuTypeRegistry::coreOuTypes()} is. Core shipping a period vocabulary
 * would be precisely the core enumeration this design rejects: a white-label
 * multi-tenant platform cannot know how a given tenant slices time, and the two
 * vocabularies that motivated this concept came from unrelated domains and share
 * not one word. The bare namespace is reserved anyway, so that if core ever DOES
 * own a period type no plugin can shadow it, and so the reservation does not
 * have to be retrofitted later against keys plugins have already written.
 *
 * Deliberately an INSTANCE service
 * --------------------------------
 * Not a static catalogue. Process-level statics are per FrankenPHP worker, so a
 * registration performed while serving one request is invisible to the other
 * workers — the hazard that produced the stale-permission bug in PR #701. An
 * instance resolved from the container is rebuilt per request from the same
 * plugin bootstrap every worker runs, so every worker agrees.
 */
class WindowTypeRegistry implements HostWiredService
{
    /** Source name for window types shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Provenance marker for a key a TENANT authored through the API.
     *
     * Stored in `time_window_types.source`, never accepted as a registration
     * source: a tenant-authored key is by definition not declared in code, so
     * nothing may register under it. It exists so an operator can tell "we made
     * this up" from "this came with a plugin" when deciding what is safe to
     * rename or remove.
     */
    public const TENANT_SOURCE = 'tenant';

    /**
     * Separates a plugin's namespace from its slug: `acme:growing_season`.
     *
     * The same separator every other catalogue uses, referenced rather than
     * repeated so they cannot drift into spelling the same plugin's keys two
     * different ways.
     */
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;

    /**
     * The query sentinel meaning "windows of no particular type".
     *
     * `GET /api/v1/time-windows?type=none` has to be able to ask for windows
     * whose type filter is deliberately absent, and an empty `?type=` already
     * means "no filter". So one bare key is reserved as the sentinel and refused
     * as a tenant-authored key. A PLUGIN may still declare `none`; it becomes
     * `acme:none`, which is a different key and unambiguous.
     */
    public const UNTYPED = 'none';

    /**
     * Longest key the `time_window_types.type_key` column holds (migration 126).
     *
     * Validated here as well as by the column so an over-long key is refused at
     * the boundary with a message, rather than truncated or rejected by the
     * driver halfway through a write.
     */
    public const KEY_MAX_LENGTH = 128;

    /**
     * Registered definitions, keyed by canonical key.
     *
     * @var array<string, WindowTypeDefinition>
     */
    private array $types = [];

    /** Whether core's (currently empty) declaration has been applied. */
    private bool $coreRegistered = false;

    private ?HookManager $hookManager;

    public function __construct(?HookManager $hookManager = null)
    {
        $this->hookManager = $hookManager;
    }

    /**
     * Register a source's window types.
     *
     * The batch is validated as a WHOLE before anything is stored, which is the
     * one place this departs from {@see OuTypeRegistry::register()}. Nesting
     * makes the declarations interdependent: a type may name a sibling as its
     * parent, so storing them one at a time would either reject a forward
     * reference that is perfectly legal or leave half a hierarchy behind whose
     * parents point at nothing. A plugin's typo therefore costs it its window
     * vocabulary rather than one type — the trade the interdependence forces.
     *
     * @param string                              $source       Plugin name supplied by the loader.
     * @param array<string, array<string, mixed>> $declarations Bare slug => declaration.
     * @return list<string> The canonical keys actually registered.
     *
     * @throws InvalidWindowTypeException On the first invalid declaration. Nothing
     *                                   from this batch is stored.
     */
    public function register(string $source, array $declarations): array
    {
        if ($source === self::CORE_SOURCE || $source === self::TENANT_SOURCE) {
            // `tenant` is not merely reserved-by-convention: it is the
            // provenance a row carries when nothing declared it, so a plugin
            // registering under it would make declared and undeclared keys
            // indistinguishable.
            throw InvalidWindowTypeException::forReservedSource($source);
        }

        $prefix = SourceSlug::from($source);
        if ($prefix === null) {
            throw InvalidWindowTypeException::forSource($source);
        }

        return $this->store($source, $prefix, $declarations);
    }

    /**
     * The window types core owns.
     *
     * Empty, and that is the design — see the class docblock. Kept as a method
     * rather than an inlined `[]` so that adding a core type later is a change
     * here and nowhere else.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function coreWindowTypes(): array
    {
        return [];
    }

    /**
     * Apply core's declaration. Idempotent and bootstrap-safe.
     */
    public function registerCoreWindowTypes(): void
    {
        if ($this->coreRegistered) {
            return;
        }

        // Set first so the dispatch hook cannot recurse back into lazy core
        // registration (the same guard PermissionRegistry, ResourceTypeRegistry
        // and OuTypeRegistry use).
        $this->coreRegistered = true;
        $this->store(self::CORE_SOURCE, null, self::coreWindowTypes());
    }

    /**
     * A registered definition, or null when the key is unknown.
     *
     * An unknown key is NOT an error at the API boundary: a tenant-authored key
     * is unknown here by construction. Callers use this to find DEFAULTS, and
     * fall back to their own when there are none.
     */
    public function get(string $key): ?WindowTypeDefinition
    {
        $this->ensureCoreRegistered();

        return $this->types[$key] ?? null;
    }

    /**
     * Whether a canonical key was declared in code by core or a plugin.
     */
    public function has(string $key): bool
    {
        $this->ensureCoreRegistered();

        return array_key_exists($key, $this->types);
    }

    /**
     * Every declared definition, keyed by canonical key, ordered by key.
     *
     * Ordering is presentational only — it is what
     * `GET /api/v1/time-window-types/catalog` renders. A tenant's actual
     * hierarchy lives on its own rows.
     *
     * @return array<string, WindowTypeDefinition>
     */
    public function all(): array
    {
        $this->ensureCoreRegistered();

        $types = $this->types;
        ksort($types);

        return $types;
    }

    /**
     * The definitions declared by one source.
     *
     * @return list<WindowTypeDefinition>
     */
    public function getBySource(string $source): array
    {
        $this->ensureCoreRegistered();

        return array_values(array_filter(
            $this->types,
            static fn (WindowTypeDefinition $d): bool => $d->source() === $source
        ));
    }

    /**
     * The canonical key a given source's bare slug resolves to.
     *
     * Callers that hold a bare slug and a source use this rather than
     * concatenating by hand, so the namespacing rule lives in exactly one place
     * and a change to it cannot silently orphan every reference a plugin wrote.
     */
    public static function canonicalKey(string $source, string $slug): string
    {
        if ($source === self::CORE_SOURCE) {
            return $slug;
        }

        return ResourceTypeRegistry::canonicalKey($source, $slug);
    }

    /**
     * A valid BARE slug: lowercase, starts with a letter, then letters/digits/underscore.
     *
     * Intentionally has no colon — the colon is the namespace separator the host
     * applies, so accepting one here would let a declaration choose its own
     * namespace, which is the whole thing the loader-stamped prefix prevents.
     */
    public static function isValidSlug(string $slug): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*$/', $slug) === 1
            && strlen($slug) <= self::KEY_MAX_LENGTH;
    }

    /**
     * A valid CANONICAL key: a bare slug, or `prefix:slug` for a plugin type.
     *
     * This is the shape the `?type=` filter and every stored
     * `time_window_types.type_key` must match. A value that fails it is
     * malformed input (422), never a silently-ignored filter.
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(?::[a-z][a-z0-9_]*)?$/', $key) === 1
            && strlen($key) <= self::KEY_MAX_LENGTH;
    }

    /**
     * Whether a key is one a TENANT may author for itself.
     *
     * Bare, well-formed, and not the reserved sentinel. Prefixed keys are
     * refused because the prefix is an attribution: a tenant writing
     * `acme:growing_season` by hand would be claiming the Acme plugin said so,
     * and the adoption path (which resolves the key through this registry) is
     * how a plugin's type legitimately reaches a tenant.
     */
    public static function isTenantAuthorable(string $key): bool
    {
        return self::isValidSlug($key) && $key !== self::UNTYPED;
    }

    /**
     * Validate a whole batch, then store it.
     *
     * @param string                              $source       Raw source name, kept for attribution.
     * @param string|null                         $prefix       Namespace prefix, or null for core (bare keys).
     * @param array<string, array<string, mixed>> $declarations Bare slug => declaration.
     * @return list<string>
     *
     * @throws InvalidWindowTypeException
     */
    private function store(string $source, ?string $prefix, array $declarations): array
    {
        /** @var array<string, WindowTypeDefinition> $pending */
        $pending = [];
        /** @var array<string, string|null> $parents Bare slug => bare parent slug. */
        $parents = [];

        foreach ($declarations as $slug => $declaration) {
            $slug = (string) $slug;
            if (!self::isValidSlug($slug)) {
                throw InvalidWindowTypeException::forSlug($slug);
            }

            $key = $prefix === null ? $slug : $prefix . self::NAMESPACE_SEPARATOR . $slug;
            if (strlen($key) > self::KEY_MAX_LENGTH) {
                throw InvalidWindowTypeException::forSlug($key);
            }
            if (array_key_exists($key, $this->types) || array_key_exists($key, $pending)) {
                throw InvalidWindowTypeException::forDuplicateKey($key);
            }
            if (!is_array($declaration)) {
                throw InvalidWindowTypeException::forMalformedDeclaration($key);
            }

            $parentSlug = self::parentSlug($key, $declaration['parent'] ?? null);
            $parents[$slug] = $parentSlug;

            $pending[$key] = new WindowTypeDefinition(
                $key,
                $source,
                $slug,
                self::label($key, $declaration['label'] ?? null, $slug),
                $parentSlug === null
                    ? null
                    : ($prefix === null ? $parentSlug : $prefix . self::NAMESPACE_SEPARATOR . $parentSlug),
            );
        }

        // Both structural checks run over the WHOLE batch, which is why nothing
        // was stored above. A parent declared later in the array is legal.
        foreach ($parents as $slug => $parentSlug) {
            $key = $prefix === null ? $slug : $prefix . self::NAMESPACE_SEPARATOR . $slug;
            if ($parentSlug !== null && !array_key_exists($parentSlug, $parents)) {
                throw InvalidWindowTypeException::forUnknownParent($key, $parentSlug);
            }
        }
        foreach (array_keys($parents) as $slug) {
            self::assertNoCycle($slug, $parents, $prefix);
        }

        foreach ($pending as $key => $definition) {
            $this->types[$key] = $definition;
            $this->hookManager?->dispatch('window_type.registered', [
                'source' => $source,
                'window_type' => $key,
            ]);
        }

        return array_keys($pending);
    }

    /**
     * Walk a slug's declared ancestry and refuse a loop.
     *
     * Bounded by the number of declarations rather than a magic depth: the walk
     * can only revisit a slug if a loop exists, so `count($parents)` steps is
     * both sufficient and the smallest sufficient bound.
     *
     * @param array<string, string|null> $parents
     *
     * @throws InvalidWindowTypeException
     */
    private static function assertNoCycle(string $slug, array $parents, ?string $prefix): void
    {
        $seen = [];
        $cursor = $slug;
        $steps = count($parents) + 1;

        while ($steps-- > 0) {
            if (isset($seen[$cursor])) {
                $key = $prefix === null ? $slug : $prefix . self::NAMESPACE_SEPARATOR . $slug;
                throw InvalidWindowTypeException::forNestingCycle($key);
            }
            $seen[$cursor] = true;

            $next = $parents[$cursor] ?? null;
            if ($next === null) {
                return;
            }
            $cursor = $next;
        }

        $key = $prefix === null ? $slug : $prefix . self::NAMESPACE_SEPARATOR . $slug;
        throw InvalidWindowTypeException::forNestingCycle($key);
    }

    /**
     * Normalise the declared default label, falling back to the bare slug so a
     * type always has something to render.
     *
     * @throws InvalidWindowTypeException
     */
    private static function label(string $key, mixed $raw, string $slug): string
    {
        if ($raw === null) {
            return $slug;
        }
        if (!is_string($raw) || trim($raw) === '') {
            throw InvalidWindowTypeException::forField($key, 'label', 'must be a non-empty string when present');
        }

        return trim($raw);
    }

    /**
     * Normalise the declared parent slug.
     *
     * A BARE slug is required and a namespaced one refused: the prefix is the
     * host's to apply, and a declaration spelling its own prefix would be
     * choosing its namespace — the thing the loader-stamped prefix prevents.
     *
     * @throws InvalidWindowTypeException
     */
    private static function parentSlug(string $key, mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        if (!is_string($raw) || !self::isValidSlug($raw)) {
            throw InvalidWindowTypeException::forField(
                $key,
                'parent',
                'must be a bare slug from this same declaration when present'
            );
        }

        return $raw;
    }

    /**
     * Lazily apply core's declaration on first read, so a reader never sees a
     * catalogue missing a core type merely because bootstrap order changed.
     */
    private function ensureCoreRegistered(): void
    {
        if (!$this->coreRegistered) {
            $this->registerCoreWindowTypes();
        }
    }
}
