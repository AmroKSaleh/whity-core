<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * The render request itself is not acceptable — a malformed `dataRows`, or a
 * batch/size ceiling exceeded.
 *
 * Distinct from {@see RenderServiceUnavailableException}, which says the
 * SERVICE could not do the work (503). This one says the CALLER asked for
 * something that will not be attempted (422), and its text is written to be
 * SHOWN: it names the limit that was hit, because a bare "too large" leaves the
 * caller guessing at a number that is tenant-configurable and therefore not
 * knowable from the outside.
 *
 * WHY THE TEXT IS A PROPERTY AND NOT JUST `getMessage()`
 * ------------------------------------------------------
 * WC-186 forbids interpolating a throwable's message into a client response,
 * and {@see \Tests\Api\ExceptionLeakageTest} enforces it statically over
 * `src/Api`. That rule is right and this class is not an exception to it: a
 * `getMessage()` is whatever the nearest throw site happened to put there, and
 * on any class that later wraps a cause — a driver error, a downstream Node
 * trace — it is exactly the internal text the rule exists to keep out.
 *
 * So the client-safe text is its own named thing. {@see $clientMessage} is
 * populated only by {@see because()}, from a string an author wrote for a
 * reader, and handlers read that; the Throwable message carries the same text
 * purely so a log line is legible. A future cause attached to this exception
 * changes what `getMessage()` returns and leaves `$clientMessage` alone, which
 * is the whole point of keeping them apart.
 */
final class DocumentRenderRejectedException extends \RuntimeException
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
     * {@see $clientMessage} with text nobody wrote for a client.
     */
    public static function because(string $clientMessage): self
    {
        return new self($clientMessage);
    }
}
