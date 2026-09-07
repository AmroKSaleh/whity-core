<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

use Whity\Core\Container\HostWiredService;
use Whity\Core\RBAC\ResourceTypeRegistry;

/**
 * The catalogue of things a routing stage can DO (#1032).
 *
 * The sibling migration 112 specified: "a sibling registry beside
 * RoutingRuleRegistry", built the same way and namespaced the same way, so that
 * a kind of effect is contributed exactly as a kind of rule is.
 *
 * WHAT IT IS NOT
 * --------------
 * Not a table. The catalogue is CODE — populated at boot by core, and later by
 * plugins — which is why `document_route_step_effects.effect_kind` carries no
 * foreign key. A kinds table would have to be kept in step with this registry,
 * which is already the source of truth, and the day they disagreed the
 * authoring screen and the runner would disagree with it differently.
 *
 * {@see HostWiredService}, and the marker matters more here than usual. An
 * improvised, empty registry answers "no such kind" for every effect a route
 * declares — so every stage would author fine, run, and record every effect as
 * skipped-because-unregistered. That reads exactly like an instance where
 * nobody has configured any effects, which is an ordinary state. Failing closed
 * at resolution turns a silent, plausible nothing into an error naming its
 * cause.
 *
 * THE PLUGIN-FACING HALF IS DELIBERATELY ABSENT, for the reason
 * {@see RouteEffectInterface} gives: {@see RouteEffectPlan} describes one shape
 * of outcome today and will gain a field when the first genuinely different
 * kind arrives. This class is shaped for the promotion — {@see register()} is
 * already written and already namespaces — so adding it is a move rather than a
 * redesign.
 */
final class RouteEffectRegistry implements HostWiredService
{
    /** Source recorded for effects core itself ships. Reserved. */
    public const CORE_SOURCE = 'core';

    /**
     * Separates a plugin's namespace from its slug: `acme:post_to_ledger`.
     *
     * Borrowed from {@see ResourceTypeRegistry} rather than restated, so the
     * catalogues cannot drift into two spellings of one plugin's keys.
     */
    public const NAMESPACE_SEPARATOR = ResourceTypeRegistry::NAMESPACE_SEPARATOR;

    /**
     * Core's one kind: tell somebody that a stage was reached.
     *
     * One rather than several on purpose. #1032's scope note is explicit —
     * "the work is the declaration, the registry, the runner, and an honest
     * record of each attempt, not a mail client" — and a second core kind
     * invented before anybody asked for it would be a second thing to keep
     * correct with no caller.
     */
    public const KIND_NOTIFY = 'notify';

    /** Matches `document_route_step_effects.effect_kind VARCHAR(128)`. */
    public const KEY_MAX_LENGTH = 128;

    /** @var array<string, RouteEffectInterface> */
    private array $effects = [];

    /** @var array<string, string> */
    private array $sourceByKind = [];

    private bool $coreRegistered = false;

    /**
     * Register the kinds core ships.
     *
     * Idempotent, and the flag is set BEFORE the store so a listener that
     * somehow re-entered could not recurse — the same guard
     * {@see RoutingRuleRegistry::registerCoreRoutingRules()} carries.
     */
    public function registerCoreEffects(NotifyEffect $notify): void
    {
        if ($this->coreRegistered) {
            return;
        }
        $this->coreRegistered = true;

        $this->store(null, [self::KIND_NOTIFY => $notify]);
    }

    /**
     * Register a plugin's kinds under its own namespace.
     *
     * Unused until the plugin-facing half lands, and written now because the
     * namespacing is the part that must not be improvised later: the prefix
     * comes from the loader-supplied `$source`, never from anything the plugin
     * returns, which is what stops a plugin claiming a bare key.
     *
     * @param array<string, RouteEffectInterface> $declared Slug => effect.
     * @return list<string> The canonical kinds registered.
     *
     * @throws InvalidRouteEffectException
     */
    public function register(string $source, array $declared): array
    {
        if ($source === self::CORE_SOURCE) {
            throw InvalidRouteEffectException::forReservedSource($source);
        }
        if (!self::isValidSlug($source)) {
            throw InvalidRouteEffectException::forSlug($source);
        }

        return $this->store($source, $declared);
    }

    /**
     * The effect registered for a kind, or null.
     *
     * Null is what lets the runner record a `skipped` attempt naming the
     * unregistered kind, instead of throwing inside a fail-soft handler where
     * the reason would reach a log and nothing else.
     */
    public function get(string $kind): ?RouteEffectInterface
    {
        return $this->effects[$kind] ?? null;
    }

    public function has(string $kind): bool
    {
        return array_key_exists($kind, $this->effects);
    }

    /**
     * What an authoring screen may offer.
     *
     * @return list<array{kind: string, source: string}>
     */
    public function catalogue(): array
    {
        $kinds = array_keys($this->effects);
        sort($kinds);

        return array_map(
            fn (string $kind): array => [
                'kind' => $kind,
                'source' => $this->sourceByKind[$kind] ?? self::CORE_SOURCE,
            ],
            $kinds
        );
    }

    /** Which source registered a kind, for an operator deciding what is safe to uninstall. */
    public function sourceOf(string $kind): ?string
    {
        return $this->sourceByKind[$kind] ?? null;
    }

    public static function canonicalKey(?string $source, string $slug): string
    {
        return $source === null ? $slug : $source . self::NAMESPACE_SEPARATOR . $slug;
    }

    public static function isValidSlug(string $slug): bool
    {
        return $slug !== ''
            && strlen($slug) <= self::KEY_MAX_LENGTH
            && preg_match('/^[a-z][a-z0-9_]*$/', $slug) === 1;
    }

    public static function isValidKind(string $kind): bool
    {
        return $kind !== ''
            && strlen($kind) <= self::KEY_MAX_LENGTH
            && preg_match('/^[a-z][a-z0-9_]*(?::[a-z][a-z0-9_]*)?$/', $kind) === 1;
    }

    /**
     * @param array<string, RouteEffectInterface> $declared
     * @return list<string>
     *
     * @throws InvalidRouteEffectException
     */
    private function store(?string $prefix, array $declared): array
    {
        $registered = [];

        foreach ($declared as $slug => $effect) {
            $slug = (string) $slug;
            if (!self::isValidSlug($slug)) {
                throw InvalidRouteEffectException::forSlug($slug);
            }

            $kind = self::canonicalKey($prefix, $slug);
            if (strlen($kind) > self::KEY_MAX_LENGTH) {
                throw InvalidRouteEffectException::forOverlongKey($kind, self::KEY_MAX_LENGTH);
            }
            if (!$effect instanceof RouteEffectInterface) {
                throw InvalidRouteEffectException::forMalformedEffect($kind);
            }
            if (array_key_exists($kind, $this->effects)) {
                throw InvalidRouteEffectException::forDuplicateKey($kind);
            }

            $this->effects[$kind] = $effect;
            $this->sourceByKind[$kind] = $prefix ?? self::CORE_SOURCE;
            $registered[] = $kind;
        }

        return $registered;
    }
}
