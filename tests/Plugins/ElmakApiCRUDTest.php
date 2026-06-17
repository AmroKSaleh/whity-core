<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Elmak\ElmakPlugin;
use Elmak\Migrations\CreateElmakTables;
use Elmak\Api\FormulaEvaluator;
use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

require_once dirname(__DIR__, 2) . '/plugins/Elmak/ElmakPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/Migrations/CreateElmakTables.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/Api/FormulaEvaluator.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/Api/CurriculumApiHandler.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/Api/UserProfilesApiHandler.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/Api/QuestionBankApiHandler.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/Api/ExamApiHandler.php';
require_once dirname(__DIR__, 2) . '/plugins/Elmak/Api/GradingApiHandler.php';

final class ElmakApiCRUDTest extends TestCase
{
    private PDO $pdo;
    private ElmakPlugin $plugin;
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

        // Run migrations
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

        // Seed 2 Tenants
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'Tenant A', 'tenant-a')");
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'Tenant B', 'tenant-b')");

        // Seed Roles
        $instructorRoleId = $this->pdo->query("SELECT id FROM roles WHERE name = 'instructor'")->fetchColumn();
        $studentRoleId = $this->pdo->query("SELECT id FROM roles WHERE name = 'student'")->fetchColumn();

        // Seed Users
        $this->pdo->exec("INSERT INTO users (id, tenant_id, email, password, role_id) VALUES (10, 1, 'inst@tenant-a.com', 'pwd', {$instructorRoleId})");
        $this->pdo->exec("INSERT INTO users (id, tenant_id, email, password, role_id) VALUES (11, 1, 'stud@tenant-a.com', 'pwd', {$studentRoleId})");
        $this->pdo->exec("INSERT INTO users (id, tenant_id, email, password, role_id) VALUES (20, 2, 'inst@tenant-b.com', 'pwd', {$instructorRoleId})");
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        $GLOBALS['whity_services'] = $this->savedServices;
    }

    // ------------------------------------------------------------------
    // Curriculum CRUD Tests
    // ------------------------------------------------------------------

    public function testCurriculumCRUDAndTenantIsolation(): void
    {
        TenantContext::setTenantId(1);

        // 1. Create Faculty
        $req = new Request('POST', '/api/elmak/faculties', [], json_encode(['name' => 'Engineering', 'code' => 'ENG']));
        $resp = $this->plugin->createFaculty($req);
        $this->assertSame(201, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $facultyId = $body['data']['id'];
        $this->assertSame('Engineering', $body['data']['name']);
        $this->assertSame('ENG', $body['data']['code']);

        // 2. Create Department
        $req = new Request('POST', '/api/elmak/departments', [], json_encode(['facultyId' => $facultyId, 'name' => 'Computer Engineering', 'code' => 'CPE']));
        $resp = $this->plugin->createDepartment($req);
        $this->assertSame(201, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $deptId = $body['data']['id'];

        // 3. Create Program
        $req = new Request('POST', '/api/elmak/programs', [], json_encode(['departmentId' => $deptId, 'name' => 'BS CPE', 'code' => 'BSCPE']));
        $resp = $this->plugin->createProgram($req);
        $this->assertSame(201, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $programId = $body['data']['id'];

        // 4. Create Courses
        $req = new Request('POST', '/api/elmak/courses', [], json_encode(['departmentId' => $deptId, 'name' => 'Intro to Programming', 'code' => 'CPE101', 'credits' => 3]));
        $resp = $this->plugin->createCourse($req);
        $this->assertSame(201, $resp->getStatusCode());
        $c1Id = json_decode($resp->getBody(), true)['data']['id'];

        $req = new Request('POST', '/api/elmak/courses', [], json_encode(['departmentId' => $deptId, 'name' => 'Object Oriented Programming', 'code' => 'CPE102', 'credits' => 4]));
        $resp = $this->plugin->createCourse($req);
        $c2Id = json_decode($resp->getBody(), true)['data']['id'];

        // 5. Add Prerequisite
        $req = new Request('POST', "/api/elmak/courses/{$c2Id}/prerequisites", [], json_encode(['prerequisiteId' => $c1Id, 'isOptional' => false]));
        $resp = $this->plugin->addPrerequisite($req, ['id' => (string)$c2Id]);
        $this->assertSame(200, $resp->getStatusCode());

        // 6. Add Program Course
        $req = new Request('POST', "/api/elmak/programs/{$programId}/courses", [], json_encode(['courseId' => $c1Id, 'isElective' => false]));
        $resp = $this->plugin->addProgramCourse($req, ['program_id' => (string)$programId]);
        $this->assertSame(200, $resp->getStatusCode());

        // 7. Tenant Isolation Tests (Try to access/mutate Tenant 1 from Tenant 2 context)
        TenantContext::reset();
        TenantContext::setTenantId(2);

        // List faculties (should return empty list for Tenant 2)
        $req = new Request('GET', '/api/elmak/faculties');
        $resp = $this->plugin->listFaculties($req);
        $this->assertSame(200, $resp->getStatusCode());
        $this->assertEmpty(json_decode($resp->getBody(), true)['data']);

        // Try to update Tenant 1 faculty (should return 404)
        $req = new Request('PATCH', "/api/elmak/faculties/{$facultyId}", [], json_encode(['name' => 'Intruder Eng', 'code' => 'ENG']));
        $resp = $this->plugin->updateFaculty($req, ['id' => (string)$facultyId]);
        $this->assertSame(404, $resp->getStatusCode());

        // Try to delete Tenant 1 course (should return 404)
        $req = new Request('DELETE', "/api/elmak/courses/{$c1Id}");
        $resp = $this->plugin->deleteCourse($req, ['id' => (string)$c1Id]);
        $this->assertSame(404, $resp->getStatusCode());
    }

    // ------------------------------------------------------------------
    // User Profiles CRUD Tests
    // ------------------------------------------------------------------

    public function testProfilesCRUD(): void
    {
        TenantContext::setTenantId(1);

        // Pre-create hierarchy
        $this->pdo->exec("INSERT INTO elmak_faculties (id, tenant_id, name, code) VALUES (100, 1, 'Science', 'SCI')");
        $this->pdo->exec("INSERT INTO elmak_departments (id, tenant_id, faculty_id, name, code) VALUES (200, 1, 100, 'CS', 'CS')");
        $this->pdo->exec("INSERT INTO elmak_programs (id, tenant_id, department_id, name, code) VALUES (300, 1, 200, 'BS CS', 'BSCS')");

        // 1. Create Instructor
        $req = new Request('POST', '/api/elmak/instructors', [], json_encode([
            'userId' => 10,
            'departmentId' => 200,
            'title' => 'Professor',
            'office' => 'Room 205'
        ]));
        $resp = $this->plugin->createInstructor($req);
        $this->assertSame(201, $resp->getStatusCode());
        $body = json_decode($resp->getBody(), true);
        $instId = $body['data']['id'];
        $this->assertSame('Professor', $body['data']['title']);
        $this->assertSame('inst@tenant-a.com', $body['data']['userEmail']);

        // 2. Create Student
        $req = new Request('POST', '/api/elmak/students', [], json_encode([
            'userId' => 11,
            'programId' => 300,
            'studentNumber' => 'S-998877',
            'gpa' => 3.92
        ]));
        $resp = $this->plugin->createStudent($req);
        $this->assertSame(201, $resp->getStatusCode());
        $studId = json_decode($resp->getBody(), true)['data']['id'];

        // 3. Try creating profile with cross-tenant user ID (User 20 is Tenant 2, active tenant is 1)
        $req = new Request('POST', '/api/elmak/instructors', [], json_encode(['userId' => 20]));
        $resp = $this->plugin->createInstructor($req);
        $this->assertSame(400, $resp->getStatusCode());
        $this->assertStringContainsString('belongs to another tenant', json_decode($resp->getBody(), true)['error']);
    }

    // ------------------------------------------------------------------
    // FormulaEvaluator Unit Tests
    // ------------------------------------------------------------------

    public function testFormulaEvaluator(): void
    {
        // Simple operations
        $this->assertEquals(10.0, FormulaEvaluator::evaluate('3 + 7'));
        $this->assertEquals(23.0, FormulaEvaluator::evaluate('3 + 4 * 5'));
        $this->assertEquals(16.0, FormulaEvaluator::evaluate('2 ^ 4'));

        // Parentheses
        $this->assertEquals(35.0, FormulaEvaluator::evaluate('(3 + 4) * 5'));

        // Variable substitution
        $this->assertEquals(12.0, FormulaEvaluator::evaluate('x * y + z', ['x' => 2, 'y' => 5, 'z' => 2]));

        // Functions
        $this->assertEquals(6.0, FormulaEvaluator::evaluate('sqrt(36)'));
        $this->assertEquals(5.0, FormulaEvaluator::evaluate('abs(-5)'));
        $this->assertEquals(15.0, FormulaEvaluator::evaluate('max(10, 15)'));
        $this->assertEquals(10.0, FormulaEvaluator::evaluate('min(10, 15)'));
        $this->assertEquals(8.0, FormulaEvaluator::evaluate('pow(2, 3)'));

        // Unary minus
        $this->assertEquals(-5.0, FormulaEvaluator::evaluate('-x', ['x' => 5]));
        $this->assertEquals(-10.0, FormulaEvaluator::evaluate('-abs(-10)'));

        // Complex
        $this->assertEquals(3.5, FormulaEvaluator::evaluate('3 + 4 * 2 / (1 - 5) ^ 2'));

        // Division by zero throws exception
        $this->expectException(\DivisionByZeroError::class);
        FormulaEvaluator::evaluate('10 / 0');
    }

    // ------------------------------------------------------------------
    // Question Bank & Exam Generation Tests
    // ------------------------------------------------------------------

    public function testExamGenerationAndScaling(): void
    {
        TenantContext::setTenantId(1);

        // Setup instructor profile
        $this->pdo->exec("INSERT INTO elmak_faculties (id, tenant_id, name, code) VALUES (100, 1, 'Science', 'SCI')");
        $this->pdo->exec("INSERT INTO elmak_departments (id, tenant_id, faculty_id, name, code) VALUES (200, 1, 100, 'CS', 'CS')");
        $this->pdo->exec("INSERT INTO elmak_instructors (id, tenant_id, user_id, department_id, title, office) VALUES (400, 1, 10, 200, 'Dr.', 'Office 1')");
        $this->pdo->exec("INSERT INTO elmak_courses (id, tenant_id, department_id, name, code, credits) VALUES (600, 1, 200, 'Intro to Programming', 'CS101', 3)");

        // Add 5 Questions to Bank (with various difficulties and base marks)
        // Question 1: Multiple choice
        $req = new Request('POST', '/api/elmak/questions', [], json_encode([
            'courseId' => 600,
            'chapter' => 'Chapter 1',
            'clo' => 'CLO1',
            'difficulty' => 'easy',
            'marksIn50' => 5.0,
            'questionType' => 'multiple_choice',
            'questionText' => 'What is the value of 5 + 5?',
            'answers' => [
                ['answerText' => '10', 'isCorrect' => true, 'explanation' => '5+5=10'],
                ['answerText' => '15', 'isCorrect' => false, 'explanation' => 'wrong']
            ]
        ]));
        $resp = $this->plugin->createQuestion($req);
        $this->assertSame(201, $resp->getStatusCode());

        // Question 2: True/False
        $req = new Request('POST', '/api/elmak/questions', [], json_encode([
            'courseId' => 600,
            'chapter' => 'Chapter 1',
            'clo' => 'CLO1',
            'difficulty' => 'medium',
            'marksIn50' => 10.0,
            'questionType' => 'true_false',
            'questionText' => 'Is PHP an interpreted language?',
            'answers' => [
                ['answerText' => 'True', 'isCorrect' => true],
                ['answerText' => 'False', 'isCorrect' => false]
            ]
        ]));
        $this->plugin->createQuestion($req);

        // Question 3: Formula Question
        $req = new Request('POST', '/api/elmak/questions', [], json_encode([
            'courseId' => 600,
            'chapter' => 'Chapter 2',
            'clo' => 'CLO2',
            'difficulty' => 'hard',
            'marksIn50' => 15.0,
            'questionType' => 'formula',
            'questionText' => 'Evaluate a * b + c where a={a}, b={b}, c={c}.',
            'formulaDefinition' => [
                'variables' => [
                    'a' => ['min' => 2, 'max' => 5, 'step' => 1],
                    'b' => ['min' => 4, 'max' => 10, 'step' => 2],
                    'c' => ['min' => 1, 'max' => 3, 'step' => 1]
                ]
            ],
            'answers' => [
                ['answerText' => 'a * b + c', 'isCorrect' => true],
                ['answerText' => 'a * b', 'isCorrect' => false]
            ]
        ]));
        $resp = $this->plugin->createQuestion($req);
        $this->assertSame(201, $resp->getStatusCode());

        // Question 4: Additional Easy
        $req = new Request('POST', '/api/elmak/questions', [], json_encode([
            'courseId' => 600,
            'difficulty' => 'easy',
            'marksIn50' => 5.0,
            'questionType' => 'true_false',
            'questionText' => 'Is 1 + 1 = 2?',
            'answers' => [
                ['answerText' => 'True', 'isCorrect' => true],
                ['answerText' => 'False', 'isCorrect' => false]
            ]
        ]));
        $this->plugin->createQuestion($req);

        // ------------------------------------------------------------------
        // Generate Exam (Gating on Instructor user 10)
        // ------------------------------------------------------------------
        $req = new Request('POST', '/api/elmak/exams/generate', [], json_encode([
            'courseId' => 600,
            'title' => 'Final Exam CPE',
            'totalMarks' => 100.0, // Scale up from base marks in 50
            'params' => [
                'count' => 3,
                'difficultyRatio' => ['easy' => 0.33, 'medium' => 0.33, 'hard' => 0.34]
            ]
        ]));
        $req->user = (object) ['user_id' => 10]; // Instructor User

        $resp = $this->plugin->generateExam($req);
        $this->assertSame(200, $resp->getStatusCode());

        $examData = json_decode($resp->getBody(), true)['data'];
        $this->assertSame('Final Exam CPE', $examData['title']);
        $this->assertEquals(100.0, $examData['totalMarks']);
        $this->assertCount(3, $examData['questions']);

        // Assert scaling matches total marks
        $allocatedSum = 0.0;
        foreach ($examData['questions'] as $eq) {
            $allocatedSum += $eq['marksAllocated'];
        }
        $this->assertEquals(100.0, $allocatedSum);

        // Assert formula question is instantiated properly
        $formulaQuestion = null;
        foreach ($examData['questions'] as $eq) {
            if ($eq['questionType'] === 'formula') {
                $formulaQuestion = $eq;
            }
        }
        $this->assertNotNull($formulaQuestion);
        $this->assertNotNull($formulaQuestion['formulaValues']);
        
        // Question text should contain evaluated numbers instead of templates
        $this->assertStringNotContainsString('{a}', $formulaQuestion['questionText']);
        $this->assertStringNotContainsString('{b}', $formulaQuestion['questionText']);
        $this->assertStringNotContainsString('{c}', $formulaQuestion['questionText']);

        // Answers should be numerical values evaluated correctly
        foreach ($formulaQuestion['answers'] as $ans) {
            $this->assertTrue(is_numeric($ans['answerText']), "Answer must be evaluated numeric string, got: " . $ans['answerText']);
        }
    }

    // ------------------------------------------------------------------
    // Grading Results CRUD Tests
    // ------------------------------------------------------------------

    public function testGradingResultsCRUD(): void
    {
        TenantContext::setTenantId(1);

        // Pre-seed template
        $this->pdo->exec("
            INSERT INTO elmak_exam_templates (id, tenant_id, exam_id, title, question_count, correct_answers)
            VALUES ('temp-uuid-123', 1, null, 'CPE101 Key', 5, '{\"1\":\"A\",\"2\":\"B\"}')
        ");

        // 1. Submit Grading Result (image is mock base64)
        $req = new Request('POST', '/api/elmak/grading-results', [], json_encode([
            'id' => 'result-uuid-456',
            'templateId' => 'temp-uuid-123',
            'studentId' => null,
            'studentName' => 'Alice Smith',
            'studentNumber' => 'S112233',
            'courseId' => null,
            'score' => 4,
            'totalQuestions' => 5,
            'percentage' => 80.0,
            'image' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=', // mock PNG
            'questions' => [['questionNumber' => 1, 'selectedOption' => 'A', 'isCorrect' => true]]
        ]));
        $resp = $this->plugin->createGradingResult($req);
        $this->assertSame(201, $resp->getStatusCode());

        $body = json_decode($resp->getBody(), true);
        $this->assertSame('Alice Smith', $body['data']['studentName']);
        // Check that path is stored (fallback local path)
        $this->assertNotNull($body['data']['imagePath']);
        $this->assertStringContainsString('/api/elmak/assets/uploads/', $body['data']['imagePath']);

        // 2. Fetch Grading Results
        $req = new Request('GET', '/api/elmak/grading-results');
        $resp = $this->plugin->listGradingResults($req);
        $this->assertSame(200, $resp->getStatusCode());
        $results = json_decode($resp->getBody(), true)['data'];
        $this->assertCount(1, $results);
    }
}

