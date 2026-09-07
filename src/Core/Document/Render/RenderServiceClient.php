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
    /** Largest error body {@see clientErrorFrom()} will even parse. */
    private const MAX_ERROR_BODY_BYTES = 8192;

    /** Longest relayed sentence. A field name and a rule, not a document. */
    private const MAX_ERROR_CHARS = 500;

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
        return $this->postForPdf('/render', $payload)[0];
    }

    /**
     * The flowing mode: POST to `/render/flow` and return the bytes together
     * with the page counts the service reports in its response headers.
     *
     * @param array<string, mixed> $payload {page, direction, content, ...}
     * @throws RenderServiceUnavailableException On any transport failure, a
     *         non-200 response, or a 200 body that is not a PDF.
     */
    public function renderFlow(array $payload): RenderedDocument
    {
        [$pdf, $lines] = $this->postForPdf('/render/flow', $payload);

        return new RenderedDocument(
            $pdf,
            self::headerInt($lines, 'x-render-page-count'),
            self::headerInt($lines, 'x-render-front-matter-pages'),
        );
    }

    /**
     * The transport both modes share: encode, POST, and refuse anything that
     * is not a 200 carrying a PDF.
     *
     * Returns the bytes AND the raw response header lines, because the flowing
     * mode's page counts arrive as headers and the fixed-canvas mode's do not
     * exist — reading them here, once, keeps a single place that knows how the
     * service answers.
     *
     * @param array<string, mixed> $payload
     * @return array{0: string, 1: list<string>}
     * @throws RenderServiceUnavailableException
     */
    private function postForPdf(string $path, array $payload): array
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

        $url = rtrim($this->baseUrl, '/') . $path;
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

        // A 422 is the CALLER's problem, and it is the only status where the
        // service's own words are worth more than ours.
        //
        // Every other non-200 stays "unavailable". This one does not, because
        // collapsing it there tells a caller who sent an unacceptable payload to
        // retry a request that can never succeed — a 503 "temporarily
        // unavailable" for a permanent, entirely fixable fault, with the sentence
        // naming the offending field discarded on this line. The flowing mode
        // makes that acute: `POST /render/flow` validates a whole content TREE
        // (block types, heading levels, table row shapes, figure `data:` URIs,
        // front-matter kinds) and answers `"content[3].level" must be a whole
        // number 1-6`. Nothing core knows can reconstruct that string, and the
        // alternative — a second copy of those rules in PHP — is two validators
        // that drift, which is how this repository has already shipped a limit
        // enforced in one place and not the other.
        //
        // WHAT IS RELAYED IS BOUNDED ON PURPOSE (WC-186). Only a 422, only the
        // `error` STRING of a JSON body, and only up to a sane length: these are
        // strings authored in render-service/src/{limits,flow/document}.js to be
        // read by a caller, not wrapped internal errors. A 422 with any other
        // body shape falls through to the generic rejection rather than
        // forwarding whatever arrived.
        if ($status === 422) {
            error_log('[RenderServiceClient] render service rejected the payload (422) for POST ' . $url);

            throw DocumentRenderRejectedException::because(
                self::clientErrorFrom(is_string($raw) ? $raw : '')
                    ?? 'The render service rejected this payload'
            );
        }

        if ($status !== 200) {
            error_log('[RenderServiceClient] render service returned HTTP ' . $status . ' for POST ' . $url);
            throw new RenderServiceUnavailableException('Render service returned HTTP ' . $status);
        }

        if (!is_string($raw) || !str_starts_with($raw, '%PDF-')) {
            error_log('[RenderServiceClient] render service returned a 200 response that is not a PDF');
            throw new RenderServiceUnavailableException('Render service response does not look like a PDF');
        }

        return [$raw, $lines];
    }

    /**
     * The client-safe sentence out of a render-service error body, or null when
     * the body is not the shape this service documents.
     *
     * Null rather than a best-effort excerpt: an unrecognised body is, by
     * definition, not a string anyone wrote for a caller, and forwarding it
     * would be the leak WC-186 forbids. The length cap is the same reasoning
     * applied to a body that IS the right shape but has grown unreasonably —
     * a validator naming a field needs a sentence, not a kilobyte.
     */
    private static function clientErrorFrom(string $body): ?string
    {
        if ($body === '' || strlen($body) > self::MAX_ERROR_BODY_BYTES) {
            return null;
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded) || !is_string($decoded['error'] ?? null)) {
            return null;
        }

        $message = trim($decoded['error']);

        return $message === '' ? null : mb_substr($message, 0, self::MAX_ERROR_CHARS);
    }

    /**
     * A non-negative integer response header, or 0 when it is absent or not a
     * number.
     *
     * Zero rather than null on purpose: every caller of this wants a count to
     * store, and "the service did not say" and "the service said nothing was
     * produced" are the same non-answer as far as a stored page count goes.
     *
     * @param list<string> $lines Raw response header lines.
     */
    private static function headerInt(array $lines, string $name): int
    {
        $needle = strtolower($name) . ':';
        foreach ($lines as $line) {
            if (!str_starts_with(strtolower($line), $needle)) {
                continue;
            }
            $value = trim(substr($line, strlen($needle)));
            if (preg_match('/^\d+$/', $value) === 1) {
                return (int) $value;
            }
        }

        return 0;
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
