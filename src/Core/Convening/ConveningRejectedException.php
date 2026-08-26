<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use RuntimeException;

/**
 * A convening request that is not acceptable — the 422 of this subsystem.
 *
 * Carries its client-facing text in a SEPARATE PROPERTY rather than in
 * `getMessage()`, exactly as {@see \Whity\Core\TimeWindow\WindowRejectedException}
 * and {@see \Whity\Core\Document\Routing\RoutingRejectedException} do, and for
 * the reason WC-186 records: a handler that returns `$e->getMessage()` to a
 * caller will one day return the message of an exception nobody wrote for a
 * caller. Giving the safe text its own field makes "only text written FOR a
 * caller reaches a caller" structural instead of a habit every catch site has to
 * remember.
 *
 * Every message here names WHAT WAS REFUSED AND WHY, and where there is an
 * obvious next move it says that too. A refusal a person cannot act on is a dead
 * end wearing a status code.
 */
final class ConveningRejectedException extends RuntimeException
{
    private function __construct(public readonly string $clientMessage)
    {
        parent::__construct($clientMessage);
    }

    public static function because(string $clientMessage): self
    {
        return new self($clientMessage);
    }
}
