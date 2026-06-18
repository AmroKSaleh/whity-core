<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

use Whity\Sdk\Http\Exception\MultipartException;

/**
 * Parses a multipart/form-data body into text fields and spilled file parts
 * (SDK v1.5).
 *
 * The parser is driven purely by a Content-Type string plus the raw body — it
 * never reads PHP's $_FILES / php://input superglobals — which makes it both
 * unit-testable and FrankenPHP worker-safe (it holds NO per-request state in
 * statics; callers build a fresh {@see MultipartResult} per request).
 *
 * Caps are enforced WHILE consuming the body: the global request-size cap is
 * checked before splitting, and each file part's size is checked against the
 * per-file cap as it is written, so an oversized upload is rejected without
 * being fully materialised in memory or on disk.
 */
final class MultipartParser
{
    public function __construct(private readonly MultipartConfig $config)
    {
    }

    /**
     * Detect whether a Content-Type header value declares multipart/form-data.
     *
     * @param string|null $contentType The raw Content-Type header value.
     * @return bool True when the type is multipart/form-data.
     */
    public static function isMultipart(?string $contentType): bool
    {
        if ($contentType === null) {
            return false;
        }

        return str_starts_with(
            ltrim(strtolower($contentType)),
            'multipart/form-data'
        );
    }

    /**
     * Parse a multipart/form-data body.
     *
     * @param string $contentType The request Content-Type header (carries the boundary).
     * @param string $body The raw request body.
     * @return MultipartResult The parsed text fields and uploaded files.
     * @throws MultipartException On a missing boundary, a malformed body, a
     *                            cap violation, or a temp-write failure.
     */
    public function parse(string $contentType, string $body): MultipartResult
    {
        if (strlen($body) > $this->config->getMaxRequestBytes()) {
            throw MultipartException::requestTooLarge($this->config->getMaxRequestBytes());
        }

        $boundary = self::extractBoundary($contentType);
        if ($boundary === null) {
            throw MultipartException::missingBoundary();
        }

        $fields = [];
        $files = [];

        // Track temp files written so far so a mid-parse failure does not leak
        // them. On any throw we unlink everything spilled before re-throwing.
        $spilled = [];

        try {
            foreach ($this->splitParts($body, $boundary) as $part) {
                $parsed = self::parsePartHeaders($part);
                if ($parsed === null) {
                    // A part with no usable Content-Disposition name is skipped
                    // rather than failing the whole request (lenient like PHP).
                    continue;
                }

                [$name, $filename, $mediaType, $content] = $parsed;

                if ($filename === null) {
                    // Text field: keep it in the flat field bag.
                    $fields[$name] = $content;
                    continue;
                }

                if (strlen($content) > $this->config->getMaxFileBytes()) {
                    throw MultipartException::fileTooLarge($this->config->getMaxFileBytes());
                }

                $tmpPath = $this->spill($content);
                $spilled[] = $tmpPath;

                $files[$name] = new UploadedFile(
                    $tmpPath,
                    strlen($content),
                    UPLOAD_ERR_OK,
                    $filename,
                    $mediaType
                );
            }
        } catch (\Throwable $e) {
            foreach ($spilled as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            throw $e;
        }

        return new MultipartResult($fields, $files);
    }

    /**
     * Extract the boundary token from a multipart Content-Type header.
     *
     * @param string $contentType The Content-Type header value.
     * @return string|null The boundary, or null when absent/empty.
     */
    private static function extractBoundary(string $contentType): ?string
    {
        if (preg_match('/boundary="?([^";]+)"?/i', $contentType, $m) !== 1) {
            return null;
        }

        $boundary = trim($m[1]);

        return $boundary === '' ? null : $boundary;
    }

    /**
     * Split a multipart body into raw part blocks (headers + body per part).
     *
     * @param string $body The raw request body.
     * @param string $boundary The boundary token (without leading dashes).
     * @return list<string> The raw part blocks, preamble/epilogue stripped.
     * @throws MultipartException When the delimiter is absent from the body.
     */
    private function splitParts(string $body, string $boundary): array
    {
        $delimiter = '--' . $boundary;

        if (!str_contains($body, $delimiter)) {
            throw MultipartException::malformedBody();
        }

        $segments = explode($delimiter, $body);

        // Drop the preamble (before the first delimiter) and the epilogue/closing
        // delimiter remainder (after the final "--").
        array_shift($segments);

        $parts = [];
        foreach ($segments as $segment) {
            // The closing delimiter leaves a leading "--"; that marks the end.
            if (str_starts_with($segment, '--')) {
                break;
            }

            // Strip the CRLF that follows the delimiter and the trailing CRLF
            // that precedes the next delimiter.
            $segment = preg_replace('/^\r\n/', '', $segment) ?? $segment;
            $segment = preg_replace('/\r\n$/', '', $segment) ?? $segment;

            if ($segment !== '') {
                $parts[] = $segment;
            }
        }

        return $parts;
    }

    /**
     * Parse a single part block's headers and split off its content.
     *
     * @param string $part A raw part block (headers, blank line, then content).
     * @return array{0:string,1:?string,2:?string,3:string}|null
     *         [name, filename|null, mediaType|null, content], or null when the
     *         part carries no usable Content-Disposition field name.
     */
    private static function parsePartHeaders(string $part): ?array
    {
        $split = preg_split('/\r\n\r\n/', $part, 2);
        if ($split === false || count($split) !== 2) {
            return null;
        }

        [$rawHeaders, $content] = $split;

        $name = null;
        $filename = null;
        $mediaType = null;

        foreach (preg_split('/\r\n/', $rawHeaders) ?: [] as $headerLine) {
            if (stripos($headerLine, 'content-disposition:') === 0) {
                if (preg_match('/name="([^"]*)"/i', $headerLine, $m) === 1) {
                    $name = $m[1];
                }
                if (preg_match('/filename="([^"]*)"/i', $headerLine, $m) === 1) {
                    $filename = $m[1];
                }
            } elseif (stripos($headerLine, 'content-type:') === 0) {
                $mediaType = trim(substr($headerLine, strlen('content-type:')));
            }
        }

        if ($name === null) {
            return null;
        }

        return [$name, $filename, $mediaType, $content];
    }

    /**
     * Write a file part's bytes to a fresh temp file in the configured dir.
     *
     * @param string $content The file part's raw bytes.
     * @return string The temp file path.
     * @throws MultipartException When the temp file cannot be created/written.
     */
    private function spill(string $content): string
    {
        $tmpPath = @tempnam($this->config->getTempDir(), 'wc-upload-');
        if ($tmpPath === false) {
            throw MultipartException::tempWriteFailed();
        }

        if (@file_put_contents($tmpPath, $content) === false) {
            @unlink($tmpPath);
            throw MultipartException::tempWriteFailed();
        }

        return $tmpPath;
    }
}
