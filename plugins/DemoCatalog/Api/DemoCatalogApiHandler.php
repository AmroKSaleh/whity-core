<?php

declare(strict_types=1);

namespace DemoCatalog\Api;

use PDO;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * Tenant-scoped CRUD over the plugin's own `demo_catalog_items` table.
 *
 * The DB-backed half of the DemoCatalog pilot plugin (WC-features-pilot),
 * modeled directly on HelloWorld's GreetingsApiHandler. It exists to give the
 * new `packages/features` shared-component pilot a real, minimal, generic
 * list/detail resource to bind against — deliberately NOT modeled on any
 * specific downstream product's domain (see the plugin's own docblock).
 *
 * Tenant scoping
 * --------------
 * Every statement carries an explicit, parameterised `tenant_id` predicate
 * derived from {@see TenantContext}. The SYSTEM tenant (id 0) is unscoped and
 * sees all tenants; every other tenant sees ONLY its own rows. Cross-tenant id
 * probing (read of another tenant's row) reports 404 without leaking the
 * foreign row. An unresolved tenant context fails closed (403).
 *
 * Authorization
 * -------------
 * The plugin's route declarations gate reads on `demo_catalog:view` and
 * writes on `demo_catalog:manage` (`requiredPermission`, host-enforced) —
 * this handler never runs for a caller without them.
 */
final class DemoCatalogApiHandler
{
    private const SYSTEM_TENANT_ID = 0;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_DESCRIPTION_LENGTH = 2000;

    /** @var list<string> */
    private const VALID_STATUSES = ['active', 'archived'];

    private PDO $db;

    /** Set by {@see validatedInput()} on failure; read by callers to build the 400 response. */
    private string $lastValidationError = '';

    /**
     * @param PDO $db Live database connection.
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * GET /api/demo-catalog/items — list the caller's items, newest first.
     *
     * @param Request $request The incoming request.
     * @return Response JSON `{ data: [...] }` (200) or an error.
     */
    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                // @tenant-guard-ignore: system-tenant (id 0) branch — sees every tenant's items by design
                $stmt = $this->db->prepare(
                    'SELECT id, tenant_id, name, description, status, created_at, updated_at
                     FROM demo_catalog_items
                     ORDER BY created_at DESC, id DESC'
                );
                $stmt->execute();
            } else {
                $stmt = $this->db->prepare(
                    'SELECT id, tenant_id, name, description, status, created_at, updated_at
                     FROM demo_catalog_items
                     WHERE tenant_id = :tenant_id
                     ORDER BY created_at DESC, id DESC'
                );
                $stmt->execute([':tenant_id' => $tenantId]);
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(['data' => array_map([$this, 'toPublicItem'], $rows)], 200);
        } catch (\Throwable) {
            return Response::error('Failed to fetch items', 500);
        }
    }

    /**
     * GET /api/demo-catalog/items/{id} — fetch one item in the caller's tenant.
     *
     * @param Request $request The incoming request.
     * @param array<string, string> $params Route parameters (expects 'id').
     * @return Response JSON `{ data: {...} }` (200) or an error (403/404).
     */
    public function get(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $id = (int) ($params['id'] ?? 0);
        $row = $this->findScoped($id, $tenantId);
        if ($row === null) {
            return Response::error('Item not found', 404);
        }

        return Response::json(['data' => $this->toPublicItem($row)], 200);
    }

    /**
     * POST /api/demo-catalog/items — create an item in the caller's tenant.
     *
     * @param Request $request The incoming request.
     * @return Response JSON `{ data: {...} }` (201) or an error.
     */
    public function create(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $input = $this->validatedInput($request, requireName: true);
        if ($input === null) {
            return Response::error($this->lastValidationError, 400);
        }

        try {
            // The caller's tenant is stamped on the row — never client input.
            $stmt = $this->db->prepare(
                'INSERT INTO demo_catalog_items (tenant_id, name, description, status, created_at, updated_at)
                 VALUES (:tenant_id, :name, :description, :status, NOW(), NOW())'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':name' => $input['name'],
                ':description' => $input['description'],
                ':status' => $input['status'],
            ]);

            $id = (int) $this->db->lastInsertId();
            $row = $this->findScoped($id, $tenantId);
            if ($row === null) {
                return Response::error('Failed to create item', 500);
            }

            return Response::json(['data' => $this->toPublicItem($row)], 201);
        } catch (\Throwable) {
            return Response::error('Failed to create item', 500);
        }
    }

    /**
     * PATCH /api/demo-catalog/items/{id} — update an item in the caller's tenant.
     *
     * A row that does not exist IN THE CALLER'S TENANT is reported as 404:
     * cross-tenant id probing never reaches (or reveals) another tenant's row.
     *
     * @param Request $request The incoming request.
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

        $input = $this->validatedInput($request, requireName: true);
        if ($input === null) {
            return Response::error($this->lastValidationError, 400);
        }

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                // @tenant-guard-ignore: system-tenant (id 0) branch — may update any tenant's item by design
                $stmt = $this->db->prepare(
                    'UPDATE demo_catalog_items
                     SET name = :name, description = :description, status = :status, updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute([
                    ':name' => $input['name'],
                    ':description' => $input['description'],
                    ':status' => $input['status'],
                    ':id' => $id,
                ]);
            } else {
                $stmt = $this->db->prepare(
                    'UPDATE demo_catalog_items
                     SET name = :name, description = :description, status = :status, updated_at = NOW()
                     WHERE id = :id AND tenant_id = :tenant_id'
                );
                $stmt->execute([
                    ':name' => $input['name'],
                    ':description' => $input['description'],
                    ':status' => $input['status'],
                    ':id' => $id,
                    ':tenant_id' => $tenantId,
                ]);
            }

            if ($stmt->rowCount() === 0) {
                return Response::error('Item not found', 404);
            }

            $row = $this->findScoped($id, $tenantId);
            if ($row === null) {
                return Response::error('Item not found', 404);
            }

            return Response::json(['data' => $this->toPublicItem($row)], 200);
        } catch (\Throwable) {
            return Response::error('Failed to update item', 500);
        }
    }

    /**
     * Fetch an item row by id, scoped to the caller's tenant.
     *
     * @param int $id The row id.
     * @param int $tenantId The caller's resolved tenant id (0 = unscoped).
     * @return array<string, mixed>|null The row, or null when not visible.
     */
    private function findScoped(int $id, int $tenantId): ?array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            // @tenant-guard-ignore: system-tenant (id 0) branch — may read any tenant's item by design
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, name, description, status, created_at, updated_at
                 FROM demo_catalog_items WHERE id = :id'
            );
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT id, tenant_id, name, description, status, created_at, updated_at
                 FROM demo_catalog_items WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * Extract and validate `name`/`description`/`status` from the JSON body.
     *
     * @param Request $request The incoming request.
     * @param bool $requireName Whether `name` must be present and non-empty.
     * @return array{name: string, description: ?string, status: string}|null Valid input, or null (see {@see $lastValidationError}).
     */
    private function validatedInput(Request $request, bool $requireName): ?array
    {
        $body = json_decode($request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $name = $body['name'] ?? null;
        if ($requireName) {
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                $this->lastValidationError = 'name must be a non-empty string of at most '
                    . self::MAX_NAME_LENGTH . ' characters';
                return null;
            }
        }

        $description = $body['description'] ?? null;
        if ($description !== null) {
            if (!is_string($description) || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH) {
                $this->lastValidationError = 'description must be a string of at most '
                    . self::MAX_DESCRIPTION_LENGTH . ' characters';
                return null;
            }
        }

        $status = $body['status'] ?? 'active';
        if (!is_string($status) || !in_array($status, self::VALID_STATUSES, true)) {
            $this->lastValidationError = 'status must be one of: ' . implode(', ', self::VALID_STATUSES);
            return null;
        }

        return [
            'name' => (string) $name,
            'description' => $description,
            'status' => $status,
        ];
    }

    /**
     * Shape a raw demo_catalog_items row into the public API contract.
     *
     * @param array<string, mixed> $row A raw demo_catalog_items row.
     * @return array<string, mixed> The public entry.
     */
    private function toPublicItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenantId' => (int) ($row['tenant_id'] ?? 0),
            'name' => (string) ($row['name'] ?? ''),
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'status' => (string) ($row['status'] ?? 'active'),
            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }
}
