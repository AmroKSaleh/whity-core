<?php

declare(strict_types=1);

namespace Taxonomy\Api;

use Whity\Sdk\Sync\SyncableResource;

/**
 * The {@see SyncableResource} descriptor for the Taxonomy plugin's `tag_groups`
 * table — the declarative half; {@see \Whity\Sdk\Sync\SyncController} drives the
 * sync lifecycle.
 *
 * `display_name` is a plain human-readable string on the wire (so a full-replace
 * edit form can seed it via `defaultFrom` — the block contract has no localized-
 * input seeding yet). It is STORED json-encoded (`"Colors"`, a JSON string):
 * that is valid for BOTH the server's JSONB column (core migration 063) and the
 * offline TEXT column, whereas a bare `Colors` is invalid JSON and Postgres
 * rejects it into JSONB. The resource decodes it on read. Core stores a localized
 * `{ar,en}` object there; reconciling the two shapes is an R3-cutover concern.
 */
final class TagGroupResource implements SyncableResource
{
    private const MAX_KEY_LENGTH = 64;
    private const MAX_NAME_LENGTH = 255;

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
        if ($displayName !== null && (!is_string($displayName) || mb_strlen($displayName) > self::MAX_NAME_LENGTH)) {
            return ['ok' => false, 'error' => 'displayName must be a string of at most '
                . self::MAX_NAME_LENGTH . ' characters'];
        }

        return ['ok' => true, 'values' => [
            'group_key'    => is_string($groupKey) ? trim($groupKey) : '',
            // JSON-encode the string ('"Colors"' / '""') so it is valid for both
            // the server JSONB column and the offline TEXT column.
            'display_name' => json_encode(is_string($displayName) ? trim($displayName) : '', JSON_UNESCAPED_UNICODE) ?: '""',
        ]];
    }

    public function toPublicFields(array $row): array
    {
        // Decode the stored JSON back to a plain string. A legacy/default object
        // (e.g. the '{}' column default, or core's localized value) decodes to a
        // non-string, which surfaces as an empty display name until reconciled.
        $raw = $row['display_name'] ?? null;
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;

        return [
            'groupKey'    => (string) ($row['group_key'] ?? ''),
            'displayName' => is_string($decoded) ? $decoded : '',
        ];
    }
}
