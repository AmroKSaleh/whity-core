<?php

declare(strict_types=1);

namespace Tests\Core\RBAC;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\RoleCheckerPermissionResolver;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * WC-712 (issue #712 §3): permission RESOLUTION must be reachable by plugins,
 * and must give the SAME answer the RBAC middleware enforces.
 *
 * Before this change a plugin needing an authorization decision INSIDE a handler
 * — rather than the flat route-level `requiredPermission` gate — had no way to
 * ask the host: RoleChecker was hand-constructed in public/index.php and handed
 * only to RbacMiddleware, never registered in the \Whity\app() service registry,
 * and plugins receive only a raw PDO. The only remaining option was to re-derive
 * the answer in hand-written SQL, which drifts from what is actually enforced
 * and leaves the system holding two different answers to the same authorization
 * question.
 *
 * These tests pin the property that closes that gap: for every question the
 * resolver can be asked, its answer is IDENTICAL to the one a real RbacMiddleware
 * reaches on a real route, across the full resolution surface —
 *
 *   - direct membership role,
 *   - role-hierarchy inheritance,
 *   - organizational-unit (and ancestor-OU) role inheritance,
 *   - live, non-revoked delegations,
 *   - suspended-membership denial,
 *   - cross-tenant isolation,
 *   - permission-catalogue gating.
 *
 * Runs on in-memory SQLite via SchemaFromMigrations and, under PHPUNIT_PG_DSN
 * (the CI postgres-integration job), on real PostgreSQL.
 */
final class PermissionResolverTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const JWT_SECRET = 'permission-resolver-test-secret-min-32-bytes-long';

    private PDO $pdo;
    private Database $db;
    private RoleChecker $roleChecker;
    private PermissionRegistry $registry;
    private RoleCheckerPermissionResolver $resolver;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();

        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
        $this->registry = $this->registry();

        // Exactly the production wiring: a delegation-UNAWARE bounding checker
        // feeding the delegation service, and a delegation-AWARE checker for
        // enforcement. Both the middleware and the resolver take the latter.
        $boundingChecker = new RoleChecker($this->db, $this->registry);
        $delegationService = new DelegationService(
            new DelegationRepository($this->pdo),
            $boundingChecker,
            $this->registry
        );
        $this->roleChecker = new RoleChecker($this->db, $this->registry, null, $delegationService);
        $this->resolver = new RoleCheckerPermissionResolver($this->roleChecker, $this->registry);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // =========================================================================
    // The contract type
    // =========================================================================

    /**
     * The host implementation must satisfy the SDK contract — that is what lets
     * an out-of-repo plugin type-hint it with only whity/plugin-sdk installed.
     */
    public function testHostResolverSatisfiesTheSdkContract(): void
    {
        $this->assertInstanceOf(PermissionResolver::class, $this->resolver);
    }

    /**
     * The contract stays READ-ONLY: it must never expose cache invalidation or a
     * database handle. Handing plugins RoleChecker itself would leak both.
     */
    public function testContractExposesOnlyReadOnlyQuestions(): void
    {
        $methods = array_map(
            static fn (\ReflectionMethod $m): string => $m->getName(),
            (new \ReflectionClass(PermissionResolver::class))->getMethods()
        );
        sort($methods);

        $this->assertSame(
            ['effectivePermissions', 'hasPermission', 'hasRole'],
            $methods,
            'PermissionResolver must expose exactly three read-only questions — no clearCache, no PDO.'
        );
    }

    /**
     * Resource-scoped arguments exist as of WC-712 §2 / SDK 1.17 — and the
     * invariant this test has always protected is unchanged: they must never be
     * SILENTLY IGNORED. An accepted-then-discarded $resourceId would hand a
     * caller the tenant-wide answer while it believed it held a record-scoped
     * one, which fails OPEN.
     *
     * Until resolution could honour them the only safe contract was to omit
     * them; now that `resource_role_assignments` exists they are honoured, so
     * this pins BOTH the shape and — in the behavioural half below — that they
     * actually change the answer.
     */
    public function testContractExposesResourceScopeArguments(): void
    {
        $parameters = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(PermissionResolver::class, 'hasPermission'))->getParameters()
        );

        $this->assertSame(
            ['profileId', 'tenantId', 'permission', 'resourceType', 'resourceId'],
            $parameters
        );

        // Additive only: a caller written against SDK 1.16 must keep compiling
        // and keep getting the tenant-wide answer.
        $method = new \ReflectionMethod(PermissionResolver::class, 'hasPermission');
        $this->assertTrue(
            $method->getParameters()[3]->isOptional() && $method->getParameters()[4]->isOptional(),
            'Resource arguments must be optional so existing callers are unaffected.'
        );

        // The same must hold for effectivePermissions(), or the documented parity
        // identity between the two methods cannot be expressed at resource scope.
        $effective = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(PermissionResolver::class, 'effectivePermissions'))->getParameters()
        );
        $this->assertSame(['profileId', 'tenantId', 'resourceType', 'resourceId'], $effective);
    }

    /**
     * SDK 1.22: the ROLE side takes the same pair.
     *
     * 1.17 fitted the resource scope to hasPermission()/effectivePermissions()
     * only. RoleChecker::getEffectiveRolesForProfile() accepted a scope from the
     * same change, but hasRoleForProfile() called it with no resource arguments —
     * so a role granted at ONE record was representable in storage, resolvable by
     * the machinery, and unreachable through the method a caller would use. The
     * asymmetry, not any missing storage, is what made per-record role holding
     * look like it needed a `memberships` schema change.
     */
    public function testContractExposesResourceScopeOnTheRoleSideToo(): void
    {
        $method = new \ReflectionMethod(PermissionResolver::class, 'hasRole');

        $this->assertSame(
            ['profileId', 'tenantId', 'role', 'resourceType', 'resourceId'],
            array_map(
                static fn (\ReflectionParameter $p): string => $p->getName(),
                $method->getParameters()
            )
        );

        // Additive: every existing three-argument caller must keep compiling and
        // keep getting the tenant-wide answer.
        $this->assertTrue(
            $method->getParameters()[3]->isOptional() && $method->getParameters()[4]->isOptional(),
            'Resource arguments must be optional so three-argument callers are unaffected.'
        );

        // The host method behind it must accept the scope as well — passing it
        // through is the whole change.
        $checkerParameters = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(RoleChecker::class, 'hasRoleForProfile'))->getParameters()
        );
        $this->assertSame(
            ['profileId', 'requiredRole', 'tenantId', 'resourceType', 'resourceId'],
            $checkerParameters
        );
    }

    // =========================================================================
    // Parity with the enforcement path (the whole point)
    // =========================================================================

    /**
     * The resolver's answer equals the middleware's verdict across the full
     * resolution surface. Each case seeds a real membership/role/OU/delegation
     * shape, then compares the boolean the resolver returns with whether a REAL
     * RbacMiddleware lets a REAL request through a route gated on that same
     * permission. A single disagreement anywhere is the defect this closes.
     */
    public function testResolverAgreesWithRbacMiddlewareAcrossTheResolutionSurface(): void
    {
        // ── direct membership role ───────────────────────────────────────────
        $direct = $this->seedProfile('direct@example.com');
        $this->addMembership($direct, self::TENANT_A, 'viewer');
        $this->grantPermission('viewer', 'users:read');

        // ── role hierarchy: editor's parent is viewer, so editor inherits ────
        $inheritor = $this->seedProfile('hierarchy@example.com');
        $this->addMembership($inheritor, self::TENANT_A, 'editor');
        $this->pdo->prepare('UPDATE roles SET parent_id = ? WHERE id = ?')
            ->execute([$this->roleId('viewer'), $this->roleId('editor')]);
        $this->grantPermission('editor', 'posts:write');

        // ── OU-inherited role (membership OU carries the admin role) ─────────
        $ouMember = $this->seedProfile('ou@example.com');
        $this->pdo->prepare(
            "INSERT INTO organizational_units (id, tenant_id, name, slug, created_at)
             VALUES (70, ?, 'Engineering', 'eng', datetime('now'))"
        )->execute([self::TENANT_A]);
        $this->pdo->prepare(
            "INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at)
             VALUES (?, 70, ?, datetime('now'))"
        )->execute([self::TENANT_A, $this->roleId('admin')]);
        $this->addMembership($ouMember, self::TENANT_A, 'viewer', 70);
        $this->grantPermission('admin', 'users:delete');

        // ── delegation: a viewer holds users:delete only via a live grant ────
        $grantor = $this->seedProfile('grantor@example.com');
        $this->addMembership($grantor, self::TENANT_A, 'admin');
        $grantee = $this->seedProfile('grantee@example.com');
        $this->addMembership($grantee, self::TENANT_A, 'viewer');
        $this->delegate($grantor, $grantee, 'users:delete', self::TENANT_A);

        // ── suspended membership ─────────────────────────────────────────────
        $suspended = $this->seedProfile('suspended@example.com');
        $membershipId = $this->addMembership($suspended, self::TENANT_A, 'admin');
        $this->pdo->prepare("UPDATE memberships SET status = 'suspended' WHERE id = ?")
            ->execute([$membershipId]);

        // ── no membership in tenant A at all ─────────────────────────────────
        $stranger = $this->seedProfile('stranger@example.com');
        $this->addMembership($stranger, self::TENANT_B, 'admin');

        RoleChecker::clearCache();

        /** @var list<array{0: int, 1: string, 2: bool, 3: string}> $cases */
        $cases = [
            [$direct,    'users:read',   true,  'direct membership role grant'],
            [$direct,    'users:delete', false, 'permission the membership role does not hold'],
            [$inheritor, 'users:read',   true,  'permission inherited through the role hierarchy'],
            [$inheritor, 'posts:write',  true,  "the inheriting role's own grant"],
            [$ouMember,  'users:delete', true,  'role inherited from the membership OU'],
            [$ouMember,  'users:read',   true,  'direct role grant alongside the OU-inherited one'],
            [$grantee,   'users:delete', true,  'live delegation'],
            [$grantor,   'users:delete', true,  "the grantor's own role grant"],
            [$suspended, 'users:delete', false, 'suspended membership grants nothing'],
            [$stranger,  'users:delete', false, 'no membership in the acting tenant'],
        ];

        foreach ($cases as [$profileId, $permission, $expected, $why]) {
            $resolverSaysYes = $this->resolver->hasPermission($profileId, self::TENANT_A, $permission);
            $middlewareSaysYes = $this->middlewareAllows($profileId, self::TENANT_A, $permission);

            $this->assertSame(
                $expected,
                $resolverSaysYes,
                "Resolver gave the wrong answer for {$why} ({$permission})."
            );
            $this->assertSame(
                $middlewareSaysYes,
                $resolverSaysYes,
                "Resolver and RbacMiddleware disagree about {$why} ({$permission}) — "
                . 'that divergence is the defect WC-712 closes.'
            );
        }
    }

    /**
     * hasRole() tracks the middleware's requiredRole gate too, including the
     * OU-inherited role — a plugin gating on a role must not see a different
     * role set from the one the route gate uses.
     */
    public function testHasRoleAgreesWithTheMiddlewareRoleGate(): void
    {
        $profileId = $this->seedProfile('roles@example.com');
        $this->pdo->prepare(
            "INSERT INTO organizational_units (id, tenant_id, name, slug, created_at)
             VALUES (80, ?, 'Ops', 'ops', datetime('now'))"
        )->execute([self::TENANT_A]);
        $this->pdo->prepare(
            "INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at)
             VALUES (?, 80, ?, datetime('now'))"
        )->execute([self::TENANT_A, $this->roleId('admin')]);
        $this->addMembership($profileId, self::TENANT_A, 'viewer', 80);

        RoleChecker::clearCache();

        foreach (['viewer' => true, 'admin' => true, 'editor' => false] as $role => $expected) {
            $this->assertSame(
                $expected,
                $this->resolver->hasRole($profileId, self::TENANT_A, $role),
                "hasRole() gave the wrong answer for '{$role}'."
            );
            $this->assertSame(
                $this->middlewareAllowsRole($profileId, self::TENANT_A, $role),
                $this->resolver->hasRole($profileId, self::TENANT_A, $role),
                "hasRole() and the middleware's requiredRole gate disagree about '{$role}'."
            );
        }
    }

    /**
     * The same agreement, asked AT one record (SDK 1.22).
     *
     * The route-level `requiredRole` gate is FLAT — it asks the tenant-wide
     * question and knows nothing about a record — so a role held only at a
     * resource is invisible to it, exactly as it should be. What must hold is
     * that hasRole() agrees with the EFFECTIVE ROLE SET at whichever scope it is
     * asked, and that the two scopes cannot be confused for one another:
     *
     *     in_array($r, getEffectiveRolesForProfile($p, $t, $ty, $rid), true)
     *         === hasRole($p, $t, $r, $ty, $rid)
     *
     * Before this change the right-hand side could not express the scope at all.
     */
    public function testHasRoleAgreesWithTheEffectiveRoleSetAtBothScopes(): void
    {
        $profileId = $this->seedProfile('scoped-roles@example.com');
        $this->addMembership($profileId, self::TENANT_A, 'viewer');

        // 'editor' is held ONLY at this one record.
        $this->grantRoleAtResource($profileId, self::TENANT_A, 'doc', 4242, 'editor');

        RoleChecker::clearCache();

        foreach (['viewer', 'editor', 'admin'] as $role) {
            $this->assertSame(
                in_array(
                    $role,
                    $this->roleChecker->getEffectiveRolesForProfile($profileId, self::TENANT_A),
                    true
                ),
                $this->resolver->hasRole($profileId, self::TENANT_A, $role),
                "Unscoped parity must hold for '{$role}'."
            );

            $this->assertSame(
                in_array(
                    $role,
                    $this->roleChecker->getEffectiveRolesForProfile($profileId, self::TENANT_A, 'doc', 4242),
                    true
                ),
                $this->resolver->hasRole($profileId, self::TENANT_A, $role, 'doc', 4242),
                "Scoped parity must hold for '{$role}'."
            );
        }

        // The capability itself: askable at the record, absent tenant-wide.
        $this->assertTrue($this->resolver->hasRole($profileId, self::TENANT_A, 'editor', 'doc', 4242));
        $this->assertFalse($this->resolver->hasRole($profileId, self::TENANT_A, 'editor'));

        // And the flat route gate is untouched by a grant it cannot address.
        $this->assertFalse(
            $this->middlewareAllowsRole($profileId, self::TENANT_A, 'editor'),
            "The route-level requiredRole gate asks the tenant-wide question; a record-scoped "
            . 'grant must not silently open a whole route.'
        );
    }

    // =========================================================================
    // The resolver must not become its OWN source of divergence
    // =========================================================================

    /**
     * The documented equivalence:
     *
     *     in_array($p, $r->effectivePermissions($id, $t), true)
     *         === $r->hasPermission($id, $t, $p)
     *
     * RoleChecker::getEffectivePermissionsForProfile() returns the RAW resolved
     * set (core's document designer stores arbitrary tenant-defined tags in the
     * same column and needs them back verbatim), while hasPermissionForProfile()
     * is gated on the permission catalogue. Handing the raw set straight through
     * would have recreated, inside this very contract, the divergence it exists
     * to close: a stale role_permissions row naming a permission no longer
     * declared by core or by any loaded plugin would appear granted by one method
     * and denied by the other.
     */
    public function testEffectivePermissionsIsExactlyTheSetHasPermissionAnswersTrueFor(): void
    {
        $profileId = $this->seedProfile('equivalence@example.com');
        $this->addMembership($profileId, self::TENANT_A, 'admin');

        $this->grantPermission('admin', 'users:read');
        $this->grantPermission('admin', 'users:delete');
        // A grant naming a permission NO source declares any more — exactly what
        // an uninstalled plugin or a removed core permission leaves behind.
        $this->grantPermission('admin', 'retired_plugin:manage');

        RoleChecker::clearCache();

        $effective = $this->resolver->effectivePermissions($profileId, self::TENANT_A);

        $this->assertContains('users:read', $effective);
        $this->assertContains('users:delete', $effective);
        $this->assertNotContains(
            'retired_plugin:manage',
            $effective,
            'A permission absent from the catalogue must not appear in the effective set — '
            . 'hasPermission() denies it, so the set must too.'
        );

        // The equivalence itself, over both granted and ungranted slugs.
        $probes = [
            'users:read',
            'users:delete',
            'users:write',
            'posts:write',
            'retired_plugin:manage',
            'never_granted:anything',
        ];
        foreach ($probes as $permission) {
            $this->assertSame(
                $this->resolver->hasPermission($profileId, self::TENANT_A, $permission),
                in_array($permission, $effective, true),
                "effectivePermissions() and hasPermission() disagree about '{$permission}'."
            );
        }

        // The raw checker really does still carry the ungated tag — proving the
        // filter is doing work rather than the row simply being absent.
        $this->assertContains(
            'retired_plugin:manage',
            $this->roleChecker->getEffectivePermissionsForProfile($profileId, self::TENANT_A),
            'RoleChecker keeps returning the raw set; the resolver is what gates it.'
        );
    }

    /**
     * effectivePermissions() is a list (sequential keys) after filtering — a
     * caller doing json_encode() must get an ARRAY, not an object with holes.
     */
    public function testEffectivePermissionsReturnsAList(): void
    {
        $profileId = $this->seedProfile('list@example.com');
        $this->addMembership($profileId, self::TENANT_A, 'admin');
        $this->grantPermission('admin', 'retired_plugin:manage'); // filtered out first
        $this->grantPermission('admin', 'users:read');

        RoleChecker::clearCache();

        $effective = $this->resolver->effectivePermissions($profileId, self::TENANT_A);

        $this->assertSame(
            array_keys($effective),
            range(0, count($effective) - 1),
            'The filtered set must be re-indexed so it serialises as a JSON array.'
        );
        $this->assertSame('[', substr((string) json_encode($effective), 0, 1));
    }

    // =========================================================================
    // Tenant isolation
    // =========================================================================

    /**
     * The resolver is tenant scoped exactly like the middleware: authority held
     * in tenant A — whether through the membership role, an OU role, or a
     * delegation — grants nothing when the acting tenant is B.
     */
    public function testAuthorityInOneTenantNeverLeaksIntoAnother(): void
    {
        $profileId = $this->seedProfile('isolation@example.com');
        $this->addMembership($profileId, self::TENANT_A, 'admin');
        $this->addMembership($profileId, self::TENANT_B, 'viewer');
        $this->grantPermission('admin', 'users:delete');
        $this->grantPermission('viewer', 'users:read');

        // A delegation that lives ONLY in tenant A.
        $grantor = $this->seedProfile('iso-grantor@example.com');
        $this->addMembership($grantor, self::TENANT_A, 'admin');
        $this->delegate($grantor, $profileId, 'tenants:manage', self::TENANT_A);

        RoleChecker::clearCache();

        $this->assertTrue($this->resolver->hasPermission($profileId, self::TENANT_A, 'users:delete'));
        $this->assertTrue($this->resolver->hasPermission($profileId, self::TENANT_A, 'tenants:manage'));

        $this->assertFalse(
            $this->resolver->hasPermission($profileId, self::TENANT_B, 'users:delete'),
            "Tenant A's role grant must not be visible when acting in tenant B."
        );
        $this->assertFalse(
            $this->resolver->hasPermission($profileId, self::TENANT_B, 'tenants:manage'),
            "Tenant A's delegation must not be visible when acting in tenant B."
        );
        $this->assertSame(
            ['users:read'],
            $this->resolver->effectivePermissions($profileId, self::TENANT_B),
            'The tenant B effective set must contain only what the tenant B membership grants.'
        );
    }

    // =========================================================================
    // Delegation awareness (the wiring the entry point must not get wrong)
    // =========================================================================

    /**
     * A resolver built over the delegation-UNAWARE bounding checker answers
     * differently from the middleware for a delegated permission. This is the
     * concrete failure mode PermissionResolverEntryPointWiringTest guards
     * against at the entry points — pinned here so the reason is executable, not
     * just a comment.
     */
    public function testResolverOverTheBoundingCheckerWouldDivergeOnDelegatedPermissions(): void
    {
        $grantor = $this->seedProfile('deleg-grantor@example.com');
        $grantee = $this->seedProfile('deleg-grantee@example.com');
        $this->addMembership($grantor, self::TENANT_A, 'admin');
        $this->addMembership($grantee, self::TENANT_A, 'viewer');
        $this->grantPermission('admin', 'users:delete');
        $this->delegate($grantor, $grantee, 'users:delete', self::TENANT_A);

        RoleChecker::clearCache();

        $this->assertTrue(
            $this->resolver->hasPermission($grantee, self::TENANT_A, 'users:delete'),
            'The production resolver is delegation-aware, matching the middleware.'
        );

        $boundingResolver = new RoleCheckerPermissionResolver(
            new RoleChecker($this->db, $this->registry),
            $this->registry
        );
        RoleChecker::clearCache();

        $this->assertFalse(
            $boundingResolver->hasPermission($grantee, self::TENANT_A, 'users:delete'),
            'A resolver over the bounding checker denies a delegated permission the middleware allows — '
            . 'wiring that instance into the container would reinstate the divergence.'
        );
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    /**
     * Run a REAL RbacMiddleware over a REAL request against a route gated on
     * $permission, and report whether the caller got through.
     */
    private function middlewareAllows(int $profileId, int $tenantId, string $permission): bool
    {
        return $this->runMiddleware($profileId, $tenantId, null, $permission);
    }

    private function middlewareAllowsRole(int $profileId, int $tenantId, string $role): bool
    {
        return $this->runMiddleware($profileId, $tenantId, $role, null);
    }

    private function runMiddleware(
        int $profileId,
        int $tenantId,
        ?string $requiredRole,
        ?string $requiredPermission
    ): bool {
        $jwtParser = new JwtParser(self::JWT_SECRET);
        $middleware = new RbacMiddleware($jwtParser, $this->roleChecker);

        $token = $jwtParser->create([
            'profile_id' => $profileId,
            'active_tenant_id' => $tenantId,
            'sub' => 'parity-test',
            'token_epoch' => 0,
        ]);

        $request = new Request('GET', '/api/parity', ['Authorization' => 'Bearer ' . $token]);

        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        try {
            $response = $middleware->handle(
                $request,
                static fn (Request $req): Response => new Response(200, 'ok'),
                $requiredRole,
                $requiredPermission
            );
        } finally {
            TenantContext::reset();
        }

        $status = $response->getStatusCode();
        $this->assertContains(
            $status,
            [200, 403],
            'The parity harness must produce an authorization verdict, not an auth error; got ' . $status
        );

        return $status === 200;
    }

    private function delegate(int $grantorProfileId, int $granteeProfileId, string $permission, int $tenantId): void
    {
        $this->pdo->prepare(
            "INSERT INTO permission_delegations
                 (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at)
             VALUES (?, ?, 'profile', ?, ?, datetime('now'))"
        )->execute([$tenantId, $grantorProfileId, $granteeProfileId, $permission]);
    }

    /**
     * Write a role grant addressed at ONE record, straight to the table.
     *
     * Deliberately not through ResourceRoleAssignmentRepository: this test is
     * about what RESOLUTION does with a grant row, not about the registry gate
     * the write boundary applies (which ResourceRoleGrantRealEngineTest covers).
     */
    private function grantRoleAtResource(
        int $profileId,
        int $tenantId,
        string $resourceType,
        int $resourceId,
        string $roleName
    ): void {
        $this->pdo->prepare(
            "INSERT INTO resource_role_assignments
                 (tenant_id, resource_type, resource_id, role_id, profile_id, created_at)
             VALUES (?, ?, ?, ?, ?, datetime('now'))"
        )->execute([$tenantId, $resourceType, $resourceId, $this->roleId($roleName), $profileId]);
    }

    private function registry(): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        $registry->register('test', [
            'users:read', 'users:write', 'users:delete',
            'roles:read', 'posts:write', 'tenants:manage',
        ]);
        return $registry;
    }

    private function seedProfile(string $email): int
    {
        $localPart = strstr($email, '@', true) ?: $email;
        $this->pdo->prepare(
            "INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version,
                  token_epoch, created_at, updated_at)
             VALUES (?, '', false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([$localPart]);
        return (int) $this->pdo->lastInsertId();
    }

    private function addMembership(int $profileId, int $tenantId, string $roleName, ?int $ouId = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, 'active', datetime('now'))"
        )->execute([$profileId, $tenantId, $this->roleId($roleName), $ouId]);
        return (int) $this->pdo->lastInsertId();
    }

    private function grantPermission(string $roleName, string $permission): void
    {
        $this->pdo->prepare(
            "INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, datetime('now'))"
        )->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            "INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at)
             VALUES (?, ?, datetime('now'))"
        )->execute([$this->roleId($roleName), $permId]);
    }

    private function roleId(string $roleName): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$roleName]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            throw new \RuntimeException("Role '{$roleName}' not found in test schema.");
        }
        return (int) $id;
    }

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();
        return $db;
    }

    private function makeSchema(): PDO
    {
        // STRINGIFY_FETCHES = true to mirror PostgreSQL's string-fetch behaviour.
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        // admin=1 and user=2 come from migration 001; add the roles these tests use.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES
            (1, 'admin',  '', NULL, datetime('now')),
            (2, 'user',   '', NULL, datetime('now')),
            (3, 'viewer', '', NULL, datetime('now')),
            (4, 'editor', '', NULL, datetime('now'))");

        return $pdo;
    }
}
