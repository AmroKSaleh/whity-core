<?php

declare(strict_types=1);

namespace Whity\Native;

/**
 * HTTP client for the Rust native bridge — a local loopback server the Tauri
 * app runs so offline plugin code can reach hardware (printers now, scanners
 * later). Mirrors whity-core's own RenderServiceClient exactly: the
 * `file_get_contents` + `stream_context_create` idiom (no curl, no new
 * Composer dependency), a shared-secret header, and every transport failure
 * normalized to one typed exception.
 */
final class NativeBridgeClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $sharedSecret,
        private readonly int $timeoutSeconds = 10,
    ) {
    }

    /**
     * A non-empty base URL and a shared secret of at least 32 chars (this
     * codebase's minimum-secret-length rule, matching RenderServiceClient).
     */
    public function isConfigured(): bool
    {
        return $this->baseUrl !== '' && strlen($this->sharedSecret) >= 32;
    }

    public static function fromEnv(): self
    {
        return new self(
            rtrim((string) ($_ENV['WHITY_NATIVE_BRIDGE_URL'] ?? ''), '/'),
            (string) ($_ENV['WHITY_NATIVE_BRIDGE_SECRET'] ?? ''),
        );
    }

    /**
     * Print `$text` to the OS default printer via Rust. Returns the printer
     * name Rust reports, mirroring commands/printer.rs's print_text_impl().
     *
     * @throws NativeBridgeUnavailableException On any transport failure, a
     *         non-200 response, or an unparsable body.
     */
    public function print(string $text): string
    {
        $decoded = $this->post('/native/print', ['text' => $text]);
        $printer = $decoded['printer'] ?? null;

        return is_string($printer) ? $printer : '';
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     * @throws NativeBridgeUnavailableException
     */
    private function post(string $path, array $payload): array
    {
        if (!$this->isConfigured()) {
            throw new NativeBridgeUnavailableException(
                'NativeBridgeClient is not configured (missing native-bridge URL, or the secret is under 32 chars)'
            );
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new NativeBridgeUnavailableException('Failed to encode the native-bridge payload as JSON: ' . json_last_error_msg());
        }

        $url = $this->baseUrl . $path;
        $headerLines = "Content-Type: application/json\r\n"
            . 'X-Native-Bridge-Secret: ' . $this->sharedSecret . "\r\n";

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

        $http_response_header = [];
        $raw = @file_get_contents($url, false, $context);
        /** @var list<string> $lines */
        $lines = $http_response_header;

        if (!is_string($raw) && $lines === []) {
            error_log('[NativeBridgeClient] request failed: POST ' . $url . ' (connection/transport error)');
            throw new NativeBridgeUnavailableException('POST ' . $url . ' failed (connection/transport error)');
        }

        $status = self::parseStatus($lines);
        if ($status !== 200) {
            error_log('[NativeBridgeClient] native bridge returned HTTP ' . $status . ' for POST ' . $url);
            throw new NativeBridgeUnavailableException('Native bridge returned HTTP ' . $status);
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            throw new NativeBridgeUnavailableException('Native bridge response was not valid JSON');
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * @param list<string> $lines Raw response header lines ($http_response_header).
     */
    private static function parseStatus(array $lines): int
    {
        $status = 0;
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                $status = (int) $m[1];
            }
        }

        return $status;
    }
}
