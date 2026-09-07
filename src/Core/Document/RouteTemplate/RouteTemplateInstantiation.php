<?php

declare(strict_types=1);

namespace Whity\Core\Document\RouteTemplate;

use Whity\Core\Db\DbBool;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RouteVerdict;

/**
 * The converter (#1031): a stored DESIGN turned into the step list
 * {@see \Whity\Core\Document\Routing\DocumentRouter::issue()} accepts.
 *
 * This is the whole of "applying a template". Everything else #1031 adds is
 * plumbing around it — a repository read, two provenance columns and a button —
 * and the correctness of the feature is the correctness of this class.
 *
 * TWO SPELLINGS OF ONE FACT, AND WHY BOTH ARE RIGHT
 * --------------------------------------------------
 * A template stores branches as a FLAT EDGE LIST keyed by position
 * (`[{from, to, verdict}]`) because a graph save REPLACES rather than diffs, so
 * step ids churn on every save and an editor must not hold one across one. The
 * route authoring API puts the branch ON THE STEP (`on_approved` / `on_rejected`,
 * each a 1-based index into the same `steps` array) because while a route is
 * being composed its steps have no ids at all.
 *
 * The two are interconvertible without loss because `UNIQUE (from_step_id,
 * verdict)` holds on BOTH sides: at most one approve edge and at most one reject
 * edge leave any node, which is exactly what two fields express.
 *
 * `decision` IS CARRIED, NEVER INFERRED — THE ONE TRAP HERE
 * ---------------------------------------------------------
 * The obvious conversion reads `decision` off the edges: a node with an outgoing
 * edge is a gate, one without is not. It is wrong in the case that matters least
 * visibly and costs most:
 *
 *   A GATE AT THE END OF A ROUTE HAS NO OUTGOING EDGE AND STILL DEMANDS A
 *   VERDICT. "Approved by the dean" as the last stage is the ordinary shape of
 *   an approval flow. Inferred from edges it converts to an ordinary
 *   circulation step, the dean forwards or acknowledges it, no verdict is ever
 *   recorded, and the trail shows a document that travelled its whole route
 *   without anybody approving anything. Nothing anywhere reports an error.
 *
 * So `decision` comes off the step row and off nothing else. The template store
 * already refuses a `decision_quorum` on a non-decision step and an edge leaving
 * one ({@see RouteTemplateGraph::validateEdges()}, for the identical reason the
 * engine does — such an edge could never fire), so a design that SAVED cleanly
 * already satisfies both invariants.
 *
 * `satisfied_by` IS CARRIED TOO, AND FOR THE SAME REASON (#1054)
 * ---------------------------------------------------------------
 * A stage may be satisfied by DELIVERY: its people are told and are not asked to
 * act, its recipient rows are closed by the event that created them, and the
 * document carries straight on to the next stage.
 *
 * Nothing about that is derivable from any other column. A converter that
 * dropped it would produce the failure this whole issue exists to remove,
 * reached through the template door: the stage would become an ordinary
 * circulation, every instructor in a faculty would be handed an item that no act
 * of theirs was ever going to close, and each of them would carry the document
 * in "Awaiting me" for ever. Nothing anywhere would report an error — the route
 * would look exactly like a route waiting on some slow people.
 *
 * The mirror-image mistake is just as available and would be caught by nothing:
 * INFERRING delivery from "this stage has no outgoing edge and is not a
 * decision" describes almost every ordinary circulation step ever authored, and
 * would silently close everybody's item on all of them.
 *
 * WHY A DRIFTED ROW IS REFUSED RATHER THAN CORRECTED
 * ---------------------------------------------------
 * A stored graph can still violate those invariants — a hand-edited row, a
 * restored dump, a future writer that skips the validator. Faced with an edge
 * leaving a step whose `decision` is false, this class REFUSES and names the
 * stage. It would be one line shorter to coerce `decision` to true instead, and
 * that line would be a bug: marking a stage as a gate changes what every person
 * standing on it is ALLOWED to do — `forwarded` becomes a 422, a verdict becomes
 * mandatory — so the coercion would silently do MORE than the canvas draws, which
 * is the mirror image of the flattening this whole issue exists to avoid. A
 * design that cannot be run as drawn is one somebody has to look at.
 *
 * POSITIONS ARE REMAPPED, BECAUSE THEY ARE NOT PROMISED TO BE 1..N
 * ----------------------------------------------------------------
 * `document_route_template_steps` constrains `position` to be UNIQUE within a
 * template and at least 1 — not to be contiguous, and
 * {@see RouteTemplateGraph::validateSteps()} enforces exactly that and no more.
 * The canvas renumbers to 1..N after every delete, so templates authored through
 * the editor are contiguous in practice; a template written straight to
 * `PUT /graph` with positions `1, 2, 5` is legal and saves cleanly today.
 *
 * `DocumentRouter::issue()` numbers instance steps from ARRAY ORDER and reads
 * `on_approved` / `on_rejected` as indexes into that array, so a converter that
 * passed template positions through unchanged would, on that template, point
 * every branch at the wrong stage or at position 5 of a 3-step route — a 422 in
 * the lucky case and a silently re-pointed branch in the unlucky one. Steps are
 * therefore ordered by position and every edge target is rewritten through the
 * resulting map, in one pass, for the reason `packages/ui`'s `renumber()` gives
 * for doing the same: positions are what edges NAME.
 *
 * A BACKWARDS EDGE IS A FEATURE AND SURVIVES THIS INTACT
 * ------------------------------------------------------
 * "Rejected goes back to the department to fix" points at a LOWER position, and
 * it is the main reason the edge table exists. There is no forward-only
 * assumption anywhere below — the map is a lookup, not a comparison — matching
 * the engine, whose `nextForVerdict()` resolves an edge through a bare
 * `findById()` with no position test on any path.
 *
 * WHAT THIS CLASS DOES NOT DO
 * ---------------------------
 * It resolves nothing. A `group` or `role` step is copied as `rule_kind` +
 * `rule_config` and stays a TYPE: no roster is materialised here or anywhere
 * downstream, and the rule is resolved fresh when the step is reached — for step
 * one at issue, for the rest relative to whoever acts. A design authored in March
 * and applied in November therefore reaches the people who hold the role in
 * November, which is the entire argument for rules over stored lists.
 *
 * It also does not check the tenant's step ceiling. `documents.routing_max_steps`
 * is resolved per-tenant, then global, then the registry default, and
 * `DocumentRouter::validateSteps()` already applies it to whatever list it is
 * handed AT THE MOMENT OF ISSUE — which is the moment #1031 asks about, since the
 * setting can have moved since the design was authored. A second copy here would
 * be a second reading of a tenant-configurable number, free to disagree with the
 * first.
 *
 * STATELESS. Pure: no database, no settings, no clock.
 */
final class RouteTemplateInstantiation
{
    /**
     * Convert a stored graph into the `steps` payload the router accepts.
     *
     * @param list<array<string, mixed>>                       $steps Rows from
     *        {@see RouteTemplateRepository::stepsFor()}, in any order.
     * @param list<array{from: int, to: int, verdict: string}> $edges Rows from
     *        {@see RouteTemplateRepository::edgesFor()}, keyed by POSITION.
     *
     * @return list<array<string, mixed>> Ready for `DocumentRouter::issue()`.
     *
     * @throws RouteTemplateRejectedException When the design cannot be run as
     *         drawn (422).
     */
    public static function toRouteSteps(array $steps, array $edges): array
    {
        if ($steps === []) {
            // The engine's own message for an empty list talks about a route the
            // caller composed; this one talks about the design they picked,
            // which is the thing they can go and fix.
            throw RouteTemplateRejectedException::because(
                'This route template has no stages yet, so applying it would circulate the document to '
                . 'nobody and record it as sent. Draw at least one stage on it first.'
            );
        }

        $ordered = $steps;
        usort($ordered, static fn (array $a, array $b): int => (int) $a['position'] <=> (int) $b['position']);

        /** @var array<int, int> $ordinalByPosition Template position -> 1-based index in the route. */
        $ordinalByPosition = [];
        /** @var array<int, bool> $decisionByPosition */
        $decisionByPosition = [];
        foreach ($ordered as $index => $step) {
            $position = (int) $step['position'];
            $ordinalByPosition[$position] = $index + 1;
            $decisionByPosition[$position] = DbBool::of($step['decision']);
        }

        $branches = self::branches($edges, $ordinalByPosition, $decisionByPosition);

        $out = [];
        foreach ($ordered as $step) {
            $position = (int) $step['position'];
            $label = $step['label'] ?? null;
            $quorum = $step['decision_quorum'] ?? null;
            // CARRIED. Read this line against the docblock: an implementation
            // that derived this from `isset($branches[$position])` would convert
            // a terminal gate into an ordinary circulation step, and every test
            // downstream of it would still pass.
            //
            // Through {@see DbBool} rather than a bare cast. The repository has
            // already normalised this row, so the cast would be right today —
            // but this method takes an ARRAY, not a repository, and the one
            // representation that defeats `(bool)` is reachable: a boolean
            // projected as text comes back as the string 'false', which casts to
            // TRUE and would turn every circulation stage in the design into a
            // gate.
            $decision = DbBool::of($step['decision']);
            // CARRIED, exactly like `decision` above and never inferred — see the
            // class docblock for what inferring it in either direction costs.
            // Read through the vocabulary rather than cast, because this method
            // takes an ARRAY rather than a repository and a value it cannot read
            // must become an ordinary stage rather than a delivery one.
            $satisfiedBy = is_string($step['satisfied_by'] ?? null)
                && RouteSatisfaction::isValid((string) $step['satisfied_by'])
                    ? (string) $step['satisfied_by']
                    : RouteSatisfaction::fallback();

            if ($satisfiedBy === RouteSatisfaction::DELIVERY && $decision) {
                // REFUSED, not coerced, and the choice matters as much here as it
                // does for the edge case below. Silently clearing `decision` would
                // turn a stage the canvas draws as an approval into one that
                // approves nothing and lets the document straight through;
                // silently clearing `satisfied_by` would hand an unanswerable
                // approval to everybody the stage reaches. Both do MORE than the
                // design says, in opposite directions, which is why the answer is
                // to do neither and name the stage.
                throw RouteTemplateRejectedException::because(
                    "Stage {$position} of this template is marked both as a decision and as satisfied by "
                    . 'delivery. It cannot be both — a decision needs somebody holding the item to answer '
                    . 'it, and a delivery stage closes every item the moment it is sent. Fix the design '
                    . 'before applying it.'
                );
            }

            if ($quorum !== null && !$decision) {
                // Refused rather than dropped: the engine refuses the same pair
                // with the same reasoning, and an author whose quorum vanished
                // in translation would believe they had configured an approval.
                throw RouteTemplateRejectedException::because(
                    "Stage {$position} of this template sets a decision quorum but is not marked as a "
                    . 'decision, so nothing would ever read it. Fix the design before applying it.'
                );
            }

            $converted = [
                'rule_kind' => (string) $step['rule_kind'],
                // Passed through untouched. It is the rule's own vocabulary and
                // core does not claim to understand a plugin's config — nor does
                // it resolve one here: a group stays a group.
                'rule_config' => is_array($step['rule_config'] ?? null) ? $step['rule_config'] : [],
                'label' => is_string($label) && trim($label) !== '' ? trim($label) : null,
                'decision' => $decision,
                'decision_quorum' => is_string($quorum) ? $quorum : null,
                'satisfied_by' => $satisfiedBy,
            ];

            foreach (RouteVerdict::all() as $verdict) {
                if (isset($branches[$position][$verdict])) {
                    $converted['on_' . $verdict] = $branches[$position][$verdict];
                }
            }

            $out[] = $converted;
        }

        return $out;
    }

    /**
     * Collapse the flat edge list onto per-step branch targets, remapped to the
     * ordinals the route will use.
     *
     * @param list<array{from: int, to: int, verdict: string}> $edges
     * @param array<int, int>  $ordinalByPosition
     * @param array<int, bool> $decisionByPosition
     *
     * @return array<int, array<string, int>> position => verdict => target ordinal
     */
    private static function branches(array $edges, array $ordinalByPosition, array $decisionByPosition): array
    {
        /** @var array<int, array<string, int>> $branches */
        $branches = [];

        foreach ($edges as $edge) {
            $from = (int) $edge['from'];
            $to = (int) $edge['to'];
            $verdict = (string) $edge['verdict'];

            // Both endpoints are guaranteed by `edgesFor()`, which reads the
            // positions THROUGH a join onto the step rows, and by the foreign
            // keys under it. Kept as a narrowing branch rather than an assertion
            // because the alternative is an undefined-index fatal on a row nobody
            // can see, and because this method is worth being safe to call with a
            // hand-built list in a test.
            if (!isset($ordinalByPosition[$from]) || !isset($ordinalByPosition[$to])) {
                throw RouteTemplateRejectedException::because(
                    "This template has a branch between stages {$from} and {$to}, and one of them is not "
                    . 'a stage of the template. The design cannot be applied until it is redrawn.'
                );
            }

            if (!RouteVerdict::isValid($verdict)) {
                // CHECK-constrained in migration 120 and validated at save time.
                // Refused here too rather than passed on, because the engine's
                // own message for it would name a request field this caller never
                // sent.
                throw RouteTemplateRejectedException::because(
                    "This template has a branch from stage {$from} on '{$verdict}', which is not a verdict "
                    . 'this system can produce.'
                );
            }

            if ($decisionByPosition[$from] === false) {
                // See the class docblock for why this is refused and not fixed up.
                throw RouteTemplateRejectedException::because(
                    "Stage {$from} of this template branches on '{$verdict}' but is not marked as a "
                    . 'decision, so it never produces a verdict and the branch could never be taken. Mark '
                    . 'the stage as a decision, or remove the branch, before applying the design.'
                );
            }

            if (isset($branches[$from][$verdict])) {
                // `uq_document_route_template_edges_from_verdict` makes this
                // unreachable from a stored graph. It is the one invariant the
                // conversion RELIES on — two answers to one question cannot be
                // expressed as `on_approved` at all — so it is asserted rather
                // than assumed.
                throw RouteTemplateRejectedException::because(
                    "Stage {$from} of this template has two '{$verdict}' branches. A stage has one "
                    . 'destination per verdict.'
                );
            }

            $branches[$from][$verdict] = $ordinalByPosition[$to];
        }

        return $branches;
    }
}
