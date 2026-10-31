<?php

declare(strict_types=1);

namespace Whity\Core\Document\Fields;

use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Organizer\DocumentCriteria;
use Whity\Core\RBAC\CorePermissions;

/**
 * Core's own record type: an issued document, so a template (a cover letter,
 * a receipt for a document) can be written and previewed before any plugin
 * adds invoices or customers. Visibility is the document library's.
 */
final class IssuedDocumentFieldProvider implements DocumentFieldProvider
{
    public const TYPE = 'document';

    /** How many recent documents the record picker searches. */
    private const SEARCH_WINDOW = 200;

    private FieldLabels $labels;

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentVisibilityPolicy $visibility,
        ?FieldLabels $labels = null,
    ) {
        $this->labels = $labels ?? new FieldLabels();
    }

    public function recordType(): string
    {
        return self::TYPE;
    }

    public function recordLabel(): string
    {
        return $this->labels->get('fields.group.document', 'Document');
    }

    public function groups(int $tenantId): array
    {
        return [[
            'group' => $this->recordLabel(),
            'items' => [
                ['path' => 'document.title', 'label' => $this->labels->get('fields.document.title', 'Document title'), 'sample' => 'Payment reminder'],
                ['path' => 'document.number', 'label' => $this->labels->get('fields.document.number', 'Document number'), 'sample' => '1042', 'required' => true],
                ['path' => 'document.template', 'label' => $this->labels->get('fields.document.template', 'Template'), 'sample' => 'Letters'],
                ['path' => 'document.issued', 'label' => $this->labels->get('fields.document.issued', 'Issued on'), 'sample' => '29 October 2026'],
            ],
        ]];
    }

    public function values(int $tenantId, int $callerId, callable $hasPermission, ?string $recordId): ?array
    {
        if ($recordId === null || !ctype_digit($recordId) || !$hasPermission(CorePermissions::DOCUMENTS_READ)) {
            return null;
        }
        $document = $this->documents->findById((int) $recordId, $tenantId);
        if ($document === null || !$this->visibility->canView($document, $callerId, $hasPermission)) {
            return null;
        }
        $template = trim((string) ($document['template_name'] ?? ''));
        $issued = $this->date((string) ($document['created_at'] ?? ''));

        return [
            'label' => $this->recordName($document),
            'values' => [
                'document.title' => (string) $document['title'],
                'document.number' => (string) $document['id'],
                'document.template' => $template !== '' ? $template : null,
                'document.issued' => $issued,
            ],
        ];
    }

    public function records(int $tenantId, int $callerId, callable $hasPermission, string $query, int $limit): array
    {
        if (!$hasPermission(CorePermissions::DOCUMENTS_READ)) {
            return [];
        }
        $criteria = DocumentCriteria::unfiltered()->withRequestScope($this->visibility->restrictToCreator($callerId, $hasPermission), null);
        $rows = $this->documents->listForCriteria($tenantId, $criteria, self::SEARCH_WINDOW, 0);
        $q = mb_strtolower(trim($query));
        $out = [];
        foreach ($rows as $row) {
            $label = $this->recordName($row);
            if ($q !== '' && !str_contains(mb_strtolower($label), $q)) {
                continue;
            }
            $out[] = ['id' => (string) $row['id'], 'label' => $label];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * "#1042 · Payment reminder": the number stays Western digits.
     *
     * @param array<string, mixed> $document
     */
    private function recordName(array $document): string
    {
        return '#' . (int) $document['id'] . ' · ' . (string) $document['title'];
    }

    private function date(string $stamp): ?string
    {
        if ($stamp === '') {
            return null;
        }
        try {
            return $this->labels->date(new \DateTimeImmutable($stamp));
        } catch (\Exception) {
            return null;
        }
    }
}
