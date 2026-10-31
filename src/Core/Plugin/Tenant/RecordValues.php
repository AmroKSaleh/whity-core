<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use DateTimeImmutable;
use DateTimeZone;
use Exception;

/**
 * Checks a record's field values against its record type's declaration
 * (WHIT-761), and normalises them for storage.
 *
 * Every write to the record store goes through here, whichever door it came
 * in by, so the manifest is the one place a field's rules live.
 *
 * Normalised forms: money is an integer of minor units (JOD has 3 decimals,
 * so 12.500 JOD is `12500`; `min`/`max` on money are minor units too); dates
 * `YYYY-MM-DD`; date-times ISO 8601 in UTC with a `Z`; links (`person`, `ou`,
 * `record`) positive integer ids. `null` clears a field that isn't required.
 */
final class RecordValues
{
    private const TEXT_MAX = 1000;
    private const LONG_TEXT_MAX = 20000;

    private function __construct()
    {
    }

    /**
     * @param array<string, array<string, mixed>> $fields The record type's fields.
     * @param array<mixed> $values Incoming values.
     * @param bool $partial True for an update: only the given fields change,
     *        and a required field may be left out (but not cleared).
     * @return array{values: array<string, mixed>, issues: list<array{severity: string, message: string, column: string}>}
     */
    public static function check(array $fields, array $values, bool $partial): array
    {
        $out = [];
        $issues = [];
        $issue = static function (string $column, string $message) use (&$issues): void {
            $issues[] = ['severity' => 'error', 'message' => $message, 'column' => $column];
        };

        foreach ($values as $name => $value) {
            $name = (string) $name;
            if (!isset($fields[$name])) {
                $issue($name, "\"{$name}\" isn't a field of this record type");
                continue;
            }
            $field = $fields[$name];
            if ($value === null) {
                if (($field['required'] ?? false) === true) {
                    $issue($name, 'This field is required');
                    continue;
                }
                $out[$name] = null;
                continue;
            }
            $result = self::normalise($field, $value);
            if (is_string($result['error'])) {
                $issue($name, $result['error']);
                continue;
            }
            $out[$name] = $result['value'];
        }

        if (!$partial) {
            foreach ($fields as $name => $field) {
                if (($field['required'] ?? false) === true && !array_key_exists($name, $out) && !self::hasIssue($issues, $name)) {
                    $issue($name, 'This field is required');
                }
            }
        }

        return ['values' => $out, 'issues' => $issues];
    }

    /**
     * @param array<string, mixed> $field
     * @return array{value: mixed, error: string|null}
     */
    private static function normalise(array $field, mixed $value): array
    {
        $ok = static fn (mixed $v): array => ['value' => $v, 'error' => null];
        $bad = static fn (string $e): array => ['value' => null, 'error' => $e];

        switch ($field['type'] ?? null) {
            case 'text':
            case 'long_text':
                if (!is_string($value)) {
                    return $bad('Must be text');
                }
                $max = (int) ($field['maxLength'] ?? ($field['type'] === 'text' ? self::TEXT_MAX : self::LONG_TEXT_MAX));
                return mb_strlen($value) > $max ? $bad("At most {$max} characters") : $ok($value);
            case 'number':
            case 'money':
                $isMoney = $field['type'] === 'money';
                if ($isMoney ? !is_int($value) : (!is_int($value) && !is_float($value)) || (is_float($value) && !is_finite($value))) {
                    return $bad($isMoney ? 'Must be a whole number of minor units' : 'Must be a number');
                }
                if (isset($field['min']) && $value < $field['min']) {
                    return $bad('At least ' . $field['min']);
                }
                if (isset($field['max']) && $value > $field['max']) {
                    return $bad('At most ' . $field['max']);
                }
                return $ok($value);
            case 'date':
                if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return $bad('Must be a date as YYYY-MM-DD');
                }
                return $ok($value);
            case 'datetime':
                if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
                    return $bad('Must be a date and time in ISO 8601 with a time zone');
                }
                try {
                    $at = new DateTimeImmutable($value);
                } catch (Exception) {
                    return $bad('Must be a real date and time');
                }
                return $ok($at->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'));
            case 'boolean':
                return is_bool($value) ? $ok($value) : $bad('Must be true or false');
            case 'choice':
                return is_string($value) && in_array($value, self::optionValues($field), true)
                    ? $ok($value)
                    : $bad('Must be one of: ' . implode(', ', self::optionValues($field)));
            case 'multi_choice':
                if (!is_array($value) || !array_is_list($value)) {
                    return $bad('Must be a list');
                }
                $allowed = self::optionValues($field);
                foreach ($value as $v) {
                    if (!is_string($v) || !in_array($v, $allowed, true)) {
                        return $bad('Each must be one of: ' . implode(', ', $allowed));
                    }
                }
                return $ok(array_values(array_unique($value)));
            case 'person':
            case 'ou':
            case 'record':
                return is_int($value) && $value > 0 ? $ok($value) : $bad('Must be an id');
        }

        return $bad('This field has an unknown type');
    }

    /**
     * @param array<string, mixed> $field
     * @return list<string>
     */
    private static function optionValues(array $field): array
    {
        $values = [];
        foreach (is_array($field['options'] ?? null) ? $field['options'] : [] as $option) {
            if (is_array($option) && is_string($option['value'] ?? null)) {
                $values[] = $option['value'];
            }
        }

        return $values;
    }

    /**
     * @param list<array{severity: string, message: string, column: string}> $issues
     */
    private static function hasIssue(array $issues, string $column): bool
    {
        foreach ($issues as $issue) {
            if ($issue['column'] === $column) {
                return true;
            }
        }

        return false;
    }
}
