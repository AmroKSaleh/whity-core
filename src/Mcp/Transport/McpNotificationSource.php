<?php

declare(strict_types=1);

namespace Whity\Mcp\Transport;

/**
 * A dispatcher that may have server-initiated notifications to deliver (#952).
 *
 * Kept separate from {@see McpRequestHandlerInterface} rather than folded into
 * `handle()`: a dispatcher with nothing to push is a complete implementation of
 * the transport contract, and bootstrap stubs such as {@see NullMcpDispatcher}
 * should not have to grow a method to say so.
 *
 * The transport only calls this when it can actually put the frames on the wire.
 * Draining CLAIMS the notifications — a client is told about a catalogue change
 * once — so asking for them and then dropping them would lose the change for
 * good.
 */
interface McpNotificationSource
{
    /**
     * Take the notifications owed to the client of the request just handled.
     *
     * Each entry is a complete, encoded JSON-RPC notification object ready to be
     * framed. Returns an empty list when nothing is owed, when the request was
     * unauthenticated, or when the bookkeeping failed — a caller cannot and need
     * not distinguish those.
     *
     * @return list<string>
     */
    public function drainNotifications(): array;
}
