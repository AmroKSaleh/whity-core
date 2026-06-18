<?php

declare(strict_types=1);

namespace Whity\Sdk\Http\Exception;

/**
 * Raised when a multipart/form-data body cannot be parsed or violates a cap
 * (SDK v1.5).
 *
 * Carries a typed reason {@see self::reason()} and an HTTP-friendly status hint
 * {@see self::statusCode()} so the host can map upload failures to a response
 * without leaking internal parser detail. Never thrown for non-multipart
 * requests — those simply yield an empty uploaded-files bag.
 */
final class MultipartException extends \RuntimeException
{
    /** The raw body exceeded the configured global request-size cap. */
    public const REASON_REQUEST_TOO_LARGE = 'request_too_large';

    /** A single file part exceeded the configured per-file size cap. */
    public const REASON_FILE_TOO_LARGE = 'file_too_large';

    /** The Content-Type declared multipart but no usable boundary was present. */
    public const REASON_MISSING_BOUNDARY = 'missing_boundary';

    /** The body was structurally malformed for the declared boundary. */
    public const REASON_MALFORMED_BODY = 'malformed_body';

    /** A temp file could not be created to spill a file part. */
    public const REASON_TEMP_WRITE_FAILED = 'temp_write_failed';

    /**
     * @param string $reason One of the REASON_* constants.
     * @param string $message Human-readable, non-sensitive message.
     * @param int $statusCode HTTP status hint (413 for caps, 400 for malformed).
     */
    private function __construct(
        private readonly string $reason,
        string $message,
        private readonly int $statusCode,
    ) {
        parent::__construct($message);
    }

    /**
     * The raw body exceeded the global request-size cap (HTTP 413).
     *
     * @param int $limit The configured cap in bytes.
     * @return self The exception.
     */
    public static function requestTooLarge(int $limit): self
    {
        return new self(
            self::REASON_REQUEST_TOO_LARGE,
            sprintf('Request body exceeds the maximum of %d bytes.', $limit),
            413
        );
    }

    /**
     * A file part exceeded the per-file cap (HTTP 413).
     *
     * @param int $limit The configured per-file cap in bytes.
     * @return self The exception.
     */
    public static function fileTooLarge(int $limit): self
    {
        return new self(
            self::REASON_FILE_TOO_LARGE,
            sprintf('An uploaded file exceeds the maximum of %d bytes.', $limit),
            413
        );
    }

    /**
     * The multipart Content-Type carried no usable boundary (HTTP 400).
     *
     * @return self The exception.
     */
    public static function missingBoundary(): self
    {
        return new self(
            self::REASON_MISSING_BOUNDARY,
            'multipart/form-data request is missing a boundary.',
            400
        );
    }

    /**
     * The body was structurally malformed for the boundary (HTTP 400).
     *
     * @return self The exception.
     */
    public static function malformedBody(): self
    {
        return new self(
            self::REASON_MALFORMED_BODY,
            'multipart/form-data body is malformed.',
            400
        );
    }

    /**
     * A temp file could not be created to spill a part (HTTP 500).
     *
     * @return self The exception.
     */
    public static function tempWriteFailed(): self
    {
        return new self(
            self::REASON_TEMP_WRITE_FAILED,
            'Failed to write uploaded file to temporary storage.',
            500
        );
    }

    /**
     * The typed failure reason.
     *
     * @return string One of the REASON_* constants.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The HTTP status hint for this failure.
     *
     * @return int An HTTP status code (e.g. 400, 413, 500).
     */
    public function statusCode(): int
    {
        return $this->statusCode;
    }
}
