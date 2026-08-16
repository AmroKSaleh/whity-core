<?php

declare(strict_types=1);

namespace DemoCatalog\Api;

use Whity\Sdk\Sync\SyncableResource;

/**
 * The {@see SyncableResource} descriptor for `demo_catalog_items` — the small,
 * declarative half of the DemoCatalog pilot now that the sync LIFECYCLE lives in
 * {@see \Whity\Sdk\Sync\SyncController}. It names the table, its change-sequence
 * key, its own (non-sync) columns, and how to validate/shape them; the engine
 * does everything else (tenant scoping, idempotent create, optimistic
 * concurrency, tombstones, the changes feed).
 *
 * This is the "extract-once / consume-twice" proof (ADR 0003): the very code
 * that hand-rolled the sync flow is now the FIRST consumer of the shared engine.
 */
final class DemoCatalogResource implements SyncableResource
{
    private const MAX_NAME_LENGTH = 255;
    private const MAX_DESCRIPTION_LENGTH = 2000;

    /** @var list<string> */
    private const VALID_STATUSES = ['active', 'archived'];

    public function table(): string
    {
        return 'demo_catalog_items';
    }

    public function sequenceKey(): string
    {
        return 'democatalog:change_seq';
    }

    public function domainColumns(): array
    {
        return ['name', 'description', 'status'];
    }

    public function validate(array $body, bool $requireAll): array
    {
        $name = $body['name'] ?? null;
        if ($requireAll) {
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                return ['ok' => false, 'error' => 'name must be a non-empty string of at most '
                    . self::MAX_NAME_LENGTH . ' characters'];
            }
        }

        $description = $body['description'] ?? null;
        if ($description !== null && (!is_string($description) || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH)) {
            return ['ok' => false, 'error' => 'description must be a string of at most '
                . self::MAX_DESCRIPTION_LENGTH . ' characters'];
        }

        $status = $body['status'] ?? 'active';
        if (!is_string($status) || !in_array($status, self::VALID_STATUSES, true)) {
            return ['ok' => false, 'error' => 'status must be one of: ' . implode(', ', self::VALID_STATUSES)];
        }

        return ['ok' => true, 'values' => [
            'name' => is_string($name) ? $name : '',
            'description' => is_string($description) ? $description : null,
            'status' => $status,
        ]];
    }

    public function toPublicFields(array $row): array
    {
        return [
            'name' => (string) ($row['name'] ?? ''),
            'description' => isset($row['description']) && $row['description'] !== null ? (string) $row['description'] : null,
            'status' => (string) ($row['status'] ?? 'active'),
        ];
    }
}
