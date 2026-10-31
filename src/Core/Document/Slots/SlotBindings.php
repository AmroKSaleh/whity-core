<?php

declare(strict_types=1);

namespace Whity\Core\Document\Slots;

/**
 * The workspace's slot bindings (DOCUMENT-AUTOMATION.md 3.2, WHIT-742): the
 * tenant setting `documents.slot_bindings`, a JSON object of slot key to the
 * id of the template bound to it, e.g. {"bakery.order_confirmation":42}.
 *
 * Only the shape is checked here. Whether a template fits its slot (kind,
 * record type, published) is checked by the binding API when it is bound,
 * and again when a document is issued, since a template can change after.
 */
final class SlotBindings
{
    private const KEY = '/^[A-Za-z][A-Za-z0-9_]{0,63}\.[a-z][a-z0-9_]{0,39}$/';

    private function __construct()
    {
    }

    /** Why the stored value is malformed, or null when it is valid. '' is "no bindings". */
    public static function validate(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $map = json_decode($value, true);
        if (!is_array($map) || ($map !== [] && array_is_list($map))) {
            return 'documents.slot_bindings must be a JSON object of slot key to template id, e.g. {"bakery.order_confirmation":42}.';
        }
        foreach ($map as $key => $id) {
            if (preg_match(self::KEY, (string) $key) !== 1) {
                return "documents.slot_bindings: {$key} is not a slot key (<plugin>.<slot>).";
            }
            if (!is_int($id) || $id <= 0) {
                return "documents.slot_bindings: the template for {$key} must be a template id.";
            }
        }

        return null;
    }

    /**
     * The bindings as a map, or none when the stored value is malformed.
     *
     * @return array<string, int>
     */
    public static function parse(string $value): array
    {
        if ($value === '' || self::validate($value) !== null) {
            return [];
        }
        /** @var array<string, int> $map */
        $map = json_decode($value, true);

        return $map;
    }

    /** @param array<string, int> $map */
    public static function encode(array $map): string
    {
        if ($map === []) {
            return '';
        }
        ksort($map);

        return json_encode($map, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * The ETag of the whole binding set: a PUT names the set it was made
     * against, so two people binding at once can't silently undo each other.
     *
     * @param array<string, int> $map
     */
    public static function etag(array $map): string
    {
        return '"' . substr(hash('sha256', self::encode($map)), 0, 16) . '"';
    }
}
