<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\RolesApiHandler;
use Whity\Api\UserGroupsApiHandler;
use Whity\Api\UsersApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audience\ExplicitRuleResolver;
use Whity\Core\Document\Routing\RoleBelowActorRuleResolver;
use Whity\Core\Document\Routing\RoleRuleResolver;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\Group\GroupResolver;
use Whity\Core\Group\GroupRuleResolver;
use Whity\Core\Group\UserGroupRepository;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationRepository;
use Whity\Database\Database;
use Whity\Sdk\Http\Response;

/**
 * The list contract as the ENDPOINTS actually serve it (#1102).
 *
 * {@see ListQueryRealEngineTest} proves the contract over a table of its own.
 * This proves the four list endpoints that now use it — `GET /api/users`,
 * `GET /api/roles`, `GET /api/roles/{id}/assignments` and
 * `GET /api/user-groups` — got the wiring right, which is a different question
 * and the one that goes wrong in practice.
 *
 * FOUR PROPERTIES, ASSERTED FOR EVERY ENDPOINT:
 *
 *  1. Every offered sort key actually REORDERS. A key in the map that names a
 *     column the query does not select, or an alias that does not exist, is a
 *     500 on some engines and a silently unordered list on others — and a sort
 *     key that quietly does nothing is worse than no sort key, because the
 *     screen renders a control that lies.
 *
 *  2. An UNKNOWN key falls back rather than erroring. The endpoint must answer a
 *     client that asks for a column it cannot see.
 *
 *  3. Search narrows the ROWS **and** the reported TOTAL. This is the one that
 *     is easy to half-do: put the predicate on the SELECT, forget the COUNT, and
 *     the client draws page controls for pages that come back empty. Every case
 *     below asserts both numbers, never just the array.
 *
 *  4. Paging over a TIED sort column returns every row exactly once. Each
 *     fixture deliberately ties the column being sorted — every user shares a
 *     role, every role shares a description, every holder is granted at the same
 *     instant — because that is the ordinary state of the columns these screens
 *     sort by, and it is the state in which an untied `LIMIT/OFFSET` repeats one
 *     row on two pages and never shows another.
 *
 * Runs against whichever engine the harness supplies — real PostgreSQL when
 * `PHPUNIT_PG_DSN` is set, SQLite otherwise — because the search predicate
 * differs by dialect (`ILIKE` vs `LIKE`) and only running it proves either
 * branch.
 */
final class ListContractEndpointsRealEngineTest extends TestCase
{
    private const TENANT = 1;
    private const OTHER_TENANT = 2;

    /** The acting caller's profile id. Never one of the seeded fixtures. */
    private const CALLER = 900;

    private PDO $pdo;

    /**
     * How many roles tenant 1 could already see before this suite seeded any.
     *
     * The migrations seed GLOBAL base roles (`admin`, `user`, …) with a NULL
     * tenant_id, and the roles list returns own-plus-global by design — so a
     * total written as a bare literal here would be asserting how many roles the
     * platform ships with, and would fail the day it ships one more. The
     * fixtures' own counts are exact; the baseline is measured.
     */
    private int $baseRoles = 0;

    /**
     * How many ACTIVE primary memberships existed across all tenants before this
     * suite seeded any — the population the SYSTEM tenant's users list counts.
     *
     * Measured for the same reason as {@see self::$baseRoles}: the migrations
     * provision the system administrator, so a literal here would be asserting
     * how many accounts a fresh install comes with. The tenant-scoped branch
     * needs no such baseline — tenant 1 starts empty, which is why its totals
     * below are exact.
     */
    private int $baseSystemUsers = 0;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Tenant One') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (2, 'Tenant Two') ON CONFLICT DO NOTHING");
        $this->baseRoles = $this->scalar(
            'SELECT COUNT(*) FROM roles WHERE tenant_id = 1 OR tenant_id IS NULL'
        );
        $this->baseSystemUsers = $this->scalar(
            "SELECT COUNT(*) FROM memberships WHERE is_primary AND status = 'active'"
        );
        MockRequestFactory::setTestTenant(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ==================================================================
    // GET /api/users
    // ==================================================================

    /**
     * Every sort key the users endpoint offers reorders the page.
     *
     * The fixture is built so each key disagrees with the others: the
     * alphabetical order of the emails is not the order the memberships were
     * created, and the roles cut across both. A key that silently did nothing
     * would come back in creation order and be caught here.
     */
    public function testUsersSortByEveryOfferedKeyReorders(): void
    {
        $this->seedUsers();

        self::assertSame(
            ['ana@example.test', 'bo@example.test', 'cy@example.test', 'dee@example.test'],
            $this->userEmails('sort=email&dir=asc'),
            'email ascending'
        );
        self::assertSame(
            ['dee@example.test', 'cy@example.test', 'bo@example.test', 'ana@example.test'],
            $this->userEmails('sort=email&dir=desc'),
            'and descending is the reverse, not the same list'
        );
        self::assertSame(
            $this->userEmails('sort=email&dir=asc'),
            $this->userEmails('sort=name&dir=asc'),
            'name IS the email local part, so the two keys order identically by design'
        );

        // role: two `viewer`s and two `editor`s. Sorting by role must put the
        // editors first and the viewers second whichever order they were made
        // in — and the tiebreaker decides WITHIN each pair, stably.
        self::assertSame(
            ['editor', 'editor', 'viewer', 'viewer'],
            $this->userField('role', 'sort=role&dir=asc'),
            'role ascending groups the editors before the viewers'
        );
        self::assertSame(
            ['viewer', 'viewer', 'editor', 'editor'],
            $this->userField('role', 'sort=role&dir=desc')
        );

        // status: one deactivated account among three active ones.
        self::assertSame(
            ['active', 'active', 'active', 'inactive'],
            $this->userField('accountStatus', 'sort=status&dir=asc')
        );

        // created: the default, newest first — and the fixture's creation order
        // is deliberately not its alphabetical order.
        self::assertSame(
            ['dee@example.test', 'ana@example.test', 'cy@example.test', 'bo@example.test'],
            $this->userEmails('sort=created&dir=desc')
        );
        self::assertSame(
            $this->userEmails('sort=created&dir=desc'),
            $this->userEmails(''),
            'created desc is the default, so the endpoint answers the same with no sort at all'
        );
    }

    /** A key the users endpoint does not offer is not an error — it is not a sort. */
    public function testUsersUnknownSortKeyFallsBackToTheDefault(): void
    {
        $this->seedUsers();

        $response = $this->users('sort=password_hash');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($this->userEmails(''), $this->emailsOf($response));
    }

    /**
     * A search narrows the ROWS and the TOTAL together.
     *
     * The total is asserted in the same breath as the rows every time. A COUNT
     * that ignored the filter would still return four here, and the screen would
     * render page controls for a second page that came back empty.
     */
    public function testUsersSearchNarrowsTheRowsAndTheTotal(): void
    {
        $this->seedUsers();

        $all = $this->json($this->users(''));
        self::assertSame(4, $all['pagination']['total']);

        $one = $this->json($this->users('q=ana'));
        self::assertSame(['ana@example.test'], array_column($one['data'], 'email'));
        self::assertSame(1, $one['pagination']['total'], 'the total describes the FILTERED list');
        self::assertSame(1, $one['pagination']['totalPages']);

        // Case-insensitively — ILIKE on PostgreSQL, LIKE on SQLite.
        self::assertSame(
            $one['data'],
            $this->json($this->users('q=ANA'))['data'],
            'the same answer on either engine'
        );

        // The role name is searchable too, because the screen renders it and its
        // own client-side filter has always matched on it.
        $editors = $this->json($this->users('q=editor'));
        self::assertSame(2, $editors['pagination']['total']);
        self::assertSame(['editor', 'editor'], array_column($editors['data'], 'role'));

        $none = $this->json($this->users('q=nobody-by-this-name'));
        self::assertSame([], $none['data']);
        self::assertSame(0, $none['pagination']['total']);
        self::assertSame(0, $none['pagination']['totalPages']);
    }

    /**
     * Walking the pages of a list sorted by a TIED column sees every person
     * exactly once.
     *
     * Every one of these nine shares a role, which is the normal state of a
     * users table and the reason the tiebreaker is not ceremony: without it the
     * database may order within the tie differently for each page, and the
     * symptom on screen is somebody who vanished — which reads as lost data and
     * is an unstable query.
     */
    public function testUsersPagingOverATiedRoleSeesEveryoneExactlyOnce(): void
    {
        $this->seedTiedUsers(9);

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            foreach ($this->json($this->users("sort=role&per_page=3&page={$page}"))['data'] as $row) {
                $seen[] = $row['id'];
            }
        }

        sort($seen);
        self::assertSame(range(500, 508), $seen, 'every row exactly once across the walk');
    }

    /** The envelope is byte-for-byte the shape it was before the contract landed. */
    public function testUsersEnvelopeIsUnchanged(): void
    {
        $this->seedUsers();

        $body = $this->json($this->users('page=2&per_page=3'));

        self::assertSame(['data', 'pagination'], array_keys($body));
        self::assertSame(
            ['page' => 2, 'perPage' => 3, 'total' => 4, 'totalPages' => 2],
            $body['pagination']
        );
    }

    /**
     * The SYSTEM tenant's branch got the same treatment, `tenant` sort included.
     *
     * Both branches were rewritten, and a fixture that only ever ran the scoped
     * one would leave the cross-tenant SQL — the branch with the
     * `@tenant-guard-ignore` on it — unexecuted.
     */
    public function testUsersSystemTenantBranchSortsSearchesAndCountsToo(): void
    {
        $this->seedUsers();
        $this->seedProfile(410, 'zed@example.test');
        $this->seedMembership(410, self::OTHER_TENANT, $this->roleId('viewer'), '2026-05-01 00:00:00');

        MockRequestFactory::setTestTenant(0);

        $all = $this->json($this->users(''));
        self::assertSame(
            $this->baseSystemUsers + 5,
            $all['pagination']['total'],
            'the system tenant counts across tenants'
        );

        // sort=tenant is offered ONLY to this caller, because it is the only one
        // whose list spans more than one tenant. Asserted as the property —
        // non-decreasing, with the other tenant's person last — rather than as a
        // fixed array, which would pin the install's own seeded accounts.
        $ascending = array_column(
            $this->json($this->users('sort=tenant&dir=asc&per_page=100'))['data'],
            'tenantId'
        );
        $expected = $ascending;
        sort($expected);
        self::assertSame($expected, $ascending, 'sort=tenant orders by tenant, ascending');
        self::assertSame(self::OTHER_TENANT, end($ascending), 'and tenant 2 is last');

        $descending = array_column(
            $this->json($this->users('sort=tenant&dir=desc&per_page=100'))['data'],
            'tenantId'
        );
        self::assertSame(self::OTHER_TENANT, $descending[0], 'descending puts it first');

        $searched = $this->json($this->users('q=zed'));
        self::assertSame(['zed@example.test'], array_column($searched['data'], 'email'));
        self::assertSame(1, $searched['pagination']['total'], 'the system branch narrows its COUNT too');
    }

    // ==================================================================
    // GET /api/roles
    // ==================================================================

    /** Every sort key the roles endpoint offers reorders the page. */
    public function testRolesSortByEveryOfferedKeyReorders(): void
    {
        $this->seedRoles();

        // The assertions read the RELATIVE order of this suite's own roles out
        // of each page. The list also carries the global base roles every tenant
        // sees, and pinning those here would be asserting the seed data rather
        // than the sort.
        self::assertSame(
            ['alpha', 'beta', 'delta-shared', 'epsilon-shared', 'gamma'],
            $this->mine($this->roleNames('sort=name&dir=asc&per_page=50')),
            'alphabetical'
        );
        self::assertSame(
            ['gamma', 'epsilon-shared', 'delta-shared', 'beta', 'alpha'],
            $this->mine($this->roleNames('sort=name&dir=desc&per_page=50')),
            'and descending is the reverse, not the same list'
        );

        // By description: 'zzz last' > 'shared blurb' > 'mmm middle' > 'aaa
        // first'. The two rows sharing a description are separated by the
        // tiebreaker, in id order, which is the order they were made.
        self::assertSame(
            ['gamma', 'delta-shared', 'epsilon-shared', 'beta', 'alpha'],
            $this->mine($this->roleNames('sort=description&dir=desc&per_page=50'))
        );

        self::assertSame(
            ['epsilon-shared', 'delta-shared', 'gamma', 'beta', 'alpha'],
            $this->mine($this->roleNames('sort=created&dir=desc&per_page=50')),
            'created desc — newest first'
        );
        self::assertSame(
            $this->roleNames('sort=created&dir=desc&per_page=50'),
            $this->roleNames('per_page=50'),
            'and it is the default, so an unsorted request is unchanged'
        );
    }

    /** `permissionCount` is not offered, so asking for it falls back rather than erroring. */
    public function testRolesUnknownSortKeyFallsBackToTheDefault(): void
    {
        $this->seedRoles();

        $response = $this->roles('sort=permissionCount&per_page=50');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($this->roleNames('per_page=50'), $this->namesOf($response));
    }

    /** Search narrows both halves, over name and over description. */
    public function testRolesSearchNarrowsTheRowsAndTheTotal(): void
    {
        $this->seedRoles();

        $all = $this->json($this->roles('per_page=50'));
        self::assertSame($this->baseRoles + 5, $all['pagination']['total']);

        $byName = $this->json($this->roles('q=shared&per_page=50'));
        self::assertSame(
            ['epsilon-shared', 'delta-shared'],
            array_column($byName['data'], 'name'),
            'name matches'
        );
        self::assertSame(2, $byName['pagination']['total']);

        $byDescription = $this->json($this->roles('q=MIDDLE&per_page=50'));
        self::assertSame(['beta'], array_column($byDescription['data'], 'name'));
        self::assertSame(1, $byDescription['pagination']['total'], 'description matches, case-insensitively');

        // The count is over the SAME population as the page. Asserting
        // totalPages is the thing a client would actually get wrong.
        $paged = $this->json($this->roles('q=shared&per_page=1'));
        self::assertCount(1, $paged['data']);
        self::assertSame(2, $paged['pagination']['total']);
        self::assertSame(2, $paged['pagination']['totalPages']);
    }

    /** Paging over a tied description sees every role exactly once. */
    public function testRolesPagingOverATiedDescriptionSeesEveryRoleExactlyOnce(): void
    {
        $ids = $this->seedTiedRoles(9);
        $pages = (int) ceil(($this->baseRoles + 9) / 2);

        $seen = [];
        for ($page = 1; $page <= $pages; $page++) {
            foreach ($this->json($this->roles("sort=description&per_page=2&page={$page}"))['data'] as $row) {
                $seen[] = (int) $row['id'];
            }
        }

        self::assertSame(
            $seen,
            array_values(array_unique($seen)),
            'no row may appear on two pages'
        );
        self::assertSame(
            $ids,
            $this->sortedInts(array_values(array_intersect($seen, $ids))),
            'and every tied row appears exactly once across the walk'
        );
    }

    /** The roles envelope is unchanged, and still carries `global` / `manageable`. */
    public function testRolesEnvelopeAndRowShapeAreUnchanged(): void
    {
        $this->seedRoles();

        $total = $this->baseRoles + 5;
        $body = $this->json($this->roles('per_page=2&page=2'));

        self::assertSame(['data', 'pagination'], array_keys($body));
        self::assertSame(
            ['page' => 2, 'perPage' => 2, 'total' => $total, 'totalPages' => (int) ceil($total / 2)],
            $body['pagination']
        );
        self::assertArrayHasKey('permissionCount', $body['data'][0]);
        self::assertArrayHasKey('global', $body['data'][0]);
        self::assertArrayHasKey('manageable', $body['data'][0]);
        self::assertArrayNotHasKey('tenant_id', $body['data'][0], 'the owning tenant is still not disclosed');
    }

    /** The system branch — the annotated, cross-tenant one — sorts and searches too. */
    public function testRolesSystemTenantBranchSortsAndSearches(): void
    {
        $this->seedRoles();
        $this->seedRole('omega-shared', 'another tenant', self::OTHER_TENANT, '2026-06-01 00:00:00');

        MockRequestFactory::setTestTenant(0);

        $searched = $this->json($this->roles('q=shared&per_page=50'));
        self::assertSame(
            ['delta-shared', 'epsilon-shared', 'omega-shared'],
            $this->sorted(array_column($searched['data'], 'name')),
            'the system tenant sees every tenant\'s roles'
        );
        self::assertSame(3, $searched['pagination']['total'], 'and its COUNT carries the same filter');

        self::assertSame(
            ['alpha', 'beta', 'delta-shared', 'epsilon-shared', 'gamma', 'omega-shared'],
            $this->mine($this->roleNames('sort=name&dir=asc&per_page=50'), ['omega-shared']),
            'and the cross-tenant branch orders by the same key'
        );
    }

    // ==================================================================
    // GET /api/roles/{id}/assignments
    // ==================================================================

    /** Every sort key the holders list offers reorders it. */
    public function testAssignmentsSortByEveryOfferedKeyReorders(): void
    {
        $roleId = $this->seedHolders();

        self::assertSame(
            ['Ada', 'Brendan', 'Grace', 'Linus'],
            $this->holderField($roleId, 'displayName', 'sort=name&dir=asc')
        );
        self::assertSame(
            ['Linus', 'Grace', 'Brendan', 'Ada'],
            $this->holderField($roleId, 'displayName', 'sort=name&dir=desc')
        );
        self::assertSame(
            ['ada@example.test', 'brendan@example.test', 'grace@example.test', 'linus@example.test'],
            $this->holderField($roleId, 'email', 'sort=email&dir=asc')
        );
        self::assertSame(
            ['Grace', 'Linus', 'Ada', 'Brendan'],
            $this->holderField($roleId, 'displayName', 'sort=assigned&dir=desc'),
            'newest grant first'
        );
        self::assertSame(
            $this->holderField($roleId, 'displayName', 'sort=assigned&dir=desc'),
            $this->holderField($roleId, 'displayName', ''),
            'which is still the default — page one IS the recent-assignment history'
        );
    }

    /** An unknown key falls back to newest-grant-first. */
    public function testAssignmentsUnknownSortKeyFallsBackToTheDefault(): void
    {
        $roleId = $this->seedHolders();

        $response = $this->assignments($roleId, 'sort=ou_id');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(
            $this->holderField($roleId, 'displayName', ''),
            array_column($this->json($response)['data'], 'displayName')
        );
    }

    /**
     * Search narrows the holders AND the headcount.
     *
     * Worth stating plainly because this endpoint's `total` has a second job:
     * the record page renders it as the "Users with this role" stat. A SEARCHED
     * total is the number of MATCHING holders, and that is the correct answer —
     * a total that stayed at the headcount would describe a list the caller is
     * not looking at.
     */
    public function testAssignmentsSearchNarrowsTheRowsAndTheTotal(): void
    {
        $roleId = $this->seedHolders();

        self::assertSame(4, $this->json($this->assignments($roleId, ''))['pagination']['total']);

        $byName = $this->json($this->assignments($roleId, 'q=grace'));
        self::assertSame(['Grace'], array_column($byName['data'], 'displayName'));
        self::assertSame(1, $byName['pagination']['total']);

        $byEmail = $this->json($this->assignments($roleId, 'q=BRENDAN@'));
        self::assertSame(['Brendan'], array_column($byEmail['data'], 'displayName'));
        self::assertSame(1, $byEmail['pagination']['total']);

        $none = $this->json($this->assignments($roleId, 'q=nobody'));
        self::assertSame([], $none['data']);
        self::assertSame(0, $none['pagination']['total']);
    }

    /**
     * A holder with NO primary email survives the search-capable COUNT.
     *
     * The count gained the SELECT's joins so the search can read `pe.email`. If
     * that had been an INNER JOIN it would have dropped this person from the
     * list AND from the headcount — the quiet kind of wrong the original
     * LEFT JOIN was chosen to avoid.
     */
    public function testAssignmentsStillCountAHolderWithNoPrimaryEmail(): void
    {
        $roleId = $this->seedHolders();
        $this->seedProfile(340, null, 'active', 'Emailless');
        $this->seedMembership(340, self::TENANT, $roleId, '2026-01-09 00:00:00');

        $body = $this->json($this->assignments($roleId, 'per_page=50'));

        self::assertSame(5, $body['pagination']['total']);
        self::assertContains('Emailless', array_column($body['data'], 'displayName'));
        self::assertNull(
            $body['data'][array_search('Emailless', array_column($body['data'], 'displayName'), true)]['email']
        );
    }

    /**
     * Paging over holders granted at the SAME instant sees each exactly once.
     *
     * A bulk grant, an import or a seeder writes every membership with one
     * timestamp, which ties the endpoint's default sort completely — the worst
     * case for `LIMIT/OFFSET`, and the one a long holder list is most likely to
     * be in.
     */
    public function testAssignmentsPagingOverAnIdenticalGrantTimeSeesEveryoneExactlyOnce(): void
    {
        $roleId = $this->seedTiedHolders(9);

        $seen = [];
        for ($page = 1; $page <= 4; $page++) {
            foreach ($this->json($this->assignments($roleId, "per_page=3&page={$page}"))['data'] as $row) {
                $seen[] = (int) $row['profileId'];
            }
        }

        sort($seen);
        self::assertSame(range(600, 608), $seen);
    }

    // ==================================================================
    // GET /api/user-groups
    // ==================================================================

    /** Every sort key the groups endpoint offers reorders it. */
    public function testUserGroupsSortByEveryOfferedKeyReorders(): void
    {
        $this->seedGroups();

        self::assertSame(
            ['Auditors', 'Deans', 'Instructors', 'Wardens'],
            $this->groupNames('sort=name&dir=asc')
        );
        self::assertSame(
            ['Wardens', 'Instructors', 'Deans', 'Auditors'],
            $this->groupNames('sort=name&dir=desc')
        );
        self::assertSame(
            $this->groupNames('sort=name&dir=asc'),
            $this->groupNames(''),
            'name ascending is the default this list has always used'
        );

        // rule: two `explicit` groups and two `role` ones. Ordering by the KIND
        // groups the rows that render the same label together — the honest limit
        // of sorting a client-localised column on the server.
        self::assertSame(
            ['explicit', 'explicit', 'role', 'role'],
            $this->groupField('rule_kind', 'sort=rule&dir=asc')
        );
        self::assertSame(
            ['role', 'role', 'explicit', 'explicit'],
            $this->groupField('rule_kind', 'sort=rule&dir=desc')
        );
    }

    /** An unknown key falls back to name ascending. */
    public function testUserGroupsUnknownSortKeyFallsBackToTheDefault(): void
    {
        $this->seedGroups();

        $response = $this->userGroups('sort=rule_config');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($this->groupNames(''), array_column($this->json($response)['data'], 'name'));
    }

    /** Search narrows the rows and the total, over name and description. */
    public function testUserGroupsSearchNarrowsTheRowsAndTheTotal(): void
    {
        $this->seedGroups();

        self::assertSame(4, $this->json($this->userGroups(''))['pagination']['total']);

        $byName = $this->json($this->userGroups('q=ward'));
        self::assertSame(['Wardens'], array_column($byName['data'], 'name'));
        self::assertSame(1, $byName['pagination']['total']);

        $byDescription = $this->json($this->userGroups('q=EVERY faculty'));
        self::assertSame(['Deans'], array_column($byDescription['data'], 'name'));
        self::assertSame(1, $byDescription['pagination']['total'], 'description matches, case-insensitively');

        $paged = $this->json($this->userGroups('q=s&per_page=2'));
        self::assertCount(2, $paged['data']);
        self::assertSame(4, $paged['pagination']['total']);
        self::assertSame(2, $paged['pagination']['totalPages']);

        $none = $this->json($this->userGroups('q=no-such-group'));
        self::assertSame([], $none['data']);
        self::assertSame(0, $none['pagination']['total']);
    }

    /**
     * The search does not escape the tenant.
     *
     * The repository's `tenant_id` predicate and the search predicate live in the
     * same WHERE now, and a search that ANDed on the wrong side of it would
     * return a neighbouring tenant's groups to whoever typed the right word.
     */
    public function testUserGroupsSearchStaysInsideTheTenant(): void
    {
        $this->seedGroups();
        $this->seedGroup('Wardens', 'another tenant entirely', 'role', self::OTHER_TENANT);

        $body = $this->json($this->userGroups('q=ward'));

        self::assertSame(['Wardens'], array_column($body['data'], 'name'));
        self::assertSame(1, $body['pagination']['total']);
        self::assertSame(self::TENANT, $body['data'][0]['tenant_id']);
    }

    /** Paging over a tied rule kind sees every group exactly once. */
    public function testUserGroupsPagingOverATiedRuleKindSeesEveryGroupExactlyOnce(): void
    {
        $ids = [];
        for ($i = 0; $i < 9; $i++) {
            $ids[] = $this->seedGroup(sprintf('Tied %02d', $i), 'tied', 'role', self::TENANT);
        }
        sort($ids);

        $seen = [];
        for ($page = 1; $page <= 5; $page++) {
            foreach ($this->json($this->userGroups("sort=rule&per_page=2&page={$page}"))['data'] as $row) {
                $seen[] = (int) $row['id'];
            }
        }

        sort($seen);
        self::assertSame($ids, $seen);
    }

    /** The groups envelope is unchanged. */
    public function testUserGroupsEnvelopeIsUnchanged(): void
    {
        $this->seedGroups();

        $body = $this->json($this->userGroups('page=2&per_page=3'));

        self::assertSame(['data', 'pagination'], array_keys($body));
        self::assertSame(
            ['page' => 2, 'perPage' => 3, 'total' => 4, 'totalPages' => 2],
            $body['pagination']
        );
    }

    // ==================================================================
    // fixtures
    // ==================================================================

    /**
     * Four people whose alphabetical, creation and role orders all disagree.
     *
     * Deliberate, so a sort key that did nothing would be indistinguishable from
     * no sort at all in exactly one ordering and caught in the other three.
     */
    private function seedUsers(): void
    {
        $viewer = $this->seedRole('viewer', 'reads things', self::TENANT, '2026-01-01 00:00:00');
        $editor = $this->seedRole('editor', 'writes things', self::TENANT, '2026-01-02 00:00:00');

        $this->seedProfile(301, 'bo@example.test');
        $this->seedProfile(302, 'cy@example.test');
        $this->seedProfile(303, 'ana@example.test');
        $this->seedProfile(304, 'dee@example.test', 'inactive');

        $this->seedMembership(301, self::TENANT, $viewer, '2026-02-01 00:00:00');
        $this->seedMembership(302, self::TENANT, $editor, '2026-02-02 00:00:00');
        $this->seedMembership(303, self::TENANT, $viewer, '2026-02-03 00:00:00');
        $this->seedMembership(304, self::TENANT, $editor, '2026-02-04 00:00:00');
    }

    /** N people who all hold the SAME role, so sorting by role ties completely. */
    private function seedTiedUsers(int $count): void
    {
        $role = $this->seedRole('shared', 'one role for everybody', self::TENANT, '2026-01-01 00:00:00');

        for ($i = 0; $i < $count; $i++) {
            $this->seedProfile(500 + $i, sprintf('tied%02d@example.test', $i));
            $this->seedMembership(500 + $i, self::TENANT, $role, '2026-03-01 00:00:00');
        }
    }

    /** Five roles: three distinct descriptions and one shared by two rows. */
    private function seedRoles(): void
    {
        $this->seedRole('alpha', 'aaa first', self::TENANT, '2026-01-01 00:00:00');
        $this->seedRole('beta', 'mmm middle', self::TENANT, '2026-01-02 00:00:00');
        $this->seedRole('gamma', 'zzz last', self::TENANT, '2026-01-03 00:00:00');
        $this->seedRole('delta-shared', 'shared blurb', self::TENANT, '2026-01-04 00:00:00');
        $this->seedRole('epsilon-shared', 'shared blurb', self::TENANT, '2026-01-05 00:00:00');
    }

    /**
     * N roles sharing one description, so `sort=description` ties completely.
     *
     * @return list<int> their ids, ascending
     */
    private function seedTiedRoles(int $count): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->seedRole(
                sprintf('tied-%02d', $i),
                'one description for all of them',
                self::TENANT,
                '2026-04-01 00:00:00'
            );
        }
        sort($ids);

        return $ids;
    }

    /** Four holders of one role, alphabetical and chronological orders disagreeing. */
    private function seedHolders(): int
    {
        $roleId = $this->seedRole('holdable', 'held by four people', self::TENANT, '2026-01-01 00:00:00');

        $this->seedProfile(310, 'ada@example.test', 'active', 'Ada');
        $this->seedProfile(311, 'linus@example.test', 'active', 'Linus');
        $this->seedProfile(312, 'grace@example.test', 'active', 'Grace');
        $this->seedProfile(313, 'brendan@example.test', 'active', 'Brendan');

        $this->seedMembership(310, self::TENANT, $roleId, '2026-01-03 00:00:00');
        $this->seedMembership(311, self::TENANT, $roleId, '2026-01-05 00:00:00');
        $this->seedMembership(312, self::TENANT, $roleId, '2026-01-07 00:00:00');
        $this->seedMembership(313, self::TENANT, $roleId, '2026-01-01 00:00:00');

        return $roleId;
    }

    /** N holders granted the role at the SAME instant. */
    private function seedTiedHolders(int $count): int
    {
        $roleId = $this->seedRole('bulk-granted', 'given to everybody at once', self::TENANT, '2026-01-01 00:00:00');

        for ($i = 0; $i < $count; $i++) {
            $this->seedProfile(600 + $i, sprintf('bulk%02d@example.test', $i), 'active', sprintf('Bulk %02d', $i));
            $this->seedMembership(600 + $i, self::TENANT, $roleId, '2026-07-01 12:00:00');
        }

        return $roleId;
    }

    /** Four groups: two rule kinds, alphabetical order disagreeing with kind order. */
    private function seedGroups(): void
    {
        $this->seedGroup('Instructors', 'everyone holding the instructor role', 'role', self::TENANT);
        $this->seedGroup('Auditors', 'a hand-picked committee', 'explicit', self::TENANT);
        $this->seedGroup('Wardens', 'a hand-picked pair', 'explicit', self::TENANT);
        $this->seedGroup('Deans', 'the head of every faculty', 'role', self::TENANT);
    }

    private function seedGroup(string $name, string $description, string $kind, int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_groups
                 (tenant_id, name, description, rule_kind, rule_config, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, NULL, NOW(), NOW())'
        );
        $stmt->execute([$tenantId, $name, $description, $kind, '{}']);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedRole(string $name, string $description, ?int $tenantId, string $createdAt): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, $description, $tenantId, $createdAt]);

        return (int) $this->pdo->lastInsertId();
    }

    private function roleId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function seedProfile(
        int $id,
        ?string $email,
        string $status = 'active',
        ?string $displayName = null
    ): void {
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, ?, NOW(), NOW())"
        )->execute([$id, $displayName ?? ($email === null ? 'p' . $id : (strstr($email, '@', true) ?: $email)), $status]);

        if ($email === null) {
            return;
        }

        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$id, $email]);
    }

    private function seedMembership(int $profileId, int $tenantId, int $roleId, string $createdAt): void
    {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, 'active', ?)"
        )->execute([$profileId, $tenantId, $roleId, $createdAt]);
    }

    // ==================================================================
    // drivers
    // ==================================================================

    private function users(string $query): Response
    {
        return $this->usersHandler()->list($this->request('GET', '/api/users?' . $query));
    }

    private function roles(string $query): Response
    {
        return $this->rolesHandler()->list($this->request('GET', '/api/roles?' . $query));
    }

    private function assignments(int $roleId, string $query): Response
    {
        return $this->rolesHandler()->assignments(
            $this->request('GET', "/api/roles/{$roleId}/assignments?" . $query),
            ['id' => (string) $roleId]
        );
    }

    private function userGroups(string $query): Response
    {
        return $this->groupsHandler()->index($this->request('GET', '/api/user-groups?' . $query));
    }

    /** @return list<string> */
    private function userEmails(string $query): array
    {
        return $this->emailsOf($this->users($query));
    }

    /** @return list<string> */
    private function emailsOf(Response $response): array
    {
        return array_column($this->json($response)['data'], 'email');
    }

    /** @return list<mixed> */
    private function userField(string $field, string $query): array
    {
        return array_column($this->json($this->users($query))['data'], $field);
    }

    /** @return list<string> */
    private function roleNames(string $query): array
    {
        return $this->namesOf($this->roles($query));
    }

    /** @return list<string> */
    private function namesOf(Response $response): array
    {
        return array_column($this->json($response)['data'], 'name');
    }

    /** @return list<mixed> */
    private function holderField(int $roleId, string $field, string $query): array
    {
        return array_column($this->json($this->assignments($roleId, $query))['data'], $field);
    }

    /** @return list<string> */
    private function groupNames(string $query): array
    {
        return array_column($this->json($this->userGroups($query))['data'], 'name');
    }

    /** @return list<mixed> */
    private function groupField(string $field, string $query): array
    {
        return array_column($this->json($this->userGroups($query))['data'], $field);
    }

    /**
     * The names THIS suite seeded, in the order the endpoint returned them.
     *
     * The roles list carries the global base roles the migrations ship with, and
     * an assertion that pinned those would be testing the seed data. Filtering
     * to the fixture's own names keeps the relative order — which is the whole
     * claim a sort makes — without asserting how many roles the platform ships.
     *
     * @param list<string> $returned
     * @param list<string> $extra Fixture names beyond the five seedRoles() makes.
     * @return list<string>
     */
    private function mine(array $returned, array $extra = []): array
    {
        $names = array_merge(
            ['alpha', 'beta', 'gamma', 'delta-shared', 'epsilon-shared'],
            $extra
        );

        return array_values(array_intersect($returned, $names));
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }

    /**
     * @param list<int> $values
     * @return list<int>
     */
    private function sortedInts(array $values): array
    {
        sort($values);

        return $values;
    }

    // ==================================================================
    // wiring
    // ==================================================================

    private function usersHandler(): UsersApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new UsersApiHandler($this->pdo, $hooks);
    }

    private function rolesHandler(): RolesApiHandler
    {
        $hooks = $this->createMock(HookManager::class);
        $hooks->method('dispatch')->willReturnArgument(1);
        $hooks->method('dispatchAsync');

        return new RolesApiHandler($this->pdo, $hooks);
    }

    /**
     * The groups handler with its REAL collaborators, wired as
     * public/index.php wires them.
     *
     * `index()` touches none of the resolvers, but constructing them for real
     * costs thirty lines and removes the question of whether a stub is why this
     * passes.
     */
    private function groupsHandler(): UserGroupsApiHandler
    {
        $groups = new UserGroupRepository($this->pdo);
        $rules = new RoutingRuleRegistry();
        $resolver = new GroupResolver($this->pdo, $groups, static fn (): RoutingRuleRegistry => $rules);
        $rules->registerCoreRoutingRules(
            new RoleRuleResolver($this->pdo),
            new RoleBelowActorRuleResolver($this->pdo),
            new ExplicitRuleResolver(),
            new GroupRuleResolver($resolver)
        );

        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return new UserGroupsApiHandler(
            $this->pdo,
            $groups,
            $resolver,
            $rules,
            new SettingsService(
                new GlobalSettingsRepository($this->pdo),
                new TenantSettingsRepository($this->pdo)
            ),
            new RoleChecker($db, new PermissionRegistry()),
            new ServerLabels(new LanguageRegistry(
                new LanguageRepository($this->pdo),
                new TranslationRepository($this->pdo),
                new StaticTenantContextAdapter(),
            ))
        );
    }

    private function request(string $method, string $path): Request
    {
        $request = new Request($method, $path, [], '');
        $request->user = (object) ['profile_id' => self::CALLER, 'active_tenant_id' => TenantContext::getTenantId()];

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

    /**
     * One integer out of a scalar query.
     *
     * `PDO::query()` can return false, and PHPStan says so. Asserting it here
     * keeps the measured baselines above readable while giving a failed query a
     * message of its own instead of a silent zero.
     */
    private function scalar(string $sql): int
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, "query failed: {$sql}");

        return (int) $stmt->fetchColumn();
    }
}
