<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

use Whity\Core\Ou\OuTypeRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\PasswordPolicy;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Http\InputLimits;

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
            self::authRoutes(),
            self::userRoutes(),
            self::roleRoutes(),
            self::tenantRoutes(),
            self::ouRoutes(),
            self::ouTypeRoutes(),
            self::delegationRoutes(),
            self::auditRoutes(),
            self::frontendFeatureRoutes(),
            self::meRoutes(),
            self::meNotificationRoutes(),
            self::tenantNotificationSettingsRoutes(),
            self::platformOpsRoutes(),
            self::familyRelationsRoutes(),
            self::settingsRoutes(),
            self::languageRoutes(),
            self::translationManagementRoutes(),
            self::brandingRoutes(),
            self::themeRoutes(),
            self::identityRoutes(),
            self::meEmailsRoutes(),
            self::tenantEmailDomainRoutes(),
            self::tenantEntitlementRoutes(),
            self::tenantStorageRoutes(),
            self::planRoutes(),
            self::subscriptionRoutes(),
            self::documentTemplateRoutes(),
            self::documentBlockRoutes(),
            self::documentRecordRoutes(),
            self::documentRoutingRoutes(),
            self::meInboxRoutes(),
            self::userGroupRoutes(),
            self::documentCollectionRoutes(),
            self::instanceRoutes(),
            self::twoFactorPolicyRoutes(),
            self::tagRoutes(),
            self::passwordResetRoutes(),
            self::invitationRoutes(),
            self::twoFactorRecoveryRoutes(),
            self::dataTypeRoutes(),
            self::resourceRoleGrantRoutes()
        );
    }

    /**
     * First-run instance lifecycle surface (WC-instance-first-run).
     *
     * GET /api/instance/status is authenticated but unpermissioned (any signed-in
     * caller reads it to drive onboarding routing); POST /api/instance/complete-setup
     * is settings:manage (and system-tenant-only, enforced in the handler).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function instanceRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/instance/status',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'First-run + version probe (drives onboarding routing)',
                    'tags' => ['instance'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'Whether guided first-run setup is complete, plus the running core version',
                            'InstanceStatusResponse'
                        ),
                    ] + self::authErrors(),
                ],
            ],
            self::permissionRoute('POST', '/api/instance/complete-setup', 'settings:manage', [
                'summary' => 'Mark guided first-run setup complete (system tenant only)',
                'tags' => ['instance'],
                'responses' => [
                    200 => self::jsonResponse('First-run setup marked complete', 'InstanceCompleteSetupResponse'),
                    422 => self::errorResponse('Not the system tenant — first-run setup is an operator/global action'),
                ] + self::authErrors(),
            ]),
        ];
    }


    /**
     * Auth surface route declarations: login, 2FA login-completion,
     * multi-tenant selection, /me (read + self-service update),
     * token refresh/logout, and all /api/auth/2fa/* management routes.
     *
     * All shapes are derived directly from AuthHandler and TwoFactorHandler
     * (post-identity-rewrite, WC-388a61e3). No auth gate is declared on the
     * public login paths (they are unauthenticated); the /me, refresh, logout,
     * and 2FA management routes are self-authenticated via HTTP-only cookie and
     * carry no requiredRole/requiredPermission (the handlers validate the
     * access-token cookie themselves).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function authRoutes(): array
    {
        // ── Public login surface ─────────────────────────────────────────────

        // POST /api/login  — AuthHandler::handle()
        // Returns 200 (session issued), 202 (2FA required), or 200 with
        // requires_tenant_selection for multi-membership profiles.
        $loginRoute = [
            'method' => 'POST',
            'path' => '/api/login',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Authenticate with email and password',
                'tags' => ['auth'],
                'request' => 'LoginRequest',
                'responses' => [
                    200 => self::jsonResponse(
                        'Session issued (single membership) or tenant-selection prompt (multi-membership)',
                        'LoginResponse'
                    ),
                    202 => self::jsonResponse('2FA challenge — temp token set in cookie', 'Login2faRequiredResponse'),
                    401 => self::errorResponse('Invalid credentials or unverified email'),
                    429 => self::errorResponse('Too many attempts'),
                ],
            ],
        ];

        // POST /api/login/2fa  — AuthHandler::handle2fa()
        // Completes the 2FA challenge issued by POST /api/login.
        // On success may return a session (200) or a tenant-selection prompt.
        $login2faRoute = [
            'method' => 'POST',
            'path' => '/api/login/2fa',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Complete login with a TOTP code or backup code',
                'tags' => ['auth'],
                'request' => 'TwoFaLoginRequest',
                'responses' => [
                    200 => self::jsonResponse(
                        'Session issued, or tenant-selection prompt when the profile has multiple memberships',
                        'LoginResponse'
                    ),
                    401 => self::errorResponse('Invalid or expired temp token, or invalid 2FA code'),
                    429 => self::errorResponse('Too many attempts'),
                ],
            ],
        ];

        // POST /api/auth/select-tenant  — AuthHandler::handleSelectTenant()
        // ADR 0005 §6: completes a multi-membership login by selecting a tenant.
        $selectTenantRoute = [
            'method' => 'POST',
            'path' => '/api/auth/select-tenant',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Select the active tenant after a multi-membership login',
                'tags' => ['auth'],
                'request' => 'SelectTenantRequest',
                'responses' => [
                    200 => self::jsonResponse('Session issued for the selected tenant', 'SessionUserResponse'),
                    400 => self::errorResponse('tenant_id missing or not numeric'),
                    401 => self::errorResponse('No pending selection token, expired/invalid token, or invalid tenant'),
                ],
            ],
        ];

        // ── Authenticated session surface ────────────────────────────────────

        // GET /api/me  — AuthHandler::handleMe()
        // Returns the caller's session claims (id/email/role/tenant_id).
        $getMeRoute = [
            'method' => 'GET',
            'path' => '/api/me',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Return the current authenticated user from the access-token claims',
                'tags' => ['auth'],
                'responses' => [
                    // GET (handleMe) returns tenant_id from the token claims;
                    // PATCH (shapeSelf) does NOT — the two paths have distinct shapes.
                    200 => self::jsonResponse('The caller\'s session identity (includes tenant_id)', 'MeGetResponse'),
                    401 => self::errorResponse('Missing or invalid access token'),
                ],
            ],
        ];

        // PATCH /api/me  — AuthHandler::handleUpdateMe()
        // Self-service email/password change; re-issues auth cookies on success.
        $patchMeRoute = [
            'method' => 'PATCH',
            'path' => '/api/me',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Self-service profile update (email and/or password)',
                'tags' => ['auth'],
                'request' => 'MeUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('Updated self profile; auth cookies re-issued', 'MeResponse'),
                    400 => self::errorResponse('No changes, invalid email format, or password too short'),
                    401 => self::errorResponse('Missing/invalid token, or current password incorrect'),
                    // #917: an IdP-backed account has no local password, so it
                    // can satisfy neither the current-password gate nor the
                    // change it guards - said plainly rather than as a wrong 401.
                    409 => self::errorResponse(
                        'Email already exists in the tenant, or this account signs in through an '
                        . 'identity provider and has no local password'
                    ),
                ],
            ],
        ];

        // POST /api/auth/refresh  — AuthHandler::handleRefresh()
        // Issues a new access-token cookie from a valid refresh-token cookie.
        $refreshRoute = [
            'method' => 'POST',
            'path' => '/api/auth/refresh',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Issue a new access token from the refresh-token cookie',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('New access-token cookie set; body carries status:success', 'RefreshResponse'),
                    401 => self::errorResponse('Missing, invalid, expired, or revoked refresh token'),
                    429 => self::errorResponse('Too many attempts'),
                ],
            ],
        ];

        // POST /api/auth/logout  — AuthHandler::handleLogout()
        // Revokes both jtis and clears auth cookies. Idempotent.
        $logoutRoute = [
            'method' => 'POST',
            'path' => '/api/auth/logout',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Logout — revoke both auth tokens and clear cookies',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('Always 200; cookies cleared', 'LogoutResponse'),
                ],
            ],
        ];

        // ── 2FA management surface (all require a valid access-token cookie) ─

        // POST /api/auth/2fa/setup  — TwoFactorHandler::setup()
        $setupRoute = [
            'method' => 'POST',
            'path' => '/api/auth/2fa/setup',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Generate a TOTP secret and QR-code URL for 2FA enrolment',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('The plaintext TOTP secret and a QR-code URL for the authenticator app', 'TwoFaSetupResponse'),
                    400 => self::errorResponse('2FA is already enabled'),
                    401 => self::errorResponse('Missing or invalid access token'),
                    404 => self::errorResponse('User/profile not found'),
                    500 => self::errorResponse('Internal error'),
                ],
            ],
        ];

        // POST /api/auth/2fa/confirm  — TwoFactorHandler::confirm()
        $confirmRoute = [
            'method' => 'POST',
            'path' => '/api/auth/2fa/confirm',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Confirm 2FA setup with a TOTP code — stores the encrypted secret and generates backup codes',
                'tags' => ['auth'],
                'request' => 'TwoFaConfirmRequest',
                'responses' => [
                    200 => self::jsonResponse('2FA enabled; 15 plaintext backup codes returned (store immediately — not stored in plaintext)', 'TwoFaConfirmResponse'),
                    400 => self::errorResponse('code or secret missing'),
                    401 => self::errorResponse('Missing/invalid access token, or invalid TOTP code'),
                    500 => self::errorResponse('Internal error'),
                ],
            ],
        ];

        // POST /api/auth/2fa/disable  — TwoFactorHandler::disable()
        $disableRoute = [
            'method' => 'POST',
            'path' => '/api/auth/2fa/disable',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Disable 2FA — clears the stored secret and invalidates all backup codes',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('2FA disabled', 'SimpleMessageResponse'),
                    401 => self::errorResponse('Missing or invalid access token'),
                    404 => self::errorResponse('User/profile not found'),
                    500 => self::errorResponse('Internal error'),
                ],
            ],
        ];

        // POST /api/auth/2fa/regenerate-codes  — TwoFactorHandler::regenerateCodes()
        $regenerateCodesRoute = [
            'method' => 'POST',
            'path' => '/api/auth/2fa/regenerate-codes',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Invalidate existing backup codes and generate 15 new ones',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('15 new plaintext backup codes; old codes are invalidated', 'TwoFaRegenerateCodesResponse'),
                    400 => self::errorResponse('2FA is not enabled'),
                    401 => self::errorResponse('Missing or invalid access token'),
                    404 => self::errorResponse('User/profile not found'),
                    500 => self::errorResponse('Internal error'),
                ],
            ],
        ];

        // GET /api/auth/2fa/status  — TwoFactorHandler::status()
        $statusRoute = [
            'method' => 'GET',
            'path' => '/api/auth/2fa/status',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Return the caller\'s 2FA enabled flag and available backup-code count',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('2FA status and backup-code availability', 'TwoFaStatusResponse'),
                    401 => self::errorResponse('Missing or invalid access token'),
                    404 => self::errorResponse('User/profile not found'),
                    500 => self::errorResponse('Internal error'),
                ],
            ],
        ];

        // POST /api/auth/switch-tenant — AuthHandler::handleSwitchTenant()
        // WC-f8164c87: authenticated tenant switch. Requires a full session
        // (access token cookie); re-mints the JWT with the new active_tenant_id
        // after validating the caller holds an ACTIVE membership there.
        $switchTenantRoute = [
            'method' => 'POST',
            'path' => '/api/auth/switch-tenant',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Switch the active tenant for an already-logged-in profile',
                'tags' => ['auth'],
                'request' => 'SwitchTenantRequest',
                'responses' => [
                    200 => self::jsonResponse('Session re-issued for the chosen tenant; auth cookies replaced', 'SessionUserResponse'),
                    400 => self::errorResponse('tenant_id missing or not numeric'),
                    401 => self::errorResponse('Missing, invalid, or legacy-only access token'),
                    403 => self::errorResponse('Profile has no active membership in the requested tenant'),
                ],
            ],
        ];

        return [
            $loginRoute,
            $login2faRoute,
            $selectTenantRoute,
            $switchTenantRoute,
            $getMeRoute,
            $patchMeRoute,
            $refreshRoute,
            $logoutRoute,
            $setupRoute,
            $confirmRoute,
            $disableRoute,
            $regenerateCodesRoute,
            $statusRoute,
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function userRoutes(): array
    {
        return [
            // #839: same undeclared pagination as GET /api/roles — the handler
            // paginates, UserListResponse carries the envelope, and the query
            // parameters were missing from the spec.
            self::adminRoute('GET', '/api/users', [
                'summary' => 'List the tenant\'s users',
                'tags' => ['users'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100). A client that needs every user must follow the `pagination` envelope to the last page; one request only ever describes page one.'),
                ],
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
                    // #917: `role`/`role_id` present but empty or null is a 400,
                    // distinct from omitting it (which still defaults to the
                    // global `user` role). A named field with nothing behind it
                    // is not a request for the default.
                    400 => self::errorResponse('Validation failed, or a role field was supplied empty'),
                    404 => self::errorResponse('Declared role not found or not visible'),
                    409 => self::errorResponse('Email already exists in the tenant'),
                    422 => self::errorResponse('Email longer than 255 characters'),
                ] + self::authErrors(),
            ]),
            // #882: the single-record read a record page is built on. Tenant
            // scoped exactly like the list — a non-system caller reads only a
            // membership in its OWN tenant, and a profile without one here is a
            // 404 rather than a leak that the profile exists somewhere.
            self::adminRoute('GET', '/api/users/{id:\d+}', [
                'summary' => 'Read one user',
                'tags' => ['users'],
                'responses' => [
                    200 => self::jsonResponse('The user', 'UserResponse'),
                    404 => self::errorResponse('User not found in this tenant'),
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
                    // Two causes share the 409: a duplicate email, and a
                    // `password` for an IdP-backed account without
                    // `allowLocalPasswordOnIdpAccount`.
                    409 => self::errorResponse(
                        'Email already exists in the tenant, or a local password was set on an '
                        . 'identity-provider-backed account without the explicit override'
                    ),
                    422 => self::errorResponse('Email longer than 255 characters'),
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
            // WC-712 §1: a profile may hold more than one role in a tenant. The
            // user list carries only the PRIMARY one (one row per person), so
            // these are where an additional role is seen, granted and revoked.
            // #797 §2: they are also where a profile is attached to a tenant it
            // is not yet in — the only write path that names a tenant other than
            // the caller's, and only a tenant-0 caller may use it.
            self::adminRoute('GET', '/api/users/{id:\d+}/memberships', [
                'summary' => 'List the roles a user holds (every tenant for a system-tenant caller)',
                'tags' => ['users'],
                'responses' => [
                    200 => self::jsonResponse('The user\'s memberships, primary first', 'MembershipListResponse'),
                    404 => self::errorResponse('User not found'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('POST', '/api/users/{id:\d+}/memberships', [
                'summary' => 'Grant a user a role, optionally in another tenant (system tenant only)',
                'tags' => ['users'],
                'request' => 'MembershipCreateRequest',
                'responses' => [
                    // 200 rather than 201 when the role is already held with the
                    // same OU: the call is idempotent and reports created=false.
                    200 => self::jsonResponse('The membership already existed', 'MembershipResponse'),
                    201 => self::jsonResponse('The membership that was created', 'MembershipResponse'),
                    400 => self::errorResponse('Validation failed'),
                    // Overrides the generic authErrors() 403 so the tenant-naming
                    // refusal is discoverable from the contract rather than only
                    // from the response body.
                    403 => self::errorResponse('Insufficient permissions, or a non-system caller named a target tenant'),
                    404 => self::errorResponse('User, tenant or role not found'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('DELETE', '/api/users/{id:\d+}/memberships/{membershipId:\d+}', [
                'summary' => 'Revoke one of a user\'s additional roles',
                'tags' => ['users'],
                'responses' => [
                    200 => self::jsonResponse('Removal confirmation', 'MutationResponse'),
                    404 => self::errorResponse('User or membership not found'),
                    409 => self::errorResponse('The primary membership cannot be removed here'),
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
            // #839: the handler has read PaginationParams since it was written and
            // RoleListResponse has always carried the envelope, but the query
            // parameters that drive it were never declared — so the generated
            // client typed `query: never` and a picker literally could not ask
            // for page 2 without lying about the contract. Declaring them changes
            // no behaviour; it stops the published spec understating what the
            // endpoint does.
            self::permissionRoute('GET', '/api/roles', CorePermissions::ROLES_READ, [
                'summary' => 'List the roles visible to the tenant (own + global)',
                'tags' => ['roles'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100). A client that needs every role must follow the `pagination` envelope to the last page; one request only ever describes page one.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('Visible roles with permission counts', 'RoleListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/roles', CorePermissions::ROLES_WRITE, [
                'summary' => 'Create a role, owned by the caller\'s tenant unless a system caller names another',
                'tags' => ['roles'],
                'request' => 'RoleCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created role', 'RoleCreateResponse'),
                    400 => self::errorResponse('Validation failed, or tenant_id and global both sent'),
                    403 => self::errorResponse('Only the system tenant may name a target tenant or create a global role'),
                    404 => self::errorResponse('The named target tenant does not exist'),
                    409 => self::errorResponse('Role name already exists'),
                    422 => self::errorResponse('name over 255 or description over 10000 characters'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/roles/{id:\d+}', CorePermissions::ROLES_READ, [
                'summary' => 'Get a role with its permissions',
                'tags' => ['roles'],
                'responses' => [
                    200 => self::jsonResponse('The role and its permissions', 'RoleDetailResponse'),
                    404 => self::errorResponse('Role not found or not visible'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/roles/{id:\d+}', CorePermissions::ROLES_WRITE, [
                'summary' => 'Update a role (permissions are replaced when supplied)',
                'tags' => ['roles'],
                'request' => 'RoleUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('Update confirmation', 'MutationResponse'),
                    404 => self::errorResponse('Role not found or not manageable by the tenant'),
                    409 => self::errorResponse('Role name already exists'),
                    422 => self::errorResponse('name over 255 or description over 10000 characters'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/roles/{id:\d+}', CorePermissions::ROLES_DELETE, [
                'summary' => 'Delete a role',
                'tags' => ['roles'],
                'responses' => [
                    200 => self::jsonResponse('Deletion confirmation', 'MutationResponse'),
                    404 => self::errorResponse('Role not found or not manageable by the tenant'),
                    409 => self::errorResponse('Role has active user assignments'),
                ] + self::authErrors(),
            ]),
            // #882: the record page's "12 users hold this role, most recently
            // user3". Ordered by grant time (memberships.created_at) newest
            // first, so page one is the recent-assignment history and
            // `pagination.total` is the headcount — one request for both, and no
            // client-side count over every user in the tenant.
            self::permissionRoute('GET', '/api/roles/{id:\d+}/assignments', CorePermissions::ROLES_READ, [
                'summary' => 'List who holds this role, newest grant first (total = headcount)',
                'tags' => ['roles'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The role\'s holders with pagination', 'RoleAssignmentListResponse'),
                    404 => self::errorResponse('Role not found or not visible'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/roles/{id:\d+}/permissions', CorePermissions::PERMISSIONS_READ, [
                'summary' => 'List a role\'s permissions',
                'tags' => ['roles'],
                'responses' => [
                    200 => self::jsonResponse('The role\'s permissions', 'PermissionListResponse'),
                    404 => self::errorResponse('Role not found or not visible'),
                ] + self::authErrors(),
            ]),
            // #712: additive/subtractive grants. PATCH replaces the whole set,
            // so adding one permission means reading the set and writing it back
            // — and two admins doing that at once silently lose one edit. These
            // send only the delta, and are idempotent in both directions.
            self::permissionRoute('POST', '/api/roles/{id:\d+}/permissions', CorePermissions::ROLES_MANAGE, [
                'summary' => 'Grant permissions to a role (additive, idempotent)',
                'tags' => ['roles'],
                'request' => 'RolePermissionsChangeRequest',
                'responses' => [
                    200 => self::jsonResponse('The grants added and the resulting set', 'RolePermissionsGrantResponse'),
                    400 => self::errorResponse('permissions missing or not an array'),
                    404 => self::errorResponse('Role not found or not manageable by the tenant'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/roles/{id:\d+}/permissions', CorePermissions::ROLES_MANAGE, [
                'summary' => 'Revoke permissions from a role (subtractive, idempotent)',
                'tags' => ['roles'],
                'request' => 'RolePermissionsChangeRequest',
                'responses' => [
                    200 => self::jsonResponse('The grants removed and the resulting set', 'RolePermissionsRevokeResponse'),
                    400 => self::errorResponse('permissions missing or not an array'),
                    404 => self::errorResponse('Role not found or not manageable by the tenant'),
                ] + self::authErrors(),
            ]),
            self::adminRoute('GET', '/api/permissions', [
                'summary' => 'List the permission catalogue',
                'tags' => ['roles'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100). Pickers pass per_page=100 to fetch the whole catalogue.'),
                ],
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
                    201 => self::jsonResponse('The created tenant', 'TenantCreatedResponse'),
                    400 => self::errorResponse('Validation failed'),
                    404 => self::errorResponse('The requested initial administrator role does not exist'),
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
     * OU management, gated on the seeded ous:* permissions rather than the bare
     * `admin` role so a plugin aliasing these operations inherits the platform's
     * authority model instead of inventing a slug of its own. Mirrors the wiring
     * in public/index.php — the spec and the live router must agree on the gate.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function ouRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/ous', 'ous:read', [
                'summary' => 'List the tenant\'s organizational units',
                'description' => 'Paginated. A client that needs the whole hierarchy (to build a tree) '
                    . 'must follow the `pagination` envelope to the last page — `per_page` is capped at '
                    . '100, so no single request is guaranteed to return every unit.',
                'tags' => ['ous'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100). This is the platform-wide name; `perPage` and `limit` are not accepted.'),
                    self::queryParam('parent_id', 'integer', 'Return only the direct children of this OU. Use 0 for the roots. Omit or leave empty for the whole tenant; a non-numeric value is a 422.'),
                    self::queryParam('type', 'string', 'Return only units of this KIND, by the stable type key (e.g. faculty, acme:clinic). Use `none` for units with no type. Omit or leave empty for every unit; a malformed key is a 422, and a well-formed key this tenant has not defined matches nothing.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s organizational units', 'OuListResponse'),
                    422 => self::errorResponse('parent_id is not a non-negative integer, or type is not a valid type key'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/ous', 'ous:write', [
                'summary' => 'Create an organizational unit',
                'tags' => ['ous'],
                'request' => 'OuCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created organizational unit', 'OuResponse'),
                    400 => self::errorResponse('Validation failed'),
                    409 => self::errorResponse('A sibling unit already has this name, or no unique slug could be derived'),
                    422 => self::errorResponse('The requested organizational unit type is unknown or belongs to another tenant'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/ous/{id:\d+}', 'ous:read', [
                'summary' => 'Get an organizational unit with its direct children',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The organizational unit and its children', 'OuDetailResponse'),
                    404 => self::errorResponse('Organizational unit not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/ous/{id:\d+}', 'ous:write', [
                'summary' => 'Update an organizational unit (re-parenting is cycle-checked)',
                'tags' => ['ous'],
                'request' => 'OuUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('Update confirmation', 'MutationResponse'),
                    409 => self::errorResponse('A sibling unit already has this name'),
                    422 => self::errorResponse('The re-parent would create a cycle, or the requested type is unknown'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/ous/{id:\d+}', 'ous:delete', [
                'summary' => 'Delete an organizational unit',
                'tags' => ['ous'],
                'responses' => [
                    204 => ['description' => 'Deleted'],
                    409 => self::errorResponse('Organizational unit still has children or users'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/ous/{id:\d+}/roles', 'ous:read', [
                'summary' => 'List the roles assigned to an organizational unit',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The assigned roles', 'RoleSummaryListResponse'),
                    404 => self::errorResponse('Organizational unit not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/ous/{id:\d+}/members', 'ous:read', [
                'summary' => 'List the users assigned to an organizational unit',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The assigned users', 'UserListResponse'),
                    404 => self::errorResponse('Organizational unit not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/ous/{id:\d+}/roles', 'ous:assign', [
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
            self::permissionRoute('DELETE', '/api/ous/{ouId:\d+}/roles/{roleId:\d+}', 'ous:assign', [
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
     * The tenant's OU TYPE vocabulary (#822) — the campus/faculty/department
     * levels its tree is built from.
     *
     * Gated on the SAME `ous:*` permissions as the OU routes above rather than a
     * new `ou_types:*` pair: a new permission ships with a grant migration that
     * reaches only the seeded `admin` role, so every operator running a custom
     * administrative role would silently lose the capability (#834). DELETE takes
     * `ous:write`, not `ous:delete`, because it destroys no unit — its forced
     * path is an UPDATE that clears `ou_type_id`.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function ouTypeRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/ou-types', 'ous:read', [
                'summary' => "List the tenant's organizational unit types",
                'description' => 'Returned in rank order (`sort_order`, then key): a campus outranks a '
                    . 'faculty outranks a department, and that ordering is data rather than presentation.',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse("The tenant's OU type vocabulary", 'OuTypeListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/ou-types/catalog', 'ous:read', [
                'summary' => 'List the OU types declared in code, with this tenant\'s adoption state',
                'description' => 'Core and plugin declarations. A plugin\'s keys are namespaced under the '
                    . 'plugin (`acme:clinic`); adopting one with POST /api/ou-types copies its declared '
                    . 'label and rank in as the tenant\'s starting values.',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The declared catalogue', 'OuTypeCatalogResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/ou-types', 'ous:write', [
                'summary' => 'Author a new OU type, or adopt a declared one',
                'tags' => ['ous'],
                'request' => 'OuTypeCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created type', 'OuTypeResponse'),
                    409 => self::errorResponse('The tenant already holds this key'),
                    422 => self::errorResponse('Malformed key, a namespaced key no plugin declares, or the reserved key `none`'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/ou-types/{id:\d+}', 'ous:read', [
                'summary' => 'Get one OU type',
                'tags' => ['ous'],
                'responses' => [
                    200 => self::jsonResponse('The type', 'OuTypeResponse'),
                    404 => self::errorResponse('Organizational unit type not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/ou-types/{id:\d+}', 'ous:write', [
                'summary' => 'Relabel or re-rank an OU type',
                'description' => 'The `key` is immutable — a routing rule binds to it, so editing it in '
                    . 'place would silently repoint every such rule at a type that no longer exists.',
                'tags' => ['ous'],
                'request' => 'OuTypeUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated type', 'OuTypeResponse'),
                    404 => self::errorResponse('Organizational unit type not found'),
                    422 => self::errorResponse('No updatable field supplied, or an attempt to change the key'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/ou-types/{id:\d+}', 'ous:write', [
                'summary' => 'Delete an OU type',
                'description' => 'Refused while any unit still carries the type, since deleting it would '
                    . 'untype them and make them invisible to every `?type=` rule that used to match. '
                    . 'Repeat with `?force=true` to untype them explicitly.',
                'tags' => ['ous'],
                'parameters' => [
                    self::queryParam('force', 'boolean', 'Untype the units that still carry this type instead of refusing.'),
                ],
                'responses' => [
                    204 => ['description' => 'Deleted'],
                    404 => self::errorResponse('Organizational unit type not found'),
                    409 => self::errorResponse('Units still carry this type; retry with ?force=true'),
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
                    self::queryParam('target_id', 'integer', 'Filter by target id — the history of ONE record. Normally paired with target_type; alone it matches that id across every target type.'),
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
            // #868: batch "would you let me make these requests?" resolution,
            // behind the `inbox` block type. Registered with NEITHER a required
            // role NOR a required permission for the same reason as
            // /api/me/capabilities — any authenticated caller may ask about
            // their OWN authority — and the handler fails closed itself. The
            // profile and tenant are the caller's resolved ones and are never
            // read from the body, so this cannot probe another user's authority;
            // the answers are UI hints and grant nothing.
            [
                'method' => 'POST',
                'path' => '/api/me/permitted-actions',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Resolve which of a batch of requests the caller is permitted to make',
                    'tags' => ['me'],
                    'request' => 'PermittedActionsRequest',
                    'responses' => [
                        200 => self::jsonResponse(
                            'One allow/deny answer per check, in request order',
                            'PermittedActionsResponse'
                        ),
                        422 => self::errorResponse("Missing 'checks' list, or more than 200 checks"),
                    ] + self::authErrors(),
                ],
            ],
            // Self-service analogue of GET /api/audit-logs (audit:read-gated,
            // see auditRoutes()): no permission gate here — every authenticated
            // caller may see their OWN activity. actor_user_id is pinned to the
            // caller server-side (AuditLogApiHandler::listOwn()); there is no
            // `actor` filter to widen this to another profile's rows.
            [
                'method' => 'GET',
                'path' => '/api/me/audit-logs',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the caller\'s own audit entries (newest first, paginated)',
                    'tags' => ['me'],
                    'parameters' => [
                        self::queryParam('action', 'string', 'Exact action match (e.g. users:create)'),
                        self::queryParam('target_type', 'string', 'Filter by target type'),
                        self::queryParam('target_id', 'integer', 'Filter by target id — the caller\'s own entries about ONE record'),
                        self::queryParam('from', 'string', 'Inclusive ISO-8601 lower bound'),
                        self::queryParam('to', 'string', 'Inclusive ISO-8601 upper bound'),
                        self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                        self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                    ],
                    'responses' => [
                        200 => self::jsonResponse('The caller\'s own audit entries with pagination', 'AuditLogListResponse'),
                    ] + self::authErrors(),
                ],
            ],
        ];
    }

    /**
     * In-app notification inbox surface (WC-notifications, 6e10d9ea). All routes
     * are self-scoped to the caller's (tenant, profile) and session-gated with NO
     * RBAC permission (any authenticated caller reads/mutates only their OWN
     * inbox), so they carry no requiredRole/requiredPermission — matching the
     * other /api/me self-service surfaces.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function meNotificationRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/me/notifications',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the caller\'s in-app notifications (newest first, paginated) + unread count',
                    'tags' => ['notifications'],
                    'parameters' => [
                        self::queryParam('unread', 'boolean', 'Restrict to unread notifications when truthy'),
                        self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                        self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                    ],
                    'responses' => [
                        200 => self::jsonResponse('The caller\'s inbox with pagination and unread count', 'NotificationListResponse'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/me/notifications/unread-count',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'The caller\'s unread-notification count (inbox badge)',
                    'tags' => ['notifications'],
                    'responses' => [
                        200 => self::jsonResponse('The unread count', 'UnreadCountResponse'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/me/notifications/read-all',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Mark all the caller\'s unread notifications read',
                    'tags' => ['notifications'],
                    'responses' => [
                        200 => self::jsonResponse('How many notifications were marked read', 'MarkAllReadResponse'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/me/notifications/{id:\d+}/read',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Mark one of the caller\'s notifications read (idempotent)',
                    'tags' => ['notifications'],
                    'responses' => [
                        204 => ['description' => 'Marked read'],
                        422 => self::errorResponse('A valid notification id is required'),
                        404 => self::errorResponse('Notification not found or not owned by the caller'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/me/notification-preferences',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'The caller\'s notification preferences + which types are transactional (locked)',
                    'tags' => ['notifications'],
                    'responses' => [
                        200 => self::jsonResponse('The caller\'s per-(type, channel) toggles', 'NotificationPreferencesResponse'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'PUT',
                'path' => '/api/me/notification-preferences',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Upsert a batch of the caller\'s notification toggles (transactional types cannot be disabled)',
                    'tags' => ['notifications'],
                    'request' => 'NotificationPreferencesUpdateRequest',
                    'responses' => [
                        200 => self::jsonResponse('The caller\'s updated preferences', 'NotificationPreferencesResponse'),
                        422 => self::errorResponse('Invalid preferences, or an attempt to disable a transactional type'),
                    ] + self::authErrors(),
                ],
            ],
        ];
    }

    /**
     * Per-tenant notification SENDER configuration (WC-notifications, d70c6083).
     * All routes are settings:manage-gated and tenant-scoped; provider
     * credentials are write-only (never returned).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function tenantNotificationSettingsRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/notification-settings', 'notification_settings:manage', [
                'summary' => 'List the tenant\'s per-channel sender config (credentials redacted)',
                'tags' => ['notifications'],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s sender config per channel', 'TenantNotificationSettingsListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/notification-settings/{channel}', 'notification_settings:manage', [
                'summary' => 'Upsert a channel\'s sender config (from/reply-to, transport, provider config)',
                'tags' => ['notifications'],
                'request' => 'TenantNotificationSettingsUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated channel sender config', 'TenantNotificationSettingsResponse'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/notification-settings/{channel}/credentials', 'notification_settings:manage', [
                'summary' => 'Set or clear a channel\'s provider credentials (write-only, encrypted at rest)',
                'tags' => ['notifications'],
                'request' => 'NotificationCredentialsRequest',
                'responses' => [
                    204 => ['description' => 'Credentials stored or cleared'],
                    400 => self::errorResponse('Missing credentials field'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/notification-settings/{channel}', 'notification_settings:manage', [
                'summary' => 'Remove a channel\'s sender config',
                'tags' => ['notifications'],
                'responses' => [
                    204 => ['description' => 'Channel configuration removed'],
                    404 => self::errorResponse('Channel configuration not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/notification-metrics', 'notifications:manage', [
                'summary' => 'Notification delivery metrics (counts, failure rate, queue depth, latency)',
                'tags' => ['notifications'],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s notification delivery metrics', 'NotificationMetricsResponse'),
                ] + self::authErrors(),
            ]),
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
            // WHIT-587: platform version state. settings:manage AND the system
            // tenant (the tenant half is enforced in the handler, which the
            // router cannot express) — it describes the whole deployment.
            self::permissionRoute('GET', '/api/platform/version', 'settings:manage', [
                'summary' => 'Running core, plugin-SDK and PHP versions (system tenant only)',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('The versions this deployment is running', 'PlatformVersionResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/platform/version/latest', 'settings:manage', [
                'summary' => 'Compare the running core against the latest published release (system tenant only)',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse(
                        'The comparison verdict — including `check_failed` when the release stream could not be reached',
                        'PlatformLatestReleaseResponse'
                    ),
                ] + self::authErrors(),
            ]),
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
            self::permissionRoute('POST', '/api/plugins/install-from-store', 'plugins:upload', [
                'summary' => 'Fetch a package from a trusted plugin store and stage it (lands disabled)',
                'description' => 'Downloads a plugin package from a store host that MUST be on the '
                    . 'operator `plugins.store_allowed_hosts` allowlist (SSRF control; empty ⇒ disabled), '
                    . 'and requires the `plugins.store_enabled` master switch (default true) to also be on, '
                    . 'then validates and stages it through the same hardened installer as an upload.',
                'tags' => ['platform-ops'],
                'request' => 'InstallFromStoreRequest',
                'responses' => [
                    201 => self::jsonResponse('Plugin staged (disabled)', 'PluginUploadResponse'),
                    400 => self::errorResponse('Invalid package, unsafe name, or unsafe archive'),
                    403 => self::errorResponse('Feature disabled or store host not in the allowlist'),
                    409 => self::errorResponse('A plugin with this name is already installed'),
                    422 => self::errorResponse('Missing/invalid fields, or plugin incompatible with this host'),
                    500 => self::errorResponse('Internal error'),
                    502 => self::errorResponse('The store package could not be fetched'),
                ],
            ]),
            self::permissionRoute('GET', '/api/plugins/store/allowed', 'plugins:read', [
                'summary' => 'List the trusted store hosts (for the store-browser UI)',
                'description' => 'Returns the operator `plugins.store_allowed_hosts` allowlist and whether '
                    . 'installing from a store is enabled (both the allowlist AND the `plugins.store_enabled` '
                    . 'master switch must be on). Read-only; makes no outbound request.',
                'tags' => ['platform-ops'],
                'responses' => [
                    200 => self::jsonResponse('Allowed store hosts', 'StoreAllowedHostsResponse'),
                    500 => self::errorResponse('Internal error'),
                ],
            ]),
            self::permissionRoute('GET', '/api/plugins/store/catalog', 'plugins:read', [
                'summary' => 'Browse (and search) a trusted store\'s public catalogue',
                'description' => 'Server-side proxy to a store\'s public catalogue for the admin UI. Query: '
                    . '`store_url` (a bare https origin that MUST be on the allowlist) and optional `q` '
                    . '(case-insensitive substring over slug/name/description/author/tags). The browser '
                    . 'never contacts the store directly.',
                'tags' => ['platform-ops'],
                'parameters' => [
                    ['name' => 'store_url', 'in' => 'query', 'required' => true, 'schema' => ['type' => 'string']],
                    ['name' => 'q', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ],
                'responses' => [
                    200 => self::jsonResponse('Store catalogue (optionally filtered)', 'StoreCatalogueResponse'),
                    403 => self::errorResponse('Feature disabled or store host not in the allowlist'),
                    422 => self::errorResponse('store_url is required or not a bare https origin'),
                    500 => self::errorResponse('Internal error'),
                    502 => self::errorResponse('The store catalogue could not be fetched'),
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
            self::permissionRoute('GET', '/api/profiles/{id:\d+}/relations', 'relations:read', [
                'summary' => 'Get the person record and relations for a profile',
                'tags' => ['relations'],
                'responses' => [
                    200 => self::jsonResponse('The profile\'s person and relations', 'ProfileRelationsResponse'),
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
            // No single required permission — each tab is individually RBAC-
            // filtered server-side, mirroring GET /api/navigation.
            [
                'method' => 'GET',
                'path' => '/api/settings/tabs',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the settings console tabs visible to the caller',
                    'tags' => ['settings'],
                    'responses' => [
                        200 => self::jsonResponse('The visible settings tabs, in display order', 'SettingsTabListResponse'),
                        403 => self::errorResponse('Unauthenticated or tenant not resolved'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Language management and user language preference routes.
     *
     * GET /api/v1/languages — public endpoint, returns list of available languages
     * (no auth required).
     * GET /api/v1/settings/language — authenticated, returns user's language preference
     * and list of available languages.
     * PATCH /api/v1/settings/language — authenticated, updates user's language preference.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function languageRoutes(): array
    {
        // The public language shape. `direction` travels WITH the language —
        // the client sets <html dir> from it, so switching language flips the
        // interface direction and there is no separate direction preference.
        $languageObject = self::object([
            'code' => self::str(),
            'name' => self::str(),
            'direction' => ['type' => 'string', 'enum' => ['ltr', 'rtl']],
        ], ['code', 'name', 'direction']);

        // Whether this instance offers more than one language at all
        // (`i18n.enabled`). Served on both END-USER payloads so a client can
        // hide its language switcher from one explicit field, rather than
        // inferring it from how many languages the catalogue happens to hold.
        $i18nEnabled = ['type' => 'boolean'];

        return [
            [
                'method' => 'GET',
                'path' => '/api/languages',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List available languages (public endpoint)',
                    'tags' => ['languages'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'The list of available languages',
                            self::object([
                                'languages' => ['type' => 'array', 'items' => $languageObject],
                                'i18n_enabled' => $i18nEnabled,
                            ], ['languages', 'i18n_enabled'])
                        ),
                        500 => self::errorResponse('Internal error'),
                    ],
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/settings/language',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Get the current user\'s language preference',
                    'tags' => ['languages'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'The user\'s EFFECTIVE language preference (null while i18n is disabled, whatever the profile stores) and the available languages',
                            self::object([
                                'language_code' => self::str(nullable: true),
                                'available_languages' => ['type' => 'array', 'items' => $languageObject],
                                'i18n_enabled' => $i18nEnabled,
                            ], ['language_code', 'available_languages', 'i18n_enabled'])
                        ),
                        403 => self::errorResponse('Authentication required'),
                        404 => self::errorResponse('User profile not found'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/settings/language',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Update the current user\'s language preference',
                    'tags' => ['languages'],
                    'request' => self::object([
                        'language_code' => self::str(nullable: true),
                    ], ['language_code']),
                    'responses' => [
                        200 => self::jsonResponse(
                            'The updated language preference',
                            self::object([
                                'language_code' => self::str(nullable: true),
                            ], ['language_code'])
                        ),
                        400 => self::errorResponse('Invalid request body'),
                        403 => self::errorResponse('Authentication required'),
                        422 => self::errorResponse('Invalid language code'),
                        503 => self::errorResponse('Language selection is disabled on this instance (i18n.enabled is off)'),
                    ] + self::authErrors(),
                ],
            ],
            // WC-583: admin language management. languages:manage is necessary
            // but not sufficient — the handler additionally requires the SYSTEM
            // tenant (id 0), since languages carry no tenant_id column at all.
            self::permissionRoute('POST', '/api/languages', 'languages:manage', [
                'summary' => 'Create a language (admin, system tenant only)',
                'tags' => ['languages'],
                'request' => 'LanguageCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created language', 'LanguageDataResponse'),
                    403 => self::errorResponse('Restricted to the system tenant'),
                    409 => self::errorResponse('A language with this code already exists'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/languages/{id:\d+}', 'languages:manage', [
                'summary' => 'Update a language\'s name and/or enabled status (admin, system tenant only)',
                'tags' => ['languages'],
                'request' => 'LanguageUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated language', 'LanguageDataResponse'),
                    403 => self::errorResponse('Restricted to the system tenant'),
                    404 => self::errorResponse('Language not found'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/admin/languages', 'languages:manage', [
                'summary' => 'List every language, including disabled ones (admin, system tenant only)',
                'tags' => ['languages'],
                'responses' => [
                    200 => self::jsonResponse('Every language with its full admin shape', 'LanguageListResponse'),
                    403 => self::errorResponse('Restricted to the system tenant'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * i18n admin management of translation rows (WC-583). Reads and writes are
     * gated on `translations:manage`; the row's tenant scope (system default vs
     * tenant override) follows the caller — see TranslationsApiHandler's class
     * docblock for the full System-Tenant Context write-access rule (404 vs 422).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function translationManagementRoutes(): array
    {
        return [
            // The public per-domain bundle (resolved fallback chain) — was
            // KNOWN_UNDOCUMENTED pending this task; now declared.
            [
                'method' => 'GET',
                'path' => '/api/translations/{language_code}/{domain}',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Get resolved translations for a language + domain (public)',
                    'tags' => ['languages'],
                    'responses' => [
                        200 => self::jsonResponse('The resolved key => translation map', 'TranslationBundleResponse'),
                        400 => self::errorResponse('Missing or invalid language_code/domain parameter'),
                        404 => self::errorResponse('Language not found or is disabled'),
                        500 => self::errorResponse('Internal error'),
                    ],
                ],
            ],
            self::permissionRoute('GET', '/api/translations/coverage', 'translations:manage', [
                'summary' => 'Translation coverage per language and domain (admin)',
                'description' => 'What still needs translating. Missing keys have no rows, so a plain '
                    . 'listing can only ever show work already done; this reports the gap between each '
                    . 'language and the source language, per domain.',
                'tags' => ['languages'],
                'responses' => [
                    200 => self::jsonResponse('Per-language, per-domain coverage counts', 'TranslationCoverageResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/translations', 'translations:manage', [
                'summary' => 'List raw translation rows for a language + domain (admin)',
                'tags' => ['languages'],
                'parameters' => [
                    self::queryParam('language_code', 'string', 'The language code (required)'),
                    self::queryParam('domain', 'string', 'The translation domain (required)'),
                    self::queryParam('untranslated', 'string', 'Set to 1 to list only keys this language has no text for'),
                ],
                'responses' => [
                    200 => self::jsonResponse('System-default and tenant-override rows, per key', 'TranslationAdminListResponse'),
                    400 => self::errorResponse('Missing/invalid language_code or domain'),
                    404 => self::errorResponse('Language not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/translations', 'translations:manage', [
                'summary' => 'Create a translation row (admin)',
                'tags' => ['languages'],
                'request' => 'TranslationCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created translation', 'TranslationDataResponse'),
                    404 => self::errorResponse('Language not found'),
                    409 => self::errorResponse('A translation for this key already exists in this scope'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/translations/{id:\d+}', 'translations:manage', [
                'summary' => 'Update a translation row\'s text (admin)',
                'tags' => ['languages'],
                'request' => 'TranslationUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated translation', 'TranslationDataResponse'),
                    404 => self::errorResponse('Translation not found'),
                    422 => self::errorResponse('Validation failed, or the system tenant targeted a per-tenant override'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/translations/{id:\d+}', 'translations:manage', [
                'summary' => 'Delete a translation row (admin)',
                'tags' => ['languages'],
                'responses' => [
                    204 => ['description' => 'Translation deleted'],
                    404 => self::errorResponse('Translation not found'),
                    422 => self::errorResponse('The system tenant targeted a per-tenant override'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function brandingRoutes(): array
    {
        // #954: this body was written under the key `requestBody`, which
        // SchemaGenerator::addOperation() does not read — it reads `request`.
        // The declaration was therefore built, merged, and dropped on the floor:
        // both upload operations published with NO request body at all, so a
        // generated client could see the endpoint and had no way to learn the
        // part is called `file`. The key is the whole fix; the shape below was
        // already right.
        //
        // A complete OpenAPI requestBody object (it carries `content`), so the
        // generator passes it through verbatim rather than re-wrapping it as
        // application/json — the same path POST /api/plugins/upload takes.
        $multipartBody = [
            'request' => [
                'required' => true,
                'content' => [
                    'multipart/form-data' => [
                        'schema' => self::object([
                            // BrandingApiHandler::readUploadedFile() reads exactly
                            // one part, named `file`, via getUploadedFiles(); there
                            // is no other field and no JSON alternative (FrankenPHP
                            // drains php://input, so a raw-body path cannot work).
                            // Absent, the handler answers 400.
                            'file' => [
                                'type' => 'string',
                                'format' => 'binary',
                                'description' => 'The asset bytes. The TYPE is decided by magic bytes, not by '
                                    . 'filename or Content-Type: `logo_wide` and `logo_square` accept PNG, WebP '
                                    . 'or SVG (max 2 MiB, SVG stored sanitized), `favicon` accepts ICO or PNG '
                                    . '(max 1 MiB). Anything else is a 422.',
                            ],
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
     * Theme Override route declaration (WC-242): a single public GET that
     * proxies to AT MOST ONE installed plugin's own theme-override route (see
     * {@see \Whity\Core\PluginLoader::getThemeOverrideRoute()}). Public and
     * unauthenticated by design, exactly like {@see brandingRoutes()} — called
     * on every page load, including pre-login.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function themeRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/theme',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Get the effective theme color overrides for the resolved tenant (public)',
                    'tags' => ['theme'],
                    'responses' => [
                        200 => self::jsonResponse('The effective theme overrides (possibly empty)', 'ThemeOverridesResponse'),
                    ],
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
        // WC-f3660e68 (ADR 0005 hard cutover): `id` is now the canonical
        // profile_id (profiles.id), NOT a legacy users.id. A "user" in a tenant
        // is an ACTIVE membership; role/ou_id/status come from that membership,
        // email/name from the profile identity. `status` is the membership
        // lifecycle state (active|invited|suspended); the list only returns
        // 'active' but reads may surface others. `accountStatus` (WC-user-status)
        // is the DISTINCT, GLOBAL profile-level active/inactive switch (ADR 0005
        // §1, migration 083) — deactivating it blocks login for the profile
        // everywhere it holds a membership, not just in one tenant.
        $user = self::object([
            'id' => self::int(),
            'name' => self::str(),
            'email' => self::str(),
            'role' => self::str(),
            'tenantId' => self::int(),
            'ou_id' => self::int(true),
            'createdAt' => self::str(true),
            'status' => self::str(),
            'accountStatus' => ['type' => 'string', 'enum' => ['active', 'inactive']],
            // #917: which authority holds this account's credentials.
            // 'local' = a password this platform stores; 'idp' = an external
            // identity provider and NO local password; 'both' = an external
            // provider AND a local password. Read-only - it is a consequence of
            // which credentials exist, not a field to set. Published because
            // before migration 104 nothing in the API could tell an SSO account
            // from a password one (`password_hash` was NOT NULL and held '' for
            // the former), so a reviewer looking at an account could not see
            // which of them a password even applied to.
            'authMethod' => ['type' => 'string', 'enum' => ['local', 'idp', 'both']],
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
            // True when this is a GLOBAL (NULL-tenant) base role — one row shared
            // by every tenant, so an edit to it applies deployment-wide (#886).
            // Distinct from `manageable`, which says what the CALLER may do: for
            // the system tenant everything is manageable, so without this flag
            // the one caller whose edit reaches every tenant is also the one who
            // cannot tell which roles those are. The owning tenant id itself is
            // never returned — a tenant learns that a role is shared, not who
            // else has one.
            'global' => self::bool(),
        ], ['id', 'name', 'description', 'parent_id', 'created_at', 'permissionCount', 'manageable', 'global']);

        $tenant = self::object([
            'id' => self::int(),
            'name' => self::str(),
            'slug' => self::str(true),
            'userCount' => self::int(),
            'createdAt' => self::str(true),
        ], ['id', 'name', 'slug', 'userCount', 'createdAt']);

        // #822: a unit's KIND travels with it as both the per-tenant id and the
        // stable key a consumer's rule is written against. All three are nullable
        // because typing is OPTIONAL — an existing untyped tree keeps working.
        $ou = self::object([
            'id' => self::int(),
            'tenant_id' => self::int(),
            'parent_id' => self::int(true),
            'name' => self::str(),
            'slug' => self::str(),
            'description' => self::str(true),
            'created_at' => self::str(true),
            'ou_type_id' => self::int(true),
            'ou_type_key' => self::str(true),
            'ou_type_label' => self::str(true),
        ], [
            'id', 'tenant_id', 'parent_id', 'name', 'slug', 'description', 'created_at',
            'ou_type_id', 'ou_type_key', 'ou_type_label',
        ]);

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

        // The grant body shared by both alternatives of MembershipCreateRequest
        // (see there). One map, so the two branches cannot describe different
        // fields — which is the failure mode of writing a union out twice.
        $membershipGrant = [
            'role_id' => self::reference('/api/roles', 'name') + [
                'description' => 'The role to grant, by id. Interchangeable with `role`, which the '
                    . 'handler reads when `role_id` is absent; supplying neither is a 400, and '
                    . 'supplying both is legal with `role_id` winning.',
            ],
            'role' => $permissionRef + [
                'description' => 'The same grant addressed by role NAME (or by a numeric id). '
                    . 'Core\'s own memberships UI uses this spelling.',
            ],
            'ou_id' => self::reference('/api/ous', 'name', nullable: true),
            'tenant_id' => self::int(),
        ];

        // ---- Auth component schemas (WC-388a61e3) ----

        // The resolved user that appears in login / GET /api/me / PATCH /api/me.
        // id is the legacy users.id during the dual-claim window (may equal
        // profile_id for pre-migration accounts); role is the role name string.
        $sessionUser = self::object([
            'id' => self::int(),
            'email' => self::str(),
            'role' => self::str(),
            'tenant_id' => self::int(),
        ], ['id', 'email', 'role', 'tenant_id']);

        // Self-profile (GET /api/me and PATCH /api/me shapeSelf): no tenant_id
        // — the handler returns id/email/role only from the token claims / users row.
        $selfUser = self::object([
            'id' => self::int(),
            'email' => self::str(),
            'role' => self::str(),
        ], ['id', 'email', 'role']);

        // One selectable membership entry returned by the tenant-selection prompt.
        $membershipEntry = self::object([
            'tenant_id' => self::int(),
            'tenant_name' => self::str(),
            'role' => self::str(),
        ], ['tenant_id', 'tenant_name', 'role']);

        return [
            'Error' => self::object([
                'error' => self::str(),
                'details' => ['type' => 'object'],
            ], ['error']),
            'MutationResponse' => self::dataEnvelope($mutationResult),

            // ---- Auth schemas ----

            // POST /api/login — request body
            'LoginRequest' => self::object([
                'email' => self::str(),
                'password' => self::str(),
            ], ['email', 'password']),

            // POST /api/plugins/install-from-store — request body. store_url host
            // must be on the plugins.store_allowed_hosts allowlist; token is an
            // opaque store credential sent as a bearer to the store (never logged).
            'InstallFromStoreRequest' => self::object([
                'store_url' => array_merge(self::str(), [
                    'description' => 'Bare https origin of a trusted plugin store — scheme + allowlisted '
                        . 'host only (no path, query, credentials, or non-443 port).',
                    'example' => 'https://store.example.com',
                ]),
                'slug' => array_merge(self::str(), [
                    'description' => 'Plugin slug to install.',
                ]),
                'version' => array_merge(self::str(), [
                    'description' => 'Exact package version to install.',
                ]),
                'token' => array_merge(self::str(true), [
                    'description' => 'Optional store access token (opaque bearer credential).',
                ]),
            ], ['store_url', 'slug', 'version']),

            // GET /api/plugins/store/allowed — the configured trusted store hosts.
            'StoreAllowedHostsResponse' => self::object([
                'data' => self::object([
                    'enabled' => self::bool(),
                    'hosts' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], ['enabled', 'hosts']),
            ], ['data']),

            // GET /api/plugins/store/catalog — a store's public catalogue entry.
            'StoreCataloguePlugin' => self::object([
                'slug' => self::str(),
                'name' => self::str(),
                'description' => self::str(),
                'author' => self::str(),
                'tags' => ['type' => 'array', 'items' => ['type' => 'string']],
                'latest_version' => self::str(true),
            ], ['slug', 'name']),
            'StoreCatalogueResponse' => self::object([
                'data' => ['type' => 'array', 'items' => ['$ref' => '#/components/schemas/StoreCataloguePlugin']],
                'store_url' => self::str(),
                'count' => ['type' => 'integer'],
            ], ['data']),

            // POST /api/login — 200 success (single membership → session issued,
            // OR multi-membership → requires_tenant_selection prompt).
            // Inferred from AuthHandler::issueSessionForProfile() and
            // AuthHandler::requireTenantSelection() — both are 200 paths.
            'LoginResponse' => self::object([
                // Present when a session is issued directly.
                'user' => array_merge($sessionUser, ['nullable' => true]),
                // Present (true) when the profile has multiple active memberships
                // and a selection token has been set in the cookie.
                'requires_tenant_selection' => ['type' => 'boolean', 'nullable' => true],
                // Non-empty only when requires_tenant_selection is true.
                'memberships' => [
                    'type' => 'array',
                    'items' => $membershipEntry,
                    'nullable' => true,
                ],
            ], []),

            // POST /api/login — 202 (2FA required)
            'Login2faRequiredResponse' => self::object([
                'requires_2fa' => self::bool(),
            ], ['requires_2fa']),

            // POST /api/login/2fa — request body
            'TwoFaLoginRequest' => self::object([
                'code' => self::str(),
            ], ['code']),

            // POST /api/auth/select-tenant — request body
            'SelectTenantRequest' => self::object([
                'tenant_id' => self::int(),
            ], ['tenant_id']),

            // POST /api/auth/select-tenant — 200 response (session issued)
            'SessionUserResponse' => self::object([
                'user' => $sessionUser,
            ], ['user']),

            // POST /api/auth/switch-tenant — request body (WC-f8164c87)
            'SwitchTenantRequest' => self::object([
                'tenant_id' => self::int(),
            ], ['tenant_id']),

            // GET /api/me — 200 response. AuthHandler::handleMe() reads directly
            // from the access-token claims and INCLUDES tenant_id in the user.
            // Also includes the caller's own active memberships (WC-f8164c87) for
            // the sidenav tenant-switcher — empty array for legacy-only tokens.
            'MeGetResponse' => self::object([
                'user' => $sessionUser,
                'memberships' => [
                    'type' => 'array',
                    'items' => $membershipEntry,
                ],
            ], ['user', 'memberships']),

            // PATCH /api/me — 200 response. AuthHandler::handleUpdateMe() shapes
            // the row via shapeSelf(), which returns id/email/role ONLY (no
            // tenant_id) — a genuinely different shape from GET /api/me above.
            'MeResponse' => self::object([
                'user' => $selfUser,
            ], ['user']),

            // PATCH /api/me — request body
            'MeUpdateRequest' => self::object([
                'email' => self::str(),
                'password' => self::str(),
                'current_password' => self::str(),
            ], ['current_password']),

            // POST /api/auth/refresh — 200 response (AuthHandler::handleRefresh)
            'RefreshResponse' => self::object([
                'status' => self::str(),
            ], ['status']),

            // POST /api/auth/logout — 200 response (AuthHandler::handleLogout)
            'LogoutResponse' => self::object([
                'status' => self::str(),
            ], ['status']),

            // POST /api/auth/2fa/setup — 200 response (TwoFactorHandler::setup)
            'TwoFaSetupResponse' => self::object([
                'secret' => self::str(),
                'qrCodeUrl' => self::str(),
            ], ['secret', 'qrCodeUrl']),

            // POST /api/auth/2fa/confirm — request body (TwoFactorHandler::confirm)
            'TwoFaConfirmRequest' => self::object([
                'code' => self::str(),
                'secret' => self::str(),
            ], ['code', 'secret']),

            // POST /api/auth/2fa/confirm — 200 response
            'TwoFaConfirmResponse' => self::object([
                'backup_codes' => ['type' => 'array', 'items' => self::str()],
                'message' => self::str(),
            ], ['backup_codes', 'message']),

            // POST /api/auth/2fa/regenerate-codes — 200 response
            'TwoFaRegenerateCodesResponse' => self::object([
                'backup_codes' => ['type' => 'array', 'items' => self::str()],
                'message' => self::str(),
            ], ['backup_codes', 'message']),

            // GET /api/auth/2fa/status — 200 response (TwoFactorHandler::status)
            'TwoFaStatusResponse' => self::object([
                'enabled' => self::bool(),
                'backup_codes_available' => self::int(),
            ], ['enabled', 'backup_codes_available']),

            // ── SSO / federated identity (WC-e6287, WC-f3b17bd2; ADR 0009) ────
            // A tenant's configured OIDC provider. `client_secret` is WRITE-ONLY:
            // the repository never returns it — `has_secret` reflects whether one
            // is set. `discovery_url`/`domain` are nullable (optional config).
            'IdentityProvider' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'provider_key' => self::str(),
                'display_name' => self::str(),
                'client_id' => self::str(),
                'has_secret' => self::bool(),
                'issuer' => self::str(),
                'discovery_url' => self::str(true),
                'scopes' => self::str(),
                'domain' => self::str(true),
                'enabled' => self::bool(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], [
                'id', 'tenant_id', 'provider_key', 'display_name', 'client_id',
                'has_secret', 'issuer', 'scopes', 'enabled', 'created_at', 'updated_at',
            ]),
            'IdentityProviderListResponse' => self::listEnvelope('IdentityProvider'),
            'IdentityProviderResponse' => self::dataEnvelope(SchemaBuilder::ref('IdentityProvider')),
            // provider_key is constrained to the currently-configurable set; issuer
            // and discovery_url must be https URLs; client_secret is write-only.
            'IdentityProviderCreateRequest' => self::object([
                'provider_key' => ['type' => 'string', 'enum' => ['google', 'microsoft', 'oidc']],
                'display_name' => self::str(),
                'client_id' => self::str(),
                'issuer' => ['type' => 'string', 'format' => 'uri'],
                'client_secret' => self::str(),
                'discovery_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'scopes' => self::str(),
                'domain' => self::str(true),
                'enabled' => self::bool(),
            ], ['provider_key', 'display_name', 'client_id', 'issuer']),
            // All fields optional — a partial patch. Sending `client_secret`
            // re-encrypts and replaces it; omitting it leaves the stored one.
            'IdentityProviderUpdateRequest' => self::object([
                'provider_key' => ['type' => 'string', 'enum' => ['google', 'microsoft', 'oidc']],
                'display_name' => self::str(),
                'client_id' => self::str(),
                'issuer' => ['type' => 'string', 'format' => 'uri'],
                'client_secret' => self::str(),
                'discovery_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                'scopes' => self::str(),
                'domain' => self::str(true),
                'enabled' => self::bool(),
            ], []),
            // Public login-screen shape — only the safe display fields.
            'SsoProvider' => self::object([
                'provider_key' => self::str(),
                'display_name' => self::str(),
            ], ['provider_key', 'display_name']),
            'SsoProviderListResponse' => self::listEnvelope('SsoProvider'),
            // A caller's linked external identity (connected account). `email` and
            // `last_login_at` may be null (asserted-at-link email; never-since-linked).
            'MeIdentity' => self::object([
                'id' => self::int(),
                'provider_key' => self::str(),
                'email' => self::str(true),
                'linked_at' => self::str(),
                'last_login_at' => self::str(true),
            ], ['id', 'provider_key', 'linked_at']),
            'MeIdentityListResponse' => self::listEnvelope('MeIdentity'),

            // One of the caller's own email addresses (WC-54fb5c37).
            'MeEmail' => self::object([
                'id' => self::int(),
                'email' => self::str(),
                'verified' => self::bool(),
                'isPrimary' => self::bool(),
                'createdAt' => self::str(),
            ], ['id', 'email', 'verified', 'isPrimary', 'createdAt']),
            'MeEmailListResponse' => self::listEnvelope('MeEmail'),
            'MeEmailResponse' => self::dataEnvelope(SchemaBuilder::ref('MeEmail')),
            'MeEmailAddRequest' => self::object([
                'email' => self::str(),
            ], ['email']),

            // Tenant email-domain policy admin surface (WC-9b87 / WC-628738f5).
            'DomainVerificationChallenge' => self::object([
                'record_name' => self::str(),
                'record_type' => self::str(),
                'record_value' => self::str(),
            ], ['record_name', 'record_type', 'record_value']),
            'TenantEmailDomain' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'domain' => self::str(),
                'default_role_id' => self::int(),
                'auto_provision' => self::bool(),
                'verified_at' => self::str(true),
                'is_verified' => self::bool(),
                'created_at' => self::str(),
                'verification' => SchemaBuilder::ref('DomainVerificationChallenge'),
            ], ['id', 'tenant_id', 'domain', 'default_role_id', 'auto_provision', 'is_verified', 'created_at']),
            'TenantEmailDomainListResponse' => self::listEnvelope('TenantEmailDomain'),
            'TenantEmailDomainResponse' => self::dataEnvelope(SchemaBuilder::ref('TenantEmailDomain')),
            'TenantEmailDomainVerifyPendingResponse' => self::object([
                'error' => self::str(),
                'verification' => SchemaBuilder::ref('DomainVerificationChallenge'),
            ], ['error', 'verification']),
            'TenantEmailDomainCreateRequest' => self::object([
                'domain' => self::str(),
                'default_role_id' => self::int(),
                'auto_provision' => self::bool(),
            ], ['domain', 'default_role_id']),

            // ── Operator per-tenant entitlements (WC-ent) ─────────────────────
            // One catalogue entry: how to render + interpret an entitlement.
            'EntitlementCatalogueEntry' => self::object([
                'type' => ['type' => 'string', 'enum' => ['bool', 'int']],
                'default' => self::str(),
                'description' => self::str(),
            ], ['type', 'default', 'description']),
            // effective: a map of entitlement key → typed value (bool | int, where
            // -1 on an int means unlimited). registry: key → catalogue entry.
            'TenantEntitlementsResponse' => self::dataEnvelope(self::object([
                'tenant_id' => self::int(),
                'effective' => [
                    'type' => 'object',
                    'additionalProperties' => ['oneOf' => [['type' => 'boolean'], ['type' => 'integer']]],
                ],
                'overridden' => ['type' => 'array', 'items' => self::str()],
                'registry' => [
                    'type' => 'object',
                    'additionalProperties' => SchemaBuilder::ref('EntitlementCatalogueEntry'),
                ],
            ], ['tenant_id', 'effective', 'overridden', 'registry'])),
            'TenantEntitlementsMutationResponse' => self::dataEnvelope(self::object([
                'tenant_id' => self::int(),
                'effective' => [
                    'type' => 'object',
                    'additionalProperties' => ['oneOf' => [['type' => 'boolean'], ['type' => 'integer']]],
                ],
                'overridden' => ['type' => 'array', 'items' => self::str()],
            ], ['tenant_id', 'effective', 'overridden'])),
            // A map of entitlement key → value (string/bool/int) or null to clear.
            'TenantEntitlementsPatchRequest' => self::object([
                'entitlements' => ['type' => 'object', 'additionalProperties' => true],
            ], ['entitlements']),

            // ── Per-tenant storage backend (WC-storage) ───────────────────────
            // The tenant's object-storage config. The secret is WRITE-ONLY — never
            // returned; `has_secret` reflects whether one is stored.
            'StorageConfig' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'driver' => self::str(),
                'endpoint' => self::str(),
                'region' => self::str(),
                'bucket' => self::str(),
                'access_key' => self::str(),
                'has_secret' => self::bool(),
                'path_style' => self::bool(),
                'public_base_url' => self::str(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], [
                'id', 'tenant_id', 'driver', 'endpoint', 'region', 'bucket',
                'access_key', 'has_secret', 'path_style', 'created_at', 'updated_at',
            ]),
            // GET response: config is null when the tenant uses the platform
            // default; `entitled` is whether the plan includes a custom backend.
            'StorageConfigResponse' => self::dataEnvelope(self::object([
                'config' => ['nullable' => true, 'allOf' => [SchemaBuilder::ref('StorageConfig')]],
                'entitled' => self::bool(),
                'drivers' => ['type' => 'array', 'items' => self::str()],
            ], ['config', 'entitled', 'drivers'])),
            'StorageConfigDataResponse' => self::dataEnvelope(SchemaBuilder::ref('StorageConfig')),
            // endpoint/public_base_url must be https; secret is write-only and may
            // be omitted on update to keep the stored one.
            'StorageConfigPutRequest' => self::object([
                'driver' => ['type' => 'string', 'enum' => ['s3']],
                'endpoint' => ['type' => 'string', 'format' => 'uri'],
                'region' => self::str(),
                'bucket' => self::str(),
                'access_key' => self::str(),
                'secret' => self::str(),
                'path_style' => self::bool(),
                'public_base_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
            ], ['endpoint', 'region', 'bucket', 'access_key']),

            // ── Admin-enforced 2FA policy (WC-525) ─────────────────────────────
            'TwoFactorPolicy' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'scope_type' => ['type' => 'string', 'enum' => ['tenant', 'ou', 'user']],
                'scope_id' => self::int(true),
                'grace_period_days' => self::int(),
                'created_by' => self::int(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], [
                'id', 'tenant_id', 'scope_type', 'scope_id', 'grace_period_days', 'created_at', 'updated_at',
            ]),
            'TwoFactorPolicyListResponse' => self::listEnvelope('TwoFactorPolicy'),
            'TwoFactorPolicyDataResponse' => self::dataEnvelope(SchemaBuilder::ref('TwoFactorPolicy')),
            'TwoFactorPolicyCreateRequest' => self::object([
                'scope_type' => ['type' => 'string', 'enum' => ['tenant', 'ou', 'user']],
                'scope_id' => self::int(true),
                'grace_period_days' => self::int(),
            ], ['scope_type']),
            'TwoFactorPolicyUpdateRequest' => self::object([
                'grace_period_days' => self::int(),
            ], ['grace_period_days']),
            'TwoFactorPolicyStatusEntry' => self::object([
                'profile_id' => self::int(),
                'email' => self::str(),
                'enrolled' => self::bool(),
                'enforcement_deadline' => self::int(true),
            ], ['profile_id', 'email', 'enrolled', 'enforcement_deadline']),
            'TwoFactorPolicyStatusResponse' => self::listEnvelope('TwoFactorPolicyStatusEntry'),

            // ── i18n admin management (WC-583) ────────────────────────────────
            // A language row (admin shape — the public GET /api/languages list
            // returns {code, name, direction}, declared inline in languageRoutes()).
            // `direction` is the interface writing direction the client applies
            // to <html dir>; it is a property of the LANGUAGE, so adding a
            // right-to-left language is a POST here rather than a code change.
            'Language' => self::object([
                'id' => self::int(),
                'code' => self::str(),
                'name' => self::str(),
                'direction' => ['type' => 'string', 'enum' => ['ltr', 'rtl']],
                'enabled' => self::bool(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'code', 'name', 'direction', 'enabled', 'created_at', 'updated_at']),
            'LanguageDataResponse' => self::dataEnvelope(SchemaBuilder::ref('Language')),
            'LanguageListResponse' => self::listEnvelope('Language'),
            'LanguageCreateRequest' => self::object([
                'code' => self::str(),
                'name' => self::str(),
                'direction' => ['type' => 'string', 'enum' => ['ltr', 'rtl']],
                'enabled' => self::bool(),
            ], ['code', 'name']),
            'LanguageUpdateRequest' => self::object([
                'name' => self::str(),
                'direction' => ['type' => 'string', 'enum' => ['ltr', 'rtl']],
                'enabled' => self::bool(),
            ], []),
            // A translation row. tenant_id is nullable: NULL = system default,
            // an integer = the owning tenant's override.
            'Translation' => self::object([
                'id' => self::int(),
                'language_id' => self::int(),
                'domain' => self::str(),
                'key' => self::str(),
                'translation' => self::str(),
                'tenant_id' => self::int(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'language_id', 'domain', 'key', 'translation', 'tenant_id', 'created_at', 'updated_at']),
            'TranslationDataResponse' => self::dataEnvelope(SchemaBuilder::ref('Translation')),
            'TranslationCreateRequest' => self::object([
                'language_code' => self::str(),
                'domain' => self::str(),
                'key' => self::str(),
                'translation' => self::str(),
            ], ['language_code', 'domain', 'key', 'translation']),
            'TranslationUpdateRequest' => self::object([
                'translation' => self::str(),
            ], ['translation']),
            // GET /api/v1/translations (admin) row shape: the system-default and
            // this tenant's override shown SIDE BY SIDE for one key (never merged).
            'TranslationRowRef' => self::object([
                'id' => self::int(),
                'translation' => self::str(),
            ], ['id', 'translation']),
            // `source_text` is the English this key is translated FROM, and is
            // why a key with no row in the requested language still appears:
            // listing only what exists would show a translator an empty table
            // and call the language finished.
            'TranslationAdminRow' => self::object([
                'key' => self::str(),
                'system_default' => ['nullable' => true, 'allOf' => [SchemaBuilder::ref('TranslationRowRef')]],
                'tenant_override' => ['nullable' => true, 'allOf' => [SchemaBuilder::ref('TranslationRowRef')]],
                'source_text' => self::str(true),
                'translated' => self::bool(),
            ], ['key', 'system_default', 'tenant_override', 'source_text', 'translated']),
            'TranslationAdminListResponse' => self::listEnvelope('TranslationAdminRow'),
            // GET /api/v1/translations/coverage: the gap, per language and domain.
            'TranslationDomainCoverage' => self::object([
                'domain' => self::str(),
                'total' => self::int(),
                'translated' => self::int(),
                'missing' => self::int(),
            ], ['domain', 'total', 'translated', 'missing']),
            'TranslationLanguageCoverage' => self::object([
                'language_code' => self::str(),
                'name' => self::str(),
                'total' => self::int(),
                'translated' => self::int(),
                'missing' => self::int(),
                'domains' => ['type' => 'array', 'items' => SchemaBuilder::ref('TranslationDomainCoverage')],
            ], ['language_code', 'name', 'total', 'translated', 'missing', 'domains']),
            'TranslationCoverageResponse' => self::dataEnvelope(self::object([
                'source_language_code' => self::str(),
                'languages' => ['type' => 'array', 'items' => SchemaBuilder::ref('TranslationLanguageCoverage')],
            ], ['source_language_code', 'languages'])),
            // GET /api/v1/translations/{language_code}/{domain} (public bundle):
            // an open-ended key => translated-string map (the resolved fallback
            // chain), not a fixed shape.
            'TranslationBundleResponse' => self::object([
                'translations' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            ], ['translations']),

            // ── Native taxonomy/tagging (WC-621) ──────────────────────────────
            // A tag group; `display_name` is the bilingual {ar?, en?} label.
            'TagGroup' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'key' => self::str(),
                'display_name' => ['type' => 'object', 'x-whity-localized-text' => true, 'properties' => ['ar' => self::str(), 'en' => self::str()]],
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'tenant_id', 'key', 'display_name', 'created_at', 'updated_at']),
            'TagGroupListResponse' => self::listEnvelope('TagGroup'),
            'TagGroupDataResponse' => self::dataEnvelope(SchemaBuilder::ref('TagGroup')),
            // `key` is trimmed before validation and matched against
            // TagGroupsApiHandler::KEY_PATTERN; `RequestSchemaContractTest`
            // pins these bounds to that constant so the two cannot drift.
            // Only the `ar` and `en` locales are stored — any other key in
            // `display_name` is accepted and then silently dropped.
            'TagGroupCreateRequest' => self::object([
                'key' => self::tagGroupKey(),
                'display_name' => self::localizedText(),
            ], ['key']),
            // Both fields are optional individually, but a body that supplies
            // NEITHER is a 422 ('No updatable fields supplied'). A supplied
            // `display_name` REPLACES the stored label wholesale rather than
            // merging into it, so `{}` clears every locale.
            'TagGroupUpdateRequest' => self::object([
                'key' => self::tagGroupKey(),
                'display_name' => self::localizedText(),
            ], []) + ['minProperties' => 1],
            // A tag inside a group.
            'Tag' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'group_id' => self::int(),
                'name' => self::str(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'tenant_id', 'group_id', 'name', 'created_at', 'updated_at']),
            'TagListResponse' => self::listEnvelope('Tag'),
            'TagDataResponse' => self::dataEnvelope(SchemaBuilder::ref('Tag')),
            'TagCreateRequest' => self::object([
                'group_id' => self::reference('/api/tag-groups', 'key') + ['minimum' => 1],
                'name' => self::tagName(),
            ], ['group_id', 'name']),
            // A tag cannot be moved between groups here: `group_id` is not read
            // by the rename handler, so it is deliberately not declared.
            'TagUpdateRequest' => self::object([
                'name' => self::tagName(),
            ], ['name']),
            // A polymorphic tag<->entity association.
            'EntityTagAssociation' => self::object([
                'entity_type' => self::str(),
                'entity_id' => self::int(),
                'tag_id' => self::int(),
            ], ['entity_type', 'entity_id', 'tag_id']),
            // The body of POST and DELETE /api/entity-tags alike — the DELETE
            // genuinely reads a body (RequestBodyValidator parses DELETE too),
            // which is why it declares a `request` where the other taxonomy
            // DELETEs declare query parameters.
            'EntityTagAssociationRequest' => self::object([
                'entity_type' => self::entityType(),
                'entity_id' => self::int() + ['minimum' => 1],
                'tag_id' => self::reference('/api/tags', 'name') + ['minimum' => 1],
            ], ['entity_type', 'entity_id', 'tag_id']),
            'EntityTagDataResponse' => self::dataEnvelope(SchemaBuilder::ref('EntityTagAssociation')),
            // The GET /api/entity-tags shape is polymorphic: with entity_id it
            // returns the entity's tags; with tag_id it returns the entities
            // carrying the tag. Typed as a generic object list.
            'EntityTagQueryResponse' => self::object([
                'data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
            ], ['data']),
            // The result of the WC-714 §6 record-delete cleanup hook: which
            // entity was cleaned and how many associations that removed.
            'EntityTagDetachAllResult' => self::object([
                'entity_type' => self::str(),
                'entity_id' => self::int(),
                'removed' => self::int(),
            ], ['entity_type', 'entity_id', 'removed']),
            'EntityTagDetachAllResponse' => self::dataEnvelope(SchemaBuilder::ref('EntityTagDetachAllResult')),

            // ── Subscription plans (WC-plans, ADR 0010) ───────────────────────
            // A plan row (list shape — no entitlement bundle).
            'PlanSummary' => self::object([
                'id' => self::int(),
                'plan_key' => self::str(),
                'name' => self::str(),
                'description' => self::str(true),
                'is_active' => self::bool(),
                'sort_order' => self::int(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'plan_key', 'name', 'is_active', 'sort_order', 'created_at', 'updated_at']),
            // A plan WITH its entitlement bundle (detail shape). `entitlements` maps
            // an entitlement key → typed value (bool | int).
            'Plan' => self::object([
                'id' => self::int(),
                'plan_key' => self::str(),
                'name' => self::str(),
                'description' => self::str(true),
                'is_active' => self::bool(),
                'sort_order' => self::int(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
                'entitlements' => [
                    'type' => 'object',
                    'additionalProperties' => ['oneOf' => [['type' => 'boolean'], ['type' => 'integer']]],
                ],
            ], ['id', 'plan_key', 'name', 'is_active', 'sort_order', 'created_at', 'updated_at', 'entitlements']),
            'PlanListResponse' => self::listEnvelope('PlanSummary'),
            'PlanResponse' => self::dataEnvelope(SchemaBuilder::ref('Plan')),
            'PlanCreateRequest' => self::object([
                'plan_key' => self::str(),
                'name' => self::str(),
                'description' => self::str(true),
                'is_active' => self::bool(),
                'sort_order' => self::int(),
            ], ['plan_key', 'name']),
            'PlanUpdateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(true),
                'is_active' => self::bool(),
                'sort_order' => self::int(),
            ], []),
            // A map of entitlement key → value (string/bool/int) or null to remove.
            'PlanEntitlementsPutRequest' => self::object([
                'entitlements' => ['type' => 'object', 'additionalProperties' => true],
            ], ['entitlements']),
            'PlanApplyRequest' => self::object([
                'plan_id' => self::int(),
            ], ['plan_id']),
            // The tenant's plan assignment; the enclosing data is null when the
            // tenant has no plan.
            'TenantPlan' => self::object([
                'tenant_id' => self::int(),
                'plan_id' => self::int(true),
                'assigned_by' => self::int(true),
                'assigned_at' => self::str(),
                'plan' => ['nullable' => true, 'allOf' => [SchemaBuilder::ref('PlanSummary')]],
            ], ['tenant_id', 'plan_id', 'assigned_at']),
            'TenantPlanResponse' => self::dataEnvelope(['nullable' => true, 'allOf' => [SchemaBuilder::ref('TenantPlan')]]),

            // ── Subscription / billing state (WC-billing) ─────────────────────
            'PlanRef' => self::object([
                'id' => self::int(),
                'plan_key' => self::str(),
                'name' => self::str(),
            ], ['id', 'plan_key', 'name']),
            // Operator view: full billing state. status is null when no
            // subscription is configured; plan is null when none is assigned.
            'Subscription' => self::object([
                'tenant_id' => self::int(),
                'status' => self::str(true),
                'plan' => ['nullable' => true, 'allOf' => [SchemaBuilder::ref('PlanRef')]],
                'current_period_end' => self::str(true),
                'effective_enforcement_mode' => ['type' => 'string', 'enum' => ['off', 'warn', 'block_writes', 'block_all']],
                'enforcement_mode' => self::str(true),
                'grace_until' => self::str(true),
                'external_ref' => self::str(true),
            ], ['tenant_id', 'status', 'plan', 'effective_enforcement_mode']),
            // Tenant-self view: omits the enforcement policy + provider ref.
            'SelfSubscription' => self::object([
                'tenant_id' => self::int(),
                'status' => self::str(true),
                'plan' => ['nullable' => true, 'allOf' => [SchemaBuilder::ref('PlanRef')]],
                'current_period_end' => self::str(true),
                'effective_enforcement_mode' => ['type' => 'string', 'enum' => ['off', 'warn', 'block_writes', 'block_all']],
            ], ['tenant_id', 'status', 'plan', 'effective_enforcement_mode']),
            'SubscriptionResponse' => self::dataEnvelope(SchemaBuilder::ref('Subscription')),
            'SelfSubscriptionResponse' => self::dataEnvelope(SchemaBuilder::ref('SelfSubscription')),
            // All fields optional. plan_id applies a plan (materialising its
            // entitlements); null clears a billing column.
            'SubscriptionPutRequest' => self::object([
                'plan_id' => self::int(true),
                'status' => ['type' => 'string', 'nullable' => true, 'enum' => ['trialing', 'active', 'past_due', 'canceled', 'expired']],
                'enforcement_mode' => ['type' => 'string', 'nullable' => true, 'enum' => ['off', 'warn', 'block_writes', 'block_all']],
                'current_period_end' => self::str(true),
                'grace_until' => self::str(true),
                'external_ref' => self::str(true),
            ], []),

            // ── Document/label designer templates (WC-docdesigner) ────────────
            // `data` is the verbatim client DocTemplate JSON (freeform object).
            'DocumentTemplate' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'name' => self::str(),
                'data' => ['type' => 'object', 'additionalProperties' => true],
                'scope' => ['type' => 'string', 'enum' => ['personal', 'tenant', 'global', 'system']],
                'required_permission' => self::str(true),
                'is_system' => self::bool(),
                'created_by' => self::int(true),
                // Where in the organisation the row is filed (migration 117). null = tenant-wide,
                // which is what every row was before it and what an unplaced row still is.
                'owner_ou_id' => self::int(true),
                // WHICH shipped starter this row is, or null for anything a user made
                // (#1013). `is_system` says "a system row" and cannot answer it, so a
                // client rendering a Starter badge off that alone can label the row but
                // never offer to restore the starter it came from.
                'starter_key' => self::str(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'tenant_id', 'name', 'data', 'scope', 'is_system', 'created_at', 'updated_at']),
            'DocumentTemplateListResponse' => self::listEnvelope('DocumentTemplate'),
            'DocumentTemplateResponse' => self::dataEnvelope(SchemaBuilder::ref('DocumentTemplate')),
            // scope/required_permission/owner_ou_id are all optional; setting a
            // shared scope, a permission tag, or a placement requires
            // documents:publish (403 otherwise). owner_ou_id must be a unit of the
            // caller's own tenant (422 otherwise); null files the row tenant-wide.
            'DocumentTemplateCreateRequest' => self::object([
                'name' => self::str(),
                'data' => ['type' => 'object', 'additionalProperties' => true],
                'scope' => ['type' => 'string', 'enum' => ['personal', 'tenant', 'global', 'system']],
                'required_permission' => self::str(true),
                'owner_ou_id' => self::int(true),
            ], ['name', 'data']),
            'DocumentTemplateUpdateRequest' => self::object([
                'name' => self::str(),
                'data' => ['type' => 'object', 'additionalProperties' => true],
                'scope' => ['type' => 'string', 'enum' => ['personal', 'tenant', 'global', 'system']],
                'required_permission' => self::str(true),
                'owner_ou_id' => self::int(true),
            ], []),

            // Server-side render (ADR 0012 / WC-docdesigner Track 2). `dataRows`
            // is one flat string=>string map per label/row (omitted or empty ->
            // a single row from the template's own placeholder samples);
            // `sheet` is the optional N-up label-sheet tiling layout — both
            // freeform (mirror the client's DocElement-adjacent JSON shapes
            // rather than a rigid schema, same "verbatim client JSON" posture
            // as DocumentTemplate.data itself).
            //
            // `persist` (#947 item 1) turns the render into a DOCUMENT RECORD:
            // the response becomes a DocumentResponse (201) instead of PDF
            // bytes. It defaults to FALSE because the dominant caller is the
            // designer's preview, which must not write to storage — see
            // DocumentRenderApiHandler. `title` names the record and is ignored
            // when not persisting.
            'DocumentRenderRequest' => self::object([
                'dataRows' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
                'sheet' => ['type' => 'object', 'additionalProperties' => true, 'nullable' => true],
                'persist' => ['type' => 'boolean', 'default' => false],
                'title' => self::str(true),
            ], []),

            // ── Issued documents (#947 item 1) ────────────────────────────────
            // A document is the RECORD; its artifacts are the immutable files
            // that were issued from it. `content_url` is the durable reference —
            // an API path, not a storage key (which is never on the wire) and
            // not a signed URL (the local driver cannot produce one).
            // `document_template_id` is nullable because the template may be
            // deleted after the fact; `template_name` is the snapshot that keeps
            // the record legible when it is.
            'DocumentArtifact' => self::object([
                'id' => self::int(),
                'document_id' => self::int(),
                'content_type' => self::str(),
                'byte_size' => self::int(),
                // Lowercase hex SHA-256 of the stored bytes: what lets a
                // consumer prove the file it downloaded is the file that was
                // issued.
                'checksum_sha256' => self::str(),
                'rendered_by' => self::int(true),
                'rendered_at' => self::str(),
                'content_url' => self::str(),
            ], ['id', 'document_id', 'content_type', 'byte_size', 'checksum_sha256', 'rendered_at', 'content_url']),
            'Document' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'document_template_id' => self::int(true),
                'template_name' => self::str(),
                'title' => self::str(),
                'origin_ou_id' => self::int(true),
                'created_by' => self::int(true),
                'created_at' => self::str(),
                // The CURRENT artifact's bytes; null only for a record whose
                // artifact rows are gone (a partial restore), since the issuer
                // never commits a document without one.
                'content_url' => self::str(true),
                // Newest first. More than one entry means the document has been
                // re-rendered: every earlier artifact is still fetchable at its
                // own content_url.
                'artifacts' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentArtifact')],
                // #978: the CALLER's own filing of this document. Both keys are
                // OPTIONAL and their ABSENCE is meaningful — the routes that
                // know who is asking (list, get) compute them; the render route
                // does not, and defaulting `starred` to false there would be a
                // claim nobody made. Neither is in `required` for that reason.
                'collection_ids' => ['type' => 'array', 'items' => self::int()],
                'starred' => self::bool(),
                // #993: the record page's per-region verdicts, keyed by region
                // (`document`, `trail`, `recipients`). Sent by `GET /{id}` only
                // — the LIST omits it, for the same reason the filing keys above
                // are optional: a verdict is an answer about one record and one
                // caller, and 25 of them per page would gate nothing.
                //
                // A region the caller may not see is ABSENT from the map. There
                // is no `hidden` state on the wire and there must not be: a
                // `{"state": "hidden"}` entry would tell a caller the exact
                // thing withholding the region was for. See
                // RecordSectionResolver.
                'sections' => [
                    'type' => 'object',
                    'additionalProperties' => SchemaBuilder::ref('RecordSectionVerdict'),
                ],
            ], ['id', 'tenant_id', 'template_name', 'title', 'created_at', 'artifacts']),
            // Not paginatedListEnvelope: the organizer echoes back WHICH view it
            // ran and what anchor it resolved to, so a client rendering a rail
            // does not have to re-derive the selection from its own URL — and
            // the anchor the server actually used (the caller's own unit, when
            // none was supplied) is visible rather than assumed.
            'DocumentListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('Document')],
                'pagination' => SchemaBuilder::ref('Pagination'),
                'view' => self::object([
                    'key' => self::str(),
                    'ou_id' => self::int(true),
                    'collection_id' => self::int(true),
                ], ['key']),
            ], ['data', 'pagination', 'view']),
            'DocumentResponse' => self::dataEnvelope(SchemaBuilder::ref('Document')),

            // The FRONT DOOR (#947 item 1, the half that was missing). Naming a
            // template is the only requirement; everything else has a defined
            // fallback, because a client that knows nothing but the template
            // should still be able to raise a document from it.
            //
            // `dataRows` is the SAME shape the render routes take, deliberately,
            // and is what the values are STORED as (migration 118,
            // `documents.variable_data`) rather than a second authoring format
            // that would have to be converted before it could be rendered. Keys
            // are the template's own placeholder keys; a key the template does
            // not declare is a 422 naming it, because a typo silently accepted
            // renders as the literal text `{{reference}}` in a finished document.
            //
            // `render` is a TRI-STATE and its absent case is the common one:
            // absent = "render if this instance can", true = "I require an
            // artifact" (503 when it cannot), false = "record only". Omitting it
            // is what a client that does not care should do.
            'DocumentCreateRequest' => self::object([
                'document_template_id' => self::int(),
                'title' => self::str(true),
                'dataRows' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
                'sheet' => ['type' => 'object', 'additionalProperties' => true, 'nullable' => true],
                'render' => ['type' => 'boolean', 'nullable' => true],
            ], ['document_template_id']),

            // Why the render outcome is a SIBLING of `data` rather than a status
            // code, and why `reason` is a closed vocabulary: a 201 means the
            // document exists, which it does on an instance with no render tier
            // at all (`documents.render_enabled` defaults to false). A client
            // deciding whether to offer a "render now" button has to tell
            // `disabled` (never going to work here) from `unavailable`
            // (transient, retry) from `declined` (you asked for this), and a
            // prose message cannot be branched on. Same posture as the routing
            // create's `resolved`/`delivered` keys.
            'DocumentCreateResponse' => self::object([
                'data' => SchemaBuilder::ref('Document'),
                'render' => self::object([
                    'attempted' => self::bool(),
                    'stored' => self::bool(),
                    'reason' => [
                        'type' => 'string',
                        'nullable' => true,
                        'enum' => [
                            'declined',
                            'disabled',
                            'persist_disabled',
                            'rejected',
                            'unavailable',
                            'storage_unavailable',
                            null,
                        ],
                    ],
                ], ['attempted', 'stored']),
            ], ['data', 'render']),
            // The re-render body. Same render inputs as DocumentRenderRequest
            // minus `persist`/`title`: this route ALWAYS persists (that is what
            // it is for) and never renames the record it appends to.
            'DocumentRerenderRequest' => self::object([
                'dataRows' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']]],
                'sheet' => ['type' => 'object', 'additionalProperties' => true, 'nullable' => true],
            ], []),

            // -- Document routing (#947 item 3) --------------------------------
            // A ROUTE is one circulation of one document; its STEPS are the
            // ordered plan, and each step names a RULE rather than a person, so
            // the people are resolved at send time against the organisation as
            // it stands then. The TRAIL is append-only and is the system of
            // record - there is no status column anywhere in routing, on purpose.
            //
            // `rule_config` is freeform because a rule's parameters are open by
            // construction: core cannot know what an `acme:committee` rule needs
            // to be told, and the only code that does is the resolver the plugin
            // registered (which validates it at authoring time). Contrast the
            // trail below, whose shape IS fixed and is therefore typed.
            'RoutingRule' => self::object([
                'kind' => self::str(),
                'label' => self::str(),
                // 'core', or the plugin that contributed the kind.
                'source' => self::str(),
            ], ['kind', 'label', 'source']),
            'RoutingRuleListResponse' => self::listEnvelope('RoutingRule'),

            'DocumentRouteStep' => self::object([
                'id' => self::int(),
                // A 1-based AUTHORING ORDINAL, not a depth: see migration 112.
                'position' => self::int(),
                'rule_kind' => self::str(),
                'rule_config' => ['type' => 'object', 'additionalProperties' => true],
                'label' => self::str(true),
                // #1014. `decision` is whether this step is a GATE - answered
                // with a verdict rather than a forward. Published rather than
                // inferred from the edges, because a gate at the END of a route
                // has no outgoing edge and still demands one.
                'decision' => self::bool(),
                // The step's own override of what "this node approved" means when
                // it fans out to many people. NULL is not "no quorum": it defers
                // to the tenant's `documents.routing_approval_quorum` setting,
                // which defaults to `all`.
                'decision_quorum' => ['type' => 'string', 'enum' => ['all', 'any', 'majority'], 'nullable' => true],
            ], ['id', 'position', 'rule_kind', 'rule_config', 'decision']),
            // Where a settled VERDICT sends the document (#1014, migration 119).
            // A flat list on the route rather than fields on a step, because an
            // edge is a relationship between two steps and belongs to neither -
            // and because a node editor reads a node list and an edge list.
            'DocumentRouteEdge' => self::object([
                'id' => self::int(),
                'route_id' => self::int(),
                'from_step_id' => self::int(),
                'to_step_id' => self::int(),
                'verdict' => ['type' => 'string', 'enum' => ['approved', 'rejected']],
            ], ['id', 'route_id', 'from_step_id', 'to_step_id', 'verdict']),
            'DocumentRoute' => self::object([
                'id' => self::int(),
                'document_id' => self::int(),
                'title' => self::str(),
                'created_by' => self::int(true),
                'created_at' => self::str(),
                'steps' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentRouteStep')],
                'edges' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentRouteEdge')],
            ], ['id', 'document_id', 'title', 'created_at', 'steps', 'edges']),
            'DocumentRouteListResponse' => self::listEnvelope('DocumentRoute'),
            // `resolved` and `delivered` are on the envelope rather than on the
            // route, because they describe what THIS request did rather than a
            // property of the record: `resolved` is how many people the first
            // step's rule answered with, `delivered` how many rows that became
            // after de-duplicating against chains that already reached them. A
            // rule that matched nobody is legal, and reporting the counts is what
            // makes it VISIBLE in the response instead of six weeks later.
            'DocumentRouteResponse' => self::object([
                'data' => SchemaBuilder::ref('DocumentRoute'),
                'resolved' => self::int(),
                'delivered' => self::int(),
            ], ['data', 'resolved', 'delivered']),
            // `steps` is required and must be non-empty: a route with no steps
            // would issue a document to nobody and record it as sent.
            'DocumentRouteCreateRequest' => self::object([
                'title' => self::str(true),
                'steps' => [
                    'type' => 'array',
                    'items' => self::object([
                        'rule_kind' => self::str(),
                        'rule_config' => ['type' => 'object', 'additionalProperties' => true],
                        'label' => self::str(true),
                        // #1014. `decision` turns the step into a gate. The other
                        // three are refused outright when it is absent or false -
                        // a quorum or an edge on a step that produces no verdict
                        // is a stored intention that silently does nothing.
                        'decision' => self::bool(true),
                        'decision_quorum' => [
                            'type' => 'string',
                            'enum' => ['all', 'any', 'majority'],
                            'nullable' => true,
                        ],
                        // Targets are named by 1-BASED POSITION in this same
                        // `steps` array, not by id: while a route is being
                        // composed its steps have no ids yet, and the position is
                        // the only handle an author has. Reads publish ids.
                        // Backwards edges are legal ("rejected goes back to
                        // step 1 for correction"); an edge onto the step itself
                        // is not.
                        'on_approved' => self::int(true),
                        'on_rejected' => self::int(true),
                    ], ['rule_kind']),
                ],
            ], ['steps']),

            // The trail. Every field is a COLUMN (migration 112) rather than a
            // JSONB key, because the shape is fixed and known to core - which is
            // the argument for the table existing at all.
            'DocumentTrailEvent' => self::object([
                'id' => self::int(),
                'document_id' => self::int(),
                'route_id' => self::int(),
                // Null on an `issued` event, which is about the route rather than
                // any one step.
                'step_id' => self::int(true),
                'actor_profile_id' => self::int(true),
                'action' => ['type' => 'string', 'enum' => ['issued', 'forwarded', 'acknowledged', 'returned', 'noted']],
                'from_ou_id' => self::int(true),
                // Null whenever the act named no SINGLE unit - a tenant-wide
                // fan-out has no destination, and naming one would make the
                // browser's "passed through my unit" folder report a unit that
                // was never involved.
                'to_ou_id' => self::int(true),
                'note' => self::str(true),
                // #1014: what the actor DECIDED, which is a different fact from
                // what they DID. NULL on every act that decided nothing - every
                // act on a circulation step, every note, and every event recorded
                // before migration 119. It never means "not approved".
                'verdict' => ['type' => 'string', 'enum' => ['approved', 'rejected'], 'nullable' => true],
                'occurred_at' => self::str(),
            ], ['id', 'document_id', 'route_id', 'action', 'occurred_at']),
            'DocumentTrailListResponse' => self::paginatedListEnvelope('DocumentTrailEvent'),

            // A recipient row: the inbox, and the document's own view of where it
            // currently is. `open` is DERIVED from `closed_by_event_id` and both
            // are published - the boolean is what a screen renders, the pointer
            // is what lets a reader follow the claim back into the trail and
            // check it.
            'DocumentRouteRecipient' => self::object([
                'id' => self::int(),
                'document_id' => self::int(),
                'route_id' => self::int(),
                'step_id' => self::int(),
                'profile_id' => self::int(),
                'ou_id' => self::int(true),
                // The recipient whose action produced this row; null at the first
                // step. This is what makes distribution fan out rather than block.
                'parent_recipient_id' => self::int(true),
                'created_by_event_id' => self::int(),
                'closed_by_event_id' => self::int(true),
                'open' => self::bool(),
                'created_at' => self::str(),
            ], ['id', 'document_id', 'route_id', 'step_id', 'profile_id', 'created_by_event_id', 'open', 'created_at']),
            'DocumentRouteRecipientListResponse' => self::listEnvelope('DocumentRouteRecipient'),

            'DocumentRouteActionRequest' => self::object([
                'action' => ['type' => 'string', 'enum' => ['forwarded', 'acknowledged', 'returned', 'noted']],
                // Required for `noted` (an empty note records nothing), optional
                // on the other three.
                'note' => self::str(true),
                // #1014. REQUIRED with `acknowledged` on a decision step and
                // refused everywhere else - including on a circulation step,
                // where a recorded verdict nothing routes on would read later as
                // an authorisation that was never asked for.
                'verdict' => ['type' => 'string', 'enum' => ['approved', 'rejected'], 'nullable' => true],
            ], ['action']),
            'DocumentRouteActionResponse' => self::object([
                'data' => SchemaBuilder::ref('DocumentTrailEvent'),
                'resolved' => self::int(),
                'delivered' => self::int(),
                // What the STEP concluded, which is NOT what the caller said: a
                // quorum of `all` means two of three approvals conclude nothing,
                // and this stays null until the third arrives. On the envelope
                // rather than on the event for the reason the two counts are - it
                // describes what this request did, not a property of the record.
                'decided' => ['type' => 'string', 'enum' => ['approved', 'rejected'], 'nullable' => true],
            ], ['data', 'resolved', 'delivered']),

            // -- The inbox (#881, first source contributed by #947 item 3) -----
            // Field names are the props the `inbox` block type declares (#868),
            // so a screen can point a block straight at this endpoint and the two
            // cannot disagree. `status` is NOT a stored status: for the routing
            // source it is read from the trail event that created the item.
            'InboxItem' => self::object([
                // A string, because the eventual cross-source aggregate will mix
                // sources whose ids are not integers.
                'id' => self::str(),
                'title' => self::str(),
                'subtitle' => self::str(true),
                'timestamp' => self::str(),
                'status' => self::str(true),
                'resource_type' => self::str(true),
                'resource_id' => self::str(true),
                // Source-specific extras a client may use and must not depend on.
                'meta' => ['type' => 'object', 'additionalProperties' => true],
            ], ['id', 'title', 'timestamp']),
            'InboxItemListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('InboxItem')],
                'pagination' => SchemaBuilder::ref('Pagination'),
                'source' => self::str(),
            ], ['data', 'pagination', 'source']),
            'InboxSource' => self::object([
                'key' => self::str(),
                'label' => self::str(),
                'origin' => self::str(),
                // The `inbox` block prop => item field mapping, published rather
                // than left for each client to hardcode.
                'item_fields' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'open_count' => self::int(),
            ], ['key', 'label', 'origin', 'item_fields', 'open_count']),
            'InboxSourceListResponse' => self::listEnvelope('InboxSource'),
            // ── The document organizer (#978, implementing #947 item 5) ───────
            // A "folder" is a NAMED QUERY, never a stored container: a document
            // raised centrally and needed by fifteen units has no single home,
            // and a stored tree has to be maintained as the organisation
            // changes. So this describes the folders that EXIST, which is not
            // the same as the folders somebody specified.
            //
            // `requires` names the fact sources the view reads. A view whose
            // sources this installation does not record is NOT IN THE RESPONSE
            // AT ALL. Note the converse, which #947 item 3 landing made
            // concrete: its facts now exist, so the routing substrates resolve,
            // and item 5's "awaiting me", "acted on by me" and "passed through
            // my unit" are still absent because a substrate is not a folder —
            // each needs a predicate and a registration. An empty "Awaiting me"
            // would state "nothing awaits you", which is false and which the
            // reader cannot check.
            //
            // `available: false` is the DIFFERENT case: the folder is real and
            // THIS caller cannot anchor it (they belong to no unit). It carries
            // the reason and is rendered disabled, per #951 — a control hidden
            // for three unrelated causes makes all three look identical.
            'DocumentView' => self::object([
                'key' => self::str(),
                // English. A client translates the keys it knows and falls back
                // to this for a view registered by a later feature or a plugin,
                // which it cannot have a translation for.
                'label' => self::str(),
                'description' => self::str(),
                // `derived` (a fact about the document) or `personal` (a fact
                // about you).
                'group' => self::str(),
                'parameters' => ['type' => 'array', 'items' => self::object([
                    'name' => self::str(),
                    'required' => self::bool(),
                ], ['name', 'required'])],
                'requires' => ['type' => 'array', 'items' => self::str()],
                'available' => self::bool(),
                'unavailable_reason' => self::str(true),
            ], ['key', 'label', 'description', 'group', 'parameters', 'requires', 'available']),
            // What this installation does NOT record, and what would supply it.
            // A diagnostic, deliberately a separate field from `data`: an
            // operator asking "why is there no inbox here" otherwise has no
            // answer at all, and nothing in this list has a key to open, so no
            // client can mistake it for a folder.
            'DocumentSubstrate' => self::object([
                'key' => self::str(),
                'description' => self::str(),
                'provenance' => self::str(true),
            ], ['key', 'description']),
            'DocumentViewListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentView')],
                'unavailable_substrates' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentSubstrate')],
            ], ['data', 'unavailable_substrates']),

            // ── Per-user collections (#978) ──────────────────────────────────
            // The one part of the organizer that is stored, because it is the
            // one part that claims nothing about the document: "I filed this"
            // is a fact about me.
            //
            // `system_key` is `starred` for the collection the star control
            // addresses and null for one somebody made. It is the collection's
            // IDENTITY rather than its name, which is why the name is free to
            // be renamed or translated and why a keyed collection refuses
            // rename and delete (409). Starring is not a separate concept —
            // there is no `document_stars` table; migration 114 argues why.
            //
            // `item_count` is how many documents are FILED, which can exceed
            // how many the owner may still read: visibility narrows over time
            // and a stored pointer is never a grant.
            'DocumentCollection' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'profile_id' => self::int(),
                'name' => self::str(),
                'system_key' => self::str(true),
                'created_at' => self::str(),
                'item_count' => self::int(),
            ], ['id', 'tenant_id', 'profile_id', 'name', 'created_at']),
            // ── Named user groups (#999) ──────────────────────────────────────
            // A group is a NAMED RULE, not a list of people. The shape says so:
            // there is no `members` field, no `member_count`, and no
            // `member_ids`, because a group's membership is not a property of the
            // group — it is a question asked of the organisation at a moment in
            // time, relative to whoever asks, and `/preview` is where it is
            // answered.
            //
            // `rule_kind` + `rule_config` are the pair #989 shipped for route
            // steps, deliberately spelled the same: a group and a route step are
            // the same expression, one stored under a name for reuse and one
            // stored inline for a single circulation. `rule_config` is freeform
            // for the same reason it is freeform there — core cannot know what an
            // `acme:committee` rule needs to be told, and only the resolver the
            // plugin registered does.
            'UserGroup' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'name' => self::str(),
                // Optional prose. A name cannot carry intent — "Instructors"
                // does not say whether visiting lecturers count — and a group
                // many people will address documents to needs somewhere to say.
                'description' => self::str(true),
                'rule_kind' => self::str(),
                'rule_config' => ['type' => 'object', 'additionalProperties' => true],
                // Null once the person who defined it has been deleted. The group
                // survives them: "instructors" is the institution's definition,
                // not that person's private filing, which is why migration 116
                // makes this SET NULL where `document_collections.profile_id`
                // cascades.
                'created_by' => self::int(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'tenant_id', 'name', 'rule_kind', 'rule_config', 'created_at', 'updated_at']),
            'UserGroupListResponse' => self::paginatedListEnvelope('UserGroup'),
            'UserGroupResponse' => self::dataEnvelope(SchemaBuilder::ref('UserGroup')),
            'UserGroupCreateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(true),
                'rule_kind' => self::str(),
                'rule_config' => ['type' => 'object', 'additionalProperties' => true],
            ], ['name', 'rule_kind']),
            // PATCH: omitted fields keep their value. `rule_kind` and
            // `rule_config` must be sent TOGETHER or not at all — a config
            // written for `role` means nothing to `explicit`, and pairing a new
            // kind with the old config silently would store a rule the resolver
            // will later refuse.
            'UserGroupUpdateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(true),
                'rule_kind' => self::str(),
                'rule_config' => ['type' => 'object', 'additionalProperties' => true],
            ], []),
            'UserGroupPreviewRequest' => self::object([
                'rule_kind' => self::str(),
                'rule_config' => ['type' => 'object', 'additionalProperties' => true],
            ], ['rule_kind']),
            'UserGroupDeleteResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'deleted' => self::bool(),
            ], ['id', 'deleted'])),
            // The rule kinds a GROUP DEFINITION may name — a subset of
            // `/api/routing-rules`, excluding `group` itself and any plugin kind
            // that needs the document it is routed with. Same row shape as
            // `RoutingRule` and deliberately a separate component: a client draws
            // one picker from one list, rather than filtering a list by a rule it
            // would have to know.
            'GroupRule' => self::object([
                'kind' => self::str(),
                'label' => self::str(),
                'source' => self::str(),
            ], ['kind', 'label', 'source']),
            'GroupRuleListResponse' => self::listEnvelope('GroupRule'),
            // One sampled person. `display_name` is null when the caller does not
            // hold `users:read` — the COUNT is a fact about the rule, a name is a
            // fact about a person, and `users:read` is the platform's existing
            // answer to who may read those. Nullable rather than omitted so
            // there is ONE payload shape and a client renders an id when there is
            // no name instead of branching on which flavour it received.
            'UserGroupPreviewMember' => self::object([
                'profile_id' => self::int(),
                // The unit the rule reached them THROUGH, or null when the rule
                // is not unit-scoped. `explicit` is always null: it named people,
                // through no unit at all.
                'ou_id' => self::int(true),
                'display_name' => self::str(true),
            ], ['profile_id', 'ou_id', 'display_name']),
            // A COUNT AND A SAMPLE, NEVER A LIST, and there is no page parameter
            // anywhere on this shape. `total` is exact; `sample` holds at most
            // `sample_size` people (the `groups.preview_sample_size` setting),
            // lowest profile id first so two previews of an unchanged group show
            // the same faces. A surface that rendered 1,043 rows would have
            // rebuilt the thousand-nodes problem the whole design avoids; a
            // caller who wants a person-by-person list is asking `/api/users` a
            // question about roles.
            //
            // `resolved_for` is on every preview, not only the actor-relative
            // kinds. `role_below_actor` resolves to a different set for a dean
            // than for a faculty officer, so without this two colleagues would
            // read two different counts off the same screen with nothing to
            // explain the difference — and whether a kind is relative is the
            // resolver's business, not something core can ask it.
            'UserGroupPreviewResponse' => self::dataEnvelope(self::object([
                'total' => self::int(),
                'truncated' => self::bool(),
                'sample_size' => self::int(),
                'sample' => ['type' => 'array', 'items' => SchemaBuilder::ref('UserGroupPreviewMember')],
                'resolved_for' => self::object([
                    'profile_id' => self::int(true),
                    'ou_id' => self::int(true),
                ], ['profile_id', 'ou_id']),
            ], ['total', 'truncated', 'sample_size', 'sample', 'resolved_for'])),

            'DocumentCollectionListResponse' => self::listEnvelope('DocumentCollection'),
            'DocumentCollectionResponse' => self::dataEnvelope(SchemaBuilder::ref('DocumentCollection')),
            'DocumentCollectionCreateRequest' => self::object([
                'name' => self::str(),
            ], ['name']),
            'DocumentCollectionUpdateRequest' => self::object([
                'name' => self::str(),
            ], ['name']),
            // Read back rather than asserted: filing is idempotent, and two
            // clicks racing a concurrent un-star from another tab both land
            // here, so the row that is actually there is the answer.
            'DocumentCollectionMembershipResponse' => self::dataEnvelope(self::object([
                'collection_id' => self::int(),
                'document_id' => self::int(),
                'in_collection' => self::bool(),
            ], ['collection_id', 'document_id', 'in_collection'])),
            // The starred collection as it now stands, plus the resulting state.
            // `data` is null only on an un-star by someone who has never starred
            // anything — creating the collection just to delete a row from it
            // would write a row to record an absence.
            'DocumentStarResponse' => self::object([
                'data' => ['oneOf' => [SchemaBuilder::ref('DocumentCollection'), ['type' => 'null']]],
                'starred' => self::bool(),
            ], ['data', 'starred']),

            // ── Document/label designer blocks (WC-521) ───────────────────────
            // `data` is the verbatim client DocElement[] fragment (freeform array);
            // documents reference a block by POINTER (a `blockInstance` element
            // carrying this block's id), never an inline copy.
            'DocumentBlock' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'name' => self::str(),
                'data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'scope' => ['type' => 'string', 'enum' => ['personal', 'tenant', 'global', 'system']],
                'required_permission' => self::str(true),
                'is_system' => self::bool(),
                'created_by' => self::int(true),
                // Where in the organisation the row is filed (migration 117). null = tenant-wide,
                // which is what every row was before it and what an unplaced row still is.
                'owner_ou_id' => self::int(true),
                // WHICH shipped starter this row is, or null for anything a user made
                // (#1013). `is_system` says "a system row" and cannot answer it, so a
                // client rendering a Starter badge off that alone can label the row but
                // never offer to restore the starter it came from.
                'starter_key' => self::str(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'tenant_id', 'name', 'data', 'scope', 'is_system', 'created_at', 'updated_at']),
            'DocumentBlockListResponse' => self::listEnvelope('DocumentBlock'),
            'DocumentBlockResponse' => self::dataEnvelope(SchemaBuilder::ref('DocumentBlock')),
            // scope/required_permission/owner_ou_id are all optional; setting a
            // shared scope, a permission tag, or a placement requires
            // documents:publish (403 otherwise). owner_ou_id must be a unit of the
            // caller's own tenant (422 otherwise); null files the row tenant-wide.
            'DocumentBlockCreateRequest' => self::object([
                'name' => self::str(),
                'data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'scope' => ['type' => 'string', 'enum' => ['personal', 'tenant', 'global', 'system']],
                'required_permission' => self::str(true),
                'owner_ou_id' => self::int(true),
            ], ['name', 'data']),
            'DocumentBlockUpdateRequest' => self::object([
                'name' => self::str(),
                'data' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'scope' => ['type' => 'string', 'enum' => ['personal', 'tenant', 'global', 'system']],
                'required_permission' => self::str(true),
                'owner_ou_id' => self::int(true),
            ], []),
            // What would break if this block changed — the answer a management UI
            // needs BEFORE offering an edit or a delete, since a block is
            // pointer-referenced and an edit propagates to every instance.
            //
            // `templates` is row-filtered (never the identity of a template the
            // caller may not see); `total` is NOT, and `hidden` is the difference.
            // A visible-only total would understate the blast radius of an edit
            // to exactly the callers whose reach is narrowest — the argument is
            // written out in full on DocumentBlocksApiHandler::usage().
            //
            // `owner_ou_id` is an id, not a name: resolving unit names is
            // ous:read's job and this route is gated on documents:read, so a
            // client that cannot read units renders the id rather than being
            // handed a name this endpoint had no authority to look up.
            'DocumentBlockUsage' => self::object([
                'block_id' => self::int(),
                'total' => self::int(),
                'hidden' => self::int(),
                'templates' => ['type' => 'array', 'items' => self::object([
                    'id' => self::int(),
                    'name' => self::str(),
                    'scope' => ['type' => 'string', 'enum' => ['personal', 'tenant', 'global', 'system']],
                    'required_permission' => self::str(true),
                    'owner_ou_id' => self::int(true),
                    'is_system' => self::bool(),
                    'updated_at' => self::str(),
                ], ['id', 'name', 'scope', 'is_system', 'updated_at'])],
            ], ['block_id', 'total', 'hidden', 'templates']),
            'DocumentBlockUsageResponse' => self::dataEnvelope(SchemaBuilder::ref('DocumentBlockUsage')),

            // ── Resource-scoped role grants (WC-712 §3) ───────────────────────
            // `profile_id` nullability carries the meaning, so it is nullable
            // here and NOT in the required list of the create request: null (or
            // omitted) is the "everyone at this resource" grant, a value is the
            // "this profile at this resource" grant.
            'ResourceRoleGrant' => self::object([
                'id' => self::int(),
                'role_id' => self::int(),
                'profile_id' => self::int(true),
            ], ['id', 'role_id', 'profile_id']),
            'ResourceRoleGrantListResponse' => self::listEnvelope('ResourceRoleGrant'),
            'ResourceRoleGrantCreateRequest' => self::object([
                'resource_type' => self::str(),
                'resource_id' => self::int(),
                'role_id' => self::int(),
                'profile_id' => self::int(true),
            ], ['resource_type', 'resource_id', 'role_id']),
            // `created` distinguishes a fresh grant from an idempotent repeat,
            // so a caller can tell the two apart without comparing status codes.
            'ResourceRoleGrantResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'resource_type' => self::str(),
                'resource_id' => self::int(),
                'role_id' => self::int(),
                'profile_id' => self::int(true),
                'created' => self::bool(),
            ], ['id', 'tenant_id', 'resource_type', 'resource_id', 'role_id', 'profile_id', 'created'])),
            // The record-delete cleanup: which resource was cleaned and how many
            // grants that removed. The count is what makes the call verifiable —
            // 0 is a success, so without it a caller cannot tell "that record had
            // no grants" from "I addressed the wrong record".
            'ResourceRoleGrantRevokeAllResult' => self::object([
                'resource_type' => self::str(),
                'resource_id' => self::int(),
                'revoked' => self::int(),
            ], ['resource_type', 'resource_id', 'revoked']),
            'ResourceRoleGrantRevokeAllResponse' => self::dataEnvelope(SchemaBuilder::ref('ResourceRoleGrantRevokeAllResult')),

            'User' => $user,
            'UserListResponse' => self::paginatedListEnvelope('User'),
            'UserResponse' => self::dataEnvelope(SchemaBuilder::ref('User')),
            // NOTE: no tenantId field — the handler always creates the user in
            // the caller's TenantContext (a declared field with zero runtime
            // effect would be a contract lie).
            // WC-712 §1: one row per role the profile holds in a tenant.
            // `isPrimary` marks the row that answers "what is this person here?"
            // for display and defaults — exactly one per (profile, tenant),
            // enforced by migration 094's partial unique index.
            // #797 §2: every row names its tenant. For a tenant caller that is a
            // constant; for a tenant-0 caller the list spans tenants and this is
            // the only thing distinguishing the rows.
            'Membership' => self::object([
                'id' => self::int(),
                'tenantId' => self::int(),
                'tenantName' => self::str(),
                'roleId' => self::int(),
                'role' => self::str(),
                'ou_id' => self::int(true),
                'isPrimary' => ['type' => 'boolean'],
                'status' => ['type' => 'string', 'enum' => ['active', 'invited', 'suspended']],
            ], ['id', 'tenantId', 'tenantName', 'roleId', 'role', 'isPrimary', 'status']),
            'MembershipListResponse' => self::dataEnvelope([
                'type' => 'array',
                'items' => SchemaBuilder::ref('Membership'),
            ]),
            // `created` is false when the role was already held with the same OU:
            // the call is idempotent rather than a 409, because granting a role
            // somebody already has is not an error.
            'MembershipResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'tenantId' => self::int(),
                'roleId' => self::int(),
                'ou_id' => self::int(true),
                'isPrimary' => ['type' => 'boolean'],
                'created' => ['type' => 'boolean'],
            ], ['id', 'tenantId', 'roleId', 'isPrimary', 'created'])),
            // The role is REQUIRED here (unlike create, which defaults to `user`):
            // `$body['role_id'] ?? $body['role']` and a 400 "role_id is required"
            // when neither is present. The catalogue used to declare no required
            // field at all, which told a generated client an empty body was legal
            // — the one shape this endpoint always refuses.
            //
            // Two interchangeable spellings cannot both go in `required`, so the
            // component is a UNION of the two legal shapes. Every alternative
            // repeats the full property set, which is what makes it survive code
            // generation: `anyOf: [{required:[a]}, {required:[b]}]` — branches
            // carrying a `required` and nothing else — is the terser spelling of
            // the same rule, and openapi-typescript renders it as
            // `{...} | unknown | unknown`, collapsing to `unknown` and stripping
            // every field from the generated client. Full branches generate a
            // union of two typed objects instead.
            //
            // `anyOf` and not `oneOf`: sending BOTH spellings is legal (`role_id`
            // simply wins), so "exactly one" would refuse a request the handler
            // accepts. Declaring `role_id` alone required is equally wrong in the
            // other direction — core's own memberships modal sends `{role: name}`,
            // the alias, so that would invalidate a call the platform makes
            // itself.
            //
            // `tenant_id` (#797 §2) names the tenant to grant IN and is honoured
            // ONLY for a tenant-0 caller — anyone else sending it gets a 403
            // rather than a silent ignore. Omitted, the tenant is the caller's
            // and the endpoint behaves exactly as it did.
            'MembershipCreateRequest' => [
                'anyOf' => [
                    self::object($membershipGrant, ['role_id']),
                    self::object($membershipGrant, ['role']),
                ],
            ],
            'UserCreateRequest' => self::object([
                'email' => self::email(),
                'password' => self::password(),
                // Accepted as a role NAME or a numeric role id, under either
                // spelling: the handler reads `$body['role'] ?? $body['role_id']`.
                // `role_id` was missing from this declaration entirely, so a
                // generated client had no way to send the id form.
                //
                // OPTIONAL, and an ABSENT role DEFAULTS to the global `user`
                // role (a 500 if that role is missing from the instance). A
                // supplied-but-invisible role is a 404, not a fallback.
                //
                // "Absent" means the key is not present. Either spelling
                // PRESENT but null or empty is a 400, NOT the default (#917):
                // substituting the least-privileged role for a key with nothing
                // behind it is how an account meant to be an administrator was
                // created as an ordinary user, with a 201 and nothing in any
                // log. The distinction is not expressible in JSON Schema — a
                // property can be absent or typed, not "present but must not be
                // empty" — so it is stated here, where a client author reading
                // the declaration will see it.
                'role' => $permissionRef,
                'role_id' => self::reference('/api/roles', 'name'),
                // Optional OU placement, so provisioning is one atomic call. The
                // OU must belong to the caller's tenant (403 otherwise); omitted
                // or null leaves the membership unassigned.
                'ou_id' => self::reference('/api/ous', 'name', nullable: true),
            ], ['email', 'password']),
            'UserUpdateRequest' => self::object([
                'email' => self::email(),
                'password' => self::password(),
                'role' => $permissionRef,
                'role_id' => self::reference('/api/roles', 'name'),
                // array_key_exists, not isset: an explicit null CLEARS the OU.
                'ou_id' => self::reference('/api/ous', 'name', nullable: true),
                // WC-user-status: the admin deactivate/reactivate control.
                'accountStatus' => ['type' => 'string', 'enum' => ['active', 'inactive']],
                // #917: permits `password` against an account whose credentials
                // belong to an identity provider (`authMethod: 'idp'`), which is
                // otherwise refused with 409. Taking it moves the account to
                // 'both' and records an audit row - the arrangement stays
                // available, it just stops being something that can happen by
                // accident. Ignored when no password is sent.
                'allowLocalPasswordOnIdpAccount' => self::bool(),
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
            // `manageable` mirrors the LIST row's flag (WC-222): whether THIS
            // tenant may write this role. #882 added it here because a record
            // page reached by URL has no list row to read it from, and a page
            // that guesses renders an editable form that 404s on save.
            'RoleDetail' => self::object([
                'id' => self::int(),
                'name' => self::str(),
                'description' => self::str(true),
                'parent_id' => self::int(true),
                'created_at' => self::str(true),
                'manageable' => self::bool(),
                // #886 — the same "is this shared by every tenant" fact the list
                // rows carry. The record page previously inferred it from
                // `!manageable`, which reads correctly for a tenant and inverts
                // for the system tenant.
                'global' => self::bool(),
                // #910 — OPTIONAL, and its absence is what says "hidden".
                //
                // `permissions` is no longer required, because a caller without
                // `permissions:read` does not receive the region's data at all:
                // the record page gates its REGIONS, and a hidden region is
                // withheld rather than suppressed on the client. A response that
                // shipped the rows and asked the browser not to draw them would
                // be a rendering instruction, not a control.
                'permissions' => ['type' => 'array', 'items' => SchemaBuilder::ref('Permission')],
                // The per-region verdicts. Keyed by region (`details`,
                // `permissions`); a region the caller may not see is ABSENT,
                // which is the only way this contract has of saying so — a
                // `{"state": "hidden"}` entry would disclose the region it was
                // withholding, and shipping a viewer the labels of things they
                // may not see is a different bug wearing authorization's clothes.
                'sections' => [
                    'type' => 'object',
                    'additionalProperties' => SchemaBuilder::ref('RecordSectionVerdict'),
                ],
            ], ['id', 'name', 'description', 'parent_id', 'created_at', 'manageable', 'global']),
            // The same `{code, reason, detail}` shape #951/#968 settled for a
            // denied crud control, because a region is the same idea one level
            // up: present, inert, and able to say why.
            'RecordSectionDenial' => self::object([
                // `permission` — the caller lacks what the write needs;
                // `record` — the record itself refuses (a global base role).
                'code' => ['type' => 'string', 'enum' => ['permission', 'record']],
                // Audience-safe prose, and the client's i18n fallback.
                'reason' => self::str(),
                // Operator-grade, naming the permission the write would need.
                // Non-null only for a caller holding `permissions:read` — the
                // permission that governs seeing permission slugs at all.
                'detail' => self::str(true),
            ], ['code', 'reason', 'detail']),
            'RecordSectionVerdict' => self::object([
                'state' => ['type' => 'string', 'enum' => ['read-only', 'editable']],
                'denial' => SchemaBuilder::ref('RecordSectionDenial'),
            ], ['state', 'denial']),
            'RoleDetailResponse' => self::dataEnvelope(SchemaBuilder::ref('RoleDetail')),
            // #882 — one holder of a role. `assignedAt` is the membership's
            // created_at: when this person was given this role in this tenant,
            // which is what makes the list an assignment history rather than a
            // roster in arbitrary order. `email` is nullable because the primary
            // email row is LEFT JOINed — someone without one still holds the
            // role. `tenantId` is a constant for a tenant caller and the only
            // thing distinguishing rows for a tenant-0 one.
            'RoleAssignment' => self::object([
                'membershipId' => self::int(),
                'profileId' => self::int(),
                'tenantId' => self::int(),
                'displayName' => self::str(),
                'email' => self::str(true),
                'ouId' => self::int(true),
                'isPrimary' => self::bool(),
                'status' => self::str(),
                'assignedAt' => self::str(true),
            ], ['membershipId', 'profileId', 'tenantId', 'displayName', 'isPrimary', 'status']),
            'RoleAssignmentListResponse' => self::paginatedListEnvelope('RoleAssignment'),
            // #888 — `tenant_id` names the tenant the role is created FOR and
            // `global: true` asks for a shared NULL-tenant base role. Both are
            // honoured ONLY for a tenant-0 caller (403 otherwise, never a silent
            // ignore) and both are optional, so an unqualified create still
            // stamps the caller's own tenant exactly as before.
            //
            // Two fields rather than one nullable one, and `tenant_id` is
            // INTEGER, not nullable-integer, on purpose: ownership has three
            // states and `tenant_id: null` for the third would make the meaning
            // on the wire depend on whether a client serialises an unset
            // optional as `null` or drops it.
            //
            // The conflict is VALUE-dependent, so it is stated here rather than
            // as a `not: {required: [tenant_id, global]}` clause: the handler
            // refuses `global: true` ALONGSIDE `tenant_id` (400) but accepts
            // `global: false` alongside it. A presence-based exclusion would
            // forbid `{tenant_id: 1, global: false}`, which the API accepts, and
            // a client validating against it would refuse to send a legal
            // request. RequestSchemaValidationParityTest pins both halves.
            'RoleCreateRequest' => self::object([
                // `empty()` rejects it, so "" is a 400 as surely as an omission.
                'name' => self::name(nonEmpty: true),
                'description' => self::text(),
                // Mixed id-or-`resource:action` notation. A DIGIT-STRING is read
                // as an id, never as a name, and a reference that resolves to
                // nothing is dropped silently rather than refused.
                'permissions' => ['type' => 'array', 'items' => $permissionRef],
                'tenant_id' => self::int(),
                'global' => self::bool(),
            ], ['name']),
            'RoleUpdateRequest' => self::object([
                'name' => self::name(nonEmpty: true),
                'description' => self::text(),
                // A full REPLACE of the role's grants, so `[]` revokes every one
                // of them. Omitting the key leaves the grants untouched — the
                // two are emphatically not the same request.
                'permissions' => ['type' => 'array', 'items' => $permissionRef],
            ], []),
            // `tenantId`/`global` echo the RESOLVED owner (#888). The request's
            // ownership fields are optional, so a caller that omitted them
            // otherwise cannot tell what it got. In a RESPONSE the field is
            // always present, so `tenantId: null` is unambiguously global — the
            // omitted-vs-explicit-null problem exists only on the request side.
            'RoleCreateResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'name' => self::str(),
                'description' => self::str(),
                'permissionCount' => self::int(),
                'tenantId' => self::int(true),
                'global' => self::bool(),
            ], ['id', 'name'])),
            // #712 — the delta body shared by POST and DELETE
            // /api/roles/{id}/permissions. Same mixed id-or-`resource:action`
            // notation as RoleCreateRequest.permissions, so a client that can
            // already build a full replace can build a delta with no new code.
            'RolePermissionsChangeRequest' => self::object([
                'permissions' => ['type' => 'array', 'items' => $permissionRef],
            ], ['permissions']),
            // `granted` / `revoked` count what the call actually CHANGED, which
            // for an idempotent endpoint is not the size of the request: a
            // re-grant of a held permission reports 0 and still returns 200.
            'RolePermissionsGrantResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'message' => self::str(),
                'granted' => self::int(),
                'permissions' => ['type' => 'array', 'items' => SchemaBuilder::ref('Permission')],
            ], ['id', 'message', 'granted', 'permissions'])),
            'RolePermissionsRevokeResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'message' => self::str(),
                'revoked' => self::int(),
                'permissions' => ['type' => 'array', 'items' => SchemaBuilder::ref('Permission')],
            ], ['id', 'message', 'revoked', 'permissions'])),
            'RoleSummary' => $roleSummary,
            'RoleSummaryListResponse' => self::listEnvelope('RoleSummary'),

            'Tenant' => $tenant,
            'TenantListResponse' => self::paginatedListEnvelope('Tenant'),
            'TenantResponse' => self::dataEnvelope(SchemaBuilder::ref('Tenant')),
            // Create echoes the administrator it provisioned, so the caller can
            // report who now owns the tenant without a second round trip. Absent
            // when the request carried no `admin` block.
            'TenantCreatedResponse' => self::dataEnvelope(['allOf' => [
                SchemaBuilder::ref('Tenant'),
                self::object([
                    'admin' => self::object([
                        'id' => self::int(),
                        'email' => self::str(),
                        'role' => self::str(),
                    ], ['id', 'email', 'role']),
                ], []),
            ]]),
            // #779: the optional `admin` block provisions the tenant's first
            // administrator in the SAME transaction as the tenant. Without it,
            // POST /api/tenants leaves a tenant no API path can reach — creating
            // a user targets the caller's tenant, and switching to the new one
            // requires the very membership that cannot yet be made.
            'TenantCreateRequest' => self::object([
                'name' => self::str(),
                'slug' => ['type' => 'string', 'pattern' => '^[a-z0-9-]+$'],
                'admin' => SchemaBuilder::ref('TenantInitialAdmin'),
            ], ['name']),
            // `role` names a role the new tenant can see: one it owns (seeded by
            // a tenant.created listener) or a global one. Defaults to `admin`.
            'TenantInitialAdmin' => self::object([
                'email' => ['type' => 'string', 'format' => 'email'],
                'password' => ['type' => 'string', 'format' => 'password'],
                'role' => self::str(),
            ], ['email', 'password']),
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
                'ou_type_id' => self::int(true),
                'ou_type_key' => self::str(true),
                'ou_type_label' => self::str(true),
                'children' => [
                    'type' => 'array',
                    'items' => self::object(['id' => self::int()], ['id']),
                ],
            ], [
                'id', 'tenant_id', 'parent_id', 'name', 'slug', 'description', 'created_at',
                'ou_type_id', 'ou_type_key', 'ou_type_label', 'children',
            ]),
            'OuDetailResponse' => self::dataEnvelope(SchemaBuilder::ref('OuDetail')),
            'OuCreateRequest' => self::object([
                'name' => self::name(nonEmpty: true),
                'description' => self::text(),
                // Omitted or null makes the unit a root. A parent outside the
                // caller's tenant is a 403, not a 404.
                'parent_id' => self::reference('/api/ous', 'name', nullable: true),
                // #822: the unit's kind, addressed by id OR by stable key.
                // Supplying both is a 422 rather than a silent preference — the
                // `not` clause below is what says so in the published contract.
                'ou_type_id' => self::reference('/api/ou-types', 'label', nullable: true),
                'type' => self::ouTypeKey(),
            ], ['name']) + self::mutuallyExclusive('ou_type_id', 'type'),
            'OuUpdateRequest' => self::object([
                'name' => self::name(nonEmpty: true),
                'description' => self::text(),
                // array_key_exists, not isset: an explicit null moves the unit
                // to the root, and an explicit null `ou_type_id`/`type` untypes
                // it. (`name` and `description` are read with isset, so an
                // explicit null there is a silent no-op, not a clear.)
                'parent_id' => self::reference('/api/ous', 'name', nullable: true),
                'ou_type_id' => self::reference('/api/ou-types', 'label', nullable: true),
                'type' => self::ouTypeKey(),
            ], []) + self::mutuallyExclusive('ou_type_id', 'type'),
            'OuRoleAssignRequest' => self::object([
                'role_id' => self::reference('/api/roles', 'name') + ['minimum' => 1],
            ], ['role_id']),
            'OuRoleAssignment' => self::object([
                'id' => self::int(),
                'ou_id' => self::int(),
                'role_id' => self::int(),
                'tenant_id' => self::int(),
            ], ['id', 'ou_id', 'role_id', 'tenant_id']),
            'OuRoleAssignmentResponse' => self::dataEnvelope(SchemaBuilder::ref('OuRoleAssignment')),

            // #822. `key` is the stable identifier a routing rule binds to —
            // bare for a tenant's own vocabulary, `plugin:slug` for a type a
            // plugin contributed. `label` is the tenant's rendering of it, so
            // one install's `faculty` reads as School and another's as
            // Kulliyyah. `source` records provenance: `tenant`, `core`, or the
            // plugin that declared it.
            'OuType' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'key' => self::str(),
                'label' => self::str(),
                'sort_order' => self::int(),
                'source' => self::str(),
                'created_at' => self::str(true),
                'updated_at' => self::str(true),
            ], ['id', 'tenant_id', 'key', 'label', 'sort_order', 'source', 'created_at', 'updated_at']),
            'OuTypeResponse' => self::dataEnvelope(SchemaBuilder::ref('OuType')),
            'OuTypeListResponse' => self::dataEnvelope([
                'type' => 'array',
                'items' => SchemaBuilder::ref('OuType'),
            ]),
            'OuTypeCatalogEntry' => self::object([
                'key' => self::str(),
                'source' => self::str(),
                'label' => self::str(),
                'sort_order' => self::int(true),
                'adopted' => ['type' => 'boolean'],
                'ou_type_id' => self::int(true),
            ], ['key', 'source', 'label', 'sort_order', 'adopted', 'ou_type_id']),
            'OuTypeCatalogResponse' => self::dataEnvelope([
                'type' => 'array',
                'items' => SchemaBuilder::ref('OuTypeCatalogEntry'),
            ]),
            // The key is immutable: PATCH does not merely ignore a `key`, it
            // REFUSES the whole request with a 422, so the field is deliberately
            // absent from OuTypeUpdateRequest rather than declared-and-ignored.
            'OuTypeCreateRequest' => self::object([
                // Trimmed before validation, so surrounding whitespace is
                // stripped rather than rejected. A NAMESPACED key must already be
                // declared by a plugin, and the reserved key `none` is always a
                // 422 — neither is expressible as a pattern.
                'key' => self::ouTypeKey(nullable: false) + ['minLength' => 1],
                // Empty, whitespace-only and null all mean "absent": the label
                // falls back to the plugin-declared one, then to the key itself.
                'label' => self::name(),
                // Absent means the server appends it after the current maximum.
                'sort_order' => self::int(),
            ], ['key']),
            // Every field is individually optional, but a body that changes
            // NOTHING is a 422 ('No updatable fields supplied'), so at least one
            // must be present — which `minProperties` is exactly for. The
            // declaration used to accept `{}`, the one body this route refuses.
            'OuTypeUpdateRequest' => self::object([
                'label' => self::name(nonEmpty: true),
                'sort_order' => self::int(),
            ], []) + ['minProperties' => 1],

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
                'screen' => ['type' => 'string', 'enum' => ['crud', 'custom', 'action', 'blocks', 'embed']],
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
                // WC-246: present (non-null) only for screen=embed — the
                // plugin's own GET route the host iframes into the admin shell.
                'embed' => [
                    'type' => 'object',
                    'nullable' => true,
                    'properties' => [
                        'path' => self::str(),
                    ],
                    'required' => ['path'],
                ],
                'requiredPermission' => self::str(),
                'capabilities' => self::object([
                    'canCreate' => self::bool(),
                    'canEdit' => self::bool(),
                    'canDelete' => self::bool(),
                ], ['canCreate', 'canEdit', 'canDelete']),
                // #951: why a capability came back FALSE, so the renderer can
                // disable the control and say what happened instead of omitting
                // it (three unrelated causes used to render as one missing
                // button). One entry per false capability — a true one has no
                // entry — so every property here is optional and the object is
                // empty when all three are granted. `detail` is the
                // operator-grade half and is non-null only for a caller holding
                // plugins:read; see FrontendFeaturesApiHandler for the audience
                // split.
                'capabilityReasons' => [
                    'type' => 'object',
                    'properties' => [
                        'canCreate' => SchemaBuilder::ref('CapabilityDenial'),
                        'canEdit' => SchemaBuilder::ref('CapabilityDenial'),
                        'canDelete' => SchemaBuilder::ref('CapabilityDenial'),
                    ],
                ],
                // WC-226: present (and host-validated) ONLY for screen='blocks' —
                // the platform-neutral block tree a renderer translates to native
                // widgets. A coarse array of objects here; the SDK BlockValidator
                // is the authoritative contract for each node's type/props, so the
                // items stay open rather than re-declaring that whitelist.
                'blocks' => [
                    'type' => 'array',
                    'items' => ['type' => 'object', 'additionalProperties' => true],
                ],
            ], ['id', 'plugin', 'label', 'icon', 'group', 'order', 'screen', 'resource', 'action', 'embed', 'requiredPermission', 'capabilities', 'capabilityReasons']),
            // #951: one denied capability, explained for both audiences at once.
            // `code` is the stable machine discriminant the renderer keys its
            // localized string off; `reason` is the already-localizable English
            // fallback, safe for any caller; `detail` names the route or the
            // RBAC the platform actually looked at and is null unless the caller
            // holds plugins:read.
            'CapabilityDenial' => self::object([
                'code' => ['type' => 'string', 'enum' => ['no-resource', 'no-route', 'forbidden']],
                'reason' => self::str(),
                'detail' => self::str(true),
            ], ['code', 'reason', 'detail']),
            // #953: a feature descriptor the host REFUSED, and why. Refused at
            // plugin load (an ownership or shape rule) or while serving the
            // request (an invalid block tree) — one question to an
            // administrator, so one list.
            'DroppedFrontendFeature' => self::object([
                'plugin' => self::str(),
                'featureId' => self::str(true),
                'reason' => self::str(),
            ], ['plugin', 'featureId', 'reason']),
            // `dropped` is NOT required: it is present only for a caller holding
            // plugins:read, and its absence is what says "not yours to read" as
            // distinct from an empty array's "nothing was refused".
            'FrontendFeatureListResponse' => self::object(
                [
                    'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('FrontendFeature')],
                    'dropped' => [
                        'type' => 'array',
                        'items' => SchemaBuilder::ref('DroppedFrontendFeature'),
                    ],
                ],
                ['data']
            ),

            // WC-176 (#205): the caller's effective permission slugs. Mirrors
            // MeCapabilitiesApiHandler's ACTUAL output: a data envelope wrapping
            // a single `permissions` array of strings (sorted; empty is valid).
            'MeCapabilitiesResponse' => self::dataEnvelope(self::object([
                'permissions' => ['type' => 'array', 'items' => self::str()],
            ], ['permissions'])),

            // #868: batch permitted-action resolution behind the `inbox` block.
            // A check names the CONCRETE request an affordance would make, so the
            // host can answer with the same route lookup + RoleChecker calls
            // RbacMiddleware makes. `scopedPermission` (with resourceType +
            // resourceId) is an ADDITIONAL per-record conjunct — it can only
            // narrow the route gate's answer, never widen it.
            'PermittedActionCheck' => self::object([
                'ref' => self::str(),
                'method' => ['type' => 'string', 'enum' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']],
                'path' => self::str(),
                'resourceType' => self::str(true),
                'resourceId' => self::int(true),
                'scopedPermission' => self::str(true),
            ], ['ref', 'method', 'path']),
            'PermittedActionsRequest' => self::object([
                'checks' => ['type' => 'array', 'items' => SchemaBuilder::ref('PermittedActionCheck')],
            ], ['checks']),
            // `required` mirrors RbacMiddleware's 403 body: the permission slug
            // that refused, or null when a role (or a missing route) refused.
            'PermittedActionResult' => self::object([
                'ref' => self::str(true),
                'allowed' => self::bool(),
                'required' => self::str(true),
            ], ['ref', 'allowed', 'required']),
            'PermittedActionsResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('PermittedActionResult')],
            ], ['data']),

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

            // ---- In-app notification inbox schemas (WC-notifications, 6e10d9ea) ----

            'NotificationEntry' => self::object([
                'id' => self::int(),
                'type' => self::str(),
                'subject' => self::str(),
                'body' => self::str(),
                'data' => ['type' => 'object', 'additionalProperties' => true],
                'read' => self::bool(),
                'read_at' => self::str(true),
                'created_at' => self::str(true),
            ], ['id', 'type', 'subject', 'body', 'data', 'read', 'read_at', 'created_at']),
            'NotificationListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('NotificationEntry')],
                'pagination' => SchemaBuilder::ref('Pagination'),
                'unread_count' => self::int(),
            ], ['data', 'pagination', 'unread_count']),
            'UnreadCountResponse' => self::object([
                'unread_count' => self::int(),
            ], ['unread_count']),
            'MarkAllReadResponse' => self::object([
                'marked' => self::int(),
            ], ['marked']),
            'NotificationPreferenceEntry' => self::object([
                'type' => self::str(),
                'channel' => self::str(),
                'enabled' => self::bool(),
            ], ['type', 'channel', 'enabled']),
            'NotificationPreferencesResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('NotificationPreferenceEntry')],
                'transactional_prefixes' => ['type' => 'array', 'items' => self::str()],
            ], ['data', 'transactional_prefixes']),
            'NotificationPreferencesUpdateRequest' => self::object([
                'preferences' => ['type' => 'array', 'items' => SchemaBuilder::ref('NotificationPreferenceEntry')],
            ], ['preferences']),
            'TenantNotificationSettingsEntry' => self::object([
                'channel' => self::str(),
                'transport' => self::str(true),
                'from_address' => self::str(true),
                'from_name' => self::str(true),
                'reply_to' => self::str(true),
                'config' => ['type' => 'object', 'additionalProperties' => true],
                'has_credentials' => self::bool(),
                'enabled' => self::bool(),
            ], ['channel', 'config', 'has_credentials', 'enabled']),
            'TenantNotificationSettingsListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('TenantNotificationSettingsEntry')],
            ], ['data']),
            'TenantNotificationSettingsResponse' => self::object([
                'data' => SchemaBuilder::ref('TenantNotificationSettingsEntry'),
            ], ['data']),
            'TenantNotificationSettingsUpdateRequest' => self::object([
                'transport' => self::str(true),
                'from_address' => self::str(true),
                'from_name' => self::str(true),
                'reply_to' => self::str(true),
                'config' => ['type' => 'object', 'additionalProperties' => true],
                'enabled' => self::bool(),
            ], []),
            'NotificationCredentialsRequest' => self::object([
                'credentials' => self::str(true),
            ], ['credentials']),
            'NotificationMetrics' => self::object([
                'total' => self::int(),
                'by_status' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                'queue_depth' => self::int(),
                'failure_rate' => ['type' => 'number', 'format' => 'float'],
                'avg_latency_seconds' => ['type' => 'number', 'format' => 'float', 'nullable' => true],
            ], ['total', 'by_status', 'queue_depth', 'failure_rate', 'avg_latency_seconds']),
            'NotificationMetricsResponse' => self::object([
                'data' => SchemaBuilder::ref('NotificationMetrics'),
            ], ['data']),

            // ---- Platform-ops schemas (WC-62133b3f) ----

            // GET /api/health — top-level (not data-enveloped)
            //
            // `version` is the CORE version and keeps that name: it is a
            // published field on a public probe, so renaming it to
            // `core_version` would break somebody's alerting for cosmetics.
            // `sdk_version` is the plugin-SDK contract version, unauthenticated
            // here on purpose — the reasoning is on HealthApiHandler's docblock.
            //
            // `workers_active` was documented here as `worker_count` — a name
            // the handler has never returned. Corrected rather than left beside
            // the new field: a spec that names a key the server does not send is
            // worse than one that omits it, because a generated client compiles.
            'HealthResponse' => self::object([
                'status' => ['type' => 'string', 'enum' => ['ok', 'degraded']],
                'version' => self::str(),
                'sdk_version' => self::str(),
                'workers_active' => self::int(),
                'uptime_seconds' => self::int(),
                'db_connected' => self::bool(),
                'memory_usage_mb' => ['type' => 'number', 'format' => 'float'],
            ], ['status', 'version', 'sdk_version', 'workers_active', 'uptime_seconds', 'db_connected', 'memory_usage_mb']),

            // GET /api/platform/version (WHIT-587)
            'PlatformVersionResponse' => self::object([
                'core_version' => self::str(),
                'sdk_version' => self::str(),
                'php_version' => self::str(),
            ], ['core_version', 'sdk_version', 'php_version']),

            // GET /api/platform/version/latest (WHIT-587). `check_failed` is a
            // 200 verdict, not an HTTP error — "could not tell" must never be
            // read as "up to date".
            'PlatformLatestReleaseResponse' => self::object([
                'status' => ['type' => 'string', 'enum' => ['up_to_date', 'update_available', 'ahead', 'no_releases', 'check_failed']],
                'update_available' => self::bool(),
                'repository' => self::str(),
                'current_version' => self::str(),
                'latest_version' => self::str(true),
                'release_url' => self::str(true),
                'published_at' => self::str(true),
                'failure_reason' => self::str(true),
                'detail' => self::str(true),
            ], ['status', 'update_available', 'repository', 'current_version']),

            // GET /api/instance/status (WC-instance-first-run)
            'InstanceStatusResponse' => self::object([
                'configured' => self::bool(),
                'version' => self::str(),
            ], ['configured', 'version']),

            // POST /api/instance/complete-setup (WC-instance-first-run)
            'InstanceCompleteSetupResponse' => self::object([
                'configured' => self::bool(),
            ], ['configured']),

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

            // GET /api/settings/tabs (WC-tabs-nav-be)
            'SettingsTab' => self::object([
                'id' => self::str(),
                'label' => self::str(),
                'href' => self::str(),
            ], ['id', 'label', 'href']),
            'SettingsTabListResponse' => self::listEnvelope('SettingsTab'),

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
                'profileId' => self::int(true),
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

            // GET /api/profiles/{id}/relations — inline data envelope (personId may be null)
            'ProfileRelationsResponse' => self::dataEnvelope(self::object([
                'personId' => self::int(true),
                'relations' => ['type' => 'array', 'items' => SchemaBuilder::ref('RelationSummary')],
            ], ['personId', 'relations'])),

            // Relation create request and response
            // `kind`, not `type`: RelationsApiHandler reads $ref['kind'] and
            // RelationResolver::KIND_PROFILE/KIND_PERSON are its values. The
            // spec said `type`, so a codegen consumer sent `type` and got a
            // validation error every time — the spec was wrong, not the
            // clients (the web modal has always sent `kind`). Fixed to follow
            // the handler rather than renaming the handler, which would have
            // broken every existing caller to make a document right.
            'RelationRef' => self::object([
                'kind' => ['type' => 'string', 'enum' => ['profile', 'person']],
                'id' => self::int(),
            ], ['kind', 'id']),
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
            // GET /api/theme response body (WC-242): a free-form map of known
            // design-token names to '#rrggbb' hex strings, contributed by at
            // most one installed plugin. Possibly empty.
            'ThemeOverridesResponse' => self::dataEnvelope([
                'type' => 'object',
                'additionalProperties' => ['type' => 'string'],
            ]),

            // ── Forgotten-password + 2FA-recovery (WC-password-reset-2fa-recovery) ──

            // Shared by POST /api/auth/password/forgot and POST
            // /api/auth/2fa-recovery/request — both take only an email.
            'EmailOnlyRequest' => self::object(['email' => self::str()], ['email']),
            // Shared by POST /api/auth/2fa-recovery/confirm.
            'TokenOnlyRequest' => self::object(['token' => self::str()], ['token']),
            // Shared generic 202 body for both public "request" endpoints above —
            // deliberately identical whether or not the address has an account.
            'GenericMessageDataResponse' => self::dataEnvelope(
                self::object(['message' => self::str()], ['message'])
            ),

            // POST /api/auth/password/reset — request body.
            'PasswordResetConfirmRequest' => self::object([
                'token' => self::str(),
                'password' => self::str(),
            ], ['token', 'password']),
            // POST /api/auth/password/reset — 200 response. `status` is
            // 'applied' (self-service, no approval required) or
            // 'awaiting_approval' (staged; an admin must approve it).
            'PasswordResetConfirmResponse' => self::dataEnvelope(self::object([
                'status' => ['type' => 'string', 'enum' => ['applied', 'awaiting_approval']],
                'message' => self::str(),
            ], ['status', 'message'])),

            // POST /api/auth/2fa-recovery/confirm — 200 response. Confirming
            // only SUBMITS the request into the admin queue — status is always
            // 'pending' here; nothing on the target profile has changed yet.
            'TwoFactorRecoveryConfirmResponse' => self::dataEnvelope(self::object([
                'status' => ['type' => 'string', 'enum' => ['pending']],
                'message' => self::str(),
            ], ['status', 'message'])),

            // One row of either admin approval queue (password-resets/pending,
            // 2fa-recovery/pending) — same shape, listed under distinct names so
            // each endpoint's response schema is self-describing.
            'PendingPasswordResetItem' => self::object([
                'id' => self::int(),
                'profile_id' => self::int(),
                'email' => self::str(),
                'display_name' => self::str(),
                'created_at' => self::str(),
            ], ['id', 'profile_id', 'email', 'display_name', 'created_at']),
            'PendingPasswordResetListResponse' => self::listEnvelope('PendingPasswordResetItem'),

            // ── Tenant invitations (WHIT-417) ──

            // POST /api/invitations — request body. `role` accepts a name and
            // `role_id` an id; both resolve against the tenant's own roles plus
            // the platform-global ones, and absent means the global `user` role.
            'InvitationCreateRequest' => self::object([
                'email' => self::str(),
                'role' => self::str(),
                'role_id' => self::int(),
                'ou_id' => self::int(),
            ], ['email']),
            // One invitation as an administrator sees it. Deliberately carries
            // NO profile id and no account-existence flag: a tenant admin may
            // type any address here, so echoing back whether it already has an
            // account would make this an enumeration oracle over the platform.
            // `status` adds 'expired', which is DERIVED from expires_at rather
            // than stored, so a client never has to compute it.
            'InvitationItem' => self::object([
                'id' => self::int(),
                'email' => self::str(),
                'role_id' => self::int(),
                'role_name' => self::str(),
                'ou_id' => self::int(true),
                'status' => [
                    'type' => 'string',
                    'enum' => ['pending', 'accepted', 'revoked', 'superseded', 'expired'],
                ],
                'expires_at' => self::str(),
                'created_at' => self::str(),
                'invited_by' => self::int(true),
            ], ['id', 'email', 'role_id', 'role_name', 'status', 'expires_at', 'created_at']),
            'InvitationListResponse' => self::listEnvelope('InvitationItem'),
            'InvitationItemResponse' => self::dataEnvelope(SchemaBuilder::ref('InvitationItem')),
            'InvitationRevokedResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'status' => ['type' => 'string', 'enum' => ['revoked']],
            ], ['id', 'status'])),

            // GET /api/invitations/accept — what the token holder is shown.
            // `requires_password` is the one place the platform reveals whether
            // an address has an account, and only to somebody holding a valid
            // single-use token mailed to that address.
            'InvitationPreviewResponse' => self::dataEnvelope(self::object([
                'email' => self::str(),
                'tenant_name' => self::str(),
                'requires_password' => self::bool(),
            ], ['email', 'tenant_name', 'requires_password'])),
            // POST /api/invitations/accept — `password` is required ONLY when
            // the preview said so; supplied for an address that already has an
            // account it is ignored, never applied.
            'InvitationAcceptRequest' => self::object([
                'token' => self::str(),
                'password' => self::str(),
            ], ['token']),
            // 'joined' = a membership was created (or an invited one activated);
            // 'already_member' = they were in this tenant already, so nothing
            // was added and the token was burned.
            'InvitationAcceptResponse' => self::dataEnvelope(self::object([
                'status' => ['type' => 'string', 'enum' => ['joined', 'already_member']],
                'message' => self::str(),
            ], ['status', 'message'])),
            'PendingTwoFactorRecoveryItem' => self::object([
                'id' => self::int(),
                'profile_id' => self::int(),
                'email' => self::str(),
                'display_name' => self::str(),
                'created_at' => self::str(),
            ], ['id', 'profile_id', 'email', 'display_name', 'created_at']),
            'PendingTwoFactorRecoveryListResponse' => self::listEnvelope('PendingTwoFactorRecoveryItem'),

            // Shared 200 response for every id-based approve/reject action across
            // both queues (password-resets and 2fa-recovery).
            'ApprovalStatusResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'status' => ['type' => 'string', 'enum' => ['approved', 'rejected']],
            ], ['id', 'status'])),

            // POST /api/users/{id}/password-reset — the admin-triggered LINK.
            // Deliberately carries no token and no password: the raw token
            // exists only inside the mail sent to the user.
            'AdminPasswordResetSentResponse' => self::dataEnvelope(self::object([
                'status' => ['type' => 'string', 'enum' => ['sent']],
                'profile_id' => self::int(),
            ], ['status', 'profile_id'])),

            // GET /api/password-resets/approver-coverage — drives the "this
            // change can strand the tenant" warning on the approval-gate toggle
            // and on user removal/demotion.
            'PasswordResetApproverCoverageResponse' => self::dataEnvelope(self::object([
                'tenant_id' => self::int(),
                'minimum_recommended' => self::int(),
                'approval_required' => self::bool(),
                'approver_count' => self::int(),
                'approver_profile_ids' => ['type' => 'array', 'items' => self::int()],
                'approver_role_names' => ['type' => 'array', 'items' => self::str()],
                'below_minimum' => self::bool(),
            ], ['tenant_id', 'minimum_recommended', 'approval_required', 'approver_count', 'approver_profile_ids', 'approver_role_names', 'below_minimum'])),

            // POST /api/2fa-recovery/force-reset — the secondary admin-direct
            // fallback (no prior request): request body + 200 response.
            'ForceResetRequest' => self::object(['profile_id' => self::int()], ['profile_id']),
            'ForceResetResponse' => self::dataEnvelope(self::object([
                'profile_id' => self::int(),
                'status' => ['type' => 'string', 'enum' => ['forced']],
            ], ['profile_id', 'status'])),

            // ── Plugin-declared data types (WC-723, Door 2) ───────────────────
            // `actions` is the honest-degradation contract: it lists ONLY the
            // actions this type offers AND this caller may use, so a generated
            // screen that renders exactly what it is told can never present a
            // control the endpoint would refuse.
            'DataTypeLifecycle' => self::object([
                'declared' => self::bool(),
                'column' => self::str(true),
                'states' => ['type' => 'array', 'items' => self::str()],
                'default_state' => self::str(true),
                'trashable' => self::bool(),
                'retirable' => self::bool(),
                'trashed_state' => self::str(true),
                'retired_state' => self::str(true),
            ], ['declared', 'trashable', 'retirable']),
            // One declared edge of the reference graph. `label` is what a refusal
            // message says; core never learns what the table means.
            //
            // `ignore_when` is echoed back so the entry ROUND-TRIPS the
            // declaration. No renderer needs it — but a filter that is enforced
            // and never published is indistinguishable from one that was
            // silently dropped, and the only way to tell them apart is to read
            // core's source. Publishing it costs a renderer nothing.
            'DataTypeReference' => self::object([
                'table' => self::str(),
                'column' => self::str(),
                'label' => self::str(),
                'ignore_when' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => self::str()],
                ],
            ], ['table', 'column', 'label', 'ignore_when']),
            // One declared edge of the COMPOSITION graph: rows that are PART of
            // the record and are deleted WITH it. The exact opposite of a
            // DataTypeReference, published beside it because the pair is only
            // readable together — nothing about a table's shape says which of
            // the two a plugin meant, and with no foreign keys between plugin
            // tables the database will not choose either.
            //
            // No `ignore_when` here, and its absence is the contract: a cascade
            // that skipped some of the rows it owns would leave exactly the
            // orphans it exists to remove, so a declaration carrying one is
            // refused rather than honoured half-way.
            'DataTypeComposition' => self::object([
                'table' => self::str(),
                'column' => self::str(),
                'label' => self::str(),
            ], ['table', 'column', 'label']),
            'DataType' => self::object([
                'key' => self::str(),
                'source' => self::str(),
                'label' => ['type' => 'object', 'x-whity-localized-text' => true, 'properties' => ['ar' => self::str(), 'en' => self::str()]],
                'lifecycle' => SchemaBuilder::ref('DataTypeLifecycle'),
                'blocks_delete' => ['type' => 'array', 'items' => SchemaBuilder::ref('DataTypeReference')],
                'cascade_delete' => ['type' => 'array', 'items' => SchemaBuilder::ref('DataTypeComposition')],
                'actions' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['read', 'trash', 'restore', 'retire', 'delete']]],
                'permissions' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
            ], ['key', 'source', 'label', 'lifecycle', 'blocks_delete', 'cascade_delete', 'actions']),
            'DataTypeListResponse' => self::listEnvelope('DataType'),
            // A counted set of rows with the plugin's own label for them. Used
            // for both questions a delete raises: what is in the way
            // (`blockers`) and what would go with it (`cascade`). One shape, so
            // a renderer needs no second code path to say "3 catalogue notes".
            'DataTypeBlocker' => self::object([
                'table' => self::str(),
                'label' => self::str(),
                'count' => self::int(),
            ], ['table', 'label', 'count']),
            // Why one action is unavailable on one record. `reason` is a STABLE
            // key a client branches on and localises itself; `message` is core's
            // own sentence, offered as a fallback and never as the contract —
            // string-matching prose is not an API.
            //
            // Four causes, one vocabulary: a reference, the record's state, the
            // type not offering the action, or the record's COMPOSITION —
            // something points at a row this record owns
            // (`composition_still_referenced`), one of those rows is retired
            // (`composition_is_permanent`), or an owned table owns rows of its
            // own (`cascade_would_nest`). The `*_not_offered` keys are the SAME
            // ones the mutation endpoint's 405 body carries, so the preview
            // predicts the endpoint's answer down to the reason.
            //
            // `still_referenced` and `composition_still_referenced` are separate
            // keys deliberately: one says "detach what points at this record",
            // the other "something points at one of its parts", and they send
            // the reader to different places.
            'DataTypeRefusal' => self::object([
                'reason' => ['type' => 'string', 'enum' => [
                    'still_referenced',
                    'composition_still_referenced',
                    'composition_is_permanent',
                    'cascade_would_nest',
                    'trash_before_deleting',
                    'retired_records_are_permanent',
                    'retired_records_cannot_be_trashed',
                    'retirement_is_permanent',
                    'restore_before_retiring',
                    'nothing_to_restore',
                    'trash_not_offered',
                    'restore_not_offered',
                    'retire_not_offered',
                    'delete_not_offered',
                ]],
                'message' => self::str(),
            ], ['reason', 'message']),
            // Why each unavailable action is unavailable, keyed by action and
            // present ONLY for actions that are unavailable right now. Distinct
            // from `blockers`, which answers only "how many rows point at this" —
            // a refusal is not a reference, and merging them would make the row
            // count unanswerable.
            'DataTypeRefusals' => self::object([
                'trash' => SchemaBuilder::ref('DataTypeRefusal'),
                'restore' => SchemaBuilder::ref('DataTypeRefusal'),
                'retire' => SchemaBuilder::ref('DataTypeRefusal'),
                'delete' => SchemaBuilder::ref('DataTypeRefusal'),
            ], []),
            // One record's lifecycle position, in two kinds of field.
            //
            // ACTIONS — `restorable` and `deletable` — are each EXACTLY
            // `!refusals[action]`. A `false` on either always carries its
            // refusal, whatever the cause: a reference (`still_referenced`,
            // `blockers` populated), the state (`trash_before_deleting`,
            // `blockers` empty), or the type not offering the action
            // (`delete_not_offered`, the same key its 405 carries).
            //
            // PROPERTIES — `referenceable` and `pending_removal` — are read off
            // `state` and carry no refusal: there is no control to disable and
            // nothing to refuse, and `state` sits right beside them.
            // `referenceable` is false for BOTH trashed and retired;
            // `pending_removal` is what separates the two.
            //
            // `cascade` is a THIRD question and stays apart from both: not what
            // stops this delete, and not which action is unavailable, but what
            // ELSE this delete would remove. A record with composition is still
            // deletable — this is what lets a confirmation dialog say "and 4
            // line items" instead of destroying them silently. Empty means
            // nothing else goes; zero-count edges are omitted, as in `blockers`.
            'DataTypeRecordState' => self::dataEnvelope(self::object([
                'key' => self::str(),
                'state' => self::str(true),
                'referenceable' => self::bool(),
                'pending_removal' => self::bool(),
                'restorable' => self::bool(),
                'deletable' => self::bool(),
                'blockers' => ['type' => 'array', 'items' => SchemaBuilder::ref('DataTypeBlocker')],
                'cascade' => ['type' => 'array', 'items' => SchemaBuilder::ref('DataTypeBlocker')],
                'refusals' => SchemaBuilder::ref('DataTypeRefusals'),
            ], [
                'key',
                'state',
                'referenceable',
                'pending_removal',
                'restorable',
                'deletable',
                'blockers',
                'cascade',
                'refusals',
            ])),
            'DataTypeTransitionResponse' => self::dataEnvelope(self::object([
                'key' => self::str(),
                'outcome' => ['type' => 'string', 'enum' => ['ok']],
                'state' => self::str(true),
                'reason' => self::str(true),
                'message' => self::str(),
                'blockers' => ['type' => 'array', 'items' => SchemaBuilder::ref('DataTypeBlocker')],
            ], ['key', 'outcome', 'state', 'message', 'blockers'])),
            // One action over many records. The action is a BODY field rather
            // than a path segment so the batch path stays unambiguous against
            // the single-record routes — see the route registration in
            // public/index.php for why that matters more than symmetry here.
            'DataTypeBulkRequest' => self::object([
                'action' => ['type' => 'string', 'enum' => ['trash', 'restore', 'retire', 'delete']],
                'ids' => [
                    'type' => 'array',
                    'items' => ['oneOf' => [['type' => 'string'], ['type' => 'integer']]],
                    'description' => 'Record keys. Duplicates are collapsed; the ceiling is the '
                        . '`data_types.bulk_max_ids` setting and exceeding it is refused, never truncated.',
                ],
            ], ['action', 'ids']),
            // ONE record's line in a batch report. `outcome`, `state`, `reason`,
            // `message` and `blockers` are the SAME fields, carrying the SAME
            // stable vocabulary, that the single-record call answers with —
            // there is no second refusal vocabulary for bulk. `status` is the
            // status that single-record call would have returned, published so a
            // client already rendering (status, reason) pairs reuses that code
            // unchanged.
            'DataTypeBulkResult' => self::object([
                'id' => self::str(),
                'status' => self::int(),
                'outcome' => ['type' => 'string', 'enum' => [
                    'ok',
                    'not_found',
                    'blocked',
                    'refused',
                    'unsupported',
                    'forbidden',
                ]],
                'state' => self::str(true),
                'reason' => self::str(true),
                'message' => self::str(),
                'blockers' => ['type' => 'array', 'items' => SchemaBuilder::ref('DataTypeBlocker')],
                'required' => self::str(),
            ], ['id', 'status', 'outcome', 'state', 'reason', 'message', 'blockers']),
            // Pre-counted so "43 done, 7 refused" needs no walk over `results`.
            // `refused` counts every entry whose `outcome` is not `ok` — a state
            // refusal, a reference, a veto and a missing record alike — and is
            // exactly `unique - ok`. `requested` is what the caller sent and
            // `unique` what survived de-duplication, so a batch producing fewer
            // results than ids says why on its face.
            'DataTypeBulkCounts' => self::object([
                'requested' => self::int(),
                'unique' => self::int(),
                'ok' => self::int(),
                'refused' => self::int(),
            ], ['requested', 'unique', 'ok', 'refused']),
            'DataTypeBulkResponse' => self::dataEnvelope(self::object([
                'key' => self::str(),
                'action' => ['type' => 'string', 'enum' => ['trash', 'restore', 'retire', 'delete']],
                'counts' => SchemaBuilder::ref('DataTypeBulkCounts'),
                'results' => ['type' => 'array', 'items' => SchemaBuilder::ref('DataTypeBulkResult')],
            ], ['key', 'action', 'counts', 'results'])),
        ];
    }

    /**
     * The generated lifecycle surface for plugin-declared data types
     * (WC-723, Door 2).
     *
     * One route set serves EVERY registered type, so these are declared once
     * here rather than per plugin. They carry NO route-level requiredPermission
     * because the permission varies per type: the handler resolves the type's
     * own declared permission through the same RoleChecker the middleware uses,
     * and fails closed when the type does not offer the action (405) or the
     * caller lacks the declared permission (403).
     *
     * A blocked delete is a 409 carrying the plugin's own labels — "still
     * referenced by 3 recorded entries" — which is the difference between a
     * refusal a user can act on and one they cannot.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function dataTypeRoutes(): array
    {
        $typeParam = [
            'name' => 'type',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
            'description' => 'The namespaced data-type key, e.g. `democatalog:item`',
        ];
        $idParam = [
            'name' => 'id',
            'in' => 'path',
            'required' => true,
            'schema' => ['type' => 'string'],
            'description' => 'The record\'s primary-key value',
        ];

        $recordErrors = [
            404 => self::errorResponse('Unknown data type, or no such record in the caller\'s tenant'),
            405 => self::errorResponse('This data type does not offer that action'),
        ] + self::authErrors();

        $transition = static function (string $path, string $summary, string $conflict) use (
            $typeParam,
            $idParam,
            $recordErrors
        ): array {
            return [
                'method' => 'POST',
                'path' => $path,
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => $summary,
                    'tags' => ['data-types'],
                    'parameters' => [$typeParam, $idParam],
                    'responses' => [
                        200 => self::jsonResponse('The record\'s new lifecycle state', 'DataTypeTransitionResponse'),
                        409 => self::errorResponse($conflict),
                    ] + $recordErrors,
                ],
            ];
        };

        return [
            [
                'method' => 'GET',
                'path' => '/api/data-types',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the plugin-declared data types the caller may read, with the lifecycle actions they may use',
                    'tags' => ['data-types'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'Declared types, filtered per caller (empty data is valid)',
                            'DataTypeListResponse'
                        ),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/data-types/{type}/{id}',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Read one record\'s lifecycle state, the declared references that block deleting it, '
                        . 'and the stable reason key behind every action its current state refuses',
                    'tags' => ['data-types'],
                    'parameters' => [$typeParam, $idParam],
                    'responses' => [
                        200 => self::jsonResponse('The record\'s lifecycle position', 'DataTypeRecordState'),
                    ] + $recordErrors,
                ],
            ],
            $transition(
                '/api/data-types/{type}/{id}/trash',
                'Trash a record — reversible, closed to new references, removable once nothing references it',
                'A retired record cannot be trashed'
            ),
            $transition(
                '/api/data-types/{type}/{id}/restore',
                'Restore a trashed record to the type\'s default state',
                'A retired record cannot be restored — retirement is permanent'
            ),
            $transition(
                '/api/data-types/{type}/{id}/retire',
                'Retire a record — closed to new references, permanently readable, never deletable',
                'A trashed record must be restored before it can be retired'
            ),
            [
                'method' => 'DELETE',
                'path' => '/api/data-types/{type}/{id}',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Delete a record for real, if every declared referential guard permits it',
                    'tags' => ['data-types'],
                    'parameters' => [$typeParam, $idParam],
                    'responses' => [
                        200 => self::jsonResponse('The record was removed', 'DataTypeTransitionResponse'),
                        409 => self::errorResponse(
                            'Refused: rows still reference this record, the record is retired '
                            . '(permanent), or a trashable type\'s record has not been trashed first'
                        ),
                    ] + $recordErrors,
                ],
            ],
            // The batch surface. 200 is the answer whenever the batch RAN — a
            // record refusing is reported per record, not as an envelope status,
            // and an all-refused batch is still a 200 because the operation
            // (attempt these and report) succeeded. There is no 409 here for the
            // same reason: a mixed batch has no single conflict to report.
            [
                'method' => 'POST',
                'path' => '/api/data-types/{type}/bulk',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Perform one lifecycle action over many records, skipping and reporting '
                        . 'refusals rather than aborting the batch',
                    'tags' => ['data-types'],
                    'parameters' => [$typeParam],
                    'request' => 'DataTypeBulkRequest',
                    'responses' => [
                        200 => self::jsonResponse(
                            'Per-record outcomes. The batch ran; individual records may have refused, '
                            . 'including all of them',
                            'DataTypeBulkResponse'
                        ),
                        400 => self::errorResponse(
                            'Unknown `action`, or `ids` is not a non-empty array of record ids'
                        ),
                        422 => self::errorResponse(
                            'More ids than the `data_types.bulk_max_ids` ceiling allows for this tenant'
                        ),
                    ] + $recordErrors,
                ],
            ],
        ];
    }

    /**
     * SSO / federated-identity route declarations (WC-e6287, WC-f3b17bd2):
     *   - per-tenant identity-provider admin CRUD (gated `auth_providers:manage`),
     *   - the public enabled-providers list for the login screen,
     *   - the authenticated connected-accounts (linked identities) surface.
     *
     * The OIDC redirect flow itself — `GET /api/auth/sso/{provider}/start` and
     * `/callback` — is a 302 browser redirect, not a JSON API, and is deliberately
     * left undocumented (see RouteCatalogueCompletenessTest::KNOWN_UNDOCUMENTED).
     *
     * The client secret is WRITE-ONLY end-to-end: accepted as plaintext on
     * create/update, encrypted at rest, and NEVER returned — reads expose only
     * `has_secret` (see ADR 0009 and IdentityProviderRepository).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function identityRoutes(): array
    {
        // Public: the login screen's enabled-provider buttons (empty when the
        // SSO kill-switch is off). No auth gate.
        $ssoProviders = [
            'method' => 'GET',
            'path' => '/api/auth/sso/providers',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'List enabled SSO providers for the login screen',
                'tags' => ['sso'],
                'responses' => [
                    200 => self::jsonResponse('Enabled providers (empty when SSO is disabled)', 'SsoProviderListResponse'),
                ],
            ],
        ];

        // Authenticated self-service: the caller's linked external identities.
        // Cookie-authenticated by the handler — no requiredRole/requiredPermission.
        $meIdentitiesList = [
            'method' => 'GET',
            'path' => '/api/me/identities',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'List the caller\'s linked SSO identities',
                'tags' => ['sso'],
                'responses' => [
                    200 => self::jsonResponse('The caller\'s connected external identities', 'MeIdentityListResponse'),
                    401 => self::errorResponse('Authentication required'),
                ],
            ],
        ];

        $meIdentitiesUnlink = [
            'method' => 'DELETE',
            'path' => '/api/me/identities/{id:\d+}',
            'requiredRole' => null,
            'requiredPermission' => null,
            'schema' => [
                'summary' => 'Unlink one of the caller\'s SSO identities',
                'tags' => ['sso'],
                'responses' => [
                    204 => ['description' => 'Identity unlinked'],
                    400 => self::errorResponse('Invalid id'),
                    401 => self::errorResponse('Authentication required'),
                    404 => self::errorResponse('Identity not found'),
                    409 => self::errorResponse('Cannot remove the only sign-in method of a passwordless account'),
                ],
            ],
        ];

        return [
            self::permissionRoute('GET', '/api/identity-providers', 'auth_providers:manage', [
                'summary' => 'List the tenant\'s configured identity providers',
                'tags' => ['sso'],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s identity providers (client secret never returned)', 'IdentityProviderListResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/identity-providers', 'auth_providers:manage', [
                'summary' => 'Configure a new identity provider for the tenant',
                'tags' => ['sso'],
                'request' => 'IdentityProviderCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created identity provider', 'IdentityProviderResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                    409 => self::errorResponse('This provider is already configured for the tenant'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/identity-providers/{id:\d+}', 'auth_providers:manage', [
                'summary' => 'Update a tenant identity provider',
                'tags' => ['sso'],
                'request' => 'IdentityProviderUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated identity provider', 'IdentityProviderResponse'),
                    400 => self::errorResponse('Invalid id or missing tenant context'),
                    404 => self::errorResponse('Identity provider not found'),
                    409 => self::errorResponse('This provider is already configured for the tenant'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/identity-providers/{id:\d+}', 'auth_providers:manage', [
                'summary' => 'Delete a tenant identity provider',
                'tags' => ['sso'],
                'responses' => [
                    204 => ['description' => 'Identity provider deleted'],
                    400 => self::errorResponse('Invalid id or missing tenant context'),
                    404 => self::errorResponse('Identity provider not found'),
                ] + self::authErrors(),
            ]),
            $ssoProviders,
            $meIdentitiesList,
            $meIdentitiesUnlink,
        ];
    }

    /**
     * Authenticated self-service multi-email management (WC-54fb5c37): the
     * caller lists, adds, resends verification for, promotes, and removes
     * their own email addresses. Cookie-authenticated by the handler — no
     * requiredRole/requiredPermission, same pattern as identityRoutes()'s
     * /api/me/identities surface.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function meEmailsRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/me/emails',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the caller\'s email addresses',
                    'tags' => ['me'],
                    'responses' => [
                        200 => self::jsonResponse('The caller\'s email addresses', 'MeEmailListResponse'),
                        401 => self::errorResponse('Authentication required'),
                    ],
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/me/emails',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Add a new (unverified) email address for the caller',
                    'tags' => ['me'],
                    'request' => 'MeEmailAddRequest',
                    'responses' => [
                        201 => self::jsonResponse('The newly added address', 'MeEmailResponse'),
                        401 => self::errorResponse('Authentication required'),
                        409 => self::errorResponse('This email address is already registered'),
                        422 => self::errorResponse('Invalid address, or the maximum number of addresses was reached'),
                    ],
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/me/emails/{id:\d+}/resend-verification',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Resend the verification link for one of the caller\'s addresses',
                    'tags' => ['me'],
                    'responses' => [
                        202 => ['description' => 'Verification email sent'],
                        400 => self::errorResponse('This email address is already verified'),
                        401 => self::errorResponse('Authentication required'),
                        404 => self::errorResponse('Email address not found'),
                        429 => self::errorResponse('Too many resend requests'),
                    ],
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/me/emails/{id:\d+}/set-primary',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Promote one of the caller\'s verified addresses to primary',
                    'tags' => ['me'],
                    'responses' => [
                        200 => self::jsonResponse('The now-primary address', 'MeEmailResponse'),
                        400 => self::errorResponse('The address must be verified before it can be made primary'),
                        401 => self::errorResponse('Authentication required'),
                        404 => self::errorResponse('Email address not found'),
                    ],
                ],
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/me/emails/{id:\d+}',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Remove one of the caller\'s email addresses',
                    'tags' => ['me'],
                    'responses' => [
                        204 => ['description' => 'Email address removed'],
                        401 => self::errorResponse('Authentication required'),
                        404 => self::errorResponse('Email address not found'),
                        409 => self::errorResponse('Cannot remove the only or the primary email address'),
                    ],
                ],
            ],
        ];
    }

    /**
     * Tenant email-domain policy admin routes (WC-9b87 / WC-628738f5): manage
     * which email domains auto-provision/auto-accept memberships into this
     * tenant. Gated on the `admin` ROLE (requiredRole, not a permission — see
     * the router.register calls in public/index.php) and tenant-scoped via
     * TenantContext, so a tenant can only manage its own domain registrations.
     *
     * A registered domain never auto-provisions until the tenant proves
     * ownership via the DNS TXT challenge (POST .../verify) — this closes the
     * cross-tenant domain-harvesting hole.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function tenantEmailDomainRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/email-domains',
                'requiredRole' => 'admin',
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'List the tenant\'s registered email-domain policies',
                    'tags' => ['email-domains'],
                    'responses' => [
                        200 => self::jsonResponse('The tenant\'s domain registrations', 'TenantEmailDomainListResponse'),
                        400 => self::errorResponse('Tenant context is required'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/email-domains',
                'requiredRole' => 'admin',
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Register a new email domain for the tenant',
                    'tags' => ['email-domains'],
                    'request' => 'TenantEmailDomainCreateRequest',
                    'responses' => [
                        201 => self::jsonResponse('The newly registered (unverified) domain', 'TenantEmailDomainResponse'),
                        400 => self::errorResponse('Tenant context is required'),
                        409 => self::errorResponse('This domain is already registered for your tenant'),
                        422 => self::errorResponse('Invalid domain or missing default_role_id'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/email-domains/{id:\d+}/verify',
                'requiredRole' => 'admin',
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Check the DNS TXT challenge and mark the domain verified',
                    'tags' => ['email-domains'],
                    'responses' => [
                        200 => self::jsonResponse('The domain (verified, or still-pending with challenge instructions)', 'TenantEmailDomainResponse'),
                        400 => self::errorResponse('Tenant context is required or invalid id'),
                        404 => self::errorResponse('Domain registration not found'),
                        422 => self::jsonResponse('Ownership not yet verified — publish the returned TXT record and retry', 'TenantEmailDomainVerifyPendingResponse'),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/email-domains/{id:\d+}',
                'requiredRole' => 'admin',
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Remove a tenant email-domain registration',
                    'tags' => ['email-domains'],
                    'responses' => [
                        204 => ['description' => 'Domain registration deleted'],
                        400 => self::errorResponse('Tenant context is required or invalid id'),
                        404 => self::errorResponse('Domain registration not found'),
                    ] + self::authErrors(),
                ],
            ],
        ];
    }

    /**
     * Operator per-tenant entitlements admin routes (WC-ent). The platform owner
     * grants/limits a TARGET tenant's capabilities per subscription tier. Gated
     * on `entitlements:manage` AND (in the handler) the system tenant; the target
     * tenant is the `{id}` path parameter, never the body.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function tenantEntitlementRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/tenants/{id:\d+}/entitlements', 'entitlements:manage', [
                'summary' => 'Get a tenant\'s effective entitlements (operator)',
                'tags' => ['entitlements'],
                'responses' => [
                    200 => self::jsonResponse('The target tenant\'s effective entitlements, overrides, and the catalogue', 'TenantEntitlementsResponse'),
                    404 => self::errorResponse('Tenant not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/tenants/{id:\d+}/entitlements', 'entitlements:manage', [
                'summary' => 'Set a tenant\'s entitlement overrides (operator)',
                'tags' => ['entitlements'],
                'request' => 'TenantEntitlementsPatchRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated effective entitlements', 'TenantEntitlementsMutationResponse'),
                    400 => self::errorResponse('Body must include a non-empty "entitlements" object'),
                    404 => self::errorResponse('Tenant not found'),
                    409 => self::errorResponse('The system tenant is implicitly unlimited and has no overrides'),
                    422 => self::errorResponse('Validation failed (unknown key or invalid value)'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Per-tenant storage backend self-service routes (WC-storage). A tenant admin
     * configures its OWN object-storage backend; tenant-scoped, gated on
     * `storage:manage`, and a write additionally requires the
     * storage.custom_backend entitlement (403 otherwise). The secret is write-only.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function tenantStorageRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/storage-config', 'storage:manage', [
                'summary' => 'Get this tenant\'s storage backend configuration',
                'tags' => ['storage'],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s storage config (secret never returned) + entitlement', 'StorageConfigResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/storage-config', 'storage:manage', [
                'summary' => 'Set or replace this tenant\'s storage backend',
                'tags' => ['storage'],
                'request' => 'StorageConfigPutRequest',
                'responses' => [
                    200 => self::jsonResponse('The saved storage config (secret never returned)', 'StorageConfigDataResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                    403 => self::errorResponse('A custom storage backend is not included in your plan'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/storage-config', 'storage:manage', [
                'summary' => 'Remove this tenant\'s storage backend (revert to platform default)',
                'tags' => ['storage'],
                'responses' => [
                    204 => ['description' => 'Storage configuration removed'],
                    400 => self::errorResponse('Tenant context is required'),
                    404 => self::errorResponse('No storage configuration to remove'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Admin-enforced 2FA policy CRUD + status (WC-525 PR-3). Tenant-scoped via
     * TenantContext and gated on `security:manage`.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function twoFactorPolicyRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/2fa-policies', 'security:manage', [
                'summary' => 'List this tenant\'s admin-enforced 2FA policies',
                'tags' => ['security'],
                'responses' => [
                    200 => self::jsonResponse('Every policy row for this tenant', 'TwoFactorPolicyListResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/2fa-policies', 'security:manage', [
                'summary' => 'Create a tenant/OU/user-scoped 2FA policy',
                'tags' => ['security'],
                'request' => 'TwoFactorPolicyCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created policy', 'TwoFactorPolicyDataResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                    409 => self::errorResponse('A policy already exists for this scope'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/2fa-policies/status', 'security:manage', [
                'summary' => 'Enrollment status across every profile any policy covers',
                'tags' => ['security'],
                'responses' => [
                    200 => self::jsonResponse('Per-profile enrollment status + deadline', 'TwoFactorPolicyStatusResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/2fa-policies/{id:\d+}', 'security:manage', [
                'summary' => 'Change a policy\'s grace period',
                'tags' => ['security'],
                'request' => 'TwoFactorPolicyUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated policy', 'TwoFactorPolicyDataResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                    404 => self::errorResponse('Policy not found'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/2fa-policies/{id:\d+}', 'security:manage', [
                'summary' => 'Remove a 2FA policy',
                'tags' => ['security'],
                'responses' => [
                    204 => ['description' => 'Policy removed'],
                    400 => self::errorResponse('Tenant context is required'),
                    404 => self::errorResponse('Policy not found'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Self-service "forgot password" routes (WC-password-reset-2fa-recovery):
     * two PUBLIC endpoints (forgot/reset — PasswordResetHandler) plus the
     * tenant-scoped admin approval queue (PasswordResetApprovalsApiHandler).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function passwordResetRoutes(): array
    {
        return [
            [
                'method' => 'POST',
                'path' => '/api/auth/password/forgot',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Request a password-reset link (public, rate-limited, no enumeration)',
                    'tags' => ['auth'],
                    'request' => 'EmailOnlyRequest',
                    'responses' => [
                        202 => self::jsonResponse(
                            'Always the same generic message, regardless of whether the address has an account',
                            'GenericMessageDataResponse'
                        ),
                        422 => self::errorResponse('A valid email address is required'),
                        429 => self::errorResponse('Too many password-reset requests'),
                    ],
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/auth/password/reset',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Confirm a reset token and set a new password',
                    'tags' => ['auth'],
                    'request' => 'PasswordResetConfirmRequest',
                    'responses' => [
                        200 => self::jsonResponse(
                            'Reset applied immediately, or submitted for admin approval — see `data.status`',
                            'PasswordResetConfirmResponse'
                        ),
                        400 => self::errorResponse('The reset link is invalid or has expired'),
                        422 => self::errorResponse('Missing token, or password does not meet the policy'),
                    ],
                ],
            ],
            self::permissionRoute('GET', '/api/password-resets/pending', 'password_resets:approve', [
                'summary' => 'List pending password-reset requests for the caller\'s own tenant',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('Requests awaiting approval', 'PendingPasswordResetListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/password-resets/{id:\d+}/approve', 'password_resets:approve', [
                'summary' => 'Apply the staged password (tenant-scoped)',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('Approved', 'ApprovalStatusResponse'),
                    404 => self::errorResponse('No pending password-reset request found for that id'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/password-resets/{id:\d+}/reject', 'password_resets:approve', [
                'summary' => 'Discard the staged password (tenant-scoped)',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('Rejected', 'ApprovalStatusResponse'),
                    404 => self::errorResponse('No pending password-reset request found for that id'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/users/{id:\d+}/password-reset', 'users:write', [
                'summary' => 'Send this user a password-reset link (never returns a credential)',
                'tags' => ['users'],
                'responses' => [
                    202 => self::jsonResponse('A reset link has been mailed to the user', 'AdminPasswordResetSentResponse'),
                    404 => self::errorResponse('User not found in this tenant'),
                    // #917: a reset link for an IdP-backed account would create a
                    // local credential rather than restore access to an existing one.
                    409 => self::errorResponse(
                        'Password-reset emails are disabled for this instance, or the account signs in '
                        . 'through an identity provider and has no local password to reset'
                    ),
                    422 => self::errorResponse('Invalid user id, or the user has no email address'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/password-resets/approver-coverage', 'users:read', [
                'summary' => 'How many accounts in this tenant can approve a parked password reset',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('Approver coverage for the calling tenant', 'PasswordResetApproverCoverageResponse'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Tenant invitation routes (WHIT-417): the tenant administrator's surface,
     * gated on the same users:read/users:write that adding a user by hand
     * needs, plus the PUBLIC accept pair the emailed link lands on.
     *
     * The accept pair answers ONE generic 404/400 for unknown, expired,
     * revoked, superseded and already-used tokens — documented that way here so
     * a client never grows a branch on a distinction the server does not make.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function invitationRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/invitations/accept',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Describe an invitation token without consuming it (public, rate-limited)',
                    'tags' => ['invitations'],
                    'parameters' => [self::queryParam('token', 'string', 'The token from the invitation link')],
                    'responses' => [
                        200 => self::jsonResponse(
                            'The invitation. `requires_password` is false when the address already has an account',
                            'InvitationPreviewResponse'
                        ),
                        404 => self::errorResponse('The invitation link is invalid, expired, or already used'),
                        429 => self::errorResponse('Too many attempts'),
                    ],
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/invitations/accept',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Accept an invitation (public); a password is required only for a new address',
                    'tags' => ['invitations'],
                    'request' => 'InvitationAcceptRequest',
                    'responses' => [
                        200 => self::jsonResponse('Membership granted — see `data.status`', 'InvitationAcceptResponse'),
                        400 => self::errorResponse('The invitation link is invalid, expired, or already used'),
                        409 => self::errorResponse('The invitee is suspended in this tenant'),
                        422 => self::errorResponse('Missing token, or a password is required and was not supplied'),
                        429 => self::errorResponse('Too many attempts'),
                    ],
                ],
            ],
            self::permissionRoute('GET', '/api/invitations', 'users:read', [
                'summary' => "List the caller's own tenant's invitations",
                'tags' => ['invitations'],
                'responses' => [
                    200 => self::jsonResponse('Invitations, newest first', 'InvitationListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/invitations', 'users:write', [
                'summary' => 'Invite an address into the tenant (supersedes any invitation outstanding for it)',
                'tags' => ['invitations'],
                'request' => 'InvitationCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The invitation, identical whether or not the address has an account', 'InvitationItemResponse'),
                    409 => self::errorResponse('That address is already an active member of this tenant'),
                    422 => self::errorResponse('Invalid email address, role, or organizational unit'),
                    429 => self::errorResponse('Too many invitations sent'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/invitations/{id:\d+}/resend', 'users:write', [
                'summary' => 'Mint a fresh token and re-send it; the previous link stops working',
                'tags' => ['invitations'],
                'responses' => [
                    200 => self::jsonResponse('The re-issued invitation', 'InvitationItemResponse'),
                    404 => self::errorResponse('No pending invitation found for that id'),
                    429 => self::errorResponse('Too many invitations sent'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/invitations/{id:\d+}', 'users:write', [
                'summary' => 'Withdraw an outstanding invitation',
                'tags' => ['invitations'],
                'responses' => [
                    200 => self::jsonResponse('Revoked', 'InvitationRevokedResponse'),
                    404 => self::errorResponse('No pending invitation found for that id'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * "I lost my 2FA device" recovery-request routes
     * (WC-password-reset-2fa-recovery): two PUBLIC endpoints (request/confirm —
     * TwoFactorRecoveryHandler) plus the tenant-scoped admin approval queue and
     * the secondary admin-direct-force fallback
     * (TwoFactorRecoveryApprovalsApiHandler).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function twoFactorRecoveryRoutes(): array
    {
        return [
            [
                'method' => 'POST',
                'path' => '/api/auth/2fa-recovery/request',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Request account recovery after losing both password and 2FA device (public, rate-limited, no enumeration)',
                    'tags' => ['auth'],
                    'request' => 'EmailOnlyRequest',
                    'responses' => [
                        202 => self::jsonResponse(
                            'Always the same generic message, regardless of whether the address has an account',
                            'GenericMessageDataResponse'
                        ),
                        422 => self::errorResponse('A valid email address is required'),
                        429 => self::errorResponse('Too many recovery requests'),
                    ],
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/auth/2fa-recovery/confirm',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Confirm the recovery token — CREATES the pending admin-queue entry (clears nothing)',
                    'tags' => ['auth'],
                    'request' => 'TokenOnlyRequest',
                    'responses' => [
                        200 => self::jsonResponse('Submitted for administrator review', 'TwoFactorRecoveryConfirmResponse'),
                        400 => self::errorResponse('The confirmation link is invalid or has expired'),
                        422 => self::errorResponse('A confirmation token is required'),
                    ],
                ],
            ],
            self::permissionRoute('GET', '/api/2fa-recovery/pending', 'two_factor_recovery:approve', [
                'summary' => 'List pending 2FA-recovery requests for the caller\'s own tenant',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('Requests awaiting approval', 'PendingTwoFactorRecoveryListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/2fa-recovery/{id:\d+}/approve', 'two_factor_recovery:approve', [
                'summary' => 'Clear the target profile\'s 2FA and send a fresh password-reset link (tenant-scoped)',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('Approved', 'ApprovalStatusResponse'),
                    404 => self::errorResponse('No pending 2FA-recovery request found for that id'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/2fa-recovery/{id:\d+}/reject', 'two_factor_recovery:approve', [
                'summary' => 'Leave the target profile untouched (tenant-scoped)',
                'tags' => ['auth'],
                'responses' => [
                    200 => self::jsonResponse('Rejected', 'ApprovalStatusResponse'),
                    404 => self::errorResponse('No pending 2FA-recovery request found for that id'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/2fa-recovery/force-reset', 'two_factor_recovery:approve', [
                'summary' => 'Secondary fallback: force-clear a named profile\'s 2FA with no prior request (tenant-scoped)',
                'tags' => ['auth'],
                'request' => 'ForceResetRequest',
                'responses' => [
                    200 => self::jsonResponse('Forced', 'ForceResetResponse'),
                    404 => self::errorResponse('No such profile in your tenant'),
                    422 => self::errorResponse('A valid profile_id is required'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Native taxonomy/tagging routes (WC-621): tenant-scoped CRUD for tag groups
     * + tags, and a polymorphic tag<->entity association surface. Reads gated on
     * `tags:read`, writes on `tags:manage`.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function tagRoutes(): array
    {
        return [
            // Tag groups ────────────────────────────────────────────────────
            self::permissionRoute('GET', '/api/tag-groups', 'tags:read', [
                'summary' => 'List this tenant\'s tag groups',
                'tags' => ['taxonomy'],
                'responses' => [
                    200 => self::jsonResponse('Every tag group for this tenant', 'TagGroupListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/tag-groups', 'tags:manage', [
                'summary' => 'Create a tag group',
                'tags' => ['taxonomy'],
                'request' => 'TagGroupCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created tag group', 'TagGroupDataResponse'),
                    409 => self::errorResponse('A tag group with this key already exists'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/tag-groups/{id:\d+}', 'tags:read', [
                'summary' => 'Get a tag group',
                'tags' => ['taxonomy'],
                'responses' => [
                    200 => self::jsonResponse('The tag group', 'TagGroupDataResponse'),
                    404 => self::errorResponse('Tag group not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/tag-groups/{id:\d+}', 'tags:manage', [
                'summary' => 'Update a tag group',
                'tags' => ['taxonomy'],
                'request' => 'TagGroupUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated tag group', 'TagGroupDataResponse'),
                    404 => self::errorResponse('Tag group not found'),
                    409 => self::errorResponse('A tag group with this key already exists'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/tag-groups/{id:\d+}', 'tags:manage', [
                'summary' => 'Delete a tag group (its tags cascade)',
                'description' => 'Refuses with 409 while any entity association still references one of the group\'s '
                    . 'tags, reporting the affected counts, because the FK cascade would otherwise silently destroy '
                    . 'associations belonging to other plugins. Pass force=true to delete them as well; a forced '
                    . 'delete is recorded in the audit log.',
                'tags' => ['taxonomy'],
                'parameters' => [
                    self::queryParam('force', 'boolean', 'Delete the group even though entity associations reference its tags'),
                ],
                'responses' => [
                    204 => ['description' => 'Tag group removed'],
                    404 => self::errorResponse('Tag group not found'),
                    409 => self::errorResponse('Entity associations still reference this group\'s tags; retry with force=true'),
                ] + self::authErrors(),
            ]),

            // Tags ──────────────────────────────────────────────────────────
            self::permissionRoute('GET', '/api/tags', 'tags:read', [
                'summary' => 'List this tenant\'s tags, optionally within a group',
                'tags' => ['taxonomy'],
                'parameters' => [
                    self::queryParam('group_id', 'integer', 'Only tags in this group'),
                ],
                'responses' => [
                    200 => self::jsonResponse('Tags for this tenant', 'TagListResponse'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/tags', 'tags:manage', [
                'summary' => 'Create a tag in a group',
                'tags' => ['taxonomy'],
                'request' => 'TagCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created tag', 'TagDataResponse'),
                    409 => self::errorResponse('A tag with this name already exists in this group'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/tags/{id:\d+}', 'tags:read', [
                'summary' => 'Get a tag',
                'tags' => ['taxonomy'],
                'responses' => [
                    200 => self::jsonResponse('The tag', 'TagDataResponse'),
                    404 => self::errorResponse('Tag not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/tags/{id:\d+}', 'tags:manage', [
                'summary' => 'Rename a tag',
                'tags' => ['taxonomy'],
                'request' => 'TagUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated tag', 'TagDataResponse'),
                    404 => self::errorResponse('Tag not found'),
                    409 => self::errorResponse('A tag with this name already exists in this group'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/tags/{id:\d+}', 'tags:manage', [
                'summary' => 'Delete a tag (its associations cascade)',
                'description' => 'Refuses with 409 while entity associations still reference the tag, reporting the '
                    . 'affected count. Pass force=true to delete them as well; a forced delete is recorded in the '
                    . 'audit log.',
                'tags' => ['taxonomy'],
                'parameters' => [
                    self::queryParam('force', 'boolean', 'Delete the tag even though entity associations reference it'),
                ],
                'responses' => [
                    204 => ['description' => 'Tag removed'],
                    404 => self::errorResponse('Tag not found'),
                    409 => self::errorResponse('Entity associations still reference this tag; retry with force=true'),
                ] + self::authErrors(),
            ]),

            // Entity-tag associations ───────────────────────────────────────
            self::permissionRoute('GET', '/api/entity-tags', 'tags:read', [
                'summary' => 'An entity\'s tags (entity_type+entity_id) or entities carrying a tag (entity_type+tag_id)',
                'tags' => ['taxonomy'],
                'parameters' => [
                    self::queryParam('entity_type', 'string', 'The opaque plugin-supplied entity type (required)'),
                    self::queryParam('entity_id', 'integer', 'Return this entity\'s tags'),
                    self::queryParam('tag_id', 'integer', 'Return entities of entity_type carrying this tag'),
                ],
                'responses' => [
                    200 => self::jsonResponse('Tags of the entity, or entities carrying the tag', 'EntityTagQueryResponse'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/entity-tags', 'tags:manage', [
                'summary' => 'Attach a tag to an entity (idempotent)',
                'tags' => ['taxonomy'],
                'request' => 'EntityTagAssociationRequest',
                'responses' => [
                    200 => self::jsonResponse('The association already existed', 'EntityTagDataResponse'),
                    201 => self::jsonResponse('The tag was attached', 'EntityTagDataResponse'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/entity-tags', 'tags:manage', [
                'summary' => 'Detach a tag from an entity',
                'tags' => ['taxonomy'],
                'request' => 'EntityTagAssociationRequest',
                'responses' => [
                    204 => ['description' => 'Association removed'],
                    404 => self::errorResponse('Association not found'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/entity-tags/all', 'tags:manage', [
                'summary' => 'Detach every tag from one entity',
                'description' => 'The cleanup hook a plugin calls from its own record-delete path. entity_tags carries '
                    . 'no FK to the tagged record, so associations outlive it — and a later record reusing the same '
                    . 'entity_id would silently inherit them. Returns the number of associations removed; 0 is a '
                    . 'successful no-op.',
                'tags' => ['taxonomy'],
                'parameters' => [
                    // Both are genuinely mandatory (422 without them). They used
                    // to say so only in the prose while the emitted parameter
                    // carried `required: false`, so a generated client had no
                    // machine-readable reason to send either.
                    self::queryParam('entity_type', 'string', 'The opaque plugin-supplied entity type', required: true),
                    self::queryParam('entity_id', 'integer', 'The entity whose associations are removed', required: true),
                ],
                'responses' => [
                    200 => self::jsonResponse('The associations removed', 'EntityTagDetachAllResponse'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Operator subscription-plan admin routes (WC-plans, ADR 0010): catalog CRUD +
     * entitlement bundles + applying a plan to a target tenant. Gated on
     * `plans:manage` AND (in the handler) the system tenant.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function planRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/plans', 'plans:manage', [
                'summary' => 'List subscription plans (operator)',
                'tags' => ['plans'],
                'responses' => [
                    200 => self::jsonResponse('The plan catalog', 'PlanListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/plans', 'plans:manage', [
                'summary' => 'Create a subscription plan (operator)',
                'tags' => ['plans'],
                'request' => 'PlanCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created plan', 'PlanResponse'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/plans/{id:\d+}', 'plans:manage', [
                'summary' => 'Get a plan and its entitlement bundle (operator)',
                'tags' => ['plans'],
                'responses' => [
                    200 => self::jsonResponse('The plan and its entitlement bundle', 'PlanResponse'),
                    404 => self::errorResponse('Plan not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/plans/{id:\d+}', 'plans:manage', [
                'summary' => 'Update a plan (operator)',
                'tags' => ['plans'],
                'request' => 'PlanUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated plan', 'PlanResponse'),
                    404 => self::errorResponse('Plan not found'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/plans/{id:\d+}', 'plans:manage', [
                'summary' => 'Delete a plan (operator)',
                'tags' => ['plans'],
                'responses' => [
                    204 => ['description' => 'Plan deleted'],
                    404 => self::errorResponse('Plan not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/plans/{id:\d+}/entitlements', 'plans:manage', [
                'summary' => 'Set a plan\'s entitlement bundle (operator)',
                'tags' => ['plans'],
                'request' => 'PlanEntitlementsPutRequest',
                'responses' => [
                    200 => self::jsonResponse('The plan with its updated bundle', 'PlanResponse'),
                    400 => self::errorResponse('Body must include a non-empty "entitlements" object'),
                    404 => self::errorResponse('Plan not found'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/tenants/{id:\d+}/plan', 'plans:manage', [
                'summary' => 'Apply a plan to a tenant (operator)',
                'tags' => ['plans'],
                'request' => 'PlanApplyRequest',
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s plan assignment', 'TenantPlanResponse'),
                    404 => self::errorResponse('Tenant not found'),
                    422 => self::errorResponse('plan_id missing, unknown, or the system tenant'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/tenants/{id:\d+}/plan', 'plans:manage', [
                'summary' => 'Get a tenant\'s current plan (operator)',
                'tags' => ['plans'],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s plan assignment (null when unassigned)', 'TenantPlanResponse'),
                    404 => self::errorResponse('Tenant not found'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Subscription (billing-state) routes (WC-billing): operator set/read of a
     * tenant's subscription (gated `subscriptions:manage` + system tenant) and the
     * tenant-self read (gated `settings:read`, payment-wall exempt).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function subscriptionRoutes(): array
    {
        $selfRoute = [
            'method' => 'GET',
            'path' => '/api/subscription',
            'requiredRole' => null,
            'requiredPermission' => 'settings:read',
            'schema' => [
                'summary' => 'Get the caller tenant\'s own subscription (read-only)',
                'tags' => ['subscription'],
                'responses' => [
                    200 => self::jsonResponse('The caller tenant\'s subscription', 'SelfSubscriptionResponse'),
                    400 => self::errorResponse('Tenant context is required'),
                ] + self::authErrors(),
            ],
        ];

        return [
            self::permissionRoute('GET', '/api/tenants/{id:\d+}/subscription', 'subscriptions:manage', [
                'summary' => 'Get a tenant\'s subscription (operator)',
                'tags' => ['subscription'],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s subscription + billing state', 'SubscriptionResponse'),
                    404 => self::errorResponse('Tenant not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/tenants/{id:\d+}/subscription', 'subscriptions:manage', [
                'summary' => 'Set a tenant\'s subscription / apply a plan (operator)',
                'tags' => ['subscription'],
                'request' => 'SubscriptionPutRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated subscription', 'SubscriptionResponse'),
                    404 => self::errorResponse('Tenant not found'),
                    409 => self::errorResponse('The system tenant is never subscribed'),
                    422 => self::errorResponse('Validation failed (bad status/mode/plan)'),
                ] + self::authErrors(),
            ]),
            $selfRoute,
        ];
    }

    /**
     * Document/label designer template routes (WC-docdesigner). Tenant-scoped,
     * RBAC-gated CRUD; list/get are additionally row-filtered server-side by
     * scope + required_permission, and publishing needs documents:publish.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function documentTemplateRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/document-templates', 'documents:read', [
                'summary' => 'List document/label templates visible to the caller',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The templates the caller may see (RBAC-filtered)', 'DocumentTemplateListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/document-templates', 'documents:write', [
                'summary' => 'Create a document/label template',
                'tags' => ['documents'],
                'request' => 'DocumentTemplateCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created template', 'DocumentTemplateResponse'),
                    403 => self::errorResponse('Publishing a shared template requires documents:publish'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/document-templates/{id:\d+}', 'documents:read', [
                'summary' => 'Get a document/label template',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The template', 'DocumentTemplateResponse'),
                    404 => self::errorResponse('Template not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/document-templates/{id:\d+}', 'documents:write', [
                'summary' => 'Update a document/label template',
                'tags' => ['documents'],
                'request' => 'DocumentTemplateUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated template', 'DocumentTemplateResponse'),
                    403 => self::errorResponse('Publishing a shared template requires documents:publish'),
                    404 => self::errorResponse('Template not found or not visible to the caller'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/document-templates/{id:\d+}', 'documents:write', [
                'summary' => 'Delete a document/label template',
                'tags' => ['documents'],
                'responses' => [
                    204 => ['description' => 'Template deleted'],
                    404 => self::errorResponse('Template not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            // Server-side render (ADR 0012 / WC-docdesigner Track 2): calls the
            // separate `whity_render` service and streams back a PDF. Gated on
            // documents.render_enabled (503 when the operator has the render
            // tier turned off) BEFORE any RBAC/tenant work; 503 again if the
            // render service itself is unreachable/errors — never a raw
            // exception or a downstream stack trace.
            self::permissionRoute('POST', '/api/document-templates/{id:\d+}/render', 'documents:render', [
                'summary' => 'Render a document/label template to PDF',
                'tags' => ['documents'],
                'request' => 'DocumentRenderRequest',
                'responses' => [
                    200 => ['description' => 'The rendered PDF', 'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]]],
                    404 => self::errorResponse('Template not found or not visible to the caller'),
                    422 => self::errorResponse('Validation failed (bad dataRows, or a batch/size limit exceeded)'),
                    503 => self::errorResponse('Rendering is disabled on this instance, or the render service is unavailable'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Issued-document routes (#947 item 1). Tenant-scoped; reads are gated on
     * documents:read at the route and row-filtered on top (you raised it, or
     * you hold documents:read:all), so a caller who may not see a document is
     * told it does not exist rather than that it is forbidden.
     *
     * The re-render APPENDS an artifact. Every earlier one stays fetchable at
     * its own `content_url` — that permanence is the observable form of the
     * immutability guarantee, which is why the artifact-level content route
     * exists alongside the document-level one.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function documentRecordRoutes(): array
    {
        $pdfResponse = [
            'description' => 'The stored artifact bytes',
            'content' => ['application/pdf' => ['schema' => ['type' => 'string', 'format' => 'binary']]],
        ];

        return [
            // The organizer's rail (#978). Listed BEFORE /api/documents/{id}
            // because `views` is not a digit and could never have matched that
            // route's constraint — said out loud so the ordering is a decision
            // rather than an accident if the constraint is ever loosened.
            self::permissionRoute('GET', '/api/documents/views', 'documents:read', [
                'summary' => 'List the document folders this installation can actually compute',
                'description' =>
                    'A folder is a derived query, never a stored container. A view whose fact source this '
                    . 'installation does not record is ABSENT from this response rather than present and '
                    . 'empty — an empty "Awaiting me" would state "nothing awaits you", which is false and '
                    . 'unfalsifiable from outside. A view the CALLER cannot anchor (they belong to no unit) '
                    . 'is present with available=false and a reason, to be rendered disabled (#951). '
                    . '`unavailable_substrates` says what this installation does not record and what would '
                    . 'supply it.',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse(
                        'The computable folders, plus the fact sources this installation lacks',
                        'DocumentViewListResponse'
                    ),
                ] + self::authErrors(),
            ]),
            // The create route sits on `documents:render` rather than a slug of
            // its own — migration 113 already argued that a role holding it is
            // "precisely a role that can bring a document into existence", and a
            // new `documents:create` would be a permission nobody on any
            // existing install holds. See public/index.php's registration.
            self::permissionRoute('POST', '/api/documents', 'documents:render', [
                'summary' => 'Raise a document from a template, supplying values for its placeholders',
                'description' =>
                    'The record is the deliverable and the rendered artifact is opportunistic. '
                    . '`documents.render_enabled` defaults to FALSE, so on a default install this '
                    . 'returns a document with no artifact and `content_url: null` — which is a '
                    . 'complete, routable document, not a degraded one: the values it was raised with '
                    . 'are stored on the record, and POST /api/documents/{id}/render mints the '
                    . 'artifact from them if the tier is later switched on. The `render` block says '
                    . 'what happened. Sending `render: true` turns "could not render" into a 503 '
                    . 'instead, for a caller who genuinely requires the bytes. A template the caller '
                    . 'cannot SEE is a 404, never a 403, and the check is the designer\'s own '
                    . 'visibility policy — creating from a gated template must not be a way to read it.',
                'tags' => ['documents'],
                'request' => 'DocumentCreateRequest',
                'responses' => [
                    201 => self::jsonResponse(
                        'The document, and whether an artifact was rendered for it',
                        'DocumentCreateResponse'
                    ),
                    404 => self::errorResponse('No such template, or it is not visible to the caller'),
                    422 => self::errorResponse(
                        'No template named, a bad dataRows shape, or a value supplied for a '
                        . 'placeholder the template does not declare'
                    ),
                    503 => self::errorResponse(
                        'render:true was requested and rendering or persistence is disabled on this instance'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents', 'documents:read', [
                'summary' => 'List issued documents visible to the caller (newest first, paginated)',
                'description' =>
                    'Naming no view is the plain tenant-wide list. `view` selects one of the folders from '
                    . 'GET /api/documents/views; a key this installation cannot compute is a 404, because '
                    . 'from outside it does not exist.',
                'tags' => ['documents'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                    self::queryParam('view', 'string', 'Folder key from GET /api/documents/views (default "all")'),
                    self::queryParam(
                        'ou_id',
                        'integer',
                        'Anchor unit for the unit-scoped folders. Defaults to the caller\'s own unit.'
                    ),
                    self::queryParam('collection_id', 'integer', 'Required by the "collection" view'),
                    self::queryParam('q', 'string', 'Case-insensitive substring of the document title'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The documents the caller may see, with pagination', 'DocumentListResponse'),
                    400 => self::errorResponse('A required view parameter is missing, or ou_id is not a unit in this tenant'),
                    404 => self::errorResponse(
                        'No such view, this installation cannot compute it, or the named collection '
                        . 'belongs to somebody else'
                    ),
                    422 => self::errorResponse('The view exists but the caller cannot anchor it (e.g. they belong to no unit)'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents/{id:\\d+}', 'documents:read', [
                'summary' => 'Get an issued document and its full artifact history',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The document', 'DocumentResponse'),
                    404 => self::errorResponse('Document not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents/{id:\\d+}/content', 'documents:read', [
                'summary' => 'Download the current artifact of a document',
                'tags' => ['documents'],
                'responses' => [
                    200 => $pdfResponse,
                    404 => self::errorResponse('Document not found, not visible, or has no stored content'),
                    503 => self::errorResponse('The stored artifact could not be read from storage'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents/{id:\\d+}/artifacts/{artifactId:\\d+}/content', 'documents:read', [
                'summary' => 'Download one specific artifact, superseded or not',
                'tags' => ['documents'],
                'responses' => [
                    200 => $pdfResponse,
                    404 => self::errorResponse('Document or artifact not found, or not visible to the caller'),
                    503 => self::errorResponse('The stored artifact could not be read from storage'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/documents/{id:\\d+}/render', 'documents:render', [
                'summary' => 'Re-render the document and APPEND a new artifact (never replaces one)',
                'tags' => ['documents'],
                'request' => 'DocumentRerenderRequest',
                'responses' => [
                    201 => self::jsonResponse('The document with the new artifact at the head of its history', 'DocumentResponse'),
                    404 => self::errorResponse('Document not found or not visible to the caller'),
                    409 => self::errorResponse('The template this document was issued from is no longer available'),
                    422 => self::errorResponse('Validation failed (bad dataRows, or a batch/size limit exceeded)'),
                    503 => self::errorResponse('Rendering or persistence is disabled, or the render service is unavailable'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Document ROUTING routes (#947 item 3).
     *
     * Three views in #978 read these: the composer (`/routing-rules` + POST
     * `/routes`), the trail view (`/trail`), and acting (`/actions`).
     *
     * TWO DIFFERENT KINDS OF GATE. Issuing a route is `documents:route`
     * (migration 113). ACTING on an item that reached you carries NO permission
     * and is session-gated only, because being a recipient IS the authorization:
     * the route named a rule, the rule resolved to you, and the engine wrote the
     * row. A second permission on top would let a route resolve to somebody who
     * then cannot answer it - the item stays open forever and the person holding
     * it cannot discover why. Same posture as `/api/me/notifications`.
     *
     * Reads are `documents:read` at the route and row-filtered on top by
     * DocumentVisibilityPolicy (you raised it, you hold documents:read:all, a
     * route reached you, or a role was granted to you on the document), so a
     * caller who may not see a document is told it does not exist.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function documentRoutingRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/routing-rules', 'documents:read', [
                'summary' => 'List the routing rule kinds a route step may name on this instance',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse(
                        "Core's own kinds plus any a plugin registered",
                        'RoutingRuleListResponse'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/documents/{id:\\d+}/routes', 'documents:route', [
                'summary' => 'Issue a route on a document: create it, its ordered steps and the first step\'s recipients',
                'tags' => ['documents'],
                'request' => 'DocumentRouteCreateRequest',
                'responses' => [
                    201 => self::jsonResponse(
                        'The route with its steps, and how many recipients the first step resolved to and delivered',
                        'DocumentRouteResponse'
                    ),
                    404 => self::errorResponse('Document not found or not visible to the caller'),
                    422 => self::errorResponse(
                        'No steps, a step naming an unregistered rule kind, a config the rule refused, '
                        . 'or a step/recipient ceiling exceeded'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents/{id:\\d+}/routes', 'documents:read', [
                'summary' => 'List the circulations of a document, newest first, each with its steps',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The routes on this document', 'DocumentRouteListResponse'),
                    404 => self::errorResponse('Document not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents/{id:\\d+}/trail', 'documents:read', [
                'summary' => "The document's append-only routing trail, oldest first (paginated)",
                'tags' => ['documents'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                ],
                'responses' => [
                    200 => self::jsonResponse(
                        'The trail across every route on this document',
                        'DocumentTrailListResponse'
                    ),
                    404 => self::errorResponse('Document not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents/{id:\\d+}/recipients', 'documents:read', [
                'summary' => 'Who the document\'s routes reached, and what became of each item',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse(
                        'Every recipient row on this document',
                        'DocumentRouteRecipientListResponse'
                    ),
                    404 => self::errorResponse('Document not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            [
                'method' => 'POST',
                'path' => '/api/documents/{id:\\d+}/routes/{routeId:\\d+}/actions',
                'requiredRole' => null,
                // Deliberately unpermissioned - see the group docblock. Being a
                // recipient is the authorization; `noted` needs only visibility.
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Act on a route: forward, acknowledge, return, or add a note',
                    'tags' => ['documents'],
                    'request' => 'DocumentRouteActionRequest',
                    'responses' => [
                        201 => self::jsonResponse(
                            'The appended trail event, and how many recipients the act resolved to and delivered',
                            'DocumentRouteActionResponse'
                        ),
                        404 => self::errorResponse('Document or route not found, or not visible to the caller'),
                        422 => self::errorResponse(
                            'No open item on this route, a forward from the last step, a return from the '
                            . 'first, an empty note, or an unknown action'
                        ),
                    ] + self::authErrors(),
                ],
            ],
        ];
    }

    /**
     * Named user groups (#999).
     *
     * A group is a named, reusable RULE over the tenant's people — "everyone
     * holding the instructor role" stored once and referenced from many places,
     * starting with routing's `group` step kind. One node saying "instructors",
     * not a thousand nodes for a thousand instructors.
     *
     * TWO ABSENCES ON THIS SURFACE ARE DELIBERATE, NOT GAPS
     * ----------------------------------------------------
     *  - NO MEMBER LIST ROUTE. `/preview` answers with a count and a bounded
     *    sample and has no `page` parameter, and one is not coming. A screen that
     *    renders 1,043 people has rebuilt the problem the design exists to avoid.
     *    Somebody who wants a person-by-person list is asking `/api/users` a
     *    question about roles, which it already answers with its own filtering,
     *    paging and permission.
     *  - NO MEMBER COUNTS ON THE LIST. Resolution is live and uncached (a cache
     *    is the rejected stored list with a timestamp on it), so a count per row
     *    would resolve every rule on every render — forty groups, forty fan-out
     *    queries, to decorate a screen nobody asked a membership question on.
     *
     * TWO CATALOGUES OVER ONE REGISTRY. `/api/group-rules` is the subset of
     * `/api/routing-rules` that can answer without a document: it excludes
     * `group` itself (which is what makes a group-of-groups impossible rather
     * than merely discouraged) and any plugin kind that reads the document it is
     * routed with.
     *
     * PERMISSIONS. Reads are `groups:read`; writes and the DRAFT preview are
     * `groups:write`. The draft preview is the tighter of the two on purpose — it
     * resolves an arbitrary rule the caller composed, so a reader who may only
     * see existing definitions cannot probe the organisation by inventing new
     * ones. Both slugs are granted by migration 116 to a nameable audience, so
     * neither is a catalogue row nobody holds.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function userGroupRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/group-rules', 'groups:read', [
                'summary' => "List the rule kinds a user group's definition may name on this instance",
                'tags' => ['user-groups'],
                'responses' => [
                    200 => self::jsonResponse(
                        "The subset of routing rule kinds that can answer without a document",
                        'GroupRuleListResponse'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/user-groups', 'groups:read', [
                'summary' => "This tenant's user group DEFINITIONS, by name (paginated, no member counts)",
                'tags' => ['user-groups'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The tenant\'s groups with pagination', 'UserGroupListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/user-groups/preview', 'groups:write', [
                'summary' => 'Preview an UNSAVED rule: how many people it resolves to right now, plus a sample',
                'tags' => ['user-groups'],
                'request' => 'UserGroupPreviewRequest',
                'responses' => [
                    200 => self::jsonResponse(
                        'The count, a bounded sample, and the actor it was resolved against',
                        'UserGroupPreviewResponse'
                    ),
                    422 => self::errorResponse(
                        'A malformed kind, a kind nothing provides, a kind that needs a document, '
                        . 'or a config the rule refused'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/user-groups', 'groups:write', [
                'summary' => 'Define a user group: a name plus the rule that says who is in it',
                'tags' => ['user-groups'],
                'request' => 'UserGroupCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created group', 'UserGroupResponse'),
                    409 => self::errorResponse('A group with that name already exists in this tenant'),
                    422 => self::errorResponse(
                        'A missing or over-long name, a malformed kind, a kind nothing provides, '
                        . 'a kind that needs a document, or a config the rule refused'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/user-groups/{id:\\d+}/preview', 'groups:read', [
                'summary' => 'How many people this group resolves to RIGHT NOW, plus a bounded sample',
                'tags' => ['user-groups'],
                'responses' => [
                    200 => self::jsonResponse(
                        'The count, a bounded sample, and the actor it was resolved against',
                        'UserGroupPreviewResponse'
                    ),
                    404 => self::errorResponse('Group not found in this tenant'),
                    422 => self::errorResponse(
                        "The group's rule can no longer be resolved on this instance — for example the "
                        . 'plugin that supplied its kind was removed'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/user-groups/{id:\\d+}', 'groups:read', [
                'summary' => "One group's definition (no membership — see /preview)",
                'tags' => ['user-groups'],
                'responses' => [
                    200 => self::jsonResponse('The group', 'UserGroupResponse'),
                    404 => self::errorResponse('Group not found in this tenant'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/user-groups/{id:\\d+}', 'groups:write', [
                'summary' => 'Rename or redefine a group. Takes effect immediately, including for routes in flight',
                'tags' => ['user-groups'],
                'request' => 'UserGroupUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated group', 'UserGroupResponse'),
                    404 => self::errorResponse('Group not found in this tenant'),
                    409 => self::errorResponse('Another group in this tenant already has that name'),
                    422 => self::errorResponse(
                        "'rule_kind' sent without 'rule_config' (or the reverse), an over-long name, "
                        . 'or a config the rule refused'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/user-groups/{id:\\d+}', 'groups:write', [
                'summary' => 'Delete a group. Route steps naming it then fail LOUDLY by name, never silently',
                'tags' => ['user-groups'],
                'responses' => [
                    200 => self::jsonResponse('The deleted group id', 'UserGroupDeleteResponse'),
                    404 => self::errorResponse('Group not found in this tenant'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * The caller's INBOX (#881), read one registered source at a time.
     *
     * Self-scoped to the caller's own (tenant, profile) and session-gated with NO
     * RBAC permission - an inbox row already names exactly one person, so a
     * tenant-wide permission has no work left to do. Matches the other `/api/me`
     * self-service surfaces.
     *
     * `source` IS REQUIRED on the list. #881 names three questions that arise
     * only when sources are AGGREGATED - ordering across heterogeneous sources,
     * per-source failure isolation, and pagination across sources - and says each
     * needs deciding before an aggregate ships. Answering an unsourced request
     * would decide all three by accident, and the answer would silently become
     * wrong the day a second source registers. So it is a 422 naming the
     * registered keys, and the aggregate is a later behaviour for that case
     * reading this same registry.
     *
     * Routing's recipients are the first source rather than a surface of their
     * own: two inbox surfaces would be the same mistake as two audit trails.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function meInboxRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/me/inbox/sources',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => "The registered inbox sources, with the caller's open count for each",
                    'tags' => ['inbox'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'Every registered source, its item-field mapping and the open count',
                            'InboxSourceListResponse'
                        ),
                    ] + self::authErrors(),
                ],
            ],
            [
                'method' => 'GET',
                'path' => '/api/me/inbox',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => "A page of one inbox source's items awaiting the caller",
                    'tags' => ['inbox'],
                    'parameters' => [
                        self::queryParam('source', 'string', 'REQUIRED. A key from /api/me/inbox/sources'),
                        self::queryParam('open', 'boolean', 'Falsey to include the caller\'s history as well (default open-only)'),
                        self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                        self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                    ],
                    'responses' => [
                        200 => self::jsonResponse("That source's items, with pagination", 'InboxItemListResponse'),
                        422 => self::errorResponse("'source' is missing or names no registered source"),
                    ] + self::authErrors(),
                ],
            ],
        ];
    }

    /**
     * Per-user document collections and the star (#978, implementing #947
     * item 5).
     *
     * Every route is gated on `documents:read`, INCLUDING the writes, and a
     * `documents:organize` beside it was rejected: a permission earns its
     * existence by being withholdable from somebody who holds its neighbours,
     * and there is no administrator who wants a colleague to read documents but
     * not to keep a private note of which ones matter. A collection is
     * invisible to everyone else, confers nothing, and dies with its owner.
     *
     * What IS enforced is ownership — a collection is looked up by
     * (id, tenant, profile), so another person's id is NOT FOUND rather than
     * forbidden, since collection ids are enumerable — and document visibility
     * on the way in, so the filing endpoints cannot be used to discover which
     * document ids exist.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function documentCollectionRoutes(): array
    {
        $notFound = self::errorResponse('Collection not found, or not the caller\'s');
        $nameErrors = [
            409 => self::errorResponse('The caller already has a collection with that name'),
            422 => self::errorResponse('name is missing, empty, or over 160 characters'),
        ];

        return [
            self::permissionRoute('GET', '/api/document-collections', 'documents:read', [
                'summary' => 'List the caller\'s own document collections, with item counts',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The caller\'s collections', 'DocumentCollectionListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/document-collections', 'documents:read', [
                'summary' => 'Create one of the caller\'s own collections',
                'description' =>
                    '`system_key` is never accepted from a client: minting a well-known key would be '
                    . 'claiming the target of the star control.',
                'tags' => ['documents'],
                'request' => 'DocumentCollectionCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created collection', 'DocumentCollectionResponse'),
                ] + $nameErrors + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/document-collections/{id:\\d+}', 'documents:read', [
                'summary' => 'Rename one of the caller\'s own collections',
                'description' =>
                    'Refused with 409 for a built-in (system_key) collection: the star control addresses '
                    . 'it by key and does not label it from the row, so renaming it would rename something '
                    . 'nothing displays.',
                'tags' => ['documents'],
                'request' => 'DocumentCollectionUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The renamed collection', 'DocumentCollectionResponse'),
                    404 => $notFound,
                ] + $nameErrors + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/document-collections/{id:\\d+}', 'documents:read', [
                'summary' => 'Delete one of the caller\'s own collections (the documents are untouched)',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('Deletion confirmation', 'MutationResponse'),
                    404 => $notFound,
                    409 => self::errorResponse('A built-in collection cannot be deleted'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute(
                'PUT',
                '/api/document-collections/{id:\\d+}/documents/{documentId:\\d+}',
                'documents:read',
                [
                    'summary' => 'File a document into one of the caller\'s collections (idempotent)',
                    'tags' => ['documents'],
                    'responses' => [
                        200 => self::jsonResponse('The resulting membership, read back', 'DocumentCollectionMembershipResponse'),
                        404 => self::errorResponse('Collection not found, or the document is not visible to the caller'),
                    ] + self::authErrors(),
                ]
            ),
            self::permissionRoute(
                'DELETE',
                '/api/document-collections/{id:\\d+}/documents/{documentId:\\d+}',
                'documents:read',
                [
                    'summary' => 'Remove a document from one of the caller\'s collections (idempotent)',
                    'description' =>
                        'Deliberately does NOT re-check the document\'s visibility: un-filing something the '
                        . 'caller can no longer read is exactly the case they need, and refusing it would '
                        . 'leave a row they own and cannot get rid of.',
                    'tags' => ['documents'],
                    'responses' => [
                        200 => self::jsonResponse('The resulting membership, read back', 'DocumentCollectionMembershipResponse'),
                        404 => $notFound,
                    ] + self::authErrors(),
                ]
            ),
            self::permissionRoute('PUT', '/api/documents/{id:\\d+}/star', 'documents:read', [
                'summary' => 'Star a document — files it into the caller\'s well-known "starred" collection',
                'description' =>
                    'Starring is a collection, not a second concept. The collection is created on first '
                    . 'use rather than seeded per profile, which would write a row for every member of '
                    . 'every tenant to record something nobody has done.',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The starred collection and the resulting state', 'DocumentStarResponse'),
                    404 => self::errorResponse('Document not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/documents/{id:\\d+}/star', 'documents:read', [
                'summary' => 'Un-star a document',
                'description' =>
                    'A 200 even when the caller has never starred anything: they asked for a state that is '
                    . 'already true, and creating the collection just to delete a row from it would write a '
                    . 'row to record an absence.',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The starred collection (null if none) and the resulting state', 'DocumentStarResponse'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Document/label designer block routes (WC-521). Tenant-scoped, RBAC-gated
     * CRUD; list/get are additionally row-filtered server-side by scope +
     * required_permission, publishing needs documents:publish, and delete is
     * refused (409) while a template still holds a live blockInstance pointer
     * at the block (reference-integrity guard).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function documentBlockRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/document-blocks', 'documents:read', [
                'summary' => 'List document/label blocks visible to the caller',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The blocks the caller may see (RBAC-filtered)', 'DocumentBlockListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/document-blocks', 'documents:write', [
                'summary' => 'Create a document/label block',
                'tags' => ['documents'],
                'request' => 'DocumentBlockCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created block', 'DocumentBlockResponse'),
                    403 => self::errorResponse('Publishing a shared block requires documents:publish'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/document-blocks/{id:\d+}', 'documents:read', [
                'summary' => 'Get a document/label block',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The block', 'DocumentBlockResponse'),
                    404 => self::errorResponse('Block not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            // Listed after GET /{id} and before the write routes purely for
            // readability; `usage` is not a digit, so it could never have matched
            // the /{id:\d+} constraint either way.
            self::permissionRoute('GET', '/api/document-blocks/{id:\d+}/usage', 'documents:read', [
                'summary' => 'What would break if this block changed: the templates that instance it',
                'description' =>
                    'A block is POINTER-referenced (a `blockInstance` element), so editing it propagates '
                    . 'to every template that instances it — and unlike delete, an edit is never refused. '
                    . 'This is the answer a client needs before offering either action. `templates` is '
                    . 'row-filtered to what the caller may see; `total` counts EVERY referencing template '
                    . 'in the tenant and `hidden` is the difference, so a caller with narrow reach is told '
                    . 'the edit reaches further than they can see instead of being quietly understated.',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The referencing templates, plus the unfiltered total', 'DocumentBlockUsageResponse'),
                    404 => self::errorResponse('Block not found or not visible to the caller'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/document-blocks/{id:\d+}', 'documents:write', [
                'summary' => 'Update a document/label block',
                'tags' => ['documents'],
                'request' => 'DocumentBlockUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated block', 'DocumentBlockResponse'),
                    403 => self::errorResponse('Publishing a shared block requires documents:publish'),
                    404 => self::errorResponse('Block not found or not visible to the caller'),
                    422 => self::errorResponse('Validation failed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/document-blocks/{id:\d+}', 'documents:write', [
                'summary' => 'Delete a document/label block',
                'tags' => ['documents'],
                'responses' => [
                    204 => ['description' => 'Block deleted'],
                    404 => self::errorResponse('Block not found or not visible to the caller'),
                    409 => self::errorResponse('Cannot delete a block that is still referenced by a template'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * Resource-scoped role grants (WC-712 §3) — the write path for
     * `resource_role_assignments`.
     *
     * Gated on the EXISTING roles:read / roles:manage. A new permission would
     * need a grant migration, and such a migration reaches the `admin` role
     * only, so operators running a custom administrative role would silently
     * lose a capability their plugins depend on.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function resourceRoleGrantRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/resource-role-grants', 'roles:read', [
                'summary' => 'List the role grants addressed at one resource',
                'description' => 'Returns both grant shapes: a null `profile_id` is the '
                    . '"everyone at this resource" grant, a set `profile_id` grants to that one profile. '
                    . 'Always scoped to the caller\'s tenant, so a resource belonging to another tenant '
                    . 'yields an empty list rather than an error.',
                'tags' => ['rbac'],
                'parameters' => [
                    self::queryParam('resource_type', 'string', 'A registered resource type (e.g. `ou`, `acme:record`)', true),
                    self::queryParam('resource_id', 'integer', 'The record the grants are addressed at', true),
                ],
                'responses' => [
                    200 => self::jsonResponse('The grants at this resource', 'ResourceRoleGrantListResponse'),
                    422 => self::errorResponse('resource_type is unregistered, or resource_id is missing/invalid'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/resource-role-grants', 'roles:manage', [
                'summary' => 'Grant a role at one resource (idempotent)',
                'description' => 'Granting a role that is already granted at that resource is a SUCCESS '
                    . '(200 with `created: false` and the existing grant id), not a conflict — mirroring '
                    . 'POST /api/users/{id}/memberships. A grant WIDENS authority at one resource and is '
                    . 'never a substitute for tenant membership: resolution still requires an active '
                    . 'membership in the tenant.',
                'tags' => ['rbac'],
                'request' => 'ResourceRoleGrantCreateRequest',
                'responses' => [
                    200 => self::jsonResponse('The grant already existed', 'ResourceRoleGrantResponse'),
                    201 => self::jsonResponse('The grant was created', 'ResourceRoleGrantResponse'),
                    404 => self::errorResponse('The resource, role or profile is not the caller tenant\'s'),
                    422 => self::errorResponse('Validation failed, or resource_type is unregistered'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/resource-role-grants/{id:\d+}', 'roles:manage', [
                'summary' => 'Revoke one resource role grant by its id',
                'description' => 'By id rather than by (resource, role, profile): over HTTP an omitted '
                    . '`profile_id` and an explicit null are indistinguishable, so a tuple-addressed revoke '
                    . 'would let a dropped parameter silently revoke the everyone-grant instead of one '
                    . 'profile\'s. The ids come from the list route.',
                'tags' => ['rbac'],
                'responses' => [
                    204 => ['description' => 'Grant revoked'],
                    404 => self::errorResponse('No such grant in the caller\'s tenant'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/resource-role-grants/all', 'roles:manage', [
                'summary' => 'Revoke every role grant at one resource',
                'description' => 'The cleanup an owner runs when it deletes the record itself. '
                    . '`resource_id` carries no foreign key, so core is never told a record disappeared and its '
                    . 'grants outlive it — a later record reusing that id would silently inherit them. Takes the '
                    . 'SAME parameters as the list route, so a caller can GET exactly what this removes. Returns '
                    . 'the number of grants revoked; 0 is a successful no-op, never a 404, so the call is safe to '
                    . 'make unconditionally from a delete path and safe to retry. Unlike the create route this '
                    . 'does NOT ask the owning plugin to vouch for the resource: by cleanup time the record is '
                    . 'usually already deleted, so a fails-closed check would refuse exactly the calls that '
                    . 'matter. The tenant predicate still confines it to the caller\'s own grants.',
                'tags' => ['rbac'],
                'parameters' => [
                    self::queryParam('resource_type', 'string', 'A registered resource type (e.g. `ou`, `acme:record`)', true),
                    self::queryParam('resource_id', 'integer', 'The record whose grants are removed', true),
                ],
                'responses' => [
                    200 => self::jsonResponse('The grants removed', 'ResourceRoleGrantRevokeAllResponse'),
                    422 => self::errorResponse('resource_type is unregistered, or resource_id is missing/invalid'),
                ] + self::authErrors(),
            ]),
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
     * @param string|array<string, mixed> $component A registered component NAME
     *        (rendered as a `$ref`), or an inline JSON-Schema fragment (e.g. from
     *        {@see self::object()}) for a response with no named component.
     * @return array<string, mixed>
     */
    private static function jsonResponse(string $description, string|array $component): array
    {
        return [
            'description' => $description,
            'content' => ['application/json' => ['schema' => is_string($component) ? SchemaBuilder::ref($component) : $component]],
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
    private static function queryParam(
        string $name,
        string $type,
        string $description,
        bool $required = false
    ): array {
        return [
            'name' => $name,
            'in' => 'query',
            'required' => $required,
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

    /**
     * A plaintext password field, bounded by the ACTUAL site policy.
     *
     * Read from {@see PasswordPolicy} rather than written as a literal: the
     * schema and the handler that rejects the value must not be able to drift.
     * They had — the declaration said `minLength: 6` while the policy has
     * required 8 since it was centralised, so a generated client could build a
     * request the schema calls valid and the API answers 400 to.
     *
     * @return array<string, mixed>
     */
    private static function password(): array
    {
        return [
            'type' => 'string',
            'format' => 'password',
            'minLength' => PasswordPolicy::MIN_LENGTH,
            'maxLength' => PasswordPolicy::MAX_LENGTH,
        ];
    }

    /**
     * An email address field bounded by the VARCHAR(255) column cap the write
     * handlers enforce through {@see InputLimits::NAME_MAX} (422 past it).
     *
     * @return array<string, mixed>
     */
    private static function email(): array
    {
        return ['type' => 'string', 'format' => 'email', 'maxLength' => InputLimits::NAME_MAX];
    }

    /**
     * A VARCHAR(255)-backed identifier (name, slug, key) as the write handlers
     * bound it through {@see InputLimits::NAME_MAX} — past it they answer 422.
     *
     * @param bool $nonEmpty Whether the handler also refuses an empty string.
     * @return array<string, mixed>
     */
    private static function name(bool $nonEmpty = false): array
    {
        $schema = ['type' => 'string', 'maxLength' => InputLimits::NAME_MAX];
        if ($nonEmpty) {
            $schema['minLength'] = 1;
        }

        return $schema;
    }

    /**
     * A long-form TEXT field as the write handlers bound it through
     * {@see InputLimits::TEXT_MAX} — past it they answer 422.
     *
     * @return array<string, mixed>
     */
    private static function text(): array
    {
        return ['type' => 'string', 'maxLength' => InputLimits::TEXT_MAX];
    }

    /**
     * An organizational-unit TYPE KEY, as {@see OuTypeRegistry::isValidKey()}
     * accepts it: a lowercase slug, optionally namespaced with one colon.
     *
     * @param bool $nullable Whether an explicit null is accepted. True on the OU
     *        routes, where null (and an empty string) means "untyped"; false for
     *        `OuTypeCreateRequest.key`, which is the mandatory identity of the
     *        row being created.
     * @return array<string, mixed>
     */
    private static function ouTypeKey(bool $nullable = true): array
    {
        $schema = ['type' => 'string'];
        if ($nullable) {
            $schema['nullable'] = true;
        }

        return $schema + [
            'maxLength' => OuTypeRegistry::KEY_MAX_LENGTH,
            'pattern' => '^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*)?$',
        ];
    }

    /**
     * The bilingual `{ar?, en?}` label object (WC-532), marked for the
     * schema-driven CRUD screen's LocalizedText renderer.
     *
     * @return array<string, mixed>
     */
    private static function localizedText(): array
    {
        return [
            'type' => 'object',
            'x-whity-localized-text' => true,
            'properties' => ['ar' => self::str(), 'en' => self::str()],
        ];
    }

    /**
     * A tag-group key, as `TagGroupsApiHandler::KEY_PATTERN` accepts it.
     *
     * The handler's constant is private, so the bounds are written here and
     * pinned to it by `RequestSchemaContractTest`, which reads the constant
     * reflectively and fails when the two disagree.
     *
     * @return array<string, mixed>
     */
    private static function tagGroupKey(): array
    {
        return [
            'type' => 'string',
            'minLength' => 1,
            'maxLength' => 64,
            'pattern' => '^[A-Za-z0-9_.:-]{1,64}$',
        ];
    }

    /**
     * A tag name: trimmed, non-empty, and bounded by
     * `TagsApiHandler::MAX_NAME_LENGTH` (pinned by the same contract test).
     *
     * @return array<string, mixed>
     */
    private static function tagName(): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];
    }

    /**
     * The opaque, plugin-supplied entity type of a tag association, bounded by
     * `EntityTagsApiHandler::MAX_ENTITY_TYPE_LENGTH` (pinned by the same test).
     *
     * Deliberately carries no enum: the column has no foreign key and the set of
     * taggable types is open by design.
     *
     * @return array<string, mixed>
     */
    private static function entityType(): array
    {
        return ['type' => 'string', 'minLength' => 1, 'maxLength' => 128];
    }

    /**
     * A schema fragment forbidding two properties from appearing TOGETHER.
     *
     * `not: {required: [a, b]}` is the JSON-Schema spelling of "not both", and
     * it is what several handlers enforce with a 422 when a caller addresses one
     * thing two ways. Deliberately not `oneOf`, which would additionally demand
     * that one of them be present — every such pair here is optional.
     *
     * @return array{not: array{required: list<string>}}
     */
    private static function mutuallyExclusive(string $first, string $second): array
    {
        return ['not' => ['required' => [$first, $second]]];
    }

    /**
     * An integer FK marked with the `x-whity-reference` vendor extension, so the
     * schema-driven CRUD screen renders a dropdown fed from the referenced
     * collection instead of a bare number box.
     *
     * `$labelField` must name a STRING property of the collection's row: the
     * renderer stringifies it, so pointing at an object-valued field (a
     * `x-whity-localized-text` label, say) would render "[object Object]".
     *
     * @param string $collectionPath Unversioned collection path (e.g. `/api/roles`).
     * @param string $labelField Row property shown as the option label.
     * @param bool $nullable Whether an explicit null clears the reference.
     * @return array<string, mixed>
     */
    private static function reference(string $collectionPath, string $labelField, bool $nullable = false): array
    {
        return self::int($nullable) + [
            'x-whity-reference' => [
                'resource' => self::apiPath($collectionPath),
                'valueField' => 'id',
                'labelField' => $labelField,
            ],
        ];
    }

    /**
     * The versioned, client-callable form of an unversioned catalogue path.
     *
     * Route declarations here are written unversioned ({@see registerRoutes()}
     * lets the Router prefix them), but a `x-whity-reference` resource is a URL
     * the BROWSER fetches, so it has to carry the prefix already. Derived from
     * the Router's own default rather than written as a literal — and
     * `RequestSchemaContractTest` asserts every resource produced here resolves
     * to a path the generated spec actually serves, so a version bump cannot leave a
     * dangling dropdown behind.
     */
    private static function apiPath(string $unversionedPath): string
    {
        $prefix = (new Router())->getVersionPrefix();
        $pos = strpos($unversionedPath, '/', 1);

        return $pos === false
            ? $unversionedPath . $prefix
            : substr($unversionedPath, 0, $pos) . $prefix . substr($unversionedPath, $pos);
    }
}
