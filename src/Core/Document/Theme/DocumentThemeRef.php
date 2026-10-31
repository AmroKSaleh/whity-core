<?php

declare(strict_types=1);

namespace Whity\Core\Document\Theme;

/**
 * How a document names its theme (editor M4d; DOCUMENT-THEMES.md 3.3).
 *
 * A ref is `<scope>.<key>`: `whity.standard` (Whity's own, in code),
 * `tenant.<key>` (a workspace theme, `document_themes.theme_key`) or, from M7,
 * `<plugin>.<key>`. Pure and stateless.
 */
final class DocumentThemeRef
{
    /** Whity's standard theme: the base every theme extends. */
    public const STANDARD = 'whity.standard';

    /** The scope of a workspace theme's ref. */
    public const TENANT_SCOPE = 'tenant';

    /** The document kinds a per-kind default can name (DocumentSourcesApiHandler::KINDS). */
    public const KINDS = ['template', 'letter', 'report', 'note', 'wiki', 'label'];

    /** A theme key: lower-case letters, digits and underscores, up to 40. */
    public const KEY_PATTERN = '/^[a-z0-9_]{1,40}$/';

    private const PATTERN = '/^[a-z0-9_]{1,40}\.[a-z0-9_]{1,40}$/';

    public static function isValid(string $ref): bool
    {
        return preg_match(self::PATTERN, $ref) === 1;
    }

    public static function forTenant(string $key): string
    {
        return self::TENANT_SCOPE . '.' . $key;
    }

    /** The workspace theme key a ref names, or null when it names another scope. */
    public static function tenantKey(string $ref): ?string
    {
        if (!self::isValid($ref) || !str_starts_with($ref, self::TENANT_SCOPE . '.')) {
            return null;
        }

        return substr($ref, strlen(self::TENANT_SCOPE) + 1);
    }

    /** Why a `documents.theme_by_kind` value is invalid, or null when it is valid. */
    public static function validateByKind(string $value): ?string
    {
        $map = json_decode($value, true);
        if (!is_array($map) || ($map !== [] && array_is_list($map))) {
            return 'documents.theme_by_kind must be a JSON object of document kind to theme ref, e.g. {"label":"tenant.labels"}.';
        }
        foreach ($map as $kind => $ref) {
            if (!in_array($kind, self::KINDS, true)) {
                return "documents.theme_by_kind: {$kind} is not a document kind (" . implode(', ', self::KINDS) . ').';
            }
            if (!is_string($ref) || !self::isValid($ref)) {
                return "documents.theme_by_kind: the theme for {$kind} must be a theme ref such as tenant.<key>.";
            }
        }

        return null;
    }

    /**
     * The per-kind defaults as a map, ignoring anything invalid.
     *
     * @return array<string, string>
     */
    public static function byKind(string $value): array
    {
        if ($value === '' || self::validateByKind($value) !== null) {
            return [];
        }
        /** @var array<string, string> $map */
        $map = json_decode($value, true);

        return $map;
    }
}
