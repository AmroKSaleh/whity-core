<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\InvalidResourceTypeException;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;
use Whity\Core\RBAC\RoleNotVisibleException;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The WRITE path for resource-scoped role grants (WC-712 §3).
 *
 *  - POST   /api/resource-role-grants   {resource_type, resource_id, role_id, profile_id?}
 *  - GET    /api/resource-role-grants?resource_type=T&resource_id=N
 *  - DELETE /api/resource-role-grants/{id}
 *
 * Why this exists
 * ---------------
 * §2 shipped RESOLUTION: `PermissionResolver` can answer "does this profile
 * hold this role AT this record?" and a plugin can reach it through the SDK.
 * Nothing could write the row that answer reads, so a consumer could ask the
 * platform about authority while still storing that authority in its own
 * table. That is two sources of truth for one question — strictly worse than
 * keeping one private table, and the reason the feature could not be adopted
 * at all. These three routes are the missing half.
 *
 * The grant shapes, and why `profile_id` is nullable
 * --------------------------------------------------
 *  - `profile_id` NULL — everyone reaching this resource holds role R here.
 *  - `profile_id` set  — this ONE profile holds role R here.
 *
 * Both are creatable and both are distinguishable when listing. An OMITTED
 * `profile_id` and an explicit `null` mean the same thing (the everyone-grant);
 * there is deliberately no third state, because "unspecified" would have to
 * resolve to one of the two anyway and silently picking one is how a caller
 * ends up granting broader authority than it asked for.
 *
 * What is validated at this boundary
 * ----------------------------------
 * 1. `resource_type` against {@see ResourceTypeRegistry} — an unregistered type
 *    is a 422. Storing one would create authority that nothing ever resolves:
 *    a grant that fails CLOSED but is invisible to the operator who wrote it.
 * 2. `resource_id` against the CALLER's tenant — see
 *    {@see self::resourceExistsInTenant()}. The column carries no foreign key,
 *    so nothing else stops a grant being addressed at another tenant's record
 *    or at an id that does not exist.
 * 3. `role_id` visibility — delegated to the repository, which allows the
 *    tenant's own roles and globals only, so a resource grant cannot become a
 *    way to attach another tenant's private role. Reported as 404, never 403,
 *    so cross-tenant role existence is never disclosed.
 * 4. `profile_id` membership in the caller's tenant.
 *
 * The `tenant_id` written is always the CALLER's, never anything from the
 * request body, so even a row that slipped past the checks above could not be
 * read back by the tenant-scoped resolver.
 *
 * A grant WIDENS authority; it never replaces membership
 * ------------------------------------------------------
 * Enforced at READ time in {@see \Whity\Auth\RoleChecker}: resolution returns
 * nothing at all unless the profile holds an ACTIVE membership in the tenant,
 * so a grant can only add roles for someone already there. Nothing in this
 * handler may weaken that, which is why granting is gated on tenant membership
 * here too rather than on the mere existence of a profile.
 *
 * No UI ships with this
 * ---------------------
 * This is a platform capability consumed by plugins over HTTP, in the same way
 * `/api/entity-tags` is. A core admin screen would have to enumerate resource
 * types and render a picker per type, which only the owning plugin can do.
 */
final class ResourceRoleGrantsApiHandler
{
    /**
     * Filter hook a plugin answers to vouch for one of its own records.
     *
     * Payload: `{tenant_id, resource_type, resource_id, exists}`. The owner sets
     * `exists` to true when the record is real AND belongs to that tenant.
     */
    public const VERIFY_RESOURCE_HOOK = 'rbac.resource_grant.verify_resource';

    public function __construct(
        private readonly PDO $db,
        private readonly ResourceRoleAssignmentRepository $repository,
        private readonly ResourceTypeRegistry $resourceTypes,
        private readonly RoleChecker $roleChecker,
        private readonly HookManager $hooks,
    ) {
    }

    /**
     * POST /api/resource-role-grants — grant a role at one resource.
     *
     * IDEMPOTENT by design, mirroring POST /api/users/{id}/memberships: a repeat
     * grant answers 200 with `created: false` and the id of the row that already
     * says it, rather than 409. A conflict would force every caller to treat
     * "the thing you asked for is already true" as an error and hand-roll a
     * read-before-write, which races against a concurrent grant anyway.
     */
    public function create(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::ROLES_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        $tenantId = $auth;

        // Always an array: a malformed or empty body decodes to [], which then
        // fails the required-field checks below as a 422 rather than a 500.
        $body = JsonBody::parsed($request);

        $resourceType = trim((string) ($body['resource_type'] ?? ''));
        if ($resourceType === '' || !$this->resourceTypes->exists($resourceType)) {
            return Response::error(
                'resource_type is required and must be a registered resource type',
                422,
                ['resource_type' => $resourceType]
            );
        }

        $resourceId = self::positiveInt($body['resource_id'] ?? null);
        if ($resourceId === null) {
            return Response::error('resource_id is required and must be a positive integer', 422);
        }

        $roleId = self::positiveInt($body['role_id'] ?? null);
        if ($roleId === null) {
            return Response::error('role_id is required and must be a positive integer', 422);
        }

        // Absent and explicit-null are the SAME request: the everyone-grant.
        $profileId = null;
        if (isset($body['profile_id'])) {
            $profileId = self::positiveInt($body['profile_id']);
            if ($profileId === null) {
                return Response::error('profile_id must be a positive integer or null', 422);
            }
        }

        if (!$this->resourceExistsInTenant($tenantId, $resourceType, $resourceId)) {
            return Response::error('Resource not found', 404);
        }

        // The beneficiary must already be in the tenant. A grant is a widening of
        // authority for someone who is here, so a row naming an outsider could
        // only ever be dead weight the resolver refuses to honour — and an
        // operator reading the table would have no way to tell it apart from a
        // grant that works.
        if ($profileId !== null && !$this->profileBelongsToTenant($profileId, $tenantId)) {
            return Response::error('Profile not found', 404);
        }

        try {
            $newId = $this->repository->grant($tenantId, $resourceType, $resourceId, $roleId, $profileId);
        } catch (InvalidResourceTypeException) {
            return Response::error('resource_type is not a registered resource type', 422);
        } catch (RoleNotVisibleException) {
            // 404, not 403: a tenant must not learn that another tenant's role id
            // exists by being told it is forbidden.
            return Response::error('Role not found', 404);
        }

        $created = $newId !== null;
        $grantId = $newId ?? $this->repository->findGrantId(
            $tenantId,
            $resourceType,
            $resourceId,
            $roleId,
            $profileId
        );

        if ($grantId === null) {
            // The row was neither inserted nor found: a concurrent revoke landed
            // between the two statements. Reporting success would name an id that
            // no longer exists, so fail loudly instead.
            return Response::error('Failed to grant role at resource', 500);
        }

        if ($created) {
            $this->hooks->dispatch('rbac.resource_grant.created', [
                'id' => $grantId,
                'tenant_id' => $tenantId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'role_id' => $roleId,
                'profile_id' => $profileId,
            ]);
        }

        return Response::json([
            'data' => [
                'id' => $grantId,
                'tenant_id' => $tenantId,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'role_id' => $roleId,
                'profile_id' => $profileId,
                'created' => $created,
            ],
        ], $created ? 201 : 200);
    }

    /**
     * GET /api/resource-role-grants?resource_type=T&resource_id=N — the grants
     * at one resource.
     *
     * Both shapes come back in one list, told apart by `profile_id` being null
     * or set, because "who has authority here?" is one question and answering it
     * in two calls invites a caller to check only one.
     *
     * Deliberately does NOT run the ownership check {@see self::create()} runs.
     * The query is already tenant-scoped, so a foreign or nonexistent resource
     * yields an empty list and discloses nothing. Requiring the owning plugin to
     * vouch for a READ would make grants un-listable whenever that plugin is
     * disabled — hiding rows that still exist and still resolve, which is the
     * worst possible moment to go blind.
     */
    public function list(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::ROLES_READ);
        if ($auth instanceof Response) {
            return $auth;
        }
        $tenantId = $auth;

        $query = self::queryParams($request);

        $resourceType = trim((string) ($query['resource_type'] ?? ''));
        if ($resourceType === '' || !$this->resourceTypes->exists($resourceType)) {
            return Response::error(
                'resource_type is required and must be a registered resource type',
                422,
                ['resource_type' => $resourceType]
            );
        }

        $resourceId = self::positiveInt($query['resource_id'] ?? null);
        if ($resourceId === null) {
            return Response::error('resource_id is required and must be a positive integer', 422);
        }

        return Response::json([
            'data' => $this->repository->listFor($tenantId, $resourceType, $resourceId),
        ]);
    }

    /**
     * DELETE /api/resource-role-grants/{id} — revoke one grant.
     *
     * BY ID rather than by (resource, role, profile), for two reasons.
     *
     * An id names exactly one row. The tuple form has to express "the
     * everyone-grant" versus "this profile's grant" through a parameter that may
     * be absent, and over HTTP an omitted `profile_id` and an explicit null are
     * indistinguishable — so a caller that meant to revoke one person's grant
     * but dropped the parameter would silently revoke everyone's instead. That
     * is a widening of the blast radius produced by a typo.
     *
     * It also matches DELETE /api/users/{id}/memberships/{membershipId}, and
     * composes directly with the list route, which returns exactly these ids.
     *
     * The tenant predicate makes a client-supplied id safe: another tenant's
     * grant matches nothing, so probing ids neither deletes nor reveals a row.
     *
     * @param array<string, mixed> $params Route params (`id` = the grant id).
     */
    public function revoke(Request $request, array $params): Response
    {
        $auth = $this->authorize($request, CorePermissions::ROLES_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        $tenantId = $auth;

        $grantId = self::positiveInt($params['id'] ?? null);
        if ($grantId === null) {
            return Response::error('A grant id is required', 400);
        }

        if (!$this->repository->revokeById($tenantId, $grantId)) {
            return Response::error('Grant not found', 404);
        }

        $this->hooks->dispatch('rbac.resource_grant.revoked', [
            'id' => $grantId,
            'tenant_id' => $tenantId,
        ]);

        return Response::json([], 204);
    }

    /**
     * Does this resource exist, in THIS tenant?
     *
     * `resource_id` is polymorphic and carries no foreign key — the target table
     * varies by `resource_type` — so there is no single query core can run. The
     * answer therefore comes from whoever owns the type:
     *
     *  - `ou` is core's own, and is checked directly.
     *  - a plugin type is checked by asking the plugin, through a filter hook.
     *
     * Fails CLOSED when nobody vouches. The alternative — accepting any integer
     * for a type core cannot check — is exactly the unvalidated write this
     * change exists to replace, and it is worse here than it looks: because
     * `resource_id` has no FK and ids get reused, a grant left at a stale id is
     * silently inherited by whatever record takes that id next.
     */
    private function resourceExistsInTenant(int $tenantId, string $resourceType, int $resourceId): bool
    {
        if ($resourceType === ResourceTypeRegistry::TYPE_OU) {
            $statement = $this->db->prepare(
                'SELECT id FROM organizational_units WHERE id = ? AND tenant_id = ?'
            );
            $statement->execute([$resourceId, $tenantId]);

            return $statement->fetch() !== false;
        }

        $result = $this->hooks->dispatch(self::VERIFY_RESOURCE_HOOK, [
            'tenant_id' => $tenantId,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'exists' => false,
        ]);

        return ($result['exists'] ?? false) === true;
    }

    /**
     * Whether a profile has ANY membership in the tenant.
     *
     * Any status, not just active, deliberately: suspending someone must not
     * make their existing grants un-manageable, and re-activating them must not
     * require re-granting. Whether a grant currently CONFERS anything is a
     * read-time question that {@see \Whity\Auth\RoleChecker} answers by
     * requiring an active membership — this is only "does this person belong
     * here at all?".
     */
    private function profileBelongsToTenant(int $profileId, int $tenantId): bool
    {
        $statement = $this->db->prepare(
            'SELECT id FROM memberships WHERE profile_id = ? AND tenant_id = ? LIMIT 1'
        );
        $statement->execute([$profileId, $tenantId]);

        return $statement->fetch() !== false;
    }

    /**
     * The caller's tenant, or an error Response.
     *
     * Gated on the EXISTING `roles:read` / `roles:manage` permissions rather
     * than a new one. A new permission would need a grant migration, and such a
     * migration reaches the `admin` role only — so every operator running a
     * custom administrative role would silently not have the feature, which for
     * a capability plugins depend on reads as the platform being broken.
     */
    private function authorize(Request $request, string $permission): int|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $profileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($profileId === null
            || !$this->roleChecker->hasPermissionForProfile($profileId, $permission, $tenantId)
        ) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return $tenantId;
    }

    /**
     * A positive integer, or null when the value is not one.
     *
     * Rejects a non-numeric id HERE so `{"resource_id": "abc"}` is a clean 422
     * rather than an "invalid input syntax for integer" driver error surfacing
     * as an opaque 500 on PostgreSQL.
     */
    private static function positiveInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $int = (int) $value;

            return $int > 0 ? $int : null;
        }

        return null;
    }

    /**
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
}
