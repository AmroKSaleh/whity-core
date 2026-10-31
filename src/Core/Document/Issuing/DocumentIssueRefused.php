<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

/**
 * Why a document wasn't issued (WHIT-742, DOCUMENT-AUTOMATION.md 3.9): a
 * stable `code` a caller can act on, the HTTP status the REST route answers
 * with, and details such as the missing fields. Nothing was written.
 *
 * The message is written for the caller: {@see self::$clientMessage} is what
 * a response may carry, unlike the text of an unexpected exception.
 *
 * Codes: `invalid_request`, `template_not_found`, `record_not_found`,
 * `template_unpublished`, `concurrent_edit`, `slot_unavailable`,
 * `slot_unbound`, `slot_misfit`, `missing_fields`, `field_conflict`,
 * `vetoed`, `origin_key_conflict`, `render_unavailable`,
 * `persist_unavailable`.
 */
final class DocumentIssueRefused extends \RuntimeException
{
    public readonly string $clientMessage;

    /** @param array<string, mixed> $details */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly int $status,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
        $this->clientMessage = $message;
    }

    /** @param array<string, mixed> $details */
    public static function invalid(string $message, array $details = []): self
    {
        return new self('invalid_request', $message, 422, $details);
    }
}
