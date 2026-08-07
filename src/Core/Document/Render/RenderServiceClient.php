<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * Internal HTTP client for the `whity_render` microservice (ADR 0012 /
 * WC-docdesigner Track 2): POSTs the assembled render payload
 * (`{template, dataRows, sheet, blocks}`) to the service's `POST /render` and
 * returns the raw PDF bytes.
 *
 * Uses the codebase's established `file_get_contents` + `stream_context` idiom
 * (mirrors {@see \Whity\Storage\S3\StreamObjectHttpTransport}) rather than curl
 * or a new Composer HTTP-client dependency. Like that transport, this
 * deliberately applies NO public-IP/SSRF guard (unlike
 * {@see \Whity\Core\Http\HttpFetcher}) — the target is OPERATOR-configured
 * internal infrastructure (the `RENDER_SERVICE_URL` env var, typically the
 * `render` compose service's in-network address), not user input.
 *
 * Every failure mode — connection refused (service/profile not running),
 * DNS failure, timeout, non-200 status, or a 200 body that does not look like
 * a PDF — is normalised to {@see RenderServiceUnavailableException}. The
 * caller (DocumentRenderApiHandler) catches this and returns a generic 503;
 * the real reason is logged here (server-side only) via error_log, never
 * leaked to the client (WC-186).
 */
final class RenderServiceClient implements RenderServiceClientInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sharedSecret,
        private readonly int $timeoutSeconds = 30,
        private readonly int $maxBytes = 52428800, // 50 MiB PDF read cap
    ) {
    }

    /**
     * Whether this client is USABLE: a non-empty base URL and a shared secret
     * of at least 32 chars (the project's minimum-secret-length rule). A
     * caller should treat an unusable client the same as "render disabled" —
     * calling {@see render()} anyway still fails safely, but checking this
     * first avoids even attempting the request and lets the handler log a
     * clearer diagnostic.
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && strlen($this->sharedSecret) >= 32;
    }

    /**
     * POST the render payload to `whity_render` and return the raw PDF bytes.
     *
     * @param array<string, mixed> $payload {template, dataRows, sheet, blocks}
     * @throws RenderServiceUnavailableException On any transport failure, a
     *         non-200 response, or a 200 body that is not a PDF.
     */
    public function render(array $payload): string
    {
        if (!$this->isConfigured()) {
            throw new RenderServiceUnavailableException(
                'RenderServiceClient is not configured (missing RENDER_SERVICE_URL / RENDER_SHARED_SECRET, or the secret is under 32 chars)'
            );
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RenderServiceUnavailableException('Failed to encode the render payload as JSON: ' . json_last_error_msg());
        }

        $url = rtrim($this->baseUrl, '/') . '/render';
        $headerLines = "Content-Type: application/json\r\n"
            . "Accept: application/pdf\r\n"
            . 'X-Render-Secret: ' . $this->sharedSecret . "\r\n";

        $context = stream_context_create([
            'http' => [
                'method'          => 'POST',
                'header'          => $headerLines,
                'content'         => $body,
                'timeout'         => $this->timeoutSeconds,
                'ignore_errors'   => true, // read the body on 4xx/5xx instead of failing
                'max_redirects'   => 0,
                'follow_location' => 0,
            ],
        ]);

        // Pre-declare so it is always defined; the stream wrapper overwrites it
        // in the local scope with the response header lines after the fetch.
        $http_response_header = [];
        $raw = @file_get_contents($url, false, $context, 0, max(0, $this->maxBytes));
        /** @var list<string> $lines */
        $lines = $http_response_header;

        if (!is_string($raw) && $lines === []) {
            error_log('[RenderServiceClient] request failed: POST ' . $url . ' (connection/transport error)');
            throw new RenderServiceUnavailableException('POST ' . $url . ' failed (connection/transport error)');
        }

        $status = self::parseStatus($lines);
        if ($status !== 200) {
            error_log('[RenderServiceClient] render service returned HTTP ' . $status . ' for POST ' . $url);
            throw new RenderServiceUnavailableException('Render service returned HTTP ' . $status);
        }

        if (!is_string($raw) || !str_starts_with($raw, '%PDF-')) {
            error_log('[RenderServiceClient] render service returned a 200 response that is not a PDF');
            throw new RenderServiceUnavailableException('Render service response does not look like a PDF');
        }

        return $raw;
    }

    /**
     * @param list<string> $lines Raw response header lines ($http_response_header).
     */
    private static function parseStatus(array $lines): int
    {
        $status = 0;
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // A redirect chain would reset this; max_redirects=0 keeps one status.
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}
