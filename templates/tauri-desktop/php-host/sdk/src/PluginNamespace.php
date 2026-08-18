<?php

declare(strict_types=1);

namespace Whity\Sdk;

use InvalidArgumentException;

/**
 * How a plugin's NAME becomes the namespace a host stamps on what it declares
 * (SDK v1.29).
 *
 * Every declaration surface the host namespaces — resource types, health
 * probes, settings keys, async jobs, and now audited events — reduces the
 * plugin name the loader holds to a slug and prefixes the declared name with
 * it. The rule was host-internal, which was fine for as long as only the host
 * needed to spell the result.
 *
 * Audited events broke that. The host binds its audit listener to the
 * NAMESPACED event name, so the plugin has to dispatch that exact string — it
 * is the first namespaced name a plugin must produce for itself rather than
 * merely receive. The choices were to publish the rule or to let every plugin
 * author re-derive it from an example in the docs, and a re-derived slug is
 * wrong in precisely the cases that are hard to notice: a plugin named
 * `Acme\Widgets\Plugin`, a name carrying a hyphen, a name with a trailing
 * underscore. A dispatch under a slightly different prefix matches no listener
 * and raises no error — the plugin simply ships with an audit trail that is
 * quietly empty, which is the failure this capability exists to remove.
 *
 * So the rule lives here, once, and the host's own source-slug helper defers to
 * it — the SDK references no host type, so the dependency points this way and
 * only this way. One implementation is what makes the name a plugin dispatches
 * and the name the host listens for unable to drift apart in a future release.
 */
final class PluginNamespace
{
    /**
     * Separates a plugin's namespace from a name it declared: `acme:sync`.
     *
     * A colon, matching every host registry that namespaces a plugin
     * declaration. Core's own names live in dotted namespaces and never carry a
     * colon, which is what makes a namespaced plugin name unable to collide
     * with one.
     */
    public const SEPARATOR = ':';

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Reduce a plugin name to its lowercase namespace slug, or null when
     * nothing usable survives.
     *
     * The LAST namespace segment is taken (so `Acme\Widgets\Plugin` is
     * `plugin`, matching how a plugin is addressed rather than how its class is
     * spelled), lowercased, with runs of non-slug characters collapsed to
     * single underscores and leading/trailing underscores trimmed.
     *
     * Returns null when the result is empty or does not begin with a letter, so
     * a nameless or symbol-only plugin is REFUSED rather than silently reduced
     * to something that could collide with a real one.
     *
     * @param string $pluginName The plugin name ({@see PluginInterface::getName()}).
     * @return string|null The slug, or null when no usable slug exists.
     */
    public static function slug(string $pluginName): ?string
    {
        $segments = explode('\\', $pluginName);
        $last = (string) end($segments);
        $slug = strtolower(preg_replace('/[^A-Za-z0-9_]+/', '_', $last) ?? '');
        $slug = trim($slug, '_');

        return $slug === '' || preg_match('/^[a-z]/', $slug) !== 1 ? null : $slug;
    }

    /**
     * The canonical, namespaced form of a name this plugin declared.
     *
     * THROWS rather than falling back to the bare name when the plugin name
     * yields no slug. A fallback looks harmless — the host would refuse such a
     * plugin's declarations anyway — but it would hand back an UNPREFIXED
     * string, and an unprefixed string is exactly the shape of a core name.
     * `qualify('!!!', 'user.deleted')` returning `user.deleted` would let a
     * plugin dispatch core's own event by choosing an unusable name for itself,
     * which is the one outcome namespacing exists to make impossible. Loud
     * refusal at the call site costs a plugin whose name was never viable
     * nothing it had.
     *
     * @param string $pluginName    The plugin name ({@see PluginInterface::getName()}).
     * @param string $declaredName  The BARE name this plugin declared.
     * @return string The namespaced name, e.g. `acme:task.completed`.
     *
     * @throws InvalidArgumentException When the plugin name yields no usable slug.
     */
    public static function qualify(string $pluginName, string $declaredName): string
    {
        $slug = self::slug($pluginName);
        if ($slug === null) {
            throw new InvalidArgumentException(
                "Plugin name '{$pluginName}' yields no usable namespace slug, so '{$declaredName}' "
                . 'cannot be namespaced; a plugin name must contain a segment starting with a letter'
            );
        }

        return $slug . self::SEPARATOR . $declaredName;
    }
}
