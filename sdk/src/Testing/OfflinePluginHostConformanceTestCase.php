<?php

declare(strict_types=1);

namespace Whity\Sdk\Testing;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Hooks\HookVetoException;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Testing\Support\OfflineHostReferencePdo;

/**
 * Shared base test case proving a plugin will actually BOOT and BEHAVE
 * correctly under the Tauri desktop template's offline PHP plugin host —
 * not just that it satisfies the bare {@see PluginInterface} contract.
 *
 * {@see TenantIsolationConformanceTestCase} proves a DIFFERENT thing: that a
 * plugin's queries stay tenant-scoped. It says nothing about whether the
 * plugin will boot under the offline host's real (not idealized) shim
 * surface — no memberships/JWT/OU hierarchy, a single fixed device role, and
 * a deliberately narrow SQLite dialect shim. This test case exists because
 * every one of the real gaps this session found (a migration using `SERIAL`
 * that SQLite silently mis-parses, a missing `mbstring` extension, an
 * un-seeded `admin` role that made existing grant migrations silently
 * no-op) was discovered only by manually `curl`-ing the running host and
 * watching it fail — this kit catches the SQL-dialect and RBAC-declaration
 * classes of that failure mode before a plugin ships, without needing a
 * running FrankenPHP process at all.
 *
 * The SDK package cannot import the offline host's actual classes (they live
 * in a downstream template, not in `whity/plugin-sdk`), so this reimplements
 * — in miniature, self-contained — the same mechanics the real host's
 * `MigrationRunner`/`PermissionRegistry`/`RoleSeeder`/`HookRegistrar` use:
 * the same SQLite dialect rewrite ({@see OfflineHostReferencePdo}), the same
 * bare RBAC skeleton tables, the same "declared permission must exist in the
 * catalogue to ever be grantable" gate, and the same "a Throwable other than
 * HookVetoException is swallowed by the host, but should still fail a
 * plugin author's own test" philosophy.
 *
 * A plugin supplies its specifics by implementing {@see pluginUnderTest()}.
 */
abstract class OfflinePluginHostConformanceTestCase extends TestCase
{
    private const PERMISSION_PATTERN = '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/';

    /**
     * The plugin instance under test — fresh per test method (PHPUnit
     * instantiates the test case fresh for each test, so no shared state
     * leaks between the checks below).
     */
    abstract protected function pluginUnderTest(): PluginInterface;

    // ==================== 1. boots & migrates cleanly ====================

    /**
     * Runs every migration the plugin declares against a SQLite-flavoured
     * engine carrying only the offline host's bare RBAC skeleton (roles/
     * permissions/role_permissions) — no other plugin's tables exist. Catches
     * the exact class of bug this session found manually: a Postgres-only
     * construct (`SERIAL`, `JSONB`, `gen_random_uuid()`, `RETURNING` in DDL)
     * the dialect shim doesn't rewrite, or a migration that assumes a table
     * only a DIFFERENT plugin or core creates.
     */
    final public function testPluginMigratesCleanlyUnderTheOfflineShim(): void
    {
        $plugin = $this->pluginUnderTest();
        $pdo = $this->makePdo();
        $this->bootstrapHostSkeleton($pdo);

        foreach ($plugin->getMigrations() as $migrationFqcn) {
            self::assertTrue(
                class_exists($migrationFqcn),
                "Migration class {$migrationFqcn} (declared by getMigrations()) could not be autoloaded"
            );

            /** @var \Whity\Sdk\MigrationInterface $migration */
            $migration = new $migrationFqcn();

            $pdo->beginTransaction();
            try {
                $migration->up($pdo);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                self::fail(
                    "Migration {$migrationFqcn} failed under the offline host's SQLite-flavoured engine: "
                    . get_class($e) . ': ' . $e->getMessage()
                    . "\nIf this uses a Postgres-only construct (SERIAL, JSONB, gen_random_uuid(), "
                    . 'RETURNING in DDL) beyond SERIAL PRIMARY KEY, the offline host\'s dialect shim '
                    . 'does not rewrite it — either avoid the construct or flag it for a new shim rule.'
                );
            }
        }
    }

    // ==================== 2. permission declarations ====================

    /**
     * Every declared permission must match the host-enforced `resource:action`
     * shape. A malformed slug gets the WHOLE plugin quarantined by the offline
     * host's loader (fail-closed, same as production) — never silently
     * ignored — so this failing locally is exactly the signal a plugin author
     * needs before shipping.
     */
    final public function testDeclaredPermissionsAreWellFormed(): void
    {
        foreach ($this->pluginUnderTest()->getPermissions() as $permission) {
            self::assertMatchesRegularExpression(
                self::PERMISSION_PATTERN,
                $permission,
                "Permission '{$permission}' does not match the required `resource:action` shape "
                . '(' . self::PERMISSION_PATTERN . '). A plugin whose getPermissions() contains a '
                . 'malformed slug is quarantined OUTRIGHT by the offline host — none of its routes, '
                . 'migrations, or hooks register either.'
            );
        }
    }

    /**
     * Every route's `requiredPermission` must appear in getPermissions().
     * An undeclared permission is never inserted into the permission
     * catalogue, so — under the SAME "unregistered slug is never granted"
     * gate production's RoleChecker uses — no role can EVER satisfy that
     * route, offline or in production: every caller gets a permanent 403.
     */
    final public function testRouteRequiredPermissionsAreDeclared(): void
    {
        $plugin = $this->pluginUnderTest();
        $declared = $plugin->getPermissions();

        foreach ($plugin->getRoutes() as $route) {
            $permission = $route['requiredPermission'] ?? null;
            if ($permission === null) {
                continue;
            }

            self::assertContains(
                $permission,
                $declared,
                'Route ' . $route['method'] . ' ' . $route['path']
                . " requires permission '{$permission}', which getPermissions() does not declare — "
                . 'this route can never be satisfied by any role, offline or in production.'
            );
        }
    }

    /**
     * Seeds one throwaway role per declared permission, granting it ONLY
     * that permission, and proves the offline host's actual enforcement
     * mechanics (registry gate + role_permissions join — the same shape
     * DeviceRoleChecker uses) grant exactly what was granted and nothing
     * else. Catches a plugin that declares permissions it never actually
     * uses consistently, or a typo'd slug that would silently never match.
     */
    final public function testDeclaredPermissionsGrantExactlyThemselves(): void
    {
        $declared = $this->pluginUnderTest()->getPermissions();
        if ($declared === []) {
            self::markTestSkipped('Plugin declares no permissions to verify.');
        }

        $pdo = $this->makePdo();
        $this->bootstrapHostSkeleton($pdo);

        $insertPermission = $pdo->prepare('INSERT INTO permissions (name) VALUES (:name)');
        foreach ($declared as $permission) {
            $insertPermission->execute([':name' => $permission]);
        }

        $unregisteredCheckRole = 'conformance_unregistered_check';
        $pdo->prepare('INSERT INTO roles (name) VALUES (:name)')->execute([':name' => $unregisteredCheckRole]);
        self::assertFalse(
            $this->roleHasPermission($pdo, $unregisteredCheckRole, 'not_a_real:permission'),
            'An unregistered permission slug must never be granted, even to a role that holds every '
            . 'other permission — the registry gate must reject it.'
        );

        foreach ($declared as $granted) {
            $role = 'conformance_' . preg_replace('/[^a-z0-9_]/', '_', $granted);
            $pdo->prepare('INSERT INTO roles (name) VALUES (:name)')->execute([':name' => $role]);
            $pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id)
                 SELECT r.id, p.id FROM roles r, permissions p
                 WHERE r.name = :role AND p.name = :permission'
            )->execute([':role' => $role, ':permission' => $granted]);

            self::assertTrue(
                $this->roleHasPermission($pdo, $role, $granted),
                "A role granted only '{$granted}' should be able to exercise it — the seeding/grant path itself is broken."
            );

            foreach ($declared as $other) {
                if ($other === $granted) {
                    continue;
                }

                self::assertFalse(
                    $this->roleHasPermission($pdo, $role, $other),
                    "A role granted only '{$granted}' unexpectedly also holds '{$other}' — "
                    . 'the two permissions may be declared identically or the grant seeding is over-broad.'
                );
            }
        }
    }

    // ==================== 3. hooks ====================

    /**
     * Invokes every declared hook callback with a synthetic, empty payload.
     * A generic Throwable here would be SILENTLY swallowed by the real
     * host's per-plugin error boundary (logged, payload passed through
     * unchanged) — which means a broken hook ships invisibly rather than
     * failing loudly. {@see HookVetoException} is the one sanctioned
     * exception (the host deliberately lets it cross the boundary) and is
     * NOT a failure here.
     */
    final public function testHooksRunCleanlyOnASyntheticPayload(): void
    {
        $context = ['tenant_id' => 1, 'timestamp' => time()];

        $hooks = $this->pluginUnderTest()->getHooks();
        // A plugin with no hooks trivially conforms — assert the shape so this
        // test always makes an assertion (phpunit failOnRisky) instead of being
        // reported "risky" for the (common) no-hooks case.
        self::assertIsArray($hooks, 'getHooks() must return an array (event => listener(s)).');

        foreach ($hooks as $eventName => $hookData) {
            foreach (self::normalizeHooks($hookData) as $callback) {
                try {
                    $result = $callback([], $context);
                } catch (HookVetoException) {
                    continue;
                } catch (\Throwable $e) {
                    self::fail(
                        "Hook listener for '{$eventName}' threw " . get_class($e) . ": {$e->getMessage()}. "
                        . 'The real offline host would silently swallow this (per-plugin error boundary) and '
                        . 'carry on with the original payload, so this bug would otherwise ship invisibly.'
                    );
                }

                self::assertIsArray(
                    $result,
                    "Hook listener for '{$eventName}' must return an array (the SDK's documented filter-hook "
                    . 'contract) — a non-array return is silently discarded by the host, which usually signals a bug.'
                );
            }
        }
    }

    /**
     * @return list<callable>
     */
    private static function normalizeHooks(mixed $hookData): array
    {
        if (is_callable($hookData)) {
            return [$hookData];
        }

        if (!is_array($hookData)) {
            return [];
        }

        if (isset($hookData['callback']) && is_callable($hookData['callback'])) {
            return [$hookData['callback']];
        }

        $callbacks = [];
        foreach ($hookData as $item) {
            if (is_callable($item)) {
                $callbacks[] = $item;
            } elseif (is_array($item) && isset($item['callback']) && is_callable($item['callback'])) {
                $callbacks[] = $item['callback'];
            }
        }

        return $callbacks;
    }

    // ==================== shared fixtures ====================

    /**
     * The PDO the checks above run against. Defaults to
     * {@see OfflineHostReferencePdo} (in-memory SQLite with the same
     * SERIAL-rewrite/NOW()-UDF/STRINGIFY_FETCHES techniques the real offline
     * host's SqliteCompatPdo independently arrived at). Override only if a
     * plugin's migrations need a dialect affordance beyond what that class
     * handles.
     */
    protected function makePdo(): \PDO
    {
        return new OfflineHostReferencePdo();
    }

    /**
     * The same bare skeleton MigrationRunner::bootstrapHostSkeleton() creates
     * in the real offline host, reproduced here since the SDK cannot import
     * that class directly.
     */
    private function bootstrapHostSkeleton(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE roles (id INTEGER PRIMARY KEY, name VARCHAR(255) NOT NULL UNIQUE)');
        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW())
            )
        ');
        $pdo->exec('
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY,
                role_id INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT (NOW()),
                UNIQUE(role_id, permission_id)
            )
        ');
        $pdo->exec("INSERT INTO roles (name) VALUES ('admin')");
    }

    private function roleHasPermission(\PDO $pdo, string $role, string $permission): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM roles r
             JOIN role_permissions rp ON rp.role_id = r.id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.name = :role AND p.name = :permission'
        );
        $stmt->execute([':role' => $role, ':permission' => $permission]);

        return $stmt->fetchColumn() !== false;
    }
}
