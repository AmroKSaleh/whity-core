<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\InvalidResourceTypeException;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\RoleCheckerPermissionResolver;
use Whity\Core\RBAC\RoleNotVisibleException;
use Whity\Database\Database;

/**
 * Real-engine tests for polymorphic resource-scoped role grants (WC-712 §2).
 *
 * Runs against a genuine SQL engine seeded from the REAL migrations (SQLite in
 * CI; real PostgreSQL when PHPUNIT_PG_DSN is set), so tenant predicates, the
 * nullable-profile_id semantics and the partial unique indexes are enforced by
 * the engine rather than mocked away.
 *
 * What these pin:
 *  - a grant on a NON-OU resource type resolves (the whole point of §2 — before
 *    this, authority could only be addressed at a tenant or an OU);
 *  - a cross-tenant grant attempt is REJECTED, and reported as not-found so the
 *    existence of another tenant's role is never disclosed;
 *  - resolver PARITY: the scoped and unscoped paths agree with each other and
 *    with hasPermission(), and the scoped set is a superset of the unscoped one;
 *  - a resource grant never substitutes for tenant membership.
 */
final class ResourceRoleGrantRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    /**
     * A plugin-declared type, deliberately NOT the built-in 'ou'.
     *
     * The CANONICAL (namespaced) key: the plugin declares the bare slug
     * `document` and the registry stores it under the plugin's namespace.
     */
    private const TYPE_DOCUMENT = 'testplugin:document';
    private const DOC_ID = 4242;

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $this->db = $this->wrapSqlite($this->pdo);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    // ==================== A grant on a NON-OU resource type ====================

    public function testGrantOnNonOuResourceTypeGrantsPermissionAtThatResource(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        // The profile's own membership role is 'viewer', which holds nothing.
        $profileId = $this->seedProfile('doc@a.example', 'viewer', self::TENANT_A);

        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            $profileId
        );

        $this->assertTrue(
            $this->checker()->hasPermissionForProfile(
                $profileId,
                'posts:write',
                self::TENANT_A,
                self::TYPE_DOCUMENT,
                self::DOC_ID
            ),
            'A grant on a plugin-declared resource type must resolve at that resource.'
        );
    }

    public function testGrantDoesNotLeakToADifferentResourceOfTheSameType(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        $profileId = $this->seedProfile('doc2@a.example', 'viewer', self::TENANT_A);

        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            $profileId
        );

        $this->assertFalse(
            $this->checker()->hasPermissionForProfile(
                $profileId,
                'posts:write',
                self::TENANT_A,
                self::TYPE_DOCUMENT,
                self::DOC_ID + 1
            ),
            'A grant addressed at one record must not answer for a different record.'
        );
    }

    public function testEveryoneGrantAppliesWithoutNamingAProfile(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        $profileId = $this->seedProfile('anyone@a.example', 'viewer', self::TENANT_A);

        // profile_id NULL — "everyone with access to this resource holds R here".
        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            null
        );

        $this->assertTrue(
            $this->checker()->hasPermissionForProfile(
                $profileId,
                'posts:write',
                self::TENANT_A,
                self::TYPE_DOCUMENT,
                self::DOC_ID
            ),
            'A NULL profile_id grant must apply to any member of the tenant at that resource.'
        );
    }

    public function testUnregisteredResourceTypeIsRejectedAtTheBoundary(): void
    {
        $this->expectException(InvalidResourceTypeException::class);

        $this->repository()->grant(
            self::TENANT_A,
            'not_registered_anywhere',
            self::DOC_ID,
            $this->roleId('editor'),
            null
        );
    }

    // ==================== Cross-tenant rejection ====================

    public function testCrossTenantRoleGrantIsRejected(): void
    {
        // A role private to tenant B. Tenant A must not be able to attach it to
        // its own resource — the guard OusApiHandler::assignRole() enforces.
        $foreignRoleId = $this->seedTenantRole('tenant-b-private', self::TENANT_B);

        $this->expectException(RoleNotVisibleException::class);

        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $foreignRoleId,
            null
        );
    }

    public function testCrossTenantRejectionDoesNotDiscloseRoleExistence(): void
    {
        $foreignRoleId = $this->seedTenantRole('tenant-b-secret', self::TENANT_B);
        $missingRoleId = 987654;

        $foreignMessage = null;
        try {
            $this->repository()->grant(self::TENANT_A, self::TYPE_DOCUMENT, self::DOC_ID, $foreignRoleId);
        } catch (RoleNotVisibleException $e) {
            $foreignMessage = $e->getMessage();
        }

        $missingMessage = null;
        try {
            $this->repository()->grant(self::TENANT_A, self::TYPE_DOCUMENT, self::DOC_ID, $missingRoleId);
        } catch (RoleNotVisibleException $e) {
            $missingMessage = $e->getMessage();
        }

        // Both must read as "not found": a different message for the foreign role
        // would confirm it exists in some other tenant.
        self::assertNotNull($foreignMessage);
        self::assertNotNull($missingMessage);
        self::assertSame(
            str_replace((string) $foreignRoleId, 'ID', $foreignMessage),
            str_replace((string) $missingRoleId, 'ID', $missingMessage),
            'A foreign role and a nonexistent role must be indistinguishable to the caller.'
        );
    }

    public function testGrantWrittenForAnotherTenantIsNotResolvableByThatTenant(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        $profileInB = $this->seedProfile('b@b.example', 'viewer', self::TENANT_B);

        // Tenant A grants at the same resource_type/id. Because tenant_id is the
        // CALLER's, the row belongs to A and B's resolution must not see it.
        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            null
        );

        $this->assertFalse(
            $this->checker()->hasPermissionForProfile(
                $profileInB,
                'posts:write',
                self::TENANT_B,
                self::TYPE_DOCUMENT,
                self::DOC_ID
            ),
            'A grant written by tenant A must never resolve for tenant B at the same resource id.'
        );
    }

    public function testResourceGrantIsNoSubstituteForMembership(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        // A profile with NO membership in tenant A at all.
        $outsiderId = $this->seedProfile('outsider@b.example', 'viewer', self::TENANT_B);

        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            $outsiderId
        );

        $this->assertFalse(
            $this->checker()->hasPermissionForProfile(
                $outsiderId,
                'posts:write',
                self::TENANT_A,
                self::TYPE_DOCUMENT,
                self::DOC_ID
            ),
            'A resource grant must not become a back door into a tenant the profile does not belong to.'
        );
    }

    // ==================== Resolver parity ====================

    public function testResolverParityBetweenScopedAndUnscopedPaths(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        $this->grantPermissionToRole('viewer', 'posts:read');
        $profileId = $this->seedProfile('parity@a.example', 'viewer', self::TENANT_A);

        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            $profileId
        );

        $resolver = $this->resolver();

        // The identity documented on the interface must hold at BOTH scopes.
        foreach (['posts:read', 'posts:write'] as $permission) {
            $unscopedSet = $resolver->effectivePermissions($profileId, self::TENANT_A);
            self::assertSame(
                in_array($permission, $unscopedSet, true),
                $resolver->hasPermission($profileId, self::TENANT_A, $permission),
                "Unscoped parity must hold for {$permission}."
            );

            $scopedSet = $resolver->effectivePermissions(
                $profileId,
                self::TENANT_A,
                self::TYPE_DOCUMENT,
                self::DOC_ID
            );
            self::assertSame(
                in_array($permission, $scopedSet, true),
                $resolver->hasPermission(
                    $profileId,
                    self::TENANT_A,
                    $permission,
                    self::TYPE_DOCUMENT,
                    self::DOC_ID
                ),
                "Scoped parity must hold for {$permission}."
            );
        }
    }

    public function testScopedSetIsASupersetOfTheUnscopedSet(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        $this->grantPermissionToRole('viewer', 'posts:read');
        $profileId = $this->seedProfile('superset@a.example', 'viewer', self::TENANT_A);

        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            $profileId
        );

        $resolver = $this->resolver();
        $unscoped = $resolver->effectivePermissions($profileId, self::TENANT_A);
        $scoped = $resolver->effectivePermissions(
            $profileId,
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID
        );

        self::assertSame(
            [],
            array_diff($unscoped, $scoped),
            'A resource grant may only WIDEN authority — the scoped set must contain the unscoped one.'
        );
        self::assertContains('posts:write', $scoped, 'The granted permission must appear at the resource.');
        self::assertNotContains('posts:write', $unscoped, 'It must NOT appear tenant-wide.');
    }

    public function testHalfSpecifiedScopeIsTreatedAsNoScope(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        $profileId = $this->seedProfile('half@a.example', 'viewer', self::TENANT_A);

        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            $profileId
        );

        $resolver = $this->resolver();

        // A type with no id does not identify a record; matching on one column and
        // ignoring the other would return grants from the WRONG resource.
        self::assertFalse(
            $resolver->hasPermission($profileId, self::TENANT_A, 'posts:write', self::TYPE_DOCUMENT, null),
            'A resource type without an id must collapse to the unscoped answer.'
        );
        self::assertFalse(
            $resolver->hasPermission($profileId, self::TENANT_A, 'posts:write', null, self::DOC_ID),
            'A resource id without a type must collapse to the unscoped answer.'
        );
    }

    public function testCacheDoesNotServeAScopedAnswerForAnUnscopedQuestion(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        $profileId = $this->seedProfile('cache@a.example', 'viewer', self::TENANT_A);

        $this->repository()->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $this->roleId('editor'),
            $profileId
        );

        $checker = $this->checker();

        // Warm the SCOPED answer first, then ask the unscoped question. A cache key
        // missing the resource scope would hand back the widened set here.
        self::assertTrue($checker->hasPermissionForProfile(
            $profileId,
            'posts:write',
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID
        ));
        self::assertFalse(
            $checker->hasPermissionForProfile($profileId, 'posts:write', self::TENANT_A),
            'The unscoped question must not be answered from the scoped cache entry.'
        );
    }

    // ==================== OU grants still work through the new table ====================

    public function testOuGrantsAreUntouchedByThisChange(): void
    {
        $this->grantPermissionToRole('editor', 'posts:write');
        $ouId = $this->seedOu('engineering', self::TENANT_A);
        $profileId = $this->seedProfile('ou@a.example', 'viewer', self::TENANT_A, $ouId);

        // OU grants still live in ou_role_assignments: folding them into
        // resource_role_assignments at resource_type='ou' is the intended end
        // state but a separate change. This pins that §2 did not disturb them.
        $this->pdo->prepare(
            'INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at) VALUES (?, ?, ?, NOW())'
        )->execute([self::TENANT_A, $ouId, $this->roleId('editor')]);

        $this->assertTrue(
            $this->checker()->hasPermissionForProfile($profileId, 'posts:write', self::TENANT_A),
            'OU inheritance must keep resolving exactly as before §2.'
        );
    }

    public function testOuIsRegisteredAsABareCoreType(): void
    {
        // Core types are the reserved, UNPREFIXED namespace.
        self::assertTrue(
            (new ResourceTypeRegistry())->exists(ResourceTypeRegistry::TYPE_OU),
            "Core must register 'ou' as a resource type."
        );
    }

    // ==================== Namespacing (collision + shadowing) ====================

    public function testTwoPluginsDeclaringTheSameSlugDoNotCollide(): void
    {
        $types = new ResourceTypeRegistry();
        $types->register('Acme', ['record']);
        $types->register('Globex', ['record']);

        self::assertTrue($types->exists('acme:record'));
        self::assertTrue($types->exists('globex:record'));
        self::assertFalse(
            $types->exists('record'),
            'A bare plugin slug must never become a registered type on its own.'
        );
    }

    public function testAPluginCannotShadowACoreType(): void
    {
        $types = new ResourceTypeRegistry();
        // A plugin declaring 'ou' gets its OWN namespaced type; core's bare 'ou'
        // is untouched, so grants written against the OU cannot be intercepted.
        $types->register('Impostor', [ResourceTypeRegistry::TYPE_OU]);

        self::assertTrue($types->exists('impostor:ou'));
        self::assertSame(
            [ResourceTypeRegistry::TYPE_OU],
            $types->getBySource(ResourceTypeRegistry::CORE_SOURCE),
            "Core's 'ou' must remain exactly one bare entry owned by core."
        );
    }

    public function testAPluginCannotRegisterUnderTheReservedCoreSource(): void
    {
        $this->expectException(InvalidResourceTypeException::class);

        (new ResourceTypeRegistry())->register(ResourceTypeRegistry::CORE_SOURCE, ['sneaky']);
    }

    public function testCanonicalKeyIsTheOnePlaceTheRuleLives(): void
    {
        self::assertSame('acme:record', ResourceTypeRegistry::canonicalKey('Acme', 'record'));
        self::assertSame(
            'acme_widgets:record',
            ResourceTypeRegistry::canonicalKey('Acme\\Widgets\\Acme Widgets', 'record'),
            'A namespaced plugin class must reduce to its last segment, slugified.'
        );
        self::assertSame(
            'ou',
            ResourceTypeRegistry::canonicalKey(ResourceTypeRegistry::CORE_SOURCE, 'ou'),
            'Core types stay bare.'
        );
    }

    public function testGrantOnAnUnnamespacedPluginSlugIsRejected(): void
    {
        // The plugin declared 'document'; granting on the BARE slug must fail,
        // because the registered type is the namespaced one.
        $this->expectException(InvalidResourceTypeException::class);

        $this->repository()->grant(self::TENANT_A, 'document', self::DOC_ID, $this->roleId('editor'), null);
    }

    // ==================== Helpers ====================

    private function checker(): RoleChecker
    {
        return new RoleChecker($this->db, $this->permissionRegistry());
    }

    private function resolver(): RoleCheckerPermissionResolver
    {
        return new RoleCheckerPermissionResolver($this->checker(), $this->permissionRegistry());
    }

    private function permissionRegistry(): PermissionRegistry
    {
        $registry = new PermissionRegistry();
        $registry->register('test', ['posts:write', 'posts:read']);

        return $registry;
    }

    private function repository(): ResourceRoleAssignmentRepository
    {
        return new ResourceRoleAssignmentRepository($this->pdo, $this->resourceTypes());
    }

    /**
     * A registry with one plugin-declared type. Note the plugin declares the
     * BARE slug and the registry namespaces it, so the canonical type is
     * `testplugin:document` — which is what {@see self::TYPE_DOCUMENT} holds.
     */
    private function resourceTypes(): ResourceTypeRegistry
    {
        $types = new ResourceTypeRegistry();
        $types->register('TestPlugin', ['document']);

        return $types;
    }

    private function grantPermissionToRole(string $roleName, string $permission): void
    {
        $this->pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')
            ->execute([$permission]);
        $stmt = $this->pdo->prepare('SELECT id FROM permissions WHERE name = ?');
        $stmt->execute([$permission]);
        $permissionId = (int) $stmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())'
        )->execute([$this->roleId($roleName), $permissionId]);
    }

    private function seedOu(string $name, int $tenantId): int
    {
        $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, parent_id, name, slug, created_at)
             VALUES (?, NULL, ?, ?, NOW())'
        )->execute([$tenantId, $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedProfile(string $email, string $roleName, int $tenantId, ?int $ouId = null): int
    {
        $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, 'x', 0, 0, 0, NOW(), NOW())"
        )->execute([explode('@', $email)[0]]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, 1, 1, NOW())'
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, 'active', NOW())"
        )->execute([$profileId, $tenantId, $this->roleId($roleName), $ouId]);

        return $profileId;
    }

    private function seedTenantRole(string $name, int $tenantId): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, tenant_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$name, $tenantId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function roleId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ? ORDER BY id LIMIT 1');
        $stmt->execute([$name]);

        return (int) $stmt->fetchColumn();
    }

    private function wrapSqlite(PDO $pdo): Database
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
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, created_at) VALUES
            (3, 'editor', NOW()), (4, 'viewer', NOW())");

        return $pdo;
    }
}
