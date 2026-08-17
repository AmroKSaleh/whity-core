<?php

declare(strict_types=1);

namespace Taxonomy\Api;

use Whity\Sdk\Sync\SyncableResource;

/**
 * The {@see SyncableResource} descriptor for the Taxonomy plugin's `tag_groups`
 * table — the declarative half; {@see \Whity\Sdk\Sync\SyncController} drives the
 * sync lifecycle.
 *
 * `display_name` is a plain human-readable string offline (stored in the TEXT
 * column). Core uses a localized `{ar,en}` JSONB value; the offline plugin keeps
 * it a simple string so a full-replace edit form can seed it via `defaultFrom`
 * (the block contract has no localized-input seeding yet). Reconciling the two
 * representations is an R3-cutover concern, when the plugin runs on the server.
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
            'display_name' => is_string($displayName) && trim($displayName) !== '' ? trim($displayName) : '',
        ]];
    }

    public function toPublicFields(array $row): array
    {
        return [
            'groupKey'    => (string) ($row['group_key'] ?? ''),
            'displayName' => (string) ($row['display_name'] ?? ''),
        ];
    }
}
