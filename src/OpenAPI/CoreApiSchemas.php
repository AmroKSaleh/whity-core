<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

use Whity\Core\Response;
use Whity\Core\Router;

/**
 * Typed OpenAPI declarations for the core admin resources (WC-167).
 *
 * One catalogue describes the request/response contracts of the admin API —
 * users, roles, tenants, organizational units, delegations, audit logs —
 * mirroring the handlers' ACTUAL runtime shapes (field names, casing,
 * null-ability, envelopes, status codes). `generate:openapi` registers these
 * declarations (with no-op handlers — only paths/methods/schemas matter for
 * the spec) alongside the plugin routes, so public/openapi.json carries the
 * full typed contract that the typed client (#168) and schema-driven UI
 * (#169) consume.
 *
 * Keep this catalogue in lockstep with the route registrations in
 * public/index.php and the handlers under src/Api/ — the snapshot test
 * (tests/OpenAPI/AdminSchemasTest.php) fails when the committed spec drifts
 * from regeneration, and the per-shape assertions pin the key fields.
 */
final class CoreApiSchemas
{
    /**
     * Static catalogue only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Register the admin routes with their typed declarations.
     *
     * Handlers are no-ops: this registration exists for SPEC GENERATION; the
     * live application wires real handlers in public/index.php.
     *
     * @param Router $router The router the schema generator reads.
     */
    public static function registerRoutes(Router $router): void
    {
        $noop = static fn (): Response => new Response(501, '');

        foreach (self::routes() as $route) {
            $router->register(
                $route['method'],
                $route['path'],
                $noop,
                $route['requiredRole'],
                null,
                $route['requiredPermission'],
                $route['schema'] + ['components' => self::components()]
            );
        }
    }

    /**
     * The admin route declarations.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    public static function routes(): array
    {
        return array_merge(
            self::userRoutes(),
            self::roleRoutes(),
            self::tenantRoutes(),
            self::ouRoutes(),
            self::delegationRoutes(),
            self::auditRoutes(),
            self::frontendFeatureRoutes()
        );
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function userRoutes(): array
    {
        return [
            self::adminRoute('GET', '/api/users', [
                'summary' => 'List the tenant\'s users',
                'tags' => ['users'],
                'responses' => [
                    200 => self::jsonResponse('The users visible to the caller\'s tenant', 'UserListResponse'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('POST', '/api/users', [
                'summary' => 'Create a user',
                'tags' => ['users'],
                'request' => 'UserCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created user', 'UserResponse'),
                    400 => self::errorResponse('Validation failed'),
                    404 => self::errorResponse('Declared role not found or not visible'),
                    409 => self::errorResponse('Email already exists in the tenant'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('PATCH', '/api/users/{id:\d+}', [
                'summary' => 'Update a user',
                'tags' => ['users'],
                'request' => 'UserUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated user', 'UserResponse'),
                    400 => self::errorResponse('Validation failed'),
                    404 => self::errorResponse('User or role not found'),
                    409 => self::errorResponse('Email already exists in the tenant'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('DELETE', '/api/users/{id:\d+}', [
                'summary' => 'Delete a user',
                'tags' => ['users'],
                'responses' => [
                    200 => self::jsonResponse('Deletion confirmation', 'MutationResponse'),
                    404 => self::errorResponse('User not found'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function roleRoutes(): array
    {
        return [
            self::adminRoute('GET', '/api/roles', [
                'summary' => 'List the roles visible to the tenant (own + global)',
                'tags' => ['roles'],
                'responses' => [
                    200 => self::jsonResponse('Visible roles with permission counts', 'RoleListResponse'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('POST', '/api/roles', [
                'summary' => 'Create a role with optional permission grants',
                'tags' => ['roles'],
                'request' => 'RoleCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created role', 'RoleCreateResponse'),
                    400 => self::errorResponse('Validation failed'),
                    409 => self::errorResponse('Role name already exists'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('GET', '/api/roles/{id:\d+}', [
                'summary' => 'Get a role with its permissions',
                'tags' => ['roles'],
                'responses' => [
                    200 => self::jsonResponse('The role and its permissions', 'RoleDetailResponse'),
                    404 => self::errorResponse('Role not found or not visible'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('PATCH', '/api/roles/{id:\d+}', [
                'summary' => 'Update a role (permissions are replaced when supplied)',
                'tags' => ['roles'],
                'request' => 'RoleUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('Update confirmation', 'MutationResponse'),
                    404 => self::errorResponse('Role not found or not manageable by the tenant'),
                    409 => self::errorResponse('Role name already exists'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('DELETE', '/api/roles/{id:\d+}', [
                'summary' => 'Delete a role',
                'tags' => ['roles'],
                'responses' => [
                    200 => self::jsonResponse('Deletion confirmation', 'MutationResponse'),
                    404 => self::errorResponse('Role not found or not manageable by the tenant'),
                    409 => self::errorResponse('Role has active user assignments'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('GET', '/api/roles/{id:\d+}/permissions', [
                'summary' => 'List a role\'s permissions',
                'tags' => ['roles'],
                'responses' => [
                    200 => self::jsonResponse('The role\'s permissions', 'PermissionListResponse'),
                    404 => self::errorResponse('Role not found or not visible'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('GET', '/api/permissions', [
                'summary' => 'List the permission catalogue',
                'tags' => ['roles'],
                'responses' => [
                    200 => self::jsonResponse('All known permissions', 'PermissionCatalogueResponse'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function tenantRoutes(): array
    {
        return [
            self::adminRoute('GET', '/api/tenants', [
                'summary' => 'List tenants (system tenant sees all; others see their own)',
                'tags' => ['tenants'],
                'responses' => [
                    200 => self::jsonResponse('Visible tenants with user counts', 'TenantListResponse'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('POST', '/api/tenants', [
                'summary' => 'Create a tenant (system tenant only)',
                'tags' => ['tenants'],
                'request' => 'TenantCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created tenant', 'TenantResponse'),
                    400 => self::errorResponse('Validation failed'),
                    409 => self::errorResponse('Tenant name or slug already exists'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('PATCH', '/api/tenants/{id:\d+}', [
                'summary' => 'Update a tenant',
                'tags' => ['tenants'],
                'request' => 'TenantUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('Update confirmation', 'MutationResponse'),
                    400 => self::errorResponse('Validation failed (e.g. invalid slug format)'),
                    404 => self::errorResponse('Tenant not found'),
                    409 => self::errorResponse('Tenant name or slug already exists'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('DELETE', '/api/tenants/{id:\d+}', [
                'summary' => 'Delete a tenant (the system tenant is protected)',
                'tags' => ['tenants'],
                'responses' => [
                    200 => self::jsonResponse('Deletion confirmation', 'MutationResponse'),
                    400 => self::errorResponse('The system tenant cannot be deleted'),
                    404 => self::errorResponse('Tenant not found'),
                    409 => self::errorResponse('Tenant still has users'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function ouRoutes(): array
    {
        return [
            self::adminRoute('GET', '/api/ous', [
                'summary' => 'List the tenant\'s organizational units',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s organizational units', 'OuListResponse'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('POST', '/api/ous', [
                'summary' => 'Create an organizational unit',
                'tags' => ['ous'],
                'request' => 'OuCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created organizational unit', 'OuResponse'),
                    400 => self::errorResponse('Validation failed'),
                    409 => self::errorResponse('Name or slug already exists in the tenant'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('GET', '/api/ous/{id:\d+}', [
                'summary' => 'Get an organizational unit with its direct children',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The organizational unit and its children', 'OuDetailResponse'),
                    404 => self::errorResponse('Organizational unit not found'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('PATCH', '/api/ous/{id:\d+}', [
                'summary' => 'Update an organizational unit (re-parenting is cycle-checked)',
                'tags' => ['ous'],
                'request' => 'OuUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('Update confirmation', 'MutationResponse'),
                    422 => self::errorResponse('The re-parent would create a cycle'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('DELETE', '/api/ous/{id:\d+}', [
                'summary' => 'Delete an organizational unit',
                'tags' => ['ous'],
                'responses' => [
                    204 => ['description' => 'Deleted'],
                    409 => self::errorResponse('Organizational unit still has children or users'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('GET', '/api/ous/{id:\d+}/roles', [
                'summary' => 'List the roles assigned to an organizational unit',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The assigned roles', 'RoleSummaryListResponse'),
                    404 => self::errorResponse('Organizational unit not found'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('GET', '/api/ous/{id:\d+}/members', [
                'summary' => 'List the users assigned to an organizational unit',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The assigned users', 'UserListResponse'),
                    404 => self::errorResponse('Organizational unit not found'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('POST', '/api/ous/{id:\d+}/roles', [
                'summary' => 'Assign a role to an organizational unit',
                'tags' => ['ous'],
                'request' => 'OuRoleAssignRequest',
                'responses' => [
                    201 => self::jsonResponse('The created assignment', 'OuRoleAssignmentResponse'),
                    400 => self::errorResponse('role_id missing'),
                    404 => self::errorResponse('Organizational unit or role not found'),
                    409 => self::errorResponse('Assignment already exists'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('DELETE', '/api/ous/{ouId:\d+}/roles/{roleId:\d+}', [
                'summary' => 'Remove a role assignment from an organizational unit',
                'tags' => ['ous'],
                'responses' => [
                    204 => ['description' => 'Assignment removed'],
                    404 => self::errorResponse('Assignment not found'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function delegationRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/delegations', 'delegation:manage', [
                'summary' => 'List the tenant\'s permission delegations',
                'tags' => ['delegations'],
                'parameters' => [
                    self::queryParam('granteeType', 'string', 'Filter by grantee type (role|user)'),
                    self::queryParam('granteeId', 'integer', 'Filter by grantee id'),
                    self::queryParam('grantorUserId', 'integer', 'Filter by grantor user id'),
                    self::queryParam('includeRevoked', 'boolean', 'Include revoked delegations'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The matching delegations', 'DelegationListResponse'),
                    400 => self::errorResponse('Invalid filter'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/delegations', 'delegation:manage', [
                'summary' => 'Delegate permissions the grantor holds (one row per permission)',
                'tags' => ['delegations'],
                'request' => 'DelegationCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created delegations', 'DelegationCreateResponse'),
                    400 => self::errorResponse('Validation failed'),
                    404 => self::errorResponse('Grantee or organizational unit not found'),
                    422 => self::errorResponse('The grantor does not hold every requested permission'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/delegations/{id:\d+}', 'delegation:manage', [
                'summary' => 'Revoke a delegation (non-destructive: sets revokedAt)',
                'tags' => ['delegations'],
                'responses' => [
                    200 => self::jsonResponse('Revocation confirmation', 'MutationResponse'),
                    404 => self::errorResponse('Delegation not found'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function auditRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/audit-logs', 'audit:read', [
                'summary' => 'List the tenant\'s audit entries (newest first, paginated)',
                'tags' => ['audit-logs'],
                'parameters' => [
                    self::queryParam('action', 'string', 'Exact action match (e.g. users:create)'),
                    self::queryParam('actor', 'integer', 'Filter by actor user id'),
                    self::queryParam('target_type', 'string', 'Filter by target type'),
                    self::queryParam('from', 'string', 'Inclusive ISO-8601 lower bound'),
                    self::queryParam('to', 'string', 'Inclusive ISO-8601 upper bound'),
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The matching audit entries with pagination', 'AuditLogListResponse'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function frontendFeatureRoutes(): array
    {
        return [
            // Registered with NEITHER a required role NOR a required permission
            // (any authenticated caller may list the screens they may see), so
            // the operation carries no bearerAuth marker — matching how the
            // generator treats /api/navigation-style endpoints. The handler's
            // own fail-closed 403s (unresolved tenant, missing user) and the
            // tenant middleware's 401 are documented as responses.
            [
                'method' => 'GET',
                'path' => '/api/frontend/features',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the plugin frontend features visible to the caller',
                    'tags' => ['frontend'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'The features whose requiredPermission the caller holds (server-side filtered; empty data is valid)',
                            'FrontendFeatureListResponse'
                        ),
                    ] + self::authErrors(),
                ],
            ],
        ];
    }

    /**
     * The component schemas the admin resources publish.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function components(): array
    {
        $user = self::object([
            'id' => self::int(),
            'name' => self::str(),
            'email' => self::str(),
            'role' => self::str(),
            'tenantId' => self::int(),
            'createdAt' => self::str(true),
        ], ['id', 'name', 'email', 'role', 'tenantId', 'createdAt']);

        $permission = self::object([
            'id' => self::int(),
            'name' => self::str(),
            'description' => self::str(true),
        ], ['id', 'name']);

        $role = self::object([
            'id' => self::int(),
            'name' => self::str(),
            'description' => self::str(true),
            'parent_id' => self::int(true),
            'created_at' => self::str(true),
            'permissionCount' => self::int(),
        ], ['id', 'name', 'description', 'parent_id', 'created_at', 'permissionCount']);

        $tenant = self::object([
            'id' => self::int(),
            'name' => self::str(),
            'slug' => self::str(true),
            'userCount' => self::int(),
            'createdAt' => self::str(true),
        ], ['id', 'name', 'slug', 'userCount', 'createdAt']);

        $ou = self::object([
            'id' => self::int(),
            'tenant_id' => self::int(),
            'parent_id' => self::int(true),
            'name' => self::str(),
            'slug' => self::str(),
            'description' => self::str(true),
            'created_at' => self::str(true),
        ], ['id', 'tenant_id', 'parent_id', 'name', 'slug', 'description', 'created_at']);

        $delegation = self::object([
            'id' => self::int(),
            'tenantId' => self::int(),
            'grantorUserId' => self::int(),
            'granteeType' => ['type' => 'string', 'enum' => ['role', 'user']],
            'granteeId' => self::int(),
            'permission' => self::str(),
            'ouId' => self::int(true),
            'grantedAt' => self::str(true),
            'revokedAt' => self::str(true),
        ], ['id', 'tenantId', 'grantorUserId', 'granteeType', 'granteeId', 'permission', 'ouId', 'grantedAt', 'revokedAt']);

        $auditEntry = self::object([
            'id' => self::int(),
            'tenantId' => self::int(),
            'actorUserId' => self::int(true),
            'action' => self::str(),
            'targetType' => self::str(true),
            'targetId' => self::int(true),
            'metadata' => ['type' => 'object'],
            'ipAddress' => self::str(true),
            'createdAt' => self::str(true),
        ], ['id', 'tenantId', 'actorUserId', 'action', 'targetType', 'targetId', 'metadata', 'ipAddress', 'createdAt']);

        $roleSummary = self::object([
            'id' => self::int(),
            'name' => self::str(),
            'description' => self::str(),
        ], ['id', 'name']);

        $mutationResult = self::object([
            'id' => self::int(),
            'message' => self::str(),
        ], ['id', 'message']);

        $permissionRef = ['oneOf' => [self::int(), self::str()]];

        return [
            'Error' => self::object([
                'error' => self::str(),
                'details' => ['type' => 'object'],
            ], ['error']),
            'MutationResponse' => self::dataEnvelope($mutationResult),

            'User' => $user,
            'UserListResponse' => self::listEnvelope('User'),
            'UserResponse' => self::dataEnvelope(SchemaBuilder::ref('User')),
            // NOTE: no tenantId field — the handler always creates the user in
            // the caller's TenantContext (a declared field with zero runtime
            // effect would be a contract lie).
            'UserCreateRequest' => self::object([
                'email' => self::str(),
                'password' => ['type' => 'string', 'minLength' => 6],
                'role' => $permissionRef,
            ], ['email', 'password']),
            'UserUpdateRequest' => self::object([
                'email' => self::str(),
                'password' => ['type' => 'string', 'minLength' => 6],
                'role' => $permissionRef,
                'ou_id' => self::int(true),
            ], []),

            'Permission' => $permission,
            'PermissionListResponse' => self::listEnvelope('Permission'),
            // The catalogue (GET /api/permissions) merges database rows with
            // registry-only entries, which carry NO database id and a `source`
            // tag instead — a distinct shape from the role-scoped Permission.
            'PermissionCatalogueEntry' => self::object([
                'id' => self::int(true),
                'name' => self::str(),
                'description' => self::str(true),
                'source' => self::str(),
            ], ['id', 'name', 'description']),
            'PermissionCatalogueResponse' => self::listEnvelope('PermissionCatalogueEntry'),
            'Role' => $role,
            'RoleListResponse' => self::listEnvelope('Role'),
            'RoleDetail' => self::object([
                'id' => self::int(),
                'name' => self::str(),
                'description' => self::str(true),
                'parent_id' => self::int(true),
                'created_at' => self::str(true),
                'permissions' => ['type' => 'array', 'items' => SchemaBuilder::ref('Permission')],
            ], ['id', 'name', 'description', 'parent_id', 'created_at', 'permissions']),
            'RoleDetailResponse' => self::dataEnvelope(SchemaBuilder::ref('RoleDetail')),
            'RoleCreateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(),
                'permissions' => ['type' => 'array', 'items' => $permissionRef],
            ], ['name']),
            'RoleUpdateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(),
                'permissions' => ['type' => 'array', 'items' => $permissionRef],
            ], []),
            'RoleCreateResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'name' => self::str(),
                'description' => self::str(),
                'permissionCount' => self::int(),
            ], ['id', 'name'])),
            'RoleSummary' => $roleSummary,
            'RoleSummaryListResponse' => self::listEnvelope('RoleSummary'),

            'Tenant' => $tenant,
            'TenantListResponse' => self::listEnvelope('Tenant'),
            'TenantResponse' => self::dataEnvelope(SchemaBuilder::ref('Tenant')),
            'TenantCreateRequest' => self::object([
                'name' => self::str(),
                'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$'],
            ], ['name']),
            'TenantUpdateRequest' => self::object([
                'name' => self::str(),
                'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$'],
            ], []),

            'OrganizationalUnit' => $ou,
            'OuListResponse' => self::listEnvelope('OrganizationalUnit'),
            'OuResponse' => self::dataEnvelope(SchemaBuilder::ref('OrganizationalUnit')),
            'OuDetail' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'parent_id' => self::int(true),
                'name' => self::str(),
                'slug' => self::str(),
                'description' => self::str(true),
                'created_at' => self::str(true),
                'children' => [
                    'type' => 'array',
                    'items' => self::object(['id' => self::int()], ['id']),
                ],
            ], ['id', 'tenant_id', 'parent_id', 'name', 'slug', 'description', 'created_at', 'children']),
            'OuDetailResponse' => self::dataEnvelope(SchemaBuilder::ref('OuDetail')),
            'OuCreateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(),
                'parent_id' => self::int(true),
            ], ['name']),
            'OuUpdateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(),
                'parent_id' => self::int(true),
            ], []),
            'OuRoleAssignRequest' => self::object(['role_id' => self::int()], ['role_id']),
            'OuRoleAssignment' => self::object([
                'id' => self::int(),
                'ou_id' => self::int(),
                'role_id' => self::int(),
                'tenant_id' => self::int(),
            ], ['id', 'ou_id', 'role_id', 'tenant_id']),
            'OuRoleAssignmentResponse' => self::dataEnvelope(SchemaBuilder::ref('OuRoleAssignment')),

            'Delegation' => $delegation,
            'DelegationListResponse' => self::listEnvelope('Delegation'),
            'DelegationCreateRequest' => self::object([
                'granteeType' => ['type' => 'string', 'enum' => ['role', 'user']],
                'granteeId' => self::int(),
                'permissions' => ['type' => 'array', 'items' => self::str(), 'minItems' => 1],
                'ouId' => self::int(true),
            ], ['granteeType', 'granteeId', 'permissions']),
            'DelegationCreateResponse' => self::dataEnvelope(self::object([
                'ids' => ['type' => 'array', 'items' => self::int()],
                'granteeType' => self::str(),
                'granteeId' => self::int(),
                'ouId' => self::int(true),
                'permissions' => ['type' => 'array', 'items' => self::str()],
                'count' => self::int(),
            ], ['ids', 'count'])),

            // WC-169: plugin frontend feature descriptors. Mirrors the
            // FrontendFeaturesApiHandler's ACTUAL output: every key is always
            // present; icon and resource (and resource.titleField) are the
            // fields the handler can emit as null.
            'FrontendFeature' => self::object([
                'id' => self::str(),
                'plugin' => self::str(),
                'label' => self::str(),
                'icon' => self::str(true),
                'group' => self::str(),
                'order' => self::int(),
                'screen' => ['type' => 'string', 'enum' => ['crud', 'custom']],
                'resource' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'basePath' => self::str(),
                        'titleField' => self::str(true),
                    ],
                    'required' => ['basePath', 'titleField'],
                ],
                'requiredPermission' => self::str(),
            ], ['id', 'plugin', 'label', 'icon', 'group', 'order', 'screen', 'resource', 'requiredPermission']),
            'FrontendFeatureListResponse' => self::listEnvelope('FrontendFeature'),

            'AuditLogEntry' => $auditEntry,
            'Pagination' => self::object([
                'page' => self::int(),
                'perPage' => self::int(),
                'total' => self::int(),
                'totalPages' => self::int(),
            ], ['page', 'perPage', 'total', 'totalPages']),
            'AuditLogListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('AuditLogEntry')],
                'pagination' => SchemaBuilder::ref('Pagination'),
            ], ['data', 'pagination']),
        ];
    }

    // ==================== declaration helpers ====================

    /**
     * @param array<string, mixed> $schema
     * @return array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}
     */
    private static function adminRoute(string $method, string $path, array $schema): array
    {
        return [
            'method' => $method,
            'path' => $path,
            'requiredRole' => 'admin',
            'requiredPermission' => null,
            'schema' => $schema,
        ];
    }

    /**
     * @param array<string, mixed> $schema
     * @return array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}
     */
    private static function permissionRoute(string $method, string $path, string $permission, array $schema): array
    {
        return [
            'method' => $method,
            'path' => $path,
            'requiredRole' => null,
            'requiredPermission' => $permission,
            'schema' => $schema,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function jsonResponse(string $description, string $component): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => SchemaBuilder::ref($component)]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorResponse(string $description): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => SchemaBuilder::ref('Error')]],
        ];
    }

    /**
     * The 401/403 responses every protected admin route shares.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function authErrors(): array
    {
        return [
            401 => self::errorResponse('Missing or invalid authentication'),
            403 => self::errorResponse('Insufficient permissions'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function queryParam(string $name, string $type, string $description): array
    {
        return [
            'name' => $name,
            'in' => 'query',
            'required' => false,
            'description' => $description,
            'schema' => ['type' => $type],
        ];
    }

    // ==================== schema helpers ====================

    /**
     * @param array<string, mixed> $properties
     * @param list<string> $required
     * @return array<string, mixed>
     */
    private static function object(array $properties, array $required): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];
        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * @return array<string, mixed>
     */
    private static function listEnvelope(string $component): array
    {
        return self::object(
            ['data' => ['type' => 'array', 'items' => SchemaBuilder::ref($component)]],
            ['data']
        );
    }

    /**
     * @param array<string, mixed> $itemSchema
     * @return array<string, mixed>
     */
    private static function dataEnvelope(array $itemSchema): array
    {
        return self::object(['data' => $itemSchema], ['data']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function int(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'integer', 'nullable' => true] : ['type' => 'integer'];
    }

    /**
     * @return array<string, mixed>
     */
    private static function str(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'string', 'nullable' => true] : ['type' => 'string'];
    }
}
