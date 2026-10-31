<?php

declare(strict_types=1);

namespace Whity\Core\Search;

use PDO;
use Whity\Core\DataType\DataTypeDefinition;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\LifecycleAction;
use Whity\Core\Text\SearchText;

/**
 * A plugin's records, for a data type that opted in with a `search`
 * declaration (WHIT-783, docs/wiki/Plugin-Data-Types.md).
 *
 * Reads only the plugin's own declared table, through identifiers the
 * {@see DataTypeRegistry} already validated, scoped by its declared tenant
 * column and gated by its declared `read` permission. Trashed records are left
 * out, as the data-type list leaves them out.
 */
final class DataTypeSearchSource implements SearchSource
{
    public function __construct(
        private readonly PDO $db,
        private readonly DataTypeDefinition $type,
    ) {
    }

    /**
     * One source per registered type that opted in.
     *
     * @return list<SearchSource>
     */
    public static function fromRegistry(PDO $db, DataTypeRegistry $registry): array
    {
        $sources = [];
        foreach ($registry->all() as $definition) {
            if ($definition->search() !== null && $definition->permissionFor(LifecycleAction::READ) !== null) {
                $sources[] = new self($db, $definition);
            }
        }
        return $sources;
    }

    public function type(): string
    {
        return $this->type->key();
    }

    public function label(): array
    {
        $label = $this->type->label();
        $en    = $label['en'] ?? $this->type->key();
        return ['en' => $en, 'ar' => $label['ar'] ?? $en];
    }

    public function permission(): string
    {
        return (string) $this->type->permissionFor(LifecycleAction::READ);
    }

    public function search(SearchContext $context, string $pattern, int $limit): array
    {
        $search   = $this->type->search();
        if ($search === null) {
            return [];
        }
        $table    = $this->type->table();
        $key      = $this->type->keyColumn();
        $tenant   = $this->type->tenantColumn();
        $title    = $search['title'];
        $subtitle = $search['subtitle'];

        $columns = "{$key} AS id, {$title} AS title" . ($subtitle !== null ? ", {$subtitle} AS subtitle" : '');
        // @tenant-guard-ignore: plugin-declared table and tenant column, validated by DataTypeRegistry; bound to the caller's tenant below
        $sql = "SELECT {$columns} FROM {$table}
                 WHERE {$tenant} = :tenant_id
                   AND " . SearchText::sqlFold($title) . " LIKE :pattern ESCAPE '\\'";
        $params = [':tenant_id' => $context->tenantId, ':pattern' => $pattern];

        $lifecycle = $this->type->lifecycle();
        $trashed   = $lifecycle->trashedState();
        if ($lifecycle->column() !== null && $trashed !== null) {
            $sql .= ' AND (' . $lifecycle->column() . ' IS NULL OR ' . $lifecycle->column() . ' <> :trashed)';
            $params[':trashed'] = $trashed;
        }
        $sql .= " ORDER BY {$key} DESC LIMIT :limit";

        $url     = $search['url'];
        $typeKey = $this->type->key();

        return array_map(
            static fn (array $row): SearchHit => new SearchHit(
                $typeKey,
                (string) $row['id'],
                (string) ($row['title'] ?? ''),
                isset($row['subtitle']) ? (string) $row['subtitle'] : null,
                is_string($url) ? str_replace('{id}', rawurlencode((string) $row['id']), $url) : null,
            ),
            CoreSearchSources::fetch($this->db, $sql, $params, $limit),
        );
    }
}
