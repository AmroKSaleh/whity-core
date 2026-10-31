<?php

declare(strict_types=1);

namespace Whity\Core\Document\Legacy;

use Psr\Log\LoggerInterface;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentTemplateRepository;

/**
 * Copies a tenant's old Document designer templates into editor documents
 * (editor M4b), each once: the new row remembers the template it came from
 * (`legacy_template_id`), so running again converts only what is new.
 *
 * The old rows are left as they are, and the old designer keeps working on
 * them until it is removed (M4c). The copies are drafts with the same title,
 * scope, permission and unit; nothing is published until a person has checked
 * the layout and published it.
 *
 * Whity's own starters are not copied unless someone edited them: the editor
 * has its own starters (DocumentSourceStarterSeeder).
 */
final class LegacyTemplateMigrator
{
    public function __construct(
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentSourceRepository $sources,
        private readonly ?LoggerInterface $logger = null,
        private readonly ?ConversionLabels $labels = null,
    ) {
    }

    /**
     * @return array{converted: int, skipped: int, failed: int, dropped: array<string, int>}
     */
    public function migrateTenant(int $tenantId, bool $dryRun = false): array
    {
        $done = $this->sources->convertedTemplateIds($tenantId);
        $summary = ['converted' => 0, 'skipped' => 0, 'failed' => 0, 'dropped' => []];
        $converter = new LegacyTemplateConverter($this->labels ?? new ConversionLabels());

        foreach ($this->templates->listForTenant($tenantId) as $row) {
            $id = (int) $row['id'];
            if (isset($done[$id]) || self::isUneditedStarter($row)) {
                $summary['skipped']++;
                continue;
            }
            try {
                $doc = $converter->convert((string) $row['name'], is_array($row['data']) ? $row['data'] : []);
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
                        'created_by'          => $row['created_by'],
                        'owner_ou_id'         => $row['owner_ou_id'],
                        'legacy_template_id'  => $id,
                    ]);
                }
                $summary['converted']++;
            } catch (\Throwable $e) {
                $summary['failed']++;
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
