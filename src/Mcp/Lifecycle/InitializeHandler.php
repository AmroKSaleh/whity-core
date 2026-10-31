<?php

declare(strict_types=1);

namespace Whity\Mcp\Lifecycle;

use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Protocol\McpProtocol;

/**
 * MCP initialize method handler: the handshake of the legacy protocol versions
 * (2025-03-26 to 2025-11-25). A 2026-07-28 client never sends it; the Dispatcher
 * answers that one "method not found", as the spec asks (WHIT-708).
 *
 * Returns the negotiated protocol version, server capabilities, and server
 * identity. The version is the client's own when this server speaks it, else
 * the newest legacy version the server supports.
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
        // WHIT-794: the short "start here" for clients that read it, the same
        // for every caller. The per-caller briefing is the init_session tool.
        private readonly ?string $instructions = null,
    ) {}

    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        $result = [
            'protocolVersion' => McpProtocol::negotiate($params['protocolVersion'] ?? null),
            'capabilities'    => [
                'tools'     => ['listChanged' => $this->listChanged],
                // subscribe stays false: per-resource subscriptions are a
                // different feature from list-level change announcements, and
                // nothing here implements them.
                'resources' => ['subscribe' => false, 'listChanged' => $this->listChanged],
                'prompts'   => ['listChanged' => $this->listChanged],
            ],
            'serverInfo' => McpProtocol::serverInfo(),
        ];
        if ($this->instructions !== null) {
            $result['instructions'] = $this->instructions;
        }
        return $result;
    }
}
