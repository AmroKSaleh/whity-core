<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * Translate the user-facing text inside a schema-driven screen declaration (#1044).
 *
 * WHAT THESE ARE. `GET /api/v1/frontend/features` serves whole screens as data —
 * blocks, fields, columns, empty states — declared in PHP by
 * {@see \Whity\Core\Convening\ConveningFeatures} and friends. Every visible word
 * in them is written in that PHP and reaches the browser already worded, so a
 * screen's own `t()` never sees it. That is the same gap as the rule labels in
 * {@see \Whity\Core\Document\Routing\RoutingRuleLabels}, an order of magnitude
 * larger: a form whose field names are Arabic and whose placeholders and empty
 * states are English is the mixed state #1044 calls worse than uniform English.
 *
 * THE KEY IS DECLARED, NOT DERIVED, and that is a deliberate reversal of what
 * worked for rule kinds. A kind IS a stable slug, so its key could be computed.
 * Here there is often nothing to compute from — `['type' => 'submitButton',
 * 'label' => 'Create body']` has no identifier at all — and the identifiers that
 * do exist repeat across features with different wording (`body_key` is "Key" in
 * one screen and could be something else in the next). A derived key would
 * either collide or have nothing to derive from, so each node carrying text
 * names its own key:
 *
 *     [
 *         'type'        => 'textInput',
 *         'name'        => 'body_key',
 *         'label'       => 'Key',
 *         'placeholder' => 'e.g. senate',
 *         'i18nKey'     => 'convening.body.field.body_key',
 *     ]
 *
 * ONE KEY PER NODE, NOT PER STRING. The node's key is a prefix and each
 * translatable field hangs off it (`.label`, `.placeholder`), so adding a
 * placeholder to a field that already has a label costs no new declaration line
 * on the node itself. Keys name the FIELD, never the English, so rewording
 * "Key" to "Identifier" cannot orphan the Arabic.
 *
 * `i18nKey` NEVER REACHES THE CLIENT. It is stripped here, so the wire shape is
 * exactly what it was before this existed — no block-schema change, no OpenAPI
 * drift, and no chance of a browser rendering an internal key because some
 * component fell back to showing an unrecognised prop.
 *
 * MISSES KEEP THE DECLARED ENGLISH — see {@see ServerLabels::label()} for why
 * that rule is the whole reason these lookups are not inline.
 */
final class SchemaLabels
{
    /** The property a node uses to name its key. Never served. */
    public const KEY_FIELD = 'i18nKey';

    /**
     * The catalogue domain core's schema-driven screens live in.
     *
     * `admin` because these are admin-area screens and they had no keys in ANY
     * domain before this — the surface is entirely server-declared, which is the
     * gap itself. One domain rather than one per subsystem keeps a sweep like
     * this reviewable as a single catalogue diff.
     */
    public const CORE_DOMAIN = 'admin';

    /**
     * Fields whose value is text a person reads.
     *
     * `labelField`, `titleField` and `valueField` are deliberately absent: they
     * name a COLUMN, not a string, and translating one would break the screen
     * rather than localise it.
     *
     * @var list<string>
     */
    public const TRANSLATABLE = [
        'label',
        'title',
        'placeholder',
        'emptyText',
        'description',
        'confirm',
        'enLabel',
        'arLabel',
        // The caption on the control that opens a modal. Reads like structure
        // and is not: it is the button a person clicks.
        'trigger',
        // What one row of a `fieldArray` is called ("Attendee", "Question").
        'itemLabel',
    ];

    /**
     * Fields that are text ONLY under certain block types.
     *
     * `value` is the trap this exists for. On `type: 'text'` it is the prose of
     * a paragraph; on a select option it is the value SUBMITTED to the API, with
     * the caption in `label` beside it. Translating it unconditionally would not
     * mistranslate the screen, it would break the form — a decision recorded as
     * `مُعتمَد` against a column that accepts `approved`.
     *
     * @var array<string, list<string>>
     */
    public const TRANSLATABLE_FOR_TYPE = [
        'value' => ['text'],
        // An alert's prose. Everywhere else `body` is a request payload, which
        // is why this is scoped rather than listed above.
        'body' => ['alert'],
    ];

    /**
     * The fields of THIS node that hold text a person reads.
     *
     * @param array<string, mixed> $node
     * @return list<string>
     */
    private static function fieldsOf(array $node): array
    {
        $fields = self::TRANSLATABLE;
        $type = $node['type'] ?? null;

        if (is_string($type)) {
            foreach (self::TRANSLATABLE_FOR_TYPE as $field => $types) {
                if (in_array($type, $types, true)) {
                    $fields[] = $field;
                }
            }
        }

        return $fields;
    }

    /**
     * A declaration tree with its text in the caller's language.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    public static function localise(array $node, string $domain, ServerLabels $labels): array
    {
        $key = $node[self::KEY_FIELD] ?? null;

        if (is_string($key) && $key !== '') {
            foreach (self::fieldsOf($node) as $field) {
                $declared = $node[$field] ?? null;
                if (is_string($declared) && $declared !== '') {
                    $node[$field] = $labels->label($domain, $key . '.' . $field, $declared);
                }
            }
        }

        // Unconditionally, so a malformed or empty key cannot ride out to a
        // browser as an unrecognised prop.
        unset($node[self::KEY_FIELD]);

        foreach ($node as $property => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $node[$property] = self::localise($value, $domain, $labels);
            }
        }

        return $node;
    }

    /**
     * Every `key => English` a declaration tree declares.
     *
     * The parity guard's input: what the code says the English is, to compare
     * against what the `@i18n-keys` block told the catalogue.
     *
     * @param array<string, mixed> $node
     * @return array<string, string>
     */
    public static function declaredStrings(array $node): array
    {
        $found = [];
        $key = $node[self::KEY_FIELD] ?? null;

        if (is_string($key) && $key !== '') {
            foreach (self::fieldsOf($node) as $field) {
                $text = $node[$field] ?? null;
                if (is_string($text) && $text !== '') {
                    $found[$key . '.' . $field] = $text;
                }
            }
        }

        foreach ($node as $value) {
            if (!is_array($value)) {
                continue;
            }

            /** @var array<string, mixed> $value */
            $child = self::declaredStrings($value);

            foreach ($child as $childKey => $childText) {
                $found[$childKey] = $childText;
            }
        }

        return $found;
    }

    /**
     * Text that would stay English forever: a node with user-facing strings and
     * no `i18nKey`.
     *
     * THE COMPLETENESS GUARD'S INPUT, and the reason this file is worth its
     * length. Adding a field to a screen is routine; remembering that its label
     * needs a key is not. Without this, the failure is invisible — the screen
     * works, the tests pass, and one string is quietly English in every language
     * forever. That is precisely how the ~200 strings this sweep covers came to
     * exist in the first place.
     *
     * @param array<string, mixed> $node
     * @param string $path Where the node sits, for a message somebody can act on.
     * @return list<array{path: string, field: string, text: string}>
     */
    public static function unkeyed(array $node, string $path = ''): array
    {
        $found = [];
        $key = $node[self::KEY_FIELD] ?? null;
        $keyed = is_string($key) && $key !== '';

        if (!$keyed) {
            foreach (self::fieldsOf($node) as $field) {
                $text = $node[$field] ?? null;
                if (is_string($text) && $text !== '') {
                    $found[] = [
                        'path' => $path === '' ? '(root)' : $path,
                        'field' => $field,
                        'text' => $text,
                    ];
                }
            }
        }

        foreach ($node as $property => $value) {
            if (is_array($value)) {
                /** @var array<string, mixed> $value */
                $childPath = $path === '' ? (string) $property : $path . '.' . (string) $property;
                foreach (self::unkeyed($value, $childPath) as $problem) {
                    $found[] = $problem;
                }
            }
        }

        return $found;
    }
}
