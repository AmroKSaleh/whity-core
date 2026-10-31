<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

use Whity\Sdk\Expression\Expression;

/**
 * The `records` section of `plugin.json` (SDK 1.50, WHIT-761): record types a
 * plugin keeps in the host's shared record store instead of its own tables.
 *
 * ```json
 * "records": {
 *   "order": {
 *     "label": { "en": "Cake order", "ar": "طلب كيك" },
 *     "titleField": "customer",
 *     "fields": {
 *       "customer":  { "type": "text", "label": {...}, "required": true },
 *       "cake_type": { "type": "choice", "label": {...}, "options": [{ "value": "wedding", "label": {...} }] },
 *       "price":     { "type": "money", "label": {...}, "currency": "JOD" },
 *       "pickup_on": { "type": "date", "label": {...} }
 *     },
 *     "lifecycle": {
 *       "states": [{ "key": "draft", "label": {...} }, { "key": "confirmed", "label": {...} }],
 *       "initial": "draft",
 *       "moves": { "confirm": { "from": ["draft"], "to": "confirmed", "permission": "cakeorders:manage", "label": {...} } }
 *     },
 *     "permissions": { "read": "cakeorders:read", "create": "cakeorders:manage", "edit": "cakeorders:manage", "delete": "cakeorders:manage" }
 *   }
 * }
 * ```
 *
 * Every permission named must be one the manifest declares, so a record type
 * can't be gated by somebody else's slug. All four actions must be gated:
 * there is no public record type.
 */
final class RecordTypeValidator
{
    /** Field types the record store understands. */
    public const FIELD_TYPES = [
        'text', 'long_text', 'number', 'money', 'date', 'datetime', 'boolean',
        'choice', 'multi_choice', 'person', 'ou', 'record',
    ];

    /** Names the store uses for its own columns. */
    public const RESERVED_FIELDS = ['id', 'state', 'created_at', 'updated_at', 'created_by', 'updated_by'];

    public const ACTIONS = ['read', 'create', 'edit', 'delete'];

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const MAX_TYPES = 50;
    private const MAX_FIELDS = 100;
    private const MAX_OPTIONS = 200;
    private const MAX_STATES = 30;

    private function __construct()
    {
    }

    /**
     * @param array<string, true> $permissions Declared permission slugs.
     * @param callable(mixed, string, int, bool, list<string>&): void $label The
     *        manifest's label check (English and Arabic required).
     * @param list<string> $errors
     */
    public static function check(mixed $records, array $permissions, callable $label, array &$errors): void
    {
        if ($records === null) {
            return;
        }
        if (!is_array($records) || array_is_list($records) && $records !== []) {
            $errors[] = 'records: must be an object of record type name to declaration';
            return;
        }
        if (count($records) > self::MAX_TYPES) {
            $errors[] = 'records: at most ' . self::MAX_TYPES . ' record types';
        }
        $typeNames = array_map('strval', array_keys($records));

        foreach ($records as $name => $type) {
            $path = "records.{$name}";
            if (preg_match(self::NAME_PATTERN, (string) $name) !== 1) {
                $errors[] = "{$path}: record type names are lowercase letters, digits and underscores";
            }
            if (!is_array($type)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            self::onlyKeys($type, ['label', 'titleField', 'fields', 'lifecycle', 'permissions', 'rules'], $path, $errors);
            $label($type['label'] ?? null, "{$path}.label", 200, true, $errors);

            $fields = $type['fields'] ?? null;
            if (!is_array($fields) || array_is_list($fields)) {
                $errors[] = "{$path}.fields: required, an object of field name to field";
                $fields = [];
            }
            if (count($fields) > self::MAX_FIELDS) {
                $errors[] = "{$path}.fields: at most " . self::MAX_FIELDS;
            }
            foreach ($fields as $fieldName => $field) {
                self::checkField((string) $fieldName, $field, "{$path}.fields.{$fieldName}", $typeNames, $label, $errors);
            }

            if (isset($type['titleField']) && (!is_string($type['titleField']) || !isset($fields[$type['titleField']]))) {
                $errors[] = "{$path}.titleField: must name one of this record type's fields";
            }

            $gates = $type['permissions'] ?? null;
            if (!is_array($gates)) {
                $errors[] = "{$path}.permissions: required, with read, create, edit and delete";
                $gates = [];
            } else {
                self::onlyKeys($gates, self::ACTIONS, "{$path}.permissions", $errors);
            }
            foreach (self::ACTIONS as $action) {
                $slug = $gates[$action] ?? null;
                if (!is_string($slug) || !isset($permissions[$slug])) {
                    $errors[] = "{$path}.permissions.{$action}: required, a permission this manifest declares";
                }
            }

            if (isset($type['lifecycle'])) {
                self::checkLifecycle($type['lifecycle'], "{$path}.lifecycle", $permissions, $label, $errors);
            }

            $fieldNames = array_map('strval', array_keys($fields));
            foreach ($fields as $fieldName => $field) {
                if (!is_array($field)) {
                    continue;
                }
                foreach (['computed', 'default', 'lockedWhen'] as $key) {
                    if (array_key_exists($key, $field)) {
                        self::checkExpression($field[$key], "{$path}.fields.{$fieldName}.{$key}", $fieldNames, $errors);
                    }
                }
                if (isset($field['computed']) && (isset($field['default']) || ($field['required'] ?? false) === true)) {
                    $errors[] = "{$path}.fields.{$fieldName}.computed: a computed field has no default and isn't required; it is always calculated";
                }
            }
            self::checkRules($type['rules'] ?? null, "{$path}.rules", $fieldNames, $label, $errors);
        }
    }

    /**
     * @param list<string> $typeNames
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    private static function checkField(string $name, mixed $field, string $path, array $typeNames, callable $label, array &$errors): void
    {
        if (preg_match(self::NAME_PATTERN, $name) !== 1) {
            $errors[] = "{$path}: field names are lowercase letters, digits and underscores";
        }
        if (in_array($name, self::RESERVED_FIELDS, true)) {
            $errors[] = "{$path}: \"{$name}\" is reserved for the record store";
        }
        if (!is_array($field)) {
            $errors[] = "{$path}: must be an object";
            return;
        }
        self::onlyKeys($field, ['type', 'label', 'required', 'options', 'min', 'max', 'maxLength', 'currency', 'record', 'computed', 'default', 'lockedWhen'], $path, $errors);
        $type = $field['type'] ?? null;
        if (!in_array($type, self::FIELD_TYPES, true)) {
            $errors[] = "{$path}.type: one of " . implode(', ', self::FIELD_TYPES);
            return;
        }
        $label($field['label'] ?? null, "{$path}.label", 200, true, $errors);
        if (isset($field['required']) && !is_bool($field['required'])) {
            $errors[] = "{$path}.required: true or false";
        }
        foreach (['min', 'max'] as $bound) {
            if (isset($field[$bound]) && (!in_array($type, ['number', 'money'], true) || (!is_int($field[$bound]) && !is_float($field[$bound])))) {
                $errors[] = "{$path}.{$bound}: a number, on number and money fields only";
            }
        }
        if (isset($field['maxLength']) && (!in_array($type, ['text', 'long_text'], true) || !is_int($field['maxLength']) || $field['maxLength'] < 1)) {
            $errors[] = "{$path}.maxLength: a positive whole number, on text fields only";
        }
        if ($type === 'money') {
            if (!is_string($field['currency'] ?? null) || preg_match('/^[A-Z]{3}$/', $field['currency']) !== 1) {
                $errors[] = "{$path}.currency: required on money, an ISO 4217 code such as \"JOD\"";
            }
        } elseif (isset($field['currency'])) {
            $errors[] = "{$path}.currency: on money fields only";
        }
        if ($type === 'record') {
            if (!is_string($field['record'] ?? null) || !in_array($field['record'], $typeNames, true)) {
                $errors[] = "{$path}.record: required on a record link, naming a record type this plugin declares";
            }
        } elseif (isset($field['record'])) {
            $errors[] = "{$path}.record: on record links only";
        }
        if ($type === 'choice' || $type === 'multi_choice') {
            $options = $field['options'] ?? null;
            if (!is_array($options) || !array_is_list($options) || $options === []) {
                $errors[] = "{$path}.options: required, a non-empty list";
                return;
            }
            if (count($options) > self::MAX_OPTIONS) {
                $errors[] = "{$path}.options: at most " . self::MAX_OPTIONS;
            }
            $seen = [];
            foreach ($options as $i => $option) {
                $value = is_array($option) ? ($option['value'] ?? null) : null;
                if (!is_string($value) || preg_match(self::NAME_PATTERN, $value) !== 1) {
                    $errors[] = "{$path}.options[{$i}].value: lowercase letters, digits and underscores";
                    continue;
                }
                if (isset($seen[$value])) {
                    $errors[] = "{$path}.options[{$i}].value: \"{$value}\" is used twice";
                }
                $seen[$value] = true;
                $label($option['label'] ?? null, "{$path}.options[{$i}].label", 200, true, $errors);
            }
        } elseif (isset($field['options'])) {
            $errors[] = "{$path}.options: on choice fields only";
        }
    }

    /**
     * @param array<string, true> $permissions
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    private static function checkLifecycle(mixed $lifecycle, string $path, array $permissions, callable $label, array &$errors): void
    {
        if (!is_array($lifecycle)) {
            $errors[] = "{$path}: must be an object";
            return;
        }
        self::onlyKeys($lifecycle, ['states', 'initial', 'moves'], $path, $errors);
        $states = $lifecycle['states'] ?? null;
        $keys = [];
        if (!is_array($states) || !array_is_list($states) || $states === []) {
            $errors[] = "{$path}.states: required, a non-empty list";
            $states = [];
        }
        if (count($states) > self::MAX_STATES) {
            $errors[] = "{$path}.states: at most " . self::MAX_STATES;
        }
        foreach ($states as $i => $state) {
            $key = is_array($state) ? ($state['key'] ?? null) : null;
            if (!is_string($key) || preg_match(self::NAME_PATTERN, $key) !== 1) {
                $errors[] = "{$path}.states[{$i}].key: lowercase letters, digits and underscores";
                continue;
            }
            if (isset($keys[$key])) {
                $errors[] = "{$path}.states[{$i}].key: \"{$key}\" is used twice";
            }
            $keys[$key] = true;
            $label($state['label'] ?? null, "{$path}.states[{$i}].label", 200, true, $errors);
        }
        if (!is_string($lifecycle['initial'] ?? null) || !isset($keys[$lifecycle['initial']])) {
            $errors[] = "{$path}.initial: required, one of the declared states";
        }
        $moves = $lifecycle['moves'] ?? [];
        if (!is_array($moves) || array_is_list($moves) && $moves !== []) {
            $errors[] = "{$path}.moves: an object of move name to move";
            return;
        }
        foreach ($moves as $name => $move) {
            $movePath = "{$path}.moves.{$name}";
            if (preg_match(self::NAME_PATTERN, (string) $name) !== 1) {
                $errors[] = "{$movePath}: move names are lowercase letters, digits and underscores";
            }
            if (!is_array($move)) {
                $errors[] = "{$movePath}: must be an object";
                continue;
            }
            self::onlyKeys($move, ['from', 'to', 'permission', 'label'], $movePath, $errors);
            $from = $move['from'] ?? null;
            $from = is_string($from) ? [$from] : $from;
            if (!is_array($from) || $from === []) {
                $errors[] = "{$movePath}.from: required, a state or a list of states";
            } else {
                foreach ($from as $state) {
                    if (!is_string($state) || !isset($keys[$state])) {
                        $errors[] = "{$movePath}.from: \"" . (is_string($state) ? $state : gettype($state)) . '" isn\'t a declared state';
                    }
                }
            }
            if (!is_string($move['to'] ?? null) || !isset($keys[$move['to']])) {
                $errors[] = "{$movePath}.to: required, one of the declared states";
            }
            if (!is_string($move['permission'] ?? null) || !isset($permissions[$move['permission']])) {
                $errors[] = "{$movePath}.permission: required, a permission this manifest declares";
            }
            $label($move['label'] ?? null, "{$movePath}.label", 200, true, $errors);
        }
    }

    /**
     * Rules refuse a write: `require` must hold, when `when` does (or always).
     *
     * @param list<string> $fieldNames
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    private static function checkRules(mixed $rules, string $path, array $fieldNames, callable $label, array &$errors): void
    {
        if ($rules === null) {
            return;
        }
        if (!is_array($rules) || !array_is_list($rules)) {
            $errors[] = "{$path}: must be a list";
            return;
        }
        if (count($rules) > 100) {
            $errors[] = "{$path}: at most 100";
        }
        foreach ($rules as $i => $rule) {
            $rulePath = "{$path}[{$i}]";
            if (!is_array($rule)) {
                $errors[] = "{$rulePath}: must be an object";
                continue;
            }
            self::onlyKeys($rule, ['when', 'require', 'message', 'field'], $rulePath, $errors);
            if (!array_key_exists('require', $rule)) {
                $errors[] = "{$rulePath}.require: required, an expression that must hold";
            } else {
                self::checkExpression($rule['require'], "{$rulePath}.require", $fieldNames, $errors);
            }
            if (array_key_exists('when', $rule)) {
                self::checkExpression($rule['when'], "{$rulePath}.when", $fieldNames, $errors);
            }
            if (isset($rule['field']) && (!is_string($rule['field']) || !in_array($rule['field'], $fieldNames, true))) {
                $errors[] = "{$rulePath}.field: must name one of this record type's fields";
            }
            $label($rule['message'] ?? null, "{$rulePath}.message", 500, true, $errors);
        }
    }

    /**
     * A valid expression whose `field` references are this record type's
     * fields. Shared with {@see AutomationValidator}.
     *
     * @param list<string> $fieldNames
     * @param list<string> $errors
     */
    public static function checkExpression(mixed $expression, string $path, array $fieldNames, array &$errors): void
    {
        foreach (Expression::validate($expression)['errors'] as $error) {
            $errors[] = "{$path}: {$error}";
        }
        foreach (self::fieldRefs($expression) as $ref) {
            if (!in_array($ref, $fieldNames, true)) {
                $errors[] = "{$path}: refers to \"{$ref}\", which isn't a field of this record type";
            }
        }
    }

    /**
     * @return list<string>
     */
    private static function fieldRefs(mixed $node): array
    {
        if (!is_array($node)) {
            return [];
        }
        $refs = [];
        if (!array_is_list($node) && count($node) === 1 && array_key_first($node) === 'field') {
            $arg = $node['field'];
            $name = is_array($arg) ? ($arg[0] ?? null) : $arg;
            return is_string($name) ? [$name] : [];
        }
        foreach ($node as $child) {
            $refs = [...$refs, ...self::fieldRefs($child)];
        }

        return $refs;
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
