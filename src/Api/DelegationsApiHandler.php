<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Api\Exception\PermissionNotDelegableException;
use Whity\Auth\RoleChecker;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Core\Delegation\DelegationService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use PDO;

/**
 * Permission Delegations API Handler (WC-34, issue #34 — role delegation half).
 *
 * RBAC-protected (gated on `delegation:manage`) and tenant-scoped. Lets a
 * role-holder grant a SUBSET of their OWN effective permissions to a role or a
 * user, optionally scoped to an OU subtree, and list/revoke those delegations.
 *
 * The HARD subset invariant — a grantor can NEVER delegate a permission they do
 * not hold — is enforced server-side in {@see DelegationService::delegate()},
 * which computes the grantor's effective permissions via {@see RoleChecker} and
 * raises {@see PermissionNotDelegableException}. This handler translates that
 * typed domain error into a safe `422` and never leaks internals.
 *
 * No raw SQL lives here beyond small tenant-scoped existence/visibility checks
 * for the grantee and OU; all delegation persistence goes through
 * {@see DelegationService} / {@see DelegationRepository}.
 *
 * Cache coherence: creating or revoking a delegation changes effective
 * permission sets, so every mutating write calls {@see RoleChecker::clearCache()}
 * — otherwise a `hasPermission()` check could keep serving a stale resolved set.
 */
class DelegationsApiHandler
{
    private PDO $db;
    private DelegationService $service;
    private ?LoggerInterface $logger;

    /**
     * @param PDO                  $db      Database connection (grantee/OU visibility checks).
     * @param DelegationService    $service Delegation domain service.
     * @param LoggerInterface|null $logger  Optional PSR-3 logger for structured logs.
     */
    public function __construct(PDO $db, DelegationService $service, ?LoggerInterface $logger = null)
    {
        $this->db = $db;
        $this->service = $service;
        $this->logger = $logger;
    }

    /**
     * GET /api/delegations - List delegations visible to the current tenant.
     *
     * Query filters (all optional): `granteeType` (role|user), `granteeId`,
     * `grantorUserId`, `includeRevoked` (1/true to include revoked rows).
     *
     * @param Request $request The incoming request.
     * @return Response JSON list of delegations under the `data` key.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $query = $this->queryParams($request);

            $granteeType = isset($query['granteeType']) ? (string) $query['granteeType'] : null;
            if ($granteeType !== null && !$this->isValidGranteeType($granteeType)) {
                return Response::error('Invalid grantee type', 400);
            }

            $granteeId = isset($query['granteeId']) && is_numeric($query['granteeId'])
                ? (int) $query['granteeId']
                : null;
            $grantorUserId = isset($query['grantorUserId']) && is_numeric($query['grantorUserId'])
                ? (int) $query['grantorUserId']
                : null;
            $includeRevoked = isset($query['includeRevoked'])
                && in_array(strtolower((string) $query['includeRevoked']), ['1', 'true', 'yes'], true);

            $rows = $this->service->list(
                $tenantId,
                $granteeType,
                $granteeId,
                $grantorUserId,
                $includeRevoked
            );

            return Response::json(['data' => array_map([$this, 'toPublic'], $rows)], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch delegations', [
                'event' => 'delegations.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to fetch delegations', 500);
        }
    }

    /**
     * POST /api/delegations - Create a delegation (grant a subset of own permissions).
     *
     * Body: `{granteeType: 'role'|'user', granteeId: int, permissions: string[],
     * ouId?: int|null}`. The acting user is the grantor; the subset invariant is
     * enforced against THEIR effective permissions.
     *
     * @param Request $request The incoming request (must carry the authenticated user).
     * @return Response JSON created delegation summary (201), or a typed error.
     */
    public function create(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $grantorUserId = $this->actingUserId($request);
            if ($grantorUserId === null) {
                return Response::error('Authenticated user is required', 401);
            }

            /** @var array<string, mixed>|null $body */
            $body = json_decode($request->getBody(), true);
            if (!is_array($body)) {
                return Response::error('Invalid request body', 400);
            }

            $granteeType = isset($body['granteeType']) ? (string) $body['granteeType'] : '';
            if (!$this->isValidGranteeType($granteeType)) {
                return Response::error('granteeType must be "role" or "user"', 400);
            }

            if (!isset($body['granteeId']) || !is_numeric($body['granteeId'])) {
                return Response::error('granteeId is required', 400);
            }
            $granteeId = (int) $body['granteeId'];

            $permissions = $this->extractPermissions($body);
            if ($permissions === []) {
                return Response::error('At least one permission is required', 400);
            }

            $ouId = $this->extractOuId($body);

            // Grantee must be visible to the acting tenant; otherwise 404 so a
            // grantee's existence in another tenant is never disclosed.
            if (!$this->granteeVisible($granteeType, $granteeId, $tenantId)) {
                return Response::error('Grantee not found', 404);
            }

            // An OU scope, when supplied, must belong to the acting tenant.
            if ($ouId !== null && !$this->ouVisible($ouId, $tenantId)) {
                return Response::error('Organizational unit not found', 404);
            }

            // Enforce the subset invariant + persist (one row per permission).
            $ids = $this->service->delegate(
                $tenantId,
                $grantorUserId,
                $granteeType,
                $granteeId,
                $permissions,
                $ouId
            );

            // A new delegation changes effective permission sets; refresh caches.
            RoleChecker::clearCache();

            $this->log('info', 'Delegation created', [
                'event' => 'delegations.create',
                'tenant_id' => $tenantId,
                'grantor_user_id' => $grantorUserId,
                'grantee_type' => $granteeType,
                'grantee_id' => $granteeId,
                'ou_id' => $ouId,
                'permission_count' => count($ids),
            ]);

            return Response::json([
                'data' => [
                    'ids' => $ids,
                    'granteeType' => $granteeType,
                    'granteeId' => $granteeId,
                    'ouId' => $ouId,
                    'permissions' => $permissions,
                    'count' => count($ids),
                ],
            ], 201);
        } catch (PermissionNotDelegableException $e) {
            // Subset invariant violated: a client error, not a server fault. 422
            // (Unprocessable Entity) — well-formed but semantically invalid; no
            // row written. The denied permissions are logged, never leaked.
            $this->log('warning', 'Delegation rejected: permission not held by grantor', [
                'event' => 'delegations.subset_violation',
                'tenant_id' => TenantContext::getTenantId(),
                'denied_permissions' => $e->getDeniedPermissions(),
            ]);

            return Response::error(
                'You cannot delegate a permission you do not hold',
                422
            );
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create delegation', [
                'event' => 'delegations.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to create delegation', 500);
        }
    }

    /**
     * DELETE /api/delegations/{id} - Revoke a delegation (non-destructive).
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON confirmation (200) or an error.
     */
    public function revoke(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if ($id === null || !is_numeric($id)) {
                return Response::error('Delegation ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $revoked = $this->service->revoke((int) $id, $tenantId);
            if (!$revoked) {
                // Not found, not visible to this tenant, or already revoked.
                return Response::error('Delegation not found', 404);
            }

            // Revoking removes access; refresh the worker-level caches.
            RoleChecker::clearCache();

            $this->log('info', 'Delegation revoked', [
                'event' => 'delegations.revoke',
                'tenant_id' => $tenantId,
                'delegation_id' => (int) $id,
            ]);

            return Response::json(['data' => ['id' => (int) $id, 'message' => 'Delegation revoked']], 200);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to revoke delegation', [
                'event' => 'delegations.error',
                'tenant_id' => TenantContext::getTenantId(),
                'detail' => $e->getMessage(),
            ]);
            return Response::error('Failed to revoke delegation', 500);
        }
    }

    /**
     * Whether a grantee (role or user) is visible to the acting tenant.
     *
     * Users must belong to the tenant. Roles follow the WC-110 visibility model:
     * own roles plus global (NULL tenant_id) roles. The system tenant (id 0) sees
     * every grantee.
     *
     * @param string $granteeType The grantee discriminator.
     * @param int    $granteeId   The grantee id.
     * @param int    $tenantId    The acting tenant.
     * @return bool True when the grantee is visible.
     */
    private function granteeVisible(string $granteeType, int $granteeId, int $tenantId): bool
    {
        if ($granteeType === DelegationRepository::GRANTEE_USER) {
            if ($tenantId === 0) {
                $stmt = $this->db->prepare('SELECT 1 FROM users WHERE id = ?');
                $stmt->execute([$granteeId]);
            } else {
                $stmt = $this->db->prepare('SELECT 1 FROM users WHERE id = ? AND tenant_id = ?');
                $stmt->execute([$granteeId, $tenantId]);
            }

            return $stmt->fetchColumn() !== false;
        }

        // Role grantee.
        if ($tenantId === 0) {
            $stmt = $this->db->prepare('SELECT 1 FROM roles WHERE id = ?');
            $stmt->execute([$granteeId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM roles WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)'
            );
            $stmt->execute([$granteeId, $tenantId]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Whether an OU belongs to (is visible to) the acting tenant.
     *
     * @param int $ouId     The OU id.
     * @param int $tenantId The acting tenant (0 = system sees all).
     * @return bool True when visible.
     */
    private function ouVisible(int $ouId, int $tenantId): bool
    {
        if ($tenantId === 0) {
            $stmt = $this->db->prepare('SELECT 1 FROM organizational_units WHERE id = ?');
            $stmt->execute([$ouId]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM organizational_units WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([$ouId, $tenantId]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Extract and normalise the `permissions` list from the body.
     *
     * @param array<string, mixed> $body Decoded request body.
     * @return array<int, string> The permission strings (non-scalar entries dropped).
     */
    private function extractPermissions(array $body): array
    {
        if (!isset($body['permissions']) || !is_array($body['permissions'])) {
            return [];
        }

        $permissions = [];
        foreach ($body['permissions'] as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $permissions[] = trim($entry);
            }
        }

        return $permissions;
    }

    /**
     * Extract an optional integer OU id from the body. Treats absent/null/empty
     * as "no OU scope" (tenant-wide).
     *
     * @param array<string, mixed> $body Decoded request body.
     * @return int|null The OU id, or null for tenant-wide.
     */
    private function extractOuId(array $body): ?int
    {
        if (!array_key_exists('ouId', $body) || $body['ouId'] === null || $body['ouId'] === '') {
            return null;
        }

        return is_numeric($body['ouId']) ? (int) $body['ouId'] : null;
    }

    /**
     * Whether a grantee type is one of the two legal discriminator values.
     */
    private function isValidGranteeType(string $type): bool
    {
        return $type === DelegationRepository::GRANTEE_ROLE
            || $type === DelegationRepository::GRANTEE_USER;
    }

    /**
     * Resolve the acting (authenticated) user id from the request, if present.
     *
     * @param Request $request The incoming request.
     * @return int|null The user id, or null when unauthenticated/malformed.
     */
    private function actingUserId(Request $request): ?int
    {
        $user = $request->user;
        if ($user === null) {
            return null;
        }

        $userId = $user->user_id ?? null;

        return is_int($userId) ? $userId : (is_numeric($userId) ? (int) $userId : null);
    }

    /**
     * Parse the request's query parameters from BOTH runtime sources.
     *
     * At runtime FrankenPHP strips the query string from the path, so $_GET is
     * the live source; the path-query form is how the test suite builds a
     * {@see Request}. Path values win when both are present — mirroring
     * {@see AuditLogApiHandler::parseQuery()} (WC-167 review: path-only parsing
     * made every documented filter dead in production).
     *
     * @param Request $request The incoming request.
     * @return array<string, string> The decoded query parameters.
     */
    private function queryParams(Request $request): array
    {
        /** @var array<string, string> $stringParams */
        $stringParams = [];

        // Runtime source: the $_GET superglobal.
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $stringParams[$key] = $value;
            }
        }

        // Path source (tests / explicit query in the path).
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            foreach ($params as $key => $value) {
                if (is_string($value)) {
                    $stringParams[(string) $key] = $value;
                }
            }
        }

        return $stringParams;
    }

    /**
     * Shape a normalised delegation row into the public camelCase API contract.
     *
     * @param array<string, mixed> $row Normalised delegation row.
     * @return array<string, mixed> Public representation.
     */
    private function toPublic(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'tenantId' => (int) $row['tenant_id'],
            'grantorUserId' => (int) $row['grantor_user_id'],
            'granteeType' => (string) $row['grantee_type'],
            'granteeId' => (int) $row['grantee_id'],
            'permission' => (string) $row['permission'],
            'ouId' => $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
            'grantedAt' => $row['granted_at'] !== null ? (string) $row['granted_at'] : null,
            'revokedAt' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
        ];
    }

    /**
     * Emit a structured log record when a logger is configured.
     *
     * @param string               $level   PSR-3 log level method (e.g. `info`).
     * @param string               $message The human-readable message.
     * @param array<string, mixed> $context Structured context (includes tenant_id).
     * @return void
     */
    private function log(string $level, string $message, array $context): void
    {
        if ($this->logger === null) {
            return;
        }

        $this->logger->log($level, $message, $context);
    }
}
