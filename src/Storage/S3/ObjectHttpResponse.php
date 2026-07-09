<?php

declare(strict_types=1);

namespace Whity\Storage\S3;

/**
 * A minimal HTTP response for the object-store transport (WC-b8c5a271).
 * Header keys are normalised to lowercase.
 */
final class ObjectHttpResponse
{
    /**
     * @param array<string, string> $headers Lowercased header name => value.
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
