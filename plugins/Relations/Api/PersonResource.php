<?php

declare(strict_types=1);

namespace Relations\Api;

use Whity\Sdk\Sync\SyncableResource;

/**
 * The {@see SyncableResource} descriptor for the Relations plugin's `persons`
 * graph-node table — the declarative half; {@see \Whity\Sdk\Sync\SyncController}
 * drives the sync lifecycle.
 *
 * First slice: the person's own attributes. The graph-derived fields
 * (`relationCount`, reciprocal `relations`) and the profile-linkage guard live
 * in the repository/edges slices and are added around this by the handler.
 */
final class PersonResource implements SyncableResource
{
    private const MAX_NAME_LENGTH = 255;
    private const MAX_NOTES_LENGTH = 5000;

    public function table(): string
    {
        return 'persons';
    }

    public function sequenceKey(): string
    {
        return 'relations:persons:change_seq';
    }

    public function domainColumns(): array
    {
        return ['display_name', 'birth_date', 'deceased', 'notes'];
    }

    public function validate(array $body, bool $requireAll): array
    {
        $displayName = $body['displayName'] ?? null;
        if ($requireAll) {
            if (!is_string($displayName) || trim($displayName) === '' || mb_strlen($displayName) > self::MAX_NAME_LENGTH) {
                return ['ok' => false, 'error' => 'displayName must be a non-empty string of at most '
                    . self::MAX_NAME_LENGTH . ' characters'];
            }
        }

        $notes = $body['notes'] ?? null;
        if ($notes !== null && (!is_string($notes) || mb_strlen($notes) > self::MAX_NOTES_LENGTH)) {
            return ['ok' => false, 'error' => 'notes must be a string of at most ' . self::MAX_NOTES_LENGTH . ' characters'];
        }

        $birthDate = $body['birthDate'] ?? null;
        if ($birthDate !== null && !is_string($birthDate)) {
            return ['ok' => false, 'error' => 'birthDate must be a string (Y-m-d) or null'];
        }

        return ['ok' => true, 'values' => [
            'display_name' => is_string($displayName) ? trim($displayName) : '',
            'birth_date'   => is_string($birthDate) && trim($birthDate) !== '' ? trim($birthDate) : null,
            // Stored as 0/1 for cross-engine parity, matching PersonRepository.
            'deceased'     => !empty($body['deceased']) ? 1 : 0,
            'notes'        => is_string($notes) && trim($notes) !== '' ? trim($notes) : null,
        ]];
    }

    public function toPublicFields(array $row): array
    {
        return [
            'displayName' => (string) ($row['display_name'] ?? ''),
            'birthDate'   => isset($row['birth_date']) && $row['birth_date'] !== null ? (string) $row['birth_date'] : null,
            'deceased'    => self::toBool($row['deceased'] ?? false),
            'notes'       => isset($row['notes']) && $row['notes'] !== null ? (string) $row['notes'] : null,
            'profileId'   => isset($row['profile_id']) && $row['profile_id'] !== null ? (int) $row['profile_id'] : null,
            'hasAccount'  => isset($row['profile_id']) && $row['profile_id'] !== null,
        ];
    }

    /** Coerce a DB boolean (Postgres 't'/'f', SQLite 0/1, native bool) to bool. */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return !in_array(strtolower(trim((string) $value)), ['', '0', 'f', 'false', 'no'], true);
    }
}
