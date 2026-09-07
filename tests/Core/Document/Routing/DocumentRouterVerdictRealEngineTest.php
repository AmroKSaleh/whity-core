<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
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
 * Real-engine tests for #1014 — APPROVAL AS A DISTINCT ACT.
 *
 * Each assertion is written against the behaviour that would be WRONG, because
 * every failure this feature can have looks like success from the outside: a
 * rejected document that carries on down the route renders identically to an
 * approved one, and a step approved by one instructor out of a thousand is
 * indistinguishable from a step that was properly authorised.
 *
 *  1. REJECTION ROUTES DIFFERENTLY. The load-bearing one. Asserted by the
 *     ABSENCE of rows at the step an approval would have opened — not by the
 *     event's own `verdict` column, which a broken engine would still write
 *     correctly while sending the document onward regardless.
 *
 *  2. THE QUORUM IS EXPLICIT AND DEFAULTS TO `all`. Asserted with NO setting
 *     written anywhere, so the test exercises the registry default a fresh
 *     deployment actually gets rather than one the fixture chose.
 *
 *  3. THE COHORT IS THE ROWS, NOT THE RULE. A person who acquires the role after
 *     the step was reached does not raise the bar; a person who leaves stops
 *     holding it up. Both are asserted by OUTCOME (did the step resolve) rather
 *     than by reading a count back out of the engine.
 *
 *  4. THE GATE REFUSES WHAT WOULD BYPASS IT. `forwarded` on a decision step, a
 *     verdict on a circulation step, and a decision answered without one.
 *
 * ENGINE. {@see SchemaFromMigrations::make()} returns real PostgreSQL when
 * PHPUNIT_PG_DSN is set and SQLite otherwise. Both matter here: the verdict and
 * quorum CHECK constraints are only enforced by a real engine, while the SQLite
 * path is what CI's unit job runs.
 */
final class DocumentRouterVerdictRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** The raiser: a dean, in the Faculty unit (20). */
    private const DEAN = 10;
    /** Three approvers holding the same role, so a node means a TYPE and not a person. */
    private const HEAD_A = 11;
    private const HEAD_B = 12;
    private const HEAD_C = 13;
    /** A technician, reachable from a head's own subtree — the step an approval continues to. */
    private const TECH_A = 14;

    private const ROLE_DEAN = 100;
    private const ROLE_HEAD = 101;
    private const ROLE_TECH = 102;

    private PDO $pdo;
    private DocumentRouter $router;
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
        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $rules = new RoutingRuleRegistry();
        $registry = $rules;
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

    // -- 1. rejection routes differently ------------------------------------

    public function testARejectionDoesNotOpenTheStepAnApprovalWouldHave(): void
    {
        // THE TEST #1014 EXISTS FOR. Two identical routes on the same document,
        // decided differently at step 1, and the assertion is about step 2 —
        // where the document ENDED UP, not what the trail says was decided. An
        // engine that records the verdict faithfully and then routes both the
        // same way passes every check on the event row and fails this one.
        $documentId = $this->seedDocument();

        $approvedRoute = $this->issueGate($documentId, 'Approved run');
        $rejectedRoute = $this->issueGate($documentId, 'Rejected run');

        // Sole approver on each, so `all` and `any` cannot differ and the quorum
        // is not what is under test here.
        $this->router->act(
            self::TENANT,
            self::HEAD_A,
            $approvedRoute,
            RouteAction::ACKNOWLEDGED,
            null,
            RouteVerdict::APPROVED,
        );
        $this->router->act(
            self::TENANT,
            self::HEAD_A,
            $rejectedRoute,
            RouteAction::ACKNOWLEDGED,
            null,
            RouteVerdict::REJECTED,
        );

        self::assertNotSame(
            [],
            $this->rowsAtStep($documentId, $approvedRoute, 2),
            'an approval must continue to the next step — otherwise this test proves nothing about the '
            . 'rejection below, because neither route moved'
        );

        self::assertSame(
            [],
            $this->rowsAtStep($documentId, $rejectedRoute, 2),
            'a rejection must NOT reach the step an approval reaches. A rejection that merely records '
            . 'dissent and lets the document proceed is not approval, and it fails invisibly: the trail '
            . 'says rejected while the document travels exactly as an approved one does'
        );
    }

    public function testARejectEdgeSendsTheDocumentSomewhereAnApprovalNeverGoes(): void
    {
        $documentId = $this->seedDocument();

        // Step 1: the dean's own gate. Rejected goes BACK to step 3, a step the
        // approval path never touches; approved continues to step 2.
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Branching', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HEAD_A]],
                'decision' => true,
                'on_rejected' => 3,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::TECH_A]],
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HEAD_C]],
            ],
        ]);
        $route = $issued['route'];

        $outcome = $this->router->act(
            self::TENANT,
            self::HEAD_A,
            $route,
            RouteAction::ACKNOWLEDGED,
            'Figures do not add up.',
            RouteVerdict::REJECTED,
        );

        self::assertSame(RouteVerdict::REJECTED, $outcome['decided']);
        self::assertSame(
            [],
            $this->rowsAtStep($documentId, $route, 2),
            'the approval path must stay untouched by a rejection'
        );
        self::assertSame(
            [self::HEAD_C],
            $this->profilesAtStep($documentId, $route, 3),
            'the reject edge must open its own target — this is what "goes somewhere else" means'
        );

        // And the edge is readable back as ids, which is what a node editor needs.
        $edges = $this->edges->listForRoute((int) $route['id'], self::TENANT);
        self::assertCount(1, $edges);
        self::assertSame(RouteVerdict::REJECTED, $edges[0]['verdict']);
    }

    public function testTheVerdictIsRecordedOnTheActAndNowhereElse(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueGate($documentId, 'Recorded');

        $this->router->act(
            self::TENANT,
            self::HEAD_A,
            $route,
            RouteAction::ACKNOWLEDGED,
            null,
            RouteVerdict::REJECTED,
        );

        $trail = $this->events->listForDocument($documentId, self::TENANT, 50, 0);
        $verdicts = array_map(static fn (array $e): array => [$e['action'], $e['verdict']], $trail);

        self::assertSame(
            [
                [RouteAction::ISSUED, null],
                [RouteAction::ACKNOWLEDGED, RouteVerdict::REJECTED],
            ],
            $verdicts,
            'the verdict belongs to the ACT. An `issued` decides nothing, so its verdict is null rather '
            . 'than a default — null means "this act said nothing about approval", never "not approved"'
        );
    }

    // -- 2. the quorum, and its default -------------------------------------

    public function testWithNoSettingAnywhereThreeApproversMustAllApprove(): void
    {
        // NOTHING is written to app_settings or tenant_settings, deliberately:
        // this is the behaviour a fresh deployment gets, which is the number that
        // matters. If the default were `any`, the first approval below would open
        // step 2 and the document would have been authorised by one of three.
        $documentId = $this->seedDocument();
        $route = $this->issueRoleGate($documentId, 'Unanimous');

        self::assertSame(
            3,
            count($this->profilesAtStep($documentId, $route, 1)),
            'the fixture must put all three heads on the gate, or the quorum is not being exercised'
        );

        $first = $this->decide($route, self::HEAD_A, RouteVerdict::APPROVED);
        self::assertNull($first['decided'], 'one of three cannot settle a unanimous gate');
        self::assertSame([], $this->rowsAtStep($documentId, $route, 2));

        $second = $this->decide($route, self::HEAD_B, RouteVerdict::APPROVED);
        self::assertNull($second['decided'], 'two of three cannot settle a unanimous gate either');
        self::assertSame([], $this->rowsAtStep($documentId, $route, 2));

        $third = $this->decide($route, self::HEAD_C, RouteVerdict::APPROVED);
        self::assertSame(RouteVerdict::APPROVED, $third['decided']);
        self::assertSame(
            [self::TECH_A],
            $this->profilesAtStep($documentId, $route, 2),
            'the third approval completes the quorum and the route continues'
        );
    }

    public function testUnderTheDefaultQuorumOneRefusalStopsTheOtherTwoBeingAsked(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueRoleGate($documentId, 'One dissenter');

        $outcome = $this->decide($route, self::HEAD_B, RouteVerdict::REJECTED);

        self::assertSame(
            RouteVerdict::REJECTED,
            $outcome['decided'],
            'unanimity is already unreachable, so the step is settled — waiting for two answers that '
            . 'cannot change the result is a barrier with no purpose'
        );
        self::assertSame([], $this->rowsAtStep($documentId, $route, 2), 'and the document does not proceed');

        // The other two no longer hold the item...
        $open = array_values(array_filter(
            $this->recipients->listForDocument($documentId, self::TENANT),
            static fn (array $r): bool => $r['closed_by_event_id'] === null
        ));
        self::assertSame([], $open, 'a settled step must leave nothing open — an inbox that never empties');

        // ...and the trail does NOT claim they acted. Superseding an item is not
        // the same as answering it, and conflating the two would put words in
        // two people's mouths about a document they refused nothing.
        $actors = array_map(
            static fn (array $e): ?int => $e['actor_profile_id'],
            array_values(array_filter(
                $this->events->listForDocument($documentId, self::TENANT, 50, 0),
                static fn (array $e): bool => $e['verdict'] !== null
            ))
        );
        self::assertSame([self::HEAD_B], $actors);
    }

    public function testAnAnyQuorumIsSettledByTheFirstApprovalAndOpensTheNextStepExactlyOnce(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueRoleGate($documentId, 'First past the post', RouteQuorum::ANY);

        $first = $this->decide($route, self::HEAD_A, RouteVerdict::APPROVED);
        self::assertSame(RouteVerdict::APPROVED, $first['decided']);
        self::assertSame([self::TECH_A], $this->profilesAtStep($documentId, $route, 2));

        // The other two cannot then approve again. Without the settled step
        // closing their rows, a second approval would satisfy the quorum a second
        // time and open step 2 twice — the same person receiving the same
        // document twice from one decision.
        foreach ([self::HEAD_B, self::HEAD_C] as $late) {
            try {
                $this->decide($route, $late, RouteVerdict::APPROVED);
                self::fail('a settled step must not accept a second answer');
            } catch (RoutingRejectedException $e) {
                self::assertStringContainsString('no open item', $e->clientMessage);
            }
        }

        self::assertCount(
            1,
            $this->rowsAtStep($documentId, $route, 2),
            'the next step must be opened exactly once, however many people were on the gate'
        );
    }

    public function testAStepOverridesTheTenantSettingAndTheTenantOverridesTheGlobal(): void
    {
        // The four-layer chain, exercised as a chain rather than as three
        // separate lookups: the global says `any`, the tenant says `all`, and the
        // STEP says `any` again — so the step's answer must win over a tenant
        // setting that would otherwise make one approval decide nothing.
        (new GlobalSettingsRepository($this->pdo))
            ->set(SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM, RouteQuorum::ANY);
        (new TenantSettingsRepository($this->pdo))
            ->set(self::TENANT, SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM, RouteQuorum::ALL);

        $documentId = $this->seedDocument();

        $tenantRuled = $this->issueRoleGate($documentId, 'Tenant rules');
        self::assertNull(
            $this->decide($tenantRuled, self::HEAD_A, RouteVerdict::APPROVED)['decided'],
            'the tenant override (all) must beat the global (any)'
        );

        $stepRuled = $this->issueRoleGate($documentId, 'Step rules', RouteQuorum::ANY);
        self::assertSame(
            RouteVerdict::APPROVED,
            $this->decide($stepRuled, self::HEAD_A, RouteVerdict::APPROVED)['decided'],
            "the step's own quorum must beat the tenant setting"
        );
    }

    // -- 3. the cohort is the rows, not the rule ----------------------------

    public function testSomebodyWhoAcquiresTheRoleAfterTheStepWasReachedDoesNotRaiseTheBar(): void
    {
        // A user group / role rule resolves LIVE and stores no membership list
        // (#999), so the set it answers with can grow between the moment a step
        // was reached and the moment it is decided. Counting the RULE rather than
        // the ROWS would mean a hire on Tuesday silently makes Monday's
        // unanimous gate unreachable — nobody would ever find out why.
        $documentId = $this->seedDocument();
        $route = $this->issueRoleGate($documentId, 'Hiring during a decision');

        $this->seedProfile(15, 'head-d');
        $this->seedMembership(1015, 15, self::TENANT, self::ROLE_HEAD, 21);

        $this->decide($route, self::HEAD_A, RouteVerdict::APPROVED);
        $this->decide($route, self::HEAD_B, RouteVerdict::APPROVED);
        $last = $this->decide($route, self::HEAD_C, RouteVerdict::APPROVED);

        self::assertSame(
            RouteVerdict::APPROVED,
            $last['decided'],
            'the three people who were ASKED are the cohort. A fourth who was never sent the item, holds '
            . 'no open row and cannot answer must not be able to block it'
        );
    }

    public function testSomebodyWhoLeavesStopsHoldingTheDecisionUp(): void
    {
        // The other direction, and the one that has an operational cost if it is
        // wrong: under `all`, a single suspended account is a route stuck for
        // ever, with no remedy an operator could apply short of editing rows.
        $documentId = $this->seedDocument();
        $route = $this->issueRoleGate($documentId, 'One on leave');

        $this->pdo->exec("UPDATE memberships SET status = 'suspended' WHERE profile_id = " . self::HEAD_C);

        $this->decide($route, self::HEAD_A, RouteVerdict::APPROVED);
        $second = $this->decide($route, self::HEAD_B, RouteVerdict::APPROVED);

        self::assertSame(
            RouteVerdict::APPROVED,
            $second['decided'],
            'somebody who is no longer an active member cannot answer, so they cannot be counted as '
            . 'able to — the bar falls rather than the route stalling for ever'
        );

        // And their departure is not silently counted AS an approval: only two
        // verdicts exist in the trail.
        $given = array_values(array_filter(
            $this->events->listForDocument($documentId, self::TENANT, 50, 0),
            static fn (array $e): bool => $e['verdict'] === RouteVerdict::APPROVED
        ));
        self::assertCount(2, $given, 'the trail must record exactly the approvals that were actually given');
    }

    public function testReturningAnItemLeavesTheCohortRatherThanCountingAgainstIt(): void
    {
        // `returned` is the escape from a gate, and it already has a destination
        // of its own. Counting it as a refusal would make one person's "not mine
        // to decide" a permanent veto under `all`, and would open a second
        // destination for a single act.
        $documentId = $this->seedDocument();
        $route = $this->issueRoleGateAfterAForward($documentId);

        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::RETURNED, 'Not mine.');

        $remaining = $this->decide($route, self::HEAD_B, RouteVerdict::APPROVED);
        $last = $this->decide($route, self::HEAD_C, RouteVerdict::APPROVED);

        self::assertNull($remaining['decided'], 'two of the remaining pair have not both answered yet');
        self::assertSame(
            RouteVerdict::APPROVED,
            $last['decided'],
            'the returner left the cohort, so the two who stayed and both approved settle it'
        );
    }

    // -- 4. the gate refuses what would bypass it ---------------------------

    public function testForwardingIsRefusedOnADecisionStep(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueGate($documentId, 'No bypass');

        $this->expectException(RoutingRejectedException::class);

        try {
            $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('decision step', $e->clientMessage);
            // Nothing moved: a refusal that had already written the trail would
            // be worse than the bypass it prevented.
            self::assertSame([], $this->rowsAtStep($documentId, $route, 2));
            throw $e;
        }
    }

    public function testADecisionStepCannotBeClosedWithoutAVerdict(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueGate($documentId, 'Answer it');

        $this->expectException(RoutingRejectedException::class);
        $this->expectExceptionMessageMatches('/./');

        try {
            $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::ACKNOWLEDGED, null);
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('verdict', $e->clientMessage);
            throw $e;
        }
    }

    public function testAVerdictIsRefusedOnACirculationStep(): void
    {
        // Refused rather than ignored. A stored verdict nothing routes on would
        // read later as an authorisation nobody asked for, on a trail that cannot
        // be corrected.
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Plain circulation', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HEAD_A]],
            ],
        ]);

        $this->expectException(RoutingRejectedException::class);

        try {
            $this->router->act(
                self::TENANT,
                self::HEAD_A,
                $issued['route'],
                RouteAction::ACKNOWLEDGED,
                null,
                RouteVerdict::APPROVED,
            );
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('circulation step', $e->clientMessage);
            self::assertSame(
                [],
                array_values(array_filter(
                    $this->events->listForDocument($documentId, self::TENANT, 50, 0),
                    static fn (array $ev): bool => $ev['verdict'] !== null
                )),
                'the refused act must have written nothing'
            );
            throw $e;
        }
    }

    public function testAnEdgeOnAStepThatProducesNoVerdictIsRefusedAtAuthoringTime(): void
    {
        $documentId = $this->seedDocument();

        $this->expectException(RoutingRejectedException::class);

        try {
            $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Dead edge', [
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                    'rule_config' => ['profile_ids' => [self::HEAD_A]],
                    // No `decision`, so nothing on this route can ever produce a
                    // verdict for the edge to follow.
                    'on_rejected' => 2,
                ],
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                    'rule_config' => ['profile_ids' => [self::HEAD_B]],
                ],
            ]);
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('decision step', $e->clientMessage);
            self::assertSame(
                [],
                $this->events->listForDocument($documentId, self::TENANT, 50, 0),
                'a refused route must write nothing at all, not even a partial one'
            );
            throw $e;
        }
    }

    public function testAnEdgePointingAtItselfIsRefused(): void
    {
        $documentId = $this->seedDocument();

        $this->expectException(RoutingRejectedException::class);
        $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Self loop', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HEAD_A]],
                'decision' => true,
                'on_rejected' => 1,
            ],
        ]);
    }

    public function testAQuorumOnANonDecisionStepIsRefused(): void
    {
        $documentId = $this->seedDocument();

        $this->expectException(RoutingRejectedException::class);
        $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Pointless quorum', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HEAD_A]],
                'decision_quorum' => RouteQuorum::MAJORITY,
            ],
        ]);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * A two-step route whose FIRST step is a gate held by one person, with no
     * edges — so approval falls through to the ordinal successor and rejection
     * has nowhere to go.
     *
     * @return array<string, mixed> The route row.
     */
    private function issueGate(int $documentId, string $title): array
    {
        return $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], $title, [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::HEAD_A]],
                'decision' => true,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::TECH_A]],
            ],
        ])['route'];
    }

    /**
     * The same shape, but the gate names a ROLE three people hold — the "you can
     * say instructors in one node" case the quorum exists for.
     *
     * @return array<string, mixed>
     */
    private function issueRoleGate(int $documentId, string $title, ?string $quorum = null): array
    {
        return $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], $title, [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                'rule_config' => ['role_id' => self::ROLE_HEAD],
                'decision' => true,
                'decision_quorum' => $quorum,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::TECH_A]],
            ],
        ])['route'];
    }

    /**
     * A route whose gate is reached by a FORWARD rather than by the issue, so
     * the cohort is a fan-out from one act and `returned` has a predecessor to go
     * back to.
     *
     * @return array<string, mixed>
     */
    private function issueRoleGateAfterAForward(int $documentId): array
    {
        $route = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Forward then decide', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::DEAN]],
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                'rule_config' => ['role_id' => self::ROLE_HEAD],
                'decision' => true,
            ],
        ])['route'];

        $this->router->act(self::TENANT, self::DEAN, $route, RouteAction::FORWARDED, null);

        return $route;
    }

    /**
     * @param array<string, mixed> $route
     * @return array{event: array<string, mixed>, resolved: int, delivered: int, decided: ?string}
     */
    private function decide(array $route, int $actorId, string $verdict): array
    {
        return $this->router->act(
            self::TENANT,
            $actorId,
            $route,
            RouteAction::ACKNOWLEDGED,
            null,
            $verdict,
        );
    }

    /**
     * The recipient rows at one step of one route, read back from the table
     * rather than from anything the engine returned.
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
        $ids = array_map(
            static fn (array $r): int => (int) $r['profile_id'],
            $this->rowsAtStep($documentId, $route, $position)
        );
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

    private function seedProfile(int $id, string $name): void
    {
        $this->pdo->exec(
            'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                   two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (' . $id . ', ' . $this->pdo->quote($name) . ', ' . $this->pdo->quote('x') . ', false, 0, 0, '
             . $this->now() . ', ' . $this->now() . ')'
        );
    }

    private function seedMembership(int $id, int $profileId, int $tenantId, int $roleId, ?int $ouId): void
    {
        $this->pdo->exec(
            'INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES (' . $id . ', ' . $profileId . ', ' . $tenantId . ', ' . $roleId . ', '
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

        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Faculty') . ', ' . $quote('faculty') . ', ' . $now . '),
                (21, 1, 20,   ' . $quote('Dept A') . ',  ' . $quote('dept-a') . ',  ' . $now . ')'
        );

        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('dean') . ', ' . $quote('') . ', 1, ' . $now . '),
                (101, ' . $quote('head') . ', ' . $quote('') . ', 1, ' . $now . '),
                (102, ' . $quote('technician') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );

        foreach ([[10, 'dean'], [11, 'head-a'], [12, 'head-b'], [13, 'head-c'], [14, 'tech-a']] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, '
                 . $now . ', ' . $now . ')'
            );
        }

        // The three heads share ROLE_HEAD, so `role: head` is one node meaning a
        // TYPE — which is the whole reason the quorum question exists.
        $pdo->exec(
            "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1010, 10, 1, " . self::ROLE_DEAN . ", 20, true, 'active', {$now}),
                (1011, 11, 1, " . self::ROLE_HEAD . ", 21, true, 'active', {$now}),
                (1012, 12, 1, " . self::ROLE_HEAD . ", 21, true, 'active', {$now}),
                (1013, 13, 1, " . self::ROLE_HEAD . ", 21, true, 'active', {$now}),
                (1014, 14, 1, " . self::ROLE_TECH . ", 21, true, 'active', {$now})"
        );

        return $pdo;
    }
}
