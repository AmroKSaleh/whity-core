<?php

declare(strict_types=1);

namespace Whity\Core\Search;

use PDO;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Text\SearchText;

/**
 * Issued documents. `documents:read` to search at all; without
 * `documents:read:all` only what the caller created, was routed or was granted,
 * exactly as `GET /api/documents` lists them.
 */
final class DocumentsSearchSource implements SearchSource
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function type(): string
    {
        return 'document';
    }

    public function label(): array
    {
        return ['en' => 'Documents', 'ar' => 'المستندات'];
    }

    public function permission(): string
    {
        return CorePermissions::DOCUMENTS_READ;
    }

    public function search(SearchContext $context, string $pattern, int $limit): array
    {
        $sql = 'SELECT id, title, template_name FROM documents
                 WHERE tenant_id = :tenant_id
                   AND ' . SearchText::sqlFold('title') . " LIKE :pattern ESCAPE '\\'";
        $params = [':tenant_id' => $context->tenantId, ':pattern' => $pattern];

        if (!$context->can(CorePermissions::DOCUMENTS_READ_ALL)) {
            // Mirrors DocumentRepository::VISIBLE_TO_CALLER.
            $sql .= ' AND (created_by = :profile_id
                OR EXISTS (
                    SELECT 1 FROM document_route_recipients r
                     WHERE r.tenant_id = :tenant_id
                       AND r.document_id = documents.id
                       AND r.profile_id = :profile_id
                )
                OR EXISTS (
                    SELECT 1 FROM resource_role_assignments rra
                     WHERE rra.tenant_id = :tenant_id
                       AND rra.resource_type = \'document\'
                       AND rra.resource_id = documents.id
                       AND rra.profile_id = :profile_id
                ))';
            $params[':profile_id'] = $context->profileId;
        }
        $sql .= ' ORDER BY id DESC LIMIT :limit';

        return array_map(
            static fn (array $row): SearchHit => new SearchHit(
                'document',
                (string) $row['id'],
                (string) $row['title'],
                (string) $row['template_name'],
                '/admin/document-library/' . (int) $row['id'],
            ),
            CoreSearchSources::fetch($this->db, $sql, $params, $limit),
        );
    }
}
