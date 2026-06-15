<?php

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Deployment\DeploymentManager;
use Whity\Core\Tenant\TenantContext;

/**
 * Deployment API Handler
 *
 * Handles deployment and rollback operations.
 */
class DeploymentApiHandler
{
    private DeploymentManager $deploymentManager;

    public function __construct(DeploymentManager $deploymentManager)
    {
        $this->deploymentManager = $deploymentManager;
    }

    /**
     * POST /api/deployments/apply - Apply staged code
     */
    public function apply(Request $request): Response
    {
        try {
            if (!TenantContext::hasTenant()) {
                return Response::error('Tenant context required', 403);
            }

            $tenantId = TenantContext::getTenantId();
            $body = json_decode($request->getBody(), true);

            if (empty($body['version']) || empty($body['source_path'])) {
                return Response::error('Version and source_path are required', 400);
            }

            $this->deploymentManager->apply($tenantId, $body['version'], $body['source_path']);

            return Response::json(['message' => 'Deployment applied successfully'], 201);
        } catch (\Exception $e) {
            error_log('[DeploymentApiHandler] deploy failed: ' . $e->getMessage());
            return Response::error('Deployment failed', 500);
        }
    }

    /**
     * POST /api/deployments/rollback - Rollback to previous version
     */
    public function rollback(Request $request): Response
    {
        try {
            if (!TenantContext::hasTenant()) {
                return Response::error('Tenant context required', 403);
            }

            $tenantId = TenantContext::getTenantId();
            $this->deploymentManager->rollback($tenantId);

            return Response::json(['message' => 'Rollback successful'], 200);
        } catch (\Exception $e) {
            error_log('[DeploymentApiHandler] rollback failed: ' . $e->getMessage());
            return Response::error('Rollback failed', 500);
        }
    }

    /**
     * GET /api/deployments/status - Get deployment status
     */
    public function status(Request $request): Response
    {
        try {
            if (!TenantContext::hasTenant()) {
                return Response::error('Tenant context required', 403);
            }

            $tenantId = TenantContext::getTenantId();
            $status = $this->deploymentManager->getStatus($tenantId);

            return Response::json(['data' => $status], 200);
        } catch (\Exception $e) {
            error_log('[DeploymentApiHandler] status failed: ' . $e->getMessage());
            return Response::error('Failed to fetch status', 500);
        }
    }

    /**
     * POST /api/migrations/rollback - Rollback a specific migration
     */
    public function rollbackMigration(Request $request): Response
    {
        try {
            if (!TenantContext::hasTenant()) {
                return Response::error('Tenant context required', 403);
            }

            $tenantId = TenantContext::getTenantId();
            $body = json_decode($request->getBody(), true);

            if (empty($body['migration_name'])) {
                return Response::error('migration_name is required', 400);
            }

            $this->deploymentManager->rollbackMigration($tenantId, $body['migration_name']);

            return Response::json(['message' => 'Migration rollback recorded'], 200);
        } catch (\Exception $e) {
            error_log('[DeploymentApiHandler] rollbackMigration failed: ' . $e->getMessage());
            return Response::error('Migration rollback failed', 500);
        }
    }
}
