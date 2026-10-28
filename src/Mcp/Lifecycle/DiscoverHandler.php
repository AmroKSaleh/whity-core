<?php

declare(strict_types=1);

namespace Whity\Mcp\Lifecycle;

use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Protocol\McpProtocol;

/**
 * `server/discover` (MCP 2026-07-28, WHIT-708): the versions, capabilities and
 * identity of this server, in one answer a client may ask for before anything
 * else. Required of every 2026 server; harmless to answer for earlier ones.
 *
 * No `listChanged` is advertised: a 2026 client receives change notifications
 * only through `subscriptions/listen`, which this server does not offer (see
 * {@see McpProtocol}). The list results carry a freshness hint instead.
 */
final class DiscoverHandler implements MethodHandler
{
    public function __construct(
        private readonly ?string $instructions = null,
    ) {}

    /** @param array<string, mixed>|null $params */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        $result = [
            'supportedVersions' => McpProtocol::supported(),
            'capabilities'      => [
                'tools'     => new \stdClass(),
                'resources' => new \stdClass(),
                'prompts'   => new \stdClass(),
            ],
            'ttlMs'             => McpProtocol::DISCOVER_TTL_MS,
            // The same for every caller: what this server speaks, not what
            // any one caller may do with it.
            'cacheScope'        => 'public',
        ];
        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }
        return $result;
    }
}
