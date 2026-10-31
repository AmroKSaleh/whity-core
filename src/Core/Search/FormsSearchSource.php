<?php

declare(strict_types=1);

namespace Whity\Core\Search;

use PDO;
use Whity\Core\Form\LocalizedLabel;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Text\SearchText;

/** Forms, by name (both languages) and key. `forms:read`, tenant-wide, as `GET /api/forms`. */
final class FormsSearchSource implements SearchSource
{
    public function __construct(private readonly PDO $db)
    {
    }

    public function type(): string
    {
        return 'form';
    }

    public function label(): array
    {
        return ['en' => 'Forms', 'ar' => 'النماذج'];
    }

    public function permission(): string
    {
        return CorePermissions::FORMS_READ;
    }

    public function search(SearchContext $context, string $pattern, int $limit): array
    {
        // `name` holds the label as JSON with both languages written out
        // unescaped (LocalizedLabel::encode), so a match inside it is a match
        // on either language.
        $sql = 'SELECT id, name, form_key FROM forms
                 WHERE tenant_id = :tenant_id
                   AND (' . SearchText::sqlFold('name') . " LIKE :pattern ESCAPE '\\'
                        OR LOWER(form_key) LIKE :pattern ESCAPE '\\')
                 ORDER BY id DESC LIMIT :limit";

        return array_map(
            static fn (array $row): SearchHit => new SearchHit(
                'form',
                (string) $row['id'],
                LocalizedLabel::preferred(LocalizedLabel::decode(isset($row['name']) ? (string) $row['name'] : null)),
                (string) $row['form_key'],
                '/forms/' . (int) $row['id'],
            ),
            CoreSearchSources::fetch($this->db, $sql, [':tenant_id' => $context->tenantId, ':pattern' => $pattern], $limit),
        );
    }
}
