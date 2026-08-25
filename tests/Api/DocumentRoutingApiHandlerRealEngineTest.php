<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DocumentRoutingApiHandler;
use Whity\Api\MeInboxApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\DocumentRoutingInboxSource;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Inbox\InboxSourceRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * Real-engine tests for {@see DocumentRoutingApiHandler} and
 * {@see MeInboxApiHandler} (#947 item 3).
 *
 * Four things worth failing a build over:
 *
 *  1. THE VISIBILITY WIDENING WORKS, AND ONLY AS FAR AS IT SHOULD. A person a
 *     route reached can read the document; a colleague it did not reach still
 *     cannot, and is told it does not exist rather than that it is forbidden.
 *     This is the disjunct migration 108 left a home for, and getting it too
 *     wide would publish a tenant's output to everybody holding `documents:read`.
 *
 *  2. ACTING IS SELF-SERVICE, AND BOUNDED BY THE ROW. Being a recipient IS the
 *     authorization, so no permission is required — but somebody with no open
 *     item is refused, which is what stops "unpermissioned" meaning "anybody".
 *
 *  3. RECIPIENTS ARE AN #881 INBOX SOURCE, NOT A SURFACE. The items come back
 *     through the registry, in the field shape the `inbox` block declares, and
 *     an unsourced request is REFUSED rather than defaulted — because defaulting
 *     would silently become wrong the day a second source registers.
 *
 *  4. TENANT ISOLATION at every route, including the two that take a route id in
 *     the path.
 */
final class DocumentRoutingApiHandlerRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    private const DEAN = 10;      // raiser; holds documents:route + read
    private const HEAD_A = 11;    // reached by the route
    private const HEAD_B = 12;    // reached by the route
    private const BYSTANDER = 14; // holds documents:read, never reached
    private const OUTSIDER = 20;  // another tenant

    private const ROLE_DEAN = 100;
    private const ROLE_HEAD = 101;

    private PDO $pdo;
    private DocumentRoutingApiHandler $handler;
    private RouteRecipientRepository $recipients;
    private RouteTemplateRepository $templates;
    private RouteTemplateGraph $templateGraph;
    private SettingsService $settings;
    private InboxSourceRegistry $inboxSources;
    private int $documentId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapDb($this->pdo);

        $documents = new DocumentRepository($this->pdo);
        $routes = new RouteRepository($this->pdo);
        $steps = new RouteStepRepository($this->pdo);
        $events = new RouteEventRepository($this->pdo);
        $this->recipients = new RouteRecipientRepository($this->pdo);

        $rules = new RoutingRuleRegistry();
        // Wired exactly as public/index.php wires it, including #999's two extra
        // core kinds and the closure that breaks the group/registry cycle. A
        // stub here would let a route pass in tests that could not be authored
        // in production.
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver(new GroupResolver(
                $this->pdo,
                new UserGroupRepository($this->pdo),
                static fn (): RoutingRuleRegistry => $rules
            ))
        );

        $settings = $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $edges = new RouteEdgeRepository($this->pdo);

        $visibility = new DocumentVisibilityPolicy(
            $this->recipients,
            new ResourceRoleAssignmentRepository($this->pdo, new ResourceTypeRegistry())
        );

        $this->handler = new DocumentRoutingApiHandler(
            $documents,
            $routes,
            $steps,
            $events,
            $this->recipients,
            $edges,
            new DocumentRouter(
                $this->pdo,
                $routes,
                $steps,
                $events,
                $this->recipients,
                $edges,
                $rules,
                $settings,
                null
            ),
            $rules,
            $visibility,
            new RoleChecker($db, new PermissionRegistry()),
            // #1031. The same store the templates surface uses - a stub here
            // would let this file assert against a design production could not
            // have saved.
            $this->templates = new RouteTemplateRepository($this->pdo)
        );
        $this->templateGraph = new RouteTemplateGraph($rules);

        // Routing's recipients register as a SOURCE, exactly as public/index.php
        // wires them — the test reads the inbox the way production does, through
        // the registry, so a regression that bypassed it would fail here.
        $this->inboxSources = new InboxSourceRegistry();
        $this->inboxSources->registerCoreSource(
            InboxSourceRegistry::CORE_DOCUMENT_ROUTING,
            new DocumentRoutingInboxSource($this->recipients)
        );

        $this->documentId = $this->seedDocument();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // -- issuing ------------------------------------------------------------

    public function testIssuingARouteReturnsTheStepsAndTheResolvedCounts(): void
    {
        $response = $this->issueRoute();

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);

        self::assertSame($this->documentId, $body['data']['document_id']);
        self::assertCount(1, $body['data']['steps']);
        self::assertSame('role', $body['data']['steps'][0]['rule_kind']);
        self::assertSame(1, $body['data']['steps'][0]['position']);
        // Both counts, so an author can tell "the rule found nobody" from "the
        // rule found people who already had it".
        self::assertSame(2, $body['resolved']);
        self::assertSame(2, $body['delivered']);
        // The tenant is never echoed back.
        self::assertArrayNotHasKey('tenant_id', $body['data']);
    }

    public function testAStepNamingAnUnregisteredRuleIsA422ThatNamesTheKind(): void
    {
        $response = $this->post("/api/documents/{$this->documentId}/routes", self::DEAN, [
            'steps' => [['rule_kind' => 'acme:committee']],
        ], ['id' => (string) $this->documentId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('acme:committee', $this->json($response)['error']);
    }

    public function testAConfigTheRuleRefusesIsA422CarryingTheRulesOwnMessage(): void
    {
        $response = $this->post("/api/documents/{$this->documentId}/routes", self::DEAN, [
            // `role` needs a role_id; the resolver says so, and the text reaches
            // the author verbatim.
            'steps' => [['rule_kind' => 'role', 'rule_config' => []]],
        ], ['id' => (string) $this->documentId]);

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertStringContainsString('Step 1', $error, 'the failing step is named');
        self::assertStringContainsString('role_id', $error);
    }

    public function testStepsMustBeAJsonArrayRatherThanAnObject(): void
    {
        // A JSON object with numeric-ish keys decodes to an associative array and
        // the engine indexes positions from ORDER, so re-indexing silently would
        // give an author steps in an order they did not choose.
        $response = $this->post("/api/documents/{$this->documentId}/routes", self::DEAN, [
            'steps' => ['1' => ['rule_kind' => 'role', 'rule_config' => ['role_id' => self::ROLE_HEAD]]],
        ], ['id' => (string) $this->documentId]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('array', $this->json($response)['error']);
    }

    public function testTheRouteTitleFallsBackToTheDocumentsOwn(): void
    {
        $response = $this->post("/api/documents/{$this->documentId}/routes", self::DEAN, [
            'steps' => [['rule_kind' => 'role', 'rule_config' => ['role_id' => self::ROLE_HEAD]]],
        ], ['id' => (string) $this->documentId]);

        self::assertSame('Q3 circular', $this->json($response)['data']['title']);
    }

    // -- the visibility widening -------------------------------------------

    public function testARecipientCanReadTheTrailOfADocumentTheyDidNotRaise(): void
    {
        $this->issueRoute();

        // HEAD_A holds documents:read but did NOT raise this document. Before
        // item 3 they would have got a 404.
        $response = $this->get("/api/documents/{$this->documentId}/trail", self::HEAD_A, ['id' => (string) $this->documentId]);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertCount(1, $body['data']);
        self::assertSame(RouteAction::ISSUED, $body['data'][0]['action']);
    }

    public function testAColleagueTheRouteNeverReachedStillCannotSeeIt(): void
    {
        $this->issueRoute();

        // BYSTANDER holds documents:read and nothing else. The widening must not
        // have turned "may use the designer" into "may read the tenant's output".
        foreach (['trail', 'routes', 'recipients'] as $sub) {
            $response = $this->get(
                "/api/documents/{$this->documentId}/{$sub}",
                self::BYSTANDER,
                ['id' => (string) $this->documentId]
            );
            self::assertSame(404, $response->getStatusCode(), "{$sub} must be invisible");
            // 404, not 403: a 403 confirms the id exists.
            self::assertStringContainsString('not found', $this->json($response)['error']);
        }
    }

    public function testARecipientKeepsAccessAfterActing(): void
    {
        $issued = $this->json($this->issueRoute());
        $routeId = $issued['data']['id'];

        $this->post(
            "/api/documents/{$this->documentId}/routes/{$routeId}/actions",
            self::HEAD_A,
            ['action' => RouteAction::ACKNOWLEDGED],
            ['id' => (string) $this->documentId, 'routeId' => (string) $routeId]
        );

        // "I no longer have it in my inbox" is not "I was never sent it" — and
        // the only way to amend an append-only trail is to add a note to it.
        $response = $this->get("/api/documents/{$this->documentId}/trail", self::HEAD_A, ['id' => (string) $this->documentId]);
        self::assertSame(200, $response->getStatusCode());
    }

    // -- acting -------------------------------------------------------------

    public function testActingNeedsNoPermissionButDoesNeedAnOpenItem(): void
    {
        $issued = $this->json($this->issueRoute());
        $routeId = $issued['data']['id'];
        $params = ['id' => (string) $this->documentId, 'routeId' => (string) $routeId];

        // HEAD_A holds no `documents:route` and acts anyway: being a recipient
        // IS the authorization.
        $ok = $this->post(
            "/api/documents/{$this->documentId}/routes/{$routeId}/actions",
            self::HEAD_A,
            ['action' => RouteAction::ACKNOWLEDGED],
            $params
        );
        self::assertSame(201, $ok->getStatusCode());
        self::assertSame(RouteAction::ACKNOWLEDGED, $this->json($ok)['data']['action']);

        // Acting twice is refused: the row, not a permission, is the bound.
        $again = $this->post(
            "/api/documents/{$this->documentId}/routes/{$routeId}/actions",
            self::HEAD_A,
            ['action' => RouteAction::ACKNOWLEDGED],
            $params
        );
        self::assertSame(422, $again->getStatusCode());
        self::assertStringContainsString('no open item', $this->json($again)['error']);
    }

    public function testAnUnknownActionIsRefusedWithTheAvailableVerbsNamed(): void
    {
        $issued = $this->json($this->issueRoute());
        $routeId = $issued['data']['id'];

        $response = $this->post(
            "/api/documents/{$this->documentId}/routes/{$routeId}/actions",
            self::HEAD_A,
            ['action' => 'deleted'],
            ['id' => (string) $this->documentId, 'routeId' => (string) $routeId]
        );

        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        foreach ([RouteAction::FORWARDED, RouteAction::ACKNOWLEDGED, RouteAction::RETURNED, RouteAction::NOTED] as $verb) {
            self::assertStringContainsString($verb, $error);
        }
        self::assertStringNotContainsString(
            RouteAction::ISSUED,
            $error,
            'a recipient may not mint a second beginning for a circulation already under way'
        );
    }

    public function testAnOverlongNoteIsRefusedBecauseTheTrailCannotBeEdited(): void
    {
        $issued = $this->json($this->issueRoute());
        $routeId = $issued['data']['id'];

        $response = $this->post(
            "/api/documents/{$this->documentId}/routes/{$routeId}/actions",
            self::HEAD_A,
            ['action' => RouteAction::NOTED, 'note' => str_repeat('x', 4001)],
            ['id' => (string) $this->documentId, 'routeId' => (string) $routeId]
        );

        self::assertSame(422, $response->getStatusCode());
    }

    public function testARouteIdFromAnotherDocumentIsNotFound(): void
    {
        $issued = $this->json($this->issueRoute());
        $routeId = $issued['data']['id'];

        // A second document, and the first document's route id aimed at it.
        $otherDocumentId = $this->seedDocument();

        $response = $this->post(
            "/api/documents/{$otherDocumentId}/routes/{$routeId}/actions",
            self::HEAD_A,
            ['action' => RouteAction::ACKNOWLEDGED],
            ['id' => (string) $otherDocumentId, 'routeId' => (string) $routeId]
        );

        self::assertSame(404, $response->getStatusCode(), 'a route id alone is never a capability');
    }

    // -- tenant isolation ---------------------------------------------------

    public function testEveryRoutingRouteIsInvisibleFromAnotherTenant(): void
    {
        $issued = $this->json($this->issueRoute());
        $routeId = $issued['data']['id'];

        // TenantContext locks once set (it is a per-request singleton), so the
        // switch has to go through reset() — the same thing the middleware does
        // between requests. Doing it here rather than in a second test class
        // keeps the cross-tenant assertion beside the route that must refuse it.
        TenantContext::reset();
        TenantContext::setTenantId(self::OTHER_TENANT);

        foreach (['trail', 'routes', 'recipients'] as $sub) {
            $response = $this->get(
                "/api/documents/{$this->documentId}/{$sub}",
                self::OUTSIDER,
                ['id' => (string) $this->documentId]
            );
            self::assertSame(404, $response->getStatusCode(), "{$sub} must be invisible cross-tenant");
        }

        $act = $this->post(
            "/api/documents/{$this->documentId}/routes/{$routeId}/actions",
            self::OUTSIDER,
            ['action' => RouteAction::ACKNOWLEDGED],
            ['id' => (string) $this->documentId, 'routeId' => (string) $routeId]
        );
        self::assertSame(404, $act->getStatusCode());
    }

    // -- the rule catalogue -------------------------------------------------

    public function testTheRuleCatalogueListsWhatAStepMayName(): void
    {
        $response = $this->get('/api/routing-rules', self::DEAN, []);

        self::assertSame(200, $response->getStatusCode());
        $kinds = array_column($this->json($response)['data'], 'kind');
        // #999 added two: `explicit` (the enumerated case, as a rule rather than
        // as an exception to rules) and `group` (dereference a named user group).
        // A route step may name any of the four. The GROUP catalogue is narrower
        // — see RoutingRuleRegistryTest — because `group` may not define a group.
        self::assertSame(['explicit', 'group', 'role', 'role_below_actor'], $kinds);
    }

    // -- the inbox (#881) ---------------------------------------------------

    public function testTheInboxReadsThroughTheSourceRegistryInTheBlocksFieldShape(): void
    {
        $this->issueRoute();

        $response = $this->inboxRequest(self::HEAD_A, '?source=' . InboxSourceRegistry::CORE_DOCUMENT_ROUTING);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->json($response);
        self::assertSame(InboxSourceRegistry::CORE_DOCUMENT_ROUTING, $body['source']);
        self::assertCount(1, $body['data']);

        $item = $body['data'][0];
        // Exactly the fields the `inbox` block type declares (#868), so a screen
        // can point a block straight at this endpoint.
        foreach (['id', 'title', 'subtitle', 'timestamp', 'status', 'resource_type', 'resource_id'] as $field) {
            self::assertArrayHasKey($field, $item);
        }
        self::assertSame('Q3 circular', $item['title']);
        self::assertSame('Awaiting you', $item['status']);
        // The resource is the DOCUMENT, because that is what a per-record
        // permission is granted on — not the assignment row.
        self::assertSame(ResourceTypeRegistry::TYPE_DOCUMENT, $item['resource_type']);
        self::assertSame((string) $this->documentId, $item['resource_id']);
        self::assertIsString($item['id'], 'the id is a string: the aggregate will mix non-integer sources');
    }

    public function testAnUnsourcedInboxRequestIsRefusedRatherThanDefaulted(): void
    {
        $this->issueRoute();

        $response = $this->inboxRequest(self::HEAD_A, '');

        // Defaulting to the only source would silently become wrong the day a
        // second one registers, and the caller would have no way to notice.
        self::assertSame(422, $response->getStatusCode());
        $error = $this->json($response)['error'];
        self::assertStringContainsString('source', $error);
        self::assertStringContainsString(InboxSourceRegistry::CORE_DOCUMENT_ROUTING, $error);
    }

    public function testAnUnknownSourceIsA422NamingTheRegisteredOnes(): void
    {
        $response = $this->inboxRequest(self::HEAD_A, '?source=acme_approvals');

        // 422 rather than an empty 200: an empty list reads as "you have no
        // items", which is the wrong answer to a typo.
        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(InboxSourceRegistry::CORE_DOCUMENT_ROUTING, $this->json($response)['error']);
    }

    public function testTheInboxIsOpenItemsOnlyUnlessHistoryIsAskedForExplicitly(): void
    {
        $issued = $this->json($this->issueRoute());
        $routeId = $issued['data']['id'];

        $this->post(
            "/api/documents/{$this->documentId}/routes/{$routeId}/actions",
            self::HEAD_A,
            ['action' => RouteAction::ACKNOWLEDGED],
            ['id' => (string) $this->documentId, 'routeId' => (string) $routeId]
        );

        $open = $this->inboxRequest(self::HEAD_A, '?source=' . InboxSourceRegistry::CORE_DOCUMENT_ROUTING);
        self::assertSame([], $this->json($open)['data'], 'an answered item leaves the inbox');

        $all = $this->inboxRequest(self::HEAD_A, '?source=' . InboxSourceRegistry::CORE_DOCUMENT_ROUTING . '&open=0');
        $items = $this->json($all)['data'];
        self::assertCount(1, $items, 'the history is still readable when asked for');
        self::assertSame('Done', $items[0]['status']);
    }

    public function testTheSourceCatalogueCarriesTheBlockFieldMappingAndTheOpenCount(): void
    {
        $this->issueRoute();

        $response = $this->inboxSourcesRequest(self::HEAD_A);

        self::assertSame(200, $response->getStatusCode());
        $sources = $this->json($response)['data'];
        self::assertCount(1, $sources);
        self::assertSame(InboxSourceRegistry::CORE_DOCUMENT_ROUTING, $sources[0]['key']);
        self::assertSame('core', $sources[0]['origin']);
        self::assertSame(1, $sources[0]['open_count']);
        // Published rather than left for each client to hardcode.
        self::assertSame('id', $sources[0]['item_fields']['idField']);
        self::assertSame('title', $sources[0]['item_fields']['titleField']);
        self::assertSame('status', $sources[0]['item_fields']['statusField']);
    }

    public function testAnInboxIsStrictlyTheCallersOwn(): void
    {
        $this->issueRoute();

        // HEAD_B was reached by the same route; HEAD_A's inbox must not show it.
        $a = $this->json($this->inboxRequest(self::HEAD_A, '?source=' . InboxSourceRegistry::CORE_DOCUMENT_ROUTING));
        $b = $this->json($this->inboxRequest(self::HEAD_B, '?source=' . InboxSourceRegistry::CORE_DOCUMENT_ROUTING));

        self::assertCount(1, $a['data']);
        self::assertCount(1, $b['data']);
        self::assertNotSame($a['data'][0]['id'], $b['data'][0]['id']);

        // And the raiser, who is not a recipient, has nothing.
        $dean = $this->json($this->inboxRequest(self::DEAN, '?source=' . InboxSourceRegistry::CORE_DOCUMENT_ROUTING));
        self::assertSame([], $dean['data']);
    }

    // -- helpers ------------------------------------------------------------

    // -- applying a route template (#1031) ------------------------------------

    public function testApplyingATemplateIssuesItsStagesAndRecordsWhereTheyCameFrom(): void
    {
        $templateId = $this->seedTemplate('Purchase approval');

        $response = $this->post(
            "/api/documents/{$this->documentId}/routes/from-template",
            self::DEAN,
            ['template_id' => $templateId],
            ['id' => (string) $this->documentId]
        );

        self::assertSame(201, $response->getStatusCode());
        $body = $this->json($response);
        /** @var array<string, mixed> $data */
        $data = $body['data'];

        self::assertSame($templateId, $data['template_id']);
        self::assertSame('Purchase approval', $data['template_name']);
        self::assertSame(
            'Purchase approval',
            $data['title'],
            'left unnamed, a circulation is named after the DESIGN it follows rather than the document'
        );
        /** @var list<array<string, mixed>> $steps */
        $steps = $data['steps'];
        self::assertCount(2, $steps);
        self::assertTrue($steps[1]['decision'], 'the gate must arrive as a gate');
        self::assertSame([self::HEAD_A, self::HEAD_B], $this->reachedProfiles());
    }

    public function testTheAppliedRouteReportsTheTENANTSQuorumAndNotTheHardcodedDefault(): void
    {
        // #1041 publishes `default_quorum` on a route so a recipient standing on
        // a gate with no quorum of its own can be told what their approval
        // actually does — the settings chain that holds the answer is behind
        // `settings:read`, which that person will not hold.
        //
        // An APPLIED design is the route most likely to need it: the canvas
        // leaves `decision_quorum` NULL by default, so nearly every gate a
        // template produces defers to the tenant. Omitting the argument on this
        // one call site is invisible on a stock install — the presenter's own
        // fallback is `all`, which is also the registry default — and wrong on
        // the first tenant that changed the setting, in the direction that tells
        // an approver their single approval carries a gate it does not.
        //
        // So the tenant is moved OFF the default before anything is asserted;
        // against `all` this test could not fail.
        $this->settings->setTenant(
            self::TENANT,
            SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM,
            RouteQuorum::ANY
        );
        $templateId = $this->seedTemplate('Quorum check');

        $applied = $this->post(
            "/api/documents/{$this->documentId}/routes/from-template",
            self::DEAN,
            ['template_id' => $templateId],
            ['id' => (string) $this->documentId]
        );

        self::assertSame(201, $applied->getStatusCode());
        self::assertSame(
            RouteQuorum::ANY,
            $this->json($applied)['data']['default_quorum'],
            "the reply must carry the ENGINE resolved answer, not the presenter fallback"
        );

        // And the read agrees with the write, which is the property that makes
        // the field worth publishing at all.
        $read = $this->get(
            "/api/documents/{$this->documentId}/routes",
            self::DEAN,
            ['id' => (string) $this->documentId]
        );
        self::assertSame(RouteQuorum::ANY, $this->json($read)['data'][0]['default_quorum']);
    }

    public function testAHandComposedRouteStillReportsNoTemplate(): void
    {
        // The provenance fields are on every route, so a client cannot tell
        // "composed by hand" from "the field is missing" unless the ad-hoc path
        // states it. Asserted on the ORIGINAL endpoint, which #1031 must not have
        // changed.
        $data = $this->json($this->issueRoute())['data'];

        self::assertNull($data['template_id']);
        self::assertNull($data['template_name']);
    }

    public function testATemplateFromAnotherTenantIsReportedAsAbsent(): void
    {
        $templateId = $this->seedTemplate('Elsewhere', tenantId: self::OTHER_TENANT);

        $response = $this->post(
            "/api/documents/{$this->documentId}/routes/from-template",
            self::DEAN,
            ['template_id' => $templateId],
            ['id' => (string) $this->documentId]
        );

        self::assertSame(
            404,
            $response->getStatusCode(),
            'a template id is an enumerable integer, so a 403 would confirm which ids exist'
        );
    }

    public function testRoutingADocumentDoesNotByItselfConferReadingSomebodysDesign(): void
    {
        // The dean holds `documents:route` (the route's own gate) but this
        // deployment has not granted `route_templates:read`. The reply would
        // contain every stage of the design, so the second slug is required and
        // the refusal NAMES it — an author told "not found" would go looking for
        // a template that is sitting right there.
        //
        // This test is written to be non-inert: the assertion below would pass
        // just as well if the document were invisible or the template missing, so
        // the first two lines prove the request otherwise succeeds.
        $templateId = $this->seedTemplate('Purchase approval');
        $granted = $this->post(
            "/api/documents/{$this->documentId}/routes/from-template",
            self::DEAN,
            ['template_id' => $templateId],
            ['id' => (string) $this->documentId]
        );
        self::assertSame(201, $granted->getStatusCode(), 'the same request must succeed while the grant stands');

        $this->revoke(self::ROLE_DEAN, CorePermissions::ROUTE_TEMPLATES_READ);
        RoleChecker::clearCache();

        $refused = $this->post(
            "/api/documents/{$this->documentId}/routes/from-template",
            self::DEAN,
            ['template_id' => $templateId],
            ['id' => (string) $this->documentId]
        );

        self::assertSame(403, $refused->getStatusCode());
        self::assertStringContainsString(
            CorePermissions::ROUTE_TEMPLATES_READ,
            (string) $this->json($refused)['error']
        );
    }

    public function testATemplateWithNothingDrawnOnItIsRefusedInItsOwnWords(): void
    {
        $templateId = $this->templates->create(self::TENANT, 'Empty', null, self::DEAN);

        $response = $this->post(
            "/api/documents/{$this->documentId}/routes/from-template",
            self::DEAN,
            ['template_id' => $templateId],
            ['id' => (string) $this->documentId]
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('no stages yet', (string) $this->json($response)['error']);
        self::assertSame([], $this->reachedProfiles(), 'a refused apply must write nothing');
    }

    public function testAMissingTemplateIdIsRefusedRatherThanTreatedAsZero(): void
    {
        $response = $this->post(
            "/api/documents/{$this->documentId}/routes/from-template",
            self::DEAN,
            [],
            ['id' => (string) $this->documentId]
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('template_id', (string) $this->json($response)['error']);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * A two-stage design saved THROUGH the real graph validator: circulate to the
     * heads, then a gate held by the dean with no outgoing edge - the terminal
     * gate that an edge-inferred `decision` would silently demote.
     */
    private function seedTemplate(string $name, int $tenantId = self::TENANT): int
    {
        $id = $this->templates->create($tenantId, $name, null, self::DEAN);
        $validated = $this->templateGraph->validate([
            [
                'position' => 1,
                'rule_kind' => 'role',
                'rule_config' => ['role_id' => self::ROLE_HEAD],
                'decision' => false,
                'canvas_x' => 0,
                'canvas_y' => 0,
            ],
            [
                'position' => 2,
                'rule_kind' => 'role',
                'rule_config' => ['role_id' => self::ROLE_DEAN],
                'decision' => true,
                'canvas_x' => 0,
                'canvas_y' => 0,
            ],
        ], [], 50);
        $this->templates->replaceGraph($id, $tenantId, $validated['steps'], $validated['edges']);

        return $id;
    }

    /**
     * Who this document's routes have reached, from the recipient rows rather
     * than from any response body.
     *
     * @return list<int>
     */
    private function reachedProfiles(): array
    {
        $ids = array_map(
            static fn (array $r): int => (int) $r['profile_id'],
            $this->recipients->listForDocument($this->documentId, self::TENANT)
        );
        sort($ids);

        return array_values($ids);
    }

    private function revoke(int $roleId, string $permission): void
    {
        $this->pdo->prepare(
            'DELETE FROM role_permissions
              WHERE role_id = ?
                AND permission_id = (SELECT id FROM permissions WHERE name = ?)'
        )->execute([$roleId, $permission]);
    }

    private function issueRoute(): Response
    {
        return $this->post("/api/documents/{$this->documentId}/routes", self::DEAN, [
            'steps' => [['rule_kind' => 'role', 'rule_config' => ['role_id' => self::ROLE_HEAD]]],
        ], ['id' => (string) $this->documentId]);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $params
     */
    private function post(string $path, int $callerId, array $body, array $params): Response
    {
        $request = new Request('POST', $path, [], (string) json_encode($body));
        $request->user = (object) ['profile_id' => $callerId];

        return match (true) {
            // Before the bare `/routes` arm: `str_ends_with` would never match
            // this path, but the ordering says out loud which is the more
            // specific route, as the router's own registration order does.
            str_ends_with($path, '/routes/from-template')
                => $this->handler->createFromTemplate($request, $params),
            str_ends_with($path, '/routes') => $this->handler->create($request, $params),
            str_contains($path, '/actions') => $this->handler->act($request, $params),
            default => throw new \LogicException("no POST mapping for {$path}"),
        };
    }

    /**
     * @param array<string, string> $params
     */
    private function get(string $path, int $callerId, array $params): Response
    {
        $request = new Request('GET', $path);
        $request->user = (object) ['profile_id' => $callerId];

        return match (true) {
            str_contains($path, '/trail') => $this->handler->trail($request, $params),
            str_contains($path, '/recipients') => $this->handler->recipients($request, $params),
            str_contains($path, '/routes') => $this->handler->list($request, $params),
            $path === '/api/routing-rules' => $this->handler->rules($request),
            default => throw new \LogicException("no GET mapping for {$path}"),
        };
    }

    private function inboxRequest(int $callerId, string $query): Response
    {
        return $this->inboxHandler($callerId)->list(new Request('GET', '/api/me/inbox' . $query));
    }

    private function inboxSourcesRequest(int $callerId): Response
    {
        return $this->inboxHandler($callerId)->sources(new Request('GET', '/api/me/inbox/sources'));
    }

    /**
     * A handler whose token validator answers for one caller.
     *
     * Subclassing the real {@see TokenValidator} rather than mocking it, so the
     * claim SHAPE the handler reads (`profile_id`, `active_tenant_id`) is the
     * real one: a mock would keep passing if those keys were renamed.
     */
    private function inboxHandler(int $callerId): MeInboxApiHandler
    {
        $tenantId = $callerId === self::OUTSIDER ? self::OTHER_TENANT : self::TENANT;

        $validator = new class ($this->pdo, $callerId, $tenantId) extends TokenValidator {
            public function __construct(PDO $pdo, private readonly int $profileId, private readonly int $tenantId)
            {
                parent::__construct(new \Whity\Auth\JwtParser('test-secret-that-is-long-enough-for-hs256'), $pdo);
            }

            /**
             * @return array<string, mixed>|null
             */
            public function validateAccessToken(): ?array
            {
                return ['profile_id' => $this->profileId, 'active_tenant_id' => $this->tenantId];
            }
        };

        return new MeInboxApiHandler($validator, $this->inboxSources);
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($response->getBody(), true);

        return $decoded;
    }

    private function seedDocument(): int
    {
        $this->pdo->exec(
            'INSERT INTO documents (tenant_id, document_template_id, template_name, title, origin_ou_id, created_by, created_at)
             VALUES (1, NULL, ' . $this->pdo->quote('Circular') . ', ' . $this->pdo->quote('Q3 circular')
             . ', 20, 10, ' . $this->now() . ')'
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function now(): string
    {
        return $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';
    }

    private function wrapDb(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Tenant One') . ') ON CONFLICT DO NOTHING');
        $pdo->exec('INSERT INTO tenants (id, name) VALUES (2, ' . $quote('Tenant Two') . ') ON CONFLICT DO NOTHING');

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
                (103, ' . $quote('bystander') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );
        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (104, ' . $quote('outsider') . ', ' . $quote('') . ', 2, ' . $now . ')'
        );

        // The dean may route; everyone else may only read. That asymmetry is what
        // proves acting needs no permission.
        $this->grant($pdo, self::ROLE_DEAN, CorePermissions::DOCUMENTS_READ);
        $this->grant($pdo, self::ROLE_DEAN, CorePermissions::DOCUMENTS_ROUTE);
        // Migration 120 grants this to `documents:route` holders, because the
        // people who pick a design when routing a document are an audience for
        // it. Mirrored here so the fixture matches a real install.
        $this->grant($pdo, self::ROLE_DEAN, CorePermissions::ROUTE_TEMPLATES_READ);
        $this->grant($pdo, self::ROLE_HEAD, CorePermissions::DOCUMENTS_READ);
        $this->grant($pdo, 103, CorePermissions::DOCUMENTS_READ);
        $this->grant($pdo, 104, CorePermissions::DOCUMENTS_READ);

        foreach ([[10, 'dean'], [11, 'head-a'], [12, 'head-b'], [14, 'bystander'], [20, 'outsider']] as [$id, $name]) {
            $pdo->exec(
                'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                       two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (' . $id . ', ' . $quote($name) . ', ' . $quote('x') . ', false, 0, 0, ' . $now . ', ' . $now . ')'
            );
        }

        $pdo->exec(
            "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at) VALUES
                (1010, 10, 1, 100, 20,   true, 'active', {$now}),
                (1011, 11, 1, 101, 21,   true, 'active', {$now}),
                (1012, 12, 1, 101, 22,   true, 'active', {$now}),
                (1014, 14, 1, 103, NULL, true, 'active', {$now}),
                (1020, 20, 2, 104, NULL, true, 'active', {$now})"
        );

        return $pdo;
    }

    private function grant(PDO $pdo, int $roleId, string $permission): void
    {
        $pdo->prepare('INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, NOW())')
            ->execute([$permission, '']);
        $sel = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $sel->execute([$permission]);
        $pid = (int) $sel->fetchColumn();
        $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$roleId, $pid]);
    }
}
