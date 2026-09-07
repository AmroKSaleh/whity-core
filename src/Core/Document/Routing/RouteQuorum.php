<?php

declare(strict_types=1);

namespace Whity\Core\Document\Routing;

/**
 * What "this node approved" MEANS when the node resolves to a thousand people
 * (#1014).
 *
 * A route step names a RULE, never a person — that is migration 112's first
 * semantic and the standing product requirement behind it: "you can put nodes
 * for all 1000 instructors but you can say instructors in one node". A verdict
 * does not change that, and it raises the question the whole feature turns on:
 *
 *     the instructors node approved. All of them? Any of them? A quorum?
 *
 * This class is that rule, made EXPLICIT. It is not an implicit "any", which is
 * what a first implementation lands on without noticing, and which is invisible
 * until somebody's document turns out to have been authorised by one instructor
 * out of a thousand.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * THE DEFAULT IS `all`, AND HERE IS WHY
 * ─────────────────────────────────────────────────────────────────────────
 * The two ways to get this wrong are not symmetric.
 *
 * TOO FEW approvals is a SILENT authority failure. The document proceeds
 * carrying an authorisation nobody gave, every screen shows a route running
 * normally, the trail is internally consistent, and nothing anywhere looks
 * wrong. It surfaces — if it ever surfaces — in an audit, years later, about a
 * decision that has already had its effect.
 *
 * TOO MANY approvals required is a LOUD failure. The document visibly stops, the
 * person waiting on it complains the same afternoon, and an operator changes one
 * setting. Nothing has been authorised that should not have been.
 *
 * A default is what protects the deployment where NOBODY thought about this. The
 * one that fails loudly is the one to pick.
 *
 * The second half of the argument is that `all` costs almost nothing in the
 * ordinary case. The overwhelmingly common approval step — "the dean approves",
 * "the head of department signs off" — resolves to ONE person, and for a cohort
 * of one, `all`, `any` and `majority` are the same rule. The default therefore
 * differs from `any` in exactly the situation where `any` is dangerous: a node
 * that fans out to hundreds. Identical in the safe case, conservative in the
 * risky one.
 *
 * Anyone who genuinely means "any one instructor may sign this" says so, once,
 * in a setting or on the step — and is then saying it deliberately, which is the
 * whole point of the value being explicit.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * REJECTION IS DERIVED, NOT A SECOND SETTING
 * ─────────────────────────────────────────────────────────────────────────
 * There is deliberately no `rejection_quorum`. The reject edge fires when the
 * APPROVAL QUORUM HAS BECOME UNREACHABLE — that is, when even if every person
 * still holding the item approved, the bar could not be met:
 *
 *     approvals + still-outstanding < required
 *
 * One rule, and it produces the behaviour each quorum obviously ought to have
 * without anyone configuring it twice:
 *
 *   - under `all`, the FIRST rejection is decisive, because unanimity is already
 *     impossible;
 *   - under `any`, a rejection decides nothing while anybody is still able to
 *     approve, and the step is refused only when everyone has refused;
 *   - under `majority`, the reject edge fires the moment a majority in favour
 *     has become arithmetically impossible.
 *
 * A second setting could express all three, and could also express combinations
 * that contradict each other — "all must approve, and three must reject" — which
 * is a state the engine would then have to have an opinion about. Deriving it
 * makes those states unrepresentable.
 *
 * COMPLETENESS, WHICH IS WHAT MAKES THIS SAFE
 * -------------------------------------------
 * For any non-empty cohort, once nobody is left holding the item, EXACTLY ONE of
 * the two edges has fired: either `approvals >= required` (approve) or
 * `approvals + 0 < required` (reject). There is no third outcome and therefore
 * no way for a decision step to end silently undecided, which is the failure
 * mode a hand-written counter would eventually produce.
 * {@see \Tests\Core\Document\Routing\RouteQuorumTest} asserts it exhaustively
 * over every quorum and every possible tally rather than trusting the argument.
 *
 * Stateless — worker-safe.
 */
final class RouteQuorum
{
    /**
     * Everyone in the cohort must approve.
     *
     * The default. See the class docblock: it is indistinguishable from `any`
     * for the single-approver step that is the common case, and it is the safe
     * answer for the fan-out step that is the dangerous one.
     */
    public const ALL = 'all';

    /** One approval carries the step. The first answer decides. */
    public const ANY = 'any';

    /** More than half of the cohort — `intdiv(n, 2) + 1`, so 2 of 3, 3 of 4. */
    public const MAJORITY = 'majority';

    /**
     * Every quorum, in the order a reader meets them.
     *
     * Must stay in step with the CHECK on `document_route_steps.decision_quorum`
     * (migration 119) and with the validator for the
     * `documents.routing_approval_quorum` setting.
     * {@see \Tests\Core\Document\Routing\RouteQuorumTest} pins all three.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::ALL, self::ANY, self::MAJORITY];
    }

    public static function isValid(string $quorum): bool
    {
        return in_array($quorum, self::all(), true);
    }

    /**
     * How many approvals a cohort of `$cohortSize` needs.
     *
     * An empty cohort requires 0 and is handled by the callers below, which
     * refuse to decide anything at all about one — see {@see approvalCarried()}.
     *
     * An unrecognised quorum resolves as {@see ALL}. That is not defensive
     * padding: the value reaching here has been through a CHECK constraint, a
     * settings validator and this class's own {@see isValid()}, so a foreign
     * string means something upstream has broken — and the safe reading of a
     * broken approval rule is the strictest one, never the most permissive.
     */
    public static function required(string $quorum, int $cohortSize): int
    {
        if ($cohortSize <= 0) {
            return 0;
        }

        return match ($quorum) {
            self::ANY => 1,
            self::MAJORITY => intdiv($cohortSize, 2) + 1,
            default => $cohortSize,
        };
    }

    /**
     * Has the step been APPROVED?
     *
     * @param int $approvals   Cohort members who answered `approved`.
     * @param int $outstanding Cohort members who still hold an open item AND can
     *                         still answer it. See {@see DocumentRouter} for why
     *                         somebody who has left the tenant is not counted.
     */
    public static function approvalCarried(string $quorum, int $approvals, int $outstanding, int $cohortSize): bool
    {
        if ($cohortSize <= 0) {
            // Nobody is in the cohort — every row left through a `returned`, or
            // the rule resolved to nobody. Neither edge fires: the document has
            // already gone wherever those acts sent it, and firing an edge on
            // top would open a second destination for one act.
            return false;
        }

        return $approvals >= self::required($quorum, $cohortSize);
    }

    /**
     * Has the step been REJECTED — i.e. has approval become impossible?
     *
     * Note what is NOT counted: rejections. They matter only through their
     * effect on how many people are still able to approve, which is what makes a
     * single refusal decisive under `all` and inert under `any` without either
     * behaviour being written down as a rule of its own.
     */
    public static function approvalImpossible(string $quorum, int $approvals, int $outstanding, int $cohortSize): bool
    {
        if ($cohortSize <= 0) {
            return false;
        }

        return $approvals + $outstanding < self::required($quorum, $cohortSize);
    }
}
