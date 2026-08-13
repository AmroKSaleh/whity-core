<?php

declare(strict_types=1);

namespace Whity\Sdk\Http;

/**
 * A response that streams its body through a Closure rather than holding the
 * complete body as a string in worker memory.
 *
 * Use this for file downloads, large exports, or any response where buffering
 * the full body would exhaust FrankenPHP worker memory. The Closure is only
 * invoked during send(), not at construction time.
 *
 * Range requests (RFC 7233) are handled by the {@see fromFile()} factory:
 *   - No Range header   → 200 + full file streamed via fpassthru
 *   - Valid range        → 206 + Content-Range + only the requested bytes
 *   - Out-of-bounds      → 416 + Content-Range: bytes * /{size}
 *
 * X-Accel-Redirect offloading (Caddy / Nginx) is available via
 * {@see xAccelRedirect()} — set the header, emit an empty body, and let the
 * upstream server read the file directly without involving PHP's I/O path.
 */
final class StreamedResponse extends Response
{
    /** @var \Closure(): void */
    private \Closure $streamer;

    /**
     * @param int                    $statusCode HTTP status code.
     * @param \Closure(): void       $streamer   Called during send() to emit the body.
     * @param array<string, string>  $headers    Response headers.
     */
    public function __construct(int $statusCode, \Closure $streamer, array $headers = [])
    {
        parent::__construct($statusCode, '', $headers);
        $this->streamer = $streamer;
    }

    /**
     * Emit the response: status line, headers, then the streamer output.
     *
     * {@inheritDoc}
     */
    public function send(): void
    {
        if (headers_sent()) {
            throw new \RuntimeException('Headers already sent');
        }

        http_response_code($this->getStatusCode());

        foreach ($this->getHeaders() as $name => $value) {
            header("{$name}: {$value}");
        }

        ($this->streamer)();
    }

    /**
     * Return a copy of this response with additional headers merged in.
     *
     * Overrides the parent to carry the streamer reference forward rather than
     * the empty string body.
     *
     * @param array<string, string> $extraHeaders
     * @return static
     */
    public function withHeaders(array $extraHeaders): static
    {
        return new static(
            $this->getStatusCode(),
            $this->streamer,
            array_merge($this->getHeaders(), $extraHeaders)
        );
    }

    /**
     * Create a StreamedResponse that serves a file from disk.
     *
     * Supports RFC 7233 Range requests (single range only). Pass the value of
     * the incoming `Range` header as $rangeHeader; omit or pass null for a
     * full-file 200 response.
     *
     * @param string                $path         Absolute path to the file.
     * @param string                $contentType  MIME type for Content-Type.
     * @param string                $disposition  'attachment' or 'inline'.
     * @param string|null           $filename     Filename in Content-Disposition; defaults to basename($path).
     * @param string|null           $rangeHeader  Value of the Range request header, e.g. 'bytes=0-1023'.
     * @param array<string, string> $extraHeaders Additional headers to include.
     * @return self
     * @throws \InvalidArgumentException If the file does not exist or is not readable.
     */
    public static function fromFile(
        string $path,
        string $contentType = 'application/octet-stream',
        string $disposition = 'attachment',
        ?string $filename = null,
        ?string $rangeHeader = null,
        array $extraHeaders = []
    ): self {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException("File not found or not readable: {$path}");
        }

        $fileSize = (int) filesize($path);
        $fname    = $filename ?? basename($path);

        $base = array_merge([
            'Content-Type'        => $contentType,
            'Content-Disposition' => "{$disposition}; filename=\"{$fname}\"",
            'Accept-Ranges'       => 'bytes',
        ], $extraHeaders);

        if ($rangeHeader !== null && preg_match('/^bytes=(\d*)-(\d*)$/', $rangeHeader, $m)) {
            [$start, $end] = self::parseRange($m[1], $m[2], $fileSize);

            if ($start === null || $start > $end || $start >= $fileSize) {
                return new self(416, static function () {}, array_merge($base, [
                    'Content-Range' => "bytes */{$fileSize}",
                ]));
            }

            $length = $end - $start + 1;

            return new self(206, static function () use ($path, $start, $length) {
                $fp = fopen($path, 'rb');
                if ($fp === false) {
                    return;
                }
                fseek($fp, $start);
                $remaining = $length;
                while ($remaining > 0 && !feof($fp)) {
                    $chunk = fread($fp, (int) min(8192, $remaining));
                    if ($chunk === false || $chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
                fclose($fp);
            }, array_merge($base, [
                'Content-Length' => (string) $length,
                'Content-Range'  => "bytes {$start}-{$end}/{$fileSize}",
            ]));
        }

        return new self(200, static function () use ($path) {
            $fp = fopen($path, 'rb');
            if ($fp === false) {
                return;
            }
            fpassthru($fp);
            fclose($fp);
        }, array_merge($base, [
            'Content-Length' => (string) $fileSize,
        ]));
    }

    /**
     * Create a response that instructs the upstream server to serve the file
     * directly via X-Accel-Redirect (Caddy, Nginx) or X-Sendfile (Apache),
     * bypassing PHP's body-streaming path entirely.
     *
     * Requires matching configuration in the Caddyfile / Nginx virtual host:
     * the $internalPath must map to an internal location the web server can
     * read. No file I/O happens in PHP — the response body is empty.
     *
     * @param string                $internalPath Internal path the web server will serve.
     * @param string                $contentType  MIME type for Content-Type.
     * @param string                $disposition  'attachment' or 'inline'.
     * @param string|null           $filename     Optional filename in Content-Disposition.
     * @param array<string, string> $extraHeaders Additional headers.
     * @return self
     */
    public static function xAccelRedirect(
        string $internalPath,
        string $contentType = 'application/octet-stream',
        string $disposition = 'attachment',
        ?string $filename = null,
        array $extraHeaders = []
    ): self {
        $dispositionValue = $filename !== null
            ? "{$disposition}; filename=\"{$filename}\""
            : $disposition;

        return new self(200, static function () {}, array_merge([
            'X-Accel-Redirect'    => $internalPath,
            'Content-Type'        => $contentType,
            'Content-Disposition' => $dispositionValue,
        ], $extraHeaders));
    }

    /**
     * Parse a bytes range spec (\d*)-(\d*) into [start, end].
     *
     * Handles three forms:
     *   N-M  → [$N, $M]
     *   N-   → [$N, $fileSize-1]
     *   -M   → suffix: last M bytes → [$fileSize-M, $fileSize-1]
     *
     * Returns [null, 0] when the spec is entirely empty (degenerate).
     *
     * @return array{0: int|null, 1: int}
     */
    private static function parseRange(string $rawStart, string $rawEnd, int $fileSize): array
    {
        if ($rawStart === '' && $rawEnd !== '') {
            // Suffix form: bytes=-N
            $suffix = (int) $rawEnd;
            $start  = max(0, $fileSize - $suffix);
            return [$start, $fileSize - 1];
        }

        if ($rawStart === '' && $rawEnd === '') {
            return [null, 0];
        }

        $start = (int) $rawStart;
        $end   = $rawEnd !== '' ? (int) $rawEnd : $fileSize - 1;

        return [$start, min($end, $fileSize - 1)];
    }
}
