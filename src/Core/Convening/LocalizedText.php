<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

/**
 * A label that exists in more than one language at once.
 *
 * WHY THE COLUMN HOLDS JSON AND NOT A STRING
 * ------------------------------------------
 * This platform's Arabic/RTL support is not a display setting layered over
 * English data — a body HAS an Arabic name and an English one, and both are the
 * real one. A single VARCHAR forces every deployment to pick which of its two
 * names is the record, and the other one then lives in a spreadsheet. A column
 * per language needs a migration for the third language and a schema change for
 * every tenant that only ever wanted one.
 *
 * So `convening_bodies.name` and `meetings.title` hold a JSON OBJECT of
 * locale => text, and this class is the only place in the subsystem that encodes
 * or decodes one. Two rules it enforces, both of which exist because their
 * absence is silent:
 *
 *  - AN EMPTY OBJECT IS REFUSED. A body whose name is `{}` renders as a blank
 *    row in every picker, and blank rows read as a loading bug rather than as
 *    missing data.
 *  - A BARE STRING IS ACCEPTED ON THE WAY IN and stored under the caller's
 *    locale. An API that demanded a locale map would make the common case — one
 *    organisation, one language — the awkward one, and awkward APIs get bare
 *    strings JSON-encoded into them by clients that then cannot read them back.
 *
 * THE MAP IS THE RECORD; A PICKED STRING IS NEVER A SUBSTITUTE FOR IT. Every API
 * response carries the whole map, because the client knows the viewer's locale
 * and its own fallback order, and resolving it server-side would bake one
 * client's policy into every other client's data.
 *
 * {@see preferred()} exists for the surfaces that can carry only ONE string and
 * have no viewer to ask — a notification subject line, and a cell in a
 * server-driven block table whose renderer is given a field name and nothing
 * else. Those are real and they are narrow. It is emitted ALONGSIDE the map
 * (`display_name` / `display_title`), never instead of it, so a localizing client
 * ignores it and a single-string surface has something to show. Giving those
 * surfaces nothing does not make the platform more multilingual; it makes the
 * column blank.
 */
final class LocalizedText
{
    /**
     * Longest a single localized label may be.
     *
     * Applied PER LOCALE rather than to the encoded object: a caller supplying
     * three languages should not be refused for the crime of supplying three
     * languages, and the encoded length is a storage detail they cannot see.
     */
    public const MAX_LENGTH = 255;

    /**
     * Most locales one label may carry.
     *
     * A ceiling rather than no ceiling, because this is a JSON blob a caller
     * controls the size of and TEXT has no length to refuse them at. Sixteen is
     * past any real deployment and far short of anything worth storing by
     * accident.
     */
    public const MAX_LOCALES = 16;

    /**
     * Normalize whatever a caller sent into a locale => text map.
     *
     * @param mixed  $raw           A map of locale => text, or a bare string.
     * @param string $fallbackLocale Where a bare string is filed.
     *
     * @return array<string, string>
     *
     * @throws ConveningRejectedException When it is neither, is empty, carries a
     *         malformed locale key, or exceeds a ceiling.
     */
    public static function normalize(mixed $raw, string $fallbackLocale, string $field): array
    {
        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                throw ConveningRejectedException::because("{$field} cannot be empty.");
            }
            $raw = [$fallbackLocale => $trimmed];
        }

        if (!is_array($raw) || $raw === []) {
            throw ConveningRejectedException::because(
                "{$field} must be a non-empty string, or an object of language code to text "
                . '(for example {"en": "Standards Board", "ar": "مجلس المعايير"}).'
            );
        }

        $out = [];
        foreach ($raw as $locale => $text) {
            if (!is_string($locale) || preg_match('/^[a-z]{2,3}(-[A-Za-z0-9]{2,8})?$/', $locale) !== 1) {
                throw ConveningRejectedException::because(
                    "{$field} has a language key that is not a language code: "
                    . (is_string($locale) ? "'{$locale}'" : gettype($locale)) . '.'
                );
            }
            if (!is_string($text)) {
                throw ConveningRejectedException::because("{$field}.{$locale} must be text.");
            }
            $text = trim($text);
            if ($text === '') {
                // Dropped rather than refused: a client sending every language
                // it knows with the ones nobody filled in left blank is doing
                // the ordinary thing, and refusing it would make the form
                // unusable. What is refused is ALL of them being blank, below.
                continue;
            }
            if (mb_strlen($text) > self::MAX_LENGTH) {
                throw ConveningRejectedException::because(
                    "{$field}.{$locale} is longer than " . self::MAX_LENGTH . ' characters.'
                );
            }
            $out[$locale] = $text;
        }

        if ($out === []) {
            throw ConveningRejectedException::because("{$field} cannot be empty in every language.");
        }
        if (count($out) > self::MAX_LOCALES) {
            throw ConveningRejectedException::because(
                "{$field} carries more than " . self::MAX_LOCALES . ' languages.'
            );
        }

        return $out;
    }

    /**
     * Encode a normalized map for storage.
     *
     * `JSON_UNESCAPED_UNICODE` is not cosmetic here: without it an Arabic name is
     * stored as a wall of `\uXXXX` escapes, which triples its stored length and
     * makes every `grep` over a database dump useless to the people most likely
     * to be running one.
     *
     * @param array<string, string> $map
     */
    public static function encode(array $map): string
    {
        return (string) json_encode($map, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Decode a stored value back into a map.
     *
     * TOLERANT ON THE WAY OUT, and deliberately so. A row written before this
     * class existed, or by a hand-run SQL statement, holds a bare string; that is
     * a label somebody can still read, and refusing to render the row would turn
     * a cosmetic inconsistency into an empty screen. It is filed under the
     * fallback locale, which is the only honest thing to say about it.
     *
     * @return array<string, string>
     */
    public static function decode(mixed $stored, string $fallbackLocale): array
    {
        if (!is_string($stored) || trim($stored) === '') {
            return [];
        }

        $decoded = json_decode($stored, true);
        if (!is_array($decoded)) {
            return [$fallbackLocale => $stored];
        }

        $out = [];
        foreach ($decoded as $locale => $text) {
            if (is_string($locale) && is_string($text) && $text !== '') {
                $out[$locale] = $text;
            }
        }

        return $out === [] ? [$fallbackLocale => $stored] : $out;
    }

    /**
     * ONE readable string out of a locale map, for a surface that can hold only
     * one and has no viewer to ask.
     *
     * The order is: the preferred locale if the label has it, then any locale the
     * label does have, then the fallback text. The middle step is the one worth
     * defending — a subject line or a table cell in a language the reader did not
     * ask for is still information, and an empty one is not. A blank cell reads
     * as a broken query; an Arabic name in an English list reads as an Arabic
     * name.
     *
     * Accepts a bare string too, so a legacy row that never held a map still
     * renders.
     *
     * @param mixed $localized A locale map, or a bare string.
     */
    public static function preferred(mixed $localized, string $preferLocale = 'en', string $fallback = ''): string
    {
        if (is_string($localized) && trim($localized) !== '') {
            return trim($localized);
        }
        if (!is_array($localized) || $localized === []) {
            return $fallback;
        }

        $preferred = $localized[$preferLocale] ?? null;
        if (is_string($preferred) && trim($preferred) !== '') {
            return trim($preferred);
        }

        foreach ($localized as $text) {
            if (is_string($text) && trim($text) !== '') {
                return trim($text);
            }
        }

        return $fallback;
    }
}
