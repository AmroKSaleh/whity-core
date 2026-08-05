<?php

declare(strict_types=1);

namespace Whity\Core\i18n;

/**
 * Domain model for a language.
 *
 * Represents a single language with its code, name, and enabled status.
 * Language codes are globally unique and available to all tenants.
 */
final class Language
{
    public function __construct(
        public readonly int $id,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $enabled,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
    }

    /**
     * Create a Language from a database row.
     *
     * @param array<string, mixed> $row The database row.
     * @return self
     */
    public static function fromRow(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            code: (string) $row['code'],
            name: (string) $row['name'],
            enabled: (bool) $row['enabled'],
            createdAt: (string) $row['created_at'],
            updatedAt: (string) $row['updated_at'],
        );
    }
}
