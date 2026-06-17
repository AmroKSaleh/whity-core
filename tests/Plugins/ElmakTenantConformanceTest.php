<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Elmak\Migrations\CreateElmakTables;
use Whity\Core\Tenant\CoreTenantTableRegistry;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Tenant\TenantTableRegistry;
use Whity\Sdk\Testing\TenantIsolationConformanceTestCase;

require_once dirname(__DIR__, 2) . '/plugins/Elmak/Migrations/CreateElmakTables.php';

/**
 * Conformance test for the Elmak plugin (WC-194).
 *
 * Verifies that all 14 Elmak tables comply with multi-tenant isolation rules,
 * carry the required tenant_id column, and are linted correctly.
 */
final class ElmakTenantConformanceTest extends TenantIsolationConformanceTestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../plugins/Elmak';

    /**
     * @inheritDoc
     */
    protected function tenantTableRegistry(): TenantTableRegistry
    {
        return TenantTableRegistry::for([
            'elmak_faculties' => 'Elmak faculties',
            'elmak_departments' => 'Elmak departments',
            'elmak_programs' => 'Elmak programs',
            'elmak_instructors' => 'Elmak instructors',
            'elmak_students' => 'Elmak students',
            'elmak_courses' => 'Elmak courses',
            'elmak_course_prerequisites' => 'Elmak course prerequisites',
            'elmak_program_courses' => 'Elmak program courses',
            'elmak_questions' => 'Elmak questions',
            'elmak_question_answers' => 'Elmak question answers',
            'elmak_exams' => 'Elmak exams',
            'elmak_exam_questions' => 'Elmak exam questions',
            'elmak_exam_templates' => 'Elmak exam templates',
            'elmak_grading_results' => 'Elmak grading results',
        ])->merge(CoreTenantTableRegistry::build());
    }

    /**
     * @inheritDoc
     */
    protected function migrationsDirectory(): string
    {
        return self::PLUGIN_DIR . '/Migrations';
    }

    /**
     * @inheritDoc
     */
    protected function handlerSourceDirectories(): array
    {
        // Elmak API handlers
        $apiDir = self::PLUGIN_DIR . '/Api';
        if (!is_dir($apiDir)) {
            mkdir($apiDir, 0755, true);
        }
        return [$apiDir];
    }

    /**
     * @inheritDoc
     */
    protected function schemaMigrations(): array
    {
        return [new CreateElmakTables()];
    }

    /**
     * @inheritDoc
     */
    protected function ownTenantTables(): array
    {
        return [
            'elmak_faculties',
            'elmak_departments',
            'elmak_programs',
            'elmak_instructors',
            'elmak_students',
            'elmak_courses',
            'elmak_course_prerequisites',
            'elmak_program_courses',
            'elmak_questions',
            'elmak_question_answers',
            'elmak_exams',
            'elmak_exam_questions',
            'elmak_exam_templates',
            'elmak_grading_results',
        ];
    }

    /**
     * @inheritDoc
     */
    protected function makePdo(): \PDO
    {
        $pdo = parent::makePdo();

        // Create core tables to satisfy foreign key constraints in SQLite
        $pdo->exec('
            CREATE TABLE tenants (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                slug VARCHAR(255) UNIQUE
            )
        ');

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                description TEXT DEFAULT \'\',
                parent_id INTEGER NULL REFERENCES roles(id) ON DELETE SET NULL,
                tenant_id INTEGER NULL REFERENCES tenants(id) ON DELETE CASCADE
            )
        ');

        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name VARCHAR(255) NOT NULL UNIQUE,
                description TEXT,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
        ');

        $pdo->exec('
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                permission_id INTEGER NOT NULL REFERENCES permissions(id) ON DELETE CASCADE,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE(role_id, permission_id)
            )
        ');

        $pdo->exec('
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

        return $pdo;
    }
}
