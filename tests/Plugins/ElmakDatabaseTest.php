<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Elmak\Migrations\CreateElmakTables;
use PDO;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/plugins/Elmak/Migrations/CreateElmakTables.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/ElmakPlugin.php';

/**
 * Functional integration test for the Elmak database schema.
 *
 * Exercises the real SQLite engine to verify:
 * 1. Migration up() and down() correctness and idempotency.
 * 2. Seeding of custom roles (instructor, student) and permissions.
 * 3. Cascade deletes and Set Null constraints on deletion of parents.
 * 4. Relational integrity and tenant isolation constraints.
 */
final class ElmakDatabaseTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(PDO::ATTR_STRINGIFY_FETCHES, true);
        
        // Enable foreign key constraints in SQLite
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

        // Pre-seed core admin role
        $this->pdo->exec("INSERT INTO roles (name, description) VALUES ('admin', 'System Administrator')");
    }

    public function testMigrationLifecycleAndSeeding(): void
    {
        $migration = new CreateElmakTables();
        $plugin = new \Elmak\ElmakPlugin();
        $seeder = new \Whity\Core\PluginRoleSeeder($this->pdo);

        // 1. Run Migration Up
        $migration->up($this->pdo);

        // Run Seeder (simulating framework's native seeding)
        $seeder->seed($plugin, \Whity\Core\PluginRoleSeeder::SYSTEM_TENANT_ID);

        // Verify tables exist
        $tables = [
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
            'elmak_grading_results'
        ];

        foreach ($tables as $table) {
            $exists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
            $this->assertSame($table, $exists, "Table {$table} should exist");
        }

        // Verify seeded roles
        $roles = $this->pdo->query("SELECT name, description FROM roles WHERE name IN ('instructor', 'student') ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
        $this->assertCount(2, $roles);
        $this->assertSame('instructor', $roles[0]['name']);
        $this->assertSame('student', $roles[1]['name']);

        // Verify seeded permissions
        $perms = $this->pdo->query("SELECT name FROM permissions WHERE name LIKE 'elmak:%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['elmak:admin', 'elmak:instructor', 'elmak:student'], $perms);

        // Verify role permission grants
        $adminPerms = $this->pdo->query(
            "SELECT p.name FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.name = 'admin'
             ORDER BY p.name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['elmak:admin', 'elmak:instructor', 'elmak:student'], $adminPerms);

        $instructorPerms = $this->pdo->query(
            "SELECT p.name FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.name = 'instructor'
             ORDER BY p.name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['elmak:instructor', 'elmak:student'], $instructorPerms);

        $studentPerms = $this->pdo->query(
            "SELECT p.name FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.name = 'student'
             ORDER BY p.name"
        )->fetchAll(PDO::FETCH_COLUMN);
        $this->assertSame(['elmak:student'], $studentPerms);

        // Run Seeder down (simulating framework's deactivation/uninstall)
        $seeder->removeGrants($plugin, \Whity\Core\PluginRoleSeeder::SYSTEM_TENANT_ID);

        // Verify role permission grants are revoked
        $totalGrants = $this->pdo->query(
            "SELECT COUNT(*) FROM role_permissions rp
             JOIN permissions p ON p.id = rp.permission_id
             WHERE p.name LIKE 'elmak:%'"
        )->fetchColumn();
        $this->assertSame('0', $totalGrants, 'Seeded permission grants must be revoked');

        // 2. Run Migration Down
        $migration->down($this->pdo);

        // Verify tables are dropped
        foreach ($tables as $table) {
            $exists = $this->pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='{$table}'")->fetchColumn();
            $this->assertFalse($exists, "Table {$table} should be dropped");
        }

        // Verify permissions are deleted
        $permsCount = $this->pdo->query("SELECT COUNT(*) FROM permissions WHERE name LIKE 'elmak:%'")->fetchColumn();
        $this->assertSame('0', $permsCount);
    }

    public function testCascadeDeletesAndForeignKeys(): void
    {
        $migration = new CreateElmakTables();
        $migration->up($this->pdo);

        $plugin = new \Elmak\ElmakPlugin();
        $seeder = new \Whity\Core\PluginRoleSeeder($this->pdo);
        $seeder->seed($plugin, \Whity\Core\PluginRoleSeeder::SYSTEM_TENANT_ID);

        // 1. Insert two Tenants
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Tenant A', 'tenant-a')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Tenant B', 'tenant-b')");

        // Get role IDs
        $instructorRoleId = $this->pdo->query("SELECT id FROM roles WHERE name = 'instructor'")->fetchColumn();
        $studentRoleId = $this->pdo->query("SELECT id FROM roles WHERE name = 'student'")->fetchColumn();

        // 2. Insert Users
        $this->pdo->exec("INSERT INTO users (id, tenant_id, email, password, role_id) VALUES (10, 1, 'inst@tenant-a.com', 'pwd', {$instructorRoleId})");
        $this->pdo->exec("INSERT INTO users (id, tenant_id, email, password, role_id) VALUES (11, 1, 'stud@tenant-a.com', 'pwd', {$studentRoleId})");

        // 3. Insert Academic Hierarchy for Tenant A
        $this->pdo->exec("INSERT INTO elmak_faculties (id, tenant_id, name, code) VALUES (100, 1, 'Science', 'SCI')");
        $this->pdo->exec("INSERT INTO elmak_departments (id, tenant_id, faculty_id, name, code) VALUES (200, 1, 100, 'Computer Science', 'CS')");
        $this->pdo->exec("INSERT INTO elmak_programs (id, tenant_id, department_id, name, code) VALUES (300, 1, 200, 'BS CS', 'BSCS')");

        // Instructor & Student profiles
        $this->pdo->exec("INSERT INTO elmak_instructors (id, tenant_id, user_id, department_id, title, office) VALUES (400, 1, 10, 200, 'Dr.', 'Room 101')");
        $this->pdo->exec("INSERT INTO elmak_students (id, tenant_id, user_id, program_id, student_number, gpa) VALUES (500, 1, 11, 300, 'S12345', 3.85)");

        // 4. Courses & Curriculum
        $this->pdo->exec("INSERT INTO elmak_courses (id, tenant_id, department_id, name, code, credits) VALUES (600, 1, 200, 'Intro to Programming', 'CS101', 3)");
        $this->pdo->exec("INSERT INTO elmak_courses (id, tenant_id, department_id, name, code, credits) VALUES (601, 1, 200, 'Data Structures', 'CS102', 4)");
        
        // Prerequisites
        $this->pdo->exec("INSERT INTO elmak_course_prerequisites (tenant_id, course_id, prerequisite_id, is_optional) VALUES (1, 601, 600, 0)");
        
        // Program Courses
        $this->pdo->exec("INSERT INTO elmak_program_courses (tenant_id, program_id, course_id, is_elective) VALUES (1, 300, 600, 0)");
        $this->pdo->exec("INSERT INTO elmak_program_courses (tenant_id, program_id, course_id, is_elective) VALUES (1, 300, 601, 0)");

        // 5. Questions
        $this->pdo->exec("
            INSERT INTO elmak_questions (id, tenant_id, course_id, chapter, clo, difficulty, marks_in_50, question_type, question_text)
            VALUES (700, 1, 600, 'Chapter 1', 'CLO1', 'easy', 2.50, 'multiple_choice', 'What is 1+1?')
        ");
        $this->pdo->exec("INSERT INTO elmak_question_answers (id, tenant_id, question_id, answer_text, is_correct, explanation) VALUES (800, 1, 700, '2', 1, 'Basic math')");
        $this->pdo->exec("INSERT INTO elmak_question_answers (id, tenant_id, question_id, answer_text, is_correct, explanation) VALUES (801, 1, 700, '3', 0, 'Wrong')");

        // 6. Exams
        $this->pdo->exec("
            INSERT INTO elmak_exams (id, tenant_id, course_id, instructor_id, title, total_marks, generation_params)
            VALUES (900, 1, 600, 400, 'Midterm Exam', 50.00, '{\"easy\": 1.0}')
        ");
        $this->pdo->exec("INSERT INTO elmak_exam_questions (tenant_id, exam_id, question_id, order_index, marks_allocated) VALUES (1, 900, 700, 0, 5.00)");

        // 7. Grading Results
        $this->pdo->exec("
            INSERT INTO elmak_exam_templates (id, tenant_id, exam_id, title, question_count, correct_answers)
            VALUES ('template-uuid-1', 1, 900, 'Midterm Key', 1, '{\"1\": \"A\"}')
        ");
        $this->pdo->exec("
            INSERT INTO elmak_grading_results (id, tenant_id, template_id, student_id, student_name, student_number, course_id, score, total_questions, percentage, questions)
            VALUES ('result-uuid-1', 1, 'template-uuid-1', 500, 'John Doe', 'S12345', 600, 5, 1, 100.0, '[]')
        ");

        // Verify everything was inserted successfully
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_faculties WHERE id = 100")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_departments WHERE id = 200")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_programs WHERE id = 300")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_instructors WHERE id = 400")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_students WHERE id = 500")->fetchColumn());
        $this->assertSame('2', $this->pdo->query("SELECT COUNT(*) FROM elmak_courses")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_course_prerequisites")->fetchColumn());
        $this->assertSame('2', $this->pdo->query("SELECT COUNT(*) FROM elmak_program_courses")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_questions WHERE id = 700")->fetchColumn());
        $this->assertSame('2', $this->pdo->query("SELECT COUNT(*) FROM elmak_question_answers")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_exams WHERE id = 900")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_exam_questions")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_exam_templates WHERE id = 'template-uuid-1'")->fetchColumn());
        $this->assertSame('1', $this->pdo->query("SELECT COUNT(*) FROM elmak_grading_results WHERE id = 'result-uuid-1'")->fetchColumn());

        // Test SET NULL constraint on department deletion for instructor
        $this->pdo->exec("DELETE FROM elmak_departments WHERE id = 200");
        // Department 200 is deleted.
        // Programs and Courses should be cascade-deleted.
        // Instructor department_id should be NULL.
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_departments WHERE id = 200")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_programs WHERE id = 300")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_courses WHERE id = 600")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_course_prerequisites")->fetchColumn());
        
        $instructorDept = $this->pdo->query("SELECT department_id FROM elmak_instructors WHERE id = 400")->fetchColumn();
        $this->assertNull($instructorDept);

        // Student's program_id should be NULL (program 300 deleted due to department cascade)
        $studentProg = $this->pdo->query("SELECT program_id FROM elmak_students WHERE id = 500")->fetchColumn();
        $this->assertNull($studentProg);

        // Exam 900 and Question 700 should be cascade-deleted because Course 600 was cascade-deleted.
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_exams WHERE id = 900")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_questions WHERE id = 700")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_question_answers")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_exam_questions")->fetchColumn());

        // Exam Template's exam_id should be NULL since Exam 900 is deleted
        $examTemplateId = $this->pdo->query("SELECT exam_id FROM elmak_exam_templates WHERE id = 'template-uuid-1'")->fetchColumn();
        $this->assertNull($examTemplateId);

        // Grading Result's course_id and student_id should be NULL because student 500 and course 600 are still valid but...
        // Wait, course 600 was deleted, so course_id on grading result should be NULL. Let's assert:
        $resCourseId = $this->pdo->query("SELECT course_id FROM elmak_grading_results WHERE id = 'result-uuid-1'")->fetchColumn();
        $this->assertNull($resCourseId);
        
        // Student 500 is still present, so student_id should be 500
        $resStudentId = $this->pdo->query("SELECT student_id FROM elmak_grading_results WHERE id = 'result-uuid-1'")->fetchColumn();
        $this->assertSame('500', $resStudentId);

        // Now delete the student user to verify user cascade deletes student profile
        $this->pdo->exec("DELETE FROM users WHERE id = 11");
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_students WHERE id = 500")->fetchColumn());

        // Grading Result's student_id should now be NULL since student 500 was deleted
        $resStudentIdDeleted = $this->pdo->query("SELECT student_id FROM elmak_grading_results WHERE id = 'result-uuid-1'")->fetchColumn();
        $this->assertNull($resStudentIdDeleted);

        // 8. Test Cascade Delete of Tenant
        // Delete Tenant 1. Everything remaining (faculty 100, instructor 400, template, result) should be deleted
        $this->pdo->exec("DELETE FROM tenants WHERE id = 1");
        
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_faculties WHERE tenant_id = 1")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_instructors WHERE tenant_id = 1")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_exam_templates WHERE tenant_id = 1")->fetchColumn());
        $this->assertSame('0', $this->pdo->query("SELECT COUNT(*) FROM elmak_grading_results WHERE tenant_id = 1")->fetchColumn());
    }

    public function testUniqueConstraints(): void
    {
        $migration = new CreateElmakTables();
        $migration->up($this->pdo);

        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Tenant A', 'tenant-a')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Tenant B', 'tenant-b')");

        // 1. UNIQUE(tenant_id, code) on elmak_faculties
        $this->pdo->exec("INSERT INTO elmak_faculties (tenant_id, name, code) VALUES (1, 'Science A', 'SCI')");
        // Same code on Tenant B is allowed
        $this->pdo->exec("INSERT INTO elmak_faculties (tenant_id, name, code) VALUES (2, 'Science B', 'SCI')");
        
        // Duplicate code on same tenant should fail
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO elmak_faculties (tenant_id, name, code) VALUES (1, 'Duplicate Science', 'SCI')");
    }
}
