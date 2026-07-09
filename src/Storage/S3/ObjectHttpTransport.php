<?php

declare(strict_types=1);

namespace Whity\Storage\S3;

/**
 * Low-level HTTP transport for the S3 driver (WC-b8c5a271). Abstracted so the
 * driver's request-building + SigV4 signing is fully unit-testable with a fake
 * transport (no live bucket, no network).
 */
interface ObjectHttpTransport
{
    /**
     * Perform a single request. Implementations MUST NOT throw on a non-2xx
     * status — return it in the response so the driver maps it (e.g. 404 →
     * not-found). They MAY throw only on a genuine transport failure (DNS,
     * connect, timeout).
     *
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers, ?string $body): ObjectHttpResponse;
}
