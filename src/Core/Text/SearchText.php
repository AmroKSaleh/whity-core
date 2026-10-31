<?php

declare(strict_types=1);

namespace Whity\Core\Text;

/**
 * Folding text so a search matches what a person meant (WHIT-783).
 *
 * Arabic is written several ways for one word: the alef with or without hamza
 * or madda, taa marbuta or haa at the end, alef maqsura or yaa, with or without
 * diacritics and tatweel. A search for "احمد" has to find "أحمد", and one for
 * "مدرسه" has to find "مدرسة". Arabic-Indic digits are folded to Western ones,
 * which is how Whity shows numbers anyway, and Latin text is lower-cased.
 *
 * Two halves that must agree: {@see self::fold()} folds the query in PHP, and
 * {@see self::sqlFold()} folds a column in SQL the same way, as nested
 * `REPLACE()` calls that PostgreSQL and SQLite both have. No extension and no
 * stored column is needed. A test runs both over the same fixtures on the real
 * engine, so they cannot drift apart unnoticed.
 *
 * The SQL half folds letters, not digits: Whity stores numbers in Western
 * digits, so folding them in every row would cost twenty more REPLACE calls for
 * nothing. Only the query's digits are folded.
 */
final class SearchText
{
    /** Letter variants folded to one form, in both halves. */
    private const LETTERS = [
        'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا', 'ٱ' => 'ا',
        'ة' => 'ه',
        'ى' => 'ي',
    ];

    /** Marks removed entirely, in both halves: the harakat, the superscript alef and tatweel. */
    private const REMOVED = [
        "\u{064B}", "\u{064C}", "\u{064D}", "\u{064E}", "\u{064F}", "\u{0650}", "\u{0651}", "\u{0652}",
        "\u{0670}", "\u{0640}",
    ];

    /** Arabic-Indic and Eastern Arabic-Indic digits, folded in the query only. */
    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
    ];

    /** The folded form of a query: letters, marks and digits folded, lower-cased, spaces collapsed. */
    public static function fold(string $text): string
    {
        $text = strtr($text, self::LETTERS + self::DIGITS + array_fill_keys(self::REMOVED, ''));
        $text = mb_strtolower($text, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * A SQL expression folding $column the way {@see self::fold()} folds a
     * query (letters and marks; see the class note on digits). $column must be
     * a trusted identifier or expression, never user input.
     */
    public static function sqlFold(string $column): string
    {
        $expr = 'LOWER(' . $column . ')';
        foreach (self::LETTERS as $from => $to) {
            $expr = "REPLACE({$expr}, '{$from}', '{$to}')";
        }
        foreach (self::REMOVED as $mark) {
            $expr = "REPLACE({$expr}, '{$mark}', '')";
        }

        return $expr;
    }

    /**
     * A `LIKE` pattern matching $folded anywhere, with `%`, `_` and `\` escaped.
     * Use it with `ESCAPE '\'`: SQLite has no default escape character.
     */
    public static function containsPattern(string $folded): string
    {
        return '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $folded) . '%';
    }
}
