<?php

declare(strict_types=1);

namespace Taxonomy\Api;

use Whity\Sdk\Sync\SyncableResource;

/**
 * The {@see SyncableResource} descriptor for the Taxonomy plugin's `tags` table —
 * an individual tag inside a group ({@see TagGroupResource}).
 *
 * `group_id` is carried as a plain domain column: within a single store (the
 * offline device, or the server) the id is consistent, so local CRUD and the
 * master-detail block UI (a group's tags) work directly. CROSS-STORE sync of the
 * `group_id` reference (mapping a client-side tag_group id to the server's) is
 * the same reference-remapping concern the Relations edges slice defers — handled
 * later via clientUuid-based remapping, not in this slice.
 */
final class TagResource implements SyncableResource
{
    private const MAX_NAME_LENGTH = 128;

    public function table(): string
    {
        return 'tags';
    }

    public function sequenceKey(): string
    {
        return 'taxonomy:tags:change_seq';
    }

    public function domainColumns(): array
    {
        return ['group_id', 'name'];
    }

    public function validate(array $body, bool $requireAll): array
    {
        $groupId = $body['groupId'] ?? null;
        $name    = $body['name'] ?? null;

        if ($requireAll) {
            if (!is_int($groupId) && !(is_string($groupId) && ctype_digit($groupId))) {
                return ['ok' => false, 'error' => 'groupId must be an integer (the owning tag group)'];
            }
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                return ['ok' => false, 'error' => 'name must be a non-empty string of at most '
                    . self::MAX_NAME_LENGTH . ' characters'];
            }
        }

        return ['ok' => true, 'values' => [
            'group_id' => $groupId === null ? null : (int) $groupId,
            'name'     => is_string($name) ? trim($name) : '',
        ]];
    }

    public function toPublicFields(array $row): array
    {
        return [
            'groupId' => isset($row['group_id']) && $row['group_id'] !== null ? (int) $row['group_id'] : null,
            'name'    => (string) ($row['name'] ?? ''),
        ];
    }
}
