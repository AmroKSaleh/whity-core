<?php

declare(strict_types=1);

namespace Whity\Core\Document\Legacy;

use PDO;
use Psr\Log\LoggerInterface;
use Whity\Core\Document\DocumentSourceRepository;

/**
 * Copies a tenant's old Document designer templates into editor documents,
 * each once: the new row remembers the template it came from
 * (`legacy_template_id`), so running again converts only what is new.
 *
 * Run by migration 164 for every tenant, just before it drops the old
 * designer's tables (editor M4c; the owner's call on 31 Oct). It reads those
 * tables directly, so it needs no code that goes with them. Saved blocks are
 * placed into the templates that use them.
 *
 * The copies are drafts with the same title, scope, permission and unit;
 * nothing is published until a person has checked the layout and published
 * it. Whity's own starters are not copied unless someone edited them: the
 * editor has its own starters (DocumentSourceStarterSeeder).
 */
final class LegacyTemplateMigrator
{
    public function __construct(
        private readonly PDO $db,
        private readonly DocumentSourceRepository $sources,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ConversionLabels $labels = null,
    ) {
    }

    /**
     * @return array{converted: int, skipped: int, failed: int, dropped: array<string, int>, errors: list<string>}
     */
    public function migrateTenant(int $tenantId, bool $dryRun = false): array
    {
        $done = $this->sources->convertedTemplateIds($tenantId);
        $summary = ['converted' => 0, 'skipped' => 0, 'failed' => 0, 'dropped' => [], 'errors' => []];
        $converter = new LegacyTemplateConverter($this->labels ?? new ConversionLabels());
        $savedBlocks = $this->savedBlocks($tenantId);

        foreach ($this->templates($tenantId) as $row) {
            $id = (int) $row['id'];
            if (isset($done[$id]) || self::isUneditedStarter($row)) {
                $summary['skipped']++;
                continue;
            }
            try {
                $doc = $converter->convert((string) $row['name'], self::json($row['data']), $savedBlocks);
                foreach ($doc['dropped'] as $kind => $n) {
                    $summary['dropped'][$kind] = ($summary['dropped'][$kind] ?? 0) + $n;
                }
                if (!$dryRun) {
                    $this->sources->create($tenantId, [
                        'kind'                => $doc['kind'],
                        'title'               => $doc['title'],
                        'lang'                => $doc['lang'],
                        'default_view'        => 'page',
                        'page'                => $doc['page'],
                        'blocks'              => $doc['blocks'],
                        'own_fields'          => $doc['own_fields'] !== [] ? $doc['own_fields'] : null,
                        'scope'               => (string) $row['scope'],
                        'required_permission' => $row['required_permission'],
                        'created_by'          => $row['created_by'] !== null ? (int) $row['created_by'] : null,
                        'owner_ou_id'         => $row['owner_ou_id'] !== null ? (int) $row['owner_ou_id'] : null,
                        'legacy_template_id'  => $id,
                    ]);
                }
                $summary['converted']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
                $summary['errors'][] = "template {$id}: " . $e->getMessage();
                $this->logger?->error('Converting an old document template failed', [
                    'event'       => 'documents.legacy_convert_failed',
                    'tenant_id'   => $tenantId,
                    'template_id' => $id,
                    'error'       => $e->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function templates(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, name, data, scope, required_permission, created_by, owner_ou_id, starter_key, created_at, updated_at
             FROM document_templates WHERE tenant_id = :tenant_id ORDER BY id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function savedBlocks(int $tenantId): array
    {
        $stmt = $this->db->prepare('SELECT id, data FROM document_blocks WHERE tenant_id = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $data = self::json($row['data']);
            $out[(string) $row['id']] = array_values(array_filter(array_is_list($data) ? $data : [], 'is_array'));
        }

        return $out;
    }

    /**
     * @return array<mixed>
     */
    private static function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        $decoded = is_string($value) ? json_decode($value, true) : null;

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * A starter Whity seeded and nobody changed since: its timestamps are the
     * seeder's own.
     *
     * @param array<string, mixed> $row
     */
    private static function isUneditedStarter(array $row): bool
    {
        if (($row['starter_key'] ?? null) === null) {
            return false;
        }
        $created = strtotime((string) ($row['created_at'] ?? ''));
        $updated = strtotime((string) ($row['updated_at'] ?? ''));

        return $created !== false && $updated !== false && abs($updated - $created) < 60;
    }
}
