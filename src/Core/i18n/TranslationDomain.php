<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use Whity\Core\Support\SourceSlug;

/**
 * The naming rule for a translation DOMAIN — the bundle a set of keys belongs
 * to, and the unit the client fetches (`GET /api/v1/translations/{lang}/{domain}`).
 *
 * THE CONVENTION
 * --------------
 * A domain is either
 *
 *   - a BARE slug (`auth`, `common`, `errors`, `email`) — reserved for CORE, or
 *   - `<plugin-slug>:<slug>` (`acme:catalog`) — everything a plugin contributes.
 *
 * The separator, and the reasoning, are deliberately identical to
 * {@see \Whity\Core\RBAC\ResourceTypeRegistry}: the prefix comes from the
 * SOURCE the plugin loader supplies (`$plugin->getName()`, reduced by the
 * shared {@see SourceSlug}), never from the plugin's own data, so
 *
 *   - two plugins both shipping a `catalog` domain get DIFFERENT bundles and
 *     cannot overwrite each other's strings, and
 *   - no plugin can produce a bare key, so none can shadow `common` (or any
 *     future core domain) and silently restate the whole interface.
 *
 * Core stays unprefixed because `common`/`email`/`errors` are already written
 * that way in `translations.domain`; namespacing plugins does not rewrite data
 * already seeded.
 *
 * KEYS INSIDE A DOMAIN are a separate, looser convention: dot-delimited
 * lowercase paths, most-general segment first, named for the SCREEN or feature
 * rather than the English text — `login.email.label`, not `enter_your_email`.
 * Rewording the copy must never require renaming the key, since a key rename
 * orphans every translation of it in every other language.
 *
 * A domain is capped at 100 characters by the column (`translations.domain`
 * VARCHAR(100)); callers enforce that separately via InputLimits.
 */
final class TranslationDomain
{
    /**
     * Separates a plugin's namespace from its domain slug: `acme:catalog`.
     *
     * Matches {@see \Whity\Core\RBAC\ResourceTypeRegistry::NAMESPACE_SEPARATOR}
     * and the data-type keys of #723 — one namespacing shape across the
     * platform, so a reader who has learned it once has learned it everywhere.
     */
    public const NAMESPACE_SEPARATOR = ':';

    /** Source name for domains shipped by core. Its domains stay bare. */
    public const CORE_SOURCE = 'core';

    /** A bare (core) domain slug: lowercase, starts with a letter. */
    private const SLUG_PATTERN = '/^[a-z][a-z0-9_]*$/';

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Whether `$domain` is a well-formed domain in either shape.
     *
     * This is the ONE place the shape is decided; every read and write path
     * (public bundle fetch, admin list, admin create) asks here rather than
     * carrying its own regex, so a domain that can be written can always be
     * read back.
     */
    public static function isValid(string $domain): bool
    {
        if ($domain === '') {
            return false;
        }

        $parts = explode(self::NAMESPACE_SEPARATOR, $domain);

        if (count($parts) === 1) {
            return preg_match(self::SLUG_PATTERN, $parts[0]) === 1;
        }

        if (count($parts) !== 2) {
            // More than one separator is not a namespace, it is a typo.
            return false;
        }

        return preg_match(self::SLUG_PATTERN, $parts[0]) === 1
            && preg_match(self::SLUG_PATTERN, $parts[1]) === 1;
    }

    /**
     * The canonical domain a given source's bare slug resolves to.
     *
     * A plugin holding a bare slug uses this rather than concatenating by hand,
     * so the namespacing rule lives in exactly one place — the same contract as
     * {@see \Whity\Core\RBAC\ResourceTypeRegistry::canonicalKey()}.
     *
     * Returns the bare slug unchanged for core, and for a source that reduces
     * to no usable slug (a nameless plugin) — the caller validates the result
     * with {@see self::isValid()} before writing it.
     *
     * @param string $source The registration source (a plugin name; 'core' is core).
     * @param string $domain The bare domain slug, e.g. 'catalog'.
     */
    public static function canonical(string $source, string $domain): string
    {
        if ($source === self::CORE_SOURCE) {
            return $domain;
        }

        $prefix = SourceSlug::from($source);

        return $prefix === null ? $domain : $prefix . self::NAMESPACE_SEPARATOR . $domain;
    }

    /**
     * The namespace a domain belongs to: the plugin slug, or {@see self::CORE_SOURCE}
     * for a bare domain.
     */
    public static function namespaceOf(string $domain): string
    {
        $parts = explode(self::NAMESPACE_SEPARATOR, $domain, 2);

        return count($parts) === 2 ? $parts[0] : self::CORE_SOURCE;
    }
}
