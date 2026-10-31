<?php

declare(strict_types=1);

namespace Whity\Core\Search;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Text\SearchText;

/** Organisational units, by name and slug. `ous:read`, as `GET /api/ous`. */
final class OusSearchSource implements SearchSource
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function type(): string
    {
        return 'ou';
    }

    public function label(): array
    {
        return ['en' => 'Organisational units', 'ar' => 'الوحدات التنظيمية'];
    }

    public function permission(): string
    {
        return CorePermissions::OUS_READ;
    }

    public function search(SearchContext $context, string $pattern, int $limit): array
    {
        $sql = 'SELECT id, name, slug FROM organizational_units
                 WHERE tenant_id = :tenant_id
                   AND (' . SearchText::sqlFold('name') . " LIKE :pattern ESCAPE '\\'
                        OR LOWER(slug) LIKE :pattern ESCAPE '\\')
                 ORDER BY id DESC LIMIT :limit";

        return array_map(
            static fn (array $row): SearchHit => new SearchHit(
                'ou',
                (string) $row['id'],
                (string) $row['name'],
                isset($row['slug']) ? (string) $row['slug'] : null,
                '/admin/ous',
            ),
            CoreSearchSources::fetch($this->db, $sql, [':tenant_id' => $context->tenantId, ':pattern' => $pattern], $limit),
        );
    }
}
