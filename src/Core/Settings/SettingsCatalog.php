<?php

declare(strict_types=1);

namespace Whity\Core\Settings;

use Whity\Core\Container\HostWiredService;

/**
 * The UNION of core's static setting catalogue and the plugin-declared one
 * (#713 item 1).
 *
 * The seam, and why it is shaped this way
 * ---------------------------------------
 * Opening settings to plugins meant reconciling two things that cannot both
 * move:
 *
 *  1. The catalogue must not become mutable static state. Statics are per
 *     FrankenPHP worker, so a registration made while serving one request is
 *     invisible to the other seven (PR #701, and again #727). In a settings
 *     layer that does not throw — a key missing from one worker's catalogue
 *     reads as "unknown setting", so the same request 422s or succeeds depending
 *     on which worker answered it.
 *  2. {@see SettingsRegistry}'s roughly 330 static call sites, across three
 *     dozen files, must keep behaving exactly as they do today.
 *
 * Both hold if the two halves stay separate and are unioned in one place:
 *
 *  - CORE's contribution stays the `private const` catalogue in
 *    {@see SettingsRegistry}. It is a compile-time constant, identical in every
 *    worker — not the mutable state (1) is about — so leaving it static is not a
 *    compromise, it is the correct home for it. Every existing static call site
 *    keeps resolving core-only and is not touched.
 *  - PLUGIN contributions live in {@see PluginSettingsRegistry}, an instance
 *    service rebuilt per boot from the plugins actually loaded.
 *  - THIS class is the only thing that sees both, and it is what new consumers
 *    resolve.
 *
 * So the answer to "do existing callers see plugin keys?" is deliberately NO.
 * `SettingsRegistry::isKnown('acme:mode')` is false and stays false. That is not
 * an oversight to tidy up later: those call sites are core code asking about core
 * keys — `MailerFactory` reading `mail.transport`, `StorageDriverFactory` reading
 * `storage.driver` — and widening what they can see could only ever let a plugin
 * key answer a question core asked about its own configuration. The union is
 * additive and opt-in, which is why this change reaches 330 call sites and
 * modifies none of them.
 *
 * The consumers that DO need the union are the ones that treat keys as data
 * rather than by name: {@see SettingsService} (resolution and writes) and
 * {@see \Whity\Api\SettingsApiHandler} (the admin surface). Both take it as an
 * OPTIONAL collaborator, so a host — or a test — that wires nothing behaves
 * exactly as before.
 *
 * Discovery versus surface
 * ------------------------
 * Two different questions, deliberately answered by two different method
 * families:
 *
 *  - {@see keys()} / {@see describe()} — the FULL catalogue. What exists, what
 *    type it is, what it defaults to. This is the discovery surface a plugin
 *    introspects.
 *  - {@see textKeys()} / {@see describeText()} / {@see tenantTextKeys()} /
 *    {@see describeTenantText()} — the ADMIN SURFACE. Core's text keys, plus only
 *    those plugin keys that opted in with `admin => true`. See
 *    {@see SettingDefinition::isAdminVisible()} for why that is opt-in.
 *
 * Stateless beyond its injected registry: safe for a FrankenPHP worker.
 */
final class SettingsCatalog implements HostWiredService
{
    /** The source name reported for keys core itself declares. */
    public const CORE_SOURCE = 'core';

    /**
     * @param PluginSettingsRegistry|null $plugins The loader-filled catalogue of
     *        plugin contributions, or null for a core-only view.
     */
    public function __construct(private ?PluginSettingsRegistry $plugins = null)
    {
    }

    /**
     * A core-only view.
     *
     * The explicit, named way to say "this host has no plugin contributions" —
     * used as the default inside {@see SettingsService} so an unwired caller
     * behaves exactly as it did before this class existed. Named rather than
     * improvised, because {@see HostWiredService} exists precisely to stop the
     * container from guessing an empty registry into being.
     */
    public static function coreOnly(): self
    {
        return new self();
    }

    /**
     * Whether the key is known to EITHER half of the catalogue.
     *
     * The gate every settings write passes through.
     */
    public function isKnown(string $key): bool
    {
        return SettingsRegistry::isKnown($key) || $this->plugins?->has($key) === true;
    }

    /** Whether the key belongs to core's own catalogue. */
    public function isCoreKey(string $key): bool
    {
        return SettingsRegistry::isKnown($key);
    }

    /**
     * Which plugin declared the key, or {@see CORE_SOURCE} for a core key.
     *
     * Null when the key is unknown to both halves.
     */
    public function sourceOf(string $key): ?string
    {
        if (SettingsRegistry::isKnown($key)) {
            return self::CORE_SOURCE;
        }

        return $this->plugins?->get($key)?->source();
    }

    /**
     * Every known key: core's, in declared order, then plugin contributions in
     * registration order.
     *
     * Core first is not cosmetic — it is the order {@see SettingsService}
     * resolves in and the order the admin screen renders, and an operator looks
     * for `site_name` before anything a plugin added.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return [...SettingsRegistry::keys(), ...($this->plugins?->keys() ?? [])];
    }

    /**
     * The text-kind keys: core's text keys, plus every ADMIN-VISIBLE plugin key.
     *
     * Drives the global settings surface. Plugin keys that did not opt in are
     * absent by design — they are still stored, resolved and validated, just not
     * published on a screen gated by core's `settings:*` permissions.
     *
     * @return list<string>
     */
    public function textKeys(): array
    {
        return [...SettingsRegistry::textKeys(), ...$this->adminVisiblePluginKeys()];
    }

    /**
     * The text keys a TENANT may override: core's tenant-overridable text keys,
     * plus every admin-visible plugin key that is not declared `global_only`.
     *
     * @return list<string>
     */
    public function tenantTextKeys(): array
    {
        return [
            ...SettingsRegistry::tenantTextKeys(),
            ...array_values(array_filter(
                $this->adminVisiblePluginKeys(),
                fn (string $key): bool => !$this->isGlobalOnly($key)
            )),
        ];
    }

    /**
     * The hardcoded fallback default for a known key.
     *
     * @throws \InvalidArgumentException When the key is unknown to both halves.
     */
    public function defaultFor(string $key): string
    {
        if (SettingsRegistry::isKnown($key)) {
            return SettingsRegistry::defaultFor($key);
        }

        $definition = $this->plugins?->get($key);
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown setting key: {$key}");
        }

        return $definition->defaultValue();
    }

    /**
     * The full map of defaults (key => default value), core first.
     *
     * @return array<string, string>
     */
    public function defaults(): array
    {
        $defaults = SettingsRegistry::defaults();
        foreach ($this->plugins?->all() ?? [] as $key => $definition) {
            $defaults[$key] = $definition->defaultValue();
        }

        return $defaults;
    }

    /**
     * The kind of a known key: 'asset' for core's branding binary keys, 'text'
     * for everything else.
     *
     * A plugin key is always 'text'. Asset keys carry a storage reference written
     * by the branding upload endpoints, which are core-only surfaces.
     *
     * @throws \InvalidArgumentException When the key is unknown to both halves.
     */
    public function kindFor(string $key): string
    {
        if (SettingsRegistry::isKnown($key)) {
            return SettingsRegistry::kindFor($key);
        }
        if ($this->plugins?->has($key) !== true) {
            throw new \InvalidArgumentException("Unknown setting key: {$key}");
        }

        return 'text';
    }

    /**
     * The value-type of a known key, in the same vocabulary core publishes.
     *
     * @throws \InvalidArgumentException When the key is unknown to both halves.
     */
    public function typeFor(string $key): string
    {
        if (SettingsRegistry::isKnown($key)) {
            return SettingsRegistry::typeFor($key);
        }

        $definition = $this->plugins?->get($key);
        if ($definition === null) {
            throw new \InvalidArgumentException("Unknown setting key: {$key}");
        }

        return $definition->type();
    }

    /**
     * The allowed values for an enum key, or null when the key is not an enum.
     *
     * @return list<string>|null
     */
    public function optionsFor(string $key): ?array
    {
        if (SettingsRegistry::isKnown($key)) {
            return SettingsRegistry::optionsFor($key);
        }

        return $this->plugins?->get($key)?->options();
    }

    /**
     * Whether the key is GLOBAL-ONLY: settable on the global settings surface
     * only, never as a per-tenant override.
     */
    public function isGlobalOnly(string $key): bool
    {
        if (SettingsRegistry::isKnown($key)) {
            return SettingsRegistry::isGlobalOnly($key);
        }

        return $this->plugins?->get($key)?->isGlobalOnly() === true;
    }

    /**
     * Whether the key is one of core's curated FEATURE FLAGS.
     *
     * Always false for a plugin key. The Feature Flags tab is a curated set of
     * core capability toggles, chosen one at a time for what an operator would
     * recognise as a feature — not "every boolean". A plugin cannot nominate
     * itself into a curated list; that is what curation means.
     */
    public function isFeatureFlag(string $key): bool
    {
        return SettingsRegistry::isFeatureFlag($key);
    }

    /**
     * Whether core's own settings screens publish this key.
     *
     * True for every core text key (they ARE those screens) and, for a plugin
     * key, only when the declaration opted in with `admin => true`.
     */
    public function isAdminVisible(string $key): bool
    {
        if (SettingsRegistry::isKnown($key)) {
            return true;
        }

        return $this->plugins?->get($key)?->isAdminVisible() === true;
    }

    /**
     * Validate a value for a known key.
     *
     * Returns null when valid, or a human-readable reason. An unknown key is
     * itself a validation failure — identical to
     * {@see SettingsRegistry::validate()}, including the message, so a caller
     * cannot tell from the response whether it addressed a core key or a
     * plugin one.
     */
    public function validate(string $key, string $value): ?string
    {
        if (SettingsRegistry::isKnown($key)) {
            return SettingsRegistry::validate($key, $value);
        }

        $definition = $this->plugins?->get($key);
        if ($definition === null) {
            return "Unknown setting key: {$key}";
        }

        return $definition->validate($value);
    }

    /**
     * Normalise a value into its canonical stored form.
     *
     * Callers MUST {@see validate()} first; an unknown key is returned unchanged,
     * matching {@see SettingsRegistry::normalize()}.
     */
    public function normalize(string $key, string $value): string
    {
        if (SettingsRegistry::isKnown($key)) {
            return SettingsRegistry::normalize($key, $value);
        }

        $definition = $this->plugins?->get($key);

        return $definition === null ? $value : $definition->normalize($value);
    }

    /**
     * Descriptors for the FULL catalogue — core's, then every plugin key
     * whether admin-visible or not. The discovery surface.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(): array
    {
        return [
            ...SettingsRegistry::describe(),
            ...array_map(
                static fn (SettingDefinition $d): array => $d->describe(),
                array_values($this->plugins?->all() ?? [])
            ),
        ];
    }

    /**
     * Descriptors for the global ADMIN SURFACE: core's text keys plus the
     * admin-visible plugin keys.
     *
     * @return list<array<string, mixed>>
     */
    public function describeText(): array
    {
        return [...SettingsRegistry::describeText(), ...$this->describePluginKeys($this->adminVisiblePluginKeys())];
    }

    /**
     * Descriptors for the PER-TENANT admin surface.
     *
     * @return list<array<string, mixed>>
     */
    public function describeTenantText(): array
    {
        $pluginKeys = array_values(array_filter(
            $this->adminVisiblePluginKeys(),
            fn (string $key): bool => !$this->isGlobalOnly($key)
        ));

        return [...SettingsRegistry::describeTenantText(), ...$this->describePluginKeys($pluginKeys)];
    }

    /**
     * The plugin keys that opted onto core's settings screens, in registration
     * order.
     *
     * @return list<string>
     */
    private function adminVisiblePluginKeys(): array
    {
        $keys = [];
        foreach ($this->plugins?->all() ?? [] as $key => $definition) {
            if ($definition->isAdminVisible()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * @param list<string> $keys
     * @return list<array<string, mixed>>
     */
    private function describePluginKeys(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $definition = $this->plugins?->get($key);
            if ($definition !== null) {
                $out[] = $definition->describe();
            }
        }

        return $out;
    }
}
