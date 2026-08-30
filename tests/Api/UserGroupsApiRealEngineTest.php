<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\UserGroupsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * Real-engine tests for named USER GROUPS (#999) — the definitions, the live
 * resolution, and the preview contract.
 *
 * The things worth failing a build over, in order:
 *
 *  1. RESOLUTION IS LIVE. A person added to a role AFTER the group was saved is
 *     in the group, with nothing invalidated and nobody notified. This is the
 *     single property the whole design exists to buy, and it is the one a cache
 *     added later would silently take away.
 *
 *  2. A GROUP IS NOT A LIST ANYWHERE ON THE SURFACE. The list endpoint carries
 *     no member counts, and the preview answers with a count plus a bounded
 *     sample and accepts no page parameter. A surface that renders a thousand
 *     people has rebuilt the problem the rule replaced.
 *
 *  3. NESTING IS IMPOSSIBLE, NOT DISCOURAGED. `group` is a routing kind and not
 *     an audience kind, so a group defined as a group is refused with a message
 *     — and there is therefore no cycle for anything to detect.
 *
 *  4. A DELETED GROUP FAILS LOUDLY. A route step naming it is told the group is
 *     gone, by number. Resolving silently to nobody would drop a whole class of
 *     people from a distribution and report success.
 *
 *  5. THE COUNT AND THE NAMES ARE GATED SEPARATELY. `groups:read` buys the
 *     count; a person's display name needs `users:read`, which is the platform's
 *     existing answer to who may read people. The payload SHAPE does not change
 *     either way.
 *
 *  6. TENANT ISOLATION, reported as ABSENT rather than forbidden — group ids are
 *     enumerable integers.
 *
 * The registries are wired exactly as public/index.php wires them, closure and
 * all: a stub would let a group pass here that could not be defined in
 * production.
 */
final class UserGroupsApiRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    /** groups:read + groups:write + users:read. In Faculty (unit 20). */
    private const ADMIN = 10;
    /** groups:read only, and NO users:read. In Dept A (unit 21). */
    private const READER = 11;
    /** An instructor. In Dept A. */
    private const INSTRUCTOR_A = 12;
    /** An instructor. In Dept B (unit 22). */
    private const INSTRUCTOR_B = 13;
    /** Another tenant's admin. */
    private const OUTSIDER = 20;

    private const ROLE_ADMIN = 100;
    private const ROLE_READER = 101;
    private const ROLE_INSTRUCTOR = 102;
    private const ROLE_OUTSIDER = 104;

    private const OU_FACULTY = 20;
    private const OU_DEPT_A = 21;
    private const OU_DEPT_B = 22;

    private PDO $pdo;
    private UserGroupsApiHandler $handler;
    private UserGroupRepository $groups;
    private GroupResolver $resolver;
    private RoutingRuleRegistry $rules;
    private SettingsService $settings;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapDb($this->pdo);

        $this->groups = new UserGroupRepository($this->pdo);
        $this->rules = new RoutingRuleRegistry();
        $registry = $this->rules;
        $this->resolver = new GroupResolver(
            $this->pdo,
            $this->groups,
            static fn (): RoutingRuleRegistry => $registry
        );
        $this->rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver($this->resolver)
        );

        $this->settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $this->handler = new UserGroupsApiHandler(
            $this->pdo,
            $this->groups,
            $this->resolver,
            $this->rules,
            $this->settings,
            new RoleChecker($db, new PermissionRegistry()),
            $this->serverLabels()
        );

        TenantContext::setTenantId(self::TENANT);
    }

    /**
     * A REAL label translator over this suite's own schema (#1044).
     *
     * The rule catalogue is localised at serving time now. A stub would agree
     * with whatever this file assumed, which is the one thing worth checking.
     */
    private function serverLabels(): ServerLabels
    {
        return new ServerLabels(new LanguageRegistry(
            new LanguageRepository($this->pdo),
            new TranslationRepository($this->pdo),
            new StaticTenantContextAdapter(),
        ));
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // -- the catalogue -------------------------------------------------------

    public function testTheGroupRuleCatalogueExcludesTheGroupKindItself(): void
    {
        $body = $this->json($this->handler->rules($this->request('GET', '/api/group-rules', self::ADMIN)));

        $kinds = array_column($body['data'], 'kind');

        self::assertContains('role', $kinds);
        self::assertContains('role_below_actor', $kinds);
        self::assertContains('explicit', $kinds);
        self::assertNotContains(
            'group',
            $kinds,
            'a group cannot be defined as another group — the picker must not offer it'
        );
    }

    // -- defining ------------------------------------------------------------

    public function testAGroupIsCreatedAsARuleWithNoMembersAnywhereInThePayload(): void
    {
        $response = $this->create(self::ADMIN, [
            'name' => 'Instructors',
            'description' => 'Everyone holding the instructor role, anywhere in the tenant',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]);

        self::assertSame(201, $response->getStatusCode());
        $group = $this->json($response)['data'];

        self::assertSame('Instructors', $group['name']);
        self::assertSame('role', $group['rule_kind']);
        self::assertSame(['role_id' => self::ROLE_INSTRUCTOR], $group['rule_config']);
        self::assertSame(self::ADMIN, $group['created_by']);

        foreach (['members', 'member_count', 'member_ids', 'profile_ids'] as $absent) {
            self::assertArrayNotHasKey(
                $absent,
                $group,
                "a group's membership is a question asked of the organisation, never a field on the group"
            );
        }
    }

    public function testTheHandPickedCaseIsTheSameObjectWithDifferentInnards(): void
    {
        $computed = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $handPicked = $this->json($this->create(self::ADMIN, [
            'name' => 'Tender committee',
            'rule_kind' => 'explicit',
            'rule_config' => ['profile_ids' => [self::ADMIN, self::INSTRUCTOR_B]],
        ]))['data'];

        // Same keys, same shape, different innards — which is what lets every
        // consumer downstream have one code path, and what lets an admin change
        // their mind without recreating the group as a different sort of thing.
        self::assertSame(array_keys($computed), array_keys($handPicked));
    }

    public function testASecondGroupWithTheSameNameIsA409(): void
    {
        $this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]);

        $response = $this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'explicit',
            'rule_config' => ['profile_ids' => [self::ADMIN]],
        ]);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testAConfigTheRuleRefusesIsA422CarryingTheRulesOwnMessage(): void
    {
        $response = $this->create(self::ADMIN, [
            'name' => 'Broken',
            'rule_kind' => 'explicit',
            'rule_config' => ['profile_ids' => []],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('at least one', $this->json($response)['error']);
    }

    public function testAGroupCannotBeDefinedAsAnotherGroup(): void
    {
        $existing = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $response = $this->create(self::ADMIN, [
            'name' => 'Instructors, again',
            'rule_kind' => 'group',
            'rule_config' => ['group_id' => $existing['id']],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString(
            'cannot be defined as another user group',
            $this->json($response)['error'],
            'the refusal has to say what to do instead — this is the one an author is most '
            . 'likely to hit and least likely to understand'
        );
    }

    public function testAKindNothingProvidesIsA422ThatNamesIt(): void
    {
        $response = $this->create(self::ADMIN, [
            'name' => 'Committee',
            'rule_kind' => 'acme:committee',
            'rule_config' => [],
        ]);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('acme:committee', $this->json($response)['error']);
    }

    // -- live resolution -----------------------------------------------------

    public function testSomebodyHiredAfterTheGroupWasSavedIsInIt(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $before = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];
        self::assertSame(2, $before['total']);

        // A new instructor arrives. Nothing is invalidated, nothing recomputed,
        // nobody notified — and this is the entire argument for a rule over a
        // list, so it is asserted rather than assumed.
        $this->addProfile(14, 'new-instructor');
        $this->addMembership(1014, 14, self::TENANT, self::ROLE_INSTRUCTOR, self::OU_DEPT_A);

        $after = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];

        self::assertSame(3, $after['total'], 'a stored list would still say 2, render fine, and report success');
    }

    public function testASuspendedMemberDropsOutOfTheAnswer(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $this->pdo->exec('UPDATE memberships SET status = \'suspended\' WHERE profile_id = ' . self::INSTRUCTOR_B);

        $preview = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];

        self::assertSame(1, $preview['total']);
    }

    public function testAnExplicitGroupNamingSomebodyWhoLeftResolvesWithoutThem(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Tender committee',
            'rule_kind' => 'explicit',
            // 99 belongs to no tenant at all — the stale-id case a foreign key
            // would tidy up and would not change the answer to.
            'rule_config' => ['profile_ids' => [self::INSTRUCTOR_A, 99]],
        ]))['data'];

        $preview = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];

        self::assertSame(1, $preview['total']);
        self::assertSame(self::INSTRUCTOR_A, $preview['sample'][0]['profile_id']);
    }

    public function testAnActorRelativeGroupResolvesDifferentlyForDifferentPeopleAndSaysSo(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors in my unit and below',
            'rule_kind' => 'role_below_actor',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        // The admin sits at Faculty, above both departments.
        $forAdmin = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];
        // The reader sits in Dept A, so only Dept A's instructor is below them.
        $forReader = $this->json($this->preview(self::READER, (int) $group['id']))['data'];

        self::assertSame(2, $forAdmin['total']);
        self::assertSame(1, $forReader['total']);

        self::assertSame(self::ADMIN, $forAdmin['resolved_for']['profile_id']);
        self::assertSame(self::OU_FACULTY, $forAdmin['resolved_for']['ou_id']);
        self::assertSame(self::OU_DEPT_A, $forReader['resolved_for']['ou_id']);
    }

    // -- the preview contract ------------------------------------------------

    public function testThePreviewIsACountPlusABoundedSampleAndSaysWhenItIsTruncated(): void
    {
        // Two instructors, sample size forced to one.
        $this->settings->setGlobal(SettingsRegistry::GROUPS_PREVIEW_SAMPLE_SIZE, '1');

        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $preview = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];

        self::assertSame(2, $preview['total'], 'the count is exact regardless of the sample size');
        self::assertCount(1, $preview['sample']);
        self::assertSame(1, $preview['sample_size']);
        self::assertTrue($preview['truncated']);
        // Lowest profile id first, so two previews of an unchanged group show the
        // same face — a reshuffling sample reads as "the group changed".
        self::assertSame(self::INSTRUCTOR_A, $preview['sample'][0]['profile_id']);
    }

    public function testAnUntruncatedPreviewSaysSo(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $preview = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];

        self::assertSame(2, $preview['total']);
        self::assertFalse($preview['truncated']);
        self::assertCount(2, $preview['sample']);
    }

    public function testARuleThatMatchesNobodyIsAValidGroupThatPreviewsAsZero(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Registrars',
            // A role nobody holds. Legal to define — it may be held tomorrow —
            // and the author finds out in the preview rather than in a complaint.
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => 199],
        ]))['data'];

        $preview = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];

        self::assertSame(0, $preview['total']);
        self::assertSame([], $preview['sample']);
        self::assertFalse($preview['truncated']);
    }

    public function testAnUnsavedRuleCanBePreviewedBeforeAnybodyCommitsToIt(): void
    {
        $request = $this->request('POST', '/api/user-groups/preview', self::ADMIN, [
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]);

        $preview = $this->json($this->handler->previewDraft($request))['data'];

        self::assertSame(2, $preview['total']);
        self::assertSame(0, $this->groups->countForTenant(self::TENANT), 'nothing was written');
    }

    public function testTheSampleCarriesNamesOnlyForACallerWhoMayReadPeople(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $forAdmin = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];
        $forReader = $this->json($this->preview(self::READER, (int) $group['id']))['data'];

        self::assertSame('instructor-a', $forAdmin['sample'][0]['display_name']);

        // Same count, same shape, no names. The count is a fact about the rule;
        // a name is a fact about a person, and `users:read` is the platform's
        // existing answer to who may read those.
        self::assertSame($forAdmin['total'], $forReader['total']);
        self::assertArrayHasKey('display_name', $forReader['sample'][0]);
        self::assertNull($forReader['sample'][0]['display_name']);
    }

    // -- the list ------------------------------------------------------------

    public function testTheListCarriesDefinitionsAndNoMemberCounts(): void
    {
        $this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]);

        $body = $this->json($this->handler->index($this->request('GET', '/api/user-groups', self::ADMIN)));

        self::assertCount(1, $body['data']);
        self::assertArrayHasKey('pagination', $body);
        self::assertSame(1, $body['pagination']['total']);
        self::assertArrayNotHasKey(
            'member_count',
            $body['data'][0],
            'forty groups on a page must not commission forty fan-out queries'
        );
    }

    // -- redefinition and deletion -------------------------------------------

    public function testRedefiningAGroupChangesWhatItResolvesToImmediately(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $response = $this->handler->update(
            $this->request('PATCH', '/api/user-groups/' . $group['id'], self::ADMIN, [
                'rule_kind' => 'explicit',
                'rule_config' => ['profile_ids' => [self::ADMIN]],
            ]),
            ['id' => (string) $group['id']]
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('explicit', $this->json($response)['data']['rule_kind']);

        $preview = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];
        self::assertSame(1, $preview['total']);
    }

    public function testChangingTheKindWithoutTheConfigIsRefused(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $response = $this->handler->update(
            $this->request('PATCH', '/api/user-groups/' . $group['id'], self::ADMIN, [
                'rule_kind' => 'explicit',
            ]),
            ['id' => (string) $group['id']]
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('together', $this->json($response)['error']);
    }

    public function testRenamingLeavesTheRuleAlone(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $updated = $this->json($this->handler->update(
            $this->request('PATCH', '/api/user-groups/' . $group['id'], self::ADMIN, [
                'name' => 'Teaching staff',
            ]),
            ['id' => (string) $group['id']]
        ))['data'];

        self::assertSame('Teaching staff', $updated['name']);
        self::assertSame('role', $updated['rule_kind']);
        self::assertSame(['role_id' => self::ROLE_INSTRUCTOR], $updated['rule_config']);
        self::assertSame(
            $group['id'],
            $updated['id'],
            'the id must survive a rename — routes reference the group BY ID, and renaming is '
            . 'an ordinary administrative act'
        );
    }

    public function testDeletingAGroupSucceedsAndAPreviewOfItThenReportsItGone(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $deleted = $this->handler->destroy(
            $this->request('DELETE', '/api/user-groups/' . $group['id'], self::ADMIN),
            ['id' => (string) $group['id']]
        );
        self::assertSame(200, $deleted->getStatusCode());

        // Not "resolves to nobody" — gone, said out loud. This is the assertion
        // that keeps a deleted group from silently emptying a distribution.
        $again = $this->preview(self::ADMIN, (int) $group['id']);
        self::assertSame(404, $again->getStatusCode());
    }

    public function testDeletingAGroupIsAuditedWithTheRuleItDestroyed(): void
    {
        $recorded = [];
        $logger = new class ($recorded) implements \Whity\Core\Audit\AuditLoggerInterface {
            /** @param list<array{0: string, 1: array<string, mixed>}> $recorded */
            public function __construct(public array &$recorded)
            {
            }

            /** @param array<string, mixed> $options */
            public function record(string $action, array $options = []): void
            {
                $this->recorded[] = [$action, $options];
            }
        };

        $handler = new UserGroupsApiHandler(
            $this->pdo,
            $this->groups,
            $this->resolver,
            $this->rules,
            $this->settings,
            new RoleChecker($this->wrapDb($this->pdo), new PermissionRegistry()),
            $this->serverLabels(),
            $logger
        );

        $group = $this->json($handler->create($this->request('POST', '/api/user-groups', self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ])))['data'];

        $handler->destroy(
            $this->request('DELETE', '/api/user-groups/' . $group['id'], self::ADMIN),
            ['id' => (string) $group['id']]
        );

        // ONE row, for the delete only. Creating and renaming announce
        // themselves in the list; a deletion's consequence surfaces weeks later
        // on somebody else's route, and this is the only thing that connects the
        // two.
        self::assertCount(1, $recorded);
        self::assertSame('user_group.deleted', $recorded[0][0]);
        self::assertSame((int) $group['id'], $recorded[0][1]['target_id']);
        self::assertSame(
            ['role_id' => self::ROLE_INSTRUCTOR],
            $recorded[0][1]['metadata']['rule_config'],
            'the rule is what somebody would need to recreate the group — the later failure '
            . 'mentions only the id'
        );
    }

    public function testAGroupRuleRefusalNamesTheMissingGroupRatherThanResolvingToNobody(): void
    {
        // Resolved through the ROUTING kind, which is the path a route step
        // takes. A missing group must reach the author as a message naming the
        // id, which is why the group layer's refusal is translated into the
        // InvalidArgumentException channel the routing engine shows verbatim.
        $resolver = new GroupRuleResolver($this->resolver);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/user group 4242 does not exist/');

        $resolver->resolve(new \Whity\Sdk\Routing\RoutingRuleContext(
            tenantId: self::TENANT,
            documentId: 1,
            routeId: 1,
            stepId: 1,
            position: 1,
            actorProfileId: self::ADMIN,
            actorOuId: self::OU_FACULTY,
            config: ['group_id' => 4242],
        ));
    }

    public function testARouteStepNamingAGroupResolvesToTheGroupsPeople(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        $resolved = $this->rules->get('group')?->resolve(new \Whity\Sdk\Routing\RoutingRuleContext(
            tenantId: self::TENANT,
            documentId: 1,
            routeId: 1,
            stepId: 1,
            position: 1,
            actorProfileId: self::ADMIN,
            actorOuId: self::OU_FACULTY,
            config: ['group_id' => (int) $group['id']],
        ));

        self::assertNotNull($resolved);
        self::assertSame(
            [self::INSTRUCTOR_A, self::INSTRUCTOR_B],
            array_map(static fn ($r): int => $r->profileId, $resolved)
        );
    }

    // -- tenant isolation ----------------------------------------------------

    public function testAnotherTenantsGroupIsReportedAbsentRatherThanForbidden(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]))['data'];

        // The context locks once set, so switching tenants means resetting first
        // — which is also what a real request boundary does.
        TenantContext::reset();
        TenantContext::setTenantId(self::OTHER_TENANT);

        foreach (['show', 'preview', 'destroy'] as $method) {
            $response = $this->handler->{$method}(
                $this->request('GET', '/api/user-groups/' . $group['id'], self::OUTSIDER),
                ['id' => (string) $group['id']]
            );
            self::assertSame(
                404,
                $response->getStatusCode(),
                "{$method}: a 403 would confirm the id exists, and group ids are enumerable integers"
            );
        }
    }

    public function testTheListNeverLeaksAnotherTenantsGroups(): void
    {
        $this->create(self::ADMIN, [
            'name' => 'Instructors',
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_INSTRUCTOR],
        ]);

        // The context locks once set, so switching tenants means resetting first
        // — which is also what a real request boundary does.
        TenantContext::reset();
        TenantContext::setTenantId(self::OTHER_TENANT);
        $body = $this->json($this->handler->index($this->request('GET', '/api/user-groups', self::OUTSIDER)));

        self::assertSame([], $body['data']);
        self::assertSame(0, $body['pagination']['total']);
    }

    public function testARuleNamingAnotherTenantsRoleResolvesToNobody(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Outsiders',
            // A role that belongs to tenant 2. The membership side of the query
            // is bounded by tenant 1, so this reaches nobody rather than
            // reaching that tenant's people.
            'rule_kind' => 'role',
            'rule_config' => ['role_id' => self::ROLE_OUTSIDER],
        ]))['data'];

        $preview = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];

        self::assertSame(0, $preview['total']);
    }

    public function testAnExplicitRuleNamingAnotherTenantsProfileResolvesToNobody(): void
    {
        $group = $this->json($this->create(self::ADMIN, [
            'name' => 'Reaching out',
            'rule_kind' => 'explicit',
            'rule_config' => ['profile_ids' => [self::OUTSIDER]],
        ]))['data'];

        $preview = $this->json($this->preview(self::ADMIN, (int) $group['id']))['data'];

        self::assertSame(
            0,
            $preview['total'],
            'the host filters every resolver answer against the tenant, which is why a '
            . 'hand-picked list needs no foreign key to be safe'
        );
    }

    // -- helpers -------------------------------------------------------------

    /**
     * @param array<string, mixed> $body
     */
    private function create(int $callerId, array $body): Response
    {
        return $this->handler->create($this->request('POST', '/api/user-groups', $callerId, $body));
    }

    private function preview(int $callerId, int $groupId): Response
    {
        return $this->handler->preview(
            $this->request('GET', "/api/user-groups/{$groupId}/preview", $callerId),
            ['id' => (string) $groupId]
        );
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $path, int $callerId, ?array $body = null): Request
    {
        $request = new Request($method, $path, [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => $callerId];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getBody(), true);

        return $decoded;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $quote = static fn (string $v): string => $pdo->quote($v);
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec('INSERT INTO tenants (id, name) VALUES (1, ' . $quote('Tenant One') . ') ON CONFLICT DO NOTHING');
        $pdo->exec('INSERT INTO tenants (id, name) VALUES (2, ' . $quote('Tenant Two') . ') ON CONFLICT DO NOTHING');

        // 20 (Faculty) -> 21 (Dept A), 20 -> 22 (Dept B). Two levels is the
        // minimum that distinguishes `role` from `role_below_actor`.
        $pdo->exec(
            'INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, created_at) VALUES
                (20, 1, NULL, ' . $quote('Faculty') . ', ' . $quote('faculty') . ', ' . $now . '),
                (21, 1, 20,   ' . $quote('Dept A') . ',  ' . $quote('dept-a') . ',  ' . $now . '),
                (22, 1, 20,   ' . $quote('Dept B') . ',  ' . $quote('dept-b') . ',  ' . $now . ')'
        );

        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, ' . $quote('group-admin') . ', ' . $quote('') . ', 1, ' . $now . '),
                (101, ' . $quote('group-reader') . ', ' . $quote('') . ', 1, ' . $now . '),
                (102, ' . $quote('instructor') . ', ' . $quote('') . ', 1, ' . $now . ')'
        );
        $pdo->exec(
            'INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (104, ' . $quote('outsider') . ', ' . $quote('') . ', 2, ' . $now . ')'
        );

        // The asymmetry that matters: the admin may read PEOPLE, the reader may
        // not. Both may read groups. That is what proves the preview's count and
        // its names are gated separately.
        $this->grant($pdo, self::ROLE_ADMIN, CorePermissions::GROUPS_READ);
        $this->grant($pdo, self::ROLE_ADMIN, CorePermissions::GROUPS_WRITE);
        $this->grant($pdo, self::ROLE_ADMIN, CorePermissions::USERS_READ);
        $this->grant($pdo, self::ROLE_READER, CorePermissions::GROUPS_READ);
        $this->grant($pdo, self::ROLE_OUTSIDER, CorePermissions::GROUPS_READ);
        $this->grant($pdo, self::ROLE_OUTSIDER, CorePermissions::GROUPS_WRITE);

        foreach ([
            [10, 'admin'],
            [11, 'reader'],
            [12, 'instructor-a'],
            [13, 'instructor-b'],
            [20, 'outsider'],
        ] as [$id, $name]) {
            $this->addProfile($id, $name, $pdo);
        }

        $this->addMembership(1010, 10, 1, self::ROLE_ADMIN, self::OU_FACULTY, $pdo);
        $this->addMembership(1011, 11, 1, self::ROLE_READER, self::OU_DEPT_A, $pdo);
        $this->addMembership(1012, 12, 1, self::ROLE_INSTRUCTOR, self::OU_DEPT_A, $pdo);
        $this->addMembership(1013, 13, 1, self::ROLE_INSTRUCTOR, self::OU_DEPT_B, $pdo);
        $this->addMembership(1020, 20, 2, self::ROLE_OUTSIDER, null, $pdo);

        return $pdo;
    }

    private function addProfile(int $id, string $name, ?PDO $pdo = null): void
    {
        $pdo ??= $this->pdo;
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec(
            'INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                                   two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (' . $id . ', ' . $pdo->quote($name) . ', ' . $pdo->quote('x') . ', false, 0, 0, '
             . $now . ', ' . $now . ')'
        );
    }

    private function addMembership(
        int $id,
        int $profileId,
        int $tenantId,
        int $roleId,
        ?int $ouId,
        ?PDO $pdo = null,
    ): void {
        $pdo ??= $this->pdo;
        $now = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' ? "datetime('now')" : 'NOW()';

        $pdo->exec(
            "INSERT INTO memberships (id, profile_id, tenant_id, role_id, ou_id, is_primary, status, created_at)
             VALUES ({$id}, {$profileId}, {$tenantId}, {$roleId}, "
             . ($ouId === null ? 'NULL' : (string) $ouId) . ", true, 'active', {$now})"
        );
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

    private function wrapDb(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
