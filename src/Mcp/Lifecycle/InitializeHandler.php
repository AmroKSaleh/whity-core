<?php

declare(strict_types=1);

namespace Whity\Mcp\Lifecycle;

use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP initialize method handler (MCP spec 2025-03-26).
 *
 * Returns the protocol version, server capabilities, and server identity.
 * Capabilities are hardcoded to the current set; dynamic capability discovery
 * (tools/resources/prompts derived at worker boot) is added in later tasks.
 */
final class InitializeHandler implements MethodHandler
{
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        return [
            'protocolVersion' => '2025-03-26',
            'capabilities'    => [
                'tools'     => ['listChanged' => false],
                'resources' => ['subscribe' => false, 'listChanged' => false],
                'prompts'   => ['listChanged' => false],
            ],
            'serverInfo' => [
                'name'    => 'whity-core',
                'version' => '1.0',
            ],
        ];
    }
}
