<?php

declare(strict_types=1);

namespace Whity\Core\Convening;

use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRejectedException;

/**
 * THE SEAM: a body's decision answering a document's approval step.
 *
 * This is the class the whole subsystem exists for. Everything else is a
 * minute-book; this is what lets the minute-book move a document.
 *
 * IT CALLS THE ENGINE. IT DOES NOT REIMPLEMENT IT.
 * ------------------------------------------------
 * There is exactly one write in this file's reach and it is
 * {@see DocumentRouter::act()}. Nothing here inserts a `document_route_events`
 * row, closes a `document_route_recipients` row, walks an edge, or counts a
 * quorum — all of which would be perfectly possible with the repositories this
 * class already holds, and every one of which would be a second routing engine
 * with a second set of bugs. The engine's own docblock lists five invariants it
 * enforces (rule-not-person resolution, fan-out without barriers, an append-only
 * trail, verdict-derived destinations, quorum counted live); a bridge that wrote
 * its own rows would have to re-honour all five and would be found not to have,
 * one at a time, by people whose documents went to the wrong place.
 *
 * So the engine is asked, exactly as a person's own approval asks it, and it
 * answers with the same refusals. `approved` advances or fires the approve edge;
 * `rejected` fires the reject edge or goes nowhere; a quorum still short leaves
 * `decided` null and the step open. None of that logic is repeated here, and
 * none of it can drift from the version a human approval takes.
 *
 * THE HARD PART IS NOT THE VERDICT — IT IS WHOSE NAME IS ON IT
 * ------------------------------------------------------------
 * `DocumentRouter::act()` refuses anybody without an OPEN RECIPIENT ROW: being a
 * recipient IS the authorization (migration 113), and that rule is exactly what
 * stops this class from being a way to approve documents nobody sent you. A body
 * is not a profile and cannot hold a recipient row, so the decision has to be
 * carried by a PERSON the route actually reached.
 *
 * {@see candidates()} builds that list, in this order:
 *
 *   1. THE RECORDER — whoever is minuting the decision. If the route reached
 *      them, they are the honest actor: they are the person performing the act,
 *      and the trail should say so.
 *   2. THE CHAIR, then the SECRETARY, then ordinary MEMBERS, by seat
 *      ({@see MemberRole::precedence()}). A chair speaks for the body by
 *      construction; a secretary minutes it; a member is the last resort.
 *
 * Two things this ordering is NOT:
 *
 *   - It is not a privilege. Every candidate must ALREADY hold an open row that
 *     the route opened for them by resolving its own rule. The order decides only
 *     WHICH of several equally-entitled people is named, never whether anybody
 *     unentitled can act. Remove the ordering entirely and the security property
 *     is unchanged.
 *   - It is not a fallback chain over failures. The first candidate holding an
 *     open row is used and the engine's answer is final; if the engine refuses
 *     that person, the refusal is reported rather than retried with the next
 *     name, because "try everyone until somebody is allowed" is precisely the
 *     shape that turns a per-person gate into no gate at all.
 *
 * A DECISION CAN LEGITIMATELY MOVE NOTHING, AND SAYS SO
 * -----------------------------------------------------
 * Four ordinary outcomes reach no route: the item carries no document; the
 * document has no route; the route reached nobody on this body; the step the
 * route is at is not a GATE (a circulation step takes no verdict — migration 119
 * refuses a verdict there rather than ignoring it, and this class must not
 * launder one past that refusal by answering without one).
 *
 * Every one of them is returned as a `reason`, and {@see DecisionRecorder} stores
 * the outcome on the decision row. That is the entire reason this class returns a
 * report instead of a bool: "the body approved it and the document advanced" and
 * "the body approved it and nothing happened" are different facts that render
 * identically unless somebody writes the difference down.
 *
 * NO MODIFICATION TO `DocumentRouter` WAS NEEDED. Its public surface — `act()`,
 * plus the three repositories' public reads — is sufficient, which is worth
 * recording because it was not obvious in advance and because the alternative
 * (widening the engine to take a non-recipient actor) would have removed the one
 * rule that makes routing safe.
 */
final class DecisionRouteBridge
{
    /** The agenda item carries no document, so there is nothing to route. */
    public const NO_DOCUMENT = 'no_document';

    /** The body deferred. A deferral decides nothing the engine can act on. */
    public const NO_ROUTE_VERDICT = 'no_route_verdict';

    /** The document has never been put into circulation. */
    public const NO_ROUTE = 'no_route';

    /** No current member of the body holds an open item on any of its routes. */
    public const NO_OPEN_ITEM = 'no_open_item';

    /** The step reached is a circulation step, which takes no verdict. */
    public const NOT_A_DECISION_STEP = 'not_a_decision_step';

    /** The engine accepted the verdict. */
    public const APPLIED = 'applied';

    public function __construct(
        private readonly DocumentRouter $router,
        private readonly RouteRepository $routes,
        private readonly RouteStepRepository $steps,
        private readonly RouteRecipientRepository $recipients,
        private readonly ConveningBodyRepository $bodies,
    ) {
    }

    /**
     * Drive the document's route with this body's conclusion.
     *
     * Runs inside whatever transaction the caller has open — {@see DecisionRecorder}
     * opens one so the decision row and the trail entry cannot disagree.
     * `DocumentRouter::act()` joins an open transaction rather than starting its
     * own, which is what makes that possible without either class knowing about
     * the other's boundaries.
     *
     * @param int      $tenantId
     * @param int      $bodyId       The body that decided.
     * @param int|null $documentId   The agenda item's document, or null.
     * @param string   $verdict      A {@see DecisionVerdict} value.
     * @param int|null $recorderId   Who is minuting the decision.
     * @param string|null $note      The rationale, carried onto the trail so the
     *        document's own history says WHY, not merely that a body answered.
     *
     * @return array{reason: string, route_id: ?int, step_id: ?int, actor_profile_id: ?int,
     *               event_id: ?int, decided: ?string, delivered: int}
     *
     * @throws RoutingRejectedException When the engine refuses the act. Not
     *         swallowed: see the class docblock — a decision recorded as an
     *         approval the engine refused would claim an authorization that did
     *         not happen.
     */
    public function apply(
        int $tenantId,
        int $bodyId,
        ?int $documentId,
        string $verdict,
        ?int $recorderId,
        ?string $note
    ): array {
        $none = static fn (string $reason): array => [
            'reason' => $reason,
            'route_id' => null,
            'step_id' => null,
            'actor_profile_id' => null,
            'event_id' => null,
            'decided' => null,
            'delivered' => 0,
        ];

        if ($documentId === null) {
            return $none(self::NO_DOCUMENT);
        }

        $routeVerdict = DecisionVerdict::toRouteVerdict($verdict);
        if ($routeVerdict === null) {
            // A deferral. Null is the designed answer, not a missing case — see
            // DecisionVerdict, which refuses to invent a default precisely so
            // this branch has to be written out.
            return $none(self::NO_ROUTE_VERDICT);
        }

        $routes = $this->routes->listForDocument($documentId, $tenantId);
        if ($routes === []) {
            return $none(self::NO_ROUTE);
        }

        $candidates = $this->candidates($tenantId, $bodyId, $recorderId);

        // Newest route first, which is the order listForDocument() returns. A
        // document circulated twice is being asked about by its CURRENT
        // circulation; an older route with a stale open row would otherwise
        // capture the decision and advance a chain nobody is watching.
        $notAGate = false;
        foreach ($routes as $route) {
            $routeId = (int) $route['id'];

            foreach ($candidates as $profileId) {
                $recipient = $this->recipients->findOpenForProfile($tenantId, $routeId, $profileId);
                if ($recipient === null) {
                    continue;
                }

                $step = $this->steps->findById((int) $recipient['step_id'], $tenantId);
                if ($step === null || ($step['decision'] ?? false) !== true) {
                    // The route reached this body at an ORDINARY circulation
                    // step. Migration 119 refuses a verdict on one rather than
                    // ignoring it, and answering WITHOUT a verdict here would
                    // launder a rejection into a plain acknowledgement that
                    // advances the document. Recorded and skipped — the NEXT
                    // candidate is still tried, because two people on one body
                    // can be standing at two different steps of one route and
                    // only one of them may be the one holding the gate.
                    $notAGate = true;
                    continue;
                }

                $outcome = $this->router->act(
                    $tenantId,
                    $profileId,
                    $route,
                    // The only act that carries a verdict. RouteVerdict says so
                    // itself rather than this class asserting it, so a change to
                    // the engine's vocabulary reaches here.
                    RouteAction::ACKNOWLEDGED,
                    $note,
                    $routeVerdict,
                );

                // The engine guarantees an event row (it re-reads the append and
                // throws if it cannot), so the only uncertainty is the shape of
                // its `id` — which comes back stringified under some PDO
                // settings. Cast, do not test.
                $event = $outcome['event'];

                return [
                    'reason' => self::APPLIED,
                    'route_id' => $routeId,
                    'step_id' => (int) $step['id'],
                    'actor_profile_id' => $profileId,
                    'event_id' => isset($event['id']) ? (int) $event['id'] : null,
                    // What the STEP concluded, which is not the same fact as what
                    // this body said: under a quorum of `all` the first of three
                    // approvals decides nothing, and `decided` is null there.
                    'decided' => $outcome['decided'],
                    'delivered' => $outcome['delivered'],
                ];
            }
        }

        return $none($notAGate ? self::NOT_A_DECISION_STEP : self::NO_OPEN_ITEM);
    }

    /**
     * The profiles that may carry this body's decision to a route, best first.
     *
     * The recorder leads because the trail should name the person who actually
     * performed the act when the route reached them. Then the body's current
     * seats in precedence order, which {@see ConveningBodyRepository::currentMembers()}
     * already returns.
     *
     * PAST MEMBERS ARE EXCLUDED. Somebody who has left the body may still hold an
     * open recipient row from before they left, and letting them carry today's
     * decision would put a former member's name on it — which is both wrong and
     * the sort of wrong that only surfaces when a decision is challenged.
     *
     * @return list<int> De-duplicated, order preserved.
     */
    private function candidates(int $tenantId, int $bodyId, ?int $recorderId): array
    {
        $ordered = [];

        if ($recorderId !== null) {
            $ordered[] = $recorderId;
        }
        foreach ($this->bodies->currentMembers($tenantId, $bodyId) as $member) {
            $ordered[] = (int) $member['profile_id'];
        }

        $seen = [];
        $unique = [];
        foreach ($ordered as $profileId) {
            if (!isset($seen[$profileId])) {
                $seen[$profileId] = true;
                $unique[] = $profileId;
            }
        }

        return $unique;
    }

    /**
     * A sentence for a person, explaining an outcome that moved no document.
     *
     * Lives here rather than in the API handler because the reason codes are this
     * class's vocabulary, and an explanation that lived beside the caller would
     * go stale the first time a fifth reason is added.
     */
    public static function explain(string $reason): string
    {
        return match ($reason) {
            self::NO_DOCUMENT =>
                'This agenda item is not about a document, so the decision was recorded and nothing '
                . 'was circulated.',
            self::NO_ROUTE_VERDICT =>
                'A deferral is recorded but moves nothing: there is no approval step it could answer, '
                . 'and forcing it into an approval or a rejection would say something the body did not.',
            self::NO_ROUTE =>
                'The document on this item has never been put into circulation, so there is no '
                . 'approval step for the decision to answer.',
            self::NO_OPEN_ITEM =>
                'The document is in circulation but has not reached this body — nobody currently '
                . 'sitting on it holds an open item on the route — so the decision was recorded and '
                . 'the document was left where it is.',
            self::NOT_A_DECISION_STEP =>
                'The document reached this body as a circulation, not as an approval step, so there '
                . 'is no verdict for it to take. The decision was recorded.',
            default => 'The decision was recorded and drove the document\'s approval route.',
        };
    }
}
