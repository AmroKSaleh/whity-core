<?php

declare(strict_types=1);

namespace Whity\Core\Document\Embed;

use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\RBAC\CorePermissions;

/**
 * Issued documents as Record blocks: `{ type: "document", id: "42" }`.
 *
 * Visible exactly when the reader could open the document itself: they hold
 * documents:read and {@see DocumentVisibilityPolicy} lets them see the row
 * (their own, routed to them, granted to them, or documents:read_all).
 */
final class IssuedDocumentRecordProvider implements DocumentRecordProvider
{
    private EmbedLabels $labels;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentVisibilityPolicy $visibility,
        ?EmbedLabels $labels = null,
    ) {
        $this->labels = $labels ?? new EmbedLabels();
    }

    public function type(): string
    {
        return 'document';
    }

    public function label(): string
    {
        return $this->labels->get('embeds.record.document', 'Issued document');
    }

    public function resolve(int $tenantId, int $callerId, callable $hasPermission, string $id): ?array
    {
        if (!ctype_digit($id) || !$hasPermission(CorePermissions::DOCUMENTS_READ)) {
            return null;
        }
        $document = $this->documents->findById((int) $id, $tenantId);
        if ($document === null || !$this->visibility->canView($document, $callerId, $hasPermission)) {
            return null;
        }

        $template = (string) ($document['template_name'] ?? '');

        return [
            'type_label' => $this->label(),
            'title' => (string) $document['title'],
            'subtitle' => $template !== '' ? $template : null,
            'href' => '/admin/document-library/' . (int) $document['id'],
        ];
    }
}
