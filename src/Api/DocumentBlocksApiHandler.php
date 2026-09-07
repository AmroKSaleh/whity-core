<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentBlockRepository;
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
 * Tenant-scoped, RBAC-gated CRUD for reusable document/label blocks
 * (WC-521) — mirrors {@see DocumentTemplatesApiHandler} exactly.
 *
 *   GET    /api/document-blocks        → list()   (documents:read)
 *   POST   /api/document-blocks        → create() (documents:write)
 *   GET    /api/document-blocks/{id}   → show()   (documents:read)
 *   PATCH  /api/document-blocks/{id}   → update() (documents:write)
 *   DELETE /api/document-blocks/{id}   → delete() (documents:write)
 *
 * The route permission is the baseline gate; on top of it list/get are
 * ROW-FILTERED server-side by {@see DocumentAccessPolicy} — including, since
 * migration 117, the caller's OU REACH against the block's `owner_ou_id`
 * ({@see OuReachResolver}); an unplaced block stays tenant-wide as before —
 * system=all, tenant/global=required_permission), so a caller only ever
 * receives blocks it may see (a hidden row 404s — never 403 — on show/update/
 * delete). Publishing a block tenant-wide/global or attaching a
 * required_permission tag additionally requires documents:publish.
 *
 * REFERENCE-INTEGRITY DELETE GUARD (the reason blocks need a handler distinct
 * from templates): a block is POINTER-referenced by templates via a
 * `blockInstance` element ({type:'blockInstance', blockId}) — Gutenberg
 * synced-pattern semantics, edits propagate live to every instance. Deleting a
 * block any template still references would orphan that pointer, so delete()
 * refuses with 409 when {@see DocumentTemplateRepository::referencesBlock()}
 * finds a live reference anywhere in the tenant's templates.
 *
 * `data` is the verbatim client DocElement[] fragment. Error bodies are
 * generic (WC-186 — never $e->getMessage()).
 */
final class DocumentBlocksApiHandler
{
    private DocumentBlockRepository $repo;
    private DocumentTemplateRepository $templateRepo;
    private DocumentAccessPolicy $policy;
    private RoleChecker $roleChecker;
    private OuReachResolver $ouReach;

    public function __construct(
        DocumentBlockRepository $repo,
        DocumentTemplateRepository $templateRepo,
        DocumentAccessPolicy $policy,
        RoleChecker $roleChecker,
        OuReachResolver $ouReach,
    ) {
        $this->repo = $repo;
        $this->templateRepo = $templateRepo;
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
            return Response::error('Block not found', 404);
        }

        return Response::json(['data' => $row]);
    }

    /**
     * GET /api/document-blocks/{id}/usage — WHAT WOULD BREAK if this block
     * changed or went away.
     *
     * A block is POINTER-referenced with Gutenberg synced-pattern semantics, so
     * editing one propagates to every template that instances it. Delete has a
     * guard ({@see self::delete()}'s 409); EDIT has none and can have none — it
     * is a legitimate action whose whole purpose is to propagate. The only
     * safeguard available is therefore an informed publisher, and that needs a
     * number and some names BEFORE the edit, not an error after it.
     *
     * WHY `total` IS NOT ROW-FILTERED, AND WHY THAT IS THE POINT
     * ---------------------------------------------------------
     * `templates` is filtered by {@see DocumentAccessPolicy} — a caller is never
     * handed the identity of a template it may not see. `total` is NOT: it counts
     * every referencing template in the tenant, and `hidden` is the difference.
     *
     * A visible-only count would be WORSE THAN NO COUNT, which is the reason this
     * endpoint is shaped this way rather than reusing filterVisible() for both
     * numbers. A department secretary reaches one department; a block she may
     * edit can be instanced by templates across the whole faculty. Told "used by
     * 2 templates" she edits with confidence and silently rewrites seven
     * documents she cannot see. Told "used by 9, of which you can see 2" she
     * knows the edit leaves her blast radius.
     *
     * The disclosure is a COUNT, scoped to the caller's own tenant, of rows they
     * already hold documents:read on at the route. No name, no placement, no
     * permission tag — nothing that narrows down WHICH rows. self::delete()
     * already discloses strictly more (its 409 proves at least one such row
     * exists) and has since WC-521; this replaces "something you cannot see says
     * no" with a number, which is the same fact stated usefully.
     *
     * A block the caller may not see 404s, exactly as {@see self::show()} does —
     * you cannot ask about the usage of a row whose existence is withheld.
     *
     * @param array<string, string> $params
     */
    public function usage(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $id = (int) ($params['id'] ?? 0);
        $block = $this->repo->findById($id, $tenantId);
        $has = $this->permissionResolver($callerId, $tenantId);
        $reaches = $this->ouReach->reachFor($tenantId, $callerId);
        if ($block === null || !$this->policy->canView($block, $callerId, $has, $reaches)) {
            return Response::error('Block not found', 404);
        }

        $referencing = $this->templateRepo->referencingTemplates($id, $tenantId);
        $visible = $this->policy->filterVisible($referencing, $callerId, $has, $reaches);

        // Blocks may now nest blocks (#1186 slice 3), so the templates are no
        // longer the whole answer. A block held only by another block would
        // report NO users here and then be refused with a 409 by delete() —
        // the client/server disagreement the reference scanners are deliberately
        // kept in parity to avoid, arrived at from the other side.
        //
        // Filtered through the same policy as the templates: a viewer must not
        // learn the names of blocks they cannot see, and `total` counts what
        // exists while `hidden` says how much of it is being withheld.
        $nesting = $this->repo->referencingBlocks($id, $tenantId);
        $visibleNesting = $this->policy->filterVisible($nesting, $callerId, $has, $reaches);

        return Response::json(['data' => [
            'block_id'  => $id,
            'total'     => count($referencing) + count($nesting),
            'hidden'    => (count($referencing) - count($visible))
                + (count($nesting) - count($visibleNesting)),
            'blocks'    => array_map(
                static fn (array $row): array => [
                    'id'                  => $row['id'],
                    'name'                => $row['name'],
                    'scope'               => $row['scope'],
                    'required_permission' => $row['required_permission'],
                    'owner_ou_id'         => $row['owner_ou_id'],
                    'is_system'           => $row['is_system'],
                    'updated_at'          => $row['updated_at'],
                ],
                $visibleNesting,
            ),
            'templates' => array_map(
                static fn (array $row): array => [
                    'id'                  => $row['id'],
                    'name'                => $row['name'],
                    'scope'               => $row['scope'],
                    'required_permission' => $row['required_permission'],
                    'owner_ou_id'         => $row['owner_ou_id'],
                    'is_system'           => $row['is_system'],
                    'updated_at'          => $row['updated_at'],
                ],
                $visible,
            ),
        ]]);
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
            return Response::error('data must be a non-empty element list', 422);
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
            return Response::error('Publishing a shared block requires documents:publish', 403);
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
            return Response::error('Block not found', 404);
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
                return Response::error('data must be a non-empty element list', 422);
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
        // that merely mentions it. See the twin in DocumentTemplatesApiHandler.
        //
        // This bites HARDER here, because the designer's block save has always
        // sent the whole object back, `scope` included (web/lib/documents/
        // blocks.ts). Presence was therefore permanently true on this path, so
        // an author holding documents:manage but not documents:publish could not
        // save ANY edit to a tenant-wide or global block — including the seeded
        // sys-header/sys-footer, the two blocks a tenant is most likely to want
        // corrected. The 403 blamed publishing for a save that published
        // nothing.
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
            return Response::error('Publishing a shared block requires documents:publish', 403);
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
            return Response::error('Block not found', 404);
        }

        // Reference-integrity guard (WC-521): refuse rather than orphan a live
        // blockInstance pointer held by any template in the tenant.
        if ($this->templateRepo->referencesBlock($id, $tenantId)) {
            return Response::error('Cannot delete a block that is still referenced by a template', 409);
        }

        // The same guard for the other holder of a pointer (#1186 slice 3).
        // Blocks may now contain blocks, so "no template uses it" stopped being
        // the whole question: a logo used only by the letterhead BLOCK would
        // have passed the check above, been deleted, and left the letterhead
        // pointing at a row that no longer exists.
        if ($this->repo->referencesBlock($id, $tenantId)) {
            return Response::error('Cannot delete a block that is still nested inside another block', 409);
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
     * block that quietly belongs nowhere is worse than an error, for the reason
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
