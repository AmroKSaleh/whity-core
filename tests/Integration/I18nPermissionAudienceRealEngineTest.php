<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;

/**
 * The i18n permissions must reach whoever a deployment trusts with global
 * settings — not only a role that happens to be spelled `admin`.
 *
 * #1047 reported `languages:manage` as held by nobody. That does not reproduce
 * (migration 086 grants it, and the first test here pins that), but the way 086
 * grants it is the #834 hazard migration 110 records: `WHERE name = 'admin'`
 * reaches exactly one role, so a deployment that renamed its administrative role
 * receives nothing and finds out when a nav item is silently missing.
 *
 * These run against a MIGRATED database with no seed, because that is the state
 * a real upgrade lands in — whatever the migrations granted and nothing an
 * operator added by hand. The CI holder guard runs after migrate AND seed, so a
 * slug the seeder grants looks held there while being orphaned on every upgraded
 * deployment; that difference is why this file builds its own.
 */
final class I18nPermissionAudienceRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
    }

    /** @return list<string> */
    private function rolesHolding(string $slug): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT r.name
               FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :slug
              ORDER BY r.name'
        );
        $stmt->execute([':slug' => $slug]);

        /** @var list<string> $names */
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }

    private function roleId(string $name): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = :n AND tenant_id IS NULL');
        $stmt->execute([':n' => $name]);
        $id = $stmt->fetchColumn();
        self::assertNotFalse($id, "expected a global role named {$name}");

        return (int) $id;
    }

    /**
     * The reported symptom, pinned so it cannot come back: held by SOMEBODY on a
     * migrated database. #1047 was filed against an instance whose migrations had
     * drifted behind its checkout, and this is the assertion that would have said
     * so.
     */
    public function testLanguagesManageIsHeldBySomebodyAfterMigrating(): void
    {
        self::assertNotSame(
            [],
            $this->rolesHolding(CorePermissions::LANGUAGES_MANAGE),
            'languages:manage gates a nav item; held by nobody means the Languages screen '
            . 'exists, works, and cannot be navigated to'
        );
    }

    public function testTranslationsManageIsHeldBySomebodyAfterMigrating(): void
    {
        self::assertNotSame([], $this->rolesHolding(CorePermissions::TRANSLATIONS_MANAGE));
    }

    /**
     * THE HAZARD. A deployment whose administrative role is not called `admin`
     * gets nothing from a `grant_*_to_admin` migration. Anchoring on
     * `settings:manage` means the grant follows what the deployment actually
     * trusts, so a custom role holding the anchor holds these too.
     *
     * Simulated the way an operator creates one: a role that holds
     * `settings:manage` but is not named `admin`.
     */
    public function testACustomRoleHoldingTheAnchorAlsoHoldsTheI18nPermissions(): void
    {
        // A deployment's own administrative role, created before the migration runs.
        $this->pdo->exec(
            "INSERT INTO roles (name, description, parent_id, tenant_id, created_at)
             VALUES ('superuser', 'A deployment-specific administrative role', NULL, NULL, NOW())"
        );
        $superuser = $this->roleId('superuser');

        $settingsManage = $this->pdo->prepare('SELECT id FROM permissions WHERE name = :n');
        $settingsManage->execute([':n' => CorePermissions::SETTINGS_MANAGE]);
        $anchorId = (int) $settingsManage->fetchColumn();

        $grant = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (:r, :p, NOW())'
        );
        $grant->execute([':r' => $superuser, ':p' => $anchorId]);

        // Re-run the migration, as an upgrade would.
        \Database\Migrations\GrantI18nPermsByCapability::up(
            \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400)
        );

        self::assertContains(
            'superuser',
            $this->rolesHolding(CorePermissions::LANGUAGES_MANAGE),
            'A deployment that renamed its administrative role must not silently lose the capability'
        );
        self::assertContains('superuser', $this->rolesHolding(CorePermissions::TRANSLATIONS_MANAGE));
    }

    /**
     * The grant must not widen beyond the anchor. A role holding no relevant
     * capability must not pick these up as a side effect.
     */
    public function testARoleWithoutTheAnchorDoesNotReceiveThem(): void
    {
        $this->pdo->exec(
            "INSERT INTO roles (name, description, parent_id, tenant_id, created_at)
             VALUES ('bystander', 'Holds nothing relevant', NULL, NULL, NOW())"
        );

        \Database\Migrations\GrantI18nPermsByCapability::up(
            \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400)
        );

        self::assertNotContains('bystander', $this->rolesHolding(CorePermissions::LANGUAGES_MANAGE));
        self::assertNotContains('bystander', $this->rolesHolding(CorePermissions::TRANSLATIONS_MANAGE));
    }

    /** Re-running an additive grant must change nothing. */
    public function testTheGrantIsIdempotent(): void
    {
        $before = $this->rolesHolding(CorePermissions::LANGUAGES_MANAGE);

        \Database\Migrations\GrantI18nPermsByCapability::up(
            \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400)
        );

        self::assertSame($before, $this->rolesHolding(CorePermissions::LANGUAGES_MANAGE));
    }
}
