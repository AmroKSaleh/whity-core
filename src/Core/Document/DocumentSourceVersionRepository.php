<?php

declare(strict_types=1);

namespace Whity\Core\Document;

use PDO;

/**
 * Published versions of editor documents (editor M4, E.9; migration 162).
 *
 * Publishing copies the draft into a new, numbered, insert-only row and points
 * document_sources.published_version at it, in one transaction. Every query
 * binds tenant_id.
 */
final class DocumentSourceVersionRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Publish the draft as it is at `$draftVersion`. Returns the new version's
     * number, or null when the draft has moved on since (someone saved after the
     * check was run), so what was checked is exactly what is published.
     */
    public function publish(int $sourceId, int $tenantId, int $draftVersion, int $publishedBy): ?int
    {
        $own = !$this->db->inTransaction();
        if ($own) {
            $this->db->beginTransaction();
        }
        try {
            $stmt = $this->db->prepare(
                'SELECT title, page, blocks FROM document_sources
                 WHERE id = :id AND tenant_id = :tenant_id AND draft_version = :draft_version'
            );
            $stmt->execute([':id' => $sourceId, ':tenant_id' => $tenantId, ':draft_version' => $draftVersion]);
            /** @var array<string, mixed>|false $draft */
            $draft = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($draft === false) {
                if ($own) {
                    $this->db->rollBack();
                }

                return null;
            }

            $max = $this->db->prepare(
                'SELECT COALESCE(MAX(version), 0) FROM document_source_versions WHERE source_id = :source_id AND tenant_id = :tenant_id'
            );
            $max->execute([':source_id' => $sourceId, ':tenant_id' => $tenantId]);
            $version = (int) $max->fetchColumn() + 1;

            $this->db->prepare(
                'INSERT INTO document_source_versions (tenant_id, source_id, version, title, page, blocks, published_by_profile_id)
                 VALUES (:tenant_id, :source_id, :version, :title, :page, :blocks, :published_by)'
            )->execute([
                ':tenant_id'    => $tenantId,
                ':source_id'    => $sourceId,
                ':version'      => $version,
                ':title'        => (string) $draft['title'],
                ':page'         => $draft['page'],
                ':blocks'       => (string) $draft['blocks'],
                ':published_by' => $publishedBy,
            ]);

            $update = $this->db->prepare(
                'UPDATE document_sources SET published_version = :version
                 WHERE id = :id AND tenant_id = :tenant_id AND draft_version = :draft_version'
            );
            $update->execute([':version' => $version, ':id' => $sourceId, ':tenant_id' => $tenantId, ':draft_version' => $draftVersion]);
            if ($update->rowCount() !== 1) {
                if ($own) {
                    $this->db->rollBack();
                }

                return null;
            }

            if ($own) {
                $this->db->commit();
            }

            return $version;
        } catch (\Throwable $e) {
            if ($own && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * The history, newest first, without blocks.
     *
     * @return list<array{version: int, title: string, published_by: int|null, published_at: string}>
     */
    public function listForSource(int $sourceId, int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT version, title, published_by_profile_id, published_at FROM document_source_versions
             WHERE source_id = :source_id AND tenant_id = :tenant_id ORDER BY version DESC'
        );
        $stmt->execute([':source_id' => $sourceId, ':tenant_id' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[] = [
                'version'      => (int) $row['version'],
                'title'        => (string) $row['title'],
                'published_by' => $row['published_by_profile_id'] !== null ? (int) $row['published_by_profile_id'] : null,
                'published_at' => (string) $row['published_at'],
            ];
        }

        return $out;
    }

    /**
     * One published version with its blocks, or null.
     *
     * @return array{version: int, title: string, page: array<string, mixed>|null, blocks: list<mixed>, published_by: int|null, published_at: string}|null
     */
    public function find(int $sourceId, int $tenantId, int $version): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT version, title, page, blocks, published_by_profile_id, published_at FROM document_source_versions
             WHERE source_id = :source_id AND tenant_id = :tenant_id AND version = :version'
        );
        $stmt->execute([':source_id' => $sourceId, ':tenant_id' => $tenantId, ':version' => $version]);
        /** @var array<string, mixed>|false $row */
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $page = $row['page'] !== null ? json_decode((string) $row['page'], true) : null;
        $blocks = json_decode((string) $row['blocks'], true);

        return [
            'version'      => (int) $row['version'],
            'title'        => (string) $row['title'],
            'page'         => is_array($page) ? $page : null,
            'blocks'       => is_array($blocks) ? array_values($blocks) : [],
            'published_by' => $row['published_by_profile_id'] !== null ? (int) $row['published_by_profile_id'] : null,
            'published_at' => (string) $row['published_at'],
        ];
    }
}
