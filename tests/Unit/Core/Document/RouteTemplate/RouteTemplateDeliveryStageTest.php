<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\RouteTemplate;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Sdk\Routing\RoutingRuleContext;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * A DELIVERY STAGE has to survive the round trip through a template (#1054).
 *
 * #1031 made a stored design runnable, and its converter's own docblock names the
 * trap: a property the conversion cannot CARRY is a property the design silently
 * loses. `satisfied_by` is the sharpest instance of it yet, because BOTH ways of
 * getting it wrong are silent and they fail in opposite directions:
 *
 *  - DROPPED. The stage becomes an ordinary circulation, every instructor in a
 *    faculty is handed an item no act of theirs will ever close, and each of them
 *    carries the document in "Awaiting me" for ever. The route issues cleanly and
 *    looks like one waiting on some slow people.
 *
 *  - INFERRED. "No outgoing edge and not a decision" describes almost every
 *    ordinary circulation stage ever drawn, so inferring delivery from it would
 *    close everybody's item on all of them and move every document straight past
 *    the people it was sent to.
 *
 * So it is CARRIED, and a design that cannot be run as drawn is REFUSED rather
 * than tidied up — the same instinct {@see RouteTemplateInstantiation} applies to
 * `decision` and to a quorum on a stage that asks for no verdict.
 *
 * Unit tests over the pure converter and the pure validator, deliberately:
 * the conversion IS the feature, and it is worth pinning with no database in the
 * way. {@see \Tests\Core\Document\Routing\DocumentRouterDeliveryStepRealEngineTest}
 * then drives real documents through what these produce.
 */
final class RouteTemplateDeliveryStageTest extends TestCase
{
    // -- the converter: carried, never inferred ------------------------------

    public function testADeliveryStageConvertsIntoADeliveryStep(): void
    {
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [
                $this->stored(1),
                $this->stored(2, ['satisfied_by' => RouteSatisfaction::DELIVERY]),
            ],
            []
        );

        self::assertSame(
            [RouteSatisfaction::ACT, RouteSatisfaction::DELIVERY],
            array_column($steps, 'satisfied_by'),
            'the design said one stage tells its people and does not ask them. A conversion that dropped '
            . 'that hands every one of them an item nothing can close'
        );
    }

    public function testAnOrdinaryTerminalStageIsNotMistakenForADeliveryStage(): void
    {
        // THE MIRROR-IMAGE TRAP, and the one an implementation is likeliest to
        // fall into while trying to be helpful. The last stage of a plain
        // circulation has no outgoing edge, is not a decision, and is asked for
        // an acknowledgement — exactly the shape a "surely this one is just an
        // announcement" inference would swallow.
        $steps = RouteTemplateInstantiation::toRouteSteps([$this->stored(1), $this->stored(2)], []);

        self::assertSame(
            [RouteSatisfaction::ACT, RouteSatisfaction::ACT],
            array_column($steps, 'satisfied_by'),
            'nothing about a stage may be inferred into `delivery` — the inference is right for the one '
            . 'design somebody had in mind and wrong for every circulation ever drawn'
        );
    }

    public function testAStoredValueNothingCanReadBecomesAnOrdinaryStage(): void
    {
        // A hand-edited row, a restored dump, a future writer. The fallback is
        // `act` because the two ways of being wrong are not symmetric: an
        // unreadable value read as `act` produces a document that visibly waits
        // for somebody, which somebody chases; read as `delivery` it closes every
        // item and moves on, which nothing reports.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [$this->stored(1, ['satisfied_by' => 'notify'])],
            []
        );

        self::assertSame(RouteSatisfaction::ACT, $steps[0]['satisfied_by']);
    }

    public function testADriftedDesignIsRefusedRatherThanQuietlyCorrected(): void
    {
        // Delivery AND decision. Coercing either half would do MORE than the
        // canvas draws, in opposite directions: clearing `decision` lets a
        // document through a stage drawn as an approval, and clearing
        // `satisfied_by` hands an unanswerable approval to everybody the stage
        // reaches. So neither, and the stage is named.
        $this->expectException(RouteTemplateRejectedException::class);

        try {
            RouteTemplateInstantiation::toRouteSteps(
                [$this->stored(1, ['satisfied_by' => RouteSatisfaction::DELIVERY, 'decision' => true])],
                []
            );
        } catch (RouteTemplateRejectedException $e) {
            self::assertStringContainsString('Stage 1', $e->clientMessage);
            self::assertStringContainsString('cannot be both', $e->clientMessage);
            throw $e;
        }
    }

    public function testADeliveryStageSurvivesPositionRemapping(): void
    {
        // Template positions are unique but not promised contiguous, and the
        // converter renumbers. A delivery stage in the middle of a remapped list
        // must still be the stage it was — the property has to travel with the
        // ROW, not with an index into one.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [
                $this->stored(5),
                $this->stored(1, ['satisfied_by' => RouteSatisfaction::DELIVERY]),
                $this->stored(3),
            ],
            []
        );

        self::assertSame(
            [RouteSatisfaction::DELIVERY, RouteSatisfaction::ACT, RouteSatisfaction::ACT],
            array_column($steps, 'satisfied_by'),
            'ordered by stored position (1, 3, 5), and the delivery stage is the one the author marked'
        );
    }

    public function testTheRuleIsCopiedAsATypeAndNoRosterIsMaterialised(): void
    {
        // A delivery stage is the one most likely to resolve to hundreds of
        // people, which makes it the one most tempting to freeze. It is not: the
        // converter copies `rule_kind` + `rule_config` and resolves nothing, so a
        // design authored in March and applied in November reaches whoever holds
        // the role in November.
        $steps = RouteTemplateInstantiation::toRouteSteps(
            [$this->stored(1, [
                'satisfied_by' => RouteSatisfaction::DELIVERY,
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                'rule_config' => ['role_id' => 103],
            ])],
            []
        );

        self::assertSame(RoutingRuleRegistry::KIND_ROLE, $steps[0]['rule_kind']);
        self::assertSame(['role_id' => 103], $steps[0]['rule_config']);
        self::assertArrayNotHasKey(
            'profile_ids',
            $steps[0]['rule_config'],
            'a node encodes a TYPE. Materialising a roster here would freeze a faculty as it stood the '
            . 'day the design was drawn'
        );
    }

    // -- the validator: what the canvas may save -----------------------------

    public function testTheGraphValidatorAcceptsADeliveryStage(): void
    {
        $result = $this->graph()->validate(
            [
                $this->drawn(1),
                $this->drawn(2, ['satisfied_by' => RouteSatisfaction::DELIVERY]),
            ],
            [],
            20
        );

        self::assertSame(
            [RouteSatisfaction::ACT, RouteSatisfaction::DELIVERY],
            array_column($result['steps'], 'satisfied_by')
        );
    }

    public function testAStageThatSaysNothingIsAnOrdinaryStage(): void
    {
        // Every design authored before #1054 omits the field entirely, and none
        // of them may change behaviour because migration 124 ran.
        $result = $this->graph()->validate([$this->drawn(1)], [], 20);

        self::assertSame(RouteSatisfaction::ACT, $result['steps'][0]['satisfied_by']);
    }

    public function testTheGraphValidatorRefusesADeliveryStageThatIsAlsoAGate(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);

        try {
            $this->graph()->validate(
                [$this->drawn(1, ['satisfied_by' => RouteSatisfaction::DELIVERY, 'decision' => true])],
                [],
                20
            );
        } catch (RouteTemplateRejectedException $e) {
            self::assertStringContainsString('cannot be both', $e->clientMessage);
            throw $e;
        }
    }

    public function testTheGraphValidatorRefusesAValueOutsideTheVocabulary(): void
    {
        // Refused at SAVE time rather than normalised, so an author who typed
        // `notify` is told, instead of saving a design that renders as an
        // announcement and runs as a circulation.
        $this->expectException(RouteTemplateRejectedException::class);

        try {
            $this->graph()->validate([$this->drawn(1, ['satisfied_by' => 'notify'])], [], 20);
        } catch (RouteTemplateRejectedException $e) {
            self::assertStringContainsString('satisfied_by', $e->clientMessage);
            throw $e;
        }
    }

    // -- helpers -------------------------------------------------------------

    /**
     * A validator over a registry holding one fake kind.
     *
     * A fake rather than core's own resolvers because those need a PDO, and
     * nothing in this file is about who a rule reaches — it is about a property
     * of the STAGE that must survive being saved and converted. Declared in this
     * file rather than borrowed from a sibling test so that running this one file
     * alone behaves the same as running the suite.
     */
    private function graph(): RouteTemplateGraph
    {
        $registry = new RoutingRuleRegistry();
        $registry->register('acme', ['announce' => new FakeAnnounceResolver()]);

        return new RouteTemplateGraph($registry);
    }

    /**
     * A stored template step row, as {@see RouteTemplateRepository::stepsFor()}
     * normalises one.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function stored(int $position, array $overrides = []): array
    {
        return array_merge([
            'position' => $position,
            'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
            'rule_config' => ['profile_ids' => [7]],
            'label' => null,
            'decision' => false,
            'decision_quorum' => null,
            'satisfied_by' => RouteSatisfaction::ACT,
        ], $overrides);
    }

    /**
     * A stage as the CANVAS submits one — no `satisfied_by` unless the author set
     * it, which is what every design drawn before #1054 looks like.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function drawn(int $position, array $overrides = []): array
    {
        return array_merge([
            'position' => $position,
            'rule_kind' => 'acme:announce',
            'rule_config' => ['audience' => 'instructors'],
            'decision' => false,
        ], $overrides);
    }
}

/**
 * A rule kind that accepts any config. Its job here is only to exist, so the
 * validator gets past "no such kind" and on to the question these tests ask.
 */
final class FakeAnnounceResolver implements RoutingRuleResolverInterface
{
    public function label(): string
    {
        return 'Everyone in an audience';
    }

    public function validate(array $config): void
    {
    }

    public function resolve(RoutingRuleContext $context): array
    {
        return [];
    }
}
