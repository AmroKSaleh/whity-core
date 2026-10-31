<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The `tests` section of `plugin.json` (SDK 1.54, WHIT-764): examples a
 * record type's rules must keep, run against a version before it can be
 * published.
 *
 * ```json
 * "tests": [
 *   {
 *     "label": { "en": "A low deposit can't be confirmed", "ar": "لا يمكن تأكيد طلب بعربون قليل" },
 *     "record": "order",
 *     "given": { "price": 50000, "deposit": 10000 },
 *     "state": "draft",
 *     "move": "confirm",
 *     "expect": { "refused": true, "issues": ["deposit"] }
 *   },
 *   {
 *     "label": { "en": "The balance is worked out", "ar": "يُحسب المتبقي" },
 *     "record": "order",
 *     "create": { "customer": "Example Bakery", "price": 50000, "deposit": 20000, "pickup_on": "2026-11-03" },
 *     "expect": { "refused": false, "fields": { "balance": 30000 }, "state": "draft" }
 *   }
 * ]
 * ```
 *
 * A test does ONE thing to a record of `record`: `create` one, or, starting
 * from `given` (its fields as stored, in `state`), `update` some fields or
 * `move` it. `as` lists the role names the acting person holds, for rules
 * that ask `has_role`. `expect` says whether the write is refused, and may
 * name the fields refused (`issues`) or the fields and state it leaves.
 * Values are literals, not expressions: a test is an example.
 */
final class ManifestTestValidator
{
    public const MAX_TESTS = 100;

    private function __construct()
    {
    }

    /**
     * @param mixed $records The manifest's `records` section (read defensively).
     * @param list<string> $roles Role names this manifest declares.
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    public static function check(mixed $tests, mixed $records, array $roles, callable $label, array &$errors): void
    {
        if ($tests === null) {
            return;
        }
        if (!is_array($tests) || !array_is_list($tests)) {
            $errors[] = 'tests: must be a list';
            return;
        }
        if (count($tests) > self::MAX_TESTS) {
            $errors[] = 'tests: at most ' . self::MAX_TESTS;
        }
        $records = is_array($records) ? $records : [];

        foreach ($tests as $i => $test) {
            $path = "tests[{$i}]";
            if (!is_array($test) || array_is_list($test)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            self::onlyKeys($test, ['label', 'record', 'as', 'given', 'state', 'create', 'update', 'move', 'expect'], $path, $errors);
            $label($test['label'] ?? null, "{$path}.label", 200, true, $errors);

            $type = $test['record'] ?? null;
            $decl = is_string($type) && is_array($records[$type] ?? null) ? $records[$type] : null;
            if ($decl === null) {
                $errors[] = "{$path}.record: required, a record type of this manifest";
                continue;
            }
            $fields = is_array($decl['fields'] ?? null) ? $decl['fields'] : [];
            $states = self::states($decl);

            if (isset($test['as'])) {
                if (!is_array($test['as']) || !array_is_list($test['as'])) {
                    $errors[] = "{$path}.as: a list of role names";
                } else {
                    foreach ($test['as'] as $role) {
                        if (!is_string($role) || !in_array($role, $roles, true)) {
                            $errors[] = "{$path}.as: \"" . (is_string($role) ? $role : gettype($role)) . '" isn\'t a role this manifest declares';
                        }
                    }
                }
            }

            $actions = array_values(array_intersect(['create', 'update', 'move'], array_keys($test)));
            if (count($actions) !== 1) {
                $errors[] = "{$path}: do exactly one of create, update or move";
                continue;
            }
            $action = $actions[0];
            if ($action === 'create') {
                foreach (['given', 'state'] as $key) {
                    if (array_key_exists($key, $test)) {
                        $errors[] = "{$path}.{$key}: a create starts from nothing";
                    }
                }
                self::checkValues($test['create'], "{$path}.create", $fields, $errors);
            } else {
                self::checkValues($test['given'] ?? [], "{$path}.given", $fields, $errors);
                if (isset($test['state']) && (!is_string($test['state']) || !in_array($test['state'], $states, true))) {
                    $errors[] = "{$path}.state: must be a state of {$type}";
                }
                if ($action === 'update') {
                    self::checkValues($test['update'], "{$path}.update", $fields, $errors);
                } else {
                    $moves = is_array($decl['lifecycle']['moves'] ?? null) ? $decl['lifecycle']['moves'] : [];
                    if (!is_string($test['move']) || !isset($moves[$test['move']])) {
                        $errors[] = "{$path}.move: must be a move of {$type}";
                    }
                }
            }

            $expect = $test['expect'] ?? null;
            if (!is_array($expect) || array_is_list($expect) || !is_bool($expect['refused'] ?? null)) {
                $errors[] = "{$path}.expect: required, with refused true or false";
                continue;
            }
            self::onlyKeys($expect, ['refused', 'issues', 'fields', 'state'], "{$path}.expect", $errors);
            if (isset($expect['issues'])) {
                if ($expect['refused'] !== true || !is_array($expect['issues']) || !array_is_list($expect['issues'])) {
                    $errors[] = "{$path}.expect.issues: a list of field names, for a refused write";
                } else {
                    foreach ($expect['issues'] as $field) {
                        if (!is_string($field) || !isset($fields[$field])) {
                            $errors[] = "{$path}.expect.issues: \"" . (is_string($field) ? $field : gettype($field)) . "\" isn't a field of {$type}";
                        }
                    }
                }
            }
            foreach (['fields', 'state'] as $key) {
                if (isset($expect[$key]) && $expect['refused'] === true) {
                    $errors[] = "{$path}.expect.{$key}: a refused write leaves nothing to check";
                }
            }
            if (isset($expect['fields'])) {
                self::checkValues($expect['fields'], "{$path}.expect.fields", $fields, $errors);
            }
            if (isset($expect['state']) && (!is_string($expect['state']) || !in_array($expect['state'], $states, true))) {
                $errors[] = "{$path}.expect.state: must be a state of {$type}";
            }
        }
    }

    /**
     * Literal values for fields of the record type.
     *
     * @param array<mixed> $fields
     * @param list<string> $errors
     */
    private static function checkValues(mixed $values, string $path, array $fields, array &$errors): void
    {
        if (!is_array($values) || array_is_list($values) && $values !== []) {
            $errors[] = "{$path}: an object of field name to value";
            return;
        }
        foreach ($values as $name => $value) {
            if (!isset($fields[$name])) {
                $errors[] = "{$path}.{$name}: isn't a field of the record type";
            } elseif (is_array($value) && !array_is_list($value)) {
                $errors[] = "{$path}.{$name}: a test's values are literals, not expressions";
            }
        }
    }

    /**
     * @param array<mixed> $decl
     * @return list<string>
     */
    private static function states(array $decl): array
    {
        $keys = [];
        foreach ($decl['lifecycle']['states'] ?? [] as $state) {
            if (is_array($state) && is_string($state['key'] ?? null)) {
                $keys[] = $state['key'];
            }
        }

        return $keys;
    }

    /**
     * @param array<mixed> $object
     * @param list<string> $allowed
     * @param list<string> $errors
     */
    private static function onlyKeys(array $object, array $allowed, string $path, array &$errors): void
    {
        foreach (array_keys($object) as $key) {
            if (!in_array($key, $allowed, true)) {
                $errors[] = "{$path}.{$key}: unknown key (this SDK can't run it, so it is refused rather than ignored)";
            }
        }
    }
}
