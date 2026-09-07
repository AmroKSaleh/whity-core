<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Tenant-scoped, RBAC-gated CRUD for document/label templates (WC-docdesigner).
 *
 *   GET    /api/document-templates        → list()   (documents:read)
 *   POST   /api/document-templates        → create() (documents:write)
 *   GET    /api/document-templates/{id}   → show()   (documents:read)
 *   PATCH  /api/document-templates/{id}   → update() (documents:write)
 *   DELETE /api/document-templates/{id}   → delete() (documents:write)
 *
 * The route permission is the baseline gate; on top of it list/get are
 * ROW-FILTERED server-side by {@see DocumentAccessPolicy}, so a caller only ever
 * receives templates it may see. Two independent predicates, both of which must
 * pass on a shared row:
 *
 *   WHAT KIND of person — the row's `required_permission` (migration 059);
 *   WHERE they act     — the row's `owner_ou_id` against the caller's reach
 *                        (migration 117, {@see OuReachResolver}), which is what
 *                        lets a dean's secretary and a department head's
 *                        secretary holding the SAME documents:write see
 *                        different template sets. An unplaced row is
 *                        tenant-wide, exactly as before 117.
 *
 * Publishing a template tenant-wide/global, attaching a required_permission tag,
 * or filing it at a unit additionally requires documents:publish. `data` is the
 * verbatim client DocTemplate JSON. Error bodies are generic (WC-186 — never
 * $e->getMessage()).
 */
final class DocumentTemplatesApiHandler
{
    private DocumentTemplateRepository $repo;
    private DocumentAccessPolicy $policy;
    private RoleChecker $roleChecker;
    private OuReachResolver $ouReach;

    public function __construct(
        DocumentTemplateRepository $repo,
        DocumentAccessPolicy $policy,
        RoleChecker $roleChecker,
        OuReachResolver $ouReach,
    ) {
        $this->repo = $repo;
        $this->policy = $policy;
        $this->roleChecker = $roleChecker;
        $this->ouReach = $ouReach;
    }

    public function list(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $rows = $this->repo->listForTenant($tenantId);
        $visible = $this->policy->filterVisible(
            $rows,
            $callerId,
            $this->permissionResolver($callerId, $tenantId),
            $this->ouReach->reachFor($tenantId, $callerId),
        );

        return Response::json(['data' => $visible]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $row = $this->repo->findById((int) ($params['id'] ?? 0), $tenantId);
        // 404 (not 403) when hidden — never reveal the existence of a gated row.
        if ($row === null || !$this->policy->canView(
            $row,
            $callerId,
            $this->permissionResolver($callerId, $tenantId),
            $this->ouReach->reachFor($tenantId, $callerId),
        )) {
            return Response::error('Template not found', 404);
        }

        return Response::json(['data' => $row]);
    }

    public function create(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $body = JsonBody::parsed($request);
        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '') {
            return Response::error('name is required', 422);
        }
        if (!array_key_exists('data', $body) || !is_array($body['data']) || $body['data'] === []) {
            return Response::error('data must be a non-empty template object', 422);
        }
        $scope = $this->normalizeScope($body['scope'] ?? null);
        if ($scope === null) {
            return Response::error('scope must be one of: ' . implode(', ', DocumentAccessPolicy::SCOPES), 422);
        }
        $requiredPermission = $this->normalizeRequiredPermission($body['required_permission'] ?? null);

        $ownerOuId = null;
        if (array_key_exists('owner_ou_id', $body)) {
            $parsed = $this->parseOwnerOu($body['owner_ou_id'], $tenantId);
            if ($parsed instanceof Response) {
                return $parsed;
            }
            $ownerOuId = $parsed;
        }

        // Publishing (tenant/global scope, or a permission tag) needs documents:publish.
        $has = $this->permissionResolver($callerId, $tenantId);
        if ($this->policy->needsPublish($scope, $requiredPermission, $ownerOuId)
            && !$has(CorePermissions::DOCUMENTS_PUBLISH)) {
            return Response::error('Publishing a shared template requires documents:publish', 403);
        }

        $id = $this->repo->create($tenantId, [
            'name'                => $name,
            'data'                => $body['data'],
            'scope'               => $scope,
            'required_permission' => $requiredPermission,
            'created_by'          => $callerId,
            'owner_ou_id'         => $ownerOuId,
        ]);

        return Response::json(['data' => $this->repo->findById($id, $tenantId)], 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $id = (int) ($params['id'] ?? 0);
        $row = $this->repo->findById($id, $tenantId);
        if ($row === null || !$this->policy->canView(
            $row,
            $callerId,
            $this->permissionResolver($callerId, $tenantId),
            $this->ouReach->reachFor($tenantId, $callerId),
        )) {
            return Response::error('Template not found', 404);
        }

        $body = JsonBody::parsed($request);
        $fields = [];
        if (array_key_exists('name', $body)) {
            $name = trim((string) $body['name']);
            if ($name === '') {
                return Response::error('name cannot be empty', 422);
            }
            $fields['name'] = $name;
        }
        if (array_key_exists('data', $body)) {
            if (!is_array($body['data']) || $body['data'] === []) {
                return Response::error('data must be a non-empty template object', 422);
            }
            $fields['data'] = $body['data'];
        }
        if (array_key_exists('scope', $body)) {
            $scope = $this->normalizeScope($body['scope']);
            if ($scope === null) {
                return Response::error('scope must be one of: ' . implode(', ', DocumentAccessPolicy::SCOPES), 422);
            }
            $fields['scope'] = $scope;
        }
        if (array_key_exists('required_permission', $body)) {
            $fields['required_permission'] = $this->normalizeRequiredPermission($body['required_permission']);
        }
        if (array_key_exists('owner_ou_id', $body)) {
            $parsed = $this->parseOwnerOu($body['owner_ou_id'], $tenantId);
            if ($parsed instanceof Response) {
                return $parsed;
            }
            $fields['owner_ou_id'] = $parsed;
        }
        if ($fields === []) {
            return Response::error('No updatable fields supplied', 422);
        }

        // Changing scope/required_permission/placement into a shared state is a publish action.
        $targetScope = $fields['scope'] ?? $row['scope'];
        $targetPerm = array_key_exists('required_permission', $fields) ? $fields['required_permission'] : $row['required_permission'];
        $targetOu = array_key_exists('owner_ou_id', $fields)
            ? $fields['owner_ou_id']
            : ($row['owner_ou_id'] ?? null);

        // A publish action is one that CHANGES a sharing attribute — not one
        // that merely mentions it.
        //
        // This used to be `array_key_exists(...)` on the three keys, which made
        // the gate fire on presence. A client that sends the whole object back
        // (which is what an editor with a visibility control does, echoing the
        // value the server itself just gave it) was then refused a save it had
        // changed nothing about: an author without documents:publish could open
        // a tenant template they were allowed to edit, fix a typo, and lose the
        // edit to a 403 about publishing.
        //
        // Comparing to the stored row keeps the gate exactly as strict for real
        // promotions and stops it from firing on a no-op. Loose `!=` on the OU
        // deliberately: it arrives parsed as int|null and the column may hand
        // back a numeric string.
        $becomesShared = (array_key_exists('scope', $fields) && $fields['scope'] !== $row['scope'])
            || (array_key_exists('required_permission', $fields)
                && $fields['required_permission'] !== $row['required_permission'])
            || (array_key_exists('owner_ou_id', $fields)
                && $fields['owner_ou_id'] != ($row['owner_ou_id'] ?? null));
        if ($becomesShared
            && $this->policy->needsPublish(
                is_string($targetScope) ? $targetScope : null,
                is_string($targetPerm) ? $targetPerm : null,
                is_int($targetOu) ? $targetOu : null
            )
            && !$this->permissionResolver($callerId, $tenantId)(CorePermissions::DOCUMENTS_PUBLISH)) {
            return Response::error('Publishing a shared template requires documents:publish', 403);
        }

        $this->repo->update($id, $tenantId, $fields);

        return Response::json(['data' => $this->repo->findById($id, $tenantId)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $id = (int) ($params['id'] ?? 0);
        $row = $this->repo->findById($id, $tenantId);
        if ($row === null || !$this->policy->canView(
            $row,
            $callerId,
            $this->permissionResolver($callerId, $tenantId),
            $this->ouReach->reachFor($tenantId, $callerId),
        )) {
            return Response::error('Template not found', 404);
        }

        $this->repo->delete($id, $tenantId);

        return Response::json([], 204);
    }

    /**
     * Resolve (tenantId, callerProfileId) or an early error Response.
     *
     * @return array{0: int, 1: int}|Response
     */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }

    /**
     * A resolver over the caller's EFFECTIVE permission set in the tenant, able
     * to be asked AT one organizational unit instead of tenant-wide.
     *
     * Delegated to {@see ScopedPermissionSet} rather than built here: six
     * handlers in this subsystem carried byte-identical copies of it, and the
     * unit-scoped second argument had to exist in all six at once — a copy left
     * on the one-argument version would DISCARD the unit and silently answer the
     * tenant-wide question, PHP passing surplus arguments to a closure without
     * complaint. That class also records why the raw resolved set is used rather
     * than the registry-gated check.
     *
     * @return callable(string, int|null=): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }

    /**
     * Validate a client-supplied placement, or explain why it is not one.
     *
     * Returns the unit id, `null` for an explicit tenant-wide placement, or a
     * 422 Response — the same `X|Response` shape {@see self::context()} uses.
     *
     * A unit from ANOTHER tenant is refused explicitly rather than left to fail
     * closed downstream. {@see \Whity\Core\Ou\OuSubtree} drops unknown roots, so
     * such a row would simply be reachable by nobody — but silently, and a
     * template that quietly belongs nowhere is worse than an error, for the reason
     * {@see \Whity\Api\DocumentsApiHandler} records about a quietly ignored OU
     * anchor.
     */
    private function parseOwnerOu(mixed $value, int $tenantId): int|null|Response
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return Response::error('owner_ou_id must be an organizational unit id, or null', 422);
        }

        $ouId = (int) $value;
        if ($ouId <= 0 || !$this->ouReach->existsInTenant($tenantId, $ouId)) {
            return Response::error('owner_ou_id is not an organizational unit of this tenant', 422);
        }

        return $ouId;
    }

    private function normalizeScope(mixed $scope): ?string
    {
        if ($scope === null) {
            return DocumentAccessPolicy::SCOPE_PERSONAL;
        }
        $scope = strtolower(trim((string) $scope));

        return in_array($scope, DocumentAccessPolicy::SCOPES, true) ? $scope : null;
    }

    private function normalizeRequiredPermission(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
