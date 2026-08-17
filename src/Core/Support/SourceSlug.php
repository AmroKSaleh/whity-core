<?php

declare(strict_types=1);

namespace Whity\Core\Support;

use Whity\Sdk\PluginNamespace;

/**
 * Normalises a REGISTRATION SOURCE (a plugin name) into a slug.
 *
 * Several registries key declarations by the source the plugin loader supplies
 * from `$plugin->getName()` — the resource-type catalogue namespaces types by
 * it, the table-ownership registry compares ownership by it. Plugin names are
 * PHP-ish (`DemoCatalog`, `Acme\Widgets\Plugin`), so each of them needs the
 * same reduction to a comparable slug.
 *
 * It lives in one place deliberately. Two registries deriving "who is this?"
 * with two slightly different rules is how `acme` and `acme_widgets` end up
 * naming the same plugin in different tables — and ownership comparisons that
 * are almost right are a security defect, not a cosmetic one.
 *
 * That one place is now {@see \Whity\Sdk\PluginNamespace::slug()}, in the SDK.
 * The rule stayed host-internal for as long as only the host had to spell a
 * namespaced name; audited events (SDK 1.29) made a PLUGIN produce one, since
 * the host binds its audit listener to the namespaced event name and the plugin
 * has to dispatch that exact string. Publishing the rule and deferring to it
 * here is the only arrangement in which the name a plugin dispatches and the
 * name the host listens for cannot drift; this class keeps its name and its
 * callers, so every ownership comparison in core still asks exactly one
 * implementation.
 */
final class SourceSlug
{
    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Reduce a source name to a lowercase slug, or null when nothing usable
     * survives.
     *
     * The LAST namespace segment is taken (so `Acme\Widgets\Plugin` is `plugin`,
     * matching how a plugin is addressed rather than how its class is spelled),
     * lowercased, with runs of non-slug characters collapsed to single
     * underscores and leading/trailing underscores trimmed.
     *
     * Returns null when the result is empty or does not begin with a letter, so
     * a nameless or symbol-only source is REFUSED rather than silently reduced
     * to something that could collide with a real one.
     *
     * @param string $source The raw source name (a plugin name).
     * @return string|null The slug, or null when no usable slug exists.
     */
    public static function from(string $source): ?string
    {
        return PluginNamespace::slug($source);
    }
}
