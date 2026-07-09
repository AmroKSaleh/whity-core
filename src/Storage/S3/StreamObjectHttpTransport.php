<?php

declare(strict_types=1);

namespace Whity\Storage\S3;

use Whity\Storage\StorageException;

/**
 * Real {@see ObjectHttpTransport} using the codebase's established
 * `file_get_contents` + `stream_context` idiom (no curl/AWS-SDK dependency),
 * supporting arbitrary verbs, binary bodies and response-header capture
 * (WC-b8c5a271).
 *
 * NOTE: unlike {@see \Whity\Core\Http\HttpFetcher}, this transport deliberately
 * does NOT apply the public-IP SSRF guard — the S3 endpoint is OPERATOR-configured
 * global infrastructure and may legitimately be a private-network MinIO in a
 * sovereign deployment. The endpoint is trusted config, not user input.
 */
final class StreamObjectHttpTransport implements ObjectHttpTransport
{
    public function __construct(
        private readonly int $timeoutSeconds = 15,
        private readonly int $maxBytes = 104857600, // 100 MiB read cap
    ) {
    }

    public function send(string $method, string $url, array $headers, ?string $body): ObjectHttpResponse
    {
        $headerLines = '';
        foreach ($headers as $name => $value) {
            $headerLines .= $name . ': ' . $value . "\r\n";
        }

        $context = stream_context_create([
            'http' => [
                'method'          => strtoupper($method),
                'header'          => $headerLines,
                'content'         => $body ?? '',
                'timeout'         => $this->timeoutSeconds,
                'ignore_errors'   => true, // read the body on 4xx/5xx instead of failing
                'max_redirects'   => 0,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        // Pre-declare so it is always defined; the stream wrapper overwrites it in
        // the local scope with the response header lines after the fetch.
        $http_response_header = [];
        $raw = @file_get_contents($url, false, $context, 0, max(0, $this->maxBytes));
        /** @var list<string> $lines */
        $lines = $http_response_header;

        if (!is_string($raw) && $lines === []) {
            throw new StorageException('object store request failed: ' . $method . ' ' . self::redactUrl($url));
        }

        [$status, $parsed] = self::parseHeaders($lines);
        return new ObjectHttpResponse($status, $parsed, is_string($raw) ? $raw : '');
    }

    /**
     * @param list<string> $lines Raw response header lines ($http_response_header).
     * @return array{0: int, 1: array<string, string>}
     */
    private static function parseHeaders(array $lines): array
    {
        $status = 0;
        $headers = [];
        foreach ($lines as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m) === 1) {
                // A redirect chain would reset this; max_redirects=0 keeps one status.
                $status = (int) $m[1];
                $headers = [];
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon !== false) {
                $name = strtolower(trim(substr($line, 0, $colon)));
                $headers[$name] = trim(substr($line, $colon + 1));
            }
        }
        return [$status, $headers];
    }

    /**
     * Strip the query string (a presigned URL would carry a signature) before
     * putting a URL in an exception message.
     */
    private static function redactUrl(string $url): string
    {
        $q = strpos($url, '?');
        return $q === false ? $url : substr($url, 0, $q);
    }
}
