<?php

declare(strict_types=1);

namespace Whity\Sdk\Render;

/**
 * The render request will not be attempted (SDK 1.41).
 *
 * The caller asked for something unacceptable: a heading level outside 1-6, a
 * figure that is not embedded, a tenant ceiling exceeded, or a content tree the
 * render service refused. Retrying it unchanged cannot succeed.
 *
 * Distinct from {@see RenderUnavailableException}, which says the render tier
 * could not do the work right now. A plugin that catches only one of these
 * should catch that one — an outage is worth retrying and this is not — and a
 * plugin that treats them alike will either retry forever on a malformed
 * document or give up on a container that was merely restarting.
 *
 * WHY THE TEXT IS A NAMED PROPERTY
 * --------------------------------
 * A `getMessage()` is whatever the nearest throw site happened to put there,
 * and on any class that later wraps a cause — a driver error, a downstream
 * trace — it becomes exactly the internal text that must not reach a client.
 * So the showable sentence is its own property, populated only by
 * {@see because()}, and a host mapping this to an HTTP response reads THAT.
 * The host's own rejection type is built the same way for the same reason.
 */
final class RenderRejectedException extends \RuntimeException
{
    /** Text written to be shown to the caller, never a wrapped internal error. */
    public readonly string $clientMessage;

    private function __construct(string $clientMessage)
    {
        parent::__construct($clientMessage);
        $this->clientMessage = $clientMessage;
    }

    /**
     * Reject a render, naming the reason in words the caller can act on.
     *
     * The named constructor is the only way in, so there is no path that fills
     * {@see $clientMessage} with text nobody wrote for a reader.
     */
    public static function because(string $clientMessage): self
    {
        return new self($clientMessage);
    }
}
