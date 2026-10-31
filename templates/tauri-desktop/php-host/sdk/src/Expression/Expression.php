<?php

declare(strict_types=1);

namespace Whity\Sdk\Expression;

/**
 * The manifest expression language: a JSON tree with a closed set of
 * operators (SDK 1.47, WHIT-759).
 *
 * Rules, computed fields, conditions and visibility in a manifest are written
 * in it. `{"field": "price"}` reads a field; `{">=": [{"field": "deposit"},
 * {"*": [{"field": "price"}, 0.3]}]}` is "deposit is at least 30% of price".
 *
 * WHY A TREE AND NOT A STRING: no string is ever parsed or evaluated as code,
 * so there is no injection to defend against, a person or a model writes it as
 * ordinary JSON, a diff between two versions is a tree diff, and the TypeScript
 * evaluator in `@whity-org/features/expressions` runs the same tree in a form
 * as somebody types. Both evaluators are held to one fixture set
 * (`sdk/resources/expression-fixtures.json`).
 *
 * WHY THIS IS SAFE TO LET A CUSTOMER WRITE: the only things an expression can
 * reach are in {@see ExpressionContext}. It has no loops, recursion,
 * user-defined functions or references outside the record, and it is bounded
 * three ways: {@see self::MAX_NODES} and {@see self::MAX_DEPTH} at validation,
 * and {@see self::MAX_STEPS} while evaluating, so a list of a million lines
 * stops with an error instead of a stalled request.
 *
 * GRAMMAR
 * -------
 * - A literal: `null`, `true`/`false`, a finite number, or a string of at most
 *   {@see self::MAX_STRING} characters. A bare JSON array is NOT a literal
 *   (it would be ambiguous with an argument list); write `{"list": [...]}`.
 * - An operation: an object with exactly one key, the operator, whose value is
 *   its argument list. A single argument may be written without the list:
 *   `{"field": "price"}` means `{"field": ["price"]}`.
 *
 * SEMANTICS, the same in PHP and TypeScript
 * -----------------------------------------
 * - A missing field reads as `null`. Arithmetic and text operations with a
 *   `null` argument give `null`; `<`, `<=`, `>`, `>=` with a `null` side give
 *   `false`; `==` and `!=` compare `null` like any other value.
 * - Conditions (`and`, `or`, `not`, `if`) take booleans; `null` counts as
 *   false; anything else is a type error.
 * - `==` is deep equality with no conversion: `1 == 1.0` is true, `1 == "1"` is
 *   false. Ordering works on two numbers or two strings (dates are
 *   `YYYY-MM-DD` strings, which order correctly as text).
 * - Division by zero gives `null`. `round` rounds half away from zero.
 * - Money is an integer of minor units, so arithmetic on it is exact until a
 *   multiplication by a fraction, which `round` brings back.
 * - Lengths count characters, not bytes.
 */
final class Expression
{
    public const MAX_NODES = 256;
    public const MAX_DEPTH = 24;
    public const MAX_STRING = 1000;
    public const MAX_STEPS = 10_000;

    /**
     * Operator => [min args, max args or -1 for unbounded].
     *
     * @var array<string, array{0: int, 1: int}>
     */
    public const OPERATORS = [
        // references
        'field' => [1, 1], 'item' => [0, 1], 'state' => [0, 0], 'today' => [0, 0], 'now' => [0, 0], 'has_role' => [1, 1],
        // logic
        'and' => [1, -1], 'or' => [1, -1], 'not' => [1, 1], 'if' => [3, -1],
        // comparison
        '==' => [2, 2], '!=' => [2, 2], '<' => [2, 2], '<=' => [2, 2], '>' => [2, 2], '>=' => [2, 2],
        // arithmetic
        '+' => [1, -1], '-' => [1, 2], '*' => [1, -1], '/' => [2, 2], 'min' => [1, -1], 'max' => [1, -1],
        'round' => [1, 2], 'abs' => [1, 1],
        // text
        'concat' => [1, -1], 'contains' => [2, 2], 'starts_with' => [2, 2], 'ends_with' => [2, 2],
        'length' => [1, 1], 'lower' => [1, 1], 'upper' => [1, 1],
        // dates
        'add_days' => [2, 2], 'days_between' => [2, 2],
        // lists and nulls
        'list' => [0, -1], 'in' => [2, 2], 'any' => [2, 2], 'all' => [2, 2], 'count' => [1, 2], 'sum' => [2, 2],
        'is_empty' => [1, 1], 'coalesce' => [1, -1],
    ];

    /** Operators whose second argument is evaluated once per list item. */
    private const ITEM_SCOPES = ['any', 'all', 'count', 'sum'];

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const DATE_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /** Static helper only — never instantiated. */
    private function __construct()
    {
    }

    /**
     * Check an expression without evaluating it: shape, operators, arity,
     * reference names, where `item` may appear, and the size caps.
     *
     * @return array{ok: bool, errors: list<string>}
     */
    public static function validate(mixed $expression): array
    {
        $errors = [];
        $nodes = 0;
        self::validateNode($expression, '$', 1, 0, $nodes, $errors);
        if ($nodes > self::MAX_NODES) {
            $errors[] = '$: more than ' . self::MAX_NODES . ' nodes';
        }

        return ['ok' => $errors === [], 'errors' => $errors];
    }

    /**
     * Evaluate a valid expression. Call {@see self::validate()} first (a
     * manifest is validated when it is published); evaluating an invalid tree
     * throws a type error at the first bad node.
     *
     * @throws ExpressionError
     */
    public static function evaluate(mixed $expression, ExpressionContext $context): mixed
    {
        $steps = 0;

        return self::run($expression, $context, [], $steps);
    }

    /**
     * @param list<string> $errors
     */
    private static function validateNode(mixed $node, string $path, int $depth, int $itemScopes, int &$nodes, array &$errors): void
    {
        $nodes++;
        if ($depth > self::MAX_DEPTH) {
            $errors[] = "{$path}: nested deeper than " . self::MAX_DEPTH;
            return;
        }
        if ($node === null || is_bool($node)) {
            return;
        }
        if (is_int($node) || is_float($node)) {
            if (is_float($node) && !is_finite($node)) {
                $errors[] = "{$path}: numbers must be finite";
            }
            return;
        }
        if (is_string($node)) {
            if (mb_strlen($node) > self::MAX_STRING) {
                $errors[] = "{$path}: text longer than " . self::MAX_STRING . ' characters';
            }
            return;
        }
        if (!is_array($node) || array_is_list($node)) {
            $errors[] = "{$path}: a bare list isn't an expression; write {\"list\": [...]}";
            return;
        }
        if (count($node) !== 1) {
            $errors[] = "{$path}: an operation has exactly one key, its operator";
            return;
        }
        $op = (string) array_key_first($node);
        if (!isset(self::OPERATORS[$op])) {
            $errors[] = "{$path}: unknown operator \"{$op}\"";
            return;
        }
        $args = self::args($node[$op]);
        [$min, $max] = self::OPERATORS[$op];
        $n = count($args);
        if ($n < $min || ($max !== -1 && $n > $max)) {
            $want = $max === -1 ? "at least {$min}" : ($min === $max ? (string) $min : "{$min} to {$max}");
            $errors[] = "{$path}.{$op}: takes {$want} argument(s), got {$n}";
            return;
        }

        switch ($op) {
            case 'field':
                if (!is_string($args[0]) || preg_match(self::NAME_PATTERN, $args[0]) !== 1) {
                    $errors[] = "{$path}.field: a field name in lowercase letters, digits and underscores";
                }
                return;
            case 'has_role':
                if (!is_string($args[0]) || preg_match(self::NAME_PATTERN, $args[0]) !== 1) {
                    $errors[] = "{$path}.has_role: a role name";
                }
                return;
            case 'item':
                if ($itemScopes === 0) {
                    $errors[] = "{$path}.item: only inside any, all, count or sum";
                }
                if ($n === 1 && $args[0] !== null && (!is_string($args[0]) || preg_match(self::NAME_PATTERN, $args[0]) !== 1)) {
                    $errors[] = "{$path}.item: a key name, or nothing for the item itself";
                }
                return;
            case 'state':
            case 'today':
            case 'now':
                return;
            case 'if':
                if ($n % 2 === 0) {
                    $errors[] = "{$path}.if: takes condition, value pairs and a final otherwise value (an odd count)";
                }
                break;
            case 'round':
                if ($n === 2 && (!self::isWhole($args[1]) || $args[1] < 0 || $args[1] > 6)) {
                    $errors[] = "{$path}.round: digits must be a whole number from 0 to 6";
                }
                break;
        }

        foreach ($args as $i => $arg) {
            $scope = in_array($op, self::ITEM_SCOPES, true) && $i === 1 ? $itemScopes + 1 : $itemScopes;
            self::validateNode($arg, "{$path}.{$op}[{$i}]", $depth + 1, $scope, $nodes, $errors);
        }
    }

    /**
     * @return list<mixed>
     */
    private static function args(mixed $raw): array
    {
        return is_array($raw) && array_is_list($raw) ? $raw : [$raw];
    }

    /**
     * @param list<mixed> $items The stack of current list items, innermost last.
     */
    private static function run(mixed $node, ExpressionContext $ctx, array $items, int &$steps): mixed
    {
        if (++$steps > self::MAX_STEPS) {
            throw new ExpressionError(ExpressionError::BUDGET, 'evaluation budget of ' . self::MAX_STEPS . ' steps exceeded');
        }
        if (!is_array($node)) {
            return $node;
        }
        if (array_is_list($node) || count($node) !== 1) {
            throw new ExpressionError(ExpressionError::TYPE, 'not an expression');
        }
        $op = (string) array_key_first($node);
        $args = self::args($node[$op]);
        // A closure, not an arrow function: the step counter must be shared by
        // reference, or nested arguments would each count from a copy and the
        // budget would never trip.
        $ev = static function (mixed $a) use ($ctx, $items, &$steps): mixed {
            return self::run($a, $ctx, $items, $steps);
        };

        switch ($op) {
            case 'field':
                return $ctx->fields[(string) $args[0]] ?? null;
            case 'item':
                $item = $items === [] ? null : $items[count($items) - 1];
                if (!isset($args[0])) {
                    return $item;
                }
                return is_array($item) && !array_is_list($item) ? ($item[(string) $args[0]] ?? null) : null;
            case 'state':
                return $ctx->state;
            case 'today':
                return $ctx->today;
            case 'now':
                return $ctx->now;
            case 'has_role':
                return in_array($args[0], $ctx->roles, true);

            case 'and':
                foreach ($args as $a) {
                    if (!self::truth($ev($a), 'and')) {
                        return false;
                    }
                }
                return true;
            case 'or':
                foreach ($args as $a) {
                    if (self::truth($ev($a), 'or')) {
                        return true;
                    }
                }
                return false;
            case 'not':
                return !self::truth($ev($args[0]), 'not');
            case 'if':
                $last = count($args) - 1;
                for ($i = 0; $i < $last; $i += 2) {
                    if (self::truth($ev($args[$i]), 'if')) {
                        return $ev($args[$i + 1]);
                    }
                }
                return $ev($args[$last]);

            case '==':
                return self::equal($ev($args[0]), $ev($args[1]));
            case '!=':
                return !self::equal($ev($args[0]), $ev($args[1]));
            case '<':
            case '<=':
            case '>':
            case '>=':
                return self::order($op, $ev($args[0]), $ev($args[1]));

            case '+':
            case '*':
            case 'min':
            case 'max':
                $values = array_map($ev, $args);
                if (in_array(null, $values, true)) {
                    return null;
                }
                foreach ($values as $v) {
                    self::number($v, $op);
                }
                /** @var list<int|float> $values */
                return match ($op) {
                    '+' => array_sum($values),
                    '*' => array_product($values),
                    'min' => min($values),
                    'max' => max($values),
                };
            case '-':
                $values = array_map($ev, $args);
                if (in_array(null, $values, true)) {
                    return null;
                }
                foreach ($values as $v) {
                    self::number($v, '-');
                }
                /** @var list<int|float> $values */
                return count($values) === 1 ? -$values[0] : $values[0] - $values[1];
            case '/':
                $a = $ev($args[0]);
                $b = $ev($args[1]);
                if ($a === null || $b === null) {
                    return null;
                }
                self::number($a, '/');
                self::number($b, '/');
                /** @var int|float $a */
                /** @var int|float $b */
                return $b == 0 ? null : $a / $b;
            case 'round':
                $v = $ev($args[0]);
                if ($v === null) {
                    return null;
                }
                self::number($v, 'round');
                /** @var int|float $v */
                $digits = (int) ($args[1] ?? 0);
                $scale = 10 ** $digits;
                $rounded = ($v < 0 ? -1 : 1) * floor(abs($v) * $scale + 0.5) / $scale;
                return $digits === 0 ? (int) $rounded : $rounded;
            case 'abs':
                $v = $ev($args[0]);
                if ($v === null) {
                    return null;
                }
                self::number($v, 'abs');
                /** @var int|float $v */
                return abs($v);

            case 'concat':
                $values = array_map($ev, $args);
                if (in_array(null, $values, true)) {
                    return null;
                }
                $out = '';
                foreach ($values as $v) {
                    $out .= self::text($v, 'concat');
                }
                return $out;
            case 'contains':
                $haystack = $ev($args[0]);
                $needle = $ev($args[1]);
                if ($haystack === null || $needle === null) {
                    return null;
                }
                if (is_array($haystack) && array_is_list($haystack)) {
                    foreach ($haystack as $h) {
                        if (self::equal($h, $needle)) {
                            return true;
                        }
                    }
                    return false;
                }
                return str_contains(self::stringOnly($haystack, 'contains'), self::stringOnly($needle, 'contains'));
            case 'starts_with':
            case 'ends_with':
                $a = $ev($args[0]);
                $b = $ev($args[1]);
                if ($a === null || $b === null) {
                    return null;
                }
                $a = self::stringOnly($a, $op);
                $b = self::stringOnly($b, $op);
                return $op === 'starts_with' ? str_starts_with($a, $b) : str_ends_with($a, $b);
            case 'length':
                $v = $ev($args[0]);
                if ($v === null) {
                    return null;
                }
                if (is_array($v) && array_is_list($v)) {
                    return count($v);
                }
                return mb_strlen(self::stringOnly($v, 'length'));
            case 'lower':
            case 'upper':
                $v = $ev($args[0]);
                if ($v === null) {
                    return null;
                }
                $v = self::stringOnly($v, $op);
                return $op === 'lower' ? mb_strtolower($v) : mb_strtoupper($v);

            case 'add_days':
                $date = $ev($args[0]);
                $days = $ev($args[1]);
                if ($date === null || $days === null) {
                    return null;
                }
                if (!self::isWhole($days)) {
                    throw new ExpressionError(ExpressionError::TYPE, 'add_days: the number of days must be a whole number');
                }
                /** @var int|float $days */
                return gmdate('Y-m-d', self::dayStamp($date, 'add_days') + (int) $days * 86400);
            case 'days_between':
                $a = $ev($args[0]);
                $b = $ev($args[1]);
                if ($a === null || $b === null) {
                    return null;
                }
                return intdiv(self::dayStamp($b, 'days_between') - self::dayStamp($a, 'days_between'), 86400);

            case 'list':
                return array_map($ev, $args);
            case 'in':
                $needle = $ev($args[0]);
                $list = $ev($args[1]);
                if ($list === null) {
                    return false;
                }
                foreach (self::listOnly($list, 'in') as $h) {
                    if (self::equal($h, $needle)) {
                        return true;
                    }
                }
                return false;
            case 'any':
            case 'all':
            case 'count':
            case 'sum':
                $list = $ev($args[0]);
                $list = $list === null ? [] : self::listOnly($list, $op);
                if ($op === 'count' && !isset($args[1])) {
                    return count($list);
                }
                $result = match ($op) {
                    'any' => false,
                    'all' => true,
                    default => 0,
                };
                foreach ($list as $item) {
                    $value = self::run($args[1], $ctx, [...$items, $item], $steps);
                    if ($op === 'sum') {
                        if ($value !== null) {
                            self::number($value, 'sum');
                            /** @var int|float $value */
                            $result += $value;
                        }
                        continue;
                    }
                    $hit = self::truth($value, $op);
                    if ($op === 'any' && $hit) {
                        return true;
                    }
                    if ($op === 'all' && !$hit) {
                        return false;
                    }
                    if ($op === 'count' && $hit) {
                        $result++;
                    }
                }
                return $result;
            case 'is_empty':
                $v = $ev($args[0]);
                return $v === null || $v === '' || $v === [];
            case 'coalesce':
                foreach ($args as $a) {
                    $v = $ev($a);
                    if ($v !== null) {
                        return $v;
                    }
                }
                return null;
        }

        throw new ExpressionError(ExpressionError::TYPE, "unknown operator \"{$op}\"");
    }

    /**
     * A whole number, whether JSON gave it as `2` or `2.0`: the two are the same
     * number in JSON and in TypeScript, so they must be here too.
     */
    private static function isWhole(mixed $value): bool
    {
        return is_int($value) || (is_float($value) && is_finite($value) && floor($value) === $value);
    }

    private static function truth(mixed $value, string $op): bool
    {
        if ($value === null) {
            return false;
        }
        if (!is_bool($value)) {
            throw new ExpressionError(ExpressionError::TYPE, "{$op}: a condition must be true or false");
        }

        return $value;
    }

    private static function equal(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (count($a) !== count($b) || array_is_list($a) !== array_is_list($b)) {
                return false;
            }
            foreach ($a as $k => $v) {
                if (!array_key_exists($k, $b) || !self::equal($v, $b[$k])) {
                    return false;
                }
            }
            return true;
        }

        return $a === $b;
    }

    private static function order(string $op, mixed $a, mixed $b): bool
    {
        if ($a === null || $b === null) {
            return false;
        }
        $numbers = (is_int($a) || is_float($a)) && (is_int($b) || is_float($b));
        if (!$numbers && !(is_string($a) && is_string($b))) {
            throw new ExpressionError(ExpressionError::TYPE, "{$op}: compares two numbers or two texts");
        }
        $cmp = $numbers ? $a <=> $b : strcmp($a, $b) <=> 0;

        return match ($op) {
            '<' => $cmp < 0,
            '<=' => $cmp <= 0,
            '>' => $cmp > 0,
            default => $cmp >= 0,
        };
    }

    private static function number(mixed $value, string $op): void
    {
        if (!is_int($value) && !is_float($value)) {
            throw new ExpressionError(ExpressionError::TYPE, "{$op}: expects numbers");
        }
    }

    private static function text(mixed $value, string $op): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_int($value) || is_float($value)) {
            return self::numberText($value);
        }
        throw new ExpressionError(ExpressionError::TYPE, "{$op}: expects text or numbers");
    }

    private static function stringOnly(mixed $value, string $op): string
    {
        if (!is_string($value)) {
            throw new ExpressionError(ExpressionError::TYPE, "{$op}: expects text");
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    private static function listOnly(mixed $value, string $op): array
    {
        if (!is_array($value) || !array_is_list($value)) {
            throw new ExpressionError(ExpressionError::TYPE, "{$op}: expects a list");
        }

        return $value;
    }

    private static function dayStamp(mixed $value, string $op): int
    {
        if (!is_string($value) || preg_match(self::DATE_PATTERN, $value) !== 1) {
            throw new ExpressionError(ExpressionError::TYPE, "{$op}: expects a date as YYYY-MM-DD");
        }
        [$y, $m, $d] = array_map('intval', explode('-', $value));
        if (!checkdate($m, $d, $y)) {
            throw new ExpressionError(ExpressionError::TYPE, "{$op}: \"{$value}\" isn't a real date");
        }

        return (int) gmmktime(0, 0, 0, $m, $d, $y);
    }

    /**
     * Numbers as text the way JavaScript's `String(n)` writes them for the
     * values money and counts take, so `concat` agrees across the two
     * evaluators: integers without a decimal point, other values with the
     * shortest form that round-trips.
     */
    private static function numberText(int|float $value): string
    {
        if (is_int($value) || (floor($value) === $value && abs($value) < 1e15)) {
            return (string) (int) $value;
        }

        // Shortest round-trip form, as JavaScript writes it (0.1 + 0.2 is
        // "0.30000000000000004" in both).
        return (string) json_encode($value, JSON_PRESERVE_ZERO_FRACTION);
    }
}
