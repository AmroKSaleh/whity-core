<?php

declare(strict_types=1);

namespace Whity\Sdk\Manifest;

/**
 * The `documents` section of `plugin.json` (SDK 1.55, WHIT-742): template
 * slots, the named places a workspace plugin needs a document template.
 *
 * ```json
 * "documents": {
 *   "slots": {
 *     "order_confirmation": {
 *       "label": { "en": "Order confirmation", "ar": "تأكيد الطلب" },
 *       "record": "order",
 *       "kinds": ["template"]
 *     }
 *   }
 * }
 * ```
 *
 * The host addresses a slot as `<plugin name>.<key>`, as it does a code
 * plugin's slot (DOCUMENT-AUTOMATION.md 3.2). `record` must name one of this
 * manifest's own record types, which the host serves as the document field
 * record type `<plugin name>.<record>`. `kinds` defaults to `["template"]`.
 * A slot has no starter yet: a workspace manifest is one JSON document with
 * no files beside it, so starters arrive with WHIT-687 in their own form, and
 * until then an unbound slot has no template.
 */
final class DocumentSlotValidator
{
    /** Document kinds a slot may be filled from. */
    public const KINDS = ['template', 'label'];

    public const MAX_SLOTS = 50;

    private const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    private function __construct()
    {
    }

    /**
     * @param mixed $records The manifest's `records` section (already checked
     *        on its own; read defensively here).
     * @param callable(mixed, string, int, bool, list<string>&): void $label
     * @param list<string> $errors
     */
    public static function check(mixed $documents, mixed $records, callable $label, array &$errors): void
    {
        if ($documents === null) {
            return;
        }
        if (!is_array($documents) || array_is_list($documents) && $documents !== []) {
            $errors[] = 'documents: must be an object';
            return;
        }
        foreach (array_keys($documents) as $key) {
            if ($key !== 'slots') {
                $errors[] = "documents: unknown key '{$key}'";
            }
        }
        $slots = $documents['slots'] ?? null;
        if ($slots === null) {
            return;
        }
        if (!is_array($slots) || array_is_list($slots) && $slots !== []) {
            $errors[] = 'documents.slots: must be an object of slot name to slot';
            return;
        }
        if (count($slots) > self::MAX_SLOTS) {
            $errors[] = 'documents.slots: at most ' . self::MAX_SLOTS;
        }
        $records = is_array($records) ? $records : [];

        foreach ($slots as $name => $slot) {
            $path = "documents.slots.{$name}";
            if (preg_match(self::NAME_PATTERN, (string) $name) !== 1) {
                $errors[] = "{$path}: slot names are lowercase letters, digits and underscores, up to 40";
            }
            if (!is_array($slot) || array_is_list($slot) && $slot !== []) {
                $errors[] = "{$path}: must be an object";
                continue;
            }
            foreach (array_keys($slot) as $key) {
                if (!in_array($key, ['label', 'record', 'kinds'], true)) {
                    $errors[] = "{$path}: unknown key '{$key}'";
                }
            }
            $label($slot['label'] ?? null, "{$path}.label", 200, true, $errors);

            $record = $slot['record'] ?? null;
            if (!is_string($record) || $record === '') {
                $errors[] = "{$path}.record: required, the record type the slot's documents are for";
            } elseif (!array_key_exists($record, $records)) {
                $errors[] = "{$path}.record: '{$record}' is not a record type this manifest declares";
            }

            if (array_key_exists('kinds', $slot)) {
                $kinds = $slot['kinds'];
                if (!is_array($kinds) || !array_is_list($kinds) || $kinds === []) {
                    $errors[] = "{$path}.kinds: a non-empty list of " . implode(', ', self::KINDS);
                } else {
                    foreach ($kinds as $kind) {
                        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
                            $errors[] = "{$path}.kinds: each kind is one of " . implode(', ', self::KINDS);
                            break;
                        }
                    }
                    if (count(array_unique($kinds, SORT_REGULAR)) !== count($kinds)) {
                        $errors[] = "{$path}.kinds: no repeats";
                    }
                }
            }
        }
    }
}
