<?php

declare(strict_types=1);

namespace Whity\Core\Document\Theme;

use PDO;

/**
 * The workspace's own document themes, `document_themes` (editor M4d; E.44).
 *
 * Every statement binds `tenant_id`: a workspace sees and changes only its own
 * themes. `version` starts at 1 and goes up on every change; an update names
 * the version it was based on and is refused when the row has moved on, so two
 * people editing one theme can't overwrite each other.
 */
final class DocumentThemeRepository
{
    private const COLUMNS = 'id, theme_key, name, name_ar, extends, tokens, version, created_by, updated_by, archived_at, created_at, updated_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The workspace's themes, by name.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, bool $withArchived = false): array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_themes WHERE tenant_id = :tenant_id'
            . ($withArchived ? '' : ' AND archived_at IS NULL')
            . ' ORDER BY name, id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(self::normalize(...), $rows);
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, string $key): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . self::COLUMNS . ' FROM document_themes WHERE tenant_id = :tenant_id AND theme_key = :key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':key' => $key]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : self::normalize($row);
    }

    /**
     * @param array<string, mixed> $tokens a valid partial token set
     * @return array<string, mixed> the stored row
     */
    public function create(int $tenantId, string $key, string $name, string $nameAr, ?string $extends, array $tokens, int $actor): array
    {
        $this->db->prepare(
            'INSERT INTO document_themes (tenant_id, theme_key, name, name_ar, extends, tokens, version, created_by, updated_by, created_at, updated_at)
             VALUES (:tenant_id, :key, :name, :name_ar, :extends, :tokens, 1, :actor, :actor, NOW(), NOW())'
        )->execute([
            ':tenant_id' => $tenantId,
            ':key'       => $key,
            ':name'      => $name,
            ':name_ar'   => $nameAr,
            ':extends'   => $extends,
            ':tokens'    => self::json($tokens),
            ':actor'     => $actor,
        ]);

        return $this->find($tenantId, $key) ?? throw new \RuntimeException('The theme was not stored.');
    }

    /**
     * Change a theme if nobody changed it since `$baseVersion`. Returns the
     * stored row, or null when it is at another version (or gone).
     *
     * @param array{name?: string, name_ar?: string, extends?: ?string, tokens?: array<string, mixed>} $fields
     * @return array<string, mixed>|null
     */
    public function update(int $tenantId, string $key, int $baseVersion, array $fields, int $actor): ?array
    {
        $set = ['version = version + 1', 'updated_by = :actor', 'updated_at = NOW()'];
        $params = [':tenant_id' => $tenantId, ':key' => $key, ':version' => $baseVersion, ':actor' => $actor];
        foreach (['name', 'name_ar', 'extends'] as $column) {
            if (array_key_exists($column, $fields)) {
                $set[] = "{$column} = :{$column}";
                $params[":{$column}"] = $fields[$column];
            }
        }
        if (array_key_exists('tokens', $fields)) {
            $set[] = 'tokens = :tokens';
            $params[':tokens'] = self::json($fields['tokens']);
        }
        $stmt = $this->db->prepare(
            'UPDATE document_themes SET ' . implode(', ', $set)
            . ' WHERE tenant_id = :tenant_id AND theme_key = :key AND version = :version'
        );
        $stmt->execute($params);
        if ($stmt->rowCount() !== 1) {
            return null;
        }

        return $this->find($tenantId, $key);
    }

    /**
     * Archive or restore a theme; it keeps its version.
     *
     * @return array<string, mixed>|null the stored row, or null when there is none
     */
    public function setArchived(int $tenantId, string $key, bool $archived, int $actor): ?array
    {
        $stmt = $this->db->prepare(
            'UPDATE document_themes SET archived_at = ' . ($archived ? 'NOW()' : 'NULL') . ', updated_by = :actor, updated_at = NOW()
             WHERE tenant_id = :tenant_id AND theme_key = :key'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':key' => $key, ':actor' => $actor]);

        return $stmt->rowCount() === 1 ? $this->find($tenantId, $key) : null;
    }

    public function delete(int $tenantId, string $key): bool
    {
        $stmt = $this->db->prepare('DELETE FROM document_themes WHERE tenant_id = :tenant_id AND theme_key = :key');
        $stmt->execute([':tenant_id' => $tenantId, ':key' => $key]);

        return $stmt->rowCount() === 1;
    }

    /**
     * Where a theme is used: drafts that name it, and published versions that
     * printed with it.
     *
     * @return array{drafts: list<array{id: int, title: string, kind: string}>, published: list<array{id: int, title: string, version: int}>}
     */
    public function usage(int $tenantId, string $ref): array
    {
        $drafts = [];
        $stmt = $this->db->prepare('SELECT id, title, kind, theme FROM document_sources WHERE tenant_id = :tenant_id AND theme IS NOT NULL ORDER BY id');
        $stmt->execute([':tenant_id' => $tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::refOf($row['theme']) === $ref) {
                $drafts[] = ['id' => (int) $row['id'], 'title' => (string) $row['title'], 'kind' => (string) $row['kind']];
            }
        }

        $published = [];
        $stmt = $this->db->prepare(
            'SELECT v.source_id, v.version, v.title, v.theme_resolved FROM document_source_versions v
              WHERE v.tenant_id = :tenant_id AND v.theme_resolved IS NOT NULL
                AND v.version = (SELECT s.published_version FROM document_sources s WHERE s.id = v.source_id AND s.tenant_id = :tenant_id2)
              ORDER BY v.source_id'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':tenant_id2' => $tenantId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (self::refOf($row['theme_resolved']) === $ref) {
                $published[] = ['id' => (int) $row['source_id'], 'title' => (string) $row['title'], 'version' => (int) $row['version']];
            }
        }

        return ['drafts' => $drafts, 'published' => $published];
    }

    private static function refOf(mixed $json): ?string
    {
        $value = is_string($json) ? json_decode($json, true) : $json;

        return is_array($value) && is_string($value['ref'] ?? null) ? $value['ref'] : null;
    }

    /** @param array<string, mixed> $value */
    private static function json(array $value): string
    {
        return json_encode($value === [] ? new \stdClass() : $value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function normalize(array $row): array
    {
        $tokens = json_decode((string) $row['tokens'], true);

        return [
            'id'          => (int) $row['id'],
            'key'         => (string) $row['theme_key'],
            'name'        => (string) $row['name'],
            'name_ar'     => (string) $row['name_ar'],
            'extends'     => $row['extends'] !== null ? (string) $row['extends'] : null,
            'tokens'      => is_array($tokens) ? $tokens : [],
            'version'     => (int) $row['version'],
            'created_by'  => $row['created_by'] !== null ? (int) $row['created_by'] : null,
            'updated_by'  => $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'archived_at' => $row['archived_at'] !== null ? (string) $row['archived_at'] : null,
            'created_at'  => (string) $row['created_at'],
            'updated_at'  => (string) $row['updated_at'],
        ];
    }
}
