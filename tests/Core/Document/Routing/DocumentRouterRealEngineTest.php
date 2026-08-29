<?php

declare(strict_types=1);

namespace Tests\Core\Document\Routing;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Sdk\Routing\ResolvedRecipient;
use Whity\Sdk\Routing\RoutingRuleContext;
use Whity\Sdk\Routing\RoutingRuleResolverInterface;

/**
 * Real-engine tests for {@see DocumentRouter} (#947 item 3).
 *
 * The three semantics #947 names are the things worth failing a build over, and
 * each is asserted against the behaviour that would be WRONG rather than merely
 * against the behaviour that is right — a test that only checks the happy path
 * cannot tell a fan-out from a barrier, because both deliver eventually.
 *
 *  1. A STEP NAMES A RULE, NEVER A PERSON. A unit and a person created AFTER the
 *     route was authored are included when the step is reached. This is the test
 *     that a stored recipient list would fail — and it would fail silently,
 *     reporting success while omitting them.
 *
 *  2. DISTRIBUTION FANS OUT, IT DOES NOT BLOCK. Two step-1 recipients; ONE of
 *     them forwards. The chain past that one advances, the other's item stays
 *     open, and — the part a barrier would get wrong — the advance happens
 *     without waiting for the second. Asserted by the second recipient still
 *     being open while step 2 already has rows.
 *
 *  3. THE TRAIL IS APPEND-ONLY. Asserted structurally (the repository exposes no
 *     update or delete) and behaviourally (a correction ADDS a row and the
 *     original text is still there afterwards).
 *
 * Plus the two guarantees that are not semantics but are security: a plugin
 * resolver cannot reach outside its tenant, and a ceiling is a refusal rather
 * than a truncation.
 *
 * ENGINE. {@see SchemaFromMigrations::make()} returns REAL PostgreSQL when
 * PHPUNIT_PG_DSN is set and SQLite otherwise, and the routing schema is one
 * where that matters more than usual: the partial unique index and the `action`
 * CHECK are the enforcement for two of the three semantics, and only a real
 * engine reproduces them. The migration-level proof of both lives in the PR
 * body; these tests exercise the ENGINE's behaviour on whichever database they
 * are given.
 */
final class DocumentRouterRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    /** The raiser: a dean, in the Faculty unit (20). */
    private const DEAN = 10;
    /** Two department heads, in departments under the Faculty. */
    private const HEAD_A = 11;
    private const HEAD_B = 12;
    /** A technician under department A. */
    private const TECH_A = 13;
    /** Somebody in another tenant entirely. */
    private const OUTSIDER = 20;

    /**
     * The dean holds a role of their OWN, deliberately.
     *
     * Giving the raiser the same role as the recipients would make every
     * `role: head` assertion pass for the wrong reason — three people rather than
     * two — and would hide the difference between "the rule found the heads" and
     * "the rule found everyone who happens to share a role with the sender".
     */
    private const ROLE_DEAN = 100;
    private const ROLE_HEAD = 101;
    private const ROLE_TECH = 102;

    /** The only OU id the assertions name directly; the rest are fixture detail. */
    private const OU_DEPT_B = 22;

    private PDO $pdo;
    private DocumentRouter $router;
    private RouteStepRepository $steps;
    private RouteEventRepository $events;
    private RouteRecipientRepository $recipients;
    private RouteEdgeRepository $edges;
    private RoutingRuleRegistry $rules;
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

        $this->rules = new RoutingRuleRegistry();
        // Wired exactly as public/index.php wires it, including #999's two extra
        // core kinds and the closure that breaks the group/registry cycle.
        $registry = $this->rules;
        $this->rules->registerCoreRoutingRules(
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
            $this->rules,
            $this->settings,
            // No HookManager: the spine emission is a side effect asserted
            // separately (testEveryAppendedEventIsAlsoBroadcastToTheSpine), and
            // a null here proves the engine does not depend on it.
            null
        );
    }

    // -- semantic 1: a step names a rule, never a person ---------------------

    public function testAStepResolvesPeopleWhoDidNotExistWhenTheRouteWasAuthored(): void
    {
        $documentId = $this->seedDocument();

        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Circular', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);

        // Two heads exist at issue time.
        self::assertSame(2, $issued['delivered'], 'the two existing heads should have been reached');

        // A THIRD department, and a head for it, created after the fact — the
        // reorganisation that a stored recipient list would silently miss.
        $this->pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at)
             VALUES (23, 1, 20, ' . $this->pdo->quote('Dept C') . ', ' . $this->pdo->quote('dept-c') . ', ' . $this->now() . ')'
        );
        $this->seedProfile(14, 'head-c');
        $this->seedMembership(1014, 14, self::TENANT, self::ROLE_HEAD, 23);

        // A SECOND route, authored identically, resolves the same rule again.
        $second = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Circular 2', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);

        self::assertSame(
            3,
            $second['delivered'],
            'the rule must include the head of a unit created after the route was designed — '
            . 'a stored recipient list would have delivered 2 and reported success'
        );
    }

    public function testARuleThatMatchesNobodyIsRecordedRatherThanFailing(): void
    {
        $documentId = $this->seedDocument();

        // A role nobody holds. Legal: the role may be held tomorrow.
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Nobody', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => 999]],
        ]);

        self::assertSame(0, $issued['resolved']);
        self::assertSame(0, $issued['delivered']);

        // And the empty distribution is VISIBLE in the trail, which is the whole
        // point: a stored list fails invisibly, this fails in a row somebody can
        // read, and the count is in the response the author gets back.
        $trail = $this->events->listForDocument($documentId, self::TENANT, 50, 0);
        self::assertCount(1, $trail);
        self::assertSame(RouteAction::ISSUED, $trail[0]['action']);
    }

    public function testRoleBelowActorResolvesRelativeToTheActorAndIsRootInclusive(): void
    {
        $documentId = $this->seedDocument();

        // The dean is in the Faculty (20), whose subtree is 20, 21, 22 — so both
        // heads are below them.
        $fromDean = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Faculty-wide', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                'rule_config' => ['role_id' => self::ROLE_HEAD],
            ],
        ]);
        self::assertSame(2, $fromDean['delivered'], 'both heads are within the dean\'s subtree');

        // Head A is in department A (21), a LEAF. Root-inclusive is what makes
        // this useful: the technician in their own department is reached. A
        // strict-descendants reading would resolve to nobody here while still
        // reporting the step complete.
        $fromHeadA = $this->router->issue(self::TENANT, self::HEAD_A, ['id' => $documentId], 'Dept-wide', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                'rule_config' => ['role_id' => self::ROLE_TECH],
            ],
        ]);
        self::assertSame(
            1,
            $fromHeadA['delivered'],
            'a leaf unit must still reach its own members — the exclusive reading resolves to nobody '
            . 'and reports success'
        );
    }

    public function testAnActorWithNoUnitResolvesToNobodyRatherThanTheWholeTenant(): void
    {
        $documentId = $this->seedDocument();

        // A profile with an active membership but no OU.
        $this->seedProfile(15, 'unitless');
        $this->seedMembership(1015, 15, self::TENANT, self::ROLE_HEAD, null);

        $issued = $this->router->issue(self::TENANT, 15, ['id' => $documentId], 'From nowhere', [
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                'rule_config' => ['role_id' => self::ROLE_HEAD],
            ],
        ]);

        self::assertSame(
            0,
            $issued['delivered'],
            'with no subtree the answer is nobody — falling back to "unscoped" would broadcast a '
            . 'document authored to stay inside one faculty to the whole institution'
        );
    }

    // -- semantic 2: distribution fans out, it does not block ----------------

    public function testOneRecipientForwardingAdvancesItsOwnChainWithoutWaitingForTheOther(): void
    {
        $documentId = $this->seedDocument();

        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Two-step', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                'rule_config' => ['role_id' => self::ROLE_TECH],
            ],
        ]);
        $route = $issued['route'];
        self::assertSame(2, $issued['delivered']);

        // Head A forwards. Head B does nothing at all.
        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);

        $rows = $this->recipients->listForDocument($documentId, self::TENANT);
        $steps = $this->steps->listForRoute((int) $route['id'], self::TENANT);
        $stepTwoId = (int) $steps[1]['id'];

        $openForB = array_values(array_filter(
            $rows,
            static fn (array $r): bool => $r['profile_id'] === self::HEAD_B && $r['closed_by_event_id'] === null
        ));
        $stepTwo = array_values(array_filter($rows, static fn (array $r): bool => $r['step_id'] === $stepTwoId));

        // THIS is the assertion a global barrier fails: step 2 has rows while a
        // step-1 recipient is still open.
        self::assertCount(1, $openForB, "head B's item must still be open — nobody acted for them");
        self::assertNotSame(
            [],
            $stepTwo,
            'step 2 must already have recipients: a barrier would hold the whole distribution for '
            . 'the slowest participant'
        );

        // And the new rows are linked to the recipient whose act produced them,
        // which is what makes the chains independent rather than merely parallel.
        foreach ($stepTwo as $row) {
            self::assertNotNull($row['parent_recipient_id']);
        }
    }

    public function testEachRecipientResolvesTheNextStepFromTheirOwnPosition(): void
    {
        $documentId = $this->seedDocument();

        // A technician in department B, so each head has exactly one technician
        // in their OWN department and they are different people.
        $this->seedProfile(16, 'tech-b');
        $this->seedMembership(1016, 16, self::TENANT, self::ROLE_TECH, self::OU_DEPT_B);

        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Per-branch', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                'rule_config' => ['role_id' => self::ROLE_TECH],
            ],
        ]);
        $route = $issued['route'];

        $fromA = $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);
        $fromB = $this->router->act(self::TENANT, self::HEAD_B, $route, RouteAction::FORWARDED, null);

        self::assertSame(1, $fromA['delivered'], 'head A reaches only their own department');
        self::assertSame(1, $fromB['delivered'], 'head B reaches only their own department');

        $stepTwoId = (int) $this->steps->listForRoute((int) $route['id'], self::TENANT)[1]['id'];
        $reached = array_map(
            static fn (array $r): int => $r['profile_id'],
            array_values(array_filter(
                $this->recipients->listForDocument($documentId, self::TENANT),
                static fn (array $r): bool => $r['step_id'] === $stepTwoId
            ))
        );
        sort($reached);

        self::assertSame(
            [self::TECH_A, 16],
            $reached,
            'the same step resolved to a DIFFERENT person per acting recipient — which is what a '
            . 'flattened recipient list cannot express'
        );
    }

    public function testTwoChainsReachingTheSamePersonProduceOneItemAndTwoTrailEvents(): void
    {
        $documentId = $this->seedDocument();

        // Step 2 is the UNSCOPED `role` rule, so it resolves to the same person
        // whoever reaches it — the simplest way to make two chains converge, and
        // it needs no second membership (migration 094's partial unique index
        // permits only one PRIMARY membership per profile per tenant, so a
        // second one would be testing the fixture rather than the engine).
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Converging', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_TECH]],
        ]);
        $route = $issued['route'];

        $fromA = $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);
        $fromB = $this->router->act(self::TENANT, self::HEAD_B, $route, RouteAction::FORWARDED, null);

        // Both chains RESOLVED to them; only the first DELIVERED a row. Reporting
        // both numbers is what lets an author tell "the rule found nobody" from
        // "the rule found somebody who already had it".
        self::assertSame(1, $fromA['resolved']);
        self::assertSame(1, $fromA['delivered']);
        self::assertSame(1, $fromB['resolved'], "head B's rule still resolved to the technician");
        self::assertSame(
            0,
            $fromB['delivered'],
            'the second chain must not open a second item — a person acts once, and the partial '
            . 'unique index on OPEN rows is what enforces it'
        );

        $stepTwoId = (int) $this->steps->listForRoute((int) $route['id'], self::TENANT)[1]['id'];
        $theirRows = array_values(array_filter(
            $this->recipients->listForDocument($documentId, self::TENANT),
            static fn (array $r): bool => $r['profile_id'] === self::TECH_A && $r['step_id'] === $stepTwoId
        ));
        self::assertCount(1, $theirRows, 'one inbox item, not two');

        // But the TRAIL records BOTH forwards in full: de-duplicating the inbox
        // is not the same as editing history, and "head B also sent this on" is a
        // fact somebody may need.
        $forwards = array_values(array_filter(
            $this->events->listForDocument($documentId, self::TENANT, 50, 0),
            static fn (array $e): bool => $e['action'] === RouteAction::FORWARDED
        ));
        self::assertCount(2, $forwards, 'both forwards are in the trail');
        $actors = array_map(static fn (array $e): ?int => $e['actor_profile_id'], $forwards);
        sort($actors);
        self::assertSame([self::HEAD_A, self::HEAD_B], $actors);
    }

    public function testForwardingFromTheLastStepIsRefusedRatherThanSilentlyEndingTheChain(): void
    {
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'One-step', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);

        $this->expectException(RoutingRejectedException::class);
        $this->expectExceptionMessageMatches('/last step/i');
        $this->router->act(self::TENANT, self::HEAD_A, $issued['route'], RouteAction::FORWARDED, null);
    }

    public function testReturningOpensANewRowForThePredecessorAndLeavesTheirActionRecorded(): void
    {
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Returnable', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                'rule_config' => ['role_id' => self::ROLE_TECH],
            ],
        ]);
        $route = $issued['route'];

        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::FORWARDED, null);
        $headARowsBefore = $this->rowsFor($documentId, self::HEAD_A);
        self::assertCount(1, $headARowsBefore);
        self::assertNotNull($headARowsBefore[0]['closed_by_event_id'], 'head A acted, so their row is closed');

        // The technician sends it back.
        $this->router->act(self::TENANT, self::TECH_A, $route, RouteAction::RETURNED, 'Wrong figure');

        $headARowsAfter = $this->rowsFor($documentId, self::HEAD_A);
        self::assertCount(
            2,
            $headARowsAfter,
            'a return must open a NEW row rather than clear the old one — clearing it would erase '
            . 'the fact that head A acted'
        );
        self::assertNotNull(
            $headARowsAfter[0]['closed_by_event_id'],
            "the original row stays closed: what head A did is the trail's business, not the inbox's to overwrite"
        );
        self::assertNull($headARowsAfter[1]['closed_by_event_id'], 'the reopened row is open');
    }

    public function testReturningFromTheFirstStepIsRefused(): void
    {
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'No predecessor', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);

        $this->expectException(RoutingRejectedException::class);
        $this->expectExceptionMessageMatches('/first step/i');
        $this->router->act(self::TENANT, self::HEAD_A, $issued['route'], RouteAction::RETURNED, null);
    }

    // -- semantic 3: the trail is append-only -------------------------------

    public function testTheTrailRepositoryExposesNoUpdateOrDeletePath(): void
    {
        // Structural, not behavioural: the guarantee is that there is nothing to
        // call. A behavioural test can only prove that the paths which exist
        // behave well; this proves the dangerous ones are absent.
        $methods = get_class_methods(RouteEventRepository::class);
        sort($methods);

        self::assertSame(
            [
                '__construct',
                'append',
                'countForDocument',
                'findById',
                'listForDocument',
                // #1037. A READ: `SELECT … GROUP BY step_id`, deriving the lap
                // count from the verdict rows already in the trail. Listed here
                // deliberately rather than the assertion being loosened to
                // "nothing matching /^(update|delete)/" — an exact list is what
                // makes a new method a decision somebody makes on purpose, and a
                // pattern match would have admitted `markCorrected()` silently.
                'rejectionCountsByStep',
            ],
            $methods,
            'RouteEventRepository must expose exactly one write (append) and reads. A store that '
            . 'offers an UPDATE is a store where somebody eventually calls it.'
        );
    }

    public function testACorrectionIsANewEventAndTheOriginalTextSurvives(): void
    {
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Notable', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);
        $route = $issued['route'];

        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::NOTED, 'Approved by Jhon');
        $this->router->act(self::TENANT, self::HEAD_A, $route, RouteAction::NOTED, 'Correction: approved by John');

        $notes = array_values(array_filter(
            $this->events->listForDocument($documentId, self::TENANT, 50, 0),
            static fn (array $e): bool => $e['action'] === RouteAction::NOTED
        ));

        self::assertCount(2, $notes, 'a correction ADDS a row');
        self::assertSame('Approved by Jhon', $notes[0]['note'], 'the misspelling survives — that is the point');
        self::assertSame('Correction: approved by John', $notes[1]['note']);
    }

    public function testANoteDoesNotCloseTheAuthorsOpenItem(): void
    {
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Still mine', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);

        $this->router->act(self::TENANT, self::HEAD_A, $issued['route'], RouteAction::NOTED, 'A remark');

        $rows = $this->rowsFor($documentId, self::HEAD_A);
        self::assertCount(1, $rows);
        self::assertNull($rows[0]['closed_by_event_id'], 'a note records something; it does not answer the item');
    }

    public function testAnEmptyNoteIsRefused(): void
    {
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Empty', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);

        $this->expectException(RoutingRejectedException::class);
        $this->router->act(self::TENANT, self::HEAD_A, $issued['route'], RouteAction::NOTED, '   ');
    }

    // -- the recipient row holds no state of its own ------------------------

    public function testTheInboxStatusIsReadFromTheTrailRatherThanStoredBesideIt(): void
    {
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Pointers', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
            [
                'rule_kind' => RoutingRuleRegistry::KIND_ROLE_BELOW_ACTOR,
                'rule_config' => ['role_id' => self::ROLE_TECH],
            ],
        ]);
        $this->router->act(self::TENANT, self::HEAD_A, $issued['route'], RouteAction::FORWARDED, null);

        $inbox = $this->recipients->listForProfile(self::TENANT, self::TECH_A, true, 25, 0);
        self::assertCount(1, $inbox);
        self::assertSame(
            RouteAction::FORWARDED,
            $inbox[0]['arrived_by'],
            'the qualifier a person reads is the ACTION of the creating trail event, joined through '
            . 'created_by_event_id — so it cannot disagree with the trail'
        );

        // And the pointer really does resolve to that event.
        $event = $this->events->findById((int) $inbox[0]['created_by_event_id'], self::TENANT);
        self::assertNotNull($event);
        self::assertSame(RouteAction::FORWARDED, $event['action']);
    }

    // -- security: a resolver cannot escape its tenant ---------------------

    public function testAPluginResolverCannotPlaceADocumentInAnotherTenantsInbox(): void
    {
        $documentId = $this->seedDocument();

        // A hostile (or merely buggy) resolver returning a profile from another
        // tenant, and one that does not exist at all.
        $this->rules->register('acme', [
            'hostile' => new class implements RoutingRuleResolverInterface {
                public function label(): string
                {
                    return 'Hostile';
                }

                public function validate(array $config): void
                {
                }

                public function resolve(RoutingRuleContext $context): array
                {
                    return [
                        new ResolvedRecipient(DocumentRouterRealEngineTest::outsiderId()),
                        new ResolvedRecipient(999999),
                        new ResolvedRecipient(DocumentRouterRealEngineTest::headAId()),
                    ];
                }
            },
        ]);

        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Hostile', [
            ['rule_kind' => 'acme:hostile'],
        ]);

        self::assertSame(
            1,
            $issued['delivered'],
            'only the legitimate tenant member is delivered to: the host filters a resolver\'s answer '
            . 'against the tenant\'s own active memberships before any row exists'
        );

        $reached = array_map(
            static fn (array $r): int => $r['profile_id'],
            $this->recipients->listForDocument($documentId, self::TENANT)
        );
        self::assertSame([self::HEAD_A], $reached);
        self::assertSame(
            [],
            $this->recipients->listForProfile(self::OTHER_TENANT, self::OUTSIDER, true, 25, 0),
            "the other tenant's member has nothing in their inbox"
        );
    }

    public function testAResolverThrowingAtRunTimeRefusesTheActAndWritesNothing(): void
    {
        $documentId = $this->seedDocument();

        $this->rules->register('boom', [
            'broken' => new class implements RoutingRuleResolverInterface {
                public function label(): string
                {
                    return 'Broken';
                }

                public function validate(array $config): void
                {
                }

                public function resolve(RoutingRuleContext $context): array
                {
                    throw new \RuntimeException('internal detail nobody should see');
                }
            },
        ]);

        try {
            $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Broken', [
                ['rule_kind' => 'boom:broken'],
            ]);
            self::fail('a resolver throwing at resolve time must refuse the act');
        } catch (RoutingRejectedException $e) {
            self::assertStringNotContainsString(
                'internal detail',
                $e->clientMessage,
                "a run-time failure is plugin code misbehaving, not a message for the caller"
            );
            self::assertStringContainsString('boom:broken', $e->clientMessage, 'the kind IS named');
        }

        // Nothing was committed: a half-resolved distribution is worse than a
        // refused one.
        self::assertSame([], $this->events->listForDocument($documentId, self::TENANT, 50, 0));
        self::assertSame([], $this->recipients->listForDocument($documentId, self::TENANT));
    }

    public function testAStepNamingAnUnregisteredKindFailsLoudlyAndByName(): void
    {
        $documentId = $this->seedDocument();

        try {
            $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Gone', [
                ['rule_kind' => 'acme:committee'],
            ]);
            self::fail('an unregistered kind must be refused');
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('acme:committee', $e->clientMessage);
        }
    }

    // -- ceilings are refusals, never truncations --------------------------

    public function testExceedingTheRecipientCeilingIsRefusedRatherThanTruncated(): void
    {
        $documentId = $this->seedDocument();
        // A ceiling of one, with two heads holding the role.
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_ROUTING_MAX_RECIPIENTS_PER_STEP, '1');

        try {
            $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Too many', [
                ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
            ]);
            self::fail('the ceiling must refuse');
        } catch (RoutingRejectedException $e) {
            // The NUMBER is named, because it is tenant-configurable and
            // therefore unknowable from outside.
            self::assertStringContainsString('2 recipients', $e->clientMessage);
            self::assertStringContainsString('1', $e->clientMessage);
        }

        // And nothing was delivered — the failure mode this guards against is
        // delivering to the first N and reporting success.
        self::assertSame([], $this->recipients->listForDocument($documentId, self::TENANT));
    }

    public function testExceedingTheStepCeilingIsRefusedBeforeAnythingIsWritten(): void
    {
        $documentId = $this->seedDocument();
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_ROUTING_MAX_STEPS, '2');

        $step = ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]];

        try {
            $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Too long', [$step, $step, $step]);
            self::fail('the step ceiling must refuse');
        } catch (RoutingRejectedException $e) {
            self::assertStringContainsString('3 steps', $e->clientMessage);
        }

        self::assertSame([], $this->events->listForDocument($documentId, self::TENANT, 50, 0));
    }

    public function testAPerTenantCeilingOverridesTheGlobalOne(): void
    {
        $documentId = $this->seedDocument();
        $this->settings->setGlobal(SettingsRegistry::DOCUMENTS_ROUTING_MAX_RECIPIENTS_PER_STEP, '1');
        // Per-tenant ?? global ?? registry default — never hardcoded.
        $this->settings->setTenant(self::TENANT, SettingsRegistry::DOCUMENTS_ROUTING_MAX_RECIPIENTS_PER_STEP, '5');

        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Allowed', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);

        self::assertSame(2, $issued['delivered'], 'the tenant override must win over the global ceiling');
    }

    public function testARouteWithNoStepsIsRefused(): void
    {
        $documentId = $this->seedDocument();

        $this->expectException(RoutingRejectedException::class);
        $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Empty', []);
    }

    // -- tenant isolation ---------------------------------------------------

    public function testARouteIsInvisibleAndUnactionableFromAnotherTenant(): void
    {
        $documentId = $this->seedDocument();
        $issued = $this->router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Mine', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);
        $routeId = (int) $issued['route']['id'];

        $routes = new RouteRepository($this->pdo);
        self::assertNull(
            $routes->findById($routeId, self::OTHER_TENANT),
            'a route id from another tenant must resolve to null, not to a row'
        );
        self::assertSame([], $this->events->listForDocument($documentId, self::OTHER_TENANT, 50, 0));
        self::assertSame([], $this->recipients->listForDocument($documentId, self::OTHER_TENANT));
        self::assertNull(
            $this->recipients->findOpenForProfile(self::OTHER_TENANT, $routeId, self::HEAD_A),
            'the open-item lookup that authorises acting must be tenant-bound'
        );
    }

    // -- the spine broadcast ------------------------------------------------

    public function testEveryAppendedEventIsAlsoBroadcastToTheSpine(): void
    {
        $documentId = $this->seedDocument();

        // A HookManager that records instead of persisting. Subclassing the real
        // one rather than a mock, so the method signature the engine calls is the
        // real signature: a mock would keep passing if dispatchAsync's shape
        // changed underneath it.
        $hooks = new class extends \Whity\Core\Hooks\HookManager {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $seen = [];

            public function dispatchAsync(string $eventName, array $payload): void
            {
                $this->seen[] = [$eventName, $payload];
            }
        };

        $router = new DocumentRouter(
            $this->pdo,
            new RouteRepository($this->pdo),
            $this->steps,
            $this->events,
            $this->recipients,
            $this->edges,
            $this->rules,
            $this->settings,
            $hooks
        );

        $issued = $router->issue(self::TENANT, self::DEAN, ['id' => $documentId], 'Broadcast', [
            ['rule_kind' => RoutingRuleRegistry::KIND_ROLE, 'rule_config' => ['role_id' => self::ROLE_HEAD]],
        ]);
        $router->act(self::TENANT, self::HEAD_A, $issued['route'], RouteAction::ACKNOWLEDGED, null);

        $names = array_map(static fn (array $e): string => $e[0], $hooks->seen);
        self::assertSame(['document.routed.async', 'document.route_acted.async'], $names);

        // The aggregate is the DOCUMENT, because a routing event is something
        // that happened to a document and that is what a consumer watches.
        foreach ($hooks->seen as [$name, $payload]) {
            self::assertSame($documentId, $payload['id'], 'the aggregate id is the document');
            self::assertSame(self::TENANT, $payload['tenant_id']);
        }
    }

    // -- helpers ------------------------------------------------------------

    public static function outsiderId(): int
    {
        return self::OUTSIDER;
    }

    public static function headAId(): int
    {
        return self::HEAD_A;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rowsFor(int $documentId, int $profileId): array
    {
        return array_values(array_filter(
            $this->recipients->listForDocument($documentId, self::TENANT),
            static fn (array $r): bool => $r['profile_id'] === $profileId
        ));
    }

    private function seedDocument(): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Circular') . ', ' . $this->pdo->quote('Q3 circular') . ', 20, 10, ' . $this->now() . ')'
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

    /**
     * `NOW()` on PostgreSQL, `datetime('now')` on SQLite. The seed helpers write
     * literal SQL (they are fixtures, not production paths), so the one dialect
     * difference they touch is spelled once here rather than in every insert.
     */
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
        $pdo->exec('INSERT INTO tenants (id, name) VALUES (2, ' . $quote('Tenant Two') . ') ON CONFLICT DO NOTHING');

        // Faculty (20) -> Dept A (21), Dept B (22).
        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Faculty') . ', ' . $quote('faculty') . ', ' . $now . '),
                (21, 1, 20,   ' . $quote('Dept A') . ',  ' . $quote('dept-a') . ',  ' . $now . '),
                (22, 1, 20,   ' . $quote('Dept B') . ',  ' . $quote('dept-b') . ',  ' . $now . ')'
        );

        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('dean') . ', ' . $quote('') . ', 1, ' . $now . '),
                (101, ' . $quote('head') . ', ' . $quote('') . ', 1, ' . $now . '),
                (102, ' . $quote('technician') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );

        foreach ([[10, 'dean'], [11, 'head-a'], [12, 'head-b'], [13, 'tech-a'], [20, 'outsider']] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, ' . $now . ', ' . $now . ')'
            );
        }

        $pdo->exec(
            "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1010, 10, 1, " . self::ROLE_DEAN . ", 20, true, 'active', {$now}),
                (1011, 11, 1, 101, 21,   true, 'active', {$now}),
                (1012, 12, 1, 101, 22,   true, 'active', {$now}),
                (1013, 13, 1, 102, 21,   true, 'active', {$now}),
                (1020, 20, 2, 101, NULL, true, 'active', {$now})"
        );

        return $pdo;
    }
}
