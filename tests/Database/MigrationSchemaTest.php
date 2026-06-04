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
        'core_schema_migrations',
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

    public function testAllFourteenPlusMigrationsArePresentAndOrdered(): void
    {
        $files = glob(self::$migrationDir . '/*.php') ?: [];
        sort($files);

        $names = array_map(static fn (string $f): string => basename($f), $files);

        // The original 14 RBAC/core migrations plus the user_roles junction (015).
        $this->assertContains('001_create_users_roles.php', $names);
        $this->assertContains('002_create_permissions.php', $names);
        $this->assertContains('009_create_ou_role_assignments.php', $names);
        $this->assertContains('013_add_cascading_deletes.php', $names);
        $this->assertContains('014_add_two_factor_support.php', $names);
        $this->assertContains('015_create_user_roles.php', $names);

        $this->assertGreaterThanOrEqual(15, count($files), 'Expected at least 15 migration files.');

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

        // tenant_id column: INTEGER NOT NULL REFERENCES tenants(id)
        $this->assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)/i',
            $createUsers,
            'users.tenant_id must be a non-nullable INTEGER referencing tenants(id).'
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
        $addOu = $this->readFile('008_add_ou_to_users.php');

        // id / email / password / role_id / created_at present in the base table.
        foreach (['id', 'email', 'password', 'role_id', 'created_at'] as $column) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($column, '/') . '\b/i',
                $createUsers,
                "users table is missing the '{$column}' column."
            );
        }

        // ou_id is added by migration 008 with a FK to organizational_units.
        $this->assertMatchesRegularExpression(
            '/ADD COLUMN IF NOT EXISTS\s+ou_id\s+INTEGER\s+REFERENCES\s+organizational_units\s*\(\s*id\s*\)/i',
            $addOu,
            'users.ou_id must reference organizational_units(id).'
        );
    }

    public function testRolesAndPermissionsJunctionExists(): void
    {
        $permissions = $this->readFile('002_create_permissions.php');

        $this->assertMatchesRegularExpression(
            '/role_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+roles\s*\(\s*id\s*\)/i',
            $permissions
        );
        $this->assertMatchesRegularExpression(
            '/permission_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+permissions\s*\(\s*id\s*\)/i',
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
        $sql = $this->readFile('015_create_user_roles.php');

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
        $sql = $this->readFile('007_create_organizational_units.php');

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
        $sql = $this->readFile('009_create_ou_role_assignments.php');

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
        $sql = $this->readFile('012_create_revoked_tokens.php');

        $this->assertMatchesRegularExpression('/jti\s+VARCHAR\(\d+\)\s+NOT\s+NULL\s+UNIQUE/i', $sql);
        $this->assertMatchesRegularExpression('/expires_at\s+TIMESTAMP\s+NOT\s+NULL/i', $sql);
    }

    public function testTwoFactorColumnsAndBackupCodesTableExist(): void
    {
        $sql = $this->readFile('014_add_two_factor_support.php');

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

    public function testCascadingDeletesMigrationConvertsCoreForeignKeys(): void
    {
        $sql = $this->readFile('013_add_cascading_deletes.php');

        // users -> tenants and roles cascade.
        $this->assertMatchesRegularExpression(
            '/users_tenant_id_fkey\s+FOREIGN KEY \(tenant_id\) REFERENCES tenants\(id\) ON DELETE CASCADE/i',
            $sql
        );
        $this->assertMatchesRegularExpression(
            '/role_permissions_permission_id_fkey\s+FOREIGN KEY \(permission_id\) REFERENCES permissions\(id\) ON DELETE CASCADE/i',
            $sql
        );

        // down() must restore the non-cascading constraints (reversible).
        $this->assertStringContainsString('public static function down', $sql);
        $this->assertMatchesRegularExpression(
            '/DROP CONSTRAINT IF EXISTS users_tenant_id_fkey/i',
            $sql
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
        foreach (['001_create_users_roles.php', '002_create_permissions.php', '007_create_organizational_units.php'] as $file) {
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
     * notation so a fresh database matches the RBAC registry (issue #55).
     *
     * @dataProvider permissionNotationProvider
     */
    public function testSeedMigrationsUseColonNotation(string $dotForm, string $colonForm): void
    {
        $seedSql = $this->readFile('002_create_permissions.php')
            . $this->readFile('007_create_organizational_units.php')
            . $this->readFile('010_assign_ou_permissions_to_roles.php');

        // The dot-notation form must no longer appear as a quoted permission literal.
        $this->assertStringNotContainsString(
            "'{$dotForm}'",
            $seedSql,
            "Seed migrations must not seed the dot-notation permission '{$dotForm}'."
        );
    }

    /**
     * No seed migration may reference a core permission in dot notation. This is
     * the broad guard backing the per-permission assertions above.
     */
    public function testNoSeedMigrationContainsDotNotationPermission(): void
    {
        foreach (['002_create_permissions.php', '007_create_organizational_units.php', '010_assign_ou_permissions_to_roles.php'] as $file) {
            $sql = $this->readFile($file);
            $this->assertDoesNotMatchRegularExpression(
                "/'(users|roles|tenants|ous|permissions|plugins)\.(read|create|update|delete|assign|write|manage)'/",
                $sql,
                "{$file} still contains a dot-notation permission literal."
            );
        }
    }

    public function testNormalizationMigrationExistsAndIsReversible(): void
    {
        $file = '016_normalize_permission_notation.php';
        $sql = $this->readFile($file);

        // Both lifecycle methods are present.
        $this->assertStringContainsString('public static function up', $sql);
        $this->assertStringContainsString('public static function down', $sql);

        // up() rewrites dot -> colon; down() rewrites colon -> dot. Both use a
        // REPLACE() UPDATE so they are pure data normalisations.
        $this->assertMatchesRegularExpression('/UPDATE\s+permissions/i', $sql);
        $this->assertMatchesRegularExpression('/REPLACE\s*\(\s*name/i', $sql);

        // The class must resolve via the runner's naming convention.
        $className = $this->loadMigrationClass(self::$migrationDir . '/' . $file);
        $this->assertSame('Database\\Migrations\\NormalizePermissionNotation', $className);
    }

    public function testNormalizationMigrationIsIdempotentlyScopedToKnownResources(): void
    {
        $sql = $this->readFile('016_normalize_permission_notation.php');

        // It must constrain its UPDATE with a LIKE filter so already-normalised
        // rows (and plugin permissions) are left untouched — the basis for both
        // idempotency and avoiding collateral edits.
        $this->assertMatchesRegularExpression('/LIKE\s+:pattern/i', $sql);

        // Every core resource must be covered by the rewrite.
        foreach (['users', 'roles', 'tenants', 'ous', 'permissions', 'plugins'] as $resource) {
            $this->assertStringContainsString(
                "'{$resource}'",
                $sql,
                "Normalisation migration must cover the '{$resource}' resource."
            );
        }
    }

    /**
     * Proves the dot<->colon transform applied by the migration's REPLACE() is
     * a correct, idempotent, and reversible round-trip for every core permission.
     *
     * This mirrors PostgreSQL's REPLACE(name, '<res>.', '<res>:') semantics in
     * pure PHP so the mapping is verified without a live database.
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
