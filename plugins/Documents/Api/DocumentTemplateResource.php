<?php

declare(strict_types=1);

namespace Documents\Api;

/**
 * The {@see \Whity\Sdk\Sync\SyncableResource} descriptor for the Documents
 * plugin`s `document_templates` table -- a saved DocTemplate whose versioned
 * JSON lives verbatim in `data`. All shared behaviour lives in
 * {@see AbstractDocumentDesignerResource}; this only names the table, its change
 * sequence, and the empty-object placeholder a form-created template starts from.
 */
final class DocumentTemplateResource extends AbstractDocumentDesignerResource
{
    public function table(): string
    {
        return 'document_templates';
    }

    public function sequenceKey(): string
    {
        return 'documents:document_templates:change_seq';
    }

    protected function emptyDataJson(): string
    {
        return '{}';
    }
}
