<?php

declare(strict_types=1);

namespace Whity\Storage\S3;

use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageException;

/**
 * S3-compatible object-storage driver (WC-b8c5a271): AWS S3 / Cloudflare R2 /
 * Backblaze B2 / MinIO. Implements the tenant-agnostic {@see StorageDriverInterface}
 * over an injectable {@see ObjectHttpTransport}, signing every request with
 * {@see SigV4Signer}. The opaque tenant-prefixed key (e.g.
 * `tenants/42/docs/exam.pdf`) becomes the object key under the configured bucket.
 *
 * This is the operator's shared storage backend, selected + configured via global
 * admin settings; it is the "local ↔ cloud" swap seam behind the interface.
 */
final class S3StorageDriver implements StorageDriverInterface
{
    private SigV4Signer $signer;
    private string $scheme;
    private string $endpointHost;

    public function __construct(
        private readonly S3Config $config,
        private readonly ObjectHttpTransport $transport,
    ) {
        $this->signer = new SigV4Signer($config->accessKey, $config->secretKey, $config->region, 's3');

        $parts = parse_url($config->endpoint);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new StorageException('S3StorageDriver: invalid endpoint');
        }
        $this->scheme = (string) $parts['scheme'];
        $this->endpointHost = (string) $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    public function put(string $key, string $contents, array $metadata = []): void
    {
        $contentType = isset($metadata['ContentType']) && is_string($metadata['ContentType'])
            ? $metadata['ContentType']
            : 'application/octet-stream';

        $res = $this->request('PUT', $key, ['Content-Type' => $contentType], $contents);
        $this->assertOk($res, $key, 'write');
    }

    public function get(string $key): string
    {
        $res = $this->request('GET', $key);
        $this->assertOk($res, $key, 'read');
        $this->assertComplete($res, $key);
        return $res->body;
    }

    public function getStream(string $key): mixed
    {
        // The interface returns a stream the caller closes; we buffer through a
        // temp stream (the transport already read the object into memory).
        $stream = fopen('php://temp', 'r+b');
        if ($stream === false) {
            throw new StorageException('S3StorageDriver: could not open temp stream');
        }
        fwrite($stream, $this->get($key));
        rewind($stream);
        return $stream;
    }

    public function delete(string $key): void
    {
        $res = $this->request('DELETE', $key);
        // S3 DELETE is idempotent: 204 on success, 404 if already gone — both fine.
        if ($res->status !== 204 && $res->status !== 200 && $res->status !== 404) {
            throw new StorageException(sprintf('S3StorageDriver: delete failed (%d) for %s', $res->status, $key));
        }
    }

    public function exists(string $key): bool
    {
        $res = $this->request('HEAD', $key);
        if ($res->status === 200) {
            return true;
        }
        if ($res->status === 404) {
            return false;
        }
        throw new StorageException(sprintf('S3StorageDriver: exists check failed (%d) for %s', $res->status, $key));
    }

    public function copy(string $source, string $destination): void
    {
        // Server-side copy: PUT destination with the x-amz-copy-source header.
        $copySource = '/' . $this->config->bucket . '/' . self::encodePath($source);
        $res = $this->request('PUT', $destination, ['x-amz-copy-source' => $copySource], '');
        $this->assertOk($res, $source, 'copy');
    }

    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);
        $this->delete($source);
    }

    public function size(string $key): int
    {
        $len = $this->head($key)->header('content-length');
        if ($len === null) {
            throw new StorageException('S3StorageDriver: missing Content-Length for ' . $key);
        }
        return (int) $len;
    }

    public function mimeType(string $key): string
    {
        $type = $this->head($key)->header('content-type');
        return $type ?? 'application/octet-stream';
    }

    public function lastModified(string $key): int
    {
        $lm = $this->head($key)->header('last-modified');
        $ts = $lm !== null ? strtotime($lm) : false;
        if ($ts === false) {
            throw new StorageException('S3StorageDriver: missing/invalid Last-Modified for ' . $key);
        }
        return $ts;
    }

    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): string
    {
        $host = $this->hostFor();
        $path = $this->objectPath($key);
        $query = $this->signer->presignQuery('GET', $path, $host, $expiresInSeconds, $this->amzDate());

        $pairs = [];
        foreach ($query as $k => $v) {
            $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
        }
        return $this->scheme . '://' . $host . self::encodePath($path) . '?' . implode('&', $pairs);
    }

    public function publicUrl(string $key): string
    {
        if ($this->config->publicBaseUrl === null) {
            throw new \RuntimeException('S3StorageDriver: public URLs are not enabled for this bucket');
        }
        return rtrim($this->config->publicBaseUrl, '/') . '/' . self::encodePath($key);
    }

    // ── internals ───────────────────────────────────────────────────────────────

    private function head(string $key): ObjectHttpResponse
    {
        $res = $this->request('HEAD', $key);
        if ($res->status === 404) {
            throw new StorageException('S3StorageDriver: not found: ' . $key);
        }
        $this->assertOk($res, $key, 'stat');
        return $res;
    }

    /**
     * Build, sign and send a request for an object key.
     *
     * @param array<string, string> $extraHeaders
     */
    private function request(string $method, string $key, array $extraHeaders = [], ?string $body = null): ObjectHttpResponse
    {
        $host = $this->hostFor();
        $path = $this->objectPath($key);
        $amzDate = $this->amzDate();
        $payloadHash = hash('sha256', $body ?? '');

        $headers = array_merge($extraHeaders, [
            'Host'                 => $host,
            'x-amz-date'           => $amzDate,
            'x-amz-content-sha256' => $payloadHash,
        ]);

        $headers['Authorization'] = $this->signer->authorizationHeader(
            $method,
            $path,
            [],
            $headers,
            $payloadHash,
            $amzDate,
        );

        $url = $this->scheme . '://' . $host . self::encodePath($this->objectPath($key));
        return $this->transport->send($method, $url, $headers, $body);
    }

    /**
     * The object path used for BOTH signing and the request URL: path-style keeps
     * the bucket in the path, virtual-hosted keeps only the key (bucket is in the
     * host). The two must be identical or the SigV4 signature won't match the URL.
     */
    private function objectPath(string $key): string
    {
        return $this->config->pathStyle
            ? '/' . $this->config->bucket . '/' . $key
            : '/' . $key;
    }

    private function hostFor(): string
    {
        return $this->config->pathStyle
            ? $this->endpointHost
            : $this->config->bucket . '.' . $this->endpointHost;
    }

    private function amzDate(): string
    {
        return gmdate('Ymd\THis\Z');
    }

    /**
     * Data-integrity guard for reads: the bytes returned MUST match the object's
     * declared Content-Length. Catches a body truncated at the transport's read
     * cap (a >cap object) or a connection dropped mid-transfer — either would
     * otherwise return a corrupt/partial object as a successful 200, silently
     * corrupting a stored document. (Adversarial review WC-b8c5a271, finding #1/#2.)
     */
    private function assertComplete(ObjectHttpResponse $res, string $key): void
    {
        $declared = $res->header('content-length');
        if ($declared !== null && ctype_digit($declared) && strlen($res->body) !== (int) $declared) {
            throw new StorageException(sprintf(
                'S3StorageDriver: incomplete read for %s (got %d of %s bytes)',
                $key,
                strlen($res->body),
                $declared,
            ));
        }
    }

    private function assertOk(ObjectHttpResponse $res, string $key, string $op): void
    {
        if ($res->status === 404) {
            throw new StorageException(sprintf('S3StorageDriver: not found for %s (%s)', $key, $op));
        }
        if ($res->status < 200 || $res->status >= 300) {
            throw new StorageException(sprintf('S3StorageDriver: %s failed (%d) for %s', $op, $res->status, $key));
        }
    }

    /**
     * Percent-encode each segment (matching SigV4 canonical-URI encoding) so the
     * request URL and the signature agree. A leading '/' explodes to an empty
     * first segment, which encodes to '' and preserves the leading slash.
     */
    private static function encodePath(string $path): string
    {
        // A path built from '/bucket/key' explodes with a leading empty segment,
        // which rawurlencode leaves empty, preserving the leading slash.
        $segments = explode('/', $path);
        $encoded = array_map(static fn(string $s): string => rawurlencode($s), $segments);
        return implode('/', $encoded);
    }
}
