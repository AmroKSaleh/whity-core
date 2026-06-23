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
            $unversioned = $route['unversioned'] ?? false;
            if ($unversioned) {
                $router->registerUnversioned(
                    $route['method'],
                    $route['path'],
                    $noop,
                    $route['requiredRole'],
                    null,
                    $route['requiredPermission'],
                    $route['schema'] + ['components' => self::components()]
                );
            } else {
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
    }

    /**
     * The admin route declarations.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>, unversioned?: bool}>
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
            self::frontendFeatureRoutes(),
            self::meRoutes(),
            self::platformOpsRoutes(),
            self::familyRelationsRoutes(),
            self::settingsRoutes(),
            self::brandingRoutes()
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
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function meRoutes(): array
    {
        return [
            // WC-176 (#205): the caller's effective permission slugs, so a
            // bespoke admin page can hide write controls the caller lacks.
            // Registered with NEITHER a required role NOR a required permission
            // (any authenticated caller may ask which permissions they hold), so
            // the operation carries no bearerAuth marker — matching how the
            // generator treats /api/navigation-style endpoints. The handler's
            // own fail-closed 403s (unresolved tenant, missing user) and the
            // tenant middleware's 401 are documented as responses.
            [
                'method' => 'GET',
                'path' => '/api/me/capabilities',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the caller\'s effective permission slugs',
                    'tags' => ['me'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'The caller\'s effective, tenant-scoped permission slugs (sorted; empty is valid)',
                            'MeCapabilitiesResponse'
                        ),
                    ] + self::authErrors(),
                ],
            ],
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>, unversioned?: bool}>
     */
    private static function platformOpsRoutes(): array
    {
        return [
            // No auth gate — any caller (including unauthenticated health checks).
            // WC-206: UNVERSIONED — stored at /api/health regardless of the Router
            // version prefix so load-balancer probes never need updating.
            [
                'method' => 'GET',
                'path' => '/api/health',
                'requiredRole' => null,
                'requiredPermission' => null,
                'unversioned' => true,
                'schema' => [
                    'summary' => 'Platform health probe',
                    'tags' => ['platform-ops'],
                    'responses' => [
                        200 => self::jsonResponse('System is healthy', 'HealthResponse'),
                        503 => self::errorResponse('System is degraded'),
                    ],
                ],
            ],
            // WC-209: the dynamic OpenAPI document, regenerated from the live
            // router at request time. UNVERSIONED (stored at /api/openapi.json
            // regardless of the version prefix, like /api/health) and
            // unauthenticated — it exposes only route shapes, never tenant data.
            [
                'method' => 'GET',
                'path' => '/api/openapi.json',
                'requiredRole' => null,
                'requiredPermission' => null,
                'unversioned' => true,
                'schema' => [
                    'summary' => 'The live OpenAPI 3.0 document (regenerated per request from the running router)',
                    'tags' => ['platform-ops'],
                    'responses' => [
                        200 => self::jsonResponse('The OpenAPI document describing every currently-registered route', 'OpenApiDocumentResponse'),
                        500 => self::errorResponse('Failed to generate the OpenAPI document'),
                    ],
                ],
            ],
            // No auth gate — any authenticated caller may read navigation
            [
                'method' => 'GET',
                'path' => '/api/navigation',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the navigation items visible to the caller',
                    'tags' => ['platform-ops'],
                    'responses' => [
                        200 => self::jsonResponse('The navigation items', 'NavigationListResponse'),
                        403 => self::errorResponse('Unauthenticated or tenant not resolved'),
                        500 => self::errorResponse('Internal error'),
                    ],
                ],
            ],
            // Deployment management — admin role required
            self::adminRoute('POST', '/api/deployments/apply', [
                'summary' => 'Apply a deployment artefact',
                'tags' => ['platform-ops'],
                'request' => 'DeploymentApplyRequest',
                'responses' => [
                    201 => self::jsonResponse('Deployment applied', 'SimpleMessageResponse'),
                    400 => self::errorResponse('Validation failed'),
                    403 => self::errorResponse('Insufficient permissions'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::adminRoute('POST', '/api/deployments/rollback', [
                'summary' => 'Roll back the last deployment',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Rollback complete', 'SimpleMessageResponse'),
                    403 => self::errorResponse('Insufficient permissions'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::adminRoute('GET', '/api/deployments/status', [
                'summary' => 'Get the current deployment status',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Current deployment status', 'DeploymentStatusResponse'),
                    403 => self::errorResponse('Insufficient permissions'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::adminRoute('GET', '/api/migrations', [
                'summary' => 'List database migrations and their execution state',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Migration list', 'MigrationListResponse'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::adminRoute('GET', '/api/admin/stats', [
                'summary' => 'Platform-wide aggregate statistics',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Aggregate stats', 'AdminStatsResponse'),
                    403 => self::errorResponse('Insufficient permissions'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            // Plugin lifecycle management — per-action permission required (WC-218).
            self::permissionRoute('GET', '/api/plugins', 'plugins:read', [
                'summary' => 'List all registered plugins',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Plugin list', 'PluginListResponse'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            // WC-220: staged plugin upload/install (multipart/form-data, field
            // "package"). Lands the artifact DISABLED; migration-on-enable runs
            // its migrations on the subsequent enable.
            self::permissionRoute('POST', '/api/plugins/upload', 'plugins:upload', [
                'summary' => 'Upload and stage a plugin package (lands disabled)',
                'tags' => ['platform-ops'],
                'request' => [
                    'required' => true,
                    'content' => [
                        'multipart/form-data' => [
                            'schema' => [
                                'type' => 'object',
                                'required' => ['package'],
                                'properties' => [
                                    'package' => [
                                        'type' => 'string',
                                        'format' => 'binary',
                                        'description' => 'A .zip or single .php plugin package.',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'responses' => [
                    200 => self::jsonResponse('Plugin staged (disabled)', 'PluginUploadResponse'),
                    400 => self::errorResponse('Invalid package, unsafe name, or unsafe archive'),
                    409 => self::errorResponse('A plugin with this name is already installed'),
                    422 => self::errorResponse('Plugin incompatible with this host'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('POST', '/api/plugins/{name}/enable', 'plugins:enable', [
                'summary' => 'Enable a plugin by name (applies pending migrations)',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Plugin enabled', 'SimpleMessageResponse'),
                    400 => self::errorResponse('Plugin not found or already enabled'),
                    422 => self::errorResponse('Plugin migration failed during enable'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('POST', '/api/plugins/{name}/disable', 'plugins:disable', [
                'summary' => 'Disable a plugin by name',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Plugin disabled', 'SimpleMessageResponse'),
                    400 => self::errorResponse('Plugin not found or already disabled'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('POST', '/api/plugins/{id}/re-enable', 'plugins:enable', [
                'summary' => 'Re-enable a previously disabled plugin by id',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Plugin re-enabled', 'SimpleMessageResponse'),
                    400 => self::errorResponse('Plugin not found or not disabled'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('POST', '/api/plugins/{id}/uninstall', 'plugins:uninstall', [
                'summary' => 'Uninstall a plugin (disable, roll back migrations, remove files)',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Plugin uninstalled', 'SimpleMessageResponse'),
                    400 => self::errorResponse('Invalid plugin identifier'),
                    404 => self::errorResponse('Plugin not found'),
                    409 => self::jsonResponse('Migration rollback failed', 'SimpleMessageResponse'),
                    500 => self::errorResponse('Internal error'),
                    503 => self::errorResponse('Database connection unavailable'),
                ],
            ]),
            self::permissionRoute('POST', '/api/plugins/reload', 'plugins:reload', [
                'summary' => 'Reload the plugin registry',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Registry reloaded', 'SimpleMessageResponse'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function familyRelationsRoutes(): array
    {
        return [
            // ---- Read surface (relations:read) ----
            self::permissionRoute('GET', '/api/relationship-types', 'relations:read', [
                'summary' => 'List the relationship-type vocabulary',
                'tags' => ['relations'],
                'responses' => [
                    200 => self::jsonResponse('The relationship types', 'RelationshipTypeListResponse'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('GET', '/api/persons', 'relations:read', [
                'summary' => 'List persons in the caller\'s tenant',
                'tags' => ['relations'],
                'responses' => [
                    200 => self::jsonResponse('The persons', 'PersonListResponse'),
                    400 => self::errorResponse('Bad request'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('GET', '/api/persons/{id:\d+}', 'relations:read', [
                'summary' => 'Get a single person',
                'tags' => ['relations'],
                'responses' => [
                    200 => self::jsonResponse('The person', 'PersonResponse'),
                    400 => self::errorResponse('Bad request'),
                    404 => self::errorResponse('Person not found'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('GET', '/api/persons/{id:\d+}/relations', 'relations:read', [
                'summary' => 'List a person\'s relation edges',
                'tags' => ['relations'],
                'responses' => [
                    200 => self::jsonResponse('The person\'s relations', 'RelationSummaryListResponse'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('GET', '/api/relations', 'relations:read', [
                'summary' => 'List all relation edges in the caller\'s tenant',
                'tags' => ['relations'],
                'responses' => [
                    200 => self::jsonResponse('The relation edges', 'RelationEdgeListResponse'),
                    400 => self::errorResponse('Bad request'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('GET', '/api/users/{id:\d+}/relations', 'relations:read', [
                'summary' => 'Get the person record and relations for a user account',
                'tags' => ['relations'],
                'responses' => [
                    200 => self::jsonResponse('The user\'s person and relations', 'UserRelationsResponse'),
                    400 => self::errorResponse('Bad request'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            // ---- Write surface (relations:manage) ----
            self::permissionRoute('POST', '/api/persons', 'relations:manage', [
                'summary' => 'Create a person record',
                'tags' => ['relations'],
                'request' => 'PersonCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created person', 'PersonResponse'),
                    400 => self::errorResponse('displayName required, or system tenant'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('PATCH', '/api/persons/{id:\d+}', 'relations:manage', [
                'summary' => 'Update a person record',
                'tags' => ['relations'],
                'request' => 'PersonUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated person', 'PersonResponse'),
                    400 => self::errorResponse('Validation failed'),
                    403 => self::errorResponse('Person is linked to a user account and cannot be edited'),
                    404 => self::errorResponse('Person not found'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('DELETE', '/api/persons/{id:\d+}', 'relations:manage', [
                'summary' => 'Delete a person record',
                'tags' => ['relations'],
                'responses' => [
                    204 => ['description' => 'Deleted'],
                    400 => self::errorResponse('Bad request'),
                    403 => self::errorResponse('Person is linked to a user account'),
                    404 => self::errorResponse('Person not found'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('POST', '/api/relations', 'relations:manage', [
                'summary' => 'Create a relation edge between two persons',
                'tags' => ['relations'],
                'request' => 'RelationCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created relation', 'RelationCreatedResponse'),
                    400 => self::errorResponse('Validation failed'),
                    404 => self::errorResponse('Person not found'),
                    422 => self::errorResponse('Self-relation or duplicate'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('DELETE', '/api/relations/{id:\d+}', 'relations:manage', [
                'summary' => 'Delete a relation edge',
                'tags' => ['relations'],
                'responses' => [
                    204 => ['description' => 'Deleted'],
                    400 => self::errorResponse('Bad request'),
                    404 => self::errorResponse('Relation not found'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
        ];
    }

    /**
     * The Website Settings route declarations (global defaults + per-tenant
     * overrides). Reads are gated on settings:read, current-tenant writes on
     * settings:write, and global reads/writes on settings:manage.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function settingsRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/settings', 'settings:read', [
                'summary' => 'Get the caller tenant\'s effective settings, the registry shape, and overridden keys',
                'tags' => ['settings'],
                'responses' => [
                    200 => self::jsonResponse('The effective settings, registry descriptors, and overridden keys', 'SettingsResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/settings', 'settings:write', [
                'summary' => 'Upsert the current tenant\'s setting overrides (null/empty clears an override)',
                'tags' => ['settings'],
                'request' => 'SettingsUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The recomputed effective settings', 'SettingsValueMapResponse'),
                    422 => self::errorResponse('Validation failed (unknown key or invalid value)'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/settings/global', 'settings:manage', [
                'summary' => 'Get the global setting defaults',
                'tags' => ['settings'],
                'responses' => [
                    200 => self::jsonResponse('The global defaults and the registry shape', 'GlobalSettingsResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/settings/global', 'settings:manage', [
                'summary' => 'Upsert the global setting defaults (null clears a default)',
                'tags' => ['settings'],
                'request' => 'SettingsUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The recomputed global defaults', 'SettingsValueMapResponse'),
                    422 => self::errorResponse('Validation failed (unknown key or invalid value)'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function brandingRoutes(): array
    {
        $multipartBody = [
            'requestBody' => [
                'required' => true,
                'content' => [
                    'multipart/form-data' => [
                        'schema' => self::object([
                            'file' => ['type' => 'string', 'format' => 'binary'],
                        ], ['file']),
                    ],
                ],
            ],
        ];

        return [
            [
                'method' => 'GET',
                'path' => '/api/branding',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Get effective branding for the resolved tenant (public)',
                    'tags' => ['branding'],
                    'responses' => [
                        200 => self::jsonResponse('The effective branding', 'BrandingResponse'),
                        500 => self::errorResponse('Internal error'),
                    ],
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/branding/asset/{tenantId}/{name}',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Stream a branding asset (public)',
                    'tags' => ['branding'],
                    'responses' => [
                        200 => ['description' => 'The asset bytes', 'content' => ['image/*' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                        404 => self::errorResponse('Asset not found'),
                    ],
                ],
            ],
            self::permissionRoute('POST', '/api/branding/assets/{key}', 'settings:write', array_merge([
                'summary' => 'Upload a tenant branding asset override',
                'tags' => ['branding'],
                'responses' => [
                    200 => self::jsonResponse('The updated effective branding', 'BrandingResponse'),
                    400 => self::errorResponse('No file provided'),
                    404 => self::errorResponse('Unknown branding key'),
                    422 => self::errorResponse('Validation failed or asset rejected'),
                ] + self::authErrors(),
            ], $multipartBody)),
            self::permissionRoute('DELETE', '/api/branding/assets/{key}', 'settings:write', [
                'summary' => 'Clear a tenant branding asset override',
                'tags' => ['branding'],
                'responses' => [
                    200 => self::jsonResponse('The updated effective branding', 'BrandingResponse'),
                    404 => self::errorResponse('Unknown branding key'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/branding/global/assets/{key}', 'settings:manage', array_merge([
                'summary' => 'Upload a global branding asset default',
                'tags' => ['branding'],
                'responses' => [
                    200 => self::jsonResponse('The updated effective branding', 'BrandingResponse'),
                    400 => self::errorResponse('No file provided'),
                    404 => self::errorResponse('Unknown branding key'),
                    422 => self::errorResponse('Validation failed or asset rejected'),
                ] + self::authErrors(),
            ], $multipartBody)),
            self::permissionRoute('DELETE', '/api/branding/global/assets/{key}', 'settings:manage', [
                'summary' => 'Clear a global branding asset default',
                'tags' => ['branding'],
                'responses' => [
                    200 => self::jsonResponse('The updated effective branding', 'BrandingResponse'),
                    404 => self::errorResponse('Unknown branding key'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/tenants/{id}/branding-host', 'settings:manage', [
                'summary' => 'Set or clear a tenant\'s custom branding hostname',
                'tags' => ['branding'],
                'request' => 'BrandingHostRequest',
                'responses' => [
                    200 => self::jsonResponse('The set hostname', 'BrandingHostResponse'),
                    409 => self::errorResponse('Hostname already claimed by another tenant'),
                    422 => self::errorResponse('Invalid hostname format'),
                ] + self::authErrors(),
            ]),
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
            'ou_id' => self::int(true),
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
            // True when the current tenant may update/delete this role. A global
            // NULL-tenant base role is visible but not manageable by a regular
            // tenant (only the SYSTEM tenant may manage it); the admin UI gates
            // its Edit/Delete actions on this flag (WC-222).
            'manageable' => self::bool(),
        ], ['id', 'name', 'description', 'parent_id', 'created_at', 'permissionCount', 'manageable']);

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
            'UserListResponse' => self::paginatedListEnvelope('User'),
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
            'PermissionListResponse' => self::paginatedListEnvelope('Permission'),
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
            'RoleListResponse' => self::paginatedListEnvelope('Role'),
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
            'TenantListResponse' => self::paginatedListEnvelope('Tenant'),
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
            'OuListResponse' => self::paginatedListEnvelope('OrganizationalUnit'),
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
            'DelegationListResponse' => self::paginatedListEnvelope('Delegation'),
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

            // WC-169 / WC-175: plugin frontend feature descriptors. Mirrors the
            // FrontendFeaturesApiHandler's ACTUAL output: every key is always
            // present; icon and resource (and resource.titleField) are the
            // fields the handler can emit as null. The capabilities object
            // (WC-175, #199) carries the caller's effective per-feature write
            // capabilities, computed server-side from the resource's routes'
            // RBAC so the renderer can hide controls that would 403 on submit.
            'FrontendFeature' => self::object([
                'id' => self::str(),
                'plugin' => self::str(),
                'label' => self::str(),
                'icon' => self::str(true),
                'group' => self::str(),
                'order' => self::int(),
                'screen' => ['type' => 'string', 'enum' => ['crud', 'custom', 'action', 'blocks']],
                'resource' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'basePath' => self::str(),
                        'titleField' => self::str(true),
                    ],
                    'required' => ['basePath', 'titleField'],
                ],
                // WC-169 follow-up: present (non-null) only for screen=action —
                // the route the generic form submits to plus its input fields.
                'action' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'method' => self::str(),
                        'path' => self::str(),
                        'submitLabel' => self::str(true),
                        'fields' => [
                            'type' => 'array',
                            'items' => self::object([
                                'name' => self::str(),
                                'label' => self::str(),
                                'kind' => ['type' => 'string', 'enum' => ['text', 'textarea', 'file']],
                                'accept' => self::str(true),
                                'required' => self::bool(),
                            ], ['name', 'label', 'kind', 'accept', 'required']),
                        ],
                    ],
                    'required' => ['method', 'path', 'submitLabel', 'fields'],
                ],
                'requiredPermission' => self::str(),
                'capabilities' => self::object([
                    'canCreate' => self::bool(),
                    'canEdit' => self::bool(),
                    'canDelete' => self::bool(),
                ], ['canCreate', 'canEdit', 'canDelete']),
                // WC-226: present (and host-validated) ONLY for screen='blocks' —
                // the platform-neutral block tree a renderer translates to native
                // widgets. A coarse array of objects here; the SDK BlockValidator
                // is the authoritative contract for each node's type/props, so the
                // items stay open rather than re-declaring that whitelist.
                'blocks' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ], ['id', 'plugin', 'label', 'icon', 'group', 'order', 'screen', 'resource', 'action', 'requiredPermission', 'capabilities']),
            'FrontendFeatureListResponse' => self::listEnvelope('FrontendFeature'),

            // WC-176 (#205): the caller's effective permission slugs. Mirrors
            // MeCapabilitiesApiHandler's ACTUAL output: a data envelope wrapping
            // a single `permissions` array of strings (sorted; empty is valid).
            'MeCapabilitiesResponse' => self::dataEnvelope(self::object([
                'permissions' => ['type' => 'array', 'items' => self::str()],
            ], ['permissions'])),

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

            // ---- Platform-ops schemas (WC-62133b3f) ----

            // GET /api/health — top-level (not data-enveloped)
            'HealthResponse' => self::object([
                'status' => ['type' => 'string', 'enum' => ['ok', 'degraded']],
                'version' => self::str(),
                'worker_count' => self::int(),
                'uptime_seconds' => self::int(),
                'db_connected' => self::bool(),
                'memory_usage_mb' => ['type' => 'number', 'format' => 'float'],
            ], ['status', 'version', 'worker_count', 'uptime_seconds', 'db_connected', 'memory_usage_mb']),

            // GET /api/navigation
            'NavigationItem' => self::object([
                'id' => self::str(),
                'label' => self::str(),
                'href' => self::str(),
                'icon' => self::str(),
                'group' => self::str(),
                'order' => self::int(),
                'requiredRole' => self::str(true),
                'requiredPermission' => self::str(true),
            ], ['id', 'label', 'href', 'icon', 'group', 'order']),
            'NavigationListResponse' => self::listEnvelope('NavigationItem'),

            // WC-209: the dynamic OpenAPI document is itself an OpenAPI spec —
            // a free-form object whose top-level keys (openapi/info/paths/
            // components/...) vary with the registered routes, so it is typed
            // as an open object rather than pinned field-by-field.
            'OpenApiDocumentResponse' => ['type' => 'object', 'additionalProperties' => true],

            // Shared bare { message } response (deployment and plugin handlers)
            'SimpleMessageResponse' => self::object(['message' => self::str()], ['message']),

            // POST /api/deployments/apply request body
            'DeploymentApplyRequest' => self::object([
                'version' => self::str(),
                'source_path' => self::str(),
            ], ['version', 'source_path']),

            // GET /api/deployments/status — free-form data object
            'DeploymentStatusResponse' => self::object([
                'data' => ['type' => 'object', 'additionalProperties' => true],
            ], ['data']),

            // GET /api/migrations
            'MigrationEntry' => self::object([
                'name' => self::str(),
                'executed' => self::bool(),
                'executed_at' => self::str(true),
            ], ['name', 'executed', 'executed_at']),
            'MigrationListResponse' => self::listEnvelope('MigrationEntry'),

            // GET /api/plugins
            'PluginEntry' => self::object([
                'id' => self::str(),
                'name' => self::str(),
                'enabled' => self::bool(),
                'file' => self::str(true),
                'status' => self::str(),
                'version' => self::str(),
                'routes_count' => self::int(),
                'permissions_count' => self::int(),
            ], ['id', 'name', 'enabled', 'file']),
            // WC-210: the list carries a typed propagation/staleness indicator so
            // clients know the per-plugin state is worker-local and admin changes
            // converge across workers on reload/restart.
            'PluginListMeta' => self::object([
                'worker_local' => self::bool(),
                'note' => self::str(),
            ], ['worker_local', 'note']),
            'PluginListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('PluginEntry')],
                'meta' => SchemaBuilder::ref('PluginListMeta'),
            ], ['data', 'meta']),
            // POST /api/plugins/upload (WC-220): a freshly staged plugin entry.
            'PluginUploadResponse' => self::object([
                'data' => SchemaBuilder::ref('PluginEntry'),
            ], ['data']),

            // GET /api/admin/stats
            'AdminStatsResponse' => self::object([
                'stats' => self::object([
                    'totals' => self::object([
                        'users' => self::int(),
                        'tenants' => self::int(),
                        'roles' => self::int(),
                    ], ['users', 'tenants', 'roles']),
                    'breakdown' => ['type' => 'object', 'additionalProperties' => true],
                    'growth' => ['type' => 'object', 'additionalProperties' => true],
                    'system' => self::object([
                        'migrations_executed' => self::int(),
                        'migrations_total' => self::int(),
                        'pending_migrations' => self::int(),
                        'database' => ['type' => 'object', 'additionalProperties' => true],
                    ], ['migrations_executed', 'migrations_total', 'pending_migrations', 'database']),
                ], ['totals', 'breakdown', 'growth', 'system']),
            ], ['stats']),

            // ---- Family-Relations schemas (WC-f07c870b) ----

            // Relationship-type vocabulary
            'RelationshipType' => self::object([
                'id' => self::int(),
                'name' => self::str(),
                'inverseTypeId' => self::int(true),
                'symmetric' => self::bool(),
            ], ['id', 'name', 'symmetric']),
            'RelationshipTypeListResponse' => self::listEnvelope('RelationshipType'),

            // RelationSummary — one directed edge from a person's perspective
            'RelationSummary' => self::object([
                'relationId' => self::int(),
                'otherPersonId' => self::int(),
                'otherPersonName' => self::str(),
                'otherPersonHasAccount' => self::bool(),
                'typeId' => self::int(),
                'typeName' => self::str(),
                'direction' => self::str(),
            ], ['relationId', 'otherPersonId', 'otherPersonName', 'otherPersonHasAccount', 'typeId', 'typeName', 'direction']),
            'RelationSummaryListResponse' => self::listEnvelope('RelationSummary'),

            // Person — includes embedded relations array
            'Person' => self::object([
                'id' => self::int(),
                'tenantId' => self::int(),
                'displayName' => self::str(),
                'userId' => self::int(true),
                'hasAccount' => self::bool(),
                'birthDate' => self::str(true),
                'deceased' => self::bool(),
                'notes' => self::str(true),
                'createdAt' => self::str(true),
                'relationCount' => self::int(),
                'relations' => ['type' => 'array', 'items' => SchemaBuilder::ref('RelationSummary')],
            ], ['id', 'tenantId', 'displayName', 'hasAccount', 'deceased', 'relationCount', 'relations']),
            'PersonListResponse' => self::paginatedListEnvelope('Person'),
            'PersonResponse' => self::dataEnvelope(SchemaBuilder::ref('Person')),

            // Person create / update request bodies
            'PersonCreateRequest' => self::object([
                'displayName' => self::str(),
                'birthDate' => self::str(true),
                'deceased' => self::bool(),
                'notes' => self::str(true),
            ], ['displayName']),
            'PersonUpdateRequest' => self::object([
                'displayName' => self::str(),
                'birthDate' => self::str(true),
                'deceased' => self::bool(),
                'notes' => self::str(true),
            ], []),

            // RelationEdge — full edge row with both type names
            'RelationEdge' => self::object([
                'id' => self::int(),
                'fromPersonId' => self::int(),
                'toPersonId' => self::int(),
                'typeId' => self::int(),
                'typeName' => self::str(),
                'inverseTypeName' => self::str(true),
            ], ['id', 'fromPersonId', 'toPersonId', 'typeId', 'typeName']),
            'RelationEdgeListResponse' => self::paginatedListEnvelope('RelationEdge'),

            // GET /api/users/{id}/relations — inline data envelope (personId may be null)
            'UserRelationsResponse' => self::dataEnvelope(self::object([
                'personId' => self::int(true),
                'relations' => ['type' => 'array', 'items' => SchemaBuilder::ref('RelationSummary')],
            ], ['personId', 'relations'])),

            // Relation create request and response
            'RelationRef' => self::object([
                'type' => ['type' => 'string', 'enum' => ['user', 'person']],
                'id' => self::int(),
            ], ['type', 'id']),
            'RelationCreateRequest' => self::object([
                'from' => SchemaBuilder::ref('RelationRef'),
                'to' => SchemaBuilder::ref('RelationRef'),
                'relationshipTypeId' => self::int(),
            ], ['from', 'to', 'relationshipTypeId']),
            'RelationCreatedData' => self::object([
                'id' => self::int(),
                'fromPersonId' => self::int(),
                'toPersonId' => self::int(),
                'relationshipTypeId' => self::int(),
            ], ['id', 'fromPersonId', 'toPersonId', 'relationshipTypeId']),
            'RelationCreatedResponse' => self::dataEnvelope(SchemaBuilder::ref('RelationCreatedData')),

            // ---- Website Settings schemas ----

            // The four known string-valued settings (the registry's keys). Every
            // key is always present in an effective/global value map.
            'SettingsValueMap' => self::object([
                'site_name' => self::str(),
                'timezone' => self::str(),
                'locale' => self::str(),
                'support_email' => self::str(),
            ], ['site_name', 'timezone', 'locale', 'support_email']),
            // One registry descriptor: key + value-type + hardcoded default.
            'SettingsRegistryEntry' => self::object([
                'key' => self::str(),
                'type' => self::str(),
                'default' => self::str(),
            ], ['key', 'type', 'default']),
            // GET /api/settings — effective values, registry shape, overridden
            // keys, and (WC-224) whether the caller's tenant has a per-tenant
            // override layer (false for the system tenant 0 → the UI hides the
            // editable tenant form and points at Global defaults instead).
            'SettingsResponse' => self::dataEnvelope(self::object([
                'effective' => SchemaBuilder::ref('SettingsValueMap'),
                'registry' => ['type' => 'array', 'items' => SchemaBuilder::ref('SettingsRegistryEntry')],
                'overridden' => ['type' => 'array', 'items' => self::str()],
                'tenant_overridable' => self::bool(),
            ], ['effective', 'registry', 'overridden', 'tenant_overridable'])),
            // GET /api/settings/global — the global defaults plus registry shape.
            'GlobalSettingsResponse' => self::dataEnvelope(self::object([
                'global' => SchemaBuilder::ref('SettingsValueMap'),
                'registry' => ['type' => 'array', 'items' => SchemaBuilder::ref('SettingsRegistryEntry')],
            ], ['global', 'registry'])),
            // PATCH response — the recomputed value map (effective or global).
            'SettingsValueMapResponse' => self::dataEnvelope(SchemaBuilder::ref('SettingsValueMap')),
            // PATCH request — a `settings` object of key => value (string or null
            // to clear). additionalProperties keeps it open to future registry
            // keys without an OpenAPI change.
            'SettingsUpdateRequest' => self::object([
                'settings' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'string', 'nullable' => true],
                ],
            ], ['settings']),

            // ---- Tenant Branding schemas (WC-233) ----

            // The effective branding for a tenant: site name + up to three asset
            // URLs (null when unset). The API exposes ONLY these fields — no other
            // settings are included — so callers can safely cache without leaking
            // tenant data.
            'Branding' => self::object([
                'siteName' => self::str(),
                'logoWideUrl' => ['type' => 'string', 'nullable' => true],
                'logoSquareUrl' => ['type' => 'string', 'nullable' => true],
                'faviconUrl' => ['type' => 'string', 'nullable' => true],
            ], ['siteName', 'logoWideUrl', 'logoSquareUrl', 'faviconUrl']),
            // GET /api/branding — the standard data envelope around Branding.
            'BrandingResponse' => self::dataEnvelope(SchemaBuilder::ref('Branding')),
            // PUT /api/tenants/{id}/branding-host request body.
            'BrandingHostRequest' => self::object(['host' => self::str(true)], []),
            // PUT /api/tenants/{id}/branding-host response body.
            'BrandingHostResponse' => self::dataEnvelope(self::object(['branding_host' => self::str(true)], ['branding_host'])),
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
     * @return array<string, mixed>
     */
    private static function paginatedListEnvelope(string $component): array
    {
        return self::object(
            [
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref($component)],
                'pagination' => SchemaBuilder::ref('Pagination'),
            ],
            ['data', 'pagination']
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

    /**
     * @return array<string, mixed>
     */
    private static function bool(bool $nullable = false): array
    {
        return $nullable ? ['type' => 'boolean', 'nullable' => true] : ['type' => 'boolean'];
    }
}
