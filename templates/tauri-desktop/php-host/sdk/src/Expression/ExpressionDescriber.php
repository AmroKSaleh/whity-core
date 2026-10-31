<?php

declare(strict_types=1);

namespace Whity\Sdk\Expression;

/**
 * An expression in words, for the person approving it (SDK 1.47, WHIT-759).
 *
 * A proposal to publish a rule shows "Deposit is at least Price × 0.3", not a
 * JSON tree: the register's proposal card leads with what will happen, in the
 * reader's language (decision 7.5a). English and Arabic, with field labels
 * from the manifest when the caller has them.
 *
 * Arithmetic keeps its symbols in both languages (+ − × ÷), and numbers stay
 * Western digits, as the register asks for numbers and amounts. Only the words
 * around them are translated.
 *
 * Call it on a VALID expression ({@see Expression::validate()}); anything it
 * doesn't recognise is written as `…` rather than failing, because a
 * description must never be the reason a proposal can't be shown.
 */
final class ExpressionDescriber
{
    private const WORDS = [
        'en' => [
            'and' => ' and ', 'or' => ' or ', 'not' => 'not (%s)', 'if' => 'if %s then %s', 'else' => ', otherwise %s',
            '==' => '%s is %s', '!=' => '%s is not %s', '<' => '%s is less than %s', '<=' => '%s is at most %s',
            '>' => '%s is more than %s', '>=' => '%s is at least %s',
            'state' => 'the state', 'today' => 'today', 'now' => 'now', 'has_role' => 'you have the role %s',
            'item' => 'the item', 'item.key' => 'its %s',
            'min' => 'the smaller of %s', 'max' => 'the larger of %s', 'round' => '%s rounded', 'round.digits' => '%s rounded to %s decimals',
            'abs' => 'the size of %s', 'concat' => '%s joined', 'contains' => '%s contains %s', 'starts_with' => '%s starts with %s',
            'ends_with' => '%s ends with %s', 'length' => 'the length of %s', 'lower' => '%s in lowercase', 'upper' => '%s in uppercase',
            'add_days' => '%s plus %s days', 'days_between' => 'the days from %s to %s', 'in' => '%s is one of %s',
            'any' => 'any of %s where %s', 'all' => 'all of %s where %s', 'count' => 'the number of %s', 'count.where' => 'the number of %s where %s',
            'sum' => 'the total of %s over %s', 'is_empty' => '%s is empty', 'coalesce' => 'the first of %s that has a value',
            'list' => '(%s)', 'null' => 'nothing', 'true' => 'yes', 'false' => 'no', 'sep' => ', ',
        ],
        'ar' => [
            'and' => ' و ', 'or' => ' أو ', 'not' => 'ليس (%s)', 'if' => 'إذا %s فإن %s', 'else' => '، وإلا %s',
            '==' => '%s يساوي %s', '!=' => '%s لا يساوي %s', '<' => '%s أقل من %s', '<=' => '%s لا يزيد عن %s',
            '>' => '%s أكثر من %s', '>=' => '%s لا يقل عن %s',
            'state' => 'الحالة', 'today' => 'اليوم', 'now' => 'الآن', 'has_role' => 'لديك دور %s',
            'item' => 'العنصر', 'item.key' => '%s للعنصر',
            'min' => 'الأصغر من %s', 'max' => 'الأكبر من %s', 'round' => '%s مقرّبًا', 'round.digits' => '%s مقرّبًا إلى %s منازل عشرية',
            'abs' => 'القيمة المطلقة لـ %s', 'concat' => '%s مدموجة', 'contains' => '%s يحتوي على %s', 'starts_with' => '%s يبدأ بـ %s',
            'ends_with' => '%s ينتهي بـ %s', 'length' => 'طول %s', 'lower' => '%s بأحرف صغيرة', 'upper' => '%s بأحرف كبيرة',
            'add_days' => '%s بعد %s يوم', 'days_between' => 'عدد الأيام من %s إلى %s', 'in' => '%s من بين %s',
            'any' => 'أيٌّ من %s حيث %s', 'all' => 'كلٌّ من %s حيث %s', 'count' => 'عدد %s', 'count.where' => 'عدد %s حيث %s',
            'sum' => 'مجموع %s على %s', 'is_empty' => '%s فارغ', 'coalesce' => 'أول قيمة موجودة من %s',
            'list' => '(%s)', 'null' => 'لا شيء', 'true' => 'نعم', 'false' => 'لا', 'sep' => '، ',
        ],
    ];

    private const SYMBOLS = ['+' => ' + ', '-' => ' − ', '*' => ' × ', '/' => ' ÷ '];

    /** Static helper only — never instantiated. */
    private function __construct()
    {
    }

    /**
     * @param 'en'|'ar' $language
     * @param array<string, array{en: string, ar: string}> $labels Field name =>
     *        label, usually the manifest's field labels. A field without one is
     *        written from its name, underscores as spaces.
     */
    public static function describe(mixed $expression, string $language = 'en', array $labels = []): string
    {
        $words = $language === 'ar' ? self::WORDS['ar'] : self::WORDS['en'];

        return self::text($expression, $words, $language, $labels, false);
    }

    /**
     * @param array<string, string> $w
     * @param array<string, array{en: string, ar: string}> $labels
     */
    private static function text(mixed $node, array $w, string $lang, array $labels, bool $nested): string
    {
        if ($node === null) {
            return $w['null'];
        }
        if (is_bool($node)) {
            return $node ? $w['true'] : $w['false'];
        }
        if (is_int($node) || is_float($node)) {
            return (string) $node;
        }
        if (is_string($node)) {
            return '"' . $node . '"';
        }
        if (!is_array($node) || array_is_list($node) || count($node) !== 1) {
            return '…';
        }
        $op = (string) array_key_first($node);
        $raw = $node[$op];
        $args = is_array($raw) && array_is_list($raw) ? $raw : [$raw];
        $sub = static fn (mixed $a): string => self::text($a, $w, $lang, $labels, true);
        $all = static fn (array $as): string => implode($w['sep'], array_map($sub, $as));

        $out = match ($op) {
            'field' => self::fieldLabel((string) ($args[0] ?? ''), $lang, $labels),
            'item' => isset($args[0]) && is_string($args[0])
                ? sprintf($w['item.key'], self::fieldLabel($args[0], $lang, $labels))
                : $w['item'],
            'state', 'today', 'now' => $w[$op],
            'has_role' => sprintf($w['has_role'], (string) ($args[0] ?? '')),
            'and', 'or' => implode($w[$op], array_map($sub, $args)),
            'not' => sprintf($w['not'], self::text($args[0] ?? null, $w, $lang, $labels, false)),
            'if' => self::ifText($args, $w, $sub),
            '==', '!=', '<', '<=', '>', '>=', 'contains', 'starts_with', 'ends_with', 'add_days', 'days_between', 'in', 'any', 'all', 'sum'
                => sprintf($w[$op], $sub($args[0] ?? null), $sub($args[1] ?? null)),
            '+', '*' => implode(self::SYMBOLS[$op], array_map($sub, $args)),
            '-' => count($args) === 1 ? '−' . $sub($args[0]) : $sub($args[0]) . self::SYMBOLS['-'] . $sub($args[1] ?? null),
            '/' => $sub($args[0] ?? null) . self::SYMBOLS['/'] . $sub($args[1] ?? null),
            'round' => isset($args[1])
                ? sprintf($w['round.digits'], $sub($args[0]), $sub($args[1]))
                : sprintf($w['round'], $sub($args[0] ?? null)),
            'count' => isset($args[1])
                ? sprintf($w['count.where'], $sub($args[0]), $sub($args[1]))
                : sprintf($w['count'], $sub($args[0] ?? null)),
            'min', 'max', 'concat', 'coalesce', 'list' => sprintf($w[$op], $all($args)),
            'abs', 'length', 'lower', 'upper', 'is_empty' => sprintf($w[$op], $sub($args[0] ?? null)),
            default => '…',
        };

        $grouping = in_array($op, ['and', 'or', '+', '-', '*', '/'], true) && count($args) > 1;

        return $nested && $grouping ? '(' . $out . ')' : $out;
    }

    /**
     * @param list<mixed> $args
     * @param array<string, string> $w
     * @param callable(mixed): string $sub
     */
    private static function ifText(array $args, array $w, callable $sub): string
    {
        $parts = [];
        $last = count($args) - 1;
        for ($i = 0; $i < $last; $i += 2) {
            $parts[] = sprintf($w['if'], $sub($args[$i]), $sub($args[$i + 1] ?? null));
        }

        return implode($w['sep'], $parts) . sprintf($w['else'], $sub($args[$last] ?? null));
    }

    /**
     * @param array<string, array{en: string, ar: string}> $labels
     */
    private static function fieldLabel(string $name, string $lang, array $labels): string
    {
        $label = $labels[$name][$lang === 'ar' ? 'ar' : 'en'] ?? null;

        return is_string($label) && $label !== '' ? $label : str_replace('_', ' ', $name);
    }
}
