<?php

declare(strict_types=1);

namespace Whity\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentTemplateRepository;
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
 *
 * Post WC-idcut-F: the `users` table is retired (migration 042). Assertions now
 * query `profiles`, `profile_emails`, and `memberships`.
 */
final class SeederRealEngineTest extends TestCase
{
    /** Deterministic, >= 32-char fixture password (project secret policy). */
    private const SUPERUSER_PASSWORD = 'wc223-superuser-fixture-password-0123456789';

    private PDO $pdo;
    private Database $db;

    /** APP_ENV as the process had it before setUp() forced 'development'. */
    private string|false $savedAppEnv = false;

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

        // WC-779: superuser@example.com is a DEV FIXTURE now, provisioned only
        // under APP_ENV=development. This suite is about that account, so it
        // asks for the environment that has it.
        $this->savedAppEnv  = $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        $_ENV['APP_ENV']    = 'development';
        putenv('APP_ENV=development');

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

        if (is_string($this->savedAppEnv)) {
            $_ENV['APP_ENV'] = $this->savedAppEnv;
            putenv('APP_ENV=' . $this->savedAppEnv);
        } else {
            unset($_ENV['APP_ENV']);
            putenv('APP_ENV');
        }
    }

    public function testSeedCreatesSystemTenantSuperuserWithAdminRole(): void
    {
        Seeder::seed($this->db);

        $row = $this->fetchProfile('superuser@example.com');

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

        $row = $this->fetchProfile('superuser@example.com');
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
                'SELECT COUNT(*) AS c FROM profile_emails WHERE email = :email',
                [':email' => 'superuser@example.com']
            )
            ->fetch();

        self::assertIsArray($count);
        self::assertSame(1, (int) $count['c'], 'Re-running the seeder must not duplicate the superuser.');
    }

    // ── #1012: the Default Tenant is a provisioned tenant, not just a row ────

    /**
     * A FRESH SEED LEAVES THE DEFAULT TENANT WITH ITS STARTER TEMPLATES.
     *
     * The regression this pins is that it did not. Starters were provisioned by
     * a `tenant.created` listener registered in `public/index.php`; the seeder
     * created this tenant with a bare INSERT and never reached that file's
     * bootstrap, so the tenant every fresh install actually opens the designer in
     * was the only one in the system with an empty library — defeating the
     * explicit requirement that the designer never present an empty document.
     *
     * ASSERTED ON THE OUTCOME, NOT ON THE MECHANISM. There is deliberately no
     * assertion here that a `tenant.created` dispatch happened, or that a step
     * ran, or that any particular class was called: the event is one way to
     * arrange this and the starters are the requirement. A future rearrangement
     * that keeps the tenant stocked should keep this test green; one that
     * dispatches perfectly and leaves the library empty should not.
     */
    public function testAFreshSeedLeavesTheDefaultTenantWithItsStarterTemplates(): void
    {
        Seeder::seed($this->db);

        $templates = (new DocumentTemplateRepository($this->pdo))->listForTenant($this->defaultTenantId());

        self::assertNotSame(
            [],
            $templates,
            'A fresh install must not open the designer on an empty library in the Default Tenant.'
        );

        // And they are the SHIPPED starters rather than some other row that
        // happens to exist: every one carries the stable `starter_key` the
        // starter seeder writes, which is also the column #1013 made readable.
        foreach ($templates as $template) {
            self::assertNotNull(
                $template['starter_key'],
                'Every template a bare seed leaves behind must be a shipped starter.'
            );
        }
    }

    /** The header/footer blocks are half of "never an empty document", and arrive the same way. */
    public function testAFreshSeedLeavesTheDefaultTenantWithItsStarterBlocks(): void
    {
        Seeder::seed($this->db);

        $blocks = (new DocumentBlockRepository($this->pdo))->listForTenant($this->defaultTenantId());

        self::assertNotSame([], $blocks, 'The starter header/footer blocks must reach the Default Tenant too.');
        foreach ($blocks as $block) {
            self::assertNotNull($block['starter_key']);
        }
    }

    /** Re-seeding must not leave the tenant with two of every starter. */
    public function testReSeedingDoesNotDuplicateTheStarters(): void
    {
        Seeder::seed($this->db);
        $first = $this->designerRowCounts();

        Seeder::seed($this->db);
        Seeder::seed($this->db);

        self::assertSame($first, $this->designerRowCounts());
        self::assertGreaterThan(0, $first['document_templates']);
        self::assertGreaterThan(0, $first['document_blocks']);
    }

    /**
     * AN INSTALL THAT NEVER GOT ITS STARTERS ACQUIRES THEM ON THE NEXT SEED.
     *
     * Every install created before this fix is in exactly this state: the tenant
     * is there, the library is empty, and nothing will ever create it again if
     * provisioning only runs at creation. So the provisioner runs its steps
     * against a tenant that already exists as well as one it just made, and this
     * is the assertion that keeps that true — an operator's remedy for the bug is
     * `seed`, not a rebuild.
     */
    public function testAnExistingTenantWithNoStartersIsBackfilledByTheNextSeed(): void
    {
        Seeder::seed($this->db);
        $tenantId = $this->defaultTenantId();

        $this->pdo->prepare('DELETE FROM document_templates WHERE tenant_id = :t')->execute([':t' => $tenantId]);
        $this->pdo->prepare('DELETE FROM document_blocks WHERE tenant_id = :t')->execute([':t' => $tenantId]);
        self::assertSame(
            ['document_templates' => 0, 'document_blocks' => 0],
            $this->designerRowCounts(),
            'Precondition: the tenant is standing where a pre-fix install stands.'
        );

        Seeder::seed($this->db);

        $counts = $this->designerRowCounts();
        self::assertGreaterThan(0, $counts['document_templates']);
        self::assertGreaterThan(0, $counts['document_blocks']);
    }

    private function defaultTenantId(): int
    {
        $row = $this->db
            ->query('SELECT id FROM tenants WHERE name = :name', [':name' => Seeder::DEFAULT_TENANT_NAME])
            ->fetch();

        self::assertIsArray($row, 'The seeder must leave a "' . Seeder::DEFAULT_TENANT_NAME . '" behind.');

        return (int) $row['id'];
    }

    /** @return array{document_templates: int, document_blocks: int} */
    private function designerRowCounts(): array
    {
        $tenantId = $this->defaultTenantId();

        $templates = $this->pdo->prepare('SELECT count(*) FROM document_templates WHERE tenant_id = :t');
        $templates->execute([':t' => $tenantId]);
        $blocks = $this->pdo->prepare('SELECT count(*) FROM document_blocks WHERE tenant_id = :t');
        $blocks->execute([':t' => $tenantId]);

        return [
            'document_templates' => (int) $templates->fetchColumn(),
            'document_blocks'    => (int) $blocks->fetchColumn(),
        ];
    }

    /**
     * Fetch profile data for an email via profile_emails JOIN profiles JOIN memberships.
     *
     * @return array<string, mixed>|false
     */
    private function fetchProfile(string $email): array|false
    {
        return $this->db
            ->query(
                'SELECT m.tenant_id, m.role_id
                 FROM profile_emails pe
                 JOIN memberships m ON m.profile_id = pe.profile_id
                 WHERE pe.email = :email
                 ORDER BY m.tenant_id ASC
                 LIMIT 1',
                [':email' => $email]
            )
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
