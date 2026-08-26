<?php

declare(strict_types=1);

namespace Whity\Core\TimeWindow;

use RuntimeException;

/**
 * A refusal whose message is written for the PERSON who asked (#1070).
 *
 * Distinct from {@see InvalidWindowTypeException}, which is written for a PLUGIN
 * AUTHOR and is raised at load time. Everything here is raised inside a request,
 * carries a message an API handler passes through verbatim as a 422, and names
 * the thing that stopped the request rather than the rule that was broken —
 * "3 periods inside this one are still open" tells an operator what to do next;
 * "cascade constraint violated" does not.
 */
final class WindowRejectedException extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
