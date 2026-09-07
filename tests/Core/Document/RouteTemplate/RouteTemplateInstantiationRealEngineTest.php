<?php

declare(strict_types=1);

namespace Tests\Core\Document\RouteTemplate;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Applying a route template to a real document (#1031) — the first time anybody
 * has watched one run.
 *
 * WHY THIS FILE IS NOT ANOTHER CONVERTER TEST
 * -------------------------------------------
 * {@see \Tests\Unit\Core\Document\RouteTemplate\RouteTemplateInstantiationTest}
 * pins the conversion as a pure function. It cannot tell you whether the route
 * that comes out BEHAVES like the picture the author drew, because it never runs
 * one. #1027's author said as much when the editor shipped:
 *
 *   "the convergence and loop notes are my reading of engine semantics… nobody
 *   has watched a real document traverse a converging or looping template,
 *   because instantiation doesn't exist yet. The first time it runs is the first
 *   real test of whether those labels say the right thing."
 *
 * So every test below applies a stored design to a real document and then DRIVES
 * IT, act by act, asserting what ended up in `document_route_recipients` and
 * `document_route_events` rather than what the engine returned about itself.
 *
 * TWO CANVAS LABELS ARE UNDER TEST BY NAME
 * -----------------------------------------
 * `packages/ui/src/route-flow/editor.tsx` draws exactly two semantic notes about
 * flow shape, and both are claims about an engine nothing had ever run:
 *
 *   "Paths merge — 1 item per person"   (`merges`)
 *   "Can come back round — loops"       (`inCycle`)
 *
 * {@see testAMergeWhoseArrivalsReachTheSamePersonSettlesExactlyOnce} and
 * {@see testAMergeWhoseRuleIsActorRelativeSettlesOncePerArrivingChain} are the
 * two halves of the first claim, and they do not agree with each other. The
 * second is the interesting one: read the note it carries.
 *
 * ENGINE. {@see SchemaFromMigrations::make()} returns real PostgreSQL when
 * PHPUNIT_PG_DSN is set and SQLite otherwise, and both matter — the template
 * tables' CHECK constraints and the partial unique index that does the
 * de-duplication are only enforced by a real engine, while the SQLite path is
 * what CI's unit job runs.
 */
final class RouteTemplateInstantiationRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** The raiser: a dean, in the Faculty unit (20). */
    private const DEAN = 10;
    /** Two heads, deliberately in DIFFERENT units, so an actor-relative rule diverges. */
    private const HEAD_A = 11;
    private const HEAD_B = 12;
    /** A third head, in Dept A, who acquires the role mid-test. */
    private const HEAD_C = 13;
    /** One technician per department. */
    private const TECH_A = 14;
    private const TECH_B = 15;
    /** Nobody's subordinate: the registrar sits in the Faculty unit. */
    private const REGISTRAR = 16;

    private const OU_FACULTY = 20;
    private const OU_DEPT_A = 21;
    private const OU_DEPT_B = 22;

    private const ROLE_DEAN = 100;
    private const ROLE_HEAD = 101;
    private const ROLE_TECH = 102;
    private const ROLE_REGISTRAR = 103;

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteTemplateRepository $templates;
    private RouteTemplateGraph $graph;
    private RouteStepRepository $steps;
    private RouteEventRepository $events;
    private RouteRecipientRepository $recipients;
    private RouteEdgeRepository $edges;
    private SettingsService $settings;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();

        $this->steps = new RouteStepRepository($this->pdo);
        $this->events = new RouteEventRepository($this->pdo);
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $this->edges = new RouteEdgeRepository($this->pdo);
        $this->templates = new RouteTemplateRepository($this->pdo);
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        // Wired exactly as public/index.php wires it. A stub registry here would
        // let a template save with a kind production could not resolve.
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $this->pdo,
                new UserGroupRepository($this->pdo),
                static fn (): RoutingRuleRegistry => $registry
            ))
        );

        $this->graph = new RouteTemplateGraph($rules);
        $this->router = new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            $this->steps,
            $this->events,
            $this->recipients,
            $this->edges,
            $rules,
            $this->settings,
            null
        );
    }

    // -- the design arrives intact -------------------------------------------

    public function testABranchingDesignBecomesARouteWithTheSameBranches(): void
    {
        // The whole point of #1031: a design with branches must not be flattened
        // into a linear route. Asserted on the EDGES the engine wrote, read back
        // as step ids, because that is what `nextForVerdict()` will consult — not
        // on the payload the converter produced, which is a copy of its own
        // input.
        $templateId = $this->template('Purchase approval', [
            $this->stage(1, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::HEAD_A]], decision: true),
            $this->stage(2, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::TECH_A]]),
            $this->stage(3, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::DEAN]]),
        ], [
            ['from' => 1, 'to' => 3, 'verdict' => RouteVerdict::REJECTED],
        ]);

        $issued = $this->apply($this->seedDocument(), $templateId);
        $steps = $this->steps->listForRoute((int) $issued['route']['id'], self::TENANT);
        $edges = $this->edges->listForRoute((int) $issued['route']['id'], self::TENANT);

        self::assertCount(3, $steps);
        self::assertTrue($steps[0]['decision'], 'the gate must arrive as a gate');
        self::assertCount(1, $edges, 'the reject branch must be copied, not flattened away');
        self::assertSame((int) $steps[0]['id'], (int) $edges[0]['from_step_id']);
        self::assertSame((int) $steps[2]['id'], (int) $edges[0]['to_step_id']);
        self::assertSame(RouteVerdict::REJECTED, $edges[0]['verdict']);
    }

    public function testTheInstanceIsASnapshotAndRedrawingTheDesignDoesNotMoveIt(): void
    {
        // Stated in #1031 as the thing worth saying in the code: editing a design
        // must not change a circulation already under way. That is the OPPOSITE
        // of how a `group` stage behaves — a group is re-resolved every time —
        // and the difference is exactly the difference between a rule and a
        // constructor.
        $templateId = $this->template('Two stages', [
            $this->stage(1, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::HEAD_A]]),
            $this->stage(2, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::TECH_A]]),
        ], []);

        $issued = $this->apply($this->seedDocument(), $templateId);
        $routeId = (int) $issued['route']['id'];

        // The author redraws the design down to a single stage, naming somebody
        // else entirely.
        $this->graphSave($templateId, [
            $this->stage(1, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::REGISTRAR]]),
        ], []);

        $steps = $this->steps->listForRoute($routeId, self::TENANT);
        self::assertCount(2, $steps, 'a circulation under way must keep the stages it was issued with');
        self::assertSame(
            ['profile_ids' => [self::HEAD_A]],
            $steps[0]['rule_config'],
            'the instance is a COPY. Reading back through the template would show a running document '
            . 'following a design nobody circulated it under'
        );
    }

    // -- convergence: the merge label -----------------------------------------

    public function testAMergeWhoseArrivalsReachTheSamePersonSettlesExactlyOnce(): void
    {
        // THE CANVAS LABEL, IN THE CASE IT DESCRIBES.
        //
        // The design is the one #1027's docblock names as ordinary: "approve →
        // archive" and "reject → fix → archive", both ending at one archive
        // stage. Stage 4 has TWO arriving transitions (stage 2's drawn approve
        // edge, and stage 3's positional fallthrough), so the editor draws
        // "Paths merge — 1 item per person" on it.
        //
        // This is the case the old wording — "settles once" — got right, and it
        // is only half of them: see the actor-relative sibling below, which is
        // why the label now states the de-duplication rule instead (#1058).
        //
        // Two chains genuinely reach it, by different routes and at different
        // times, and this test asserts that the registrar sees ONE item and
        // answers it ONCE.
        $templateId = $this->convergingTemplate(
            RoutingRuleRegistry::KIND_EXPLICIT,
            ['profile_ids' => [self::REGISTRAR]]
        );

        $documentId = $this->seedDocument();
        $route = $this->apply($documentId, $templateId)['route'];

        // Stage 1 fans out: both heads hold the role, in different departments.
        self::assertSame([self::HEAD_A, self::HEAD_B], $this->profilesAtStep($documentId, $route, 1));

        // Each head forwards, and stage 2 resolves relative to THEM — so the two
        // chains are genuinely separate from here on.
        $this->act(self::HEAD_A, $route, RouteAction::FORWARDED);
        $this->act(self::HEAD_B, $route, RouteAction::FORWARDED);
        self::assertSame([self::TECH_A, self::TECH_B], $this->profilesAtStep($documentId, $route, 2));

        // Chain A approves and takes the drawn edge straight to the merge.
        $this->act(self::TECH_A, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::APPROVED);
        // Chain B rejects, goes to the rework stage, and its head forwards it on
        // — arriving at the same merge by the other path.
        $this->act(self::TECH_B, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::REJECTED);
        self::assertSame([self::HEAD_B], $this->profilesAtStep($documentId, $route, 3));
        $second = $this->act(self::HEAD_B, $route, RouteAction::FORWARDED);

        self::assertSame(1, $second['resolved'], 'the second chain DID arrive — the rule answered');
        self::assertSame(
            0,
            $second['delivered'],
            'and it opened no row, because the registrar already held one. That de-duplication IS '
            . 'the "1 item per person" the canvas promises: the second arrival gets no item and no '
            . 'cohort of its own, so this stage settles once'
        );
        self::assertCount(
            1,
            $this->rowsAtStep($documentId, $route, 4),
            'one inbox item at the merge, not one per arriving chain'
        );

        $this->act(self::REGISTRAR, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::APPROVED);

        self::assertCount(
            1,
            $this->decisionsAtStep($documentId, $route, 4),
            'the merge stage settled exactly once — which is what "1 item per person" produces when '
            . 'both arrivals resolve to the SAME people'
        );
    }

    public function testAMergeWhoseRuleIsActorRelativeSettlesOncePerArrivingChain(): void
    {
        // THE SAME LABEL, IN A CASE IT DOES NOT DESCRIBE — and the finding this
        // file exists to produce.
        //
        // The graph is IDENTICAL to the test above: same stages, same edges, same
        // two arrivals at stage 4, so the editor draws exactly the same note on
        // it. The only difference is stage 4's RULE, which is actor-relative
        // (`role_below_actor`) rather than naming one person.
        //
        // De-duplication is per PERSON, and the cohort a quorum is counted over
        // is `created_by_event_id` — the rows ONE act opened. When the two
        // arrivals resolve to different people there is nothing to de-duplicate,
        // two cohorts exist at one stage, and each settles on its own and opens
        // its own continuation.
        //
        // So the canvas note is a compression of `RouteFlowResolution.merges`'s
        // own docblock, which is precise and correct ("the stage settles ONCE per
        // COHORT, not once per chain"), into four words that are only true when a
        // chain and a cohort are the same thing. They are not, for any
        // actor-relative rule — which is the rule kind a merge is most likely to
        // carry, because that is what put the two chains there in the first place.
        $templateId = $this->convergingTemplate(
            RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
            ['role_id' => self::ROLE_HEAD]
        );

        $documentId = $this->seedDocument();
        $route = $this->apply($documentId, $templateId)['route'];

        $this->act(self::HEAD_A, $route, RouteAction::FORWARDED);
        $this->act(self::HEAD_B, $route, RouteAction::FORWARDED);

        // Chain A: approve, straight to the merge. Stage 4 resolves below TECH_A,
        // who is in Dept A — so it reaches head A.
        $this->act(self::TECH_A, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::APPROVED);
        self::assertSame([self::HEAD_A], $this->profilesAtStep($documentId, $route, 4));

        // Chain B: reject, rework, then on to the merge. Stage 4 resolves below
        // head B, who is in Dept B — so it reaches head B, a DIFFERENT person.
        $this->act(self::TECH_B, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::REJECTED);
        $second = $this->act(self::HEAD_B, $route, RouteAction::FORWARDED);

        self::assertSame(
            1,
            $second['delivered'],
            'the second arrival opened a row of its own — there was no existing open item for this '
            . 'person to de-duplicate against'
        );
        self::assertSame(
            [self::HEAD_A, self::HEAD_B],
            $this->profilesAtStep($documentId, $route, 4),
            'two inbox items at one stage: "1 item per person", and the actor-relative rule '
            . 'resolved the two arrivals to two different people'
        );

        $this->act(self::HEAD_A, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::APPROVED);
        $this->act(self::HEAD_B, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::APPROVED);

        self::assertCount(
            2,
            $this->decisionsAtStep($documentId, $route, 4),
            'the merge stage settled TWICE. This is not a bug this change introduces and not one it '
            . 'fixes: it is pre-existing fan-out behaviour, now reachable from a canvas that says '
            . 'otherwise in four words. Filed against the LABEL, not the engine'
        );
    }

    // -- the loop: the "can come back round" label ----------------------------

    public function testARejectEdgePointingBackwardsSendsTheDocumentRoundAgain(): void
    {
        // "Can come back round — loops". A reject edge from stage 2 to stage 1 is
        // the rework design, and it is a cycle. Nothing had ever run one.
        $templateId = $this->reworkTemplate();
        $documentId = $this->seedDocument();
        $route = $this->apply($documentId, $templateId)['route'];

        // Lap one: the author's stage, then the gate.
        self::assertSame([self::HEAD_A], $this->profilesAtStep($documentId, $route, 1));
        $this->act(self::HEAD_A, $route, RouteAction::FORWARDED);
        self::assertSame([self::REGISTRAR], $this->profilesAtStep($documentId, $route, 2));

        // Rejected: back round to stage 1.
        $rejected = $this->act(self::REGISTRAR, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::REJECTED);
        self::assertSame(RouteVerdict::REJECTED, $rejected['decided']);
        self::assertSame(
            1,
            $rejected['delivered'],
            'a rejection with a BACKWARDS edge opens its target. A forward-only assumption anywhere on '
            . 'this path would end the chain here instead, and the trail would look identical'
        );

        // #1037: THE LAP IS NOW COUNTABLE. Before this, a document on its ninth
        // rejection looked exactly like one on its first in every surface — one
        // open item, a long trail nobody reads to the end, and no number saying
        // it had been round. The count is derived from the trail's verdict rows
        // rather than stored, so it cannot disagree with the trail it summarises.
        $steps = $this->steps->listForRoute((int) $route['id'], self::TENANT);
        $gateId = (int) $steps[1]['id'];
        self::assertSame(
            [$gateId => 1],
            $this->events->rejectionCountsByStep((int) $route['id'], self::TENANT),
            'one lap so far, attributed to the GATE that rejected — not to the stage the document '
            . 'was sent back to, which never rejected anything'
        );

        // Lap two: the same person holds stage 1 again, in a NEW row.
        self::assertCount(
            2,
            $this->rowsAtStep($documentId, $route, 1),
            'the second lap is a second row, not a re-opening of the first. Un-closing the original '
            . 'would erase the fact that they acted on lap one'
        );
        self::assertSame(
            [self::HEAD_A],
            $this->openProfilesAtStep($documentId, $route, 1),
            'exactly one OPEN item on lap two'
        );

        $this->act(self::HEAD_A, $route, RouteAction::FORWARDED);
        $this->act(self::REGISTRAR, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::APPROVED);

        // An APPROVAL moves the document on, so it is not a lap. The count still
        // reads one — which is what makes the number mean "times sent back"
        // rather than "times acted on".
        self::assertSame(
            [$gateId => 1],
            $this->events->rejectionCountsByStep((int) $route['id'], self::TENANT),
            'the approving act must not increment the rejection count'
        );

        // Approved on the second lap: stage 2 is the last, so the chain ends.
        $actions = array_map(
            static fn (array $e): string => (string) $e['action'],
            $this->events->listForDocument($documentId, self::TENANT, 50, 0)
        );
        self::assertSame(
            [
                RouteAction::ISSUED,
                RouteAction::FORWARDED,
                RouteAction::ACKNOWLEDGED,
                RouteAction::FORWARDED,
                RouteAction::ACKNOWLEDGED,
            ],
            $actions,
            'the trail records both laps in order, which is the only place a lap count is visible at '
            . 'all (#1037)'
        );
    }

    public function testTheSecondLapReachesWhoeverHoldsTheRoleNowRatherThanWhoHeldItThen(): void
    {
        // THE REASON A STAGE NAMES A RULE. The loop above ran twice against a
        // stable organisation, which proves the edge is followed but not that the
        // rule is RE-RESOLVED. Here the department head changes between laps, and
        // the document must come back to the NEW head — not to the person the
        // rule happened to answer with when the route was issued.
        //
        // A design authored in March and applied in November has the same problem
        // over a longer interval, and it is the whole argument for rules over
        // stored lists.
        $templateId = $this->reworkTemplate(RoutingRuleRegistry::KIND_ROLE, ['role_id' => self::ROLE_HEAD]);
        $documentId = $this->seedDocument();
        $route = $this->apply($documentId, $templateId)['route'];

        // Lap one reaches both heads; head A forwards it on.
        self::assertSame([self::HEAD_A, self::HEAD_B], $this->profilesAtStep($documentId, $route, 1));
        $this->act(self::HEAD_A, $route, RouteAction::FORWARDED);

        // The organisation changes: head A leaves, head C takes the role.
        $this->pdo->exec("UPDATE memberships SET status = 'inactive' WHERE profile_id = " . self::HEAD_A);
        $this->seedMembership(1013, self::HEAD_C, self::ROLE_HEAD, self::OU_DEPT_A);

        $this->act(self::REGISTRAR, $route, RouteAction::ACKNOWLEDGED, RouteVerdict::REJECTED);

        self::assertSame(
            [self::HEAD_B, self::HEAD_C],
            $this->openProfilesAtStep($documentId, $route, 1),
            'the reject edge re-resolved the stage against the organisation AS IT STANDS. A route that '
            . 'had frozen a roster at issue time would send the rework back to somebody who has left, '
            . 'and would report success doing it'
        );
    }

    // -- a stage names a TYPE, and instantiation must not change that ---------

    public function testAGroupStageSurvivesInstantiationAsAGroupAndIsResolvedLate(): void
    {
        // A `group` stage is ONE row naming a stored definition. The failure this
        // guards against is instantiation "helpfully" expanding it into the people
        // it resolves to today — which would be a roster, would be wrong the first
        // time the organisation changed, and would still report success.
        //
        // Asserted twice over: the copied step still names the group, AND the
        // people it reaches are decided by a membership change made AFTER the
        // template was applied.
        $groups = new UserGroupRepository($this->pdo);
        $groupId = $groups->create(
            self::TENANT,
            'Department heads',
            null,
            RoutingRuleRegistry::KIND_ROLE,
            ['role_id' => self::ROLE_HEAD],
            self::DEAN
        );

        $templateId = $this->template('By group', [
            $this->stage(1, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::DEAN]]),
            $this->stage(2, RoutingRuleRegistry::KIND_GROUP, ['group_id' => $groupId]),
        ], []);

        $documentId = $this->seedDocument();
        $route = $this->apply($documentId, $templateId)['route'];

        $steps = $this->steps->listForRoute((int) $route['id'], self::TENANT);
        self::assertSame(RoutingRuleRegistry::KIND_GROUP, $steps[1]['rule_kind']);
        self::assertSame(
            ['group_id' => $groupId],
            $steps[1]['rule_config'],
            'the stage must still NAME the group. A materialised roster here would be a stored list of '
            . 'people, which is the thing the whole subsystem is written against'
        );

        // Somebody joins the department AFTER the design was applied.
        $this->seedMembership(1013, self::HEAD_C, self::ROLE_HEAD, self::OU_DEPT_A);

        $this->act(self::DEAN, $route, RouteAction::FORWARDED);

        self::assertSame(
            [self::HEAD_A, self::HEAD_B, self::HEAD_C],
            $this->profilesAtStep($documentId, $route, 2),
            'the newcomer is reached, because the group was resolved when the stage was, not when the '
            . 'template was applied'
        );
    }

    // -- provenance -----------------------------------------------------------

    public function testTheRouteRecordsWhichDesignItCameFromAndKeepsTheNameAfterADelete(): void
    {
        $templateId = $this->template('Purchase approval', [
            $this->stage(1, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::HEAD_A]]),
        ], []);

        $issued = $this->apply($this->seedDocument(), $templateId);
        $routes = new RouteRepository($this->pdo);
        $routeId = (int) $issued['route']['id'];

        $stored = $routes->findById($routeId, self::TENANT);
        self::assertNotNull($stored);
        self::assertSame($templateId, $stored['template_id']);
        self::assertSame('Purchase approval', $stored['template_name']);

        // FOREIGN KEYS ARE OFF BY DEFAULT ON SQLITE, which is the trap
        // CONTRIBUTING.md lists by name: the SET NULL below is a database
        // guarantee and it simply does not fire unless the pragma is on. Turned
        // on here rather than skipping the assertion, so this test measures the
        // same behaviour on both engines instead of measuring nothing on one and
        // reporting green. It is scoped to this test's own connection.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        $this->templates->delete($templateId, self::TENANT);

        $afterDelete = $routes->findById($routeId, self::TENANT);
        self::assertNotNull($afterDelete);
        self::assertNull($afterDelete['template_id'], 'the pointer goes with the design');
        self::assertSame(
            'Purchase approval',
            $afterDelete['template_name'],
            'and the snapshot does not. A trail that lost the name when somebody tidied up would be a '
            . 'trail that quietly stopped saying what the document followed'
        );
        self::assertCount(
            1,
            $this->steps->listForRoute($routeId, self::TENANT),
            'deleting a design cannot take a circulation with it'
        );
    }

    public function testAHandComposedRouteCarriesNoProvenanceRatherThanAnInventedName(): void
    {
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $this->seedDocument()], 'Ad hoc', [
            ['rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT, 'rule_config' => ['profile_ids' => [self::HEAD_A]]],
        ]);

        self::assertNull($issued['route']['template_id']);
        self::assertNull($issued['route']['template_name']);
    }

    // -- ceilings -------------------------------------------------------------

    public function testATemplateOverTheTenantsStepCeilingIsRefusedAtTheMomentItIsApplied(): void
    {
        // #1031 asks for this explicitly: the ceiling is checked when the design
        // is AUTHORED too, but the setting can move in between. The template
        // below saved legally and is refused now, because the tenant has since
        // narrowed the limit.
        $templateId = $this->template('Three stages', [
            $this->stage(1, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::HEAD_A]]),
            $this->stage(2, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::HEAD_B]]),
            $this->stage(3, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::TECH_A]]),
        ], []);

        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_ROUTING_MAX_STEPS, '2');

        $this->expectException(RoutingRejectedException::class);
        $this->expectExceptionMessageMatches('/limit of 2/');

        $this->apply($this->seedDocument(), $templateId);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * The one design both convergence tests use, parameterised only by the rule
     * its MERGE stage names.
     *
     *   1  role: head           circulation, fans out to both departments
     *   2  role_below_actor:    GATE. approved -> 4, rejected -> 3
     *      tech
     *   3  role_below_actor:    the rework stage; falls through to 4
     *      head
     *   4  <parameter>          GATE. the merge: two transitions arrive here
     *
     * Stage 4 has two arriving transitions — stage 2's drawn approve edge and
     * stage 3's positional fallthrough — which is exactly what
     * `resolveTransitions()` counts, so the editor marks it as a merge in both
     * tests.
     *
     * @param array<string, mixed> $mergeConfig
     */
    private function convergingTemplate(string $mergeKind, array $mergeConfig): int
    {
        return $this->template('Converging', [
            $this->stage(1, RoutingRuleRegistry::KIND_ROLE, ['role_id' => self::ROLE_HEAD]),
            $this->stage(2, RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR, ['role_id' => self::ROLE_TECH], decision: true),
            $this->stage(3, RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR, ['role_id' => self::ROLE_HEAD]),
            $this->stage(4, $mergeKind, $mergeConfig, decision: true),
        ], [
            ['from' => 2, 'to' => 4, 'verdict' => RouteVerdict::APPROVED],
            ['from' => 2, 'to' => 3, 'verdict' => RouteVerdict::REJECTED],
        ]);
    }

    /**
     * The rework loop: a gate at stage 2 whose rejection goes BACK to stage 1.
     *
     * @param array<string, mixed>|null $firstConfig
     */
    private function reworkTemplate(?string $firstKind = null, ?array $firstConfig = null): int
    {
        return $this->template('Rework loop', [
            $this->stage(
                1,
                $firstKind ?? RoutingRuleRegistry::KIND_EXPLICIT,
                $firstConfig ?? ['profile_ids' => [self::HEAD_A]]
            ),
            $this->stage(2, RoutingRuleRegistry::KIND_EXPLICIT, ['profile_ids' => [self::REGISTRAR]], decision: true),
        ], [
            ['from' => 2, 'to' => 1, 'verdict' => RouteVerdict::REJECTED],
        ]);
    }

    /**
     * Create a template and save its graph THROUGH the real validator, so a
     * design a test invents could not be one the editor is unable to save.
     *
     * @param list<array<string, mixed>>                       $stages
     * @param list<array{from: int, to: int, verdict: string}> $edges
     */
    private function template(string $name, array $stages, array $edges): int
    {
        $id = $this->templates->create(self::TENANT, $name, null, self::DEAN);
        $this->graphSave($id, $stages, $edges);

        return $id;
    }

    /**
     * @param list<array<string, mixed>>                       $stages
     * @param list<array{from: int, to: int, verdict: string}> $edges
     */
    private function graphSave(int $templateId, array $stages, array $edges): void
    {
        $validated = $this->graph->validate($stages, $edges, 50);
        $this->templates->replaceGraph($templateId, self::TENANT, $validated['steps'], $validated['edges']);
    }

    /**
     * One stage in the wire shape `PUT /graph` accepts.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function stage(
        int $position,
        string $kind,
        array $config,
        bool $decision = false,
        ?string $quorum = null,
    ): array {
        return [
            'position' => $position,
            'rule_kind' => $kind,
            'rule_config' => $config,
            'label' => null,
            'decision' => $decision,
            'decision_quorum' => $quorum,
            'canvas_x' => 0,
            'canvas_y' => 0,
        ];
    }

    /**
     * Apply a design to a document, exactly as
     * {@see \Whity\Api\DocumentRoutingApiHandler::createFromTemplate()} does.
     *
     * @return array{route: array<string, mixed>, steps: list<array<string, mixed>>,
     *               edges: list<array<string, mixed>>, resolved: int, delivered: int}
     */
    private function apply(int $documentId, int $templateId): array
    {
        $template = $this->templates->findById($templateId, self::TENANT);
        self::assertNotNull($template);

        return $this->router->issue(
            self::TENANT,
            self::DEAN,
            ['id' => $documentId],
            (string) $template['name'],
            RouteTemplateInstantiation::toRouteSteps(
                $this->templates->stepsFor($templateId, self::TENANT),
                $this->templates->edgesFor($templateId, self::TENANT),
            ),
            $templateId,
            (string) $template['name'],
        );
    }

    /**
     * @param array<string, mixed> $route
     * @return array{event: array<string, mixed>, resolved: int, delivered: int, decided: ?string}
     */
    private function act(int $actorId, array $route, string $action, ?string $verdict = null): array
    {
        return $this->router->act(self::TENANT, $actorId, $route, $action, null, $verdict);
    }

    /**
     * Every recipient row ever written at one stage, open or closed.
     *
     * @param array<string, mixed> $route
     * @return list<array<string, mixed>>
     */
    private function rowsAtStep(int $documentId, array $route, int $position): array
    {
        $steps = $this->steps->listForRoute((int) $route['id'], self::TENANT);
        $stepId = (int) $steps[$position - 1]['id'];

        return array_values(array_filter(
            $this->recipients->listForDocument($documentId, self::TENANT),
            static fn (array $r): bool => $r['step_id'] === $stepId
        ));
    }

    /**
     * @param array<string, mixed> $route
     * @return list<int>
     */
    private function profilesAtStep(int $documentId, array $route, int $position): array
    {
        return $this->sortedProfiles($this->rowsAtStep($documentId, $route, $position));
    }

    /**
     * @param array<string, mixed> $route
     * @return list<int>
     */
    private function openProfilesAtStep(int $documentId, array $route, int $position): array
    {
        return $this->sortedProfiles(array_values(array_filter(
            $this->rowsAtStep($documentId, $route, $position),
            static fn (array $r): bool => $r['closed_by_event_id'] === null
        )));
    }

    /**
     * The acts that SETTLED a stage — the trail's own record of how many times a
     * verdict was concluded there, read back from `document_route_events` rather
     * than from anything the engine returned about itself.
     *
     * @param array<string, mixed> $route
     * @return list<array<string, mixed>>
     */
    private function decisionsAtStep(int $documentId, array $route, int $position): array
    {
        $steps = $this->steps->listForRoute((int) $route['id'], self::TENANT);
        $stepId = (int) $steps[$position - 1]['id'];

        return array_values(array_filter(
            $this->events->listForDocument($documentId, self::TENANT, 100, 0),
            static fn (array $e): bool => (int) $e['step_id'] === $stepId && $e['verdict'] !== null
        ));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function sortedProfiles(array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['profile_id'], $rows);
        sort($ids);

        return array_values($ids);
    }

    private function seedDocument(): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id,
                                    created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Circular') . ', ' . $this->pdo->quote('Budget request')
             . ', 20, 10, ' . $this->now() . ')'
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function seedMembership(int $id, int $profileId, int $roleId, ?int $ouId): void
    {
        $this->pdo->exec(
            'INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES (' . $id . ', ' . $profileId . ', ' . self::TENANT . ', ' . $roleId . ', '
            . ($ouId === null ? 'NULL' : (string) $ouId) . ", true, 'active', " . $this->now() . ')'
        );
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Tenant One') . ') ON CONFLICT DO NOTHING');

        // Two departments under one faculty. The split is load-bearing: it is
        // what makes `role_below_actor` answer differently for the two heads,
        // which is what puts two genuinely separate chains on one document.
        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (' . self::OU_FACULTY . ', 1, NULL, ' . $quote('Faculty') . ', ' . $quote('faculty') . ', ' . $now . '),
                (' . self::OU_DEPT_A . ', 1, ' . self::OU_FACULTY . ', ' . $quote('Dept A') . ', '
                . $quote('dept-a') . ', ' . $now . '),
                (' . self::OU_DEPT_B . ', 1, ' . self::OU_FACULTY . ', ' . $quote('Dept B') . ', '
                . $quote('dept-b') . ', ' . $now . ')'
        );

        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (' . self::ROLE_DEAN . ', ' . $quote('dean') . ', ' . $quote('') . ', 1, ' . $now . '),
                (' . self::ROLE_HEAD . ', ' . $quote('head') . ', ' . $quote('') . ', 1, ' . $now . '),
                (' . self::ROLE_TECH . ', ' . $quote('technician') . ', ' . $quote('') . ', 1, ' . $now . '),
                (' . self::ROLE_REGISTRAR . ', ' . $quote('registrar') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );

        $people = [
            [self::DEAN, 'dean'],
            [self::HEAD_A, 'head-a'],
            [self::HEAD_B, 'head-b'],
            [self::HEAD_C, 'head-c'],
            [self::TECH_A, 'tech-a'],
            [self::TECH_B, 'tech-b'],
            [self::REGISTRAR, 'registrar'],
        ];
        foreach ($people as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, '
                . $now . ', ' . $now . ')'
            );
        }

        // Head C holds NOTHING to begin with — two tests give them the role
        // partway through, which is how "the rule is re-resolved on arrival"
        // becomes an observable fact rather than a claim.
        $pdo->exec(
            "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES
                (1010, " . self::DEAN . ", 1, " . self::ROLE_DEAN . ', ' . self::OU_FACULTY . ", true, 'active', {$now}),
                (1011, " . self::HEAD_A . ", 1, " . self::ROLE_HEAD . ', ' . self::OU_DEPT_A . ", true, 'active', {$now}),
                (1012, " . self::HEAD_B . ", 1, " . self::ROLE_HEAD . ', ' . self::OU_DEPT_B . ", true, 'active', {$now}),
                (1014, " . self::TECH_A . ", 1, " . self::ROLE_TECH . ', ' . self::OU_DEPT_A . ", true, 'active', {$now}),
                (1015, " . self::TECH_B . ", 1, " . self::ROLE_TECH . ', ' . self::OU_DEPT_B . ", true, 'active', {$now}),
                (1016, " . self::REGISTRAR . ', 1, ' . self::ROLE_REGISTRAR . ', ' . self::OU_FACULTY
                . ", true, 'active', {$now})"
        );

        return $pdo;
    }
}
