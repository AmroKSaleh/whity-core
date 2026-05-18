<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Database\Database;
use PDO;

/**
 * Admin API Handler
 *
 * Provides system-wide statistics and health information for administrators.
 */
class AdminApiHandler
{
    private Database $db;
    private string $migrationDir;

    public function __construct(Database $db, string $migrationDir)
    {
        $this->db = $db;
        $this->migrationDir = $migrationDir;
    }

    /**
     * GET /api/admin/stats - Comprehensive system statistics
     */
    public function stats(Request $request): Response
    {
        try {
            $pdo = $this->db->getPdo();

            // 1. Basic Totals
            $totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $totalTenants = $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
            $totalRoles = $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();
            $totalPermissions = $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();

            // 2. Role Breakdown
            $usersPerRole = $pdo->query('
                SELECT r.name, COUNT(u.id) as count
                FROM roles r
                LEFT JOIN users u ON u.role_id = r.id
                GROUP BY r.name
            ')->fetchAll(PDO::FETCH_ASSOC);

            // 3. Growth Trends (Last 7 Days)
            $userGrowth = $pdo->query("
                SELECT DATE(created_at) as date, COUNT(*) as count
                FROM users
                WHERE created_at >= NOW() - INTERVAL '7 days'
                GROUP BY DATE(created_at)
                ORDER BY DATE(created_at)
            ")->fetchAll(PDO::FETCH_ASSOC);

            $tenantGrowth = $pdo->query("
                SELECT DATE(created_at) as date, COUNT(*) as count
                FROM tenants
                WHERE created_at >= NOW() - INTERVAL '7 days'
                GROUP BY DATE(created_at)
                ORDER BY DATE(created_at)
            ")->fetchAll(PDO::FETCH_ASSOC);

            // 4. Migration Status
            $executedMigrations = $pdo->query('SELECT COUNT(*) FROM core_schema_migrations')->fetchColumn();
            $allMigrationFiles = count(glob($this->migrationDir . '/*.php'));

            // 5. Database Health
            $dbSize = $pdo->query("SELECT pg_size_pretty(pg_database_size(current_database()))")->fetchColumn();
            $pgVersion = $pdo->query("SHOW server_version")->fetchColumn();

            // 6. System Info
            $systemInfo = [
                'php_version' => PHP_VERSION,
                'memory_usage' => $this->formatBytes(memory_get_usage()),
                'peak_memory' => $this->formatBytes(memory_get_peak_usage()),
                'os' => PHP_OS,
                'server' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
            ];

            return Response::json([
                'status' => 'success',
                'timestamp' => date('Y-m-d H:i:s'),
                'stats' => [
                    'totals' => [
                        'users' => (int)$totalUsers,
                        'tenants' => (int)$totalTenants,
                        'roles' => (int)$totalRoles,
                        'permissions' => (int)$totalPermissions,
                    ],
                    'breakdown' => [
                        'users_per_role' => $usersPerRole,
                    ],
                    'growth' => [
                        'users' => $this->fillMissingDates($userGrowth),
                        'tenants' => $this->fillMissingDates($tenantGrowth),
                    ],
                    'maintenance' => [
                        'migrations_executed' => (int)$executedMigrations,
                        'migrations_total' => $allMigrationFiles,
                        'pending_migrations' => max(0, $allMigrationFiles - $executedMigrations),
                    ],
                    'database' => [
                        'size' => $dbSize,
                        'version' => $pgVersion,
                    ],
                    'system' => $systemInfo
                ]
            ]);
        } catch (\Exception $e) {
            return Response::error('Failed to fetch system stats: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Helper to format bytes to human readable format
     */
    private function formatBytes($bytes, $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Helper to ensure growth charts have entries for all last 7 days
     */
    private function fillMissingDates(array $data): array
    {
        $filled = [];
        for ($i = 6; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $count = 0;
            foreach ($data as $row) {
                if ($row['date'] === $date) {
                    $count = (int)$row['count'];
                    break;
                }
            }
            $filled[] = ['date' => $date, 'count' => $count];
        }
        return $filled;
    }
}
