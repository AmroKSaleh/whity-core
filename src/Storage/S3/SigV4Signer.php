<?php

declare(strict_types=1);

namespace Whity\Storage\S3;

/**
 * AWS Signature Version 4 signer for S3-compatible object storage
 * (WC-b8c5a271).
 *
 * Hand-rolled (the container has no AWS SDK / composer) but kept small and pure:
 * every method is deterministic given its inputs, so the core algorithm is
 * proven against AWS's published SigV4 test-suite vector (see SigV4SignerTest).
 * Works for AWS S3, Cloudflare R2, Backblaze B2 and MinIO — the caller supplies
 * the endpoint/region and chooses header signing (server-to-server requests) or
 * query-string presigning (browser-facing temporary URLs).
 *
 * SECURITY: the secret access key never leaves the process; only derived HMAC
 * signatures are emitted. Presigned URLs carry an expiry and a signature, never
 * the secret.
 */
final class SigV4Signer
{
    private const ALGORITHM = 'AWS4-HMAC-SHA256';
    private const REQUEST_TYPE = 'aws4_request';

    public function __construct(
        private readonly string $accessKey,
        private readonly string $secretKey,
        private readonly string $region,
        private readonly string $service = 's3',
    ) {
    }

    /**
     * Compute the `Authorization` header value for a signed request.
     *
     * @param string                $method      HTTP verb (GET/PUT/DELETE/HEAD…).
     * @param string                $path        The request path (already the
     *                                            object address, e.g. /bucket/key);
     *                                            each segment is URI-encoded here.
     * @param array<string, string> $query       Query params (unencoded); may be empty.
     * @param array<string, string> $headers     Headers to sign; MUST include `Host`
     *                                            and `x-amz-date`.
     * @param string                $payloadHash Hex SHA-256 of the body, or the
     *                                            literal `UNSIGNED-PAYLOAD`.
     * @param string                $amzDate     `YYYYMMDDTHHMMSSZ` (must equal the
     *                                            request's x-amz-date header).
     */
    public function authorizationHeader(
        string $method,
        string $path,
        array $query,
        array $headers,
        string $payloadHash,
        string $amzDate,
    ): string {
        $date  = substr($amzDate, 0, 8);
        $scope = $this->credentialScope($date);

        [$canonicalHeaders, $signedHeaders] = $this->canonicalHeaders($headers);
        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalUri($path),
            $this->canonicalQuery($query),
            $canonicalHeaders,
            $signedHeaders,
            $payloadHash,
        ]);

        $signature = $this->sign($date, $this->stringToSign($amzDate, $scope, $canonicalRequest));

        return sprintf(
            '%s Credential=%s/%s, SignedHeaders=%s, Signature=%s',
            self::ALGORITHM,
            $this->accessKey,
            $scope,
            $signedHeaders,
            $signature,
        );
    }

    /**
     * Build the presigned query-string params for a temporary URL (browser GET).
     * The returned map includes `X-Amz-Signature`; the caller appends them to the
     * object URL. Only the `host` header is signed (standard for presigned GETs),
     * and the payload is `UNSIGNED-PAYLOAD`.
     *
     * @return array<string, string>
     */
    public function presignQuery(
        string $method,
        string $path,
        string $host,
        int $expiresInSeconds,
        string $amzDate,
    ): array {
        $date  = substr($amzDate, 0, 8);
        $scope = $this->credentialScope($date);

        $query = [
            'X-Amz-Algorithm'     => self::ALGORITHM,
            'X-Amz-Credential'    => $this->accessKey . '/' . $scope,
            'X-Amz-Date'          => $amzDate,
            'X-Amz-Expires'       => (string) $expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];

        $canonicalRequest = implode("\n", [
            strtoupper($method),
            $this->canonicalUri($path),
            $this->canonicalQuery($query),
            'host:' . strtolower($host) . "\n",
            'host',
            'UNSIGNED-PAYLOAD',
        ]);

        $query['X-Amz-Signature'] = $this->sign($date, $this->stringToSign($amzDate, $scope, $canonicalRequest));

        return $query;
    }

    private function credentialScope(string $date): string
    {
        return implode('/', [$date, $this->region, $this->service, self::REQUEST_TYPE]);
    }

    private function stringToSign(string $amzDate, string $scope, string $canonicalRequest): string
    {
        return implode("\n", [
            self::ALGORITHM,
            $amzDate,
            $scope,
            hash('sha256', $canonicalRequest),
        ]);
    }

    private function sign(string $date, string $stringToSign): string
    {
        $kDate    = hash_hmac('sha256', $date, 'AWS4' . $this->secretKey, true);
        $kRegion  = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', $this->service, $kRegion, true);
        $kSigning = hash_hmac('sha256', self::REQUEST_TYPE, $kService, true);

        return hash_hmac('sha256', $stringToSign, $kSigning);
    }

    /**
     * Percent-encode each path segment per RFC 3986 while preserving the slashes
     * (S3 canonical URIs encode once, keeping the '/' separators).
     */
    private function canonicalUri(string $path): string
    {
        if ($path === '' || $path === '/') {
            return '/';
        }
        $segments = explode('/', $path);
        $encoded  = array_map(static fn(string $s): string => rawurlencode($s), $segments);
        return implode('/', $encoded);
    }

    /**
     * @param array<string, string> $query
     */
    private function canonicalQuery(array $query): string
    {
        if ($query === []) {
            return '';
        }
        $pairs = [];
        $keys  = array_keys($query);
        sort($keys, SORT_STRING);
        foreach ($keys as $key) {
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($query[$key]);
        }
        return implode('&', $pairs);
    }

    /**
     * @param array<string, string> $headers
     * @return array{0: string, 1: string} [canonicalHeaders (trailing \n), signedHeaders]
     */
    private function canonicalHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $normalized[strtolower(trim((string) $name))] = trim((string) $value);
        }
        ksort($normalized, SORT_STRING);

        $canonical = '';
        foreach ($normalized as $name => $value) {
            $canonical .= $name . ':' . $value . "\n";
        }
        $signed = implode(';', array_keys($normalized));

        return [$canonical, $signed];
    }
}
