<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The `automations` section of `plugin.json` (SDK 1.53, WHIT-763): what a
 * workspace plugin does by itself when its records change.
 *
 * ```json
 * "automations": {
 *   "confirmation": {
 *     "label": { "en": "Tell the bakers", "ar": "إبلاغ الخبازين" },
 *     "on": "order.moved.confirmed",
 *     "when": { ">": [{ "field": "weight_kg" }, 0] },
 *     "steps": [
 *       { "set_fields": { "values": { "confirmed_on": { "today": [] } } } },
 *       { "create_record": { "type": "task", "values": { "title": { "field": "customer" } }, "link": "order" } },
 *       { "notify": { "role": "baker", "message": { "en": "New order for {{pickup_on}}", "ar": "طلب جديد ليوم {{pickup_on}}" } } }
 *     ]
 *   }
 * }
 * ```
 *
 * TRIGGERS name a record type of this manifest and an event:
 * `<type>.created`, `<type>.changed`, `<type>.changed.<field>`,
 * `<type>.moved` and `<type>.moved.<state>` (moved INTO that state).
 *
 * STEPS are a closed catalogue. Values are expressions over the triggering
 * record. Steps that need services this host doesn't run yet (documents,
 * webhooks, approvals, waits, other plugins' actions) are named and refused
 * rather than accepted and silently skipped.
 */
final class AutomationValidator
{
    /** The steps this SDK runs. */
    public const STEPS = ['set_fields', 'create_record', 'move', 'notify'];

    /** Steps of the designed catalogue that arrive in a later SDK. */
    public const LATER_STEPS = ['issue_document', 'send_webhook', 'ask_approval', 'wait', 'call_action'];

    public const MAX_AUTOMATIONS = 50;
    public const MAX_STEPS = 20;

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';
    private const TRIGGER_PATTERN = '/^([a-z][a-z0-9_]{0,63})\.(created|changed|moved)(?:\.([a-z][a-z0-9_]{0,63}))?$/';

    private function __construct()
    {
    }

    /**
     * @param mixed $records The manifest's `records` section (already checked
     *        on its own; read defensively here).
     * @param list<string> $roles Role names this manifest declares.
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    public static function check(mixed $automations, mixed $records, array $roles, callable $label, array &$errors): void
    {
        if ($automations === null) {
            return;
        }
        if (!is_array($automations) || array_is_list($automations) && $automations !== []) {
            $errors[] = 'automations: must be an object of automation name to automation';
            return;
        }
        if (count($automations) > self::MAX_AUTOMATIONS) {
            $errors[] = 'automations: at most ' . self::MAX_AUTOMATIONS;
        }
        $records = is_array($records) ? $records : [];

        foreach ($automations as $name => $automation) {
            $path = "automations.{$name}";
            if (preg_match(self::NAME_PATTERN, (string) $name) !== 1) {
                $errors[] = "{$path}: automation names are lowercase letters, digits and underscores";
            }
            if (!is_array($automation)) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            self::onlyKeys($automation, ['label', 'on', 'when', 'steps'], $path, $errors);
            $label($automation['label'] ?? null, "{$path}.label", 200, true, $errors);

            $type = self::checkTrigger($automation['on'] ?? null, "{$path}.on", $records, $errors);
            $decl = $type !== null && is_array($records[$type] ?? null) ? $records[$type] : null;
            $fieldNames = self::fieldNames($decl);

            if (array_key_exists('when', $automation)) {
                RecordTypeValidator::checkExpression($automation['when'], "{$path}.when", $fieldNames, $errors);
            }

            $steps = $automation['steps'] ?? null;
            if (!is_array($steps) || !array_is_list($steps) || $steps === []) {
                $errors[] = "{$path}.steps: required, a non-empty list";
                continue;
            }
            if (count($steps) > self::MAX_STEPS) {
                $errors[] = "{$path}.steps: at most " . self::MAX_STEPS;
            }
            foreach ($steps as $i => $step) {
                self::checkStep($step, "{$path}.steps[{$i}]", $type, $decl, $records, $roles, $label, $errors);
            }
        }
    }

    /**
     * @param array<mixed> $records
     * @param list<string> $errors
     * @return string|null The record type the trigger names, when it does.
     */
    private static function checkTrigger(mixed $on, string $path, array $records, array &$errors): ?string
    {
        if (!is_string($on) || preg_match(self::TRIGGER_PATTERN, $on, $m) !== 1) {
            $errors[] = "{$path}: required, \"<record type>.created\", \".changed\", \".changed.<field>\", \".moved\" or \".moved.<state>\"";
            return null;
        }
        [$type, $event, $detail] = [$m[1], $m[2], $m[3] ?? ''];
        $decl = $records[$type] ?? null;
        if (!is_array($decl)) {
            $errors[] = "{$path}: \"{$type}\" isn't a record type of this manifest";
            return null;
        }
        if ($event === 'created' && $detail !== '') {
            $errors[] = "{$path}: \"created\" takes nothing after it";
        }
        if ($event === 'changed' && $detail !== '' && !in_array($detail, self::fieldNames($decl), true)) {
            $errors[] = "{$path}: \"{$detail}\" isn't a field of {$type}";
        }
        if ($event === 'moved') {
            $states = self::stateKeys($decl);
            if ($states === []) {
                $errors[] = "{$path}: {$type} has no lifecycle, so it never moves";
            } elseif ($detail !== '' && !in_array($detail, $states, true)) {
                $errors[] = "{$path}: \"{$detail}\" isn't a state of {$type}";
            }
        }

        return $type;
    }

    /**
     * @param array<mixed>|null $decl The triggering record type.
     * @param array<mixed> $records
     * @param list<string> $roles
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    private static function checkStep(mixed $step, string $path, ?string $type, ?array $decl, array $records, array $roles, callable $label, array &$errors): void
    {
        if (!is_array($step) || array_is_list($step) || count($step) !== 1) {
            $errors[] = "{$path}: a step is an object with one key, the step's name";
            return;
        }
        $kind = (string) array_key_first($step);
        $args = $step[$kind];
        $path .= ".{$kind}";
        if (in_array($kind, self::LATER_STEPS, true)) {
            $errors[] = "{$path}: this step isn't available in this version yet";
            return;
        }
        if (!in_array($kind, self::STEPS, true)) {
            $errors[] = "{$path}: unknown step; the steps are " . implode(', ', self::STEPS);
            return;
        }
        if (!is_array($args) || array_is_list($args) && $args !== []) {
            $errors[] = "{$path}: must be an object";
            return;
        }
        $fieldNames = self::fieldNames($decl);

        switch ($kind) {
            case 'set_fields':
                self::onlyKeys($args, ['values'], $path, $errors);
                self::checkValues($args['values'] ?? null, "{$path}.values", $decl, $fieldNames, $errors);
                break;

            case 'create_record':
                self::onlyKeys($args, ['type', 'values', 'link'], $path, $errors);
                $target = $args['type'] ?? null;
                $targetDecl = is_string($target) && is_array($records[$target] ?? null) ? $records[$target] : null;
                if ($targetDecl === null) {
                    $errors[] = "{$path}.type: required, a record type of this manifest";
                    break;
                }
                self::checkValues($args['values'] ?? [], "{$path}.values", $targetDecl, $fieldNames, $errors);
                if (isset($args['link'])) {
                    $link = $args['link'];
                    $field = is_string($link) && is_array($targetDecl['fields'][$link] ?? null) ? $targetDecl['fields'][$link] : null;
                    if ($field === null || ($field['type'] ?? null) !== 'record' || ($field['record'] ?? null) !== $type) {
                        $errors[] = "{$path}.link: must name a field of {$target} that links to {$type}";
                    }
                }
                break;

            case 'move':
                self::onlyKeys($args, ['move'], $path, $errors);
                $moves = $decl !== null && is_array($decl['lifecycle']['moves'] ?? null) ? $decl['lifecycle']['moves'] : [];
                if (!is_string($args['move'] ?? null) || !isset($moves[$args['move']])) {
                    $errors[] = "{$path}.move: required, a move of {$type}";
                }
                break;

            case 'notify':
                self::onlyKeys($args, ['role', 'person', 'message'], $path, $errors);
                $hasRole = array_key_exists('role', $args);
                if ($hasRole === array_key_exists('person', $args)) {
                    $errors[] = "{$path}: name either a role or a person field";
                } elseif ($hasRole && (!is_string($args['role']) || !in_array($args['role'], $roles, true))) {
                    $errors[] = "{$path}.role: must be a role this manifest declares";
                } elseif (!$hasRole) {
                    $person = $args['person'];
                    if (!is_string($person) || ($decl['fields'][$person]['type'] ?? null) !== 'person') {
                        $errors[] = "{$path}.person: must name a person field of {$type}";
                    }
                }
                $label($args['message'] ?? null, "{$path}.message", 500, true, $errors);
                break;
        }
    }

    /**
     * Field values to write: the target's own, editable fields, as
     * expressions over the triggering record.
     *
     * @param array<mixed>|null $target The record type written to.
     * @param list<string> $sourceFields The triggering record type's fields.
     * @param list<string> $errors
     */
    private static function checkValues(mixed $values, string $path, ?array $target, array $sourceFields, array &$errors): void
    {
        if (!is_array($values) || array_is_list($values) && $values !== []) {
            $errors[] = "{$path}: required, an object of field name to value";
            return;
        }
        $fields = is_array($target['fields'] ?? null) ? $target['fields'] : [];
        foreach ($values as $name => $expression) {
            $field = $fields[$name] ?? null;
            if (!is_array($field)) {
                $errors[] = "{$path}.{$name}: isn't a field of the record type";
                continue;
            }
            if (isset($field['computed'])) {
                $errors[] = "{$path}.{$name}: is calculated and can't be set";
                continue;
            }
            RecordTypeValidator::checkExpression($expression, "{$path}.{$name}", $sourceFields, $errors);
        }
    }

    /**
     * @param array<mixed>|null $decl
     * @return list<string>
     */
    private static function fieldNames(?array $decl): array
    {
        return is_array($decl['fields'] ?? null) ? array_map('strval', array_keys($decl['fields'])) : [];
    }

    /**
     * @param array<mixed> $decl
     * @return list<string>
     */
    private static function stateKeys(array $decl): array
    {
        $states = $decl['lifecycle']['states'] ?? null;
        if (!is_array($states)) {
            return [];
        }
        $keys = [];
        foreach ($states as $state) {
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
