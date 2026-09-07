<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\RouteTemplate;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRuleRegistry;

/**
 * The design-to-route conversion (#1031).
 *
 * WHAT THESE TESTS ARE WRITTEN AGAINST
 * ------------------------------------
 * Every way this conversion can be wrong produces a route that ISSUES CLEANLY
 * and then behaves differently from the picture its author drew. Nothing throws,
 * nothing is logged, and the first person to notice is whoever expected a
 * document to come back for approval and never saw it. So each test below names
 * the WRONG implementation it would catch, and asserts the thing that
 * implementation would get wrong rather than the thing it would get right:
 *
 *  1. `decision` INFERRED FROM THE EDGES. Passes for every branching gate and
 *     fails only for a gate at the END of a route, which is the ordinary shape of
 *     an approval flow. {@see testATerminalGateStaysAGate}.
 *  2. TEMPLATE POSITIONS PASSED THROUGH AS ROUTE ORDINALS. Passes for every
 *     template the canvas produced (it renumbers to 1..N) and mis-points every
 *     branch on one that was not. {@see testNonContiguousPositionsAreRemapped}.
 *  3. A FORWARD-ONLY ASSUMPTION about edges. Silently drops the send-it-back
 *     branch, which is the design the edge table exists for.
 *     {@see testABackwardsRejectEdgeSurvives}.
 *
 * These are unit tests over a pure function, which is the point: the conversion
 * is the whole feature and it is worth pinning without a database in the way.
 * {@see \Tests\Core\Document\RouteTemplate\RouteTemplateInstantiationRealEngineTest}
 * then drives real documents through the routes this produces.
 */
final class RouteTemplateInstantiationTest extends TestCase
{
    // -- the trap: `decision` is carried, never inferred ----------------------

    public function testATerminalGateStaysAGate(): void
    {
        // THE TEST #1031's CONVERTER EXISTS FOR. Two stages, the SECOND a
        // decision, and no edges anywhere — "circulate, then the dean approves",
        // which is what most approval flows look like. An implementation that
        // read `decision` off the edge list marks neither stage as a gate, the
        // route issues, the dean acknowledges it without a verdict, and the trail
        // shows a document that travelled its whole route with nobody approving
        // anything.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [
                $this->step(1, decision: false),
                $this->step(2, decision: true),
            ],
            []
        );

        self::assertFalse($steps[0]['decision'], 'a circulation stage must not become a gate');
        self::assertTrue(
            $steps[1]['decision'],
            'a gate at the END of a route has NO outgoing edge and still demands a verdict. Inferring '
            . '`decision` from the edges loses exactly this stage, and loses it silently'
        );
    }

    public function testAGateWithNoEdgesIsStillAGateEvenWhenItIsTheOnlyStage(): void
    {
        // The minimal case, and the one a "does it have edges" implementation
        // gets wrong with no other stage to hide behind.
        $steps = RouteTemplateInstantiation::toRouteSteps([$this->step(1, decision: true)], []);

        self::assertTrue($steps[0]['decision']);
        self::assertArrayNotHasKey('on_approved', $steps[0]);
        self::assertArrayNotHasKey('on_rejected', $steps[0]);
    }

    public function testACirculationStageBetweenTwoGatesIsNotPromotedToOne(): void
    {
        // The converse mistake: marking everything a gate because something in
        // the template is one. A stage that demands a verdict refuses `forwarded`
        // outright, so promoting a circulation stage would break the one act its
        // recipients are meant to perform.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [
                $this->step(1, decision: true),
                $this->step(2, decision: false),
                $this->step(3, decision: true),
            ],
            [['from' => 1, 'to' => 3, 'verdict' => RouteVerdict::REJECTED]]
        );

        self::assertSame([true, false, true], array_column($steps, 'decision'));
    }

    // -- positions -----------------------------------------------------------

    public function testNonContiguousPositionsAreRemappedAndCarryTheirEdges(): void
    {
        // A template whose positions are 1, 4, 9 — legal today: migration 120
        // makes `position` UNIQUE and >= 1, not contiguous, and
        // `RouteTemplateGraph::validateSteps()` enforces exactly that. The canvas
        // renumbers after a delete, so this is what a template written straight to
        // `PUT /graph` looks like.
        //
        // The route the engine builds numbers its steps 1, 2, 3 from ARRAY ORDER.
        // An implementation that passed the template's own numbers through would
        // emit `on_rejected: 1` (right by luck) and `on_approved: 9` on a 3-step
        // route — refused by the engine — or, on a template numbered 2, 3, 1,
        // point every branch at a stage its author never chose.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [
                $this->step(9, decision: false, label: 'third'),
                $this->step(1, decision: true, label: 'first'),
                $this->step(4, decision: true, label: 'second'),
            ],
            [
                ['from' => 1, 'to' => 9, 'verdict' => RouteVerdict::APPROVED],
                ['from' => 4, 'to' => 1, 'verdict' => RouteVerdict::REJECTED],
            ]
        );

        self::assertSame(
            ['first', 'second', 'third'],
            array_column($steps, 'label'),
            'stages must be emitted in POSITION order — the array order IS the route order'
        );
        self::assertSame(3, $steps[0]['on_approved'], 'position 9 is the THIRD stage of the route');
        self::assertSame(1, $steps[1]['on_rejected'], 'position 1 is the FIRST stage of the route');
    }

    public function testStepsAreOrderedByPositionRegardlessOfRowOrder(): void
    {
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [
                $this->step(3, label: 'c'),
                $this->step(1, label: 'a'),
                $this->step(2, label: 'b'),
            ],
            []
        );

        self::assertSame(['a', 'b', 'c'], array_column($steps, 'label'));
    }

    // -- branches ------------------------------------------------------------

    public function testABackwardsRejectEdgeSurvives(): void
    {
        // "Rejected goes back to the author to fix" is the design the edge table
        // exists for, and it is a cycle. An implementation that assumed edges run
        // forwards would drop this branch, and the rejection would then END the
        // chain — which looks, from every screen, exactly like a rejection that
        // was correctly recorded.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [
                $this->step(1, decision: false),
                $this->step(2, decision: true),
            ],
            [['from' => 2, 'to' => 1, 'verdict' => RouteVerdict::REJECTED]]
        );

        self::assertSame(1, $steps[1]['on_rejected'], 'a reject edge may point BACKWARDS');
        self::assertArrayNotHasKey(
            'on_approved',
            $steps[1],
            'a reject edge must not become an approve edge — an approval with no edge falls through to '
            . 'the next ordinal, and inventing one here would send it somewhere nobody drew'
        );
    }

    public function testBothVerdictsOnOneStageAreCarriedSeparately(): void
    {
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [
                $this->step(1, decision: true),
                $this->step(2),
                $this->step(3),
            ],
            [
                ['from' => 1, 'to' => 3, 'verdict' => RouteVerdict::REJECTED],
                ['from' => 1, 'to' => 2, 'verdict' => RouteVerdict::APPROVED],
            ]
        );

        self::assertSame(2, $steps[0]['on_approved']);
        self::assertSame(3, $steps[0]['on_rejected']);
    }

    public function testAStageWithNoEdgeForAVerdictDeclaresNothingForIt(): void
    {
        // Absent, not null and not a sentinel. The engine reads absence as "fall
        // through to the next ordinal" for an approval and "the chain ends" for a
        // rejection, and a target it had to interpret would be a third meaning.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [$this->step(1, decision: true), $this->step(2)],
            [['from' => 1, 'to' => 2, 'verdict' => RouteVerdict::APPROVED]]
        );

        self::assertArrayHasKey('on_approved', $steps[0]);
        self::assertArrayNotHasKey('on_rejected', $steps[0]);
    }

    // -- what is copied verbatim ---------------------------------------------

    public function testAGroupStageStaysARuleAndIsNotResolved(): void
    {
        // The load-bearing semantic of the whole feature. A `group` stage is ONE
        // row naming a definition, and it must arrive at the engine as one row
        // naming that definition — not as the people it happens to resolve to
        // today. There is nowhere in the output for a roster to go, which is what
        // makes this a real guarantee rather than a convention, and this test
        // pins the config through unchanged.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [[
                'position' => 1,
                'rule_kind' => RoutingRuleRegistry::KIND_GROUP,
                'rule_config' => ['group_id' => 7],
                'label' => 'Purchasing committee',
                'decision' => true,
                'decision_quorum' => 'majority',
            ]],
            []
        );

        self::assertSame(RoutingRuleRegistry::KIND_GROUP, $steps[0]['rule_kind']);
        self::assertSame(['group_id' => 7], $steps[0]['rule_config']);
        self::assertArrayNotHasKey('profile_ids', $steps[0]['rule_config']);
        self::assertArrayNotHasKey('recipients', $steps[0]);
        self::assertSame('majority', $steps[0]['decision_quorum'], 'the quorum is the design, and it is carried');
    }

    public function testAnAbsentQuorumStaysAbsentRatherThanBeingResolved(): void
    {
        // NULL is not "no quorum": it means "ask the settings chain", which is
        // what lets a tenant change the rule for every stage at once. Resolving it
        // here would freeze today's answer into a route whose author deliberately
        // did not choose one.
        $steps = RouteTemplateInstantiation::toRouteSteps([$this->step(1, decision: true)], []);

        self::assertNull($steps[0]['decision_quorum']);
    }

    public function testAnEmptyLabelBecomesNullRatherThanAnEmptyString(): void
    {
        $steps = RouteTemplateInstantiation::toRouteSteps([$this->step(1, label: '   ')], []);

        self::assertNull($steps[0]['label']);
    }

    // -- refusals ------------------------------------------------------------

    public function testATemplateWithNoStagesIsRefusedInItsOwnWords(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessageMatches('/no stages yet/');

        RouteTemplateInstantiation::toRouteSteps([], []);
    }

    public function testABranchLeavingAStageThatProducesNoVerdictIsRefusedNotCorrected(): void
    {
        // `RouteTemplateGraph::validateEdges()` refuses this at save time, so a
        // stored graph in this shape means the rows drifted — a hand edit, a
        // restored dump, a future writer that skipped the validator.
        //
        // The tempting fix is to set `decision = true` and carry on. It would be
        // wrong: marking a stage as a gate changes what every person standing on
        // it may DO (`forwarded` becomes a 422, a verdict becomes mandatory), so
        // the coercion silently does MORE than the canvas draws. The refusal names
        // the stage so somebody can go and look at it.
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessageMatches('/Stage 1 .*not marked as a decision/s');

        RouteTemplateInstantiation::toRouteSteps(
            [$this->step(1, decision: false), $this->step(2)],
            [['from' => 1, 'to' => 2, 'verdict' => RouteVerdict::APPROVED]]
        );
    }

    public function testAQuorumOnAStageThatDemandsNoVerdictIsRefused(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessageMatches('/Stage 2 .*decision quorum/s');

        RouteTemplateInstantiation::toRouteSteps(
            [
                $this->step(1),
                [
                    'position' => 2,
                    'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                    'rule_config' => ['role_id' => 1],
                    'label' => null,
                    'decision' => false,
                    'decision_quorum' => 'any',
                ],
            ],
            []
        );
    }

    public function testABranchNamingAStageTheTemplateDoesNotHaveIsRefused(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessageMatches('/not\s+a stage of the template/s');

        RouteTemplateInstantiation::toRouteSteps(
            [$this->step(1, decision: true)],
            [['from' => 1, 'to' => 42, 'verdict' => RouteVerdict::APPROVED]]
        );
    }

    public function testTwoBranchesForOneVerdictAreRefusedRatherThanOneWinning(): void
    {
        // Unreachable from a stored graph — `uq_document_route_template_edges_from_verdict`
        // refuses it — and asserted anyway, because it is the invariant the whole
        // conversion RESTS on: `on_approved` is one field and cannot express two
        // answers, so an implementation that just let the last edge win would
        // quietly discard a branch.
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessageMatches('/two \'approved\' branches/');

        RouteTemplateInstantiation::toRouteSteps(
            [$this->step(1, decision: true), $this->step(2), $this->step(3)],
            [
                ['from' => 1, 'to' => 2, 'verdict' => RouteVerdict::APPROVED],
                ['from' => 1, 'to' => 3, 'verdict' => RouteVerdict::APPROVED],
            ]
        );
    }

    public function testAVerdictThisSystemCannotProduceIsRefused(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessageMatches('/not a verdict/');

        RouteTemplateInstantiation::toRouteSteps(
            [$this->step(1, decision: true), $this->step(2)],
            [['from' => 1, 'to' => 2, 'verdict' => 'deferred']]
        );
    }

    // -- helpers -------------------------------------------------------------

    /**
     * One template step row in the shape {@see \Whity\Core\Document\RouteTemplate\RouteTemplateRepository::stepsFor()}
     * returns it — including the fields the converter must ignore, so a test
     * cannot pass by reading a shape the repository never produces.
     *
     * @return array<string, mixed>
     */
    private function step(int $position, bool $decision = false, ?string $label = null): array
    {
        return [
            'id' => 1000 + $position,
            'template_id' => 7,
            'position' => $position,
            'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
            'rule_config' => ['role_id' => 100 + $position],
            'label' => $label,
            'decision' => $decision,
            'decision_quorum' => null,
            'canvas_x' => 0,
            'canvas_y' => 0,
        ];
    }
}
