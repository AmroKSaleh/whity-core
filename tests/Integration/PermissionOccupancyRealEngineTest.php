<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionOccupancy;

/**
 * Occupancy, against a database built the way a real one is (#1047).
 *
 * WHAT IT ANSWERS THAT CI CANNOT. `ci-permission-holder-guard.php` asks whether
 * every gated slug has a holder, on the database CI builds — migrated, seeded,
 * administrative role named `admin`. Twenty-seven migrations grant with
 * `WHERE name = 'admin'`, so on that database every grant lands and every slug
 * looks held.
 *
 * On a deployment whose administrator is called something else, those same
 * migrations granted NOBODY and reported nothing, because "no role called admin"
 * is indistinguishable from "already granted". That is a property of a
 * deployment, so it is measured against one rather than asserted about the code.
 */
final class PermissionOccupancyRealEngineTest extends TestCase
{
    private PDO $pdo;
    private PermissionOccupancy $occupancy;
    private int $tenantId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->occupancy = new PermissionOccupancy($this->pdo);

        // Its own tenant rather than an assumed id 1: which tenants exist after
        // the migrations differs by engine, and a hardcoded id passes on SQLite
        // and fails every PostgreSQL shard on the foreign key.
        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, created_at) VALUES (:name, NOW())');
        $stmt->execute([':name' => 'occupancy-fixture']);
        $this->tenantId = (int) $this->pdo->lastInsertId();
    }

    private function makeRole(string $name): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, '', ?, NOW())"
        );
        $stmt->execute([$name, $this->tenantId]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Take a slug away from EVERY role, so the "nobody holds it" precondition
     * is established rather than assumed.
     *
     * It has to be: the migrations create a role named `admin` and grant it most
     * of the catalogue, so a test that simply asserted `users:write` was unheld
     * on a freshly migrated database was asserting the opposite of the truth —
     * which is how the first version of this file failed. Stating the
     * precondition also records the fact worth knowing: on a migrations-only
     * database the by-name grants DO land, because that admin role exists.
     */
    private function revokeEverywhere(string $slug): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM role_permissions
              WHERE permission_id IN (SELECT id FROM permissions WHERE name = ?)'
        );
        $stmt->execute([$slug]);
    }

    private function grant(int $roleId, string $slug): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT ?, id, NOW() FROM permissions WHERE name = ?'
        );
        $stmt->execute([$roleId, $slug]);
    }

    public function testASlugNoRoleHoldsIsReportedUnheld(): void
    {
        $this->revokeEverywhere(CorePermissions::USERS_WRITE);

        self::assertSame(
            [CorePermissions::USERS_WRITE],
            $this->occupancy->unheld([CorePermissions::USERS_WRITE]),
            'a gated slug nobody holds is a lockout: the gate refuses every caller there is, '
            . 'including the administrator it was written for'
        );
    }

    public function testGrantingItToAnyRoleClearsIt(): void
    {
        $this->revokeEverywhere(CorePermissions::USERS_WRITE);
        $roleId = $this->makeRole('superuser');
        $this->grant($roleId, CorePermissions::USERS_WRITE);

        self::assertSame(
            [],
            $this->occupancy->unheld([CorePermissions::USERS_WRITE]),
            'held by ANY role in ANY tenant is enough — a route gate is instance-wide, so one '
            . 'holder anywhere means somebody can answer it'
        );
    }

    public function testItReportsOnlyTheSlugsAskedAbout(): void
    {
        $this->revokeEverywhere(CorePermissions::USERS_READ);
        $this->revokeEverywhere(CorePermissions::USERS_WRITE);
        $roleId = $this->makeRole('partial');
        $this->grant($roleId, CorePermissions::USERS_READ);

        self::assertSame(
            [CorePermissions::USERS_WRITE],
            $this->occupancy->unheld([CorePermissions::USERS_READ, CorePermissions::USERS_WRITE]),
            'and in the caller\'s order, so a report reads the same way twice'
        );
    }

    public function testAnUnknownSlugCountsAsUnheld(): void
    {
        // Not a catalogue row at all. Reporting it as HELD would be the
        // dangerous direction: a typo in a gate would read as satisfied.
        self::assertSame(
            ['acme:not-a-real-permission'],
            $this->occupancy->unheld(['acme:not-a-real-permission'])
        );
    }

    public function testNothingAskedMeansNothingReported(): void
    {
        self::assertSame([], $this->occupancy->unheld([]));
    }

    /**
     * The specific diagnosis: whether the by-name grants could have landed here.
     *
     * The migrations DO create a role named `admin`, so a migrated database
     * answers yes — which is precisely why neither CI nor a fresh install can
     * show this class of problem. #1047's deployment is one where somebody
     * RENAMED that role, and this is the state that produces it.
     */
    public function testItDetectsWhetherARoleNamedAdminExists(): void
    {
        self::assertTrue(
            $this->occupancy->hasRoleNamedAdmin(),
            'the migrations create it, which is why the by-name grants land on every database '
            . 'CI or a fresh install can build'
        );

        $this->pdo->exec("UPDATE roles SET name = 'superuser' WHERE name = 'admin'");

        self::assertFalse(
            $this->occupancy->hasRoleNamedAdmin(),
            'and once it is renamed, every by-name migration granted nobody — silently, because '
            . 'finding no role is indistinguishable from already having granted'
        );
    }

    /**
     * A catalogue entry nothing gates on is reported SEPARATELY and is not a
     * finding. Folding it in with the real findings would send an operator
     * chasing a slug that is doing exactly what it should.
     *
     * BOTH PRECONDITIONS ARE ESTABLISHED HERE, and that is the point. Until #990
     * this test named `tenants:read` as a live example: a slug that really was
     * unheld and really was ungated on a freshly migrated database. #990 gated it
     * on `GET /api/tenants` and granted it (migration 138), and the assertion
     * went red — for the best possible reason, but it went red, because it had
     * been leaning on a defect staying in the tree. A test about the SEPARATION
     * of two categories should construct one member of each rather than borrow
     * whichever slug happens to be neglected this month.
     */
    public function testAnUngatedOrphanIsSeparatedFromTheFindings(): void
    {
        // Unheld and NOT gated — the harmless kind.
        $this->revokeEverywhere(CorePermissions::TENANTS_READ);
        // Unheld and gated — the lockout kind. Revoked too, so the assertion
        // below turns on the gating and not on who happens to hold it.
        $this->revokeEverywhere(CorePermissions::USERS_READ);

        $gated = [CorePermissions::USERS_READ];

        $ungated = $this->occupancy->unheldAndUngated($gated);

        self::assertContains(CorePermissions::TENANTS_READ, $ungated);
        self::assertNotContains(
            CorePermissions::USERS_READ,
            $ungated,
            'a slug that IS gated belongs in the findings, never in the harmless list'
        );
        self::assertContains(
            CorePermissions::USERS_READ,
            $this->occupancy->unheld($gated),
            'and it belongs in the findings, since a gate nobody can pass is a lockout'
        );
    }
}
