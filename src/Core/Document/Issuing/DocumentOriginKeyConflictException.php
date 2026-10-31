<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

use RuntimeException;

/**
 * An origin key already names a document issued from a different template
 * (DOCUMENT-AUTOMATION.md 3.8): "the invoice for order 123" can't be two
 * different documents. Answered as 409 `origin_key_conflict`.
 */
final class DocumentOriginKeyConflictException extends RuntimeException
{
    public function __construct(public readonly int $documentId)
    {
        parent::__construct('This origin key already names another document');
    }
}
