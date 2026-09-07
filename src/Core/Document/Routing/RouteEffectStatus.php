<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * How one attempt at a stage's effect turned out (#1032).
 *
 * THREE VALUES, AND THE THIRD IS THE POINT
 * ----------------------------------------
 * An effect whose audience resolved to nobody, or whose tenant has switched
 * every notification channel off, has not SUCCEEDED and has not FAILED.
 * Recording it as either is a lie in one direction or the other: "succeeded"
 * tells a reader mail went out when none did, and "failed" sends somebody
 * hunting a broken mail server that is working perfectly.
 *
 * Recording nothing at all is worse than both, and is the failure this whole
 * feature is written against — migration 112 refused to ship an effect
 * declaration precisely because "a stored intention that silently does nothing"
 * still renders and still reports success while doing less than it claims. A
 * skipped attempt is that silence made legible: a row, with a reason.
 *
 * PINNED TO THE DATABASE. These three strings are the CHECK constraint on
 * `document_route_effect_attempts.status` (migration 139), and
 * {@see \Tests\Core\Document\Routing\RouteEffectStatusVocabularyTest} asserts
 * the two agree. A fourth value added here and not there is a write that fails
 * at runtime on the one engine that enforces it; added there and not here is a
 * value nothing can produce. The same pairing {@see RouteAction} and
 * {@see RouteSatisfaction} already have.
 */
final class RouteEffectStatus
{
    /**
     * The effect did what it was declared to do.
     *
     * For a queued delivery this means HANDED OVER, not delivered — the
     * notification subsystem enqueues a durable job and owns the retry from
     * there. Claiming more than that would be asserting an outcome this process
     * never observes.
     */
    public const SUCCEEDED = 'succeeded';

    /** The effect was attempted and did not complete. `detail` says what broke. */
    public const FAILED = 'failed';

    /**
     * Nothing to do, and that was determined rather than assumed.
     *
     * An empty audience, a tenant with no channels, a kind whose resolver is not
     * registered on this instance. `detail` names which.
     */
    public const SKIPPED = 'skipped';

    private function __construct()
    {
    }

    /**
     * Every value, in the order the CHECK constraint lists them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::SUCCEEDED, self::FAILED, self::SKIPPED];
    }

    public static function isValid(string $status): bool
    {
        return in_array($status, self::all(), true);
    }
}
