<?php

declare(strict_types=1);

namespace Whity\Core\Document\Theme;

/**
 * The closed set of values a document theme may set (editor M4d; E.44,
 * DOCUMENT-THEMES.md 3.2). A theme is data, never CSS: every value is an enum
 * or a validated colour, and core maps it onto the document's own tokens.
 * Everything else in a document's look follows stage 6 and E.33 and is not
 * themeable. A new key is a register change first.
 *
 * - `accent`: the print colour, #rrggbb (`oklch()` is accepted by the design
 *   but not yet here). Defaults to the workspace main colour, else Whity Blue.
 * - `accentOnDark`: the title colour in the continuous sheet on dark; never
 *   printed. Derived from `accent` when not set (2.8b).
 * - `accentUse`: `title`, or `title-and-headings` for H1-H3 too (E.45).
 * - `letterhead.logo`: `wide`, `square` or `none` (6.2).
 * - `letterhead.lines`: which tenant lines print under the name, in order, up
 *   to four of `address`, `taxId`, `phone`, `email`.
 *
 * The title face and large print (E.46, E.47) come with M8. Pure and stateless.
 */
final class DocumentThemeTokens
{
    public const ACCENT_USES = ['title', 'title-and-headings'];
    public const LOGOS = ['wide', 'square', 'none'];
    public const LINES = ['address', 'taxId', 'phone', 'email'];

    /** The keys a document may override on its own (3.3). */
    public const OVERRIDE_KEYS = ['accent', 'letterhead'];

    /** Whity's standard theme, apart from the accent, which comes from the workspace. */
    public const STANDARD = [
        'accentUse'  => 'title',
        'letterhead' => ['logo' => 'wide', 'lines' => ['address', 'taxId']],
    ];

    private const COLOR = '/^#[0-9A-Fa-f]{6}$/';

    /**
     * What is wrong with a partial token set, keyed by the token's path; empty
     * when it is valid. With `$overridesOnly`, only the keys a document may
     * override are allowed.
     *
     * @return array<string, string>
     */
    public static function errors(mixed $tokens, bool $overridesOnly = false): array
    {
        if (!is_array($tokens) || ($tokens !== [] && array_is_list($tokens))) {
            return ['tokens' => 'Theme tokens must be an object.'];
        }
        $allowed = $overridesOnly ? self::OVERRIDE_KEYS : ['accent', 'accentOnDark', 'accentUse', 'letterhead'];
        $errors = [];
        foreach ($tokens as $key => $value) {
            $key = (string) $key;
            if (!in_array($key, $allowed, true)) {
                $errors[$key] = $overridesOnly
                    ? "{$key} can't be set on a document; a document may override only accent and letterhead."
                    : "{$key} is not a theme token.";
                continue;
            }
            $reason = match ($key) {
                'accent', 'accentOnDark' => is_string($value) && preg_match(self::COLOR, $value) === 1
                    ? null : "{$key} must be a #rrggbb colour.",
                'accentUse' => in_array($value, self::ACCENT_USES, true)
                    ? null : 'accentUse must be one of ' . implode(', ', self::ACCENT_USES) . '.',
                default => null,
            };
            if ($reason !== null) {
                $errors[$key] = $reason;
            }
            if ($key === 'letterhead') {
                $errors += self::letterheadErrors($value);
            }
        }

        return $errors;
    }

    /**
     * A valid partial token set, with colours in lower case.
     *
     * @param array<string, mixed> $tokens
     * @return array<string, mixed>
     */
    public static function normalize(array $tokens): array
    {
        foreach (['accent', 'accentOnDark'] as $key) {
            if (is_string($tokens[$key] ?? null)) {
                $tokens[$key] = strtolower($tokens[$key]);
            }
        }

        return $tokens;
    }

    /**
     * Layer a partial token set over another: a later layer wins key by key,
     * and `letterhead` merges by its own keys.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     * @return array<string, mixed>
     */
    public static function merge(array $base, array $over): array
    {
        foreach ($over as $key => $value) {
            if ($key === 'letterhead' && is_array($value) && is_array($base['letterhead'] ?? null)) {
                $base['letterhead'] = array_replace($base['letterhead'], $value);
                continue;
            }
            $base[$key] = $value;
        }

        return $base;
    }

    /**
     * The token schema with allowed values and their English and Arabic labels
     * (`GET /api/document-themes/schema`), so the UI, plugins and agents build
     * forms from one source.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        return [
            'tokens' => [
                [
                    'key' => 'accent', 'type' => 'color', 'overridable' => true,
                    'label' => 'Print colour', 'labelAr' => 'لون الطباعة',
                    'description' => "The document's title and fields. Defaults to the workspace main colour, and is darkened if it can't be read on white.",
                    'descriptionAr' => 'عنوان المستند وحقوله. يأخذ افتراضيًا اللون الرئيسي لمساحة العمل، ويُغمَّق إن لم يكن مقروءًا على الأبيض.',
                ],
                [
                    'key' => 'accentOnDark', 'type' => 'color', 'overridable' => false,
                    'label' => 'Print colour on dark', 'labelAr' => 'لون الطباعة على الداكن',
                    'description' => 'The title on screen in dark mode. Never printed. Derived from the print colour when not set.',
                    'descriptionAr' => 'العنوان على الشاشة في الوضع الداكن. لا يُطبع. يُشتق من لون الطباعة إن لم يُحدَّد.',
                ],
                [
                    'key' => 'accentUse', 'type' => 'enum', 'overridable' => false,
                    'label' => 'Print colour on', 'labelAr' => 'لون الطباعة على',
                    'values' => [
                        ['value' => 'title', 'label' => 'The title only', 'labelAr' => 'العنوان فقط'],
                        ['value' => 'title-and-headings', 'label' => 'The title and headings', 'labelAr' => 'العنوان والعناوين الفرعية'],
                    ],
                ],
                [
                    'key' => 'letterhead.logo', 'type' => 'enum', 'overridable' => true,
                    'label' => 'Letterhead logo', 'labelAr' => 'شعار الترويسة',
                    'values' => [
                        ['value' => 'wide', 'label' => 'Wide logo', 'labelAr' => 'الشعار العريض'],
                        ['value' => 'square', 'label' => 'Square logo', 'labelAr' => 'الشعار المربع'],
                        ['value' => 'none', 'label' => 'No logo', 'labelAr' => 'بلا شعار'],
                    ],
                ],
                [
                    'key' => 'letterhead.lines', 'type' => 'list', 'overridable' => true, 'max' => count(self::LINES),
                    'label' => 'Letterhead lines', 'labelAr' => 'أسطر الترويسة',
                    'values' => [
                        ['value' => 'address', 'label' => 'Address', 'labelAr' => 'العنوان'],
                        ['value' => 'taxId', 'label' => 'Tax number', 'labelAr' => 'الرقم الضريبي'],
                        ['value' => 'phone', 'label' => 'Phone', 'labelAr' => 'الهاتف'],
                        ['value' => 'email', 'label' => 'Email', 'labelAr' => 'البريد الإلكتروني'],
                    ],
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    private static function letterheadErrors(mixed $value): array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return ['letterhead' => 'letterhead must be an object with logo and lines.'];
        }
        $errors = [];
        foreach ($value as $key => $item) {
            $key = (string) $key;
            if ($key === 'logo') {
                if (!in_array($item, self::LOGOS, true)) {
                    $errors['letterhead.logo'] = 'letterhead.logo must be one of ' . implode(', ', self::LOGOS) . '.';
                }
                continue;
            }
            if ($key === 'lines') {
                if (!is_array($item) || !array_is_list($item) || count($item) > count(self::LINES)
                    || array_filter($item, 'is_string') !== $item
                    || count(array_unique($item, SORT_REGULAR)) !== count($item)
                    || array_diff($item, self::LINES) !== []) {
                    $errors['letterhead.lines'] = 'letterhead.lines must list, in order and without repeats, up to four of '
                        . implode(', ', self::LINES) . '.';
                }
                continue;
            }
            $errors["letterhead.{$key}"] = "letterhead.{$key} is not a theme token.";
        }

        return $errors;
    }
}
