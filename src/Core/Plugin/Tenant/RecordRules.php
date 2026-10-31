<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use Whity\Sdk\Expression\Expression;
use Whity\Sdk\Expression\ExpressionContext;
use Whity\Sdk\Expression\ExpressionError;

/**
 * A record type's rules on the save path (Whity Builder, WHIT-762;
 * docs/design/WHITY-BUILDER.md 2.1 and 2.4).
 *
 * Every write to a workspace plugin's record goes through here, whichever door
 * it came in by, so the rules a manifest declares hold for a person in a form,
 * an API call and, later, an automation step or an agent:
 *
 *  - `default` (create only): an expression filling a field the write left out;
 *  - field values checked against the declaration ({@see RecordValues});
 *  - `lockedWhen`: an expression over the record AS IT IS; while it holds, the
 *    field can't be changed ("price can't change once confirmed");
 *  - `computed`: an expression over the record AS IT WILL BE, recalculated on
 *    every write and never written by the caller ("balance = price − deposit");
 *  - `rules`: `require` must hold over the record as it will be, when `when`
 *    does ("a confirmed order needs a deposit of at least 30%"). A move is
 *    checked with the record in its new state.
 *
 * FAILS CLOSED. An expression that can't be evaluated on these values (a type
 * error, the step budget) refuses the write with an issue rather than letting
 * an unchecked record through.
 */
final class RecordRules
{
    /**
     * Check and build a record's data for a create (`$existing` null) or an
     * update.
     *
     * @param array<string, mixed> $decl The record type's declaration.
     * @param array<mixed> $body The caller's values.
     * @param array{state: ?string, data: array<string, mixed>}|null $existing
     * @param list<string> $roles The actor's role names.
     * @return array{data: array<string, mixed>, issues: list<array<string, string>>}
     */
    public static function prepare(array $decl, array $body, ?array $existing, ?string $state, array $roles, string $today, string $now): array
    {
        /** @var array<string, array<string, mixed>> $fields */
        $fields = $decl['fields'] ?? [];
        $issues = [];
        $context = static fn (array $data, ?string $s): ExpressionContext => new ExpressionContext($data, $s, $roles, $today, $now);

        foreach ($body as $name => $_) {
            if (isset($fields[$name]['computed'])) {
                $issues[] = self::issue((string) $name, 'This field is calculated and can\'t be set', 'هذا الحقل محسوب ولا يمكن تعيينه');
                unset($body[$name]);
            }
        }

        if ($existing === null) {
            foreach ($fields as $name => $field) {
                if (array_key_exists('default', $field) && !array_key_exists($name, $body)) {
                    $value = self::evaluate($field['default'], $context($body, $state), $name, $issues);
                    if ($value !== null) {
                        $body[$name] = $value;
                    }
                }
            }
        } else {
            foreach ($body as $name => $value) {
                $lock = $fields[$name]['lockedWhen'] ?? null;
                if ($lock !== null && self::evaluate($lock, $context($existing['data'], $existing['state']), (string) $name, $issues) === true
                    && ($existing['data'][$name] ?? null) !== $value) {
                    $issues[] = self::issue((string) $name, 'This field can\'t be changed now', 'لا يمكن تغيير هذا الحقل الآن');
                    unset($body[$name]);
                }
            }
        }

        $editable = array_filter($fields, static fn (array $f): bool => !isset($f['computed']));
        $checked = RecordValues::check($editable, $body, $existing !== null);
        foreach ($checked['issues'] as $issue) {
            $issues[] = $issue;
        }
        $data = array_merge($existing['data'] ?? [], $checked['values']);

        foreach ($fields as $name => $field) {
            if (!isset($field['computed'])) {
                continue;
            }
            $value = self::evaluate($field['computed'], $context($data, $state), $name, $issues);
            if ($value === null) {
                $data[$name] = null;
                continue;
            }
            $normalised = RecordValues::check([$name => array_diff_key($field, ['required' => true])], [$name => $value], true);
            if ($normalised['issues'] !== []) {
                $issues[] = self::issue($name, 'The calculated value doesn\'t fit this field', 'القيمة المحسوبة لا تناسب هذا الحقل');
                continue;
            }
            $data[$name] = $normalised['values'][$name];
        }

        if ($issues === []) {
            $issues = self::checkRules($decl, $context($data, $state));
        }

        return ['data' => $data, 'issues' => $issues];
    }

    /**
     * The rules for a move: the record as it is, in its new state.
     *
     * @param array<string, mixed> $decl
     * @param array<string, mixed> $data
     * @param list<string> $roles
     * @return list<array<string, string>>
     */
    public static function checkMove(array $decl, array $data, string $toState, array $roles, string $today, string $now): array
    {
        return self::checkRules($decl, new ExpressionContext($data, $toState, $roles, $today, $now));
    }

    /**
     * @param array<string, mixed> $decl
     * @return list<array<string, string>>
     */
    private static function checkRules(array $decl, ExpressionContext $context): array
    {
        $issues = [];
        foreach (is_array($decl['rules'] ?? null) ? $decl['rules'] : [] as $rule) {
            $column = is_string($rule['field'] ?? null) ? $rule['field'] : '';
            if (array_key_exists('when', $rule) && self::evaluate($rule['when'], $context, $column, $issues) !== true) {
                continue;
            }
            if (self::evaluate($rule['require'], $context, $column, $issues) !== true) {
                $issues[] = self::issue($column, (string) ($rule['message']['en'] ?? ''), (string) ($rule['message']['ar'] ?? ''));
            }
        }

        return $issues;
    }

    /**
     * @param list<array<string, string>> $issues
     */
    private static function evaluate(mixed $expression, ExpressionContext $context, string $column, array &$issues): mixed
    {
        try {
            return Expression::evaluate($expression, $context);
        } catch (ExpressionError) {
            $issues[] = self::issue($column, 'This rule couldn\'t be checked on these values', 'تعذّر التحقق من هذه القاعدة على هذه القيم');
            return null;
        }
    }

    /** @return array<string, string> */
    private static function issue(string $column, string $message, string $messageAr): array
    {
        return ['severity' => 'error', 'message' => $message, 'messageAr' => $messageAr, 'column' => $column];
    }
}
