<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\RouteTemplate;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Sdk\Routing\RoutingRuleContext;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * Validating a whole flow before a row is written (#1027).
 *
 * Every assertion here is about an OUTCOME the author would see — the request
 * refused, and refused with a message naming the stage — rather than about the
 * shape of an intermediate. The two failures worth naming, because they are the
 * ones a template can produce that nothing downstream would ever report:
 *
 *  - AN EDGE LEAVING A NON-DECISION STAGE. It can never fire, because such a
 *    stage produces no verdict. Saved, it would sit on the canvas looking like
 *    part of the design and route nothing, forever.
 *
 *  - A QUORUM ON A NON-DECISION STAGE. It can never be consulted, and a stored
 *    value nothing reads is indistinguishable on screen from one that works.
 *
 * Both are the stored-intention failure migration 112 names for configured
 * effects, reached through a different door.
 */
final class RouteTemplateGraphTest extends TestCase
{
    private RouteTemplateGraph $graph;

    protected function setUp(): void
    {
        $registry = new RoutingRuleRegistry();
        // A kind whose validate() refuses a config, so the "the resolver's own
        // message reaches the author" path is exercised against a real registry
        // and a real resolver rather than a mock of either.
        $registry->register('acme', ['committee' => new FakeCommitteeResolver()]);

        $this->graph = new RouteTemplateGraph($registry);
    }

    /** A minimal valid stage naming the fake kind. */
    private function stage(int $position, array $overrides = []): array
    {
        return array_merge([
            'position' => $position,
            'rule_kind' => 'acme:committee',
            'rule_config' => ['committee_id' => 4],
            'decision' => false,
        ], $overrides);
    }

    public function testAcceptsALinearFlowWithNoEdgesAtAll(): void
    {
        $result = $this->graph->validate([$this->stage(1), $this->stage(2)], [], 20);

        self::assertCount(2, $result['steps']);
        self::assertSame([], $result['edges']);
        // Absent coordinates default to the origin rather than being refused: a
        // graph authored through the API has no canvas, and the editor lays one
        // out on first open.
        self::assertSame(0, $result['steps'][0]['canvas_x']);
    }

    public function testRefusesAnEdgeLeavingAStageThatProducesNoVerdict(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('is not a decision step');

        $this->graph->validate(
            [$this->stage(1), $this->stage(2)],
            [['from' => 1, 'to' => 2, 'verdict' => 'approved']],
            20
        );
    }

    public function testAcceptsBothVerdictEdgesFromOneDecisionStage(): void
    {
        // The motivating shape: approve goes on, reject diverges.
        $result = $this->graph->validate(
            [$this->stage(1, ['decision' => true]), $this->stage(2), $this->stage(3)],
            [
                ['from' => 1, 'to' => 2, 'verdict' => 'approved'],
                ['from' => 1, 'to' => 3, 'verdict' => 'rejected'],
            ],
            20
        );

        self::assertSame(
            [
                ['from' => 1, 'to' => 2, 'verdict' => 'approved'],
                ['from' => 1, 'to' => 3, 'verdict' => 'rejected'],
            ],
            $result['edges']
        );
    }

    public function testAcceptsARejectEdgePointingBackwards(): void
    {
        // "Send it back to the author to fix" is a cycle, and it is the single
        // most common real approval design. A validator that refused cycles would
        // refuse the feature's motivating example.
        $result = $this->graph->validate(
            [$this->stage(1), $this->stage(2, ['decision' => true])],
            [['from' => 2, 'to' => 1, 'verdict' => 'rejected']],
            20
        );

        self::assertSame([['from' => 2, 'to' => 1, 'verdict' => 'rejected']], $result['edges']);
    }

    public function testRefusesTwoEdgesForOneVerdict(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage("two 'approved' edges");

        $this->graph->validate(
            [$this->stage(1, ['decision' => true]), $this->stage(2), $this->stage(3)],
            [
                ['from' => 1, 'to' => 2, 'verdict' => 'approved'],
                ['from' => 1, 'to' => 3, 'verdict' => 'approved'],
            ],
            20
        );
    }

    public function testRefusesAnEdgeToAPositionThatIsNotOnTheCanvas(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('no step at position 9');

        $this->graph->validate(
            [$this->stage(1, ['decision' => true])],
            [['from' => 1, 'to' => 9, 'verdict' => 'approved']],
            20
        );
    }

    public function testRefusesASelfLoop(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('cannot lead to itself');

        $this->graph->validate(
            [$this->stage(1, ['decision' => true])],
            [['from' => 1, 'to' => 1, 'verdict' => 'rejected']],
            20
        );
    }

    public function testRefusesAQuorumOnAStageThatAsksForNoVerdict(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('only means something on a decision step');

        $this->graph->validate(
            [$this->stage(1, ['decision' => false, 'decision_quorum' => 'any'])],
            [],
            20
        );
    }

    public function testAcceptsAQuorumOnADecisionStage(): void
    {
        $result = $this->graph->validate(
            [$this->stage(1, ['decision' => true, 'decision_quorum' => 'majority'])],
            [],
            20
        );

        self::assertSame('majority', $result['steps'][0]['decision_quorum']);
    }

    public function testTreatsAnAbsentQuorumAsDeferredRatherThanResolvingIt(): void
    {
        // NULL means "follow the tenant setting". Resolving it here would freeze
        // today's answer onto a design whose whole point is that the setting can
        // change it later.
        $result = $this->graph->validate([$this->stage(1, ['decision' => true])], [], 20);

        self::assertNull($result['steps'][0]['decision_quorum']);
    }

    public function testRefusesAQuorumOutsideTheVocabulary(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('decision_quorum');

        $this->graph->validate(
            [$this->stage(1, ['decision' => true, 'decision_quorum' => 'two_thirds'])],
            [],
            20
        );
    }

    public function testRefusesAVerdictOutsideTheVocabularyAndSaysWhyThereIsNoUnconditionalEdge(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('falls through to the next position');

        $this->graph->validate(
            [$this->stage(1, ['decision' => true]), $this->stage(2)],
            [['from' => 1, 'to' => 2, 'verdict' => 'always']],
            20
        );
    }

    public function testRefusesAnUnregisteredRuleKind(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage("no routing rule 'acme:ghost' is registered");

        $this->graph->validate([$this->stage(1, ['rule_kind' => 'acme:ghost'])], [], 20);
    }

    public function testRelaysTheResolversOwnMessageAboutItsOwnConfig(): void
    {
        // The resolver decides whether its config is usable, and its message was
        // written for this author to read. Re-validating here would be a second
        // implementation of a rule's semantics — wrong for every plugin kind by
        // construction.
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage("needs a 'committee_id'");

        $this->graph->validate([$this->stage(1, ['rule_config' => []])], [], 20);
    }

    public function testRefusesDuplicatePositions(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('share position 1');

        $this->graph->validate([$this->stage(1), $this->stage(1)], [], 20);
    }

    public function testRefusesMoreStagesThanTheTenantCeilingAndNamesTheSetting(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('documents.routing_max_steps');

        $this->graph->validate([$this->stage(1), $this->stage(2), $this->stage(3)], [], 2);
    }

    public function testRefusesAJsonObjectWhereAListOfStagesWasMeant(): void
    {
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('JSON array, not an object');

        $this->graph->validate(['a' => $this->stage(1)], [], 20);
    }

    public function testRefusesACoordinateFarOutsideTheCanvas(): void
    {
        // A node at 10^9 is not visible, is not reachable by fit-to-view, and
        // looks to its author exactly like a node that vanished.
        $this->expectException(RouteTemplateRejectedException::class);
        $this->expectExceptionMessage('canvas_x');

        $this->graph->validate([$this->stage(1, ['canvas_x' => 1000000000])], [], 20);
    }

    public function testKeepsNegativeCoordinatesBecauseAnRtlFlowUsesThem(): void
    {
        // Not an edge case to be clamped away: under RTL every horizontal axis is
        // mirrored, so a perfectly ordinary right-to-left flow has negative x on
        // every stage but the first.
        $result = $this->graph->validate([$this->stage(1, ['canvas_x' => -296])], [], 20);

        self::assertSame(-296, $result['steps'][0]['canvas_x']);
    }
}

/**
 * A plugin-contributed kind, so the registry path under test is the real one.
 */
final class FakeCommitteeResolver implements RoutingRuleResolverInterface
{
    public function label(): string
    {
        return 'Everyone on a committee';
    }

    public function validate(array $config): void
    {
        if (!isset($config['committee_id'])) {
            throw new InvalidArgumentException("the 'committee' rule needs a 'committee_id'");
        }
    }

    public function resolve(RoutingRuleContext $context): array
    {
        return [];
    }
}
