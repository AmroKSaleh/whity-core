<?php

declare(strict_types=1);

namespace Whity\Mcp\RateLimit;

/**
 * Thrown by McpRateLimiter when a fixed-window budget is exhausted.
 *
 * Caught by McpTransportHandler to produce an HTTP 429 with a Retry-After
 * header, giving compliant AI clients enough information to back off correctly.
 */
final class McpRateLimitException extends \RuntimeException
{
    public function __construct(
        private readonly int $retryAfterSeconds,
        string $message = 'MCP rate limit exceeded',
    ) {
        parent::__construct($message);
    }

    /**
     * Seconds until the current fixed window resets (≥ 1).
     */
    public function getRetryAfterSeconds(): int
    {
        return $this->retryAfterSeconds;
    }
}
