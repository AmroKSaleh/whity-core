<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

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
        );
    }
}
