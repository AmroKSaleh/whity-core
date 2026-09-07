<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

use Whity\Core\Db\DbBool;

/**
 * Domain model for a translation.
 *
 * Represents a single translated string for a key within a domain and language.
 * Tenant isolation is enforced: tenant_id=NULL means system default, tenant_id>0
 * means tenant-specific override.
 */
final class Translation
{
    public function __construct(
        public readonly int $id,
        public readonly int $languageId,
        public readonly string $domain,
        public readonly string $key,
        public readonly string $translation,
        public readonly ?int $tenantId,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        /**
         * Whether this row still carries what the committed catalogue says
         * (#1057) — true only for rows {@see TranslationSync} wrote and nobody
         * has saved over since. Trails the constructor with a default because it
         * is the newest fact about a row, not the least important one: it is
         * what lets a deploy correct a stale string without reverting a human's.
         */
        public readonly bool $sourceManaged = false,
    ) {
    }

    /**
     * Create a Translation from a database row.
     *
     * @param array<string, mixed> $row The database row.
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            languageId: (int) $row['language_id'],
            domain: (string) $row['domain'],
            key: (string) $row['key'],
            translation: (string) $row['translation'],
            tenantId: isset($row['tenant_id']) && $row['tenant_id'] !== null ? (int) $row['tenant_id'] : null,
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
            // Absent means FALSE — the hands-off answer. A row array assembled
            // by hand in a fixture, or read from a database that has not run
            // migration 124, must never claim to be the catalogue's.
            sourceManaged: DbBool::of($row['source_managed'] ?? false),
        );
    }
}
