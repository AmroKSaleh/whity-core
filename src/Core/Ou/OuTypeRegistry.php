<?php

declare(strict_types=1);

namespace Whity\Core\Ou;

use Whity\Core\Container\HostWiredService;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Support\SourceSlug;

/**
 * The catalogue of declarable ORGANIZATIONAL-UNIT TYPES (#822).
 *
 * The problem this exists to solve
 * -------------------------------
 * `organizational_units` is `(id, tenant_id, parent_id, name, slug, description)`
 * — nothing says what KIND of thing a unit is. Depth is not a usable substitute:
 * an installation with one top-level unit has its faculties at depth 0 and one
 * with two campuses has the equivalent units at depth 1, so a rule phrased as
 * "every unit of level X" returns different kinds of thing per installation. It
 * is not stable WITHIN an installation either — it changes the first time
 * somebody inserts a parent above an existing unit, and nothing tells the
 * consumer it happened. Consumers therefore keep a parallel unit-id → kind map
 * in their own schema, which drifts silently the moment a unit is reparented.
 *
 * Two halves, and both are needed
 * -------------------------------
 * A type has a KEY and a LABEL, and they live in different places on purpose.
 *
 *  - The KEY is what code binds to — a routing rule saying "every unit of kind
 *    `faculty` under this parent" must mean the same thing on every install, so
 *    the key is governed here, exactly as {@see ResourceTypeRegistry} governs
 *    resource types.
 *  - The LABEL is tenant data. One institution's *faculty* is another's *school*
 *    or *college*, and a non-academic tenant has *region → branch → team*
 *    entirely. A core enum cannot express that, which is why the VOCABULARY
 *    itself lives per tenant in `ou_types` (migration 102) rather than here.
 *
 * So this registry is not the whole vocabulary. It governs which keys a PLUGIN
 * may contribute, and supplies the defaults a tenant starts from when it adopts
 * one. A tenant may equally author its own key that no code declared — that is
 * the normal case, and it is why `ou_types` is a table and not an enum.
 *
 * The namespace rule, and what it guarantees
 * ------------------------------------------
 * Plugin keys are PREFIXED from the source the loader supplies
 * (`$plugin->getName()`), never from anything the plugin returns:
 * `acme:clinic`. Two consequences, both intended and both identical to
 * {@see ResourceTypeRegistry::register()}:
 *
 *  - two plugins may each declare `clinic` and get DIFFERENT canonical keys, so
 *    neither can adopt into (or shadow) the other's rows;
 *  - a plugin can never produce a BARE key. The unprefixed namespace belongs to
 *    core and to the tenant's own vocabulary, so an install-wide plugin can
 *    never squat on a name a tenant might want, and `faculty` always means the
 *    tenant's faculty.
 *
 * `core` is RESERVED as a source for exactly that reason: were a plugin able to
 * register under it, it could mint bare keys and the guarantee above would
 * evaporate. And, as with resource types, namespacing does NOT rewrite data
 * already written — an existing bare key in a tenant's `ou_types` keeps meaning
 * what it meant.
 *
 * Why core declares NO types today
 * --------------------------------
 * {@see coreOuTypes()} is deliberately empty. Core shipping `faculty` and
 * `department` would be precisely the core enum this issue rejects: a
 * white-label multi-tenant platform cannot know whether a given tenant's second
 * level is a faculty, a region or a division. The bare namespace is reserved
 * anyway, so that if core ever DOES own a structural type no plugin can shadow
 * it, and so the reservation does not have to be retrofitted later against keys
 * plugins have already written.
 *
 * Deliberately an INSTANCE service
 * --------------------------------
 * Not a static catalogue. Process-level statics are per FrankenPHP worker, so a
 * registration performed while serving one request is invisible to the other
 * workers — the hazard that produced the stale-permission bug in PR #701. An
 * instance resolved from the container is rebuilt per request from the same
 * plugin bootstrap every worker runs, so every worker agrees.
 */
class OuTypeRegistry implements HostWiredService
{
    /** Source name for OU types shipped by core. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Provenance marker for a key a TENANT authored through the API.
     *
     * Stored in `ou_types.source`, never accepted as a registration source: a
     * tenant-authored key is by definition not declared in code, so nothing may
     * register under it. It exists so an operator can tell "we made this up"
     * from "this came with the clinic plugin" when deciding what is safe to
     * rename or remove.
     */
    public const TENANT_SOURCE = 'tenant';

    /**
     * Separates a plugin's namespace from its slug: `acme:clinic`.
     *
     * The same separator {@see ResourceTypeRegistry} uses, referenced rather
     * than repeated so the two catalogues cannot drift into spelling the same
     * plugin's keys two different ways.
     */
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;

    /**
     * The query sentinel meaning "units with no type at all".
     *
     * `GET /api/ous?type=none` has to be able to ask for the untyped units, and
     * an empty `?type=` already means "no filter" (matching `?parent_id=`). So
     * one bare key is reserved as the sentinel and refused as a tenant-authored
     * key — the same trick `?parent_id=0` uses, where 0 is free to mean "the
     * roots" because no OU can have id 0. A PLUGIN may still declare `none`; it
     * becomes `acme:none`, which is a different key and unambiguous.
     */
    public const UNTYPED = 'none';

    /**
     * Longest key the `ou_types.type_key` column holds (migration 102).
     *
     * Validated here as well as by the column so an over-long key is refused at
     * the boundary with a message, rather than truncated or rejected by the
     * driver halfway through a write.
     */
    public const KEY_MAX_LENGTH = 128;

    /**
     * Registered definitions, keyed by canonical key.
     *
     * @var array<string, OuTypeDefinition>
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
     * Register a source's OU types.
     *
     * Each type is validated and stored INDEPENDENTLY, matching
     * {@see \Whity\Core\DataType\DataTypeRegistry::register()}: one malformed
     * declaration is rejected on its own and does not discard the source's other
     * types, so a plugin author's typo costs them one type rather than all of
     * them. A type is never partially stored.
     *
     * @param string                              $source       Plugin name supplied by the loader.
     * @param array<string, array<string, mixed>> $declarations Bare slug => declaration.
     * @return list<string> The canonical keys actually registered.
     *
     * @throws InvalidOuTypeException On the FIRST invalid declaration, so the
     *                                loader can log it against the plugin. Types
     *                                validated before it are already stored.
     */
    public function register(string $source, array $declarations): array
    {
        if ($source === self::CORE_SOURCE) {
            throw InvalidOuTypeException::forReservedSource($source);
        }
        if ($source === self::TENANT_SOURCE) {
            // Not merely reserved-by-convention: `tenant` is the provenance a row
            // carries when nothing declared it, so a plugin registering under it
            // would make declared and undeclared keys indistinguishable.
            throw InvalidOuTypeException::forReservedSource($source);
        }

        $prefix = SourceSlug::from($source);
        if ($prefix === null) {
            throw InvalidOuTypeException::forSource($source);
        }

        return $this->store($source, $prefix, $declarations);
    }

    /**
     * The OU types core owns.
     *
     * Empty, and that is the design — see the class docblock. Kept as a method
     * rather than an inlined `[]` so that adding a core type later is a change
     * here and nowhere else.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function coreOuTypes(): array
    {
        return [];
    }

    /**
     * Apply core's declaration. Idempotent and bootstrap-safe.
     */
    public function registerCoreOuTypes(): void
    {
        if ($this->coreRegistered) {
            return;
        }

        // Set first so the dispatch hook cannot recurse back into lazy core
        // registration (the same guard PermissionRegistry and
        // ResourceTypeRegistry use).
        $this->coreRegistered = true;
        $this->store(self::CORE_SOURCE, null, self::coreOuTypes());
    }

    /**
     * A registered definition, or null when the key is unknown.
     *
     * An unknown key is NOT an error at the API boundary: a tenant-authored key
     * is unknown here by construction. Callers use this to find DEFAULTS, and
     * fall back to their own when there are none.
     */
    public function get(string $key): ?OuTypeDefinition
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
     * Every declared definition, keyed by canonical key, ordered by rank then key.
     *
     * Ordering here is presentational only — it is what `GET /api/ou-types/catalog`
     * renders. A tenant's actual ordering lives on its own rows.
     *
     * @return array<string, OuTypeDefinition>
     */
    public function all(): array
    {
        $this->ensureCoreRegistered();

        $types = $this->types;
        uasort($types, static function (OuTypeDefinition $a, OuTypeDefinition $b): int {
            // An unranked declaration sorts after every ranked one rather than
            // being treated as rank 0, matching how an adopting tenant appends it.
            $rankA = $a->sortOrder() ?? PHP_INT_MAX;
            $rankB = $b->sortOrder() ?? PHP_INT_MAX;

            return $rankA === $rankB ? strcmp($a->key(), $b->key()) : $rankA <=> $rankB;
        });

        return $types;
    }

    /**
     * The definitions declared by one source.
     *
     * @return list<OuTypeDefinition>
     */
    public function getBySource(string $source): array
    {
        $this->ensureCoreRegistered();

        return array_values(array_filter(
            $this->types,
            static fn (OuTypeDefinition $d): bool => $d->source() === $source
        ));
    }

    /**
     * The canonical key a given source's bare slug resolves to.
     *
     * Callers that hold a bare slug and a source use this rather than
     * concatenating by hand, so the namespacing rule lives in exactly one place
     * and a change to it cannot silently orphan every reference a plugin wrote.
     * Delegates to {@see ResourceTypeRegistry::canonicalKey()} for the same
     * reason the separator is borrowed: one plugin, one namespace, spelled the
     * same way by every catalogue in the platform.
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
     * This is the shape the `?type=` filter and every stored `ou_types.type_key`
     * must match. A value that fails it is malformed input (422), never a
     * silently-ignored filter.
     */
    public static function isValidKey(string $key): bool
    {
        return preg_match('/^[a-z][a-z0-9_]*(?::[a-z][a-z0-9_]*)?$/', $key) === 1
            && strlen($key) <= self::KEY_MAX_LENGTH;
    }

    /**
     * Whether a key is one a TENANT may author for itself.
     *
     * Bare, well-formed, and not the reserved untyped sentinel. Prefixed keys
     * are refused because the prefix is an attribution: a tenant writing
     * `acme:clinic` by hand would be claiming the Acme plugin said so, and the
     * adoption path (which resolves the key through this registry) is how a
     * plugin's type legitimately reaches a tenant.
     */
    public static function isTenantAuthorable(string $key): bool
    {
        return self::isValidSlug($key) && $key !== self::UNTYPED;
    }

    /**
     * Validate and store a batch under an already-resolved prefix.
     *
     * @param string                              $source       Raw source name, kept for attribution.
     * @param string|null                         $prefix       Namespace prefix, or null for core (bare keys).
     * @param array<string, array<string, mixed>> $declarations Bare slug => declaration.
     * @return list<string>
     *
     * @throws InvalidOuTypeException
     */
    private function store(string $source, ?string $prefix, array $declarations): array
    {
        $registered = [];

        foreach ($declarations as $slug => $declaration) {
            $slug = (string) $slug;
            if (!self::isValidSlug($slug)) {
                throw InvalidOuTypeException::forSlug($slug);
            }

            $key = $prefix === null ? $slug : $prefix . self::NAMESPACE_SEPARATOR . $slug;
            if (array_key_exists($key, $this->types)) {
                throw InvalidOuTypeException::forDuplicateKey($key);
            }
            if (!is_array($declaration)) {
                throw InvalidOuTypeException::forMalformedDeclaration($key);
            }

            $this->types[$key] = new OuTypeDefinition(
                $key,
                $source,
                $slug,
                self::label($key, $declaration['label'] ?? null, $slug),
                self::sortOrder($key, $declaration['sort_order'] ?? null),
            );
            $registered[] = $key;

            $this->hookManager?->dispatch('ou_type.registered', [
                'source' => $source,
                'ou_type' => $key,
            ]);
        }

        return $registered;
    }

    /**
     * Normalise the declared default label, falling back to the bare slug so a
     * type always has something to render.
     *
     * @throws InvalidOuTypeException
     */
    private static function label(string $key, mixed $raw, string $slug): string
    {
        if ($raw === null) {
            return $slug;
        }
        if (!is_string($raw) || trim($raw) === '') {
            throw InvalidOuTypeException::forField($key, 'label', 'must be a non-empty string when present');
        }

        return trim($raw);
    }

    /**
     * Normalise the declared default rank.
     *
     * @throws InvalidOuTypeException
     */
    private static function sortOrder(string $key, mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        throw InvalidOuTypeException::forField($key, 'sort_order', 'must be an integer when present');
    }

    /**
     * Lazily apply core's declaration on first read, so a reader never sees a
     * catalogue missing a core type merely because bootstrap order changed.
     */
    private function ensureCoreRegistered(): void
    {
        if (!$this->coreRegistered) {
            $this->registerCoreOuTypes();
        }
    }
}
