<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

use RuntimeException;

/**
 * The period request itself is not acceptable (#1070) — a date that does not
 * exist, boundaries that overlap another period of the same kind, a parent of
 * the wrong kind or that does not contain the child's dates, a close blocked by
 * periods still open inside it, a reopen with no reason, or an edit to a sealed
 * period.
 *
 * All 422s. The caller asked for something this subsystem will not attempt, and
 * the text is written to be SHOWN: it names the period it clashed with and its
 * dates, or the periods still open, because "invalid period" leaves an
 * administrator guessing at which of a tenant's own rows was in the way.
 *
 * Distinct from {@see InvalidWindowTypeException}, which is written for a PLUGIN
 * AUTHOR and raised at load time. Everything here is raised inside a request.
 *
 * WHY THE TEXT IS A PROPERTY AND NOT JUST `getMessage()`
 * ------------------------------------------------------
 * The same reason {@see \Whity\Core\Document\Routing\RoutingRejectedException}
 * records. WC-186 forbids interpolating a throwable's message into a client
 * response, and {@see \Tests\Api\ExceptionLeakageTest} enforces it statically
 * over `src/Api` — with no allowlist, which is what makes the rule readable.
 *
 * The rule is not bureaucracy here either. A `RuntimeException` reaching a
 * handler may carry text a person wrote for a reader, or whatever the nearest
 * throw site happened to leave in it — a driver message naming a table, say —
 * and nothing about the type distinguishes them. Giving the shown text its own
 * field makes the distinction structural: {@see because()} is the only way in,
 * so there is no path that fills {@see $clientMessage} with a string nobody
 * wrote for a client, and a cause attached later changes `getMessage()` while
 * leaving it alone.
 */
final class WindowRejectedException extends RuntimeException
{
    /** Text written to be shown to the caller, never a wrapped internal error. */
    public readonly string $clientMessage;

    private function __construct(string $clientMessage)
    {
        parent::__construct($clientMessage);
        $this->clientMessage = $clientMessage;
    }

    /**
     * Refuse a request, naming the reason in words the caller can act on.
     *
     * The named constructor is the only way in.
     */
    public static function because(string $clientMessage): self
    {
        return new self($clientMessage);
    }
}
