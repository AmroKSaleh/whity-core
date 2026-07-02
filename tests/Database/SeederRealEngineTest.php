<?php

declare(strict_types=1);

namespace Whity\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Database\Database;
use Whity\Database\Seeder;

/**
 * Real-engine (in-memory SQLite) tests for {@see Seeder::seed()} (WC-223).
 *
 * Runs the real production migrations followed by {@see Seeder::seed()} against a
 * genuine SQL engine, so the seed's INSERT/SELECT semantics are exercised exactly
 * as they run on PostgreSQL (the CI postgres-integration job covers the real-PG
 * path; here SQLite stands in because CI has no live PostgreSQL).
 *
 * The headline assertion is the WC-223 deliverable: an out-of-the-box
 * `superuser@example.com` account in the SYSTEM tenant (id 0) holding the `admin`
 * role, which — per the RBAC model — may manage global base roles and every
 * tenant. The default `admin@example.com` lives in a regular tenant and cannot.
 * Re-running the seeder must not duplicate that row (idempotent ON CONFLICT).
 */
final class SeederRealEngineTest extends TestCase
{
    /** Deterministic, >= 32-char fixture password (project secret policy). */
    private const SUPERUSER_PASSWORD = 'wc223-superuser-fixture-password-0123456789';

    private PDO $pdo;
    private Database $db;

    /**
     * Initial-password env vars set for the test so {@see Seeder::seed()} runs
     * deterministically and never prints a "generated password" notice (which
     * PHPUnit would flag as risky test output).
     *
     * @var list<string>
     */
    private const PASSWORD_ENV_VARS = [
        'INITIAL_SUPERUSER_PASSWORD',
        'INITIAL_ADMIN_PASSWORD',
        'INITIAL_USER_PASSWORD',
        // WC-10522424: the seeder now also seeds the system admin profile, which
        // reads INITIAL_SYSTEM_ADMIN_PASSWORD; set it so no generated-password
        // operator notice is printed to STDOUT (PHPUnit flags that as risky).
        'INITIAL_SYSTEM_ADMIN_PASSWORD',
    ];

    protected function setUp(): void
    {
        foreach (self::PASSWORD_ENV_VARS as $var) {
            $_ENV[$var] = self::SUPERUSER_PASSWORD;
            putenv($var . '=' . self::SUPERUSER_PASSWORD);
        }

        $this->pdo = SchemaFromMigrations::make();
        $this->db = Database::withFactory(fn(): PDO => $this->pdo, 86400, 86400);
        $this->db->forceConnect();
    }

    protected function tearDown(): void
    {
        foreach (self::PASSWORD_ENV_VARS as $var) {
            unset($_ENV[$var]);
            putenv($var);
        }
    }

    public function testSeedCreatesSystemTenantSuperuserWithAdminRole(): void
    {
        Seeder::seed($this->db);

        $row = $this->fetchUser('superuser@example.com');

        self::assertNotFalse($row, 'Seeder must create superuser@example.com.');
        self::assertSame(0, (int) $row['tenant_id'], 'Superuser must live in the system tenant (id 0).');
        self::assertSame(
            $this->adminRoleId(),
            (int) $row['role_id'],
            'Superuser must hold the global admin role.'
        );
    }

    public function testSeededSuperuserTenantIsTheSystemTenant(): void
    {
        Seeder::seed($this->db);

        $row = $this->fetchUser('superuser@example.com');
        self::assertNotFalse($row);

        $tenant = $this->db
            ->query('SELECT name FROM tenants WHERE id = :id', [':id' => (int) $row['tenant_id']])
            ->fetch();

        self::assertIsArray($tenant);
        self::assertSame('System', $tenant['name'], 'tenant_id 0 must be the System tenant.');
    }

    public function testReSeedingDoesNotDuplicateTheSuperuser(): void
    {
        Seeder::seed($this->db);
        Seeder::seed($this->db);

        $count = $this->db
            ->query(
                'SELECT COUNT(*) AS c FROM users WHERE tenant_id = 0 AND email = :email',
                [':email' => 'superuser@example.com']
            )
            ->fetch();

        self::assertIsArray($count);
        self::assertSame(1, (int) $count['c'], 'Re-running the seeder must not duplicate the superuser.');
    }

    /**
     * @return array<string, mixed>|false
     */
    private function fetchUser(string $email): array|false
    {
        return $this->db
            ->query('SELECT tenant_id, role_id FROM users WHERE email = :email', [':email' => $email])
            ->fetch();
    }

    private function adminRoleId(): int
    {
        $role = $this->db
            ->query('SELECT id FROM roles WHERE name = :name', [':name' => 'admin'])
            ->fetch();

        self::assertIsArray($role, 'The admin base role must be seeded by the migrations.');

        return (int) $role['id'];
    }
}
