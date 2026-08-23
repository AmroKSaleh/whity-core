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
    /**
     * @param bool $listChanged Whether this host emits `notifications/*\/list_changed`
     *                          (#952). Declared per the spec's rule that a server must
     *                          not send a notification it did not advertise — and, just
     *                          as importantly, a client that is never told the server
     *                          can announce changes has no reason to listen for one.
     *                          Defaults to false so a host that has not wired the
     *                          notifier keeps advertising what it can actually do.
     */
    public function __construct(
        private readonly bool $listChanged = false,
    ) {}

    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        return [
            'protocolVersion' => '2025-03-26',
            'capabilities'    => [
                'tools'     => ['listChanged' => $this->listChanged],
                // subscribe stays false: per-resource subscriptions are a
                // different feature from list-level change announcements, and
                // nothing here implements them.
                'resources' => ['subscribe' => false, 'listChanged' => $this->listChanged],
                'prompts'   => ['listChanged' => $this->listChanged],
            ],
            'serverInfo' => [
                'name'    => 'whity-core',
                'version' => '1.0',
            ],
        ];
    }
}
