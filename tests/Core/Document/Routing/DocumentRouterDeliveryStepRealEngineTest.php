<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\Organizer\DocumentCriteria;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRoutingInboxSource;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteSatisfaction;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingPresenter;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;

/**
 * Real-engine tests for #1054 — A STEP SATISFIED BY DELIVERY.
 *
 * THE ONE THE REQUESTING TEAM IS BLOCKED ON is
 * {@see testAwaitingMeStaysCleanForSomebodyWhoWasOnlyToldAboutADocument()}. Every
 * other assertion here supports it. The scenario is theirs, run end to end: a
 * policy circular is raised, a department head approves it, and the last stage
 * puts it in front of every instructor in the faculty. Nobody downstream logs in.
 * Nobody acknowledges anything.
 *
 * Authored as an ordinary step that stage leaves every instructor holding a
 * recipient row with `closed_by_event_id IS NULL` FOR EVER — a permanent item in
 * "Awaiting me" and in the #881 inbox that no act can clear, because there is no
 * act to make. For a node resolving to a whole faculty that is a phantom entry in
 * hundreds of inboxes.
 *
 * EVERY ASSERTION IS WRITTEN AGAINST THE BEHAVIOUR THAT WOULD BE WRONG, because
 * this feature's failures are quiet:
 *
 *  1. THE ROWS CLOSE, AND THEY CLOSE WITHOUT ANYBODY ACTING. Asserted from the
 *     TABLE rather than from what the engine returned, and paired with a CONTROL
 *     recipient on an ordinary step who must still be open — an engine that
 *     closed everything would pass the first half of this on its own.
 *
 *  2. "AWAITING ME" IS CLEAN FOR THE PEOPLE TOLD AND STILL RIGHT FOR EVERYBODY
 *     ELSE. Both halves, in one test, against one document. A folder that
 *     answered nothing for everybody would satisfy the first half perfectly.
 *
 *  3. THE DOCUMENT DOES NOT STOP THERE. A delivery stage in the MIDDLE of a route
 *     must pass the document on. The failure it prevents is invisible: the chain
 *     would simply end, and the trail would show a document that had travelled
 *     normally as far as it went.
 *
 *  4. THE TRAIL DOES NOT PUT WORDS IN ANYBODY'S MOUTH. The notified people appear
 *     as actors nowhere, and no sixth action verb was invented to say they were
 *     told.
 *
 *  5. THE COMBINATION THAT CANNOT WORK IS REFUSED AT AUTHORING TIME, and refused
 *     having written nothing.
 *
 * ENGINE. {@see SchemaFromMigrations::make()} returns real PostgreSQL when
 * PHPUNIT_PG_DSN is set and SQLite otherwise. Both matter: migration 125's CHECK
 * and NOT NULL are only enforced by a real engine, while the SQLite path is what
 * CI's unit job runs.
 */
final class DocumentRouterDeliveryStepRealEngineTest extends TestCase
{
    private const TENANT = 1;

    /** The raiser: a dean, in the Faculty unit (20). */
    private const DEAN = 10;
    /** The approver the circular passes through. */
    private const HEAD_A = 11;
    /** A technician — the CONTROL recipient, on an ordinary step. */
    private const TECH_A = 14;
    /** Three instructors sharing one role, so a node means a TYPE and not a person. */
    private const INSTRUCTOR_A = 30;
    private const INSTRUCTOR_B = 31;
    private const INSTRUCTOR_C = 32;

    private const ROLE_DEAN = 100;
    private const ROLE_HEAD = 101;
    private const ROLE_TECH = 102;
    private const ROLE_INSTRUCTOR = 103;

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteStepRepository $steps;
    private RouteEventRepository $events;
    private RouteRecipientRepository $recipients;
    private DocumentRepository $documents;

    protected function setUp(): void
    {
        $this->pdo = $this->makeSchema();

        $this->steps = new RouteStepRepository($this->pdo);
        $this->events = new RouteEventRepository($this->pdo);
        $this->recipients = new RouteRecipientRepository($this->pdo);
        $this->documents = new DocumentRepository($this->pdo);

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
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo)
            ),
            null
        );
    }

    // -- 1. the rows close, and nobody acted --------------------------------

    public function testADeliveryStepClosesEveryRowItOpensAndTheControlStaysOpen(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueCircular($documentId);

        // The head approves — which on this route is a plain forward onto the
        // delivery stage.
        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        $delivered = $this->rowsAtStep($documentId, $route, 2);

        self::assertSame(
            [self::INSTRUCTOR_A, self::INSTRUCTOR_B, self::INSTRUCTOR_C],
            $this->profilesIn($delivered),
            'the delivery stage must still RESOLVE its rule and still WRITE a row per person. The row is '
            . 'the only record of who a rule actually reached and when — not writing it would make "who '
            . 'was told?" unanswerable for exactly the distribution most likely to be asked about'
        );

        foreach ($delivered as $row) {
            self::assertNotNull(
                $row['closed_by_event_id'],
                'a delivery stage leaves NOTHING open. An open row here is a permanent item in this '
                . "person's inbox that no act of theirs could ever clear, because there is no act to make"
            );
            self::assertSame(
                $row['created_by_event_id'],
                $row['closed_by_event_id'],
                'the row must be closed by the event that CREATED it. Any other event id would mean a '
                . 'second trail entry was appended to say the system had delivered — a verb that does '
                . 'not exist, on an append-only table that could never be corrected'
            );
        }
    }

    public function testTheControlRecipientOnAnOrdinaryStepIsStillWaiting(): void
    {
        // THE FIXTURE TRAP THIS FILE IS WRITTEN AGAINST. Every assertion above is
        // about rows being CLOSED, and an engine that closed every row it ever
        // opened would satisfy all of them. This is the other direction, on the
        // same route, in the same run.
        $documentId = $this->seedDocument();
        $route = $this->issueCircularWithATailStep($documentId);

        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        $tail = $this->rowsAtStep($documentId, $route, 3);
        self::assertSame([self::TECH_A], $this->profilesIn($tail));
        self::assertNull(
            $tail[0]['closed_by_event_id'],
            'an ordinary step must still hold its item open. If this fails, every assertion in this file '
            . 'about a closed row is passing for the wrong reason'
        );
    }

    // -- 2. THE DELIVERABLE: "Awaiting me" ----------------------------------

    public function testAwaitingMeStaysCleanForSomebodyWhoWasOnlyToldAboutADocument(): void
    {
        // #1054's whole point, asserted through the ORGANIZER rather than through
        // the recipient table — because "Awaiting me" is what the requesting team
        // watched fill up with items nobody could clear, and it is a separate
        // predicate in a separate class from the one the engine writes.
        $documentId = $this->seedDocument();
        $route = $this->issueCircularWithATailStep($documentId);

        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        foreach ([self::INSTRUCTOR_A, self::INSTRUCTOR_B, self::INSTRUCTOR_C] as $instructor) {
            self::assertSame(
                [],
                $this->awaitingMe($instructor),
                'a person who was TOLD about a document is not a person the document is waiting on. '
                . 'Before #1054 this circular sat in every instructor\'s "Awaiting me" for ever, and no '
                . 'act available to them could remove it'
            );
        }

        // AND THE FOLDER STILL WORKS. A "Awaiting me" that answered nothing for
        // everybody would pass the loop above perfectly, which is why the control
        // is asserted here rather than in a test somebody could delete on its own.
        self::assertSame(
            [$documentId],
            $this->awaitingMe(self::TECH_A),
            'the same folder must still list the document for the person who really is holding it'
        );
    }

    public function testTheRoutingInboxIsEmptyForSomebodyOnlyToldAndSaysSentInTheirHistory(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueCircular($documentId);
        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        $inbox = new DocumentRoutingInboxSource($this->recipients);

        self::assertSame(
            0,
            $inbox->count(self::TENANT, self::INSTRUCTOR_A, openOnly: true),
            'the #881 inbox reads the same rows as the folder and must inherit the same emptiness'
        );

        // It is in their HISTORY, and there it must not read as work they did.
        $history = $inbox->list(self::TENANT, self::INSTRUCTOR_A, openOnly: false, limit: 10, offset: 0);
        self::assertCount(1, $history);
        self::assertSame(
            'Sent to you',
            $history[0]->status,
            'a delivery item is closed, but nobody closed it by acting. "Done" would credit this person '
            . 'with something they never did — and it is the ONE closed item in the inbox that is not '
            . 'work somebody finished'
        );
        self::assertSame(
            RouteSatisfaction::DELIVERY,
            $history[0]->meta['satisfied_by'] ?? null,
            'a client needs this before it renders anything: on a delivery item every act is a 422, so '
            . 'the three ordinary buttons are three buttons that cannot work'
        );
    }

    public function testTheDocumentsOwnRecipientListSaysWhichRowsNobodyClosedByActing(): void
    {
        // The screen that asks "who did this reach, and what became of it for
        // each of them". Without a flag here a delivery stage's rows read exactly
        // like people who acted — the same misattribution the "Awaiting me"
        // phantom was, one screen over and pointing the other way.
        $documentId = $this->seedDocument();
        $route = $this->issueCircularWithATailStep($documentId);
        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        $published = [];
        foreach ($this->recipients->listForDocument($documentId, self::TENANT) as $row) {
            $wire = RoutingPresenter::recipient($row);
            $published[(int) $wire['profile_id']] = [$wire['open'], $wire['closed_by_delivery']];
        }
        ksort($published);

        self::assertSame(
            [
                // The head: closed, and closed BY ACTING — they forwarded it.
                self::HEAD_A => [false, false],
                self::TECH_A => [true, false],
                self::INSTRUCTOR_A => [false, true],
                self::INSTRUCTOR_B => [false, true],
                self::INSTRUCTOR_C => [false, true],
            ],
            $published,
            'three states, and the middle one is the whole point: closed-by-acting and '
            . 'closed-by-delivery must not render alike'
        );
    }

    // -- 3. the document does not stop there --------------------------------

    public function testADeliveryStepInTheMiddleOfARouteDoesNotStrandTheDocument(): void
    {
        // The failure this prevents is INVISIBLE. A delivery stage that closed its
        // own rows and stopped would end every chain that reached it, and the
        // trail would show a document that had travelled perfectly normally as
        // far as it went — no error, no open item, nothing to chase.
        $documentId = $this->seedDocument();
        $route = $this->issueCircularWithATailStep($documentId);

        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        self::assertSame(
            [self::TECH_A],
            $this->profilesIn($this->rowsAtStep($documentId, $route, 3)),
            'the step AFTER a delivery stage must open in the same act. Nobody at a delivery stage will '
            . 'ever forward the document, so if the engine waits for one the route is over'
        );
    }

    public function testTwoDeliveryStepsInARowAreBothToldAndTheRouteStillReachesTheEnd(): void
    {
        $documentId = $this->seedDocument();

        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Two announcements', [
            $this->deliveryStage(['profile_ids' => [self::INSTRUCTOR_A]]),
            $this->deliveryStage(['profile_ids' => [self::INSTRUCTOR_B]]),
            [
                'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                'rule_config' => ['profile_ids' => [self::TECH_A]],
            ],
        ]);
        $route = $issued['route'];

        self::assertSame(
            [self::INSTRUCTOR_A],
            $this->profilesIn($this->rowsAtStep($documentId, $route, 1))
        );
        self::assertSame(
            [self::INSTRUCTOR_B],
            $this->profilesIn($this->rowsAtStep($documentId, $route, 2)),
            'a second delivery stage must be reached too — the walk continues for as many as the author '
            . 'drew, not for one'
        );
        self::assertSame(
            [self::TECH_A],
            $this->profilesIn($this->rowsAtStep($documentId, $route, 3)),
            'and the chain arrives at the first stage that actually asks somebody for something'
        );

        self::assertSame(
            [self::TECH_A],
            $this->openProfiles($documentId),
            'exactly one person is being waited on: the two who were told hold nothing'
        );

        // ONE trail event, not three. Reaching a delivery stage is not something
        // that happened to the document over and above the act that reached it.
        self::assertCount(
            1,
            $this->events->listForDocument($documentId, self::TENANT, 50, 0),
            'the whole chain is ONE act and appends ONE event. A second event would have to carry an '
            . 'action verb, and the only honest one for "the system delivered this" does not exist'
        );
    }

    public function testADeliveryStepAtTheEndOfARouteSimplyEnds(): void
    {
        // The motivating shape, and the one that must leave nothing behind.
        $documentId = $this->seedDocument();
        $route = $this->issueCircular($documentId);
        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        self::assertSame(
            [],
            $this->openProfiles($documentId),
            'a circular whose last stage tells a faculty is finished when it has told them. Anything '
            . 'still open is an inbox that will never empty'
        );
    }

    // -- 4. the trail does not claim anybody acted --------------------------

    public function testTheTrailNamesNobodyAtADeliveryStepAsAnActor(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueCircular($documentId);
        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        $trail = $this->events->listForDocument($documentId, self::TENANT, 50, 0);

        self::assertSame(
            [
                [RouteAction::ISSUED, self::DEAN],
                [RouteAction::FORWARDED, self::HEAD_A],
            ],
            array_map(static fn (array $e): array => [$e['action'], $e['actor_profile_id']], $trail),
            'the trail records exactly the two acts two people made. Three hundred instructors being '
            . 'told is recorded by their recipient rows, which is where "who did a rule reach" belongs — '
            . 'putting it in the trail would need a verb for it, and every candidate verb is a claim '
            . 'that somebody did something they did not'
        );

        // Nor does anything "acted on by me" for them, which is the other folder
        // derived from the trail.
        foreach ([self::INSTRUCTOR_A, self::INSTRUCTOR_B, self::INSTRUCTOR_C] as $instructor) {
            self::assertSame(
                [],
                $this->documentIds(new DocumentCriteria(actedOnByProfileId: $instructor)),
                'being told about a document is not acting on it'
            );
        }
    }

    public function testNobodyAtADeliveryStepCanActOnIt(): void
    {
        $documentId = $this->seedDocument();
        $route = $this->issueCircular($documentId);
        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        $this->expectException(RoutingRejectedException::class);

        try {
            $this->router->act(self::TENANT, self::INSTRUCTOR_A, $route, RouteAction::ACKNOWLEDGED, null);
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('no open item', $e->clientMessage);
            self::assertSame(
                2,
                count($this->events->listForDocument($documentId, self::TENANT, 50, 0)),
                'the refused act must have written nothing'
            );
            throw $e;
        }
    }

    // -- 5. the combination that cannot work is refused ---------------------

    public function testADeliveryStepCannotAlsoBeADecisionStep(): void
    {
        // Refused at AUTHORING time, not left to produce a gate nobody can
        // answer. A quorum is counted over the recipient rows one act opened, and
        // a delivery stage closes every one of them the instant it creates them —
        // so the step would sit there looking like an approval that nobody had
        // got round to, for ever.
        $documentId = $this->seedDocument();

        $this->expectException(RoutingRejectedException::class);

        try {
            $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Impossible gate', [
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                    'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
                    'satisfied_by' => RouteSatisfaction::DELIVERY,
                    'decision' => true,
                ],
            ]);
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('cannot be both', $e->clientMessage);
            self::assertSame(
                [],
                $this->events->listForDocument($documentId, self::TENANT, 50, 0),
                'a refused route must write nothing at all, not even a partial one'
            );
            throw $e;
        }
    }

    public function testAnUnknownSatisfactionIsRefusedRatherThanIgnored(): void
    {
        // Ignored, it would silently become an ordinary step — which for an author
        // who meant `delivery` is the permanent-phantom-item bug arriving by the
        // one door a typo can open.
        $documentId = $this->seedDocument();

        $this->expectException(RoutingRejectedException::class);

        try {
            $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Typo', [
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                    'rule_config' => ['profile_ids' => [self::INSTRUCTOR_A]],
                    'satisfied_by' => 'notify',
                ],
            ]);
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('satisfied_by', $e->clientMessage);
            throw $e;
        }
    }

    // -- 6. what the broadcast says -----------------------------------------

    public function testTheBroadcastNamesEveryoneReachedAndWhetherEachIsAskedForAnything(): void
    {
        // A subscriber cannot work this out for itself: by the time any listener
        // runs, a delivery stage's rows are CLOSED, so re-reading the open set
        // finds nobody and announces nothing to the very people the stage existed
        // to tell. The event has to carry it.
        $hooks = new HookManager();
        $seen = [];
        $hooks->listen('document.route_acted', static function (array $data, array $context) use (&$seen): array {
            $seen[] = $data;

            return $data;
        });

        $router = $this->routerWith($hooks);

        $documentId = $this->seedDocument();
        $route = $this->issueCircularWithATailStep($documentId, $router);
        $router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        self::assertCount(1, $seen, 'one act, one synchronous broadcast');

        $announced = [];
        foreach ($seen[0]['recipients'] as $recipient) {
            $announced[(int) $recipient['profile_id']] = (string) $recipient['satisfied_by'];
        }
        ksort($announced);

        self::assertSame(
            // Keyed by profile id and ksorted above, so TECH_A (14) leads.
            [
                self::TECH_A => RouteSatisfaction::ACT,
                self::INSTRUCTOR_A => RouteSatisfaction::DELIVERY,
                self::INSTRUCTOR_B => RouteSatisfaction::DELIVERY,
                self::INSTRUCTOR_C => RouteSatisfaction::DELIVERY,
            ],
            $announced,
            'every person the act reached, across every stop of the chain, with the one fact that '
            . 'decides which message they get — being told and being asked must not read alike'
        );

        self::assertSame('Policy circular', $seen[0]['title'] ?? null);
    }

    public function testANoteAnnouncesNobody(): void
    {
        $hooks = new HookManager();
        $seen = [];
        $hooks->listen('document.route_acted', static function (array $data, array $context) use (&$seen): array {
            $seen[] = $data;

            return $data;
        });

        $router = $this->routerWith($hooks);
        $documentId = $this->seedDocument();
        $route = $this->issueCircular($documentId, $router);

        $router->act(self::TENANT, self::DEAN, $route, RouteAction::NOTED, 'For the record.');

        self::assertCount(1, $seen);
        self::assertSame(
            [],
            $seen[0]['recipients'],
            'a note closes nothing and opens nothing, so it reaches nobody. The key is present rather '
            . 'than absent so a subscriber needs no special case for it'
        );
    }

    // -- helpers -------------------------------------------------------------

    /**
     * A delivery STAGE declaration.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function deliveryStage(array $config): array
    {
        return [
            'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
            'rule_config' => $config,
            'satisfied_by' => RouteSatisfaction::DELIVERY,
        ];
    }

    /**
     * THE MOTIVATING ROUTE: a head holds it, then every instructor is told.
     *
     * The second stage names a ROLE three people hold, so the node is a TYPE and
     * not a roster — the property that must survive a stage being non-blocking.
     *
     * @return array<string, mixed> The route row.
     */
    private function issueCircular(int $documentId, ?DocumentRouter $router = null): array
    {
        return ($router ?? $this->router)->issue(
            self::TENANT,
            self::DEAN,
            ['id' => $documentId],
            'Policy circular',
            [
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                    'rule_config' => ['profile_ids' => [self::HEAD_A]],
                ],
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                    'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
                    'satisfied_by' => RouteSatisfaction::DELIVERY,
                ],
            ]
        )['route'];
    }

    /**
     * The same route with an ordinary step BEHIND the delivery stage — so the
     * chain has to walk past it, and so there is a control recipient who is still
     * genuinely being waited on.
     *
     * @return array<string, mixed>
     */
    private function issueCircularWithATailStep(int $documentId, ?DocumentRouter $router = null): array
    {
        return ($router ?? $this->router)->issue(
            self::TENANT,
            self::DEAN,
            ['id' => $documentId],
            'Policy circular',
            [
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                    'rule_config' => ['profile_ids' => [self::HEAD_A]],
                ],
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_ROLE,
                    'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
                    'satisfied_by' => RouteSatisfaction::DELIVERY,
                ],
                [
                    'rule_kind' => RoutingRuleRegistry::KIND_EXPLICIT,
                    'rule_config' => ['profile_ids' => [self::TECH_A]],
                ],
            ]
        )['route'];
    }

    private function routerWith(HookManager $hooks): DocumentRouter
    {
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

        return new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            $this->steps,
            $this->events,
            $this->recipients,
            new RouteEdgeRepository($this->pdo),
            $rules,
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo)
            ),
            $hooks
        );
    }

    /**
     * "Awaiting me", read through the ORGANIZER — the folder, not the table.
     *
     * @return list<int>
     */
    private function awaitingMe(int $profileId): array
    {
        return $this->documentIds(new DocumentCriteria(awaitingProfileId: $profileId));
    }

    /** @return list<int> */
    private function documentIds(DocumentCriteria $criteria): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->documents->listForCriteria(self::TENANT, $criteria, 50, 0)
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
     * @param list<array<string, mixed>> $rows
     * @return list<int>
     */
    private function profilesIn(array $rows): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['profile_id'], $rows);
        sort($ids);

        return array_values($ids);
    }

    /**
     * Everybody still holding an open item on a document.
     *
     * @return list<int>
     */
    private function openProfiles(int $documentId): array
    {
        return $this->profilesIn(array_values(array_filter(
            $this->recipients->listForDocument($documentId, self::TENANT),
            static fn (array $r): bool => $r['closed_by_event_id'] === null
        )));
    }

    private function seedDocument(): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id,
                                    created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Circular') . ', ' . $this->pdo->quote('Policy circular')
             . ', 20, 10, ' . $this->now() . ')'
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
                (102, ' . $quote('technician') . ', ' . $quote('') . ', 1, ' . $now . '),
                (103, ' . $quote('instructor') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );

        $people = [
            [10, 'dean'], [11, 'head-a'], [14, 'tech-a'],
            [30, 'instructor-a'], [31, 'instructor-b'], [32, 'instructor-c'],
        ];
        foreach ($people as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, '
                 . $now . ', ' . $now . ')'
            );
        }

        // The three instructors share ROLE_INSTRUCTOR, so `role: instructor` is
        // ONE node meaning a TYPE — the property #1054 must not trade away in
        // exchange for a stage that does not block.
        $pdo->exec(
            "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1010, 10, 1, " . self::ROLE_DEAN . ", 20, true, 'active', {$now}),
                (1011, 11, 1, " . self::ROLE_HEAD . ", 21, true, 'active', {$now}),
                (1014, 14, 1, " . self::ROLE_TECH . ", 21, true, 'active', {$now}),
                (1030, 30, 1, " . self::ROLE_INSTRUCTOR . ", 21, true, 'active', {$now}),
                (1031, 31, 1, " . self::ROLE_INSTRUCTOR . ", 21, true, 'active', {$now}),
                (1032, 32, 1, " . self::ROLE_INSTRUCTOR . ", 21, true, 'active', {$now})"
        );

        return $pdo;
    }
}
