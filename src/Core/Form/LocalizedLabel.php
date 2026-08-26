<?php

declare(strict_types=1);

namespace Whity\Core\Form;

/**
 * The `{ar?, en?}` bilingual label carried by `forms.name` and
 * `form_fields.label` (migration 127).
 *
 * Encoding and decoding live here rather than in each repository because the two
 * halves of a round trip have to agree about three things — what an absent
 * language means, what a bare string means, and what comes back for a column
 * that somehow holds neither — and two copies of that agreement is two chances
 * to disagree.
 *
 * WHY A BARE STRING IS ACCEPTED ON INPUT
 * ---------------------------------------
 * Arabic and English are both first-class here ({@see \Whity\Core\i18n}), and a
 * form named in one language only is the ordinary case, not a defect. So a plain
 * string is read as `{en: "..."}` rather than refused. An API that demanded the
 * object shape would push every caller into writing `{"en": name}` by hand, and
 * the first one to forget would get a 422 for a request that meant something
 * perfectly clear.
 *
 * What is NOT accepted is a label with no language in it at all — `{}`, or an
 * object whose only keys are languages this platform does not carry. That is not
 * a label, and storing it produces a form that renders with no name anywhere.
 *
 * WHY DECODE NEVER THROWS
 * ------------------------
 * A row already in the table is a fact, whatever is in the column. A decoder
 * that threw on a malformed value would make one bad row take down the whole
 * list endpoint — the form that cannot be read is exactly the form somebody
 * needs to open in order to fix it. A value that will not decode comes back as
 * `{en: <the raw string>}`, which is legible and repairable.
 *
 * Stateless — worker-safe.
 */
final class LocalizedLabel
{
    /**
     * The languages a label may carry.
     *
     * Deliberately not read from the `languages` table. This is the set of
     * columns the SHAPE has — the same two the SDK's `bilingualText` block and
     * the schema-driven CRUD screen's LocalizedText renderer draw — and it is a
     * contract with those renderers, not a per-install setting. A tenant
     * enabling a third UI language does not thereby change the shape of a stored
     * label.
     *
     * @var list<string>
     */
    private const LANGUAGES = ['ar', 'en'];

    /**
     * Static helper only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Normalize caller input into the stored `{ar?, en?}` object.
     *
     * @param mixed $value A `{ar?, en?}` object, or a bare string meaning English.
     * @return array<string, string> Only the languages actually supplied.
     *
     * @throws FormRejectedException When nothing usable is present.
     */
    public static function fromInput(mixed $value, string $field): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                throw new FormRejectedException("{$field} must not be empty");
            }

            return ['en' => $trimmed];
        }

        if (!is_array($value)) {
            throw new FormRejectedException(
                "{$field} must be text, or an object with 'ar' and/or 'en'"
            );
        }

        $label = [];
        foreach (self::LANGUAGES as $language) {
            $raw = $value[$language] ?? null;
            if (!is_string($raw)) {
                continue;
            }
            $trimmed = trim($raw);
            if ($trimmed !== '') {
                $label[$language] = $trimmed;
            }
        }

        if ($label === []) {
            throw new FormRejectedException(
                "{$field} must carry at least one of 'ar' or 'en'"
            );
        }

        return $label;
    }

    /**
     * Encode for storage. `JSON_UNESCAPED_UNICODE` for the reason
     * {@see \Whity\Core\Document\DocumentRepository::create()} gives: an Arabic
     * label would otherwise be stored as escape sequences. They decode back
     * correctly, but the column stops being readable by the operator running a
     * query against it — and Arabic content is a first-class requirement here,
     * not an edge case.
     *
     * @param array<string, string> $label
     */
    public static function encode(array $label): string
    {
        $json = json_encode($label, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        // json_encode over an array<string,string> cannot fail; the fallback
        // exists so the return type is honest rather than because it is reachable.
        return $json === false ? '{}' : $json;
    }

    /**
     * Decode a stored column. Never throws — see the class docblock.
     *
     * @return array<string, string>
     */
    public static function decode(?string $stored): array
    {
        if ($stored === null || trim($stored) === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            // Not JSON at all — most likely a plain string written before this
            // column carried an object. Legible beats correct-and-blank.
            return ['en' => $stored];
        }

        $label = [];
        foreach (self::LANGUAGES as $language) {
            $value = $decoded[$language] ?? null;
            if (is_string($value) && $value !== '') {
                $label[$language] = $value;
            }
        }

        return $label;
    }

    /**
     * The single string to show when only one can be shown — a log line, a
     * document title, a fallback heading.
     *
     * English first because the platform's own identifiers are English and a log
     * is read by an operator; then Arabic; then empty. The renderer never calls
     * this — it gets the whole object and picks per the viewer's language.
     *
     * @param array<string, string> $label
     */
    public static function preferred(array $label): string
    {
        foreach (['en', 'ar'] as $language) {
            if (isset($label[$language]) && $label[$language] !== '') {
                return $label[$language];
            }
        }

        return '';
    }
}
