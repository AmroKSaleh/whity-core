<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Routing\RouteQuorum;

/**
 * The arithmetic behind "the instructors node approved" (#1014).
 *
 * WHY THESE ASSERTIONS ARE NOT A COPY OF THE IMPLEMENTATION
 * --------------------------------------------------------
 * The tempting test here re-computes `intdiv($n, 2) + 1` and compares it with
 * {@see RouteQuorum::required()}, which proves only that the expression appears
 * twice. Three failures in this codebase this week came from exactly that shape.
 *
 * So the expectations are derived independently:
 *
 *  - {@see testRequiredIsTheSmallestCountThatIsEnough()} builds each quorum's
 *    threshold from its ENGLISH definition ("more than half" = the smallest k
 *    with 2k > n), not from the formula under test;
 *  - the two firing predicates are checked against ENUMERATED tallies rather
 *    than against re-derived conditions;
 *  - {@see testEveryExhaustedCohortReachesExactlyOneOutcome()} asserts a
 *    PROPERTY over the whole space — for every quorum and every reachable tally,
 *    a cohort with nobody left holding it lands on exactly one of the two edges.
 *    That is a statement about the design, and it is the one that would catch a
 *    step silently ending undecided.
 */
final class RouteQuorumTest extends TestCase
{
    public function testRequiredIsTheSmallestCountThatIsEnough(): void
    {
        for ($n = 1; $n <= 12; $n++) {
            self::assertSame($n, RouteQuorum::required(RouteQuorum::ALL, $n), "all of {$n} is {$n}");
            self::assertSame(1, RouteQuorum::required(RouteQuorum::ANY, $n), "any of {$n} is 1");

            // "More than half", spelled out rather than as intdiv(n,2)+1: the
            // smallest k for which k is strictly more than half of n.
            $expected = null;
            for ($k = 1; $k <= $n; $k++) {
                if ($k * 2 > $n) {
                    $expected = $k;
                    break;
                }
            }
            self::assertSame(
                $expected,
                RouteQuorum::required(RouteQuorum::MAJORITY, $n),
                "a majority of {$n} is the smallest count that is strictly more than half"
            );
        }
    }

    public function testAnEmptyCohortDecidesNothingEitherWay(): void
    {
        // Everyone returned the item, or the rule resolved to nobody. Firing an
        // edge here would open a destination for a step nobody ever answered —
        // and under `all` the naive reading ("zero approvals out of zero
        // required") would fire the APPROVE edge, approving a document that no
        // human ever looked at. That is the single worst reachable outcome in
        // this feature, so it gets its own test.
        foreach (RouteQuorum::all() as $quorum) {
            self::assertFalse(RouteQuorum::approvalCarried($quorum, 0, 0, 0), "{$quorum}: must not approve");
            self::assertFalse(RouteQuorum::approvalImpossible($quorum, 0, 0, 0), "{$quorum}: must not reject");
        }
    }

    public function testUnderAllOneRejectionIsImmediatelyDecisiveAndOneApprovalIsNot(): void
    {
        // The thousand-instructor case, at n = 3. One person answers.
        // Approving: two people are still able to, so nothing is settled.
        self::assertFalse(RouteQuorum::approvalCarried(RouteQuorum::ALL, 1, 2, 3));
        self::assertFalse(RouteQuorum::approvalImpossible(RouteQuorum::ALL, 1, 2, 3));

        // Rejecting: unanimity is already unreachable, so the reject edge fires
        // at once rather than waiting for two people whose answer cannot matter.
        self::assertTrue(RouteQuorum::approvalImpossible(RouteQuorum::ALL, 0, 2, 3));
        self::assertFalse(RouteQuorum::approvalCarried(RouteQuorum::ALL, 0, 2, 3));
    }

    public function testUnderAnyOneApprovalCarriesAndOneRejectionDoesNot(): void
    {
        self::assertTrue(RouteQuorum::approvalCarried(RouteQuorum::ANY, 1, 2, 3));

        // The mirror of the case above, and the reason rejection is DERIVED
        // rather than configured separately: with "any one may approve", a single
        // refusal cannot settle anything while two people can still say yes.
        self::assertFalse(RouteQuorum::approvalImpossible(RouteQuorum::ANY, 0, 2, 3));
        // Only when nobody is left able to approve.
        self::assertTrue(RouteQuorum::approvalImpossible(RouteQuorum::ANY, 0, 0, 3));
    }

    public function testUnderMajorityTheEdgeFiresWhenTheOtherSideBecomesArithmeticallyImpossible(): void
    {
        // 5 people, 3 needed. Two approvals and two refusals leaves one person
        // holding it and nothing settled.
        self::assertFalse(RouteQuorum::approvalCarried(RouteQuorum::MAJORITY, 2, 1, 5));
        self::assertFalse(RouteQuorum::approvalImpossible(RouteQuorum::MAJORITY, 2, 1, 5));

        // Three refusals: 2 + 0 remaining approvals cannot reach 3.
        self::assertTrue(RouteQuorum::approvalImpossible(RouteQuorum::MAJORITY, 2, 0, 5));
        // Three approvals: settled without the last two answering at all.
        self::assertTrue(RouteQuorum::approvalCarried(RouteQuorum::MAJORITY, 3, 2, 5));
    }

    public function testEveryExhaustedCohortReachesExactlyOneOutcome(): void
    {
        // THE PROPERTY, over the whole reachable space. A decision step that can
        // end with nobody holding it and neither edge fired is a document stuck
        // where no screen reports a problem, and no hand-written counter would
        // make that impossible by construction.
        foreach (RouteQuorum::all() as $quorum) {
            for ($n = 1; $n <= 9; $n++) {
                for ($approvals = 0; $approvals <= $n; $approvals++) {
                    $carried = RouteQuorum::approvalCarried($quorum, $approvals, 0, $n);
                    $impossible = RouteQuorum::approvalImpossible($quorum, $approvals, 0, $n);

                    self::assertNotSame(
                        $carried,
                        $impossible,
                        "{$quorum}: a cohort of {$n} with {$approvals} approvals and nobody outstanding must "
                        . 'land on exactly one edge — both or neither is a step that never resolves'
                    );
                }
            }
        }
    }

    public function testTheTwoOutcomesAreNeverBothTrueEvenPartWayThrough(): void
    {
        // The weaker half of the property, over the states a cohort passes
        // THROUGH rather than ends in. Both firing at once would open two
        // destinations for one act.
        foreach (RouteQuorum::all() as $quorum) {
            for ($n = 1; $n <= 9; $n++) {
                for ($approvals = 0; $approvals <= $n; $approvals++) {
                    for ($outstanding = 0; $outstanding <= $n - $approvals; $outstanding++) {
                        self::assertFalse(
                            RouteQuorum::approvalCarried($quorum, $approvals, $outstanding, $n)
                            && RouteQuorum::approvalImpossible($quorum, $approvals, $outstanding, $n),
                            "{$quorum}: n={$n} approvals={$approvals} outstanding={$outstanding} fired both edges"
                        );
                    }
                }
            }
        }
    }

    public function testAnUnknownQuorumFallsBackToTheStrictestRuleRatherThanTheLoosest(): void
    {
        // The value has already passed a CHECK constraint and a settings
        // validator to get here, so a foreign string means something upstream is
        // broken. The safe reading of a broken approval rule is never the most
        // permissive one — falling back to `any` would turn a corrupted setting
        // into a document approved by one person out of a thousand.
        self::assertSame(
            RouteQuorum::required(RouteQuorum::ALL, 7),
            RouteQuorum::required('unanimous-ish', 7)
        );
        self::assertFalse(RouteQuorum::approvalCarried('unanimous-ish', 1, 6, 7));
    }
}
