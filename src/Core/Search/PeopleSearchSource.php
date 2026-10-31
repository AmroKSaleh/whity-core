<?php

declare(strict_types=1);

namespace Whity\Core\Search;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Text\SearchText;

/**
 * People with an active membership in the tenant, by name and primary email.
 * `users:read`, as `GET /api/users`.
 */
final class PeopleSearchSource implements SearchSource
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function type(): string
    {
        return 'person';
    }

    public function label(): array
    {
        return ['en' => 'People', 'ar' => 'الأشخاص'];
    }

    public function permission(): string
    {
        return CorePermissions::USERS_READ;
    }

    public function search(SearchContext $context, string $pattern, int $limit): array
    {
        $sql = 'SELECT p.id, p.display_name, pe.email
                  FROM memberships m
                  JOIN profiles p ON p.id = m.profile_id
                  LEFT JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = true
                 WHERE m.tenant_id = :tenant_id AND m.is_primary AND m.status = \'active\'
                   AND (' . SearchText::sqlFold('p.display_name') . " LIKE :pattern ESCAPE '\\'
                        OR LOWER(pe.email) LIKE :pattern ESCAPE '\\')
                 ORDER BY p.id DESC LIMIT :limit";

        return array_map(
            static function (array $row): SearchHit {
                $name  = trim((string) ($row['display_name'] ?? ''));
                $email = isset($row['email']) ? (string) $row['email'] : null;
                return new SearchHit(
                    'person',
                    (string) $row['id'],
                    $name !== '' ? $name : (string) $email,
                    $name !== '' ? $email : null,
                    '/admin/users/' . (int) $row['id'],
                );
            },
            CoreSearchSources::fetch($this->db, $sql, [':tenant_id' => $context->tenantId, ':pattern' => $pattern], $limit),
        );
    }
}
