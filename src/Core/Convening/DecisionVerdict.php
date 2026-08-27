<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use Whity\Core\Document\Routing\RouteVerdict;

/**
 * What a body CONCLUDED about an agenda item.
 *
 * THREE VALUES, AND WHY THIS IS NOT `RouteVerdict`
 * ------------------------------------------------
 * {@see RouteVerdict} has exactly two members — `approved` and `rejected` — and
 * migration 119 argues at length for keeping it that way. This vocabulary has a
 * third, `deferred`, and it is the whole reason the two are separate types
 * rather than one shared constant list.
 *
 * A body that defers HAS decided something. The minute says so, the decision
 * number is spent, and the item is on record as having been put to the body and
 * sent back for more work. But it has decided nothing the ROUTING ENGINE can act
 * on: there is no edge for "come back to us", and forcing it onto either of the
 * engine's two values would advance a document nobody approved or reject one
 * nobody refused. Both are worse than the document simply staying where it is.
 *
 * So the mapping is deliberately PARTIAL, and {@see toRouteVerdict()} returns
 * null for the third value rather than picking a default. A total mapping is
 * what a `match` with a fallback arm would have produced, and the fallback arm
 * is where the wrong answer would have lived.
 *
 * WHY `deferred` IS NOT SIMPLY "RECORD NOTHING"
 * ---------------------------------------------
 * Because a body that has looked at something twice and deferred it twice is
 * telling its own organisation something, and a subsystem that stored a deferral
 * as an absence would make that history unreadable. The decision row is the
 * record; whether it reached the routing engine is a separate fact, and the
 * decision row records that separately too (`route_id` / `route_event_id`, null
 * here always).
 */
final class DecisionVerdict
{
    /** The body approved the item. Drives {@see RouteVerdict::APPROVED}. */
    public const APPROVED = 'approved';

    /** The body refused the item. Drives {@see RouteVerdict::REJECTED}. */
    public const REJECTED = 'rejected';

    /**
     * The body put the item off. Recorded, numbered, and deliberately NOT
     * mapped onto any routing verdict — see the class docblock.
     */
    public const DEFERRED = 'deferred';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::APPROVED, self::REJECTED, self::DEFERRED];
    }

    public static function isValid(string $verdict): bool
    {
        return in_array($verdict, self::all(), true);
    }

    /**
     * The routing verdict this conclusion drives, or NULL when it drives none.
     *
     * Null is a real answer and callers must treat it as one: it means "the body
     * decided, and the document does not move". A caller that read null as a
     * failure would report an error on the one outcome that is working exactly
     * as designed.
     */
    public static function toRouteVerdict(string $verdict): ?string
    {
        return match ($verdict) {
            self::APPROVED => RouteVerdict::APPROVED,
            self::REJECTED => RouteVerdict::REJECTED,
            default => null,
        };
    }
}
