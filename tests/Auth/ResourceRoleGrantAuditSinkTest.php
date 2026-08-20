<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\RoleNotVisibleException;

/**
 * Pins WHERE a refused resource-role grant is reported.
 *
 * WHY THIS FILE EXISTS
 * --------------------
 * A cross-tenant grant being refused is an EXPECTED, handled outcome — the
 * repository throws {@see RoleNotVisibleException} and
 * {@see ResourceRoleGrantRealEngineTest} trips that guard on purpose, several
 * times per run. While the refusal was reported with a bare `error_log()`, a
 * fully PASSING test suite therefore wrote to the process's STDERR.
 *
 * That is not merely noisy. Infection's initial test run stops the PHPUnit
 * process on the FIRST byte of STDERR it observes:
 *
 *     // Infection\Process\Runner\InitialTestsRunner::run()
 *     $process->run(function (string $type) use ($process): void {
 *         if ($type === Process::ERR) {
 *             $process->stop();          // SIGTERM -> exit code 143
 *         }
 *         ...
 *
 * so the scheduled mutation-testing job died ~159 tests into a 433-test suite
 * with "Project tests must be in a passing state before running Infection /
 * Infection runs the test suite in a RANDOM order. Make sure your tests do not
 * have hidden dependencies." — a boilerplate message that describes a class of
 * bug this repository did not have, and which sent the investigation after a
 * nonexistent order dependency. Nothing was order-dependent; the suite simply
 * spoke on the wrong file descriptor.
 *
 * The fix is the contract {@see \Whity\Http\Middleware\EnforceTenantIsolation}
 * already uses: an injected PSR-3 sink defaulting to a NullLogger, so the audit
 * record still reaches production (public/index.php passes the application
 * logger) while a test that deliberately trips the guard stays silent.
 *
 * These tests fail if the raw write comes back.
 */
final class ResourceRoleGrantAuditSinkTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const TYPE_DOCUMENT = 'testplugin:document';
    private const DOC_ID = 4242;

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();

        // `roles.tenant_id` is a real foreign key. SQLite does not enforce it and
        // real PostgreSQL does, so seeding the tenants is not optional decoration
        // — without it this file passes the SQLite gate and fails the dialect job.
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");

        // Those rows went in at EXPLICIT ids, which PostgreSQL's sequence does not
        // notice; the next id-less INSERT would be handed 1 again and die on the
        // primary key. SQLite hides it because its counter reads the table.
        // Same reasoning as ResourceRoleGrantRealEngineTest::makeSchema().
        SchemaFromMigrations::syncSequences($this->pdo);
    }

    /**
     * The guard, tripped with no logger wired, must write NOTHING to the
     * process error log — which in the CLI SAPI is STDERR, the descriptor
     * Infection watches.
     */
    public function testRefusedGrantWritesNothingToTheProcessErrorLog(): void
    {
        $sink = tempnam(sys_get_temp_dir(), 'whity-errorlog-');
        self::assertIsString($sink);

        $previous = ini_get('error_log');
        ini_set('error_log', $sink);

        try {
            $foreignRoleId = $this->seedTenantRole('tenant-b-private', self::TENANT_B);

            try {
                $this->repository()->grant(
                    self::TENANT_A,
                    self::TYPE_DOCUMENT,
                    self::DOC_ID,
                    $foreignRoleId
                );
                self::fail('A cross-tenant grant must be refused.');
            } catch (RoleNotVisibleException) {
                // Expected — the refusal itself is asserted by
                // ResourceRoleGrantRealEngineTest; here only its REPORTING matters.
            }

            self::assertSame(
                '',
                (string) file_get_contents($sink),
                'A refused grant must not write to the process error log: in CLI that is '
                . 'STDERR, and Infection SIGTERMs its initial test run on the first byte '
                . 'of STDERR. Inject a PSR-3 logger instead (see the class docblock).'
            );
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($sink);
        }
    }

    /**
     * The counterpart: silence must come from the sink being unwired, NOT from
     * the audit record having been deleted. With a logger injected — which is
     * what production does — the refusal is still recorded, with the tenant and
     * role that were involved.
     */
    public function testRefusedGrantIsStillAuditedWhenALoggerIsInjected(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            /**
             * @param mixed                $level
             * @param string|Stringable    $message
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $foreignRoleId = $this->seedTenantRole('tenant-b-private', self::TENANT_B);

        try {
            $this->repository($logger)->grant(
                self::TENANT_A,
                self::TYPE_DOCUMENT,
                self::DOC_ID,
                $foreignRoleId
            );
            self::fail('A cross-tenant grant must be refused.');
        } catch (RoleNotVisibleException) {
            // Expected.
        }

        self::assertCount(1, $logger->records, 'The refusal must be audited exactly once.');
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame(
            ['tenant_id' => self::TENANT_A, 'role_id' => $foreignRoleId],
            $logger->records[0]['context'],
            'The audit record must carry the tenant and role involved.'
        );
    }

    /**
     * A successful grant is not an audit event at all — no record, no output.
     */
    public function testPermittedGrantIsNotAudited(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{level:mixed,message:string,context:array<string,mixed>}> */
            public array $records = [];

            /**
             * @param mixed                $level
             * @param string|Stringable    $message
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };

        $ownRoleId = $this->seedTenantRole('tenant-a-own', self::TENANT_A);

        $this->repository($logger)->grant(
            self::TENANT_A,
            self::TYPE_DOCUMENT,
            self::DOC_ID,
            $ownRoleId
        );

        self::assertSame([], $logger->records, 'A permitted grant must emit no audit record.');
    }

    /**
     * A source-level backstop for the two behavioural tests above.
     *
     * They cover the guard that is known to fire during a green suite; this
     * catches a raw `error_log()` reintroduced ANYWHERE in the repository —
     * including a path no test happens to walk today, which is exactly how the
     * original write survived unnoticed for eleven days of skipped and failing
     * scheduled runs.
     */
    public function testRepositoryReportsThroughTheInjectedSinkOnly(): void
    {
        $source = file_get_contents(
            dirname(__DIR__, 2) . '/src/Core/RBAC/ResourceRoleAssignmentRepository.php'
        );
        self::assertIsString($source);

        self::assertDoesNotMatchRegularExpression(
            '/(?<![\w$>])error_log\s*\(/',
            $source,
            'ResourceRoleAssignmentRepository must report through its injected PSR-3 logger, '
            . 'never the process error log: in the CLI SAPI error_log() writes to STDERR, and '
            . 'Infection SIGTERMs its initial test run on the first byte of STDERR — turning a '
            . 'green suite into "tests must be in a passing state / hidden dependencies".'
        );
    }

    // ==================== Helpers ====================

    private function repository(?AbstractLogger $logger = null): ResourceRoleAssignmentRepository
    {
        return new ResourceRoleAssignmentRepository($this->pdo, $this->resourceTypes(), $logger);
    }

    /**
     * Mirrors {@see ResourceRoleGrantRealEngineTest::resourceTypes()}: the plugin
     * declares the BARE slug and the registry namespaces it, so the canonical
     * type is `testplugin:document`.
     */
    private function resourceTypes(): ResourceTypeRegistry
    {
        $types = new ResourceTypeRegistry();
        $types->register('TestPlugin', ['document']);

        return $types;
    }

    private function seedTenantRole(string $name, int $tenantId): int
    {
        $this->pdo->prepare('INSERT INTO roles (name, tenant_id, created_at) VALUES (?, ?, NOW())')
            ->execute([$name, $tenantId]);

        return (int) $this->pdo->lastInsertId();
    }
}
