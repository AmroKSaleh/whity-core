<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\PluginMigrationRollback;

/**
 * Tests for PluginMigrationRollback service.
 *
 * Uses a real SQLite in-memory PDO so migration tracking rows can be
 * inserted and queried without mocking.
 */
class PluginMigrationRollbackTest extends TestCase
{
    private \PDO $pdo;
    private PluginMigrationRollback $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $this->pdo->exec('
            CREATE TABLE IF NOT EXISTS core_schema_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration_name VARCHAR(255) NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                execution_time_ms INTEGER
            )
        ');

        $this->service = new PluginMigrationRollback($this->pdo);
    }

    /** Helper: insert a tracking row. */
    private function insertMigration(string $name): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO core_schema_migrations (migration_name, executed_at) VALUES (?, datetime('now'))"
        );
        $stmt->execute([$name]);
    }

    public function testRollbackRemovesMigrationTrackingRows(): void
    {
        $this->insertMigration('plugin:HelloWorld:CreateHelloTable');
        $this->insertMigration('plugin:HelloWorld:AddHelloColumn');
        $this->insertMigration('plugin:Other:CreateOtherTable');

        $result = $this->service->rollback('HelloWorld');

        $this->assertSame([], $result['errors']);
        $this->assertCount(2, $result['rolled_back']);
        $this->assertContains('plugin:HelloWorld:CreateHelloTable', $result['rolled_back']);
        $this->assertContains('plugin:HelloWorld:AddHelloColumn', $result['rolled_back']);

        // Other plugin's rows must be untouched.
        $stmt = $this->pdo->query(
            "SELECT COUNT(*) FROM core_schema_migrations WHERE migration_name LIKE 'plugin:Other:%'"
        );
        $this->assertNotFalse($stmt);
        $this->assertSame(1, (int) $stmt->fetchColumn());
    }

    public function testRollbackReturnsNoErrorsWhenNoMigrationsExist(): void
    {
        $result = $this->service->rollback('NonExistentPlugin');

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['rolled_back']);
    }

    public function testListMigrationsForPluginReturnsPrefixMatchedNames(): void
    {
        $this->insertMigration('plugin:HelloWorld:CreateHelloTable');
        $this->insertMigration('plugin:HelloWorld:AddHelloColumn');
        $this->insertMigration('plugin:Other:CreateOtherTable');

        $list = $this->service->listMigrationsForPlugin('HelloWorld');

        $this->assertCount(2, $list);
        $this->assertContains('plugin:HelloWorld:CreateHelloTable', $list);
        $this->assertContains('plugin:HelloWorld:AddHelloColumn', $list);
    }

    public function testRollbackReturnsRowsInReverseIdOrder(): void
    {
        $this->insertMigration('plugin:Ordered:First');
        $this->insertMigration('plugin:Ordered:Second');
        $this->insertMigration('plugin:Ordered:Third');

        $result = $this->service->rollback('Ordered');

        $this->assertSame([], $result['errors']);
        $this->assertSame([
            'plugin:Ordered:Third',
            'plugin:Ordered:Second',
            'plugin:Ordered:First',
        ], $result['rolled_back']);
    }
}
