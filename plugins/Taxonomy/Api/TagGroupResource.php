<?php

declare(strict_types=1);

namespace Taxonomy\Api;

use Whity\Sdk\Sync\SyncableResource;

/**
 * The {@see SyncableResource} descriptor for the Taxonomy plugin's `tag_groups`
 * table — the declarative half; {@see \Whity\Sdk\Sync\SyncController} drives the
 * sync lifecycle.
 *
 * `display_name` is a localized `{ar, en}` object, stored as a JSON string
 * (JSONB on the server, TEXT offline — see {@see \Taxonomy\Migrations\CreateTaxonomyTables}).
 * The resource json-encodes it on write and json-decodes it on read, so the wire
 * always carries a plain object.
 */
final class TagGroupResource implements SyncableResource
{
    private const MAX_KEY_LENGTH = 64;

    public function table(): string
    {
        return 'tag_groups';
    }

    public function sequenceKey(): string
    {
        return 'taxonomy:tag_groups:change_seq';
    }

    public function domainColumns(): array
    {
        return ['group_key', 'display_name'];
    }

    public function validate(array $body, bool $requireAll): array
    {
        $groupKey = $body['groupKey'] ?? null;
        if ($requireAll) {
            if (!is_string($groupKey) || trim($groupKey) === '' || mb_strlen($groupKey) > self::MAX_KEY_LENGTH) {
                return ['ok' => false, 'error' => 'groupKey must be a non-empty string of at most '
                    . self::MAX_KEY_LENGTH . ' characters'];
            }
        }

        $displayName = $body['displayName'] ?? null;
        if ($displayName !== null && !is_array($displayName) && !is_string($displayName)) {
            return ['ok' => false, 'error' => 'displayName must be a localized object or null'];
        }

        return ['ok' => true, 'values' => [
            'group_key'    => is_string($groupKey) ? trim($groupKey) : '',
            // Store the localized object as a JSON string ('{}' default), valid
            // for both the server's JSONB column and the offline TEXT column.
            'display_name' => self::encodeDisplayName($displayName),
        ]];
    }

    public function toPublicFields(array $row): array
    {
        $raw = $row['display_name'] ?? null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return [
            'groupKey'    => (string) ($row['group_key'] ?? ''),
            'displayName' => is_array($decoded) ? $decoded : new \stdClass(),
        ];
    }

    /** @param array<mixed>|string|null $value */
    private static function encodeDisplayName(array|string|null $value): string
    {
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE) ?: '{}';
        }
        // A raw string is treated as already-encoded JSON if it decodes to an
        // object, else wrapped as an empty localized object.
        if (is_string($value) && $value !== '' && is_array(json_decode($value, true))) {
            return $value;
        }

        return '{}';
    }
}
