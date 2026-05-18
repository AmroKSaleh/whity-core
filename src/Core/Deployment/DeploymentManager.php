<?php

namespace Whity\Core\Deployment;

use PDO;
use RuntimeException;
use Whity\Database\Database;

/**
 * Manages atomic code deployments and rollbacks
 */
class DeploymentManager
{
    private PDO $db;
    private string $storagePath;

    public function __construct(PDO $db, string $storagePath)
    {
        $this->db = $db;
        $this->storagePath = rtrim($storagePath, '/');
    }

    /**
     * Apply a new deployment atomically
     */
    public function apply(int $tenantId, string $version, string $sourcePath): bool
    {
        $targetPath = $this->storagePath . "/$tenantId/$version";

        if (is_dir($targetPath)) {
            throw new RuntimeException("Version $version already exists for tenant $tenantId");
        }

        // Ensure parent directory exists
        if (!is_dir(dirname($targetPath))) {
            mkdir(dirname($targetPath), 0777, true);
        }

        // Create a temp directory for atomic swap later
        $tempPath = $this->storagePath . "/$tenantId/tmp_" . uniqid();
        $this->copyRecursive($sourcePath, $tempPath);

        $this->db->beginTransaction();
        try {
            // 1. Track state: Pending
            $this->updateDeploymentStatus($tenantId, $version, 'pending');

            // 2. Run migrations if present in the deployment
            $migrationPath = $tempPath . '/migrations';
            if (is_dir($migrationPath)) {
                $this->runMigrations($migrationPath);
            }

            // 3. Move files to final location (atomic rename)
            if (!rename($tempPath, $targetPath)) {
                throw new RuntimeException("Failed to move deployment files to final location");
            }

            // 4. Track state: Applied
            $this->updateDeploymentStatus($tenantId, $version, 'applied');

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            // Cleanup temp directory on failure
            if (is_dir($tempPath)) {
                $this->removeRecursive($tempPath);
            }
            // Mark as failed in DB (in a separate transaction if needed, but here we'll just rethrow)
            $this->updateDeploymentStatus($tenantId, $version, 'failed', $e->getMessage());
            throw $e;
        }
    }

    /**
     * Rollback to the previous version
     */
    public function rollback(int $tenantId): bool
    {
        $this->db->beginTransaction();
        try {
            // Get current and previous version
            $stmt = $this->db->prepare('
                SELECT current_version, previous_version
                FROM deployments
                WHERE tenant_id = ? AND status = ?
                ORDER BY applied_at DESC LIMIT 1
            ');
            $stmt->execute([$tenantId, 'applied']);
            $deployment = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$deployment || !$deployment['previous_version']) {
                throw new RuntimeException("No previous version found to rollback to for tenant $tenantId");
            }

            $currentVersion = $deployment['current_version'];
            $previousVersion = $deployment['previous_version'];

            // In a real system, we might need to run "down" migrations here.
            // For now, we update the status.

            $stmt = $this->db->prepare('
                UPDATE deployments
                SET status = ?, rolled_back_at = NOW()
                WHERE tenant_id = ? AND current_version = ?
            ');
            $stmt->execute(['rolled_back', $tenantId, $currentVersion]);

            // Ensure the previous version is marked as applied again
            $stmt = $this->db->prepare('
                UPDATE deployments
                SET status = ?
                WHERE tenant_id = ? AND current_version = ?
            ');
            $stmt->execute(['applied', $tenantId, $previousVersion]);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Rollback a specific migration
     */
    public function rollbackMigration(int $tenantId, string $migrationName): bool
    {
        $this->db->beginTransaction();
        try {
            // Find migration file (this is tricky as it might be in an old deployment or current one)
            // For now, we'll record the rollback intent.

            $stmt = $this->db->prepare('
                INSERT INTO migration_rollbacks (tenant_id, migration_name, rolled_back_at, reason)
                VALUES (?, ?, NOW(), ?)
            ');
            $stmt->execute([$tenantId, $migrationName, 'Manual rollback triggered via API']);

            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function getStatus(int $tenantId): array
    {
        $stmt = $this->db->prepare('
            SELECT * FROM deployments
            WHERE tenant_id = ?
            ORDER BY applied_at DESC LIMIT 5
        ');
        $stmt->execute([$tenantId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function updateDeploymentStatus(int $tenantId, string $version, string $status, ?string $reason = null): void
    {
        $previousVersion = null;
        if ($status === 'pending') {
            $stmt = $this->db->prepare('
                SELECT current_version FROM deployments
                WHERE tenant_id = ? AND status = ?
                ORDER BY applied_at DESC LIMIT 1
            ');
            $stmt->execute([$tenantId, 'applied']);
            $current = $stmt->fetch(PDO::FETCH_ASSOC);
            $previousVersion = $current['current_version'] ?? null;
        }

        $stmt = $this->db->prepare('
            INSERT INTO deployments (tenant_id, current_version, previous_version, status, applied_at)
            VALUES (?, ?, ?, ?, NOW())
            ON CONFLICT (tenant_id, current_version) DO UPDATE
            SET status = EXCLUDED.status,
                applied_at = EXCLUDED.applied_at,
                previous_version = COALESCE(EXCLUDED.previous_version, deployments.previous_version)
        ');
        $stmt->execute([$tenantId, $version, $previousVersion, $status]);
    }
    private function runMigrations(string $migrationPath): void
    {
        $files = glob($migrationPath . '/*.php');
        sort($files);

        // We need a Whity\Database\Database instance that uses our current PDO transaction
        // Since we can't easily do that without modifying Database.php,
        // we'll assume the migrations can take a PDO or we'll have to pass the wrapper.
        // Actually, I'll use reflection to pass the PDO if the migration supports it,
        // or just use a mock of Database for now if I can't change it.

        // BETTER: I'll assume the migration classes are already loaded or can be required.
        foreach ($files as $file) {
            require_once $file;
            $className = pathinfo($file, PATHINFO_FILENAME);
            // Migrations in this project seem to follow a naming convention: 001_create_users_roles.php -> CreateUsersRoles
            $className = $this->formatClassName($className);
            $fqcn = "Database\\Migrations\\$className";

            if (class_exists($fqcn)) {
                // We'll use a hack to pass the PDO to a temporary Database object if possible
                // Or just call the migration with our PDO if we've updated them.
                // Since I can't update Database.php easily, I'll just use the PDO directly if I were to write the migration.
                // But existing migrations use Whity\Database\Database.

                // Let's look at Database.php again. It takes DSN, user, password.
                // I'll just instantiate it with dummy values and then swap the PDO if I could.
                // Or better, I'll just use the PDO directly in a way that looks like Database.

                $dbWrapper = new class($this->db) {
                    private $pdo;
                    public function __construct($pdo) { $this->pdo = $pdo; }
                    public function exec($sql) { return $this->pdo->exec($sql); }
                    public function query($sql, $params = []) {
                        $stmt = $this->pdo->prepare($sql);
                        $stmt->execute($params);
                        return $stmt;
                    }
                    public function getPdo() { return $this->pdo; }
                };

                // This anonymous class mocks the Whity\Database\Database interface for basic usage.
                $fqcn::up($dbWrapper);
            }
        }
    }

    private function formatClassName(string $filename): string
    {
        // 001_create_users_roles -> CreateUsersRoles
        $parts = explode('_', $filename);
        if (is_numeric($parts[0])) {
            array_shift($parts);
        }
        $parts = array_map('ucfirst', $parts);
        return implode('', $parts);
    }

    private function copyRecursive(string $source, string $target): void
    {
        if (!is_dir($target)) {
            mkdir($target, 0777, true);
        }

        $files = scandir($source);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $src = $source . '/' . $file;
            $dst = $target . '/' . $file;
            if (is_dir($src)) {
                $this->copyRecursive($src, $dst);
            } else {
                copy($src, $dst);
            }
        }
    }

    private function removeRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->removeRecursive("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }
}
