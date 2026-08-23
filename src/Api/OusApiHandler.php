<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Api\Exception\OuHierarchyCycleException;
use Whity\Auth\RoleChecker;
use Whity\Core\Ou\OuTypeRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Hooks\HookVetoException;
use PDO;

/**
 * Organizational Units API Handler
 *
 * Handles CRUD operations for organizational units (OUs) with parent-child
 * hierarchies, role assignments, and strict tenant isolation.
 *
 * Cache coherence
 * ---------------
 * OU role assignments feed a user's effective roles/permissions (WC-54), so any
 * mutation to those assignments ({@see self::assignRole()}, {@see self::removeRole()})
 * invalidates the worker-level effective-permission caches via
 * {@see RoleChecker::clearCache()}; otherwise an authorization check could keep
 * serving a stale resolved set after a grant was added or revoked.
 */
class OusApiHandler
{
    /**
     * The OU projection every read path returns, joined to `ou_types` (#822).
     *
     * The type is exposed as its KEY as well as its id. Returning only the
     * opaque per-tenant id would leave a consumer holding a number it cannot
     * interpret without a second call, which is precisely the parallel
     * unit-id → kind map this feature exists to delete. The label rides along so
     * a picker can be rendered from one request.
     *
     * Requires the `ou` / `t` aliases the read paths declare.
     */
    private const LIST_COLUMNS = 'SELECT ou.id, ou.tenant_id, ou.parent_id, ou.name, ou.slug,'
        . ' ou.description, ou.created_at, ou.ou_type_id,'
        . ' t.type_key AS ou_type_key, t.label AS ou_type_label';

    /**
     * Longest disambiguating suffix tried before a slug is declared unassignable.
     *
     * A bound rather than an unbounded loop: a pathological tenant must not turn
     * one create into thousands of round trips. Reaching it is not a real
     * scenario — it needs a thousand sibling-legal units whose names all reduce
     * to one slug — so the ceiling exists to make the failure mode a 409 rather
     * than a hang.
     */
    private const SLUG_MAX_ATTEMPTS = 1000;

    private PDO $db;
    private HookManager $hookManager;

    public function __construct(PDO $db, HookManager $hookManager)
    {
        $this->db = $db;
        $this->hookManager = $hookManager;
    }

    /**
     * GET /api/ous - List OUs for the current tenant (paginated).
     *
     * Supports `?parent_id=` to narrow the list to one OU's DIRECT children
     * (`parent_id=0` selects the roots). The parameter used to be accepted and
     * discarded, which is the worst of both worlds: the caller believes it
     * filtered and cannot tell an ignored filter from one that matched
     * everything. A malformed value is now a 422 rather than a silent
     * full-tenant read.
     *
     * Supports `?type=` to narrow the list to one KIND of unit (#822) —
     * "every faculty", not "every unit at depth 1", which returns a different
     * kind of thing on every installation. `?type=none` selects the units that
     * carry no type at all. Same validation contract as `parent_id`: a malformed
     * key is a 422, never a silent full-tenant read. A well-formed key this
     * tenant has not defined matches nothing and returns an empty page — that is
     * a correct answer, not an error, and it lets a consumer probe for a type
     * without learning anything about other tenants.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            $p = PaginationParams::fromPath($request->getPath());

            $query = self::queryParams($request);
            $parentId = null;
            if (isset($query['parent_id']) && $query['parent_id'] !== '') {
                if (!ctype_digit($query['parent_id'])) {
                    return Response::error('parent_id must be a non-negative integer', 422);
                }
                $parentId = (int) $query['parent_id'];
            }

            $typeKey = null;
            if (isset($query['type']) && $query['type'] !== '') {
                if (!OuTypeRegistry::isValidKey($query['type'])) {
                    return Response::error(
                        'type must be a lowercase type key, optionally namespaced as plugin:slug, '
                        . "or '" . OuTypeRegistry::UNTYPED . "' for units with no type",
                        422
                    );
                }
                $typeKey = $query['type'];
            }

            $filters = self::parentClause($parentId) . self::typeClause($typeKey);
            $bindParent = $parentId !== null && $parentId !== 0;
            $bindType = $typeKey !== null && $typeKey !== OuTypeRegistry::UNTYPED;

            if ($tenantId === 0) {
                // @tenant-guard-ignore: system-tenant (id 0) lists OUs across all tenants; scoped else-branch binds tenant_id
                $countSql = 'SELECT COUNT(*) AS cnt FROM organizational_units ou'
                    . ' LEFT JOIN ou_types t ON t.id = ou.ou_type_id AND t.tenant_id = ou.tenant_id'
                    . ' WHERE 1 = 1' . $filters;
                $countStmt = $this->db->prepare($countSql);
            } else {
                $countSql = 'SELECT COUNT(*) AS cnt FROM organizational_units ou'
                    . ' LEFT JOIN ou_types t ON t.id = ou.ou_type_id AND t.tenant_id = ou.tenant_id'
                    . ' WHERE ou.tenant_id = :tenant_id' . $filters;
                $countStmt = $this->db->prepare($countSql);
                $countStmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            }
            if ($bindParent) {
                $countStmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
            }
            if ($bindType) {
                $countStmt->bindValue(':type_key', $typeKey, PDO::PARAM_STR);
            }
            $countStmt->execute();
            $countRow = $countStmt->fetch(PDO::FETCH_ASSOC);
            $total = $countRow !== false ? (int)($countRow['cnt'] ?? 0) : 0;

            if ($tenantId === 0) {
                // @tenant-guard-ignore: system-tenant (id 0) lists all OUs; scoped else-branch binds tenant_id
                $sql = self::LIST_COLUMNS . ' FROM organizational_units ou'
                    . ' LEFT JOIN ou_types t ON t.id = ou.ou_type_id AND t.tenant_id = ou.tenant_id'
                    . ' WHERE 1 = 1' . $filters
                    . ' ORDER BY ou.tenant_id, ou.id LIMIT :limit OFFSET :offset';
                $stmt = $this->db->prepare($sql);
            } else {
                $sql = self::LIST_COLUMNS . ' FROM organizational_units ou'
                    . ' LEFT JOIN ou_types t ON t.id = ou.ou_type_id AND t.tenant_id = ou.tenant_id'
                    . ' WHERE ou.tenant_id = :tenant_id' . $filters
                    . ' ORDER BY ou.id LIMIT :limit OFFSET :offset';
                $stmt = $this->db->prepare($sql);
                $stmt->bindValue(':tenant_id', $tenantId, PDO::PARAM_INT);
            }

            if ($bindParent) {
                $stmt->bindValue(':parent_id', $parentId, PDO::PARAM_INT);
            }
            if ($bindType) {
                $stmt->bindValue(':type_key', $typeKey, PDO::PARAM_STR);
            }
            $stmt->bindValue(':limit', $p->perPage, PDO::PARAM_INT);
            $stmt->bindValue(':offset', $p->offset, PDO::PARAM_INT);
            $stmt->execute();

            $ous = array_map(
                [self::class, 'withTypeFields'],
                $stmt->fetchAll(PDO::FETCH_ASSOC)
            );

            return Response::json(['data' => $ous, 'pagination' => $p->meta($total)], 200);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch organizational units', 500);
        }
    }

    /**
     * The `AND ...` fragment implementing the `parent_id` filter, or '' for no
     * filter.
     *
     * Kept out of {@see self::list()} on purpose: the tenant-predicate scanner
     * stitches SQL fragments assigned within one function into the statement
     * that consumes them, which would pull this clause into the system-tenant
     * queries and push their first line above their `@tenant-guard-ignore`
     * annotations. Returning it from a separate method keeps each statement's
     * literal — and its annotation — adjacent.
     *
     * @param int|null $parentId Parsed filter: null for none, 0 for the roots.
     */
    private static function parentClause(?int $parentId): string
    {
        if ($parentId === null) {
            return '';
        }

        // No OU can have id 0, so 0 is free to mean "the roots" — otherwise a
        // query string has no way to express parent_id IS NULL.
        return $parentId === 0 ? ' AND ou.parent_id IS NULL' : ' AND ou.parent_id = :parent_id';
    }

    /**
     * The `AND ...` fragment implementing the `?type=` filter, or '' for none.
     *
     * A separate method for the same reason as {@see self::parentClause()}: the
     * tenant-predicate scanner stitches fragments assigned within one function
     * into the statement that consumes them.
     *
     * The filter matches on the type KEY rather than its id, because the key is
     * the stable, cross-installation identifier a consumer's rule is written
     * against; the id is per tenant and means nothing outside it. The reserved
     * `none` sentinel selects the units that carry no type — a query string has
     * no other way to express `IS NULL`, exactly as `parent_id=0` exists to
     * express it for the roots.
     *
     * @param string|null $typeKey Validated key, the untyped sentinel, or null.
     */
    private static function typeClause(?string $typeKey): string
    {
        if ($typeKey === null) {
            return '';
        }

        return $typeKey === OuTypeRegistry::UNTYPED
            ? ' AND ou.ou_type_id IS NULL'
            : ' AND t.type_key = :type_key';
    }

    /**
     * Read the request's query parameters.
     *
     * Merges $_GET (the runtime source, since FrankenPHP strips the query off
     * the path) with anything embedded in the path, path last — the same
     * precedence PaginationParams uses, so a test that puts params in the path
     * and production traffic resolve identically.
     *
     * @return array<string, string>
     */
    private static function queryParams(Request $request): array
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

    /**
     * POST /api/ous - Create a new organizational unit
     */
    public function create(Request $request): Response
    {
        try {
            $body = JsonBody::parsed($request);
            $tenantId = TenantContext::getTenantId();

            // Validation: name is required
            if (empty($body['name'])) {
                return Response::error('Organizational unit name is required', 400);
            }

            $name = $body['name'];
            $parentId = $body['parent_id'] ?? null;
            $description = $body['description'] ?? '';

            // Bound the free-text fields before any DB write: name is VARCHAR(255),
            // description is an otherwise-unbounded TEXT column.
            if ($tooLong = InputLimits::firstViolation([
                'name' => [(string) $name, InputLimits::NAME_MAX],
                'description' => [(string) $description, InputLimits::TEXT_MAX],
            ])) {
                return $tooLong;
            }

            // Parent validation: if parent_id supplied, it must exist and belong to current tenant
            if ($parentId !== null) {
                $checkStmt = $this->db->prepare(
                    'SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?'
                );
                $checkStmt->execute([$parentId, $tenantId]);
                if (!$checkStmt->fetch()) {
                    return Response::error('Parent organizational unit does not belong to current tenant', 403);
                }
            }

            // Type resolution (#822). Accepts either the FK or the stable key;
            // an unknown or foreign type is refused rather than stored as null.
            $type = $this->resolveRequestedType($body, $tenantId);
            if ($type instanceof Response) {
                return $type;
            }
            $ouTypeId = $type['id'];

            // Name uniqueness is scoped to the SIBLING SET, not the tenant
            // (#822, migration 103): two faculties may each hold a Computer
            // Science department, and forbidding that was the constraint being
            // reported, not a rule anyone wanted.
            if ($this->siblingNameTaken($tenantId, self::asNullableInt($parentId), (string) $name, null)) {
                return Response::error(
                    'An organizational unit with this name already exists under the same parent',
                    409
                );
            }

            // The slug stays UNIQUE PER TENANT (see migration 103's docblock: it
            // is URL identity, and scoping it to siblings would mean no URL
            // resolves without the full ancestor path). Two sibling-legal units
            // therefore have to share a name and not a slug, so the second one is
            // disambiguated rather than refused — otherwise relaxing the name
            // rule would have achieved nothing, the 409 simply moving from the
            // name check to the slug check.
            $slug = $this->uniqueSlug($tenantId, (string) $name, null);
            if ($slug === null) {
                return Response::error('Could not derive a unique slug for this name', 409);
            }

            // Dispatch filter hook before creating OU
            $ouData = $this->hookManager->dispatch('ou.creating', [
                'name' => $name,
                'parent_id' => $parentId,
                'description' => $description,
                'slug' => $slug,
                'ou_type_id' => $ouTypeId,
            ]);

            // Extract potentially modified data from hook response
            $name = $ouData['name'];
            $parentId = $ouData['parent_id'] ?? $parentId;
            $description = $ouData['description'] ?? $description;
            $slug = $ouData['slug'] ?? $slug;
            $ouTypeId = array_key_exists('ou_type_id', $ouData)
                ? self::asNullableInt($ouData['ou_type_id'])
                : $ouTypeId;

            // Insert OU
            $stmt = $this->db->prepare('
                INSERT INTO organizational_units
                    (tenant_id, parent_id, name, slug, description, ou_type_id, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ');
            $stmt->execute([$tenantId, $parentId, $name, $slug, $description, $ouTypeId]);
            $ouId = $this->db->lastInsertId();

            // Dispatch synchronous hook after OU is created
            $this->hookManager->dispatch('ou.created', [
                'id' => (int)$ouId,
                'tenant_id' => $tenantId,
                'name' => $name,
                'slug' => $slug,
                'parent_id' => $parentId,
                'ou_type_id' => $ouTypeId,
            ]);

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('ou.created.async', [
                'id' => (int)$ouId,
                'tenant_id' => $tenantId,
                'name' => $name,
            ]);

            // Resolved AFTER the hook: a listener may have retyped the unit, and
            // echoing the pre-hook key would describe a row that was never
            // written.
            return Response::json([
                'data' => [
                    'id' => (int)$ouId,
                    'tenant_id' => $tenantId,
                    'parent_id' => $parentId,
                    'name' => $name,
                    'slug' => $slug,
                    'description' => $description,
                    'ou_type_id' => $ouTypeId,
                    'created_at' => date('Y-m-d H:i:s')
                ] + $this->typeFields($tenantId, $ouTypeId)
            ], 201);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] create failed: ' . $e->getMessage());
            return Response::error('Failed to create organizational unit', 500);
        }
    }

    /**
     * GET /api/ous/{id} - Get a specific organizational unit with children
     */
    public function get(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Get OU
            $stmt = $this->db->prepare(
                self::LIST_COLUMNS . '
                FROM organizational_units ou
                LEFT JOIN ou_types t ON t.id = ou.ou_type_id AND t.tenant_id = ou.tenant_id
                WHERE ou.id = ? AND ou.tenant_id = ?
            ');
            $stmt->execute([$id, $tenantId]);
            $ou = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ou) {
                return Response::error('Organizational unit not found', 404);
            }
            $ou = self::withTypeFields($ou);

            // Get children
            $childrenStmt = $this->db->prepare('
                SELECT id
                FROM organizational_units
                WHERE parent_id = ? AND tenant_id = ?
            ');
            $childrenStmt->execute([$id, $tenantId]);
            $children = $childrenStmt->fetchAll(PDO::FETCH_ASSOC);
            $ou['children'] = $children;

            return Response::json(['data' => $ou], 200);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] get failed: ' . $e->getMessage());
            return Response::error('Failed to fetch organizational unit', 500);
        }
    }

    /**
     * PATCH /api/ous/{id} - Update an organizational unit
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            $body = JsonBody::parsed($request);

            // Get OU to update
            $stmt = $this->db->prepare('
                SELECT * FROM organizational_units WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$id, $tenantId]);
            $ou = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$ou) {
                return Response::error('Organizational unit not found or does not belong to current tenant', 403);
            }

            // Dispatch filter hook before updating OU
            $this->hookManager->dispatch('ou.updating', [
                'id' => (int)$id,
                'changes' => $body,
            ]);

            $updates = [];
            $params_array = [];

            // Bound the free-text fields present in the body before the write.
            if ($tooLong = InputLimits::firstViolation([
                'name' => [isset($body['name']) ? (string) $body['name'] : null, InputLimits::NAME_MAX],
                'description' => [isset($body['description']) ? (string) $body['description'] : null, InputLimits::TEXT_MAX],
            ])) {
                return $tooLong;
            }

            // Handle description update
            if (isset($body['description']) && $body['description'] !== $ou['description']) {
                $updates[] = 'description = ?';
                $params_array[] = $body['description'];
            }

            // Handle parent_id update. Use array_key_exists (not isset) so an
            // explicit `null` — "move to root" from the picker — is honoured;
            // isset() is false for null and would silently drop the change.
            // Compare as nullable ints so the int (JSON body) vs string (PDO
            // column) representations of the same parent are not seen as a diff.
            $currentParentId = $ou['parent_id'] === null ? null : (int)$ou['parent_id'];
            $requestedParentId = array_key_exists('parent_id', $body) && $body['parent_id'] !== null
                ? (int)$body['parent_id']
                : null;
            $parentChanged = array_key_exists('parent_id', $body) && $requestedParentId !== $currentParentId;

            if ($parentChanged) {
                $newParentId = $requestedParentId;

                // Validate parent exists in same tenant
                if ($newParentId !== null) {
                    $parentStmt = $this->db->prepare(
                        'SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?'
                    );
                    $parentStmt->execute([$newParentId, $tenantId]);
                    if (!$parentStmt->fetch()) {
                        return Response::error('Parent organizational unit does not belong to current tenant', 403);
                    }

                    // Detect cycle: if the new parent is this OU or one of its
                    // descendants, reject the move with a typed domain error
                    // (translated to 422 below). This guards the hierarchy
                    // independently of the UI's move-picker (defense in depth).
                    if ($this->wouldCreateCycle((int)$id, (int)$newParentId, $tenantId)) {
                        throw OuHierarchyCycleException::forMove((int)$id, (int)$newParentId);
                    }
                }

                $updates[] = 'parent_id = ?';
                $params_array[] = $newParentId;
            }

            // Sibling-name check (#822). Both a rename AND a re-parent can move a
            // unit into a sibling set that already holds its name, so the check
            // runs against the TARGET pair rather than only when the name is the
            // thing being edited — a pure re-parent that collides would otherwise
            // hit the partial unique index and surface as a 500 instead of the
            // 409 it is.
            $targetName = isset($body['name']) ? (string) $body['name'] : (string) $ou['name'];
            $targetParentId = $parentChanged ? $requestedParentId : $currentParentId;
            $nameChanged = isset($body['name']) && $body['name'] !== $ou['name'];

            if (($nameChanged || $parentChanged)
                && $this->siblingNameTaken($tenantId, $targetParentId, $targetName, (int) $id)
            ) {
                return Response::error(
                    'An organizational unit with this name already exists under the same parent',
                    409
                );
            }

            // Handle name update — regenerate the slug when the name changes.
            // Disambiguated for the same reason as on create: the slug stays
            // unique per TENANT while names are only unique among siblings, so
            // the derived slug can legitimately already be taken.
            if ($nameChanged) {
                $updates[] = 'name = ?';
                $params_array[] = $body['name'];

                $newSlug = $this->uniqueSlug($tenantId, (string) $body['name'], (int) $id);
                if ($newSlug === null) {
                    return Response::error('Could not derive a unique slug for this name', 409);
                }
                $updates[] = 'slug = ?';
                $params_array[] = $newSlug;
            }

            // Handle type update (#822). array_key_exists, not isset, so an
            // explicit null — "this unit has no kind after all" — is honoured
            // rather than silently dropped.
            $type = $this->resolveRequestedType($body, $tenantId);
            if ($type instanceof Response) {
                return $type;
            }
            if ($type['present']) {
                $updates[] = 'ou_type_id = ?';
                $params_array[] = $type['id'];
            }

            if (!empty($updates)) {
                // WC-190: the UPDATE itself carries the tenant predicate, not just
                // the prior guard SELECT, so a cross-tenant id can never mutate
                // another tenant's OU even if the guard were bypassed (TOCTOU).
                $this->updateOuScoped((int)$id, $updates, $params_array, $tenantId);
            }

            // Dispatch synchronous hook after OU is updated
            $this->hookManager->dispatch('ou.updated', [
                'id' => (int)$id,
                'changes' => $body,
            ]);

            // Dispatch asynchronous hook for background tasks
            $this->hookManager->dispatchAsync('ou.updated.async', [
                'id' => (int)$id,
            ]);

            return Response::json(['data' => ['id' => (int)$id, 'message' => 'Organizational unit updated']], 200);
        } catch (OuHierarchyCycleException $e) {
            // Re-parenting under self/descendant: a client error, not a server
            // fault. 422 (Unprocessable Entity) — the request is well-formed but
            // semantically invalid; no row was changed.
            error_log(sprintf(
                '[ous] rejected cyclic re-parent: tenant_id=%s ou_id=%s',
                var_export(TenantContext::getTenantId(), true),
                var_export($params['id'] ?? null, true)
            ));
            return Response::error('Setting this parent would create a cycle in the hierarchy', 422);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] update failed: ' . $e->getMessage());
            return Response::error('Failed to update organizational unit', 500);
        }
    }

    /**
     * DELETE /api/ous/{id} - Delete an organizational unit
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Get OU
            $stmt = $this->db->prepare('
                SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$id, $tenantId]);
            if (!$stmt->fetch()) {
                return Response::error('Organizational unit not found or does not belong to current tenant', 403);
            }

            // Check if OU has children
            $childrenStmt = $this->db->prepare('
                SELECT COUNT(*) FROM organizational_units WHERE parent_id = ? AND tenant_id = ?
            ');
            $childrenStmt->execute([$id, $tenantId]);
            $childCount = $childrenStmt->fetchColumn();
            if ($childCount > 0) {
                return Response::error(
                    'Cannot delete organizational unit with ' . $childCount . ' child organizational unit(s)',
                    409
                );
            }

            // Check if OU has ACTIVE assigned members (ROLE data: ou_id now lives on
            // memberships — ADR 0005 §3; the tenant predicate is on memberships.tenant_id).
            // Only active memberships block deletion — an OU whose only members are
            // invited/suspended has no active occupants and can be deleted.
            $usersStmt = $this->db->prepare("
                SELECT COUNT(DISTINCT profile_id) FROM memberships WHERE ou_id = ? AND tenant_id = ? AND status = 'active'
            ");
            $usersStmt->execute([$id, $tenantId]);
            $userCount = $usersStmt->fetchColumn();
            if ($userCount > 0) {
                return Response::error(
                    'Cannot delete organizational unit with ' . $userCount . ' assigned member(s)',
                    409
                );
            }

            // Refuse while designer rows are still FILED here (migration 117).
            //
            // `document_templates.owner_ou_id` / `document_blocks.owner_ou_id`
            // carry ON DELETE SET NULL, the house convention for a nullable OU
            // reference — and here that convention is the wrong semantic on its
            // own: those columns are the WHERE half of a visibility rule, so
            // letting the constraint fire would turn a delete into a silent
            // WIDENING, republishing a faculty's templates to the whole tenant.
            //
            // A third 409 beside the children and members ones, rather than a
            // cascade or an automatic re-filing to the parent: re-filing guesses
            // at an audience the operator never chose, and both alternatives act
            // where they could instead ask. The message names the count so the
            // operator knows there is something to move, mirroring the two
            // guards above. The constraint stays as the backstop for anything
            // that deletes a unit by another route, where a widened-but-present
            // row beats a pointer to a unit that no longer exists.
            $placedStmt = $this->db->prepare("
                SELECT
                    (SELECT COUNT(*) FROM document_templates WHERE owner_ou_id = ? AND tenant_id = ?)
                  + (SELECT COUNT(*) FROM document_blocks    WHERE owner_ou_id = ? AND tenant_id = ?)
                    AS placed
            ");
            $placedStmt->execute([$id, $tenantId, $id, $tenantId]);
            $placedCount = (int) $placedStmt->fetchColumn();
            if ($placedCount > 0) {
                return Response::error(
                    'Cannot delete organizational unit with ' . $placedCount
                    . ' document template(s)/block(s) filed against it',
                    409
                );
            }

            // WC-713: the `ou.deleting` hook, the DELETE, and the `ou.deleted`
            // hook run inside ONE transaction. Previously the DELETE committed on
            // its own and the cleanup hook fired afterwards, so a listener that
            // failed left the caller with a 500 AND a committed delete — with no
            // way to veto or undo it. Core tables survive that because they carry
            // real ON DELETE CASCADE constraints; a PLUGIN's tables have no FK to
            // organizational_units, so this hook is the only cleanup mechanism
            // they have and it must be atomic with the row it is cleaning up
            // after.
            $ownTransaction = !$this->db->inTransaction();
            if ($ownTransaction) {
                $this->db->beginTransaction();
            }

            try {
                // Filter hook BEFORE the delete — the row is still readable here,
                // so this is where a listener reads whatever it needs. It is also
                // the veto point: HookVetoException aborts with nothing written.
                $this->hookManager->dispatch('ou.deleting', [
                    'id' => (int)$id,
                ]);

                // Delete OU
                $deleteStmt = $this->db->prepare('DELETE FROM organizational_units WHERE id = ? AND tenant_id = ?');
                $deleteStmt->execute([$id, $tenantId]);

                // Synchronous cleanup hook, still INSIDE the transaction: a
                // listener that throws takes the delete down with it instead of
                // orphaning its own rows against a row that is already gone.
                $this->hookManager->dispatch('ou.deleted', [
                    'id' => (int)$id,
                ]);

                if ($ownTransaction) {
                    $this->db->commit();
                }
            } catch (\Throwable $e) {
                if ($ownTransaction && $this->db->inTransaction()) {
                    $this->db->rollBack();
                }
                throw $e;
            }

            // Durable/async notification only AFTER the delete has COMMITTED, so
            // the event spine can never announce a deletion that rolled back.
            // (Deliberately outside the transaction: dispatchAsync is non-critical
            // by contract — see HookManager — and a failure to persist the event
            // must not undo an otherwise-successful delete.)
            $this->hookManager->dispatchAsync('ou.deleted.async', [
                'id' => (int)$id,
            ]);

            return Response::json([], 204);
        } catch (HookVetoException $e) {
            // A plugin refused the deletion (or its cleanup failed). The
            // transaction above already rolled back, so the OU still exists —
            // 409 Conflict, matching the child/member guards. `reason()` is the
            // plugin's own client-safe text; the raw exception message is never
            // surfaced (WC-186).
            error_log(sprintf(
                '[ous] delete vetoed by a hook listener: tenant_id=%s ou_id=%s event=%s',
                var_export(TenantContext::getTenantId(), true),
                var_export($params['id'] ?? null, true),
                $e->eventName()
            ));
            return Response::error(
                'Cannot delete organizational unit: blocked by an installed plugin',
                409,
                ['reason' => $e->reason()]
            );
        } catch (\Exception $e) {
            error_log('[OusApiHandler] delete failed: ' . $e->getMessage());
            return Response::error('Failed to delete organizational unit', 500);
        }
    }

    /**
     * POST /api/ous/{id}/roles - Assign a role to an organizational unit
     */
    public function assignRole(Request $request, array $params): Response
    {
        try {
            $ouId = $params['id'] ?? null;
            if (!$ouId) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();
            $body = JsonBody::parsed($request);

            // Validation: role_id is required
            if (empty($body['role_id'])) {
                return Response::error('Role ID is required', 400);
            }

            $roleId = $body['role_id'];

            // Get OU - return 404 if not found in current tenant
            $stmt = $this->db->prepare('
                SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?
            ');
            $stmt->execute([$ouId, $tenantId]);
            if (!$stmt->fetch()) {
                return Response::error('Organizational unit not found', 404);
            }

            // Validate the role is visible to the caller's tenant before attaching
            // it (WC-56). Without this a tenant could attach another tenant's
            // private role to its own OU. Own roles and globals (NULL tenant_id)
            // are allowed, consistent with the WC-110 role-visibility model. A
            // role outside that set returns 404 (not 403) so cross-tenant role
            // existence is never disclosed.
            $roleStmt = $this->db->prepare('
                SELECT id FROM roles WHERE id = ? AND (tenant_id = ? OR tenant_id IS NULL)
            ');
            $roleStmt->execute([$roleId, $tenantId]);
            if (!$roleStmt->fetch()) {
                error_log(sprintf(
                    '[ous] denied role assignment: tenant_id=%s ou_id=%s role_id=%s',
                    var_export($tenantId, true),
                    var_export($ouId, true),
                    var_export($roleId, true)
                ));
                return Response::error('Role not found', 404);
            }

            // Insert role assignment
            try {
                $assignStmt = $this->db->prepare('
                    INSERT INTO ou_role_assignments (tenant_id, ou_id, role_id, created_at)
                    VALUES (?, ?, ?, NOW())
                ');
                $assignStmt->execute([$tenantId, $ouId, $roleId]);
                $assignmentId = $this->db->lastInsertId();

                // Invalidate the worker-level effective-permission caches: this new
                // OU role assignment changes the effective roles of every user in
                // the OU (and its descendants), so cached resolutions are now stale.
                RoleChecker::clearCache();

                // Dispatch hook after role assignment
                $this->hookManager->dispatch('ou.role_assigned', [
                    'id' => (int)$assignmentId,
                    'ou_id' => (int)$ouId,
                    'role_id' => (int)$roleId,
                    'tenant_id' => $tenantId,
                ]);

                return Response::json([
                    'data' => [
                        'id' => (int)$assignmentId,
                        'ou_id' => (int)$ouId,
                        'role_id' => (int)$roleId,
                        'tenant_id' => $tenantId,
                    ]
                ], 201);
            } catch (\PDOException $e) {
                // Check if it's a constraint violation (duplicate assignment or role not found)
                if (stripos($e->getMessage(), 'constraint') !== false || stripos($e->getMessage(), 'unique') !== false) {
                    return Response::error('Role assignment already exists or role does not exist', 409);
                }
                throw $e;
            }
        } catch (\Exception $e) {
            error_log('[OusApiHandler] assignRole failed: ' . $e->getMessage());
            return Response::error('Failed to assign role', 500);
        }
    }

    /**
     * DELETE /api/ous/{ouId}/roles/{roleId} - Remove a role from an organizational unit
     */
    public function removeRole(Request $request, array $params): Response
    {
        try {
            $ouId = $params['ouId'] ?? null;
            $roleId = $params['roleId'] ?? null;

            if (!$ouId || !$roleId) {
                return Response::error('Organizational unit ID and role ID are required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            // Get assignment
            $stmt = $this->db->prepare('
                SELECT id FROM ou_role_assignments
                WHERE ou_id = ? AND role_id = ? AND tenant_id = ?
            ');
            $stmt->execute([$ouId, $roleId, $tenantId]);
            if (!$stmt->fetch()) {
                return Response::error('Role assignment not found', 404);
            }

            // Delete assignment
            $deleteStmt = $this->db->prepare('
                DELETE FROM ou_role_assignments
                WHERE ou_id = ? AND role_id = ? AND tenant_id = ?
            ');
            $deleteStmt->execute([$ouId, $roleId, $tenantId]);

            // Invalidate the worker-level effective-permission caches: revoking an
            // OU role assignment changes the effective roles of every user in the
            // OU (and its descendants), so cached resolutions are now stale.
            RoleChecker::clearCache();

            // Dispatch hook after role removal
            $this->hookManager->dispatch('ou.role_removed', [
                'ou_id' => (int)$ouId,
                'role_id' => (int)$roleId,
                'tenant_id' => $tenantId,
            ]);

            return Response::json([], 204);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] removeRole failed: ' . $e->getMessage());
            return Response::error('Failed to remove role', 500);
        }
    }

    /**
     * GET /api/ous/{id}/roles - List the roles assigned to an organizational unit.
     *
     * Joins `ou_role_assignments` to `roles` and returns the assigned roles as
     * `{id, name, description}`. Tenant-scoped: the OU must be visible to the
     * caller (system tenant 0 sees every tenant's OU; any other tenant sees only
     * its own), otherwise a 404 is returned so an OU's existence in another
     * tenant is never disclosed.
     */
    public function roles(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            if (!$this->ouIsVisible((int)$id, $tenantId)) {
                return Response::error('Organizational unit not found', 404);
            }

            // The OU's tenant scopes the assignment lookup. For a non-system
            // tenant this equals $tenantId; for the system tenant we read the
            // OU's own tenant so assignments are matched on the same tenant_id
            // they were written with.
            // @tenant-guard-ignore: OU visibility already enforced by ouIsVisible($id,$tenantId) guard above; lookup keyed on the visible ou_id
            $assignmentStmt = $this->db->prepare('
                SELECT r.id, r.name, r.description
                FROM ou_role_assignments ora
                JOIN roles r ON r.id = ora.role_id
                WHERE ora.ou_id = ?
                ORDER BY r.name
            ');
            $assignmentStmt->execute([$id]);
            $rows = $assignmentStmt->fetchAll(PDO::FETCH_ASSOC);

            $data = array_map(
                static fn (array $row): array => [
                    'id' => (int)$row['id'],
                    'name' => (string)$row['name'],
                    'description' => (string)($row['description'] ?? ''),
                ],
                $rows
            );

            return Response::json(['data' => $data], 200);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] listRoles failed: ' . $e->getMessage());
            return Response::error('Failed to fetch organizational unit roles', 500);
        }
    }

    /**
     * GET /api/ous/{id}/members - List the members assigned to an organizational unit.
     *
     * Returns the members whose `ou_id` is this OU, shaped to the public user
     * contract ({id, name, email, role, tenantId, createdAt}) — credentials are
     * never included. Tenant-scoped exactly like {@see self::roles()}: a caller
     * that cannot see the OU receives a 404.
     *
     * IDENTITY data (email, display_name) is resolved via profile_emails → profiles
     * (ADR 0005 §1-2). ROLE/OU data (role_id, ou_id) is resolved via memberships
     * (ADR 0005 §3). The tenant predicate is on memberships.tenant_id; the OU
     * visibility guard above already proved the OU belongs to this tenant, so
     * the ou_id predicate combined with the tenant predicate cannot cross tenants.
     */
    public function members(Request $request, array $params): Response
    {
        try {
            $id = $params['id'] ?? null;
            if (!$id) {
                return Response::error('Organizational unit ID is required', 400);
            }

            $tenantId = TenantContext::getTenantId();

            if (!$this->ouIsVisible((int)$id, $tenantId)) {
                return Response::error('Organizational unit not found', 404);
            }

            // ROLE/IDENTITY data: memberships.ou_id + memberships.tenant_id scope the
            // set; profile_emails supplies the login email (IDENTITY, ADR 0005 §2);
            // roles supplies the role name (ROLE, ADR 0005 §3).
            // The tenant predicate is on memberships.tenant_id — the OU guard above
            // already enforced that this ou_id belongs to $tenantId.
            // Only ACTIVE members are listed, matching the active semantics of the
            // stat/list/delete-guard queries (invited/suspended members are excluded).
            $stmt = $this->db->prepare("
                SELECT m.id, pe.email, p.display_name, m.created_at, m.tenant_id,
                       m.profile_id, r.name AS role
                FROM memberships m
                JOIN profiles p ON p.id = m.profile_id
                JOIN profile_emails pe ON pe.profile_id = m.profile_id AND pe.is_primary = true
                JOIN roles r ON r.id = m.role_id
                WHERE m.ou_id = ? AND m.tenant_id = ? AND m.status = 'active'
                ORDER BY m.created_at DESC
            ");
            $stmt->execute([$id, $tenantId]);
            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $data = array_map(fn (array $row): array => $this->toPublicMember($row), $rows);

            return Response::json(['data' => $data], 200);
        } catch (\Exception $e) {
            error_log('[OusApiHandler] listMembers failed: ' . $e->getMessage());
            return Response::error('Failed to fetch organizational unit members', 500);
        }
    }

    /**
     * Whether an OU is visible to the acting tenant.
     *
     * The system tenant (id 0) can see every tenant's OU; any other tenant sees
     * only OUs it owns. Used by the read endpoints to return 404 (rather than
     * leaking existence) for OUs the caller may not access.
     */
    private function ouIsVisible(int $ouId, int $tenantId): bool
    {
        if ($tenantId === 0) {
            // @tenant-guard-ignore: system-tenant (id 0) lists OUs across all tenants; scoped else-branch binds tenant_id
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
     * Apply a scoped `UPDATE organizational_units` whose WHERE clause itself
     * carries the tenant boundary (WC-190), not merely a preceding guard SELECT.
     *
     * Convention: the SYSTEM tenant (id 0) is unscoped and may update any tenant's
     * OU; any other tenant is scoped with `AND tenant_id = ?` so a cross-tenant id
     * mutates zero rows even if the guard SELECT were bypassed. A null/unresolved
     * tenant updates nothing.
     *
     * @param int                $ouId     The OU id to update.
     * @param array<int, string> $sets     SQL `column = ?` assignment fragments.
     * @param array<int, mixed>  $values   Bound values for the assignment fragments.
     * @param int|null           $tenantId The acting tenant (0 = SYSTEM).
     * @return void
     */
    protected function updateOuScoped(int $ouId, array $sets, array $values, ?int $tenantId): void
    {
        if ($sets === []) {
            return;
        }

        $assignments = implode(', ', $sets);

        if ($tenantId === 0) {
            $sql = 'UPDATE organizational_units SET ' . $assignments . ' WHERE id = ?';
            $params = array_merge($values, [$ouId]);
        } elseif ($tenantId === null) {
            // No resolvable tenant: never mutate (use an impossible predicate).
            $sql = 'UPDATE organizational_units SET ' . $assignments . ' WHERE id = ? AND 1 = 0';
            $params = array_merge($values, [$ouId]);
        } else {
            $sql = 'UPDATE organizational_units SET ' . $assignments . ' WHERE id = ? AND tenant_id = ?';
            $params = array_merge($values, [$ouId, $tenantId]);
        }

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    /**
     * Map a membership+profile row to the public member API contract.
     *
     * IDENTITY data (email, display_name) comes from profiles/profile_emails
     * (ADR 0005 §1-2). The legacy `id` field is the membership id rather than
     * a users.id, and `name` prefers profiles.display_name over the email
     * local-part when a display name was set.
     *
     * @param array<string, mixed> $row Raw row from the memberships SELECT joining profiles/profile_emails/roles.
     * @return array{id: int, name: string, email: string, role: string, tenantId: int, createdAt: string|null}
     */
    private function toPublicMember(array $row): array
    {
        $email = (string)($row['email'] ?? '');
        $displayName = (string)($row['display_name'] ?? '');
        if ($displayName === '') {
            $localPart = strstr($email, '@', true);
            $displayName = ($localPart !== false && $localPart !== '') ? $localPart : $email;
        }

        return [
            'id' => (int)($row['profile_id'] ?? $row['id'] ?? 0),
            'name' => $displayName,
            'email' => $email,
            'role' => (string)($row['role'] ?? ''),
            'tenantId' => (int)($row['tenant_id'] ?? 0),
            'createdAt' => isset($row['created_at']) ? (string)$row['created_at'] : null,
        ];
    }

    /**
     * Resolve the OU type a write requests, from either `ou_type_id` or `type`.
     *
     * Two spellings because two callers need different ones: an admin UI holds
     * the id it just rendered in a picker, while an integration provisioning
     * units from outside knows only the stable KEY — which is the entire point
     * of the key existing. Supplying both is refused rather than silently
     * preferring one, since a client that disagrees with itself has a bug the
     * platform should not paper over.
     *
     * An unknown or foreign-tenant type is a 422, never a silent null: storing
     * "no type" in response to a request to set one is exactly the class of
     * silent failure that made `?parent_id=` worth fixing in #823.
     *
     * The tenant is nullable for the same reason every other statement in this
     * handler tolerates it: {@see TenantContext::getTenantId()} can be
     * unresolved, and binding null scopes the lookup to no rows — so an
     * unresolved tenant resolves no type and is refused, rather than being cast
     * to 0 and silently addressing the SYSTEM tenant's vocabulary.
     *
     * @param array<string, mixed> $body
     * @return Response|array{present: bool, id: int|null}
     */
    private function resolveRequestedType(array $body, ?int $tenantId): Response|array
    {
        $hasId = array_key_exists('ou_type_id', $body);
        $hasKey = array_key_exists('type', $body);

        if ($hasId && $hasKey) {
            return Response::error('Supply either ou_type_id or type, not both', 422);
        }
        if (!$hasId && !$hasKey) {
            return ['present' => false, 'id' => null];
        }

        $raw = $hasId ? $body['ou_type_id'] : $body['type'];
        if ($raw === null || $raw === '') {
            return ['present' => true, 'id' => null];
        }

        if ($hasId) {
            if (!is_int($raw) && !(is_string($raw) && ctype_digit($raw))) {
                return Response::error('ou_type_id must be an integer or null', 422);
            }
            $stmt = $this->db->prepare(
                'SELECT id FROM ou_types WHERE id = ? AND tenant_id = ?'
            );
            $stmt->execute([(int) $raw, $tenantId]);
            if ($stmt->fetchColumn() === false) {
                return Response::error('Organizational unit type does not belong to current tenant', 422);
            }

            return ['present' => true, 'id' => (int) $raw];
        }

        if (!is_string($raw) || !OuTypeRegistry::isValidKey($raw)) {
            return Response::error(
                'type must be a lowercase type key, optionally namespaced as plugin:slug',
                422
            );
        }

        $stmt = $this->db->prepare(
            'SELECT id FROM ou_types WHERE type_key = ? AND tenant_id = ?'
        );
        $stmt->execute([$raw, $tenantId]);
        $found = $stmt->fetchColumn();
        if ($found === false) {
            return Response::error(
                "This tenant has no organizational unit type '{$raw}'. Create it first via POST /api/ou-types.",
                422
            );
        }

        return ['present' => true, 'id' => (int) $found];
    }

    /**
     * The joined type fields for one already-resolved type id.
     *
     * Used by write paths, which build their response row by hand rather than
     * re-reading it, so the type they echo is resolved from the id actually
     * written.
     *
     * @return array{ou_type_key: string|null, ou_type_label: string|null}
     */
    private function typeFields(?int $tenantId, ?int $ouTypeId): array
    {
        if ($ouTypeId === null) {
            return ['ou_type_key' => null, 'ou_type_label' => null];
        }

        $stmt = $this->db->prepare(
            'SELECT type_key, label FROM ou_types WHERE id = ? AND tenant_id = ?'
        );
        $stmt->execute([$ouTypeId, $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'ou_type_key' => $row === false ? null : (string) $row['type_key'],
            'ou_type_label' => $row === false ? null : (string) $row['label'],
        ];
    }

    /**
     * Whether another unit in the SAME sibling set already carries this name.
     *
     * The roots are one sibling set (`parent_id IS NULL`), matching the pair of
     * partial unique indexes migration 103 installs — and matching them
     * deliberately, so the 409 the API returns and the constraint the database
     * enforces can never disagree about what a collision is.
     *
     * Written as two complete statements rather than one with a stitched-in
     * predicate: `parent_id = ?` cannot express IS NULL, and the
     * tenant-predicate scanner reads statement literals, so each one carries its
     * own visible `tenant_id = ?`.
     *
     * @param int|null $parentId  The sibling set, or null for the roots.
     * @param int|null $exceptId  A unit to exclude — the one being renamed.
     */
    private function siblingNameTaken(?int $tenantId, ?int $parentId, string $name, ?int $exceptId): bool
    {
        $except = $exceptId ?? 0;

        if ($parentId === null) {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM organizational_units
                  WHERE tenant_id = ? AND name = ? AND parent_id IS NULL AND id <> ?
                  LIMIT 1'
            );
            $stmt->execute([$tenantId, $name, $except]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT 1 FROM organizational_units
                  WHERE tenant_id = ? AND name = ? AND parent_id = ? AND id <> ?
                  LIMIT 1'
            );
            $stmt->execute([$tenantId, $name, $parentId, $except]);
        }

        return $stmt->fetchColumn() !== false;
    }

    /**
     * A slug for this name that no OTHER unit in the tenant holds.
     *
     * Necessary because the two uniqueness rules now differ: a name is unique
     * among siblings while a slug stays unique per tenant (migration 103
     * explains why the slug keeps the wider rule). So the second *Computer
     * Science* department is legal and its natural slug is not, and the choice
     * is between refusing the unit and disambiguating the slug. Refusing it
     * would have made relaxing the name rule pointless — the 409 would simply
     * have moved from the name check to the slug check — so the suffix is
     * appended: `computer-science`, `computer-science-2`, `computer-science-3`.
     *
     * Every candidate is gathered in ONE query rather than probed one at a time,
     * so a tenth sibling costs one round trip rather than ten.
     *
     * Returns null when no free slug was found within
     * {@see self::SLUG_MAX_ATTEMPTS}, which the caller reports as a 409.
     *
     * @param int|null $exceptId The unit being renamed, whose own slug is free to reuse.
     */
    private function uniqueSlug(?int $tenantId, string $name, ?int $exceptId): ?string
    {
        $base = $this->generateSlug($name);

        // A name written entirely in a non-Latin script — Arabic is a first-class
        // requirement here — reduces to the empty string, and an empty slug is
        // both a broken URL and an instant collision with the next such name.
        // Falling back to a generic stem keeps every unit addressable; the
        // disambiguating suffix below does the rest.
        if ($base === '') {
            $base = 'ou';
        }

        $except = $exceptId ?? 0;
        $stmt = $this->db->prepare(
            'SELECT slug FROM organizational_units
              WHERE tenant_id = ? AND id <> ? AND (slug = ? OR slug LIKE ?)'
        );
        // `_` and `%` are LIKE wildcards; generateSlug() emits only [a-z0-9-],
        // so the pattern below can contain neither and needs no escaping.
        $stmt->execute([$tenantId, $except, $base, $base . '-%']);

        $taken = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $slug) {
            $taken[(string) $slug] = true;
        }

        if (!isset($taken[$base])) {
            return $base;
        }
        for ($n = 2; $n <= self::SLUG_MAX_ATTEMPTS; $n++) {
            $candidate = $base . '-' . $n;
            if (!isset($taken[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Attach the type fields to a joined row, typed.
     *
     * The type columns are cast here while the surrounding columns are passed
     * through raw. That asymmetry is deliberate: PostgreSQL's PDO driver returns
     * every integer column as a string, so `id` and `tenant_id` have always been
     * strings on the wire despite the published schema calling them integers.
     * Retyping those is an API-visible change for every existing consumer and
     * belongs in its own change; a field introduced here has no such history and
     * is made trustworthy from the start, because a consumer binding a routing
     * rule to `ou_type_id` is the entire reason it exists.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function withTypeFields(array $row): array
    {
        $row['ou_type_id'] = self::asNullableInt($row['ou_type_id'] ?? null);
        $row['ou_type_key'] = isset($row['ou_type_key']) ? (string) $row['ou_type_key'] : null;
        $row['ou_type_label'] = isset($row['ou_type_label']) ? (string) $row['ou_type_label'] : null;

        return $row;
    }

    /**
     * Cast a nullable id that may arrive as an int, a numeric string (every
     * integer column under PostgreSQL's PDO driver) or null.
     */
    private static function asNullableInt(mixed $value): ?int
    {
        return ($value === null || $value === '') ? null : (int) $value;
    }

    /**
     * Generate a URL-friendly slug from a string
     */
    private function generateSlug(string $text): string
    {
        // Convert to lowercase
        $slug = strtolower($text);
        // Replace spaces with hyphens
        $slug = str_replace(' ', '-', $slug);
        // Remove special characters
        $slug = preg_replace('/[^a-z0-9-]/', '', $slug);
        // Replace multiple hyphens with single hyphen
        $slug = preg_replace('/-+/', '-', $slug);
        // Trim hyphens from start and end
        $slug = trim($slug, '-');
        return $slug;
    }

    /**
     * Determine whether setting `$newParentId` as the parent of `$ouId` would
     * create a cycle in the hierarchy.
     *
     * Walks up the ancestor chain starting from the proposed new parent. If the
     * OU being moved is encountered anywhere in that chain — including being the
     * proposed parent itself — the move would form a loop and is rejected.
     *
     * Type discipline: parent ids are read back from the database (which under
     * PostgreSQL's PDO driver yields integer columns as PHP strings), so each id
     * is normalised to `int` before comparison. The earlier implementation
     * compared a string id against the int `$ouId` with `===`, which never
     * matched a deeper descendant against real Postgres and let cyclic moves
     * through (it happened to pass on SQLite, which returns native ints — the
     * mocked/SQLite-vs-Postgres gap this guard now closes).
     *
     * A visited set bounds the walk so any pre-existing data corruption cannot
     * spin into an infinite loop.
     *
     * @param int $ouId        The OU being moved.
     * @param int $newParentId The proposed new parent.
     * @param int $tenantId    The acting tenant (scopes the traversal).
     * @return bool True if the move would create a cycle, false otherwise.
     */
    private function wouldCreateCycle(int $ouId, int $newParentId, int $tenantId): bool
    {
        $currentId = $newParentId;
        $visited = [];

        $stmt = $this->db->prepare(
            'SELECT parent_id FROM organizational_units WHERE id = ? AND tenant_id = ?'
        );

        while (true) {
            if ($currentId === $ouId) {
                return true;
            }

            // A repeated node means the existing data already contains a loop;
            // stop rather than spin forever.
            if (isset($visited[$currentId])) {
                return true;
            }
            $visited[$currentId] = true;

            $stmt->execute([$currentId, $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            // Reached a root (NULL parent) or an id outside the tenant: no cycle.
            if ($row === false || $row['parent_id'] === null) {
                return false;
            }

            $currentId = (int)$row['parent_id'];
        }
    }
}
