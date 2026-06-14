<?php

declare(strict_types=1);

namespace Whity\Tests\Database;

use PHPUnit\Framework\TestCase;
use Whity\Database\Database;

/**
 * Structural tests for the RBAC schema migrations (WC-12).
 *
 * These tests run WITHOUT a live database. They statically analyse every
 * migration file in database/migrations to guarantee the contract required
 * by the WC-12 acceptance criteria:
 *
 *  - All expected tables are created with the correct columns.
 *  - users.tenant_id is non-nullable, references tenants(id) and is indexed.
 *  - Foreign keys and cascading deletes exist where required.
 *  - Every migration exposes both up() and down() methods (project rule).
 *  - Migrations are idempotent (IF NOT EXISTS / ON CONFLICT guards).
 *
 * They are intentionally database-agnostic so they pass in CI where no
 * PostgreSQL instance is available.
 *
 * NOTE: the migration set was consolidated (cleanup/consolidate-migrations) so
 * that each table is created in its FINAL form in one place — the patch
 * migrations that used to add columns / rewrite constraints / normalise
 * permission notation after the fact have been folded into the create
 * migrations. These tests assert that consolidated reality: cascade foreign keys
 * are verified inline on the create migrations, and the seed migrations seed
 * colon notation directly (no after-the-fact normalisation migration).
 */
final class MigrationSchemaTest extends TestCase
{
    private const EXPECTED_TABLES = [
        'tenants',
        'roles',
        'users',
        'permissions',
        'role_permissions',
        'user_roles',
        'organizational_units',
        'ou_role_assignments',
        'revoked_tokens',
        'backup_codes',
        'audit_log',
        'core_schema_migrations',
        'permission_delegations',
        'persons',
        'relationship_types',
        'relations',
    ];

    private static string $migrationDir;

    public static function setUpBeforeClass(): void
    {
        self::$migrationDir = dirname(__DIR__, 2) . '/database/migrations';
    }

    /**
     * @return list<array{0:string}>
     */
    public static function migrationFileProvider(): array
    {
        $dir = dirname(__DIR__, 2) . '/database/migrations';
        $files = glob($dir . '/*.php') ?: [];
        sort($files);

        $cases = [];
        foreach ($files as $file) {
            $cases[basename($file)] = [$file];
        }

        return $cases;
    }

    public function testConsolidatedMigrationsArePresentAndOrdered(): void
    {
        $files = glob(self::$migrationDir . '/*.php') ?: [];
        sort($files);

        $names = array_map(static fn (string $f): string => basename($f), $files);

        // The consolidated set: foundational create migrations plus the OU,
        // 2FA, revoked-token, user_roles and permission-grant migrations.
        $this->assertContains('001_create_users_roles.php', $names);
        $this->assertContains('002_create_permissions.php', $names);
        $this->assertContains('005_create_organizational_units.php', $names);
        $this->assertContains('006_add_ou_to_users.php', $names);
        $this->assertContains('007_add_two_factor_support.php', $names);
        $this->assertContains('008_create_ou_role_assignments.php', $names);
        $this->assertContains('012_create_user_roles.php', $names);
        $this->assertContains('013_grant_plugins_manage_to_admin.php', $names);
        $this->assertContains('014_create_permission_delegations.php', $names);
        $this->assertContains('015_grant_delegation_manage_to_admin.php', $names);
        // WC-65 family relations: persons graph node, relationship-type
        // vocabulary, and the relations edge table (which also seeds relations:*).
        $this->assertContains('018_create_persons.php', $names);
        $this->assertContains('019_create_relationship_types.php', $names);
        $this->assertContains('020_create_relations.php', $names);
        // WC-185: per-user token epoch for access-token revocation / session invalidation.
        $this->assertContains('021_add_user_token_epoch.php', $names);

        // The absorbed patch migrations must be gone (folded into the creates).
        $this->assertNotContains('003_add_slug_to_tenants.php', $names);
        $this->assertNotContains('004_add_description_to_roles.php', $names);
        $this->assertNotContains('013_add_cascading_deletes.php', $names);
        $this->assertNotContains('016_normalize_permission_notation.php', $names);
        $this->assertNotContains('017_add_role_hierarchy.php', $names);
        $this->assertNotContains('018_add_tenant_id_to_roles.php', $names);

        $this->assertGreaterThanOrEqual(15, count($files), 'Expected at least 15 consolidated migration files.');

        // Verify the numeric prefixes are strictly increasing so run order is deterministic.
        $prefixes = [];
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression(
                '/^\d{3}_[a-z0-9_]+\.php$/',
                $name,
                "Migration filename '{$name}' does not follow NNN_snake_case.php convention."
            );
            $prefixes[] = (int) substr($name, 0, 3);
        }

        $sorted = $prefixes;
        sort($sorted);
        $this->assertSame($sorted, $prefixes, 'Migration numeric prefixes must be in ascending order.');
        $this->assertSame(count($prefixes), count(array_unique($prefixes)), 'Duplicate migration prefixes detected.');

        // Prefixes must be a contiguous 1..N sequence (no gaps left by deletes).
        $this->assertSame(range(1, count($prefixes)), $prefixes, 'Migration prefixes must form a contiguous 1..N sequence.');
    }

    /**
     * @dataProvider migrationFileProvider
     */
    public function testMigrationClassExposesUpAndDownMethods(string $file): void
    {
        $className = $this->loadMigrationClass($file);

        $this->assertTrue(
            method_exists($className, 'up'),
            "Migration {$className} is missing an up() method."
        );
        $this->assertTrue(
            method_exists($className, 'down'),
            "Migration {$className} is missing a down() method (project rule: every migration is reversible)."
        );

        $up = new \ReflectionMethod($className, 'up');
        $down = new \ReflectionMethod($className, 'down');

        $this->assertTrue($up->isStatic(), "{$className}::up() must be static.");
        $this->assertTrue($down->isStatic(), "{$className}::down() must be static.");

        foreach ([$up, $down] as $method) {
            $params = $method->getParameters();
            $this->assertCount(1, $params, "{$className}::{$method->getName()}() must accept exactly one Database argument.");
            $type = $params[0]->getType();
            $this->assertInstanceOf(\ReflectionNamedType::class, $type);
            $this->assertSame(Database::class, $type->getName());
        }
    }

    public function testAllExpectedTablesAreCreatedAcrossMigrations(): void
    {
        $sql = $this->allMigrationSql();

        foreach (self::EXPECTED_TABLES as $table) {
            $this->assertMatchesRegularExpression(
                '/CREATE TABLE IF NOT EXISTS\s+' . preg_quote($table, '/') . '\b/i',
                $sql,
                "Expected a CREATE TABLE statement for '{$table}'."
            );
        }
    }

    public function testUsersTableHasNonNullableTenantIdWithForeignKeyAndIndex(): void
    {
        $createUsers = $this->readFile('001_create_users_roles.php');

        // tenant_id column: INTEGER NOT NULL REFERENCES tenants(id) with cascade
        // (the cascade was previously a separate patch migration; it is now inline).
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $createUsers,
            'users.tenant_id must be a non-nullable INTEGER referencing tenants(id) ON DELETE CASCADE.'
        );

        // Index on tenant_id.
        $this->assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+\w+\s+ON\s+users\s*\(\s*tenant_id\s*\)/i',
            $createUsers,
            'users.tenant_id must be indexed.'
        );
    }

    public function testUsersTableHasCoreRbacColumns(): void
    {
        $createUsers = $this->readFile('001_create_users_roles.php');
        $addOu = $this->readFile('006_add_ou_to_users.php');

        // id / email / password / role_id / created_at present in the base table.
        foreach (['id', 'email', 'password', 'role_id', 'created_at'] as $column) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($column, '/') . '\b/i',
                $createUsers,
                "users table is missing the '{$column}' column."
            );
        }

        // role_id carries a cascade FK to roles (folded from the cascade patch).
        $this->assertMatchesRegularExpression(
            '/role_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+roles\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $createUsers,
            'users.role_id must reference roles(id) ON DELETE CASCADE.'
        );

        // ou_id is added by the OU migration with a FK to organizational_units.
        $this->assertMatchesRegularExpression(
            '/ADD COLUMN IF NOT EXISTS\s+ou_id\s+INTEGER\s+REFERENCES\s+organizational_units\s*\(\s*id\s*\)/i',
            $addOu,
            'users.ou_id must reference organizational_units(id).'
        );
    }

    public function testRolesTableHasFinalHierarchyAndTenantColumns(): void
    {
        $createRoles = $this->readFile('001_create_users_roles.php');

        // description column (folded from the description patch).
        $this->assertMatchesRegularExpression(
            '/description\s+TEXT\s+DEFAULT/i',
            $createRoles,
            'roles.description must be present in the base create (folded from the description patch).'
        );

        // parent_id self-reference with ON DELETE SET NULL (role hierarchy patch).
        $this->assertMatchesRegularExpression(
            '/parent_id\s+INTEGER\s+NULL\s+REFERENCES\s+roles\s*\(\s*id\s*\)\s+ON DELETE SET NULL/i',
            $createRoles,
            'roles.parent_id must self-reference roles(id) ON DELETE SET NULL.'
        );

        // No-self-parent guard.
        $this->assertMatchesRegularExpression(
            '/CONSTRAINT\s+chk_roles_no_self_parent\s+CHECK\s*\(\s*parent_id\s+IS\s+NULL\s+OR\s+parent_id\s*<>\s*id\s*\)/i',
            $createRoles,
            'roles must keep the chk_roles_no_self_parent guard.'
        );

        // tenant_id with ON DELETE CASCADE (owning-tenant patch).
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $createRoles,
            'roles.tenant_id must reference tenants(id) ON DELETE CASCADE.'
        );

        // Both supporting indexes.
        $this->assertMatchesRegularExpression('/CREATE INDEX IF NOT EXISTS\s+\w+\s+ON\s+roles\s*\(\s*parent_id\s*\)/i', $createRoles);
        $this->assertMatchesRegularExpression('/CREATE INDEX IF NOT EXISTS\s+\w+\s+ON\s+roles\s*\(\s*tenant_id\s*\)/i', $createRoles);
    }

    public function testTenantsTableHasSlugColumn(): void
    {
        $createTenants = $this->readFile('001_create_users_roles.php');

        // slug is folded into the base tenants create (was a later ADD COLUMN patch).
        $this->assertMatchesRegularExpression(
            '/slug\s+VARCHAR\(\d+\)\s+UNIQUE/i',
            $createTenants,
            'tenants.slug must be present (UNIQUE) in the base create.'
        );
    }

    public function testRolesAndPermissionsJunctionExists(): void
    {
        $permissions = $this->readFile('002_create_permissions.php');

        // role_permissions FKs are now inline with ON DELETE CASCADE (folded).
        $this->assertMatchesRegularExpression(
            '/role_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+roles\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $permissions
        );
        $this->assertMatchesRegularExpression(
            '/permission_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+permissions\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $permissions
        );
        // Junction uniqueness prevents duplicate grants.
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s*\(\s*role_id\s*,\s*permission_id\s*\)/i',
            $permissions
        );
    }

    public function testUserRolesJunctionTableLinksUsersAndRolesPerTenant(): void
    {
        $sql = $this->readFile('012_create_user_roles.php');

        $this->assertMatchesRegularExpression('/CREATE TABLE IF NOT EXISTS\s+user_roles\b/i', $sql);

        // Tenant scoping with cascade.
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'user_roles.tenant_id must cascade-delete with its tenant.'
        );

        // FKs to users and roles with cascade.
        $this->assertMatchesRegularExpression(
            '/user_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+users\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'user_roles.user_id must reference users(id) ON DELETE CASCADE.'
        );
        $this->assertMatchesRegularExpression(
            '/role_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+roles\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'user_roles.role_id must reference roles(id) ON DELETE CASCADE.'
        );

        // Prevent duplicate user/role pairs.
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s*\(\s*user_id\s*,\s*role_id\s*\)/i',
            $sql,
            'user_roles must enforce a unique (user_id, role_id) pair.'
        );

        // Indexes for join performance.
        $this->assertMatchesRegularExpression('/CREATE INDEX IF NOT EXISTS\s+\w+\s+ON\s+user_roles\s*\(\s*user_id\s*\)/i', $sql);
        $this->assertMatchesRegularExpression('/CREATE INDEX IF NOT EXISTS\s+\w+\s+ON\s+user_roles\s*\(\s*role_id\s*\)/i', $sql);
    }

    public function testOrganizationalUnitsSupportParentHierarchy(): void
    {
        $sql = $this->readFile('005_create_organizational_units.php');

        $this->assertMatchesRegularExpression(
            '/parent_id\s+INTEGER\s+REFERENCES\s+organizational_units\s*\(\s*id\s*\)/i',
            $sql,
            'organizational_units must self-reference via parent_id for hierarchy support.'
        );
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql
        );
    }

    public function testOuRoleAssignmentsCascadeFromAllParents(): void
    {
        $sql = $this->readFile('008_create_ou_role_assignments.php');

        foreach (
            [
                'tenant_id' => 'tenants',
                'ou_id' => 'organizational_units',
                'role_id' => 'roles',
            ] as $column => $table
        ) {
            $this->assertMatchesRegularExpression(
                '/' . $column . '\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+' . $table . '\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
                $sql,
                "ou_role_assignments.{$column} must reference {$table}(id) ON DELETE CASCADE."
            );
        }
    }

    public function testRevokedTokensTableExistsForJwtRevocation(): void
    {
        $sql = $this->readFile('011_create_revoked_tokens.php');

        $this->assertMatchesRegularExpression('/jti\s+VARCHAR\(\d+\)\s+NOT\s+NULL\s+UNIQUE/i', $sql);
        $this->assertMatchesRegularExpression('/expires_at\s+TIMESTAMP\s+NOT\s+NULL/i', $sql);
    }

    public function testTwoFactorColumnsAndBackupCodesTableExist(): void
    {
        $sql = $this->readFile('007_add_two_factor_support.php');

        foreach (['two_factor_secret', 'two_factor_enabled', 'two_factor_backup_codes_version'] as $column) {
            $this->assertMatchesRegularExpression(
                '/ADD COLUMN IF NOT EXISTS\s+' . $column . '\b/i',
                $sql,
                "users.{$column} must be added by the two-factor migration."
            );
        }

        $this->assertMatchesRegularExpression(
            '/user_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+users\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'backup_codes.user_id must cascade-delete with its user.'
        );
    }

    public function testTokenEpochColumnIsAddedToUsersForRevocation(): void
    {
        $sql = $this->readFile('021_add_user_token_epoch.php');

        // Additive, idempotent, backward-compatible column: NOT NULL DEFAULT 0
        // so existing rows backfill to the validator's missing-claim=0 default.
        $this->assertMatchesRegularExpression(
            '/ADD COLUMN IF NOT EXISTS\s+token_epoch\s+INTEGER\s+NOT\s+NULL\s+DEFAULT\s+0/i',
            $sql,
            'users.token_epoch must be added as NOT NULL DEFAULT 0 for backward compatibility.'
        );

        // Reversible: down() drops exactly this column.
        $this->assertMatchesRegularExpression(
            '/DROP COLUMN IF EXISTS\s+token_epoch/i',
            $sql,
            'The token_epoch migration down() must drop the column.'
        );
    }

    public function testAuditLogTableHasExpectedColumnsAndIndexes(): void
    {
        $sql = $this->readFile('016_create_audit_log.php');

        // Tenant scope cascades with the tenant, mirroring the other scoped tables.
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\(id\)\s+ON DELETE CASCADE/i',
            $sql,
            'audit_log.tenant_id must be NOT NULL and cascade-delete with its tenant.'
        );

        // actor_user_id is nullable (failed logins / system actions have no actor).
        $this->assertMatchesRegularExpression(
            '/actor_user_id\s+INTEGER\s+NULL/i',
            $sql,
            'audit_log.actor_user_id must be nullable.'
        );

        foreach (['action', 'target_type', 'target_id', 'metadata', 'ip_address', 'created_at'] as $column) {
            $this->assertMatchesRegularExpression(
                '/\b' . $column . '\b/i',
                $sql,
                "audit_log must define the {$column} column."
            );
        }

        // The tenant-scoped, time-ordered index that backs the listing query.
        $this->assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+idx_audit_log_tenant_created\s+ON audit_log\s*\(\s*tenant_id,\s*created_at DESC/i',
            $sql,
            'audit_log must have a (tenant_id, created_at DESC, ...) index for newest-first tenant queries.'
        );

        // down() must drop the table.
        $this->assertMatchesRegularExpression(
            '/DROP TABLE IF EXISTS audit_log/i',
            $sql,
            'audit_log migration down() must drop the table.'
        );
    }

    public function testAuditLogMigrationSeedsAndGrantsAuditReadPermission(): void
    {
        $sql = $this->readFile('016_create_audit_log.php');

        // Seeds the audit:read permission row (idempotently).
        $this->assertStringContainsString('audit:read', $sql);
        $this->assertMatchesRegularExpression(
            '/INSERT INTO permissions[\s\S]+ON CONFLICT \(name\) DO NOTHING/i',
            $sql,
            'audit:read must be seeded idempotently.'
        );

        // Grants it to the admin role idempotently.
        $this->assertMatchesRegularExpression(
            '/INSERT INTO role_permissions[\s\S]+ON CONFLICT \(role_id, permission_id\) DO NOTHING/i',
            $sql,
            'audit:read must be granted to admin idempotently.'
        );
    }

    public function testPersonsTableSchemaAndReversibility(): void
    {
        $sql = $this->readFile('018_create_persons.php');

        // Tenant scope cascades with the tenant, like every scoped table.
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'persons.tenant_id must be NOT NULL and cascade-delete with its tenant.'
        );

        // display_name is the required human label.
        $this->assertMatchesRegularExpression(
            '/display_name\s+VARCHAR\(\d+\)\s+NOT\s+NULL/i',
            $sql,
            'persons.display_name must be a NOT NULL string.'
        );

        // user_id is the nullable, UNIQUE link to a platform user with SET NULL on
        // user deletion (the person survives as a now-account-less relative).
        $this->assertMatchesRegularExpression(
            '/user_id\s+INTEGER\s+NULL\s+UNIQUE\s+REFERENCES\s+users\s*\(\s*id\s*\)\s+ON DELETE SET NULL/i',
            $sql,
            'persons.user_id must be a NULLABLE, UNIQUE FK to users(id) ON DELETE SET NULL.'
        );

        // Optional genealogy fields.
        foreach (['birth_date', 'deceased', 'notes', 'created_at'] as $column) {
            $this->assertMatchesRegularExpression(
                '/\b' . $column . '\b/i',
                $sql,
                "persons must define the {$column} column."
            );
        }

        // Tenant listing index.
        $this->assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+\w+\s+ON\s+persons\s*\(\s*tenant_id\s*\)/i',
            $sql,
            'persons.tenant_id must be indexed for tenant listing.'
        );

        // Reversible.
        $this->assertMatchesRegularExpression(
            '/DROP TABLE IF EXISTS\s+persons/i',
            $sql,
            'persons migration down() must drop the table.'
        );
    }

    public function testRelationshipTypesTableSchemaSeedsAndReversibility(): void
    {
        $sql = $this->readFile('019_create_relationship_types.php');

        // Unique vocabulary name + the reciprocal-derivation columns.
        $this->assertMatchesRegularExpression(
            '/name\s+VARCHAR\(\d+\)\s+NOT\s+NULL\s+UNIQUE/i',
            $sql,
            'relationship_types.name must be a NOT NULL UNIQUE string.'
        );
        $this->assertMatchesRegularExpression(
            '/inverse_type_id\s+INTEGER\s+NULL\s+REFERENCES\s+relationship_types\s*\(\s*id\s*\)/i',
            $sql,
            'relationship_types.inverse_type_id must self-reference relationship_types(id).'
        );
        $this->assertMatchesRegularExpression(
            '/is_symmetric\s+BOOLEAN\s+NOT\s+NULL\s+DEFAULT/i',
            $sql,
            'relationship_types.is_symmetric must be a NOT NULL boolean with a default ' .
            '("symmetric" is a reserved word in PostgreSQL, so the column is is_symmetric).'
        );

        // The fixed v1 vocabulary is seeded idempotently.
        foreach (['Parent', 'Child', 'Spouse', 'Sibling'] as $type) {
            $this->assertStringContainsString($type, $sql, "The {$type} relationship type must be seeded.");
        }
        $this->assertMatchesRegularExpression(
            '/INSERT INTO relationship_types[\s\S]+ON CONFLICT \(name\) DO NOTHING/i',
            $sql,
            'relationship types must be seeded idempotently.'
        );

        // Reversible.
        $this->assertMatchesRegularExpression(
            '/DROP TABLE IF EXISTS\s+relationship_types/i',
            $sql,
            'relationship_types migration down() must drop the table.'
        );
    }

    public function testRelationsTableSchemaSeedsPermissionsAndReversibility(): void
    {
        $sql = $this->readFile('020_create_relations.php');

        // Tenant scope cascades with the tenant.
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'relations.tenant_id must be NOT NULL and cascade-delete with its tenant.'
        );

        // Both endpoints FK persons with cascade so deleting a person clears edges.
        foreach (['from_person_id', 'to_person_id'] as $column) {
            $this->assertMatchesRegularExpression(
                '/' . $column . '\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+persons\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
                $sql,
                "relations.{$column} must reference persons(id) ON DELETE CASCADE."
            );
        }

        // The edge carries its relationship type.
        $this->assertMatchesRegularExpression(
            '/relationship_type_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+relationship_types\s*\(\s*id\s*\)/i',
            $sql,
            'relations.relationship_type_id must reference relationship_types(id).'
        );

        // No-duplicate integrity at the DB level.
        $this->assertMatchesRegularExpression(
            '/UNIQUE\s*\(\s*tenant_id\s*,\s*from_person_id\s*,\s*to_person_id\s*,\s*relationship_type_id\s*\)/i',
            $sql,
            'relations must enforce a unique (tenant_id, from_person_id, to_person_id, relationship_type_id).'
        );

        // Seeds + grants relations:read and relations:manage idempotently.
        $this->assertStringContainsString('relations:read', $sql);
        $this->assertStringContainsString('relations:manage', $sql);
        $this->assertMatchesRegularExpression(
            '/INSERT INTO permissions[\s\S]+ON CONFLICT \(name\) DO NOTHING/i',
            $sql,
            'relations:* permissions must be seeded idempotently.'
        );
        $this->assertMatchesRegularExpression(
            '/INSERT INTO role_permissions[\s\S]+ON CONFLICT \(role_id, permission_id\) DO NOTHING/i',
            $sql,
            'relations:* permissions must be granted to admin idempotently.'
        );

        // down() reverses the grant and drops the table.
        $this->assertMatchesRegularExpression(
            '/DELETE FROM role_permissions/i',
            $sql,
            'down() must remove the relations:* grants.'
        );
        $this->assertMatchesRegularExpression(
            '/DROP TABLE IF EXISTS\s+relations/i',
            $sql,
            'relations migration down() must drop the table.'
        );
    }

    public function testCoreCascadingDeletesAreFoldedIntoCreateMigrations(): void
    {
        // The dedicated cascade-rewrite migration was folded into the create
        // migrations. Verify the cascade foreign keys live inline on the tables
        // they belong to (and are therefore reversed by those creates' down()).
        $usersRoles = $this->readFile('001_create_users_roles.php');
        $permissions = $this->readFile('002_create_permissions.php');
        $deployments = $this->readFile('004_create_deployment_tables.php');

        // users -> tenants and roles cascade (inline).
        $this->assertMatchesRegularExpression(
            '/REFERENCES\s+tenants\(id\)\s+ON DELETE CASCADE/i',
            $usersRoles
        );
        $this->assertMatchesRegularExpression(
            '/REFERENCES\s+roles\(id\)\s+ON DELETE CASCADE/i',
            $usersRoles
        );

        // role_permissions.permission_id cascade (inline).
        $this->assertMatchesRegularExpression(
            '/permission_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+permissions\(id\)\s+ON DELETE CASCADE/i',
            $permissions
        );

        // deployments + migration_rollbacks tenant cascade (inline).
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\(id\)\s+ON DELETE CASCADE/i',
            $deployments
        );

        // The dedicated cascade-rewrite migration must no longer exist.
        $this->assertFileDoesNotExist(
            self::$migrationDir . '/013_add_cascading_deletes.php',
            'The separate cascading-deletes migration must be folded into the create migrations.'
        );
    }

    public function testEveryCreateTableIsIdempotent(): void
    {
        $sql = $this->allMigrationSql();

        // Any CREATE TABLE statement must guard with IF NOT EXISTS so re-runs are safe.
        preg_match_all('/CREATE TABLE\s+(IF NOT EXISTS\s+)?(\w+)/i', $sql, $matches, PREG_SET_ORDER);
        $this->assertNotEmpty($matches);

        foreach ($matches as $match) {
            $this->assertNotEmpty(
                trim($match[1] ?? ''),
                "CREATE TABLE for '{$match[2]}' must use IF NOT EXISTS for idempotency."
            );
        }
    }

    public function testSeedStyleInsertsUseOnConflictForIdempotency(): void
    {
        // Migrations that insert default rows must guard against duplicates on re-run.
        foreach (['001_create_users_roles.php', '002_create_permissions.php', '005_create_organizational_units.php'] as $file) {
            $sql = $this->readFile($file);
            if (stripos($sql, 'INSERT INTO') !== false) {
                $this->assertMatchesRegularExpression(
                    '/ON CONFLICT/i',
                    $sql,
                    "Seed inserts in {$file} must use ON CONFLICT for idempotency."
                );
            }
        }
        $this->addToAssertionCount(1);
    }

    /**
     * Canonical dot -> colon mapping for every core permission the platform seeds.
     *
     * @return array<string, array{0:string,1:string}>
     */
    public static function permissionNotationProvider(): array
    {
        return [
            'users:read' => ['users.read', 'users:read'],
            'users:create' => ['users.create', 'users:create'],
            'users:update' => ['users.update', 'users:update'],
            'users:delete' => ['users.delete', 'users:delete'],
            'roles:read' => ['roles.read', 'roles:read'],
            'roles:create' => ['roles.create', 'roles:create'],
            'roles:update' => ['roles.update', 'roles:update'],
            'roles:delete' => ['roles.delete', 'roles:delete'],
            'tenants:read' => ['tenants.read', 'tenants:read'],
            'tenants:create' => ['tenants.create', 'tenants:create'],
            'tenants:update' => ['tenants.update', 'tenants:update'],
            'tenants:delete' => ['tenants.delete', 'tenants:delete'],
            'ous:read' => ['ous.read', 'ous:read'],
            'ous:create' => ['ous.create', 'ous:create'],
            'ous:update' => ['ous.update', 'ous:update'],
            'ous:delete' => ['ous.delete', 'ous:delete'],
            'ous:assign' => ['ous.assign', 'ous:assign'],
        ];
    }

    /**
     * The seed migrations must define permissions in `resource:action` (colon)
     * notation so a fresh database matches the RBAC registry (issue #55). The
     * after-the-fact dot->colon normalisation migration was removed because the
     * seeds now emit colon notation at source.
     *
     * @dataProvider permissionNotationProvider
     */
    public function testSeedMigrationsUseColonNotation(string $dotForm, string $colonForm): void
    {
        $seedSql = $this->readFile('002_create_permissions.php')
            . $this->readFile('005_create_organizational_units.php')
            . $this->readFile('009_assign_ou_permissions_to_roles.php');

        // The dot-notation form must no longer appear as a quoted permission literal.
        $this->assertStringNotContainsString(
            "'{$dotForm}'",
            $seedSql,
            "Seed migrations must not seed the dot-notation permission '{$dotForm}'."
        );

        // And the colon form must be present somewhere in the seed surface.
        $this->assertStringContainsString(
            $colonForm,
            $seedSql,
            "Seed migrations must seed the colon-notation permission '{$colonForm}'."
        );
    }

    /**
     * No seed migration may reference a core permission in dot notation. This is
     * the broad guard backing the per-permission assertions above.
     */
    public function testNoSeedMigrationContainsDotNotationPermission(): void
    {
        foreach (['002_create_permissions.php', '005_create_organizational_units.php', '009_assign_ou_permissions_to_roles.php'] as $file) {
            $sql = $this->readFile($file);
            $this->assertDoesNotMatchRegularExpression(
                "/'(users|roles|tenants|ous|permissions|plugins)\.(read|create|update|delete|assign|write|manage)'/",
                $sql,
                "{$file} still contains a dot-notation permission literal."
            );
        }
    }

    /**
     * The after-the-fact dot->colon normalisation migration must be gone: the
     * seeds emit colon notation directly, so there is nothing to normalise.
     */
    public function testNormalizationMigrationIsRemoved(): void
    {
        $this->assertFileDoesNotExist(
            self::$migrationDir . '/016_normalize_permission_notation.php',
            'The permission-notation normalisation migration must be removed; seeds now use colon notation at source.'
        );

        $sql = $this->allMigrationSql();
        $this->assertDoesNotMatchRegularExpression(
            '/class\s+NormalizePermissionNotation/i',
            $sql,
            'No migration should define NormalizePermissionNotation any more.'
        );
    }

    /**
     * Proves the dot<->colon transform is a correct, idempotent and reversible
     * round-trip for every core permission. Although the runtime normalisation
     * migration was removed, this guards the canonical mapping the seeds rely on.
     *
     * @dataProvider permissionNotationProvider
     */
    public function testDotColonTransformRoundTrips(string $dotForm, string $colonForm): void
    {
        $resources = ['users', 'roles', 'tenants', 'ous', 'permissions', 'plugins'];

        $toColon = static function (string $value) use ($resources): string {
            foreach ($resources as $resource) {
                if (str_starts_with($value, $resource . '.')) {
                    return str_replace($resource . '.', $resource . ':', $value);
                }
            }
            return $value;
        };
        $toDot = static function (string $value) use ($resources): string {
            foreach ($resources as $resource) {
                if (str_starts_with($value, $resource . ':')) {
                    return str_replace($resource . ':', $resource . '.', $value);
                }
            }
            return $value;
        };

        // Forward: dot -> colon.
        $this->assertSame($colonForm, $toColon($dotForm));
        // Idempotent: applying forward to an already-colon value is a no-op.
        $this->assertSame($colonForm, $toColon($colonForm));
        // Reverse (down): colon -> dot restores the original.
        $this->assertSame($dotForm, $toDot($colonForm));
        // Idempotent reverse.
        $this->assertSame($dotForm, $toDot($dotForm));
    }

    public function testPermissionDelegationsTableSchemaAndReversibility(): void
    {
        $sql = $this->readFile('014_create_permission_delegations.php');

        // Polymorphic grantee modelled as (grantee_type discriminator + grantee_id),
        // with a CHECK pinning the discriminator to the two legal values (WC-34).
        $this->assertMatchesRegularExpression(
            '/grantee_type\s+VARCHAR\(\d+\)\s+NOT\s+NULL/i',
            $sql,
            'permission_delegations.grantee_type must be a NOT NULL discriminator column.'
        );
        $this->assertMatchesRegularExpression(
            '/grantee_id\s+INTEGER\s+NOT\s+NULL/i',
            $sql,
            'permission_delegations.grantee_id must be a NOT NULL integer.'
        );
        // The SQL lives inside a single-quoted PHP string literal, so the inner
        // 'role'/'user' quotes appear backslash-escaped in the source.
        $this->assertMatchesRegularExpression(
            "/CHECK\s*\(\s*grantee_type\s+IN\s*\(\s*\\\\?'role\\\\?'\s*,\s*\\\\?'user\\\\?'\s*\)\s*\)/i",
            $sql,
            'A CHECK constraint must pin grantee_type to role|user.'
        );

        // Tenant scope + grantor + permission string + optional OU scope + lifecycle.
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'permission_delegations.tenant_id must reference tenants(id) ON DELETE CASCADE.'
        );
        $this->assertMatchesRegularExpression(
            '/grantor_user_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+users\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'permission_delegations.grantor_user_id must reference users(id) ON DELETE CASCADE.'
        );
        $this->assertMatchesRegularExpression('/permission\s+VARCHAR\(\d+\)\s+NOT\s+NULL/i', $sql);
        $this->assertMatchesRegularExpression(
            '/ou_id\s+INTEGER\s+NULL\s+REFERENCES\s+organizational_units\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'permission_delegations.ou_id must be a NULLABLE OU-scope FK with cascade.'
        );
        $this->assertMatchesRegularExpression('/revoked_at\s+TIMESTAMP\s+NULL/i', $sql);

        // Resolution index covering (tenant_id, grantee_type, grantee_id, revoked_at).
        $this->assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+idx_pd_resolution/i',
            $sql,
            'A resolution index must exist for the hot delegation lookup.'
        );

        // Reversible: down() drops the table.
        $this->assertMatchesRegularExpression(
            '/DROP TABLE IF EXISTS\s+permission_delegations/i',
            $sql,
            'permission_delegations migration must have a reversible down() that drops the table.'
        );
    }

    public function testDelegationManageGrantMigrationIsIdempotentSeedStyle(): void
    {
        $sql = $this->readFile('015_grant_delegation_manage_to_admin.php');

        // Seed-style inserts must guard with ON CONFLICT for idempotency.
        $this->assertMatchesRegularExpression(
            '/INSERT INTO permissions[\s\S]*ON CONFLICT/i',
            $sql,
            'The delegation:manage catalogue insert must use ON CONFLICT.'
        );
        $this->assertMatchesRegularExpression(
            '/INSERT INTO role_permissions[\s\S]*ON CONFLICT/i',
            $sql,
            'The delegation:manage grant insert must use ON CONFLICT.'
        );

        // down() must reverse the grant.
        $this->assertMatchesRegularExpression(
            '/DELETE FROM role_permissions/i',
            $sql,
            'down() must remove the delegation:manage grant.'
        );
    }

    public function testAllMigrationFilesDeclareTheMigrationsNamespace(): void
    {
        foreach (array_keys(self::migrationFileProvider()) as $fileName) {
            $sql = $this->readFile($fileName);
            $this->assertStringContainsString(
                'namespace Database\\Migrations;',
                $sql,
                "{$fileName} must declare the Database\\Migrations namespace so the runner can resolve its class."
            );
        }
    }

    private function loadMigrationClass(string $file): string
    {
        require_once $file;

        $name = pathinfo($file, PATHINFO_FILENAME);
        $parts = explode('_', $name);
        array_shift($parts); // strip numeric prefix
        $className = 'Database\\Migrations\\' . implode('', array_map('ucfirst', $parts));

        $this->assertTrue(
            class_exists($className),
            "Migration class {$className} could not be resolved from {$file}."
        );

        return $className;
    }

    private function readFile(string $fileName): string
    {
        $path = self::$migrationDir . '/' . $fileName;
        $contents = file_get_contents($path);
        $this->assertIsString($contents, "Unable to read migration file {$fileName}.");

        return $contents;
    }

    private function allMigrationSql(): string
    {
        $files = glob(self::$migrationDir . '/*.php') ?: [];
        $sql = '';
        foreach ($files as $file) {
            $sql .= (string) file_get_contents($file) . "\n";
        }

        return $sql;
    }
}
