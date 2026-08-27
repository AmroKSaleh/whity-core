<?php

declare(strict_types=1);

namespace Whity\Http;

/**
 * A multipart file part could not be read, for a reason the caller can act on.
 *
 * TWO MESSAGES AND A STATUS, all as FIELDS — the same construction
 * {@see \Whity\Core\Form\FormRejectedException} uses and for the same reason
 * (WC-186): `$clientMessage` is written for the caller and is safe to return
 * verbatim, while `getMessage()` may name a temp path, a PHP error code, or an
 * ini setting an operator needs to see.
 *
 * The STATUS travels with the message because the two are one decision. "No file
 * part" is a malformed request (400); "too big for PHP" is a refused entity
 * (422); and a handler that had to re-derive which is which from the wording
 * would eventually get it wrong on one of its surfaces but not the other.
 */
final class UploadedFilePartException extends \RuntimeException
{
    /**
     * @param string $clientMessage Text written for the caller, safe to return.
     * @param int    $status        The HTTP status this should be returned as.
     * @param string $logMessage    Text for the operator; defaults to the client
     *                              message when there is nothing extra to say.
     */
    public function __construct(
        public readonly string $clientMessage,
        public readonly int $status,
        string $logMessage = '',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($logMessage !== '' ? $logMessage : $clientMessage, 0, $previous);
    }
}
