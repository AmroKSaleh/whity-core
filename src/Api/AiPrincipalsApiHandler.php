<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\PaginationParams;

/**
 * Admin handler for AI-principal (MCP token) management (WC-0208ce4d).
 *
 * Provides a tenant-scoped administrative view of all MCP credentials issued
 * within the tenant, regardless of which user issued them. The per-user
 * management surface (self-service issue/list/revoke) lives in McpTokenHandler;
 * this class is for the platform admin who needs to audit or revoke any
 * credential across the whole tenant.
 *
 * Endpoints
 * ---------
 *   GET    /api/v1/admin/mcp/tokens          — list all active tokens for the tenant
 *   DELETE /api/v1/admin/mcp/tokens/{jti}    — revoke any token in the tenant
 *
 * Authorization
 * -------------
 * Both routes are gated by the mcp:tokens:manage permission at the router
 * level (index.php). This handler also re-checks the permission as defence in
 * depth, matching the AuditLogApiHandler pattern.
 *
 * Tenant isolation
 * ----------------
 * Every SQL query explicitly filters on tenant_id obtained from TenantContext.
 * The SYSTEM tenant (id 0) sees all tokens across all tenants. An unresolved
 * tenant context fails closed with 403.
 */
final class AiPrincipalsApiHandler
{
    private const SYSTEM_TENANT_ID = 0;

    public function __construct(
        private readonly PDO $db,
        private readonly RoleChecker $roleChecker,
    ) {}

    /**
     * GET /api/v1/admin/mcp/tokens
     *
     * Lists all active (non-expired, non-revoked) MCP tokens for the caller's
     * tenant. The SYSTEM tenant sees every tenant's tokens.
     *
     * @param array<string, mixed> $params Route path parameters (unused).
     */
    public function list(Request $request, array $params = []): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $actor  = $request->user;
            $userId = is_object($actor) && isset($actor->user_id) && is_int($actor->user_id)
                ? $actor->user_id
                : null;
            if ($userId === null || !$this->roleChecker->hasPermission($userId, CorePermissions::MCP_TOKENS_MANAGE, $tenantId)) {
                return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::MCP_TOKENS_MANAGE]);
            }

            // Build the base WHERE clause — system tenant sees all rows.
            $conditions = [];
            /** @var array<string, int|string> $bindParams */
            $bindParams = [];

            if ($tenantId !== self::SYSTEM_TENANT_ID) {
                $conditions[] = 't.tenant_id = :tenant_id';
                $bindParams[':tenant_id'] = $tenantId;
            }

            // Only active tokens: not expired and not in revoked_tokens.
            $conditions[] = 't.expires_at > NOW()';
            $conditions[] = 'NOT EXISTS (SELECT 1 FROM revoked_tokens r WHERE r.jti = t.jti)';

            $where = 'WHERE ' . implode(' AND ', $conditions);

            // Total for pagination.
            // @tenant-guard-ignore: tenant_id predicate appended above for non-system tenants; system tenant (id 0) intentionally reads all rows
            $countSql  = 'SELECT COUNT(*) AS cnt FROM mcp_tokens t ' . $where;
            $countStmt = $this->db->prepare($countSql);
            $countStmt->execute($bindParams);
            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
            $total    = $countRow !== false ? (int) ($countRow['cnt'] ?? 0) : 0;

            // Parse pagination from the query string.
            $query = $this->parseQueryString($request);
            $p     = PaginationParams::fromQuery($query);

            // @tenant-guard-ignore: tenant_id predicate appended above for non-system tenants; system tenant (id 0) intentionally reads all rows
            $listSql  = 'SELECT t.id, t.jti, t.user_id, t.tenant_id, t.name, t.principal_kind, t.scope, t.expires_at, t.created_at
                 FROM   mcp_tokens t
                 ' . $where . '
                 ORDER BY t.created_at DESC
                 LIMIT :limit OFFSET :offset';
            $listStmt = $this->db->prepare($listSql);
            foreach ($bindParams as $key => $value) {
                $listStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $listStmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
            $listStmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
            $listStmt->execute();

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);
            $data = array_map([$this, 'toPublicToken'], $rows);

            return Response::json([
                'data'       => $data,
                'pagination' => $p->meta($total),
            ], 200);
        } catch (\Exception $e) {
            error_log('[AiPrincipalsApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch AI principals', 500);
        }
    }

    /**
     * DELETE /api/v1/admin/mcp/tokens/{jti}
     *
     * Revokes any token belonging to the caller's tenant. The SYSTEM tenant may
     * revoke tokens belonging to any tenant. Returns 204 on success, 404 when
     * the JTI is unknown or does not belong to the caller's tenant.
     *
     * @param array<string, mixed> $params Route path parameters: 'jti'.
     */
    public function revoke(Request $request, array $params = []): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $actor  = $request->user;
            $userId = is_object($actor) && isset($actor->user_id) && is_int($actor->user_id)
                ? $actor->user_id
                : null;
            if ($userId === null || !$this->roleChecker->hasPermission($userId, CorePermissions::MCP_TOKENS_MANAGE, $tenantId)) {
                return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::MCP_TOKENS_MANAGE]);
            }

            $jti = isset($params['jti']) && is_string($params['jti']) ? trim($params['jti']) : '';
            if ($jti === '') {
                return Response::error('jti is required', 400);
            }

            // Ownership check: verify the JTI belongs to this tenant before
            // revoking. SYSTEM tenant (id 0) may revoke any token.
            $ownershipQuery = $tenantId === self::SYSTEM_TENANT_ID
                ? 'SELECT expires_at FROM mcp_tokens WHERE jti = ? LIMIT 1'
                : 'SELECT expires_at FROM mcp_tokens WHERE jti = ? AND tenant_id = ? LIMIT 1';
            $ownershipStmt  = $this->db->prepare($ownershipQuery);

            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $ownershipStmt->execute([$jti]);
            } else {
                $ownershipStmt->execute([$jti, $tenantId]);
            }

            $row = $ownershipStmt->fetch(PDO::FETCH_ASSOC);
            if ($row === false) {
                return Response::error('Token not found', 404);
            }

            // Insert into the shared revocation table (idempotent).
            $stmt = $this->db->prepare("
                INSERT INTO revoked_tokens (jti, expires_at)
                VALUES (?, ?)
                ON CONFLICT (jti) DO NOTHING
            ");
            $stmt->execute([$jti, (string) $row['expires_at']]);

            return new Response(204, '', ['Content-Type' => 'application/json']);
        } catch (\Exception $e) {
            error_log('[AiPrincipalsApiHandler] revoke failed: ' . $e->getMessage());
            return Response::error('Failed to revoke AI principal token', 500);
        }
    }

    /**
     * Shape a raw mcp_tokens row into the public API contract.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublicToken(array $row): array
    {
        $decoded = isset($row['scope']) && is_string($row['scope'])
            ? json_decode($row['scope'], true)
            : null;
        $scope = is_array($decoded) ? $decoded : [];

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'jti'           => (string) ($row['jti'] ?? ''),
            'userId'        => (int) ($row['user_id'] ?? 0),
            'tenantId'      => (int) ($row['tenant_id'] ?? 0),
            'name'          => (string) ($row['name'] ?? ''),
            'principalKind' => (string) ($row['principal_kind'] ?? 'user'),
            'scope'         => $scope,
            'expiresAt'     => isset($row['expires_at']) ? (string) $row['expires_at'] : null,
            'createdAt'     => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }

    /**
     * Parse query string parameters from both $_GET and the request path.
     *
     * @return array<string, string>
     */
    private function parseQueryString(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }
        return $query;
    }
}
