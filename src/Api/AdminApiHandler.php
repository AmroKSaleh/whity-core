<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use PDO;

/**
 * Admin API Handler
 *
 * Provides system-wide statistics and health information for administrators.
 *
 * Tenant isolation (WC-50): the aggregate counts are scoped to the caller's
 * tenant. System users (tenant_id=0) retain the platform-wide totals; a regular
 * tenant's admin sees only its own tenant's figures (its user count and growth,
 * the roles it can see, and a tenant total fixed at 1 — its own). Without this
 * scoping a regular tenant's admin could read platform-wide totals such as
 * other tenants' user counts and the total number of tenants.
 */
class AdminApiHandler
{
    /**
     * The reserved identifier for the system tenant.
     *
     * System users (tenant_id=0) act with cross-tenant authority and so see the
     * unfiltered, platform-wide statistics.
     */
    private const SYSTEM_TENANT_ID = 0;

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

            // Scope every aggregate to the caller (WC-50). System users
            // (tenant_id=0) see platform-wide totals; a regular tenant sees only
            // its own figures. A null/unresolved context is treated as a regular
            // tenant (it is not the system tenant), so it can never fall through
            // to the global counts.
            $tenantId = TenantContext::getTenantId();
            $isSystemUser = $tenantId === self::SYSTEM_TENANT_ID;

            if ($isSystemUser) {
                // 1. Basic Totals (platform-wide)
                $totalUsers = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
                $totalTenants = $pdo->query('SELECT COUNT(*) FROM tenants')->fetchColumn();
                $totalRoles = $pdo->query('SELECT COUNT(*) FROM roles')->fetchColumn();

                // 2. Role Breakdown (all roles, all users)
                $usersPerRole = $pdo->query('
                    SELECT r.name, COUNT(u.id) as count
                    FROM roles r
                    LEFT JOIN users u ON u.role_id = r.id
                    GROUP BY r.name
                ')->fetchAll(PDO::FETCH_ASSOC);

                // 3. Growth Trends (Last 7 Days, platform-wide)
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
            } else {
                // 1. Basic Totals (scoped to the caller's tenant). The tenant
                // total is fixed at 1 (the caller's own) so the platform tenant
                // count never leaks.
                $usersStmt = $pdo->prepare('SELECT COUNT(*) FROM users WHERE tenant_id = :tid');
                $usersStmt->execute(['tid' => $tenantId]);
                $totalUsers = $usersStmt->fetchColumn();

                $totalTenants = 1;

                // Roles visible to the tenant: its own plus globals (NULL
                // tenant_id), consistent with the WC-110 role-visibility model.
                $rolesStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM roles WHERE tenant_id = :tid OR tenant_id IS NULL'
                );
                $rolesStmt->execute(['tid' => $tenantId]);
                $totalRoles = $rolesStmt->fetchColumn();

                // 2. Role Breakdown: only roles the tenant can see, counting only
                // the tenant's own users against them.
                $breakdownStmt = $pdo->prepare('
                    SELECT r.name, COUNT(u.id) as count
                    FROM roles r
                    LEFT JOIN users u ON u.role_id = r.id AND u.tenant_id = :tid_users
                    WHERE r.tenant_id = :tid_roles OR r.tenant_id IS NULL
                    GROUP BY r.name
                ');
                $breakdownStmt->execute(['tid_users' => $tenantId, 'tid_roles' => $tenantId]);
                $usersPerRole = $breakdownStmt->fetchAll(PDO::FETCH_ASSOC);

                // 3. Growth Trends (Last 7 Days, scoped to the caller's tenant).
                $userGrowthStmt = $pdo->prepare("
                    SELECT DATE(created_at) as date, COUNT(*) as count
                    FROM users
                    WHERE tenant_id = :tid AND created_at >= NOW() - INTERVAL '7 days'
                    GROUP BY DATE(created_at)
                    ORDER BY DATE(created_at)
                ");
                $userGrowthStmt->execute(['tid' => $tenantId]);
                $userGrowth = $userGrowthStmt->fetchAll(PDO::FETCH_ASSOC);

                // A regular tenant only ever owns itself, so its tenant-growth
                // series carries just its own creation; the platform-wide
                // tenant timeline is never exposed.
                $tenantGrowthStmt = $pdo->prepare("
                    SELECT DATE(created_at) as date, COUNT(*) as count
                    FROM tenants
                    WHERE id = :tid AND created_at >= NOW() - INTERVAL '7 days'
                    GROUP BY DATE(created_at)
                    ORDER BY DATE(created_at)
                ");
                $tenantGrowthStmt->execute(['tid' => $tenantId]);
                $tenantGrowth = $tenantGrowthStmt->fetchAll(PDO::FETCH_ASSOC);
            }

            // Permissions are a global catalogue (not tenant-owned), so the count
            // is the same for every caller.
            $totalPermissions = $pdo->query('SELECT COUNT(*) FROM permissions')->fetchColumn();

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
            error_log('[AdminApiHandler] stats failed: ' . $e->getMessage());
            return Response::error('Failed to fetch system stats', 500);
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
