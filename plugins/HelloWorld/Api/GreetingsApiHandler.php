<?php

declare(strict_types=1);

namespace HelloWorld\Api;

use PDO;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Tenant-scoped CRUD over the plugin's own `hello_greetings` table (WC-169).
 *
 * The DB-backed half of the HelloWorld reference plugin: demonstrates how a
 * plugin route handler performs real, tenant-isolated data access.
 *
 * Tenant scoping
 * --------------
 * Every statement carries an explicit, parameterised `tenant_id` predicate
 * derived from {@see TenantContext} (the platform rule — there is no
 * query-rewriting layer). The SYSTEM tenant (id 0) is unscoped and sees all
 * tenants; every other tenant sees ONLY its own rows. Cross-tenant id probing
 * (update/delete/read of another tenant's row) reports 404 without touching
 * or leaking the foreign row. An unresolved tenant context fails closed (403).
 *
 * Authorization
 * -------------
 * The plugin's route declarations gate reads on `hello:view` and writes on
 * `hello:manage` (`requiredPermission`, enforced by the host's RbacMiddleware
 * since SDK 1.2) — this handler never runs for a caller without them.
 *
 * Wiring
 * ------
 * The PDO is injected by {@see \HelloWorld\HelloWorldPlugin}, which resolves
 * the host's shared lazy {@see \Whity\Database\Database} service from the
 * `\Whity` service container at request time — the host-side wiring seam,
 * analogous to the migration runner injecting a PDO into plugin migrations.
 * Tests construct the handler directly with an in-memory SQLite PDO.
 */
final class GreetingsApiHandler
{
    /**
     * The system tenant id; a caller resolved to it sees every tenant's rows.
     */
    private const SYSTEM_TENANT_ID = 0;

    /**
     * Maximum greeting message length (mirrors the VARCHAR(255) column).
     */
    private const MAX_MESSAGE_LENGTH = 255;

    private PDO $db;

    /**
     * @param PDO $db Live database connection.
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/hello/greetings — list the caller's greetings, newest first.
     *
     * @param Request $request The incoming request.
     * @return Response JSON `{ data: [{id, tenantId, message, createdAt}] }` (200) or an error.
     */
    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $stmt = $this->db->prepare(
                    'SELECT id, tenant_id, message, created_at FROM hello_greetings
                     ORDER BY created_at DESC, id DESC'
                );
                $stmt->execute();
            } else {
                $stmt = $this->db->prepare(
                    'SELECT id, tenant_id, message, created_at FROM hello_greetings
                     WHERE tenant_id = :tenant_id
                     ORDER BY created_at DESC, id DESC'
                );
                $stmt->execute([':tenant_id' => $tenantId]);
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(['data' => array_map([$this, 'toPublicGreeting'], $rows)], 200);
        } catch (\Throwable) {
            // Never leak internal exception details to clients.
            return Response::error('Failed to fetch greetings', 500);
        }
    }

    /**
     * POST /api/hello/greetings — create a greeting in the caller's tenant.
     *
     * @param Request $request The incoming request (`{message: string 1..255}`).
     * @return Response JSON `{ data: {id, tenantId, message, createdAt} }` (201) or an error.
     */
    public function create(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $message = $this->validatedMessage($request);
        if ($message === null) {
            return Response::error(
                'message must be a non-empty string of at most ' . self::MAX_MESSAGE_LENGTH . ' characters',
                400
            );
        }

        try {
            // The caller's tenant is stamped on the row — never client input.
            $stmt = $this->db->prepare(
                'INSERT INTO hello_greetings (tenant_id, message, created_at) VALUES (:tenant_id, :message, NOW())'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':message' => $message]);

            $id = (int) $this->db->lastInsertId();
            $row = $this->findScoped($id, $tenantId);
            if ($row === null) {
                return Response::error('Failed to create greeting', 500);
            }

            return Response::json(['data' => $this->toPublicGreeting($row)], 201);
        } catch (\Throwable) {
            return Response::error('Failed to create greeting', 500);
        }
    }

    /**
     * PATCH /api/hello/greetings/{id} — update a greeting in the caller's tenant.
     *
     * A row that does not exist IN THE CALLER'S TENANT is reported as 404:
     * cross-tenant id probing never reaches (or reveals) another tenant's row.
     *
     * @param Request $request The incoming request (`{message: string 1..255}`).
     * @param array<string, string> $params Route parameters (expects 'id').
     * @return Response JSON `{ data: {...} }` (200) or an error (400/403/404).
     */
    public function update(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $id = (int) ($params['id'] ?? 0);

        $message = $this->validatedMessage($request);
        if ($message === null) {
            return Response::error(
                'message must be a non-empty string of at most ' . self::MAX_MESSAGE_LENGTH . ' characters',
                400
            );
        }

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $stmt = $this->db->prepare('UPDATE hello_greetings SET message = :message WHERE id = :id');
                $stmt->execute([':message' => $message, ':id' => $id]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE hello_greetings SET message = :message WHERE id = :id AND tenant_id = :tenant_id'
                );
                $stmt->execute([':message' => $message, ':id' => $id, ':tenant_id' => $tenantId]);
            }

            if ($stmt->rowCount() === 0) {
                return Response::error('Greeting not found', 404);
            }

            $row = $this->findScoped($id, $tenantId);
            if ($row === null) {
                return Response::error('Greeting not found', 404);
            }

            return Response::json(['data' => $this->toPublicGreeting($row)], 200);
        } catch (\Throwable) {
            return Response::error('Failed to update greeting', 500);
        }
    }

    /**
     * DELETE /api/hello/greetings/{id} — delete a greeting in the caller's tenant.
     *
     * Same tenant-scoped 404 semantics as {@see update()}.
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (expects 'id').
     * @return Response JSON `{ data: {id, message} }` (200) or an error (403/404).
     */
    public function delete(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $id = (int) ($params['id'] ?? 0);

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $stmt = $this->db->prepare('DELETE FROM hello_greetings WHERE id = :id');
                $stmt->execute([':id' => $id]);
            } else {
                $stmt = $this->db->prepare(
                    'DELETE FROM hello_greetings WHERE id = :id AND tenant_id = :tenant_id'
                );
                $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
            }

            if ($stmt->rowCount() === 0) {
                return Response::error('Greeting not found', 404);
            }

            return Response::json(['data' => ['id' => $id, 'message' => 'Greeting deleted']], 200);
        } catch (\Throwable) {
            return Response::error('Failed to delete greeting', 500);
        }
    }

    /**
     * Fetch a greeting row by id, scoped to the caller's tenant.
     *
     * @param int $id The row id.
     * @param int $tenantId The caller's resolved tenant id (0 = unscoped).
     * @return array<string, mixed>|null The row, or null when not visible.
     */
    private function findScoped(int $id, int $tenantId): ?array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, message, created_at FROM hello_greetings WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, message, created_at FROM hello_greetings
                 WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Extract and validate the `message` field from the JSON request body.
     *
     * @param Request $request The incoming request.
     * @return string|null The valid message, or null when missing/invalid.
     */
    private function validatedMessage(Request $request): ?string
    {
        $body = json_decode($request->getBody(), true);
        $message = is_array($body) ? ($body['message'] ?? null) : null;

        if (!is_string($message) || trim($message) === '' || mb_strlen($message) > self::MAX_MESSAGE_LENGTH) {
            return null;
        }

        return $message;
    }

    /**
     * Shape a raw hello_greetings row into the public API contract.
     *
     * Casts integer-like columns (PDO returns them as strings under
     * PostgreSQL) and uses camelCase keys.
     *
     * @param array<string, mixed> $row A raw hello_greetings row.
     * @return array<string, mixed> The public entry.
     */
    private function toPublicGreeting(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenantId' => (int) ($row['tenant_id'] ?? 0),
            'message' => (string) ($row['message'] ?? ''),
            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
        ];
    }
}
