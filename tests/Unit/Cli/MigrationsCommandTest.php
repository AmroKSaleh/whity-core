<?php

declare(strict_types=1);

namespace Tests\Unit\Cli;

use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\MigrationsCommand;

/**
 * Unit tests for {@see MigrationsCommand::isMissingMigrationsTable()} — the guard
 * that decides which tracking-table INSERT/DELETE failures are safe to swallow.
 *
 * Regression: the previous guard swallowed ANY error whose message merely
 * mentioned `core_schema_migrations`, so a genuine permission/lock/serialization
 * failure was recorded as success and the migration re-ran. Only a genuine
 * "table does not exist" (bootstrap) may be skipped.
 */
final class MigrationsCommandTest extends TestCase
{
    private function pdoException(string $message, string $sqlState): \PDOException
    {
        return new class ($message, $sqlState) extends \PDOException {
            public function __construct(string $message, string $sqlState)
            {
                parent::__construct($message);
                $this->code = $sqlState;
            }
        };
    }

    public function testTreatsPostgresUndefinedTableAsMissing(): void
    {
        $e = $this->pdoException(
            'SQLSTATE[42P01]: Undefined table: relation "core_schema_migrations" does not exist',
            '42P01'
        );
        self::assertTrue(MigrationsCommand::isMissingMigrationsTable($e));
    }

    public function testTreatsSqliteNoSuchTableAsMissing(): void
    {
        $e = $this->pdoException('SQLSTATE[HY000]: no such table: core_schema_migrations', 'HY000');
        self::assertTrue(MigrationsCommand::isMissingMigrationsTable($e));
    }

    public function testDoesNotSwallowPermissionDeniedThatNamesTheTable(): void
    {
        // The regression: message mentions the table, but it is a REAL error.
        $e = $this->pdoException(
            'SQLSTATE[42501]: Insufficient privilege: permission denied for relation core_schema_migrations',
            '42501'
        );
        self::assertFalse(MigrationsCommand::isMissingMigrationsTable($e));
    }

    public function testDoesNotSwallowDeadlockThatNamesTheTable(): void
    {
        $e = $this->pdoException(
            'SQLSTATE[40P01]: deadlock detected on relation core_schema_migrations',
            '40P01'
        );
        self::assertFalse(MigrationsCommand::isMissingMigrationsTable($e));
    }

    public function testDoesNotTreatAnotherMissingTableAsOurBootstrapCase(): void
    {
        $e = $this->pdoException('SQLSTATE[HY000]: no such table: some_other_table', 'HY000');
        self::assertFalse(MigrationsCommand::isMissingMigrationsTable($e));
    }
}
