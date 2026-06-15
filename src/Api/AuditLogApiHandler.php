<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Audit Log API Handler (WC-34).
 *
 * Exposes the security audit trail as a queryable, RBAC-protected, tenant-scoped
 * read endpoint: `GET /api/audit-logs`.
 *
 * Tenant scoping
 * --------------
 * Every query is scoped to the caller's tenant via {@see TenantContext}. The
 * SYSTEM tenant (id 0) — the platform administrator — sees entries across ALL
 * tenants; every other tenant sees ONLY its own rows. There is no path that
 * returns another tenant's audit data to a regular tenant, and an unresolved
 * tenant context fails closed (403). This mirrors the visibility rules of the
 * other admin handlers.
 *
 * Authorization
 * -------------
 * The route is gated on the {@see CorePermissions::AUDIT_READ} permission by the
 * {@see \Whity\Http\RbacMiddleware}. This handler ALSO re-checks the permission
 * against the authoritative store ({@see RoleChecker}) as defence in depth, so
 * the endpoint stays safe even if it were ever mounted without the route-level
 * guard.
 *
 * Filtering & pagination
 * ----------------------
 * Optional query parameters: `action`, `actor` (actor_user_id), `target_type`,
 * `from` / `to` (inclusive ISO-8601 date or datetime bounds on `created_at`),
 * `page` and `per_page`. Results are newest-first (`created_at DESC, id DESC`).
 * The response carries the page slice under `data` and a `pagination` block.
 *
 * No direct DB writes happen here — this is a read-only handler; the single
 * writer is {@see \Whity\Core\Audit\AuditLogger}.
 */
final class AuditLogApiHandler
{
    /**
     * The system tenant id; a caller resolved to it sees every tenant's rows.
     */
    private const SYSTEM_TENANT_ID = 0;

    /**
     * Default and maximum page sizes for the listing.
     */
    private const DEFAULT_PER_PAGE = 25;
    private const MAX_PER_PAGE = 100;

    private PDO $db;
    private RoleChecker $roleChecker;

    /**
     * @param PDO         $db          Database connection.
     * @param RoleChecker $roleChecker Authoritative RBAC resolver for the defence-in-depth check.
     */
    public function __construct(PDO $db, RoleChecker $roleChecker)
    {
        $this->db = $db;
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/audit-logs — list audit entries for the caller's tenant.
     *
     * @param Request $request The incoming request.
     * @return Response JSON `{ data: [...], pagination: {...} }` (200) or an error.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();

            // Fail closed when the tenant context is unresolved.
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            // Defence in depth: re-assert the permission against the store. The
            // acting user id is attached to the request by the middleware.
            $actor = $request->user;
            $userId = is_object($actor) && isset($actor->user_id) && is_int($actor->user_id)
                ? $actor->user_id
                : null;
            if ($userId === null || !$this->roleChecker->hasPermission($userId, CorePermissions::AUDIT_READ, $tenantId)) {
                return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::AUDIT_READ]);
            }

            $query = $this->parseQuery($request->getPath());

            // Build the WHERE clause: always tenant-scoped (system tenant sees all),
            // plus any supplied filters. All values are bound, never interpolated.
            $conditions = [];
            $params = [];

            if ($tenantId !== self::SYSTEM_TENANT_ID) {
                $conditions[] = 'tenant_id = :tenant_id';
                $params[':tenant_id'] = $tenantId;
            }

            if (isset($query['action']) && $query['action'] !== '') {
                $conditions[] = 'action = :action';
                $params[':action'] = (string) $query['action'];
            }

            if (isset($query['target_type']) && $query['target_type'] !== '') {
                $conditions[] = 'target_type = :target_type';
                $params[':target_type'] = (string) $query['target_type'];
            }

            if (isset($query['actor']) && ctype_digit((string) $query['actor'])) {
                $conditions[] = 'actor_user_id = :actor';
                $params[':actor'] = (int) $query['actor'];
            }

            if (isset($query['from']) && $query['from'] !== '') {
                $conditions[] = 'created_at >= :from';
                $params[':from'] = (string) $query['from'];
            }

            if (isset($query['to']) && $query['to'] !== '') {
                $conditions[] = 'created_at <= :to';
                $params[':to'] = (string) $query['to'];
            }

            $where = $conditions === [] ? '' : (' WHERE ' . implode(' AND ', $conditions));

            // Total count for pagination metadata.
            // @tenant-guard-ignore: tenant_id predicate is appended to $where only for non-system tenants; system tenant (id 0) reads all audit rows by design
            $countStmt = $this->db->prepare('SELECT COUNT(*) AS cnt FROM audit_log' . $where);
            $countStmt->execute($params);
            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
            $total = $countRow !== false ? (int) ($countRow['cnt'] ?? 0) : 0;

            $perPage = $this->resolvePerPage($query);
            $page = $this->resolvePage($query);
            $offset = ($page - 1) * $perPage;

            // Newest-first; bound LIMIT/OFFSET as integers (cast, not interpolated
            // arbitrary input — both are validated non-negative ints above).
            // @tenant-guard-ignore: tenant_id predicate is appended to $where only for non-system tenants; system tenant (id 0) reads all audit rows by design
            $listStmt = $this->db->prepare(
                'SELECT id, tenant_id, actor_user_id, action, target_type, target_id, metadata, ip_address, created_at
                 FROM audit_log' . $where . '
                 ORDER BY created_at DESC, id DESC
                 LIMIT :limit OFFSET :offset'
            );
            foreach ($params as $key => $value) {
                $listStmt->bindValue($key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
            }
            $listStmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
            $listStmt->bindValue(':offset', $offset, PDO::PARAM_INT);
            $listStmt->execute();

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC);

            $data = array_map([$this, 'toPublicEntry'], $rows);

            return Response::json([
                'data' => $data,
                'pagination' => [
                    'page' => $page,
                    'perPage' => $perPage,
                    'total' => $total,
                    'totalPages' => $perPage > 0 ? (int) ceil($total / $perPage) : 0,
                ],
            ], 200);
        } catch (\Exception $e) {
            error_log('[AuditLogApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch audit logs', 500);
        }
    }

    /**
     * Shape a raw audit_log row into the public API contract.
     *
     * Casts integer-like columns (PDO returns them as strings under Postgres) and
     * decodes the JSON metadata so the client receives a structured object. The
     * shape uses camelCase keys for the fields the frontend binds.
     *
     * @param array<string, mixed> $row A raw audit_log row.
     * @return array<string, mixed> The public entry.
     */
    private function toPublicEntry(array $row): array
    {
        $metadata = [];
        if (isset($row['metadata']) && is_string($row['metadata']) && $row['metadata'] !== '') {
            $decoded = json_decode($row['metadata'], true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        // The published contract (WC-167) declares metadata: object. An empty
        // PHP array would JSON-encode as [] — emit a real empty OBJECT instead.
        $publicMetadata = $metadata === [] ? new \stdClass() : $metadata;

        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenantId' => isset($row['tenant_id']) ? (int) $row['tenant_id'] : null,
            'actorUserId' => isset($row['actor_user_id']) && $row['actor_user_id'] !== null
                ? (int) $row['actor_user_id']
                : null,
            'action' => (string) ($row['action'] ?? ''),
            'targetType' => isset($row['target_type']) && $row['target_type'] !== null
                ? (string) $row['target_type']
                : null,
            'targetId' => isset($row['target_id']) && $row['target_id'] !== null
                ? (int) $row['target_id']
                : null,
            'metadata' => $publicMetadata,
            'ipAddress' => isset($row['ip_address']) && $row['ip_address'] !== null
                ? (string) $row['ip_address']
                : null,
            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }

    /**
     * Resolve the validated page size from the query (clamped to a sane range).
     *
     * @param array<string, string> $query Parsed query parameters.
     * @return int The page size, in [1, MAX_PER_PAGE].
     */
    private function resolvePerPage(array $query): int
    {
        $raw = $query['per_page'] ?? null;
        if (!is_string($raw) || !ctype_digit($raw)) {
            return self::DEFAULT_PER_PAGE;
        }

        $value = (int) $raw;
        if ($value < 1) {
            return self::DEFAULT_PER_PAGE;
        }

        return min($value, self::MAX_PER_PAGE);
    }

    /**
     * Resolve the validated 1-based page number from the query.
     *
     * @param array<string, string> $query Parsed query parameters.
     * @return int The page number (>= 1).
     */
    private function resolvePage(array $query): int
    {
        $raw = $query['page'] ?? null;
        if (!is_string($raw) || !ctype_digit($raw)) {
            return 1;
        }

        return max(1, (int) $raw);
    }

    /**
     * Parse the query-string parameters for the request.
     *
     * Reads from two sources so it works both in production and in tests:
     *  - the `$_GET` superglobal (how FrankenPHP exposes the query at runtime —
     *    {@see Request::fromGlobals()} strips the query out of the path), and
     *  - the query component of the raw request path (how the test suite builds a
     *    {@see Request}, mirroring {@see \Whity\Http\Middleware\EnforceTenantIsolation}).
     * Path values win when both are present.
     *
     * @param string $rawPath The request path, possibly including a query string.
     * @return array<string, string> The parsed parameters.
     */
    private function parseQuery(string $rawPath): array
    {
        $params = [];

        // Runtime source: the $_GET superglobal.
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $params[$key] = $value;
            }
        }

        // Path source (tests / explicit query in the path).
        $queryString = parse_url($rawPath, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $parsed);
            foreach ($parsed as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $params[$key] = $value;
                }
            }
        }

        return $params;
    }
}
