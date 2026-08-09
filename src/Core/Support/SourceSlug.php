<?php

declare(strict_types=1);

namespace Whity\Core\Support;

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
        $segments = explode('\\', $source);
        $last = (string) end($segments);
        $slug = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', $last) ?? '');
        $slug = trim($slug, '_');

        return $slug === '' || preg_match('/^[a-z]/', $slug) !== 1 ? null : $slug;
    }
}
