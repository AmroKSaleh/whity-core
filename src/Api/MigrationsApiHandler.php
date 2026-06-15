<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Database\Database;
use PDO;

/**
 * Migrations API Handler
 *
 * Handles database migrations management.
 */
class MigrationsApiHandler
{
    private Database $db;
    private string $migrationDir;

    public function __construct(Database $db, string $migrationDir)
    {
        $this->db = $db;
        $this->migrationDir = $migrationDir;
    }

    /**
     * GET /api/migrations - List all migrations and their status
     */
    public function list(Request $request): Response
    {
        try {
            $executed = $this->getExecutedMigrations();
            $files = $this->getMigrationFiles();

            $migrations = [];
            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                $isExecuted = isset($executed[$name]);

                $migrations[] = [
                    'name' => $name,
                    'executed' => $isExecuted,
                    'executed_at' => $isExecuted ? $executed[$name]['executed_at'] : null
                ];
            }

            return Response::json(['data' => $migrations], 200);
        } catch (\Exception $e) {
            error_log('[MigrationsApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to list migrations', 500);
        }
    }

    /**
     * POST /api/migrations/run - Run pending migrations
     */
    public function run(Request $request): Response
    {
        try {
            $executed = $this->getExecutedMigrations();
            $files = $this->getMigrationFiles();
            $count = 0;

            foreach ($files as $file) {
                $name = pathinfo($file, PATHINFO_FILENAME);
                if (!isset($executed[$name])) {
                    $this->executeMigration($file, 'up');
                    $count++;
                }
            }

            return Response::json(['data' => ['count' => $count]], 200);
        } catch (\Exception $e) {
            error_log('[MigrationsApiHandler] run failed: ' . $e->getMessage());
            return Response::error('Migration failed', 500);
        }
    }

    /**
     * POST /api/migrations/rollback - Rollback last migration
     */
    public function rollback(Request $request): Response
    {
        try {
            $stmt = $this->db->getPdo()->prepare('
                SELECT migration_name FROM core_schema_migrations
                ORDER BY executed_at DESC LIMIT 1
            ');
            $stmt->execute();
            $last = $stmt->fetch();

            if (!$last) {
                return Response::error('No migrations to rollback', 400);
            }

            $name = $last['migration_name'];
            $file = $this->migrationDir . '/' . $name . '.php';

            if (!file_exists($file)) {
                return Response::error("Migration file {$name}.php not found", 500);
            }

            $this->executeMigration($file, 'down');

            return Response::json(['data' => ['message' => "Rolled back {$name}"]], 200);
        } catch (\Exception $e) {
            error_log('[MigrationsApiHandler] rollback failed: ' . $e->getMessage());
            return Response::error('Rollback failed', 500);
        }
    }

    /**
     * Get list of executed migrations from database
     */
    private function getExecutedMigrations(): array
    {
        try {
            $stmt = $this->db->getPdo()->prepare('SELECT migration_name, executed_at FROM core_schema_migrations');
            $stmt->execute();
            $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $executed = [];
            foreach ($results as $row) {
                $executed[$row['migration_name']] = $row;
            }
            return $executed;
        } catch (\PDOException $e) {
            // If the migrations table doesn't exist yet, return empty array
            // This handles the bootstrap case on first run
            if (strpos($e->getMessage(), 'core_schema_migrations') !== false) {
                return [];
            }
            throw $e;
        }
    }

    /**
     * Get list of migration files from directory
     */
    private function getMigrationFiles(): array
    {
        $files = scandir($this->migrationDir);
        $migrationFiles = [];
        foreach ($files as $file) {
            if (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $migrationFiles[] = $this->migrationDir . '/' . $file;
            }
        }
        sort($migrationFiles);
        return $migrationFiles;
    }

    /**
     * Execute a migration file
     */
    private function executeMigration(string $file, string $direction): void
    {
        require_once $file;
        $name = pathinfo($file, PATHINFO_FILENAME);

        // Extract class name (e.g., 001_create_users -> CreateUsers)
        $parts = explode('_', $name);
        array_shift($parts); // Remove prefix number
        $className = 'Database\\Migrations\\' . implode('', array_map('ucfirst', $parts));

        if (!class_exists($className)) {
            throw new \Exception("Migration class {$className} not found in {$file}");
        }

        $start = microtime(true);

        if ($direction === 'up') {
            $className::up($this->db);

            // Try to record the migration, but don't fail if the migrations table doesn't exist yet
            // (it will be created by migration 005)
            try {
                $stmt = $this->db->getPdo()->prepare('
                    INSERT INTO core_schema_migrations (migration_name, executed_at, execution_time_ms)
                    VALUES (?, NOW(), ?)
                ');
                $stmt->execute([$name, (int)((microtime(true) - $start) * 1000)]);
            } catch (\PDOException $e) {
                // If the migrations table doesn't exist yet, silently skip recording
                // It will be recorded in subsequent runs once the table exists
                if (strpos($e->getMessage(), 'core_schema_migrations') === false) {
                    throw $e;
                }
            }
        } else {
            $className::down($this->db);

            // Try to remove the migration record, but don't fail if it doesn't exist
            try {
                $stmt = $this->db->getPdo()->prepare('DELETE FROM core_schema_migrations WHERE migration_name = ?');
                $stmt->execute([$name]);
            } catch (\PDOException $e) {
                // If the migrations table doesn't exist, silently skip
                if (strpos($e->getMessage(), 'core_schema_migrations') === false) {
                    throw $e;
                }
            }
        }
    }
}
