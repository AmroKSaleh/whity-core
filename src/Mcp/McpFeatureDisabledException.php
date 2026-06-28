<?php

declare(strict_types=1);

namespace Whity\Mcp;

/**
 * Thrown by the Dispatcher when the requesting tenant has not opted in to MCP
 * (WC-149b2fc9). Caught by McpTransportHandler and mapped to HTTP 403.
 */
final class McpFeatureDisabledException extends \RuntimeException
{
    public function __construct(string $message = 'MCP is not enabled for this tenant')
    {
        parent::__construct($message);
    }
}
