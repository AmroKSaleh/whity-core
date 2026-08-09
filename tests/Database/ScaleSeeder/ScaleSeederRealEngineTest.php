<?php

declare(strict_types=1);

namespace Whity\Tests\Database\ScaleSeeder;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Database\Database;
use Whity\Database\ScaleSeeder\ScaleSeeder;
use Whity\Database\ScaleSeeder\ScaleSeederConfig;
use Whity\Database\ScaleSeeder\ScaleSeederPlan;

/**
 * Real-engine (in-memory SQLite) tests for {@see ScaleSeeder} (WC-35).
 *
 * Runs the real production migrations, then the scale-seeder, against a
 * genuine SQL engine (mirrors {@see \Whity\Tests\Database\SeederRealEngineTest}
 * for the original bootstrap seeder). Proves the headline requirements from
 * the task spec:
 *  - the generated dataset is the RIGHT SHAPE (exact row counts match the
 *    computed {@see ScaleSeederPlan});
 *  - it is REFERENTIALLY INTACT (every FK resolves, every tenant-owned row's
 *    tenant_id is consistent across joins — no cross-tenant leakage);
 *  - it is DETERMINISTIC (two independent databases seeded with the identical
 *    config produce byte-identical names/emails/edges);
 *  - it is IDEMPOTENT (re-running the identical config creates nothing new);
 *  - `--reset` cleanly removes exactly what this seed created.
 */
final class ScaleSeederRealEngineTest extends TestCase
{
    private const PASSWORD_ENV_VAR = 'SCALE_SEED_PASSWORD';
    private const PASSWORD_VALUE = 'wc35-scale-seed-fixture-password-0123456789';

    protected function setUp(): void
    {
        $_ENV[self::PASSWORD_ENV_VAR] = self::PASSWORD_VALUE;
        putenv(self::PASSWORD_ENV_VAR . '=' . self::PASSWORD_VALUE);
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::PASSWORD_ENV_VAR]);
        putenv(self::PASSWORD_ENV_VAR);
    }

    private function freshDatabase(): Database
    {
        $pdo = SchemaFromMigrations::make();
        $db = Database::withFactory(fn(): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    private function smallConfig(int $seed = 1234): ScaleSeederConfig
    {
        return ScaleSeederConfig::make(
            seed: $seed,
            tenants: 2,
            usersPerTenant: 4,
            ouDepth: 2,
            ouBreadth: 2,
            relationsPerPerson: 1.0,
            customRolesPerTenant: 1,
            batchSize: 2,
        );
    }

    public function testRunProducesExactlyThePlannedRowCounts(): void
    {
        $db = $this->freshDatabase();
        $config = $this->smallConfig();
        $plan = ScaleSeederPlan::fromConfig($config);

        $result = (new ScaleSeeder($db))->run($config);

        self::assertSame($plan->tenants, $result->tenantsCreated);
        self::assertSame($plan->totalOus, $result->ousCreated);
        self::assertSame($plan->totalCustomRoles, $result->customRolesCreated);
        self::assertSame($plan->totalUsers, $result->usersCreated);
        self::assertSame($plan->totalPersons, $result->personsCreated);
        self::assertSame($plan->totalRelations, $result->relationsCreated);

        // Cross-check against the actual database, not just the in-memory result.
        $pdo = $db->getPdo();
        self::assertSame(
            $plan->totalOus,
            (int) $pdo->query("SELECT COUNT(*) FROM organizational_units WHERE tenant_id IN (
                SELECT id FROM tenants WHERE slug LIKE 'scale-1234-t%'
            )")->fetchColumn()
        );
        self::assertSame(
            $plan->totalUsers,
            (int) $pdo->query("SELECT COUNT(*) FROM memberships WHERE tenant_id IN (
                SELECT id FROM tenants WHERE slug LIKE 'scale-1234-t%'
            )")->fetchColumn()
        );
        self::assertSame(
            $plan->totalRelations,
            (int) $pdo->query("SELECT COUNT(*) FROM relations WHERE tenant_id IN (
                SELECT id FROM tenants WHERE slug LIKE 'scale-1234-t%'
            )")->fetchColumn()
        );
    }

    public function testEveryOuBelongsToItsOwnTenantAndParentWithinTheSameTenant(): void
    {
        $db = $this->freshDatabase();
        (new ScaleSeeder($db))->run($this->smallConfig());

        $pdo = $db->getPdo();
        $rows = $pdo->query(
            "SELECT o.id, o.tenant_id, o.parent_id, p.tenant_id AS parent_tenant_id
             FROM organizational_units o
             LEFT JOIN organizational_units p ON p.id = o.parent_id
             WHERE o.tenant_id IN (SELECT id FROM tenants WHERE slug LIKE 'scale-1234-t%')"
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            if ($row['parent_id'] !== null) {
                self::assertSame(
                    (int) $row['tenant_id'],
                    (int) $row['parent_tenant_id'],
                    'An OU\'s parent must belong to the same tenant.'
                );
            }
        }
    }

    public function testEveryRelationConnectsTwoPersonsOfTheSameTenant(): void
    {
        $db = $this->freshDatabase();
        (new ScaleSeeder($db))->run($this->smallConfig());

        $pdo = $db->getPdo();
        $rows = $pdo->query(
            "SELECT r.tenant_id AS relation_tenant, f.tenant_id AS from_tenant, t.tenant_id AS to_tenant, r.from_person_id, r.to_person_id
             FROM relations r
             JOIN persons f ON f.id = r.from_person_id
             JOIN persons t ON t.id = r.to_person_id"
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame((int) $row['relation_tenant'], (int) $row['from_tenant']);
            self::assertSame((int) $row['relation_tenant'], (int) $row['to_tenant']);
            self::assertNotSame(
                (int) $row['from_person_id'],
                (int) $row['to_person_id'],
                'A relation must never self-loop.'
            );
        }
    }

    public function testEveryMembershipOuBelongsToTheSameTenantAsTheMembership(): void
    {
        $db = $this->freshDatabase();
        (new ScaleSeeder($db))->run($this->smallConfig());

        $pdo = $db->getPdo();
        $rows = $pdo->query(
            "SELECT m.tenant_id AS membership_tenant, o.tenant_id AS ou_tenant
             FROM memberships m
             JOIN organizational_units o ON o.id = m.ou_id
             WHERE m.ou_id IS NOT NULL"
        )->fetchAll(PDO::FETCH_ASSOC);

        self::assertNotEmpty($rows, 'At least one membership should have an OU assigned.');
        foreach ($rows as $row) {
            self::assertSame((int) $row['membership_tenant'], (int) $row['ou_tenant']);
        }
    }

    public function testEveryPersonProfileLinkResolvesToARealProfileInTheSameFlow(): void
    {
        $db = $this->freshDatabase();
        (new ScaleSeeder($db))->run($this->smallConfig());

        $pdo = $db->getPdo();
        $orphaned = (int) $pdo->query(
            "SELECT COUNT(*) FROM persons p
             WHERE p.profile_id IS NOT NULL
               AND NOT EXISTS (SELECT 1 FROM profiles pr WHERE pr.id = p.profile_id)"
        )->fetchColumn();

        self::assertSame(0, $orphaned, 'Every non-null persons.profile_id must resolve to a real profile.');
    }

    public function testEveryScaleSeededMembershipRoleExistsAndIsVisibleToItsTenant(): void
    {
        $db = $this->freshDatabase();
        (new ScaleSeeder($db))->run($this->smallConfig());

        $pdo = $db->getPdo();
        $invalid = (int) $pdo->query(
            "SELECT COUNT(*) FROM memberships m
             JOIN roles r ON r.id = m.role_id
             WHERE NOT (r.tenant_id IS NULL OR r.tenant_id = m.tenant_id)"
        )->fetchColumn();

        self::assertSame(0, $invalid, 'A membership\'s role must be global or belong to the same tenant.');
    }

    public function testTwoIndependentRunsWithTheSameConfigProduceIdenticalNamesEmailsAndEdges(): void
    {
        $dbA = $this->freshDatabase();
        $dbB = $this->freshDatabase();
        $config = $this->smallConfig(seed: 555);

        (new ScaleSeeder($dbA))->run($config);
        (new ScaleSeeder($dbB))->run($config);

        $tenantNamesA = $this->fetchColumnList($dbA->getPdo(), 'SELECT name FROM tenants ORDER BY slug');
        $tenantNamesB = $this->fetchColumnList($dbB->getPdo(), 'SELECT name FROM tenants ORDER BY slug');
        self::assertSame($tenantNamesA, $tenantNamesB, 'Tenant names must be identical across independent runs.');

        $emailsA = $this->fetchColumnList($dbA->getPdo(), 'SELECT email FROM profile_emails ORDER BY email');
        $emailsB = $this->fetchColumnList($dbB->getPdo(), 'SELECT email FROM profile_emails ORDER BY email');
        self::assertSame($emailsA, $emailsB, 'User emails must be identical across independent runs.');

        $ouNamesA = $this->fetchColumnList($dbA->getPdo(), 'SELECT name FROM organizational_units ORDER BY slug');
        $ouNamesB = $this->fetchColumnList($dbB->getPdo(), 'SELECT name FROM organizational_units ORDER BY slug');
        self::assertSame($ouNamesA, $ouNamesB, 'OU names must be identical across independent runs.');

        // Relation edges are keyed off auto-increment person ids, which are
        // identical across two fresh, identically-ordered runs; compare the
        // (from, to, type) triples directly.
        $edgesA = $this->fetchColumnList(
            $dbA->getPdo(),
            'SELECT from_person_id || \'-\' || to_person_id || \'-\' || relationship_type_id
             FROM relations ORDER BY 1'
        );
        $edgesB = $this->fetchColumnList(
            $dbB->getPdo(),
            'SELECT from_person_id || \'-\' || to_person_id || \'-\' || relationship_type_id
             FROM relations ORDER BY 1'
        );
        self::assertSame($edgesA, $edgesB, 'Relation edges must be identical across independent runs.');
    }

    public function testRerunningTheSameConfigIsIdempotent(): void
    {
        $db = $this->freshDatabase();
        $config = $this->smallConfig();

        $first = (new ScaleSeeder($db))->run($config);
        $second = (new ScaleSeeder($db))->run($config);

        self::assertGreaterThan(0, $first->tenantsCreated);
        self::assertSame(0, $second->tenantsCreated, 'A rerun must create zero new tenants.');
        self::assertSame(0, $second->ousCreated, 'A rerun must create zero new OUs.');
        self::assertSame(0, $second->usersCreated, 'A rerun must create zero new users.');
        self::assertSame(0, $second->personsCreated, 'A rerun must create zero new persons.');
        self::assertSame(0, $second->relationsCreated, 'A rerun must create zero new relations.');

        self::assertSame($first->tenantsCreated, $second->tenantsReused);
        self::assertSame($first->usersCreated, $second->usersReused);

        $pdo = $db->getPdo();
        $tenantCount = (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug LIKE 'scale-1234-t%'")
            ->fetchColumn();
        self::assertSame(2, $tenantCount, 'Row count must not double after a rerun.');
    }

    public function testResetRemovesExactlyWhatThisSeedCreated(): void
    {
        $db = $this->freshDatabase();
        $config = $this->smallConfig();
        (new ScaleSeeder($db))->run($config);

        $seeder = new ScaleSeeder($db);
        $summary = $seeder->reset($config);

        self::assertSame(2, $summary['tenantsDeleted']);
        self::assertSame(8, $summary['profilesDeleted']);

        $pdo = $db->getPdo();
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug LIKE 'scale-1234-t%'")->fetchColumn());
        self::assertSame(
            0,
            (int) $pdo->query("SELECT COUNT(*) FROM profile_emails WHERE email LIKE 'scale-seed1234-t%'")->fetchColumn()
        );
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM organizational_units')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM persons')->fetchColumn());
        self::assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM relations')->fetchColumn());
    }

    public function testResetIsScopedToItsOwnSeedAndLeavesOtherSeedsAlone(): void
    {
        $db = $this->freshDatabase();
        $seeder = new ScaleSeeder($db);

        $configA = $this->smallConfig(seed: 111);
        $configB = $this->smallConfig(seed: 222);
        $seeder->run($configA);
        $seeder->run($configB);

        $seeder->reset($configA);

        $pdo = $db->getPdo();
        self::assertSame(0, (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug LIKE 'scale-111-t%'")->fetchColumn());
        self::assertSame(2, (int) $pdo->query("SELECT COUNT(*) FROM tenants WHERE slug LIKE 'scale-222-t%'")->fetchColumn());
    }

    public function testDryRunPlanMatchesTheActualCountsForTheSameConfig(): void
    {
        $config = $this->smallConfig();
        $plan = (new ScaleSeeder($this->freshDatabase()))->plan($config);

        self::assertSame(2, $plan->tenants);
        self::assertSame(3, $plan->ousPerTenant); // 1 root + 2 breadth
        self::assertSame(6, $plan->totalOus);
        self::assertSame(4, $plan->usersPerTenant);
        self::assertSame(8, $plan->totalUsers);
        self::assertSame(8, $plan->totalPersons);
        self::assertSame(2, $plan->relationsPerTenant); // round(4 * 1.0 / 2)
        self::assertSame(4, $plan->totalRelations);
    }

    /** @return list<string> */
    private function fetchColumnList(PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);
        self::assertNotFalse($stmt);

        return array_map(static fn(array $row): string => (string) array_values($row)[0], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
