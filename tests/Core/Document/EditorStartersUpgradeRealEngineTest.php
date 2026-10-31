<?php

declare(strict_types=1);

namespace Tests\Core\Document;

use Database\Migrations\RepairMissingEditorStarters;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceStarterSeeder;
use Whity\Database\Database;

/**
 * Upgrading a 0.2.19 install keeps every workspace's editor starters
 * (WHIT-803). Runs on SQLite by default and on PostgreSQL when PHPUNIT_PG_DSN
 * is set.
 *
 * 0.2.20's migration 165 seeded the starters through the document repository,
 * which also wrote a column migration 171 adds later. The seeder logged the
 * error and the upgrade went on, so every upgraded workspace had none. A full
 * schema can't see that: the column is already there. So this test takes the
 * schema back to before 165 with each migration's own `down()`, then runs
 * `up()` forward with today's code, as an upgrade from 0.2.19 does. The next
 * column the repository learns to write fails here, not on a customer's
 * upgrade.
 */
final class EditorStartersUpgradeRealEngineTest extends TestCase
{
    /** The first migration 0.2.19 didn't have that seeds through today's code. */
    private const FIRST_AFTER_0_2_19 = 165;

    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->db = Database::withFactory(fn (): PDO => $this->pdo);
        $this->db->setMaxLifetimeSeconds(86400);
        $this->db->setPingIntervalSeconds(86400);
        $this->db->forceConnect();
    }

    public function testUpgradingFrom0219SeedsTheStartersAndConvertsOldTemplates(): void
    {
        $classes = SchemaFromMigrations::migrationClassesFrom(self::FIRST_AFTER_0_2_19);
        foreach (array_reverse($classes) as $class) {
            $class::down($this->db);
        }
        self::assertFalse($this->hasColumn('document_sources', 'theme'), 'the schema is back before migration 171');

        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Clinic', 'clinic')");
        $this->pdo->exec(
            "INSERT INTO document_templates (tenant_id, name, data, scope, is_system, starter_key, created_at, updated_at)
             VALUES (1, 'Delivery note', '{\"elements\":[]}', 'tenant', FALSE, NULL, '2026-01-10 09:00:00', '2026-01-11 09:00:00')"
        );

        foreach ($classes as $class) {
            $class::up($this->db);
        }

        $expected = count((new DocumentSourceStarterSeeder(new DocumentSourceRepository($this->pdo)))->starters());
        self::assertSame($expected, $this->starters(1), "workspace 1 has every starter");
        self::assertSame($expected, $this->starters(2), "workspace 2 has every starter");
        self::assertSame(1, (int) $this->scalar(
            'SELECT COUNT(*) FROM document_sources WHERE tenant_id = 1 AND legacy_template_id IS NOT NULL'
        ), 'the old template was converted');
        self::assertTrue($this->hasColumn('document_sources', 'theme'));
    }

    public function testTheRepairSeedsOnlyWorkspacesWithNoStarters(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery'), (2, 'Example Clinic', 'clinic')");
        $seeder = new DocumentSourceStarterSeeder(new DocumentSourceRepository($this->pdo));
        $expected = count($seeder->starters());
        // Workspace 1 was repaired by hand and then deleted one starter it doesn't use.
        $seeder->seedForTenant(1);
        $this->pdo->exec("DELETE FROM document_sources WHERE tenant_id = 1 AND starter_key = 'editor.certificate'");

        RepairMissingEditorStarters::up($this->db);

        self::assertSame($expected - 1, $this->starters(1), 'a workspace that has starters is left as it is');
        self::assertSame($expected, $this->starters(2), 'the one the upgrade left empty gets them');

        RepairMissingEditorStarters::up($this->db);
        self::assertSame($expected, $this->starters(2), 'running again adds nothing');
    }

    public function testInAMigrationAStarterThatCantBeWrittenStopsTheUpgrade(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Example Bakery', 'bakery')");
        // CASCADE for Postgres (versions and files reference it); SQLite drops the keyword.
        $this->pdo->exec('DROP TABLE document_sources CASCADE');
        $seeder = new DocumentSourceStarterSeeder(new DocumentSourceRepository($this->pdo));

        self::assertSame(0, $seeder->seedForTenant(1), 'provisioning goes on without them');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Seeding the editor starters failed for tenant 1');
        $seeder->seedForTenant(1, strict: true);
    }

    private function starters(int $tenantId): int
    {
        return (int) $this->scalar("SELECT COUNT(*) FROM document_sources WHERE tenant_id = {$tenantId} AND starter_key IS NOT NULL");
    }

    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchColumn();
    }

    private function hasColumn(string $table, string $column): bool
    {
        $stmt = $this->pdo->query("PRAGMA table_info({$table})");
        self::assertNotFalse($stmt);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (($row['name'] ?? null) === $column) {
                return true;
            }
        }

        return false;
    }
}
