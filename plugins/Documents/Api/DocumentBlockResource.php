<?php

declare(strict_types=1);

namespace Documents\Api;

/**
 * The {@see \Whity\Sdk\Sync\SyncableResource} descriptor for the Documents
 * plugin`s `document_blocks` table -- a reusable block (a DocElement[] fragment
 * in `data`) that documents reference by pointer so edits propagate. All shared
 * behaviour lives in {@see AbstractDocumentDesignerResource}; this only names the
 * table, its change sequence, and the empty-list placeholder a form-created block
 * starts from.
 */
final class DocumentBlockResource extends AbstractDocumentDesignerResource
{
    public function table(): string
    {
        return 'document_blocks';
    }

    public function sequenceKey(): string
    {
        return 'documents:document_blocks:change_seq';
    }

    protected function emptyDataJson(): string
    {
        return '[]';
    }
}
