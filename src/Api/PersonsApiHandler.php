<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\JsonBody;
use Whity\Core\Tenant\TenantContext;

/**
 * Persons API Handler (WC-65 — Family Relations Management System).
 *
 * CRUD over the `persons` graph-node table, plus a node's relations listing.
 * RBAC-gated by the router (`relations:read` for reads, `relations:manage` for
 * writes) and tenant-scoped in every method via {@see TenantContext}: the system
 * tenant (id 0) sees/acts across all tenants; every other tenant sees only its
 * own persons.
 *
 * No raw SQL lives here — all persistence goes through {@see PersonRepository}
 * and {@see RelationRepository} (project convention). The handler is FrankenPHP
 * worker-safe: it holds no request state in statics.
 */
class PersonsApiHandler
{
    private PersonRepository $persons;
    private RelationRepository $relations;

    /**
     * @param PersonRepository   $persons   Person data access.
     * @param RelationRepository $relations Relation data access (for the relations listing + counts).
     */
    public function __construct(PersonRepository $persons, RelationRepository $relations)
    {
        $this->persons = $persons;
        $this->relations = $relations;
    }

    /**
     * GET /api/persons — list/search persons visible to the current tenant.
     *
     * Query: `search` (optional display-name substring). Each row carries a
     * `relationCount` for the list view.
     *
     * @param Request $request The incoming request.
     * @return Response JSON list under `data`.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $search = $this->queryParam($request, 'search');
            $rows = $this->persons->list($tenantId, $search);

            $data = array_map(
                fn (array $row): array => $this->toPublic(
                    $row,
                    $this->relations->listForPerson((int) $row['id'], $tenantId)
                ),
                $rows
            );

            return Response::json(['data' => $data], 200);
        } catch (\Exception $e) {
            error_log('[PersonsApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch persons', 500);
        }
    }

    /**
     * POST /api/persons — create a non-user relative.
     *
     * Body: `{displayName: string, birthDate?: string|null, deceased?: bool,
     * notes?: string|null}`. A person created here is never linked to a user
     * (user shadows are auto-provisioned only by the relation resolver).
     *
     * @param Request $request The incoming request.
     * @return Response JSON created person (201) or an error.
     */
    public function create(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }
            // The system tenant has no concrete tenant to own a new person.
            if ($tenantId === 0) {
                return Response::error('Select a specific tenant to create a person', 400);
            }

            $body = JsonBody::parsed($request);

            $displayName = isset($body['displayName']) ? trim((string) $body['displayName']) : '';
            if ($displayName === '') {
                return Response::error('displayName is required', 400);
            }

            $personId = $this->persons->insert(
                $tenantId,
                $displayName,
                null,
                $this->nullableString($body, 'birthDate'),
                $this->boolField($body, 'deceased'),
                $this->nullableString($body, 'notes')
            );

            $person = $this->persons->findById($personId, $tenantId);
            if ($person === null) {
                return Response::error('Failed to load created person', 500);
            }

            return Response::json(['data' => $this->toPublic($person, [])], 201);
        } catch (\Exception $e) {
            error_log('[PersonsApiHandler] create failed: ' . $e->getMessage());
            return Response::error('Failed to create person', 500);
        }
    }

    /**
     * GET /api/persons/{id} — one person, with its relation count.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON person under `data`, or 404.
     */
    public function get(Request $request, array $params): Response
    {
        try {
            $id = $this->intParam($params, 'id');
            if ($id === null) {
                return Response::error('Person ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $person = $this->persons->findById($id, $tenantId);
            if ($person === null) {
                return Response::error('Person not found', 404);
            }

            $relations = $this->relations->listForPerson($id, $tenantId);

            return Response::json(['data' => $this->toPublic($person, $relations)], 200);
        } catch (\Exception $e) {
            error_log('[PersonsApiHandler] get failed: ' . $e->getMessage());
            return Response::error('Failed to fetch person', 500);
        }
    }

    /**
     * PATCH /api/persons/{id} — edit a non-user relative.
     *
     * A person linked to a user (a shadow) cannot be edited here — its identity
     * follows the user. Body may carry any of `displayName`, `birthDate`,
     * `deceased`, `notes`.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON confirmation (200) or an error.
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $id = $this->intParam($params, 'id');
            if ($id === null) {
                return Response::error('Person ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $person = $this->persons->findById($id, $tenantId);
            if ($person === null) {
                return Response::error('Person not found', 404);
            }

            if ($person['user_id'] !== null) {
                return Response::error(
                    'This person is linked to a user account and cannot be edited here',
                    409
                );
            }

            $body = JsonBody::parsed($request);

            $fields = [];
            if (array_key_exists('displayName', $body)) {
                $name = trim((string) $body['displayName']);
                if ($name === '') {
                    return Response::error('displayName cannot be empty', 400);
                }
                $fields['display_name'] = $name;
            }
            if (array_key_exists('birthDate', $body)) {
                $fields['birth_date'] = $this->nullableString($body, 'birthDate');
            }
            if (array_key_exists('deceased', $body)) {
                $fields['deceased'] = (bool) $body['deceased'];
            }
            if (array_key_exists('notes', $body)) {
                $fields['notes'] = $this->nullableString($body, 'notes');
            }

            $this->persons->update($id, $tenantId, $fields);

            $updated = $this->persons->findById($id, $tenantId);
            if ($updated === null) {
                return Response::error('Failed to load updated person', 500);
            }

            return Response::json(['data' => $this->toPublic($updated, [])], 200);
        } catch (\Exception $e) {
            error_log('[PersonsApiHandler] update failed: ' . $e->getMessage());
            return Response::error('Failed to update person', 500);
        }
    }

    /**
     * DELETE /api/persons/{id} — delete a non-user relative (cascades its edges).
     *
     * GUARDED: a person linked to a user account cannot be deleted here — removing
     * a person who has a login is a user-management operation, not a relations
     * one. A non-user person deletes and its relation edges cascade.
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response 204 on success, or an error.
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $this->intParam($params, 'id');
            if ($id === null) {
                return Response::error('Person ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            $person = $this->persons->findById($id, $tenantId);
            if ($person === null) {
                return Response::error('Person not found', 404);
            }

            if ($person['user_id'] !== null) {
                return Response::error(
                    'This person is linked to a user account and cannot be deleted here',
                    409
                );
            }

            $this->persons->delete($id, $tenantId);

            return Response::json([], 204);
        } catch (\Exception $e) {
            error_log('[PersonsApiHandler] delete failed: ' . $e->getMessage());
            return Response::error('Failed to delete person', 500);
        }
    }

    /**
     * GET /api/persons/{id}/relations — a node's relations (reciprocal-derived).
     *
     * @param Request              $request The incoming request.
     * @param array<string, mixed> $params  Route params (expects `id`).
     * @return Response JSON relations under `data`, or 404 when the person is not visible.
     */
    public function relations(Request $request, array $params): Response
    {
        try {
            $id = $this->intParam($params, 'id');
            if ($id === null) {
                return Response::error('Person ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 400);
            }

            // 404 (not 200 with []) when the person is invisible, so cross-tenant
            // existence is never disclosed.
            if ($this->persons->findById($id, $tenantId) === null) {
                return Response::error('Person not found', 404);
            }

            $relations = $this->relations->listForPerson($id, $tenantId);

            return Response::json(['data' => array_map([$this, 'toPublicRelation'], $relations)], 200);
        } catch (\Exception $e) {
            error_log('[PersonsApiHandler] relations failed: ' . $e->getMessage());
            return Response::error('Failed to fetch relations', 500);
        }
    }

    /**
     * Shape a normalised person row + its relations into the public camelCase
     * contract consumed by the web UI.
     *
     * @param array<string, mixed>                  $row       Normalised person row.
     * @param array<int, array<string, mixed>>      $relations Reciprocal-derived relations.
     * @return array<string, mixed>
     */
    private function toPublic(array $row, array $relations): array
    {
        return [
            'id' => (int) $row['id'],
            'tenantId' => (int) $row['tenant_id'],
            'displayName' => (string) $row['display_name'],
            'userId' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'hasAccount' => $row['user_id'] !== null,
            'birthDate' => $row['birth_date'] !== null ? (string) $row['birth_date'] : null,
            'deceased' => (bool) $row['deceased'],
            'notes' => $row['notes'] !== null ? (string) $row['notes'] : null,
            'createdAt' => $row['created_at'] !== null ? (string) $row['created_at'] : null,
            'relationCount' => count($relations),
            'relations' => array_map([$this, 'toPublicRelation'], $relations),
        ];
    }

    /**
     * Shape a reciprocal-derived relation entry into the public API contract.
     *
     * @param array<string, mixed> $relation
     * @return array<string, mixed>
     */
    private function toPublicRelation(array $relation): array
    {
        return [
            'relationId' => (int) $relation['relationId'],
            'otherPersonId' => (int) $relation['otherPersonId'],
            'otherPersonName' => (string) $relation['otherPersonName'],
            'otherPersonHasAccount' => $relation['otherPersonUserId'] !== null,
            'typeId' => (int) $relation['typeId'],
            'typeName' => (string) $relation['typeName'],
            'direction' => (string) $relation['direction'],
        ];
    }

    /**
     * Read a single query parameter from the request path.
     */
    private function queryParam(Request $request, string $name): ?string
    {
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (!is_string($query) || $query === '') {
            return null;
        }

        $params = [];
        parse_str($query, $params);

        return isset($params[$name]) && is_string($params[$name]) ? $params[$name] : null;
    }

    /**
     * Extract a positive integer route parameter, or null when missing/invalid.
     *
     * @param array<string, mixed> $params
     */
    private function intParam(array $params, string $name): ?int
    {
        $value = $params[$name] ?? null;

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    /**
     * Read an optional string body field, returning null for absent/empty/null.
     *
     * @param array<string, mixed> $body
     */
    private function nullableString(array $body, string $key): ?string
    {
        if (!array_key_exists($key, $body) || $body[$key] === null) {
            return null;
        }
        $value = trim((string) $body[$key]);

        return $value === '' ? null : $value;
    }

    /**
     * Read an optional boolean body field (default false).
     *
     * @param array<string, mixed> $body
     */
    private function boolField(array $body, string $key): bool
    {
        return array_key_exists($key, $body) && (bool) $body[$key];
    }
}
