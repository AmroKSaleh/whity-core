<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * The outcome of parsing a multipart/form-data body (SDK v1.5).
 *
 * Splits the body into (a) text fields — flat field-name => string-value pairs
 * — and (b) the uploaded-files bag, keyed by multipart field name. It is an
 * immutable carrier with no per-request statics, so the parser can build one
 * per request on a persistent worker without cross-request bleed.
 */
final class MultipartResult
{
    /**
     * @param array<string, string> $fields Text field values, keyed by field name.
     * @param array<string, UploadedFile> $uploadedFiles File parts, keyed by field name.
     */
    public function __construct(
        private readonly array $fields,
        private readonly array $uploadedFiles,
    ) {
    }

    /**
     * The parsed text fields (non-file parts).
     *
     * @return array<string, string> Field-name => string-value pairs.
     */
    public function getFields(): array
    {
        return $this->fields;
    }

    /**
     * The uploaded file parts.
     *
     * @return array<string, UploadedFile> File-field-name => UploadedFile.
     */
    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }
}
