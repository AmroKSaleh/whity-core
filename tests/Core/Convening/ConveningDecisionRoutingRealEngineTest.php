<?php

declare(strict_types=1);

namespace Tests\Core\Convening;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Convening\AgendaRepository;
use Whity\Core\Convening\ConveningBodyRepository;
use Whity\Core\Convening\ConveningRejectedException;
use Whity\Core\Convening\DecisionNumbers;
use Whity\Core\Convening\DecisionRecorder;
use Whity\Core\Convening\DecisionRepository;
use Whity\Core\Convening\DecisionRouteBridge;
use Whity\Core\Convening\DecisionVerdict;
use Whity\Core\Convening\InvitationRepository;
use Whity\Core\Convening\InvitationStatus;
use Whity\Core\Convening\MeetingRepository;
use Whity\Core\Convening\MeetingStatus;
use Whity\Core\Convening\MemberRole;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Database\SequenceCounters;

/**
 * Real-engine tests for the CONVENING seam: a body's decision answering a
 * document's approval step.
 *
 * EVERY ASSERTION HERE IS WRITTEN AGAINST THE FAILURE THAT LOOKS LIKE SUCCESS,
 * because this feature has four of them and all four render identically to a
 * working one on any screen:
 *
 *  1. A DECISION THAT MOVES NOTHING. The minute-book says "approved" and the
 *     document is exactly where it was. Asserted by the ABSENCE of recipient
 *     rows at the step an approval opens — not by the decision row's own
 *     verdict, which a broken bridge writes perfectly well.
 *
 *  2. A REJECTION THAT ADVANCES THE DOCUMENT. Asserted the same way, from the
 *     other side: the step an approval would have opened must be EMPTY.
 *
 *  3. A DEFERRAL SILENTLY TREATED AS AN APPROVAL. Asserted by the same absence,
 *     plus the reason code, plus a paired positive control on the same fixture
 *     so the test cannot pass by moving nothing at all.
 *
 *  4. A DECISION NUMBER HANDED OUT TWICE. Asserted by taking several in a row
 *     and comparing the whole set, per body and per year, rather than by
 *     checking that one looks right.
 *
 * THE ROUTING ASSERTIONS NEVER READ THE BRIDGE'S OWN REPORT ALONE. Where the
 * report says `applied`, the test also goes to `document_route_recipients` and
 * `document_route_events` and looks. A bridge that returned the right report and
 * called nothing would pass every assertion made against itself.
 *
 * ENGINE. {@see SchemaFromMigrations::make()} returns real PostgreSQL when
 * PHPUNIT_PG_DSN is set and SQLite otherwise. Both matter: migration 130's CHECK
 * constraints and its partial unique index are only enforced by a real engine,
 * while the SQLite path is what CI's unit job runs.
 *
 * VOCABULARY. Bodies, members, chairs, secretaries, meetings, agenda items and
 * decisions — the fixture is a standards board and an operations group, chosen
 * because neither is the case that motivated the feature.
 */
final class ConveningDecisionRoutingRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private const CHAIR = 10;
    private const SECRETARY = 11;
    private const MEMBER = 12;
    private const OUTSIDER = 13;
    private const ISSUER = 14;

    private PDO $pdo;
    private ConveningBodyRepository $bodies;
    private MeetingRepository $meetings;
    private AgendaRepository $agenda;
    private DecisionRepository $decisions;
    private InvitationRepository $invitations;
    private DecisionRecorder $recorder;
    private DocumentRouter $router;
    private RouteRecipientRepository $recipients;

    private int $boardId;
    private int $opsId;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();

        $sequences = new SequenceCounters($this->pdo);
        $this->bodies = new ConveningBodyRepository($this->pdo);
        $this->meetings = new MeetingRepository($this->pdo, $sequences);
        $this->agenda = new AgendaRepository($this->pdo);
        $this->decisions = new DecisionRepository($this->pdo);
        $this->invitations = new InvitationRepository($this->pdo);

        $routes = new RouteRepository($this->pdo);
        $steps = new RouteStepRepository($this->pdo);
        $this->recipients = new RouteRecipientRepository($this->pdo);

        $rules = new RoutingRuleRegistry();
        $registry = $rules;
        $rules->registerCoreRoutingRules(
            new \Whity\Core\Document\Routing\RoleRuleResolver($this->pdo),
            new \Whity\Core\Document\Routing\RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $this->pdo,
                new UserGroupRepository($this->pdo),
                static fn (): RoutingRuleRegistry => $registry
            ))
        );

        $this->router = new DocumentRouter(
            $this->pdo,
            $routes,
            $steps,
            new RouteEventRepository($this->pdo),
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo)
            ),
            null
        );

        $this->recorder = new DecisionRecorder(
            $this->pdo,
            $this->bodies,
            $this->meetings,
            $this->agenda,
            $this->decisions,
            new DecisionNumbers($sequences),
            new DecisionRouteBridge(
                $this->router,
                $routes,
                $steps,
                $this->recipients,
                $this->bodies
            )
        );

        $this->boardId = $this->bodies->create(
            self::TENANT,
            'standards-board',
            ['en' => 'Standards Board', 'ar' => 'مجلس المعايير'],
            null,
            'Approves standards before they are issued.'
        );
        $this->bodies->addMember(self::TENANT, $this->boardId, self::CHAIR, MemberRole::CHAIR);
        $this->bodies->addMember(self::TENANT, $this->boardId, self::SECRETARY, MemberRole::SECRETARY);
        $this->bodies->addMember(self::TENANT, $this->boardId, self::MEMBER, MemberRole::MEMBER);

        $this->opsId = $this->bodies->create(self::TENANT, 'operations-group', ['en' => 'Operations Group'], null, null);
        $this->bodies->addMember(self::TENANT, $this->opsId, self::CHAIR, MemberRole::CHAIR);
    }

    // -- 1. an approval actually moves the document -------------------------

    public function testAnApprovalDrivesTheRouteAndTheDocumentReachesTheNextStep(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueGateReaching($documentId, self::CHAIR);
        $item = $this->heldMeetingWithItem($this->boardId, $documentId);

        self::assertSame(
            [],
            $this->rowsAtPosition($documentId, $route, 2),
            'the fixture must start with nothing at step 2, or the assertion below proves nothing'
        );

        $result = $this->recorder->record(
            self::TENANT,
            $item,
            DecisionVerdict::APPROVED,
            'Meets the published criteria.',
            '2026-03-04 10:00:00',
            self::SECRETARY
        );

        self::assertTrue($result['routing']['applied'], 'the decision must have reached the routing engine');

        // THE LOAD-BEARING ASSERTION: where the document ENDED UP, read straight
        // out of the engine's own table. A bridge that reported success and
        // called nothing passes every check made against its own return value.
        self::assertNotSame(
            [],
            $this->rowsAtPosition($documentId, $route, 2),
            'an approved decision must open the next step of the route — a minute that says "approved" '
            . 'beside a document that has not moved is the failure this whole seam exists to prevent'
        );

        // And the trail says a person did it, carrying the verdict.
        $event = $this->lastEvent($documentId);
        self::assertSame('acknowledged', $event['action']);
        self::assertSame('approved', $event['verdict']);
        self::assertSame(
            self::CHAIR,
            (int) $event['actor_profile_id'],
            'the chair holds the open item, so the chair is who the trail must name — not the secretary '
            . 'who typed the minute, and not a body, which cannot hold a recipient row at all'
        );

        // The decision row records what it DID, not only what it said.
        self::assertSame((int) $route['id'], $result['decision']['route_id']);
        self::assertSame(
            (int) $event['id'],
            $result['decision']['route_event_id'],
            'the decision must point at the trail entry it produced, or "approved and advanced" is '
            . 'indistinguishable from "approved and nothing happened"'
        );
    }

    // -- 2. a rejection goes somewhere an approval never does ---------------

    public function testARejectionDoesNotOpenTheStepAnApprovalWouldHave(): void
    {
        $documentId = $this->seedDocument();

        $approvedRoute = $this->issueGateReaching($documentId, self::CHAIR);
        $rejectedRoute = $this->issueGateReaching($documentId, self::CHAIR);

        // Two agenda items on two held meetings, so the two decisions are
        // genuinely independent records rather than one item decided twice.
        $approvedItem = $this->heldMeetingWithItem($this->boardId, $documentId);
        $rejectedItem = $this->heldMeetingWithItem($this->boardId, $documentId);

        // Newest route first is what the bridge walks, so the REJECTION has to be
        // recorded first — otherwise both decisions would land on the same route.
        $this->recorder->record(
            self::TENANT,
            $rejectedItem,
            DecisionVerdict::REJECTED,
            'Does not meet the criteria.',
            '2026-03-04 10:00:00',
            self::SECRETARY
        );
        $this->recorder->record(
            self::TENANT,
            $approvedItem,
            DecisionVerdict::APPROVED,
            null,
            '2026-03-04 10:05:00',
            self::SECRETARY
        );

        self::assertNotSame(
            [],
            $this->rowsAtPosition($documentId, $approvedRoute, 2),
            'the approved route must reach step 2 — otherwise the rejection assertion below proves '
            . 'nothing, because neither route moved'
        );
        self::assertSame(
            [],
            $this->rowsAtPosition($documentId, $rejectedRoute, 2),
            'a rejected decision must NOT reach the step an approval reaches. A rejection that records '
            . 'dissent and lets the document proceed fails invisibly: the minute says rejected while '
            . 'the document travels exactly as an approved one does'
        );
    }

    // -- 3. a deferral decides, and moves nothing ---------------------------

    public function testADeferralIsRecordedAndNumberedButMovesNothing(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueGateReaching($documentId, self::CHAIR);
        $item = $this->heldMeetingWithItem($this->boardId, $documentId);

        $result = $this->recorder->record(
            self::TENANT,
            $item,
            DecisionVerdict::DEFERRED,
            'More information requested.',
            '2026-03-04 10:00:00',
            self::SECRETARY
        );

        self::assertFalse($result['routing']['applied']);
        self::assertSame(DecisionRouteBridge::NO_ROUTE_VERDICT, $result['routing']['reason']);
        self::assertNull($result['decision']['route_id']);

        self::assertSame(
            [],
            $this->rowsAtPosition($documentId, $route, 2),
            'a deferral must not advance the document: there is no approval it could stand for'
        );

        // AND the step is still OPEN — a deferral must not have closed the
        // chair's item either, which would strand the document with nobody able
        // to answer it.
        self::assertNotNull(
            $this->recipients->findOpenForProfile(self::TENANT, (int) $route['id'], self::CHAIR),
            'a deferral must leave the approval step open — closing it would leave the document waiting '
            . 'on a question nobody can now answer'
        );

        // It is still a real, numbered decision.
        self::assertNotSame('', $result['decision']['decision_number']);
        self::assertSame(DecisionVerdict::DEFERRED, $result['decision']['verdict']);
    }

    // -- 4. decision numbers -------------------------------------------------

    public function testDecisionNumbersAreSequentialPerBodyPerYearAndNeverRepeat(): void
    {
        $numbers = [];

        for ($i = 0; $i < 3; $i++) {
            $item = $this->heldMeetingWithItem($this->boardId, null);
            $numbers[] = $this->recorder->record(
                self::TENANT,
                $item,
                DecisionVerdict::APPROVED,
                null,
                '2026-03-04 10:00:00',
                self::SECRETARY
            )['decision']['decision_number'];
        }

        self::assertSame(
            ['standards-board/2026/1', 'standards-board/2026/2', 'standards-board/2026/3'],
            $numbers,
            'numbering restarts per body per year and never repeats'
        );

        // A SECOND BODY does not inherit the first's position, and a second YEAR
        // restarts. Both are asserted from the same fixture, because a shared
        // counter passes the first three assertions above unchanged.
        $opsItem = $this->heldMeetingWithItem($this->opsId, null);
        self::assertSame(
            'operations-group/2026/1',
            $this->recorder->record(
                self::TENANT,
                $opsItem,
                DecisionVerdict::APPROVED,
                null,
                '2026-05-01 09:00:00',
                self::CHAIR
            )['decision']['decision_number'],
            'a second body must not inherit the first body\'s position in the sequence'
        );

        $laterItem = $this->heldMeetingWithItem($this->boardId, null);
        self::assertSame(
            'standards-board/2027/1',
            $this->recorder->record(
                self::TENANT,
                $laterItem,
                DecisionVerdict::APPROVED,
                null,
                '2027-01-08 09:00:00',
                self::SECRETARY
            )['decision']['decision_number'],
            'the year in the number and the sequence it counts must agree — a sequence that did not '
            . 'restart would read standards-board/2027/5'
        );
    }

    public function testTheYearComesFromTheDecisionNotFromTheServerClock(): void
    {
        // A body minuting December's sitting in January must produce DECEMBER's
        // numbers. Asserted with a date in the past, which a `date('Y')` would
        // number under the current year.
        $item = $this->heldMeetingWithItem($this->boardId, null);

        self::assertSame(
            'standards-board/2019/1',
            $this->recorder->record(
                self::TENANT,
                $item,
                DecisionVerdict::APPROVED,
                null,
                '2019-12-19 16:00:00',
                self::SECRETARY
            )['decision']['decision_number']
        );
    }

    // -- 5. the honest no-ops ------------------------------------------------

    public function testAnItemWithNoDocumentIsRecordedAndReportsWhyNothingMoved(): void
    {
        $item = $this->heldMeetingWithItem($this->boardId, null);

        $result = $this->recorder->record(
            self::TENANT,
            $item,
            DecisionVerdict::APPROVED,
            null,
            '2026-03-04 10:00:00',
            self::SECRETARY
        );

        self::assertFalse($result['routing']['applied']);
        self::assertSame(DecisionRouteBridge::NO_DOCUMENT, $result['routing']['reason']);
        self::assertNotSame('', $result['routing']['explanation']);
    }

    public function testADocumentThatNeverReachedTheBodyIsLeftWhereItIs(): void
    {
        $documentId = $this->seedDocument();
        // The route reaches somebody who sits on no body at all.
        $route = $this->issueGateReaching($documentId, self::OUTSIDER);
        $item = $this->heldMeetingWithItem($this->boardId, $documentId);

        $result = $this->recorder->record(
            self::TENANT,
            $item,
            DecisionVerdict::APPROVED,
            null,
            '2026-03-04 10:00:00',
            self::SECRETARY
        );

        self::assertFalse($result['routing']['applied']);
        self::assertSame(DecisionRouteBridge::NO_OPEN_ITEM, $result['routing']['reason']);
        self::assertSame(
            [],
            $this->rowsAtPosition($documentId, $route, 2),
            'a body deciding about a document that was never routed to it must not advance that '
            . "document — the body's opinion is on record, and the approval chain is untouched"
        );
        self::assertNotNull(
            $this->recipients->findOpenForProfile(self::TENANT, (int) $route['id'], self::OUTSIDER),
            'and the person the route actually asked must still be holding it'
        );
    }

    public function testACirculationStepTakesNoVerdictAndIsReportedRatherThanForced(): void
    {
        $documentId = $this->seedDocument();

        // An ORDINARY step — not a gate — reaching the chair.
        $issued = $this->router->issue(self::TENANT, self::ISSUER, ['id' => $documentId], 'For information', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::CHAIR]],
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::ISSUER]],
            ],
        ]);
        $route = $issued['route'];
        $item = $this->heldMeetingWithItem($this->boardId, $documentId);

        $result = $this->recorder->record(
            self::TENANT,
            $item,
            DecisionVerdict::REJECTED,
            null,
            '2026-03-04 10:00:00',
            self::SECRETARY
        );

        self::assertFalse($result['routing']['applied']);
        self::assertSame(DecisionRouteBridge::NOT_A_DECISION_STEP, $result['routing']['reason']);
        self::assertSame(
            [],
            $this->rowsAtPosition($documentId, $route, 2),
            'a REJECTION must never be laundered into a plain acknowledgement that advances the '
            . 'document, which is what answering a circulation step without a verdict would do'
        );
    }

    // -- 6. who carries the decision ----------------------------------------

    public function testTheRecorderCarriesTheDecisionWhenTheRouteReachedThemInstead(): void
    {
        $documentId = $this->seedDocument();
        // The route asked the SECRETARY, who is also the person minuting.
        $this->issueGateReaching($documentId, self::SECRETARY);
        $item = $this->heldMeetingWithItem($this->boardId, $documentId);

        $result = $this->recorder->record(
            self::TENANT,
            $item,
            DecisionVerdict::APPROVED,
            null,
            '2026-03-04 10:00:00',
            self::SECRETARY
        );

        self::assertTrue($result['routing']['applied']);
        self::assertSame(
            self::SECRETARY,
            $result['routing']['actor_profile_id'],
            'the person performing the act is who the trail should name when the route reached them'
        );
    }

    public function testAFormerMemberCannotCarryTheBodysDecision(): void
    {
        $documentId = $this->seedDocument();
        // Only the ordinary member was asked — and they then leave the body.
        $route = $this->issueGateReaching($documentId, self::MEMBER);
        $this->bodies->removeMember(self::TENANT, $this->boardId, self::MEMBER);

        $item = $this->heldMeetingWithItem($this->boardId, $documentId);

        $result = $this->recorder->record(
            self::TENANT,
            $item,
            DecisionVerdict::APPROVED,
            null,
            '2026-03-04 10:00:00',
            self::SECRETARY
        );

        self::assertFalse(
            $result['routing']['applied'],
            'somebody who has left the body must not carry its decision, even though their recipient '
            . 'row is still open — a former member\'s name on a current decision is both wrong and '
            . 'invisible until the decision is challenged'
        );
        self::assertSame(DecisionRouteBridge::NO_OPEN_ITEM, $result['routing']['reason']);
        self::assertSame([], $this->rowsAtPosition($documentId, $route, 2));
    }

    // -- 7. a decision needs a meeting that happened ------------------------

    public function testADecisionCannotBeRecordedAgainstAMeetingThatHasNotBeenHeld(): void
    {
        $meetingId = $this->meetings->create(
            self::TENANT,
            'standards-board',
            $this->boardId,
            ['en' => 'March sitting'],
            self::SECRETARY
        );
        $item = $this->agenda->add(
            self::TENANT,
            $meetingId,
            MeetingStatus::DRAFT,
            ['en' => 'A paper'],
            null,
            null
        );

        $before = $this->countDecisions();

        try {
            $this->recorder->record(
                self::TENANT,
                $item,
                DecisionVerdict::APPROVED,
                null,
                '2026-03-04 10:00:00',
                self::SECRETARY
            );
            self::fail('a decision on a meeting that has not been held must be refused');
        } catch (ConveningRejectedException $e) {
            self::assertStringContainsString('held', $e->clientMessage);
        }

        self::assertSame(
            $before,
            $this->countDecisions(),
            'the refusal must have written nothing — a refused decision that still left a row is the '
            . 'reason this is checked by reading the table rather than trusting the exception'
        );
    }

    // -- 8. the agenda -------------------------------------------------------

    public function testAHeldMeetingRefusesAnItemUnlessTheCallerSaysItMeansIt(): void
    {
        $meetingId = $this->meetings->create(
            self::TENANT,
            'standards-board',
            $this->boardId,
            ['en' => 'March sitting'],
            self::SECRETARY
        );
        $this->meetings->hold(self::TENANT, $meetingId, '2026-03-04 10:00:00');

        try {
            $this->agenda->add(self::TENANT, $meetingId, MeetingStatus::HELD, ['en' => 'Late paper'], null, null);
            self::fail('adding to a held meeting must be refused unless explicitly allowed');
        } catch (ConveningRejectedException $e) {
            self::assertStringContainsString('allow_held', $e->clientMessage);
        }

        self::assertSame([], $this->agenda->listForMeeting(self::TENANT, $meetingId));

        // And it IS possible, when asked for.
        $this->agenda->add(
            self::TENANT,
            $meetingId,
            MeetingStatus::HELD,
            ['en' => 'Tabled on the day'],
            null,
            null,
            true
        );
        self::assertCount(1, $this->agenda->listForMeeting(self::TENANT, $meetingId));
    }

    public function testReorderingRewritesEveryPositionAndRefusesAPartialList(): void
    {
        $meetingId = $this->meetings->create(
            self::TENANT,
            'standards-board',
            $this->boardId,
            ['en' => 'March sitting'],
            self::SECRETARY
        );

        $ids = [];
        foreach (['First', 'Second', 'Third'] as $title) {
            $ids[] = $this->agenda->add(
                self::TENANT,
                $meetingId,
                MeetingStatus::DRAFT,
                ['en' => $title],
                null,
                null
            );
        }

        $this->agenda->reorder(self::TENANT, $meetingId, [$ids[2], $ids[0], $ids[1]]);

        self::assertSame(
            [$ids[2], $ids[0], $ids[1]],
            array_map(
                static fn (array $i): int => (int) $i['id'],
                $this->agenda->listForMeeting(self::TENANT, $meetingId)
            ),
            'the agenda must come back in the order asked for — the two-phase write exists because '
            . 'UNIQUE (meeting_id, position) collides the moment any item moves into a live slot'
        );
        self::assertSame(
            [1, 2, 3],
            array_map(
                static fn (array $i): int => (int) $i['position'],
                $this->agenda->listForMeeting(self::TENANT, $meetingId)
            ),
            'and the positions must be contiguous, not merely ordered'
        );

        try {
            $this->agenda->reorder(self::TENANT, $meetingId, [$ids[0]]);
            self::fail('a partial order must be refused');
        } catch (ConveningRejectedException) {
            self::assertSame(
                [$ids[2], $ids[0], $ids[1]],
                array_map(
                    static fn (array $i): int => (int) $i['id'],
                    $this->agenda->listForMeeting(self::TENANT, $meetingId)
                ),
                'a refused reorder must have changed nothing — including the parking phase'
            );
        }
    }

    public function testRemovingAnItemClosesTheGapItLeaves(): void
    {
        $meetingId = $this->meetings->create(
            self::TENANT,
            'standards-board',
            $this->boardId,
            ['en' => 'March sitting'],
            self::SECRETARY
        );

        $ids = [];
        foreach (['First', 'Second', 'Third'] as $title) {
            $ids[] = $this->agenda->add(
                self::TENANT,
                $meetingId,
                MeetingStatus::DRAFT,
                ['en' => $title],
                null,
                null
            );
        }

        $this->agenda->remove(self::TENANT, $ids[0]);

        self::assertSame(
            [1, 2],
            array_map(
                static fn (array $i): int => (int) $i['position'],
                $this->agenda->listForMeeting(self::TENANT, $meetingId)
            ),
            'an agenda with a hole in it reads to every human as an item somebody forgot to list'
        );
    }

    public function testAnItemWithADecisionAgainstItCannotBeRemoved(): void
    {
        $item = $this->heldMeetingWithItem($this->boardId, null);
        $this->recorder->record(
            self::TENANT,
            $item,
            DecisionVerdict::APPROVED,
            null,
            '2026-03-04 10:00:00',
            self::SECRETARY
        );

        $this->expectException(ConveningRejectedException::class);
        $this->agenda->remove(self::TENANT, $item);
    }

    // -- 9. invitations ------------------------------------------------------

    public function testInvitingIsIdempotentAndReportsOnlyThePeopleNewlyInvited(): void
    {
        $meetingId = $this->meetings->create(
            self::TENANT,
            'standards-board',
            $this->boardId,
            ['en' => 'March sitting'],
            self::SECRETARY
        );

        $first = $this->invitations->invite(self::TENANT, $meetingId, [self::CHAIR, self::SECRETARY]);
        self::assertSame([self::CHAIR, self::SECRETARY], $first);

        // Somebody answers, and then the invitations are re-sent because a third
        // person joined the body.
        $this->invitations->respond(self::TENANT, $meetingId, self::CHAIR, InvitationStatus::ACCEPTED);

        $second = $this->invitations->invite(
            self::TENANT,
            $meetingId,
            [self::CHAIR, self::SECRETARY, self::MEMBER]
        );
        self::assertSame(
            [self::MEMBER],
            $second,
            're-sending must report only the people hearing about it for the first time — a caller that '
            . 'notified everybody it asked about would mail the whole body again every time one person '
            . 'was added'
        );

        $byProfile = [];
        foreach ($this->invitations->listForMeeting(self::TENANT, $meetingId) as $row) {
            $byProfile[(int) $row['profile_id']] = $row;
        }

        self::assertCount(3, $byProfile);
        self::assertSame(
            InvitationStatus::ACCEPTED,
            $byProfile[self::CHAIR]['status'],
            're-sending must not reset an answer somebody already gave'
        );
        self::assertNotNull($byProfile[self::CHAIR]['responded_at']);
        self::assertSame(InvitationStatus::INVITED, $byProfile[self::MEMBER]['status']);
        self::assertNull(
            $byProfile[self::MEMBER]['responded_at'],
            '`invited` means "has not answered", never "declined" — which is why the answer time is a '
            . 'separate nullable fact'
        );
    }

    // -- 10. membership ------------------------------------------------------

    public function testMovingSomebodysSeatDoesNotMakeThemLeaveAndRejoin(): void
    {
        $this->bodies->addMember(self::TENANT, $this->boardId, self::MEMBER, MemberRole::SECRETARY);

        $rows = array_values(array_filter(
            $this->bodies->allMembers(self::TENANT, $this->boardId),
            static fn (array $m): bool => (int) $m['profile_id'] === self::MEMBER
        ));

        self::assertCount(
            1,
            $rows,
            'a chair who becomes secretary did not leave the body for an instant; a second row would '
            . 'make the membership history read as a departure that never happened'
        );
        self::assertSame(MemberRole::SECRETARY, $rows[0]['member_role']);
        self::assertNull($rows[0]['left_at']);
    }

    public function testADepartureIsRecordedRatherThanDeletedAndARejoinIsANewSeat(): void
    {
        $this->bodies->removeMember(self::TENANT, $this->boardId, self::MEMBER);
        $this->bodies->addMember(self::TENANT, $this->boardId, self::MEMBER, MemberRole::MEMBER);

        $rows = array_values(array_filter(
            $this->bodies->allMembers(self::TENANT, $this->boardId),
            static fn (array $m): bool => (int) $m['profile_id'] === self::MEMBER
        ));

        self::assertCount(2, $rows, 'the ended seat must survive so a past decision stays attributable');
        self::assertNotNull($rows[0]['left_at']);
        self::assertNull($rows[1]['left_at']);
        self::assertCount(
            3,
            $this->bodies->currentMembers(self::TENANT, $this->boardId),
            'and only one of the two counts as current'
        );
    }

    public function testABodyThatHasMetCannotBeDeleted(): void
    {
        $this->heldMeetingWithItem($this->boardId, null);

        $this->expectException(ConveningRejectedException::class);
        $this->bodies->delete(self::TENANT, $this->boardId);
    }

    // -- 11. tenant isolation ------------------------------------------------

    public function testAnotherTenantsBodiesAndMeetingsAreInvisible(): void
    {
        $otherBody = $this->bodies->create(
            self::OTHER_TENANT,
            'standards-board',
            ['en' => 'Their board'],
            null,
            null
        );
        $otherMeeting = $this->meetings->create(
            self::OTHER_TENANT,
            'standards-board',
            $otherBody,
            ['en' => 'Their sitting'],
            null
        );

        self::assertNull($this->bodies->find(self::TENANT, $otherBody));
        self::assertNull($this->meetings->find(self::TENANT, $otherMeeting));
        self::assertSame(
            [],
            array_filter(
                $this->meetings->listForTenant(self::TENANT),
                static fn (array $m): bool => (int) $m['id'] === $otherMeeting
            )
        );

        // The KEY is per tenant, so both tenants may hold `standards-board` —
        // and each mints its own numbering under it.
        self::assertNotNull($this->bodies->findByKey(self::OTHER_TENANT, 'standards-board'));

        $ours = $this->bodies->findByKey(self::TENANT, 'standards-board');
        self::assertNotNull($ours);
        self::assertSame(
            $this->boardId,
            (int) $ours['id'],
            'the same key in two tenants must resolve to each tenant\'s OWN body'
        );
    }

    // -- fixtures ------------------------------------------------------------

    /**
     * A gate reaching exactly one person, followed by an ordinary second step.
     * Step 2 is where every "did the document move" assertion looks.
     *
     * @return array<string, mixed> The route row.
     */
    private function issueGateReaching(int $documentId, int $profileId): array
    {
        $issued = $this->router->issue(self::TENANT, self::ISSUER, ['id' => $documentId], 'Approval', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [$profileId]],
                'decision' => true,
            ],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::ISSUER]],
            ],
        ]);

        return $issued['route'];
    }

    /**
     * A held meeting of the given body carrying one agenda item.
     *
     * @return int The agenda item id.
     */
    private function heldMeetingWithItem(int $bodyId, ?int $documentId): int
    {
        $body = $this->bodies->find(self::TENANT, $bodyId);
        self::assertNotNull($body);

        $meetingId = $this->meetings->create(
            self::TENANT,
            (string) $body['body_key'],
            $bodyId,
            ['en' => 'Sitting'],
            self::SECRETARY
        );
        $itemId = $this->agenda->add(
            self::TENANT,
            $meetingId,
            MeetingStatus::DRAFT,
            ['en' => 'A paper'],
            $documentId,
            null
        );
        $this->meetings->hold(self::TENANT, $meetingId, '2026-03-04 10:00:00');

        return $itemId;
    }

    /**
     * Open recipient rows at a route step, read straight out of the engine's own
     * table rather than through the bridge that is under test.
     *
     * @param array<string, mixed> $route
     * @return list<array<string, mixed>>
     */
    private function rowsAtPosition(int $documentId, array $route, int $position): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.id
               FROM document_route_recipients r
               JOIN document_route_steps s ON s.id = r.step_id
              WHERE r.tenant_id = :tenant AND r.document_id = :document
                AND r.route_id = :route AND s.position = :position'
        );
        $stmt->execute([
            ':tenant' => self::TENANT,
            ':document' => $documentId,
            ':route' => (int) $route['id'],
            ':position' => $position,
        ]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function lastEvent(int $documentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, action, verdict, actor_profile_id
               FROM document_route_events
              WHERE tenant_id = :tenant AND document_id = :document
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([':tenant' => self::TENANT, ':document' => $documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        /** @var array<string, mixed> $row */
        return $row;
    }

    private function countDecisions(): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM meeting_decisions WHERE tenant_id = :tenant');
        $stmt->execute([':tenant' => self::TENANT]);

        return (int) $stmt->fetchColumn();
    }

    private function seedDocument(): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id,
                                    created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Standard') . ', '
             . $this->pdo->quote('Draft standard') . ', 20, ' . self::ISSUER . ', ' . $this->now() . ')'
        );

        return (int) $this->pdo->lastInsertId();
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

        $pdo->exec(
            'INSERT INTO tenants (id, name) VALUES
                (1, ' . $quote('Tenant One') . '),
                (2, ' . $quote('Tenant Two') . ')
             ON CONFLICT DO NOTHING'
        );

        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Head office') . ', ' . $quote('head-office') . ', ' . $now . ')'
        );

        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('officer') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );

        foreach ([
            [self::CHAIR, 'chair'],
            [self::SECRETARY, 'secretary'],
            [self::MEMBER, 'member'],
            [self::OUTSIDER, 'outsider'],
            [self::ISSUER, 'issuer'],
        ] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, '
                 . $now . ', ' . $now . ')'
            );
        }

        // ACTIVE MEMBERSHIPS ARE NOT DECORATION: the routing engine intersects
        // every resolver's answer with them before it writes a recipient row, so
        // a fixture without them resolves to nobody and every routing assertion
        // in this file would pass by moving nothing.
        $membershipId = 1000;
        foreach ([self::CHAIR, self::SECRETARY, self::MEMBER, self::OUTSIDER, self::ISSUER] as $profileId) {
            $membershipId++;
            $pdo->exec(
                "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
                 VALUES ({$membershipId}, {$profileId}, 1, 100, 20, true, 'active', {$now})"
            );
        }

        return $pdo;
    }
}
