<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Elmak\ElmakPlugin;
use Elmak\Migrations\CreateElmakTables;
use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Request;

require_once dirname(__DIR__, 2) . '/plugins/Elmak/ElmakPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/Migrations/CreateElmakTables.php';

/**
 * Functional integration test for the Elmak bootstrap API endpoint.
 */
final class ElmakBootstrapApiTest extends TestCase
{
    private PDO $pdo;
    private ElmakPlugin $plugin;
    /** @var array<string, mixed> Saved service-container state to restore. */
    private array $savedServices = [];

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->savedServices = $GLOBALS['whity_services'] ?? [];

        // Set up in-memory SQLite database
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        // Create core mock tables
        $this->pdo->exec('
            CREATE TABLE tenants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                slug VARCHAR(255) UNIQUE
            )
        ');

        $this->pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                description TEXT DEFAULT \'\',
                parent_id INTEGER NULL REFERENCES roles(id) ON DELETE SET NULL,
                tenant_id INTEGER NULL REFERENCES tenants(id) ON DELETE CASCADE
            )
        ');

        $this->pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $this->pdo->exec('
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(role_id, permission_id)
            )
        ');

        $this->pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                email VARCHAR(255) NOT NULL,
                password VARCHAR(255) NOT NULL,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(tenant_id, email)
            )
        ');

        // Run migrations and seed roles
        $migration = new CreateElmakTables();
        $migration->up($this->pdo);

        // Register Database service in host container
        $db = Database::withFactory(fn (): PDO => $this->pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        \Whity\register_service(Database::class, $db);

        $this->plugin = new ElmakPlugin();

        $seeder = new \Whity\Core\PluginRoleSeeder($this->pdo);
        $seeder->seed($this->plugin, \Whity\Core\PluginRoleSeeder::SYSTEM_TENANT_ID);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $GLOBALS['whity_services'] = $this->savedServices;
    }

    public function testRoutesRegisterCorrectly(): void
    {
        $routes = $this->plugin->getRoutes();
        $bootstrapRoute = null;

        foreach ($routes as $route) {
            if ($route['path'] === '/api/elmak/bootstrap') {
                $bootstrapRoute = $route;
                break;
            }
        }

        $this->assertNotNull($bootstrapRoute, 'Bootstrap route should be registered');
        $this->assertSame('GET', $bootstrapRoute['method']);
        $this->assertSame([$this->plugin, 'bootstrap'], $bootstrapRoute['handler']);
    }

    public function testBootstrapUnresolvedTenantContextFailsClosed(): void
    {
        // No tenant context set
        $request = new Request('GET', '/api/elmak/bootstrap');
        $response = $this->plugin->bootstrap($request);

        $this->assertSame(403, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame('Tenant context is required', $body['error']);
    }

    public function testBootstrapWithoutUserReturnsOnlyConfig(): void
    {
        TenantContext::setTenantId(1);
        $request = new Request('GET', '/api/elmak/bootstrap');

        $response = $this->plugin->bootstrap($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $this->assertArrayHasKey('config', $body['data']);
        $this->assertNull($body['data']['profile']);

        $config = $body['data']['config'];
        $this->assertSame('0.1.0', $config['version']);
        $this->assertEquals(50.00, $config['maxExamMarks']);
        $this->assertSame(['easy', 'medium', 'hard'], $config['difficulties']);
        $this->assertSame(['multiple_choice', 'true_false', 'formula'], $config['questionTypes']);
    }

    public function testBootstrapWithInstructorProfile(): void
    {
        TenantContext::setTenantId(1);
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Tenant A')");

        // Insert instructor role and user
        $roleId = $this->pdo->query("SELECT id FROM roles WHERE name = 'instructor'")->fetchColumn();
        $this->pdo->exec("INSERT INTO users (id, tenant_id, email, password, role_id) VALUES (10, 1, 'inst@tenant-a.com', 'pwd', {$roleId})");

        // Insert department and instructor profile
        $this->pdo->exec("INSERT INTO elmak_faculties (id, tenant_id, name, code) VALUES (100, 1, 'Science', 'SCI')");
        $this->pdo->exec("INSERT INTO elmak_departments (id, tenant_id, faculty_id, name, code) VALUES (200, 1, 100, 'Computer Science', 'CS')");
        $this->pdo->exec("INSERT INTO elmak_instructors (id, tenant_id, user_id, department_id, title, office) VALUES (400, 1, 10, 200, 'Dr.', 'Room 101')");

        // Create request with authenticated user
        $request = new Request('GET', '/api/elmak/bootstrap');
        $request->user = (object) ['user_id' => 10];

        $response = $this->plugin->bootstrap($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $profile = $body['data']['profile'];
        $this->assertNotNull($profile);
        $this->assertSame('instructor', $profile['type']);
        $this->assertSame(400, $profile['id']);
        $this->assertSame('Dr.', $profile['title']);
        $this->assertSame('Room 101', $profile['office']);
        $this->assertSame(200, $profile['department']['id']);
        $this->assertSame('Computer Science', $profile['department']['name']);
        $this->assertSame('CS', $profile['department']['code']);
    }

    public function testBootstrapWithStudentProfile(): void
    {
        TenantContext::setTenantId(1);
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'Tenant A')");

        // Insert student role and user
        $roleId = $this->pdo->query("SELECT id FROM roles WHERE name = 'student'")->fetchColumn();
        $this->pdo->exec("INSERT INTO users (id, tenant_id, email, password, role_id) VALUES (11, 1, 'stud@tenant-a.com', 'pwd', {$roleId})");

        // Insert program and student profile
        $this->pdo->exec("INSERT INTO elmak_faculties (id, tenant_id, name, code) VALUES (100, 1, 'Science', 'SCI')");
        $this->pdo->exec("INSERT INTO elmak_departments (id, tenant_id, faculty_id, name, code) VALUES (200, 1, 100, 'Computer Science', 'CS')");
        $this->pdo->exec("INSERT INTO elmak_programs (id, tenant_id, department_id, name, code) VALUES (300, 1, 200, 'BS CS', 'BSCS')");
        $this->pdo->exec("INSERT INTO elmak_students (id, tenant_id, user_id, program_id, student_number, gpa) VALUES (500, 1, 11, 300, 'S12345', 3.85)");

        // Create request with authenticated user
        $request = new Request('GET', '/api/elmak/bootstrap');
        $request->user = (object) ['user_id' => 11];

        $response = $this->plugin->bootstrap($request);

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        $profile = $body['data']['profile'];
        $this->assertNotNull($profile);
        $this->assertSame('student', $profile['type']);
        $this->assertSame(500, $profile['id']);
        $this->assertSame('S12345', $profile['studentNumber']);
        $this->assertSame(3.85, $profile['gpa']);
        $this->assertSame(300, $profile['program']['id']);
        $this->assertSame('BS CS', $profile['program']['name']);
        $this->assertSame('BSCS', $profile['program']['code']);
    }
}
