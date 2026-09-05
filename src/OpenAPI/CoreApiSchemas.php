<?php

declare(strict_types=1);

namespace Whity\OpenAPI;

use Whity\Core\Convening\AttendanceCapacity;
use Whity\Core\Convening\AttendanceEntry;
use Whity\Core\Convening\AttendanceRepository;
use Whity\Core\Convening\DecisionNumbers;
use Whity\Core\Convening\DecisionVerdict;
use Whity\Core\Convening\InvitationStatus;
use Whity\Core\Convening\MeetingStatus;
use Whity\Core\Convening\MemberRole;
use Whity\Core\Document\Organizer\DocumentSortField;
use Whity\Core\Ou\OuTypeRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\PasswordPolicy;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\TimeWindow\WindowState;
use Whity\Core\TimeWindow\WindowTypeRegistry;
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
            self::timeWindowRoutes(),
            self::formRoutes(),
            self::conveningRoutes(),
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
            self::uiPreferenceRoutes(),
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
            self::documentQrRoutes(),
            self::meInboxRoutes(),
            self::userGroupRoutes(),
            self::documentRouteTemplateRoutes(),
            self::documentCollectionRoutes(),
            self::instanceRoutes(),
            self::twoFactorPolicyRoutes(),
            self::tagRoutes(),
            self::passwordResetRoutes(),
            self::invitationRoutes(),
            self::twoFactorRecoveryRoutes(),
            self::dataTypeRoutes(),
            self::reportRoutes(),
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
                    self::queryParam('sort', 'string', 'Sort key: `name`, `email`, `role`, `status`, `created` (default), plus `tenant` for a system-tenant caller. `name` and `email` order identically — the name IS the email\'s local part. An unrecognised key is not an error: it falls back to the default, because a client asking for a column it cannot see should get a list rather than a 400.'),
                    self::queryParam('dir', 'string', 'Sort direction, `asc` or `desc`. Anything else is read as `asc`.'),
                    self::queryParam('q', 'string', 'Case-insensitive substring match on email or role name. It narrows `pagination.total` too, so the envelope always describes the filtered list.'),
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
                    self::queryParam('sort', 'string', 'Sort key: `name`, `description`, or `created` (default, newest first). `permissionCount` is not offered — it is an aggregate the roles screen deliberately does not sort by. An unrecognised key falls back to the default rather than erroring.'),
                    self::queryParam('dir', 'string', 'Sort direction, `asc` or `desc`. Anything else is read as `asc`.'),
                    self::queryParam('q', 'string', 'Case-insensitive substring match on role name or description. It narrows `pagination.total` too.'),
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
                    self::queryParam('sort', 'string', 'Sort key: `name` (display name), `email`, or `assigned` (default, newest grant first). An unrecognised key falls back to the default rather than erroring.'),
                    self::queryParam('dir', 'string', 'Sort direction, `asc` or `desc`. Anything else is read as `asc`.'),
                    self::queryParam('q', 'string', 'Case-insensitive substring match on a holder\'s display name or email. It narrows `pagination.total`, so a searched list\'s total is the number of MATCHING holders and no longer the role\'s headcount.'),
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
            // #990: the same slug `GET /api/roles/{id}/permissions` uses — what
            // permissions exist and which a role holds are two halves of one
            // question, and a caller who may see the second and not the first
            // cannot read a role editor. Mirrors public/index.php; the spec and
            // the live router must agree on the gate.
            self::permissionRoute('GET', '/api/permissions', CorePermissions::PERMISSIONS_READ, [
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
     * Tenant management, gated on the seeded `tenants:*` permissions rather than
     * the bare `admin` role (#990) so a deployment that renamed or restructured
     * its administrative role keeps its own tenants screen. Mirrors the wiring in
     * public/index.php — the spec and the live router must agree on the gate.
     *
     * `tenants:read` was held by NOBODY until migration 138; that grant is what
     * keeps `GET /api/tenants` reachable after the re-gate.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function tenantRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/tenants', CorePermissions::TENANTS_READ, [
                'summary' => 'List tenants (system tenant sees all; others see their own)',
                'tags' => ['tenants'],
                'responses' => [
                    200 => self::jsonResponse('Visible tenants with user counts', 'TenantListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/tenants', CorePermissions::TENANTS_WRITE, [
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
            self::permissionRoute('PATCH', '/api/tenants/{id:\d+}', CorePermissions::TENANTS_WRITE, [
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
            self::permissionRoute('DELETE', '/api/tenants/{id:\d+}', CorePermissions::TENANTS_DELETE, [
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
     * TIME WINDOWS (#1070) — named, non-overlapping periods a tenant's data can
     * be scoped to and rolled up by, and which can be closed like a set of books.
     *
     * Four permissions rather than the usual pair: reading, writing, CLOSING and
     * REOPENING are four authorities, and an institution will want the last held
     * by fewer people than the third.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function timeWindowRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/time-window-types', 'time_windows:read', [
                'summary' => "List the tenant's period kinds",
                'description' => 'The tenant\'s own vocabulary of period kinds, ordered by key. '
                    . '`parent_type_id` is how a kind says which kind it nests inside — a sub-period '
                    . 'inside a period — and depth is derived from it rather than stored.',
                'tags' => ['time-windows'],
                'responses' => [
                    200 => self::jsonResponse("The tenant's period vocabulary", 'TimeWindowTypeListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/time-window-types/catalog', 'time_windows:read', [
                'summary' => "List the period kinds declared in code, with this tenant's adoption state",
                'description' => 'Core and plugin declarations. A plugin\'s keys are namespaced under '
                    . 'the plugin (`acme:growing_season`); adopting one with POST '
                    . '/api/v1/time-window-types copies its declared label and nesting in as the '
                    . "tenant's starting values. A declaration says nothing about WHEN a period runs — "
                    . 'boundaries are authored per period, never derived from a calendar.',
                'tags' => ['time-windows'],
                'responses' => [
                    200 => self::jsonResponse('The declared catalogue', 'TimeWindowTypeCatalogResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/time-window-types', 'time_windows:write', [
                'summary' => 'Author a new period kind, or adopt a declared one',
                'tags' => ['time-windows'],
                'request' => 'TimeWindowTypeCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created kind', 'TimeWindowTypeResponse'),
                    409 => self::errorResponse('The tenant already holds this key'),
                    422 => self::errorResponse(
                        'Malformed key, a namespaced key no plugin declares, the reserved key `none`, '
                        . 'or a parent that is not a kind in this tenant'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/time-window-types/{id:\d+}', 'time_windows:write', [
                'summary' => 'Relabel a period kind, or change what it nests inside',
                'description' => 'The `key` is immutable — code binds to it, so editing it in place '
                    . 'would silently repoint every reference at a kind that no longer exists. A '
                    . 'nesting change that would close a loop is refused.',
                'tags' => ['time-windows'],
                'request' => 'TimeWindowTypeUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated kind', 'TimeWindowTypeResponse'),
                    404 => self::errorResponse('Time window type not found'),
                    422 => self::errorResponse('No updatable field supplied, or a nesting loop'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/time-window-types/{id:\d+}', 'time_windows:write', [
                'summary' => 'Delete a period kind',
                'description' => 'Refused, never forced, while any period is of this kind or any kind '
                    . 'nests inside it. A period is what records were scoped to and rolled up by, and '
                    . 'a vocabulary edit does not get to destroy one.',
                'tags' => ['time-windows'],
                'responses' => [
                    200 => self::jsonResponse('Deleted', self::dataEnvelope(self::object(
                        ['deleted' => ['type' => 'boolean']],
                        ['deleted']
                    ))),
                    404 => self::errorResponse('Time window type not found'),
                    409 => self::errorResponse('Periods are of this kind, or kinds nest inside it'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/time-windows', 'time_windows:read', [
                'summary' => 'List periods, and resolve which one contains a date',
                'description' => '`?type_id=` with `?on=` IS the resolution question — "which period of '
                    . 'this kind contains this date" — and answers with zero or one period. Zero is a '
                    . 'real answer: no period covers that date, and no nearest match is invented, '
                    . 'because attributing a record to a period it does not belong to is worse than '
                    . 'leaving it unattributed. Ordered by `starts_on`, never by id: a period entered '
                    . 'out of order has a higher id than periods preceding it.',
                'tags' => ['time-windows'],
                'parameters' => [
                    self::queryParam('type_id', 'integer', 'Restrict to one period kind.'),
                    self::queryParam('state', 'string', 'Restrict to `open` or `closed`.'),
                    self::queryParam('on', 'string', 'Keep only periods containing this `YYYY-MM-DD` date.'),
                    self::queryParam('parent_id', 'integer', 'Restrict to periods nesting inside this one.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The matching periods', 'TimeWindowListResponse'),
                    422 => self::errorResponse('A malformed filter, or a kind this tenant does not have'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/time-windows', 'time_windows:write', [
                'summary' => 'Define a period, with explicit boundaries',
                'description' => 'Boundaries are AUTHORED and inclusive at both ends; nothing derives '
                    . 'them from a month, a quarter or a parent\'s length. Two periods of one kind may '
                    . 'not overlap, because a date has to belong to exactly one of them, and a nested '
                    . "period must sit inside its parent's range and be of the kind its own kind nests "
                    . 'inside.',
                'tags' => ['time-windows'],
                'request' => 'TimeWindowCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created period', 'TimeWindowResponse'),
                    422 => self::errorResponse(
                        'Malformed dates, an overlap with another period of the same kind, or a parent '
                        . 'that is the wrong kind, closed, or does not contain these dates'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/time-windows/{id:\d+}', 'time_windows:read', [
                'summary' => 'Get one period, with its seal trail',
                'description' => 'The trail travels with the period because the two facts are never '
                    . 'wanted apart: "is this closed" is half an answer without "and has it ever been '
                    . 'reopened, by whom, and why".',
                'tags' => ['time-windows'],
                'responses' => [
                    200 => self::jsonResponse('The period and its trail', 'TimeWindowDetailResponse'),
                    404 => self::errorResponse('Time window not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/time-windows/{id:\d+}', 'time_windows:write', [
                'summary' => 'Relabel a period, or move its boundaries',
                'description' => 'A CLOSED period is refused: moving the boundaries of a sealed period '
                    . 'is the most effective way there is to unseal it without leaving a trace, since '
                    . 'the state still reads closed while records that were inside it no longer are. '
                    . 'Reopen it first, on the record.',
                'tags' => ['time-windows'],
                'request' => 'TimeWindowUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated period', 'TimeWindowResponse'),
                    404 => self::errorResponse('Time window not found'),
                    422 => self::errorResponse(
                        'The period is closed, the dates overlap another of the same kind, or the '
                        . 'change would leave a nested period outside it'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/time-windows/{id:\d+}/close-report', 'time_windows:read', [
                'summary' => 'What closing this period would seal',
                'description' => 'The difference between a control and a trap. `open_children` is '
                    . 'structural and BLOCKS the close; `unfinished` is contributed by whatever holds '
                    . 'records in the period (through the `time_window.close_report` filter hook) and '
                    . 'does NOT block — it is told to the person, who decides. `unfinished_reported` '
                    . 'distinguishes "nothing is unfinished" from "nothing is tracking it", which are '
                    . 'both an empty list and only one of which is an all-clear. Gated on read rather '
                    . 'than close: looking changes nothing.',
                'tags' => ['time-windows'],
                'responses' => [
                    200 => self::jsonResponse('The report', 'TimeWindowCloseReportResponse'),
                    404 => self::errorResponse('Time window not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/time-windows/{id:\d+}/close', 'time_windows:close', [
                'summary' => 'Close a period — seal it',
                'description' => 'Refused while any period nested inside it is still open, naming them, '
                    . 'because a sealed period containing an accruing one is not a seal. Repeat with '
                    . '`cascade: true` to close them in the same act; each gets its own trail row '
                    . 'marked as having come from this one, so the trail distinguishes an act somebody '
                    . 'performed from a consequence of one they performed elsewhere. Closing an '
                    . 'already-closed period is a no-op rather than an error. The response carries the '
                    . 'report the close was made against — what was still unfinished at the moment of '
                    . 'sealing is unrecoverable once the work moves on.',
                'tags' => ['time-windows'],
                'request' => 'TimeWindowCloseRequest',
                'responses' => [
                    200 => self::jsonResponse('The sealed period and what it sealed', 'TimeWindowCloseResponse'),
                    404 => self::errorResponse('Time window not found'),
                    422 => self::errorResponse('Periods nested inside this one are still open'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/time-windows/{id:\d+}/reopen', 'time_windows:reopen', [
                'summary' => 'Reopen a closed period, on the record',
                'description' => 'A REASON IS REQUIRED and is recorded permanently. Refusing reopening '
                    . 'outright sounds safer and is not: an institution that must correct a sealed '
                    . 'period will do it anyway, somewhere this platform cannot see, and a reopen that '
                    . 'names who, when and why is strictly better than one that leaves no record. '
                    . 'Does not reopen nested periods, and is refused while the period containing this '
                    . 'one is closed — reopen that first.',
                'tags' => ['time-windows'],
                'request' => 'TimeWindowReopenRequest',
                'responses' => [
                    200 => self::jsonResponse('The reopened period and its trail', 'TimeWindowDetailResponse'),
                    404 => self::errorResponse('Time window not found'),
                    422 => self::errorResponse('No reason given, or the period containing this one is closed'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * FORMS (migrations 127/128) — tenant-authored forms, their fields, and the
     * submissions made against them.
     *
     * THREE gates rather than the usual read/write pair, because there are three
     * audiences and two of them barely overlap: `forms:manage` AUTHORS (an act of
     * organisational policy), `forms:submit` FILLS IN (the everyday act of the
     * largest audience in the tenant), `forms:read` READS WHAT CAME BACK (the
     * approver's job). Folding `:submit` into `:read` is the tempting fold and the
     * wrong one — it would mean letting somebody file a request also lets them
     * read everybody else's.
     *
     * `/render` is gated on `forms:submit`, NOT `forms:read`, and that is the one
     * assignment worth checking twice: its response carries the CALLER'S OWN
     * prefilled details, so it is personalised, and gating it on the
     * catalogue-reading permission would hand that payload to the wrong audience
     * while denying it to the right one.
     *
     * There is deliberately no DELETE for a form (archive instead — a form is what
     * a submission was an answer TO) and none for a submission (submit again — it
     * is what somebody declared while other people acted on it).
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function formRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/forms', 'forms:read', [
                'summary' => "List the tenant's forms",
                'description' => 'Newest first. `?status=` narrows to `draft`, `published` or '
                    . '`archived`. Each row carries `available_transitions` and `accepts_submissions`, '
                    . 'both DERIVED from the status, so a client rendering the lifecycle controls does '
                    . 'not have to hold a second copy of the transition table.',
                'tags' => ['forms'],
                'parameters' => [
                    self::queryParam('status', 'string', 'Restrict to one lifecycle state.'),
                    self::queryParam('limit', 'integer', 'Page size (default 100, max 500).'),
                    self::queryParam('offset', 'integer', 'Rows to skip.'),
                ],
                'responses' => [
                    200 => self::jsonResponse("The tenant's forms", 'FormListResponse'),
                    422 => self::errorResponse('An unrecognised status filter'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/forms', 'forms:manage', [
                'summary' => 'Author a new form',
                'description' => 'Always created as a `draft`: a form is never born live, because one '
                    . 'with no fields yet that accepted submissions would collect empty ones. '
                    . '`route_template_id` is what makes submissions CIRCULATE — pointed at a design '
                    . 'from /api/v1/document-route-templates, every submission becomes a document routed '
                    . 'through the existing engine. Omitted, the form collects and stops there.',
                'tags' => ['forms'],
                'request' => 'FormCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created form', 'FormResponse'),
                    422 => self::errorResponse(
                        'A malformed or duplicate key, a name in no language, or a route template '
                        . 'this tenant does not have'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/forms/{id:\d+}', 'forms:read', [
                'summary' => 'Get one form, with its fields and its submission count',
                'description' => 'The submission count travels with the form because an author about '
                    . 'to change a published one needs to know that people have already answered it — '
                    . 'and a count they have to go and fetch is a count they will not fetch.',
                'tags' => ['forms'],
                'responses' => [
                    200 => self::jsonResponse('The form, its fields and its counts', 'FormDetailResponse'),
                    404 => self::errorResponse('Form not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/forms/{id:\d+}', 'forms:manage', [
                'summary' => 'Rename a form, retitle it, or change where its submissions go',
                'description' => '`form_key` is immutable and a body carrying one is REFUSED, not '
                    . 'ignored: code and links bind to the key, so editing it in place would silently '
                    . 'repoint every reference at a form that no longer exists. `status` is likewise '
                    . 'refused — it moves through /publish and /archive, which are acts rather than '
                    . 'attribute assignments.',
                'tags' => ['forms'],
                'request' => 'FormUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated form', 'FormResponse'),
                    404 => self::errorResponse('Form not found'),
                    422 => self::errorResponse('An immutable field, no updatable field, or an unknown route template'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/forms/{id:\d+}/publish', 'forms:manage', [
                'summary' => 'Make the form live, and mint a version',
                'description' => 'A form with no fields is REFUSED: publishing one would produce a live '
                    . 'form that collects nothing, renders as an empty page with a submit button, and '
                    . 'reports every submission as successful. Publishing increments `version`, and '
                    . 'every submission stamps the version it was answered against — which lets a '
                    . 'reader SEE drift between an old answer set and today\'s fields, but does not by '
                    . 'itself reconstruct the old field list. Idempotent: asking for the state the form '
                    . 'is already in returns it rather than erroring.',
                'tags' => ['forms'],
                'responses' => [
                    200 => self::jsonResponse('The published form', 'FormResponse'),
                    404 => self::errorResponse('Form not found'),
                    409 => self::errorResponse('The form moved under this request — reload and retry'),
                    422 => self::errorResponse('The transition is not allowed from here, or the form has no fields'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/forms/{id:\d+}/archive', 'forms:manage', [
                'summary' => 'Stop accepting submissions',
                'description' => 'Everything already submitted stays exactly where it is; only the door '
                    . 'closes. REVERSIBLE — republishing is allowed, because retiring a form at the end '
                    . 'of a cycle and wanting it back at the start of the next one is the ordinary case. '
                    . 'There is no DELETE at all: a form is what somebody\'s submission was an answer '
                    . 'TO, and destroying it leaves every submission as a bag of keys with nothing to '
                    . 'say what they meant.',
                'tags' => ['forms'],
                'responses' => [
                    200 => self::jsonResponse('The archived form', 'FormResponse'),
                    404 => self::errorResponse('Form not found'),
                    409 => self::errorResponse('The form moved under this request — reload and retry'),
                    422 => self::errorResponse('The transition is not allowed from here'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/forms/{id:\d+}/render', 'forms:submit', [
                'summary' => 'The form as it should be DRAWN for the caller, with their prefilled values',
                'description' => 'Fields in order, grouped into derived `sections`, plus `prefill` — '
                    . 'values resolved SERVER-SIDE from the CALLER\'S own saved details so they do not '
                    . 'retype what the organisation already knows. Prefill is a suggestion, never an '
                    . 'answer: nothing is recorded until the person submits. `unresolved_prefill` names '
                    . 'any field whose declared source nothing in this install stores, so an empty box '
                    . 'is distinguishable from a bug. A form that is not accepting submissions still '
                    . 'renders — `accepts_submissions` says which — so a person following a link to an '
                    . 'archived form learns it closed rather than that it never existed.',
                'tags' => ['forms'],
                'responses' => [
                    200 => self::jsonResponse('The form, drawn for this caller', 'FormRenderResponse'),
                    404 => self::errorResponse('Form not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/forms/{id:\d+}/public-link', 'forms:manage', [
                'summary' => 'Open this form to people who have no account, minting its public address',
                'description' =>
                    'OPT-IN and OFF BY DEFAULT on every form: `public_enabled` is only ever true '
                    . 'because of this call. It mints a 256-bit random slug and returns the absolute '
                    . '`public_url` built from it — the ONLY credential the public endpoints have, '
                    . 'which is why it is random rather than derived from the form id or key: a '
                    . 'guessable address makes the whole catalogue of an install\'s forms walkable '
                    . 'with curl. '
                    . 'REFUSED (422) on a form that is not `published` (a link to one answers 404 to '
                    . 'everybody who follows it), and on a form carrying a `profile_ref` or `ou_ref` '
                    . 'field — the reference kinds would make the public submit a MEMBERSHIP ORACLE, '
                    . 'since the existence check behind them reveals whether a given id belongs to '
                    . 'this organisation. A `file` field is ACCEPTED (it was refused until migration '
                    . '134 only because no anonymous upload route existed; a file input asks the '
                    . 'tenant\'s data nothing, so it cannot answer anything about it). '
                    . '`opens_at` / `closes_at` are optional; either may be null for "no boundary on '
                    . 'this side". They are naive local date-times in the instance\'s own clock, and a '
                    . 'UTC offset is REFUSED rather than silently applied. '
                    . 'Re-opening after a close mints a DIFFERENT address: a withdrawn link stays '
                    . 'withdrawn.',
                'tags' => ['forms'],
                'request' => 'FormPublicLinkRequest',
                'responses' => [
                    200 => self::jsonResponse('The form, with its new public link', 'FormResponse'),
                    404 => self::errorResponse('Form not found'),
                    409 => self::errorResponse('The form already has a public link, or it moved under this request'),
                    422 => self::errorResponse(
                        'The form is not published, carries a field a stranger could not answer, '
                        . 'or the window is malformed'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/forms/{id:\d+}/public-link', 'forms:manage', [
                'summary' => "Close this form's public link",
                'description' =>
                    'The slug is DESTROYED, not parked beside a disabled flag, so the old address is '
                    . 'unresolvable by construction rather than by a check somebody could remove. The '
                    . 'window dates go with it. IDEMPOTENT: closing a link that is already closed is a '
                    . '200, because a client that lost a response must be able to retry; '
                    . '`meta.closed` says whether this call was the one that changed anything. '
                    . 'Submissions already received are untouched.',
                'tags' => ['forms'],
                'responses' => [
                    200 => self::jsonResponse('The form, with no public link', 'FormPublicLinkClosedResponse'),
                    404 => self::errorResponse('Form not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/forms/{id:\d+}/uploads', 'forms:submit', array_merge([
                'summary' => 'Attach a file to a form you are filling in',
                'description' =>
                    'MULTIPART, one part named `file`. Returns the `reference` a `file` answer '
                    . 'carries, plus the filename, the SNIFFED content type, the byte size and the '
                    . 'server\'s SHA-256 of what it stored. '
                    . 'Gated `forms:submit` — the same permission as the submit itself, because '
                    . 'uploading is half of answering. '
                    . 'ACCEPTS application/pdf, image/png and image/jpeg ONLY, decided by the LEADING '
                    . 'BYTES: a declared Content-Type that contradicts the bytes is a 422, and a '
                    . 'declared type is never what gets stored. Office formats are absent on purpose — '
                    . 'a .docx is indistinguishable from any other ZIP by magic bytes. '
                    . 'MAXIMUM 10 MiB. '
                    . 'REFUSED (422) on a form that is not accepting submissions, and on a form with '
                    . 'no `file` field — so a broad permission cannot be aimed at arbitrary form ids '
                    . 'as a way into a tenant\'s storage. '
                    . 'THROTTLED to 20 uploads per caller per hour. '
                    . 'THE UPLOAD IS SINGLE-USE and expires: it is spent by the first submission that '
                    . 'names it, and anything never submitted is deleted by the '
                    . '`form-uploads:sweep` retention job (24 h by default).',
                'tags' => ['forms'],
                'responses' => [
                    201 => self::jsonResponse('The stored file, and the reference to answer with', 'FormUploadResponse'),
                    400 => self::errorResponse('No file part, or the multipart body could not be read'),
                    404 => self::errorResponse('Form not found'),
                    422 => self::errorResponse(
                        'Too large, not an accepted kind, the form asks for no file, '
                        . 'or the form is not accepting submissions'
                    ),
                    429 => self::errorResponse('Too many uploads from this caller'),
                    503 => self::errorResponse('The file could not be stored'),
                ] + self::authErrors(),
            ], self::formUploadMultipartBody(
                'The file to attach. PDF, PNG or JPEG, decided by MAGIC BYTES rather than by '
                . 'filename or Content-Type. Maximum 10 MiB.'
            ))),
            [
                'method' => 'POST',
                'path' => '/api/public/forms/{slug}/uploads',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => array_merge([
                    'summary' => 'Attach a file to a publicly-opened form (PUBLIC, unauthenticated, rate-limited)',
                    'description' =>
                        'The anonymous half of the upload above, and the route that made `file` fields '
                        . 'servable on a public form at all. '
                        . 'A file input is NOT the membership oracle a person or unit picker is: it '
                        . 'offers no list, resolves no id against this organisation, and returns one '
                        . 'opaque reference to the caller\'s own bytes — so there is no question about '
                        . 'the tenant it can be asked. '
                        . 'THE TENANT IS RESOLVED FROM THE SLUG, and every reason there is no publicly '
                        . 'served form behind it collapses to the SAME 404 as the render and the '
                        . 'submit. '
                        . 'BOUNDED, because what a stranger can spend here is storage: 10 uploads per '
                        . 'IP per hour, 400 per form per hour across all addresses, and a size ceiling '
                        . 'of 5 MiB — HALF the authenticated one, so bytes-per-address-per-hour is '
                        . 'what is capped rather than just the count. Same three accepted kinds, same '
                        . 'magic-byte check. '
                        . 'Anything never submitted is deleted by the retention sweep, so an abandoned '
                        . 'upload costs a day of storage rather than a permanent one.',
                    'tags' => ['forms'],
                    'responses' => [
                        201 => self::jsonResponse('The stored file, and the reference to answer with', 'FormUploadResponse'),
                        400 => self::errorResponse('No file part, or the multipart body could not be read'),
                        404 => self::errorResponse('No publicly-open form is served at this address'),
                        422 => self::errorResponse(
                            'Too large, not an accepted kind, the form asks for no file, '
                            . 'or the form is outside its submission window'
                        ),
                        429 => self::errorResponse('Too many uploads from this address, or for this form'),
                        503 => self::errorResponse('Temporarily unavailable'),
                    ],
                ], self::formUploadMultipartBody(
                    'The file to attach. PDF, PNG or JPEG, decided by MAGIC BYTES rather than by '
                    . 'filename or Content-Type. Maximum 5 MiB on this public surface.'
                )),
            ],
            [
                'method' => 'GET',
                'path' => '/api/public/forms/{slug}',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Render a publicly-opened form (PUBLIC, unauthenticated, rate-limited)',
                    'description' =>
                        'PUBLIC and unauthenticated by design: the caller is somebody outside the '
                        . 'organisation — an applicant, a supplier, a member of the public — who has no '
                        . 'account and does not need one. '
                        . 'THE TENANT IS RESOLVED FROM THE SLUG, never from a header, a query parameter '
                        . 'or the Host — all of which are values this caller chooses. '
                        . 'A malformed slug, an unknown slug, a form whose link was closed, and a form '
                        . 'that is not published all produce THE SAME 404 with the same sentence, so '
                        . 'this endpoint cannot be asked which slugs name a real form or whether an '
                        . 'organisation uses public forms at all. '
                        . 'The response carries NO id, tenant id, form key, author, route template, '
                        . 'submission count, status, version or prefill — an anonymous caller has no '
                        . 'saved details for the platform to pre-fill, and nothing about how the '
                        . 'organisation works is disclosed. '
                        . 'Person and unit fields are omitted from the field list, for the reason '
                        . 'POST /api/v1/forms/{id}/public-link refuses them. FILE fields ARE served: '
                        . 'attach the bytes at POST /api/v1/public/forms/{slug}/uploads first and put '
                        . 'the returned `reference` in the answer. '
                        . 'A form OUTSIDE its submission window still renders, with '
                        . '`accepts_submissions: false` and the window dates, so somebody holding a '
                        . 'genuine link is told they are early or late rather than that the link is '
                        . 'wrong.',
                    'tags' => ['forms'],
                    'responses' => [
                        200 => self::jsonResponse('The form, as a stranger may see it', 'PublicFormResponse'),
                        404 => self::errorResponse('No publicly-open form is served at this address'),
                        429 => self::errorResponse('Too many attempts from this address'),
                        503 => self::errorResponse('Temporarily unavailable'),
                    ],
                ],
            ],
            [
                'method' => 'POST',
                'path' => '/api/public/forms/{slug}/submissions',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Submit a publicly-opened form (PUBLIC, unauthenticated, rate-limited)',
                    'description' =>
                        'The answers arrive under `data`, keyed by field key, and NOTHING ELSE in the '
                        . 'body is read — a body that could also set `submitted_by_profile_id`, '
                        . '`form_version` or a route template would let an anonymous stranger sign a '
                        . 'declaration in somebody\'s name or aim it at a flow the organisation did not '
                        . 'choose. '
                        . 'The submission is recorded with NO SUBMITTER '
                        . '(`form_submissions.submitted_by_profile_id` is NULL — no sentinel profile, '
                        . 'because a fake person is something every membership and permission check '
                        . 'would have to know to special-case). '
                        . 'It BECOMES A DOCUMENT and circulates through the tenant\'s existing routing '
                        . 'engine exactly as an internal submission does, which is safe because the '
                        . 'caller cannot name a route template: it lives on the FORM, is set only by '
                        . '`forms:manage`, and is never read from a request body. '
                        . 'Throttled per IP and per form. '
                        . 'The response is a receipt, not the submission row: no id, no document id, '
                        . 'no tenant id.',
                    'tags' => ['forms'],
                    'request' => 'FormSubmissionCreateRequest',
                    'responses' => [
                        201 => self::jsonResponse('Received', 'PublicFormSubmissionResponse'),
                        404 => self::errorResponse('No publicly-open form is served at this address'),
                        422 => self::errorResponse(
                            'An answer failed validation, or the form is outside its submission window'
                        ),
                        429 => self::errorResponse('Too many attempts from this address, or for this form'),
                        503 => self::errorResponse('Temporarily unavailable'),
                    ],
                ],
            ],
            self::permissionRoute('GET', '/api/form-fields', 'forms:read', [
                'summary' => "One form's fields, addressed by query param",
                'description' => 'The same list as GET /api/v1/forms/{id}/fields, reachable by '
                    . '`?form_id=` so a master-detail picker can drive it — a data-bound block\'s '
                    . 'params append QUERY params to a fixed source and cannot fill a PATH segment. '
                    . 'This flat form exists for READS only; every write stays nested under the form, '
                    . 'which is what makes a delete refuse when the field belongs to a different one. '
                    . 'An absent or unknown `form_id` returns an empty list, not a 422: the picker '
                    . 'renders before anybody has chosen.',
                'tags' => ['forms'],
                'parameters' => [
                    self::queryParam('form_id', 'integer', 'The form whose fields to return.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The fields, with the builder vocabularies', 'FormFieldListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/forms/{id:\d+}/fields', 'forms:read', [
                'summary' => "A form's fields, in authoring order",
                'description' => 'Ordered by `position`, then `id` — the id tie-break makes the sequence '
                    . 'TOTAL, since `position` carries no unique index (a drag-reorderable ordinal must '
                    . 'not, or a two-field swap becomes a three-statement dance). `meta` carries the '
                    . 'vocabularies a builder renders its pickers from, so a client cannot hold a stale '
                    . 'copy of the field kinds or the prefill sources.',
                'tags' => ['forms'],
                'responses' => [
                    200 => self::jsonResponse('The fields, with the builder vocabularies', 'FormFieldListResponse'),
                    404 => self::errorResponse('Form not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/forms/{id:\d+}/fields', 'forms:manage', [
                'summary' => 'Add a field to a form',
                'description' => 'Appended AFTER the current maximum position unless one is given: a '
                    . 'builder that adds a field expects it at the end, where the author is looking. '
                    . '`select` and `multiselect` require a non-empty `options` list; `profile_ref` and '
                    . '`ou_ref` accept none, because their choices are RESOLVED from the tenant\'s live '
                    . 'people and units rather than authored — a pasted roster is wrong by the end of '
                    . 'the month, still renders, and still reports success. `prefill_source` names a '
                    . 'rule for reaching the submitter\'s own details and is resolved at render time, '
                    . 'never stored.',
                'tags' => ['forms'],
                'request' => 'FormFieldCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created field', 'FormFieldResponse'),
                    404 => self::errorResponse('Form not found'),
                    409 => self::errorResponse('The form is archived, so its fields cannot be changed'),
                    422 => self::errorResponse(
                        'A malformed or duplicate key, an unknown kind or prefill source, a '
                        . 'choice-bearing field with no choices, or an invalid validation pattern'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/forms/{id:\d+}/fields', 'forms:manage', [
                'summary' => "Save a form's whole field set at once",
                'description' => 'Authoring a form is one act of composition, not a sequence of '
                    . 'independent single-field decisions — an editor that adds, reorders and deletes '
                    . 'question cards in place cannot rest on per-field calls without inventing a '
                    . 'client-side transaction and hoping every leg lands. Reconciled by `field_key`, '
                    . 'which is the stable identity a recorded ANSWER refers to and is deliberately not '
                    . 'updatable: a key present in both the payload and the stored set is the SAME '
                    . 'question, edited or moved, while a stored key absent from the payload is a '
                    . 'question withdrawn — and its answers stay recorded but stop having a label. '
                    . 'Matching on position instead would rename every question below an insertion and '
                    . 'silently reattribute its answers. Position comes from the order sent, and the '
                    . 'whole reconciliation is one transaction.',
                'tags' => ['forms'],
                'request' => 'FormFieldSetRequest',
                'responses' => [
                    200 => self::jsonResponse('The resulting field set, in order', 'FormFieldListResponse'),
                    404 => self::errorResponse('Form not found'),
                    409 => self::errorResponse('The form is archived, so its fields cannot be changed'),
                    422 => self::errorResponse(
                        'A malformed or duplicated key, an unknown kind or prefill source, a '
                        . 'choice-bearing field with no choices, or an invalid validation pattern'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/forms/{id:\d+}/fields/{fieldId:\d+}', 'forms:manage', [
                'summary' => 'Edit a field, or move it in the order',
                'description' => '`field_key` is immutable and a body carrying one is REFUSED: answers '
                    . 'already submitted are keyed by it, so renaming a key in place does not rename '
                    . 'the answers, it ORPHANS them, silently, while reporting success. `field_type` '
                    . 'MAY change — fixing text to textarea is a real edit — and options are '
                    . 're-validated against the new kind in the same request, so a select demoted to '
                    . 'text cannot keep choices nothing will draw.',
                'tags' => ['forms'],
                'request' => 'FormFieldUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated field', 'FormFieldResponse'),
                    404 => self::errorResponse('Form or field not found'),
                    409 => self::errorResponse('The form is archived, so its fields cannot be changed'),
                    422 => self::errorResponse('An immutable field, no updatable field, or an invalid value'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/forms/{id:\d+}/fields/{fieldId:\d+}', 'forms:manage', [
                'summary' => 'Take a field off a form',
                'description' => 'Answers already given to it are NOT deleted — they stay in the '
                    . 'submission and simply stop having a label. That is why an ARCHIVED form refuses '
                    . 'this: its fields are the only remaining explanation of what its submissions '
                    . 'answered. The field id is scoped to the form in the path, so a delete addressed '
                    . 'through the wrong form is a 404 rather than a cross-form deletion.',
                'tags' => ['forms'],
                'responses' => [
                    200 => self::jsonResponse('Deleted', self::dataEnvelope(self::object(
                        ['deleted' => ['type' => 'boolean']],
                        ['deleted']
                    ))),
                    404 => self::errorResponse('Form or field not found'),
                    409 => self::errorResponse('The form is archived, so its fields cannot be changed'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/forms/{id:\d+}/submissions', 'forms:submit', [
                'summary' => 'Submit a form',
                'description' => 'Answers arrive under `data`, keyed by field key; everything else in '
                    . 'the body is ignored, because a body that could also set `submitted_by_profile_id` '
                    . 'would let a caller sign a declaration in somebody else\'s name. On success the '
                    . 'submission ALSO becomes a core DOCUMENT, so it inherits routing, approvals, the '
                    . 'inbox, QR verification, artifacts and row-level visibility — and when the form '
                    . 'names a route template, that document starts circulating in the same '
                    . 'transaction. `meta.routed` says whether it did, so a client never tells somebody '
                    . 'their request is on its way when nothing is moving. `meta.ignored_keys` names '
                    . 'answers that matched no field (a stale client): they are dropped rather than '
                    . 'refused, so a race nobody caused does not discard everything the person typed.',
                'tags' => ['forms'],
                'request' => 'FormSubmissionCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The recorded submission', 'FormSubmissionCreateResponse'),
                    404 => self::errorResponse('Form not found'),
                    422 => self::errorResponse(
                        'The form is not accepting submissions, an answer failed validation, a '
                        . 'reference names no record in this tenant, or the form\'s route template '
                        . 'cannot be run as drawn'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/form-submissions', 'forms:read', [
                'summary' => "List the tenant's submissions",
                'description' => 'Newest first, optionally narrowed by `form_id` or `submitted_by`. '
                    . 'Each row carries the form key and name so a list renders without a round trip '
                    . 'per row. `document_id` is null for a submission to a form with no route template '
                    . '(it collected, it did not circulate) and for one whose document was later '
                    . 'deleted — both ordinary states, not failures.',
                'tags' => ['forms'],
                'parameters' => [
                    self::queryParam('form_id', 'integer', 'Restrict to one form.'),
                    self::queryParam('submitted_by', 'integer', 'Restrict to one submitter.'),
                    self::queryParam('limit', 'integer', 'Page size (default 50, max 200).'),
                    self::queryParam('offset', 'integer', 'Rows to skip.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The matching submissions', 'FormSubmissionListResponse'),
                    422 => self::errorResponse('A form this tenant does not have'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/form-submissions/{id:\d+}', 'forms:read', [
                'summary' => 'Get one submission, with the fields it was answering',
                'description' => 'The fields travel with the submission because the two are useless '
                    . 'apart — an answer of `41` means nothing without the field that says what was '
                    . 'asked. They are TODAY\'s fields, and `form_version_now` is returned beside the '
                    . 'submission\'s own `form_version` so a reader can SEE when the two do not line up '
                    . 'and knows they are looking at drift rather than at a bug.',
                'tags' => ['forms'],
                'responses' => [
                    200 => self::jsonResponse('The submission and its fields', 'FormSubmissionDetailResponse'),
                    404 => self::errorResponse('Submission not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/me/form-submissions', 'forms:submit', [
                'summary' => 'The caller\'s own submissions',
                'description' => 'Only ever the caller\'s rows — the ROUTE decides whose, not a query '
                    . 'param, so nothing a client omits or changes can widen it. Gated on '
                    . '`forms:submit` rather than `forms:read` because the rows already name exactly '
                    . 'one person, so a tenant-wide permission has nothing left to decide; requiring '
                    . 'the read permission would hide this from precisely the people whose submissions '
                    . 'are in it. A caller with no profile (a service principal) gets an empty list, '
                    . 'which is true rather than an authorization failure somebody has to investigate.',
                'tags' => ['forms'],
                'parameters' => [
                    self::queryParam('form_id', 'integer', 'Restrict to one form.'),
                    self::queryParam('limit', 'integer', 'Page size (default 50, max 200).'),
                    self::queryParam('offset', 'integer', 'Rows to skip.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The caller\'s submissions', 'FormSubmissionListResponse'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * The `multipart/form-data` request body BOTH form-upload routes declare.
     *
     * Under the key `request`, not `requestBody`: #954 records the cost of
     * getting that wrong — the branding uploads declared a body under the wrong
     * key, {@see SchemaGenerator::addOperation()} never read it, and both
     * operations published with no request body at all, so a generated client
     * could see the endpoint and had no way to learn the part is called `file`.
     *
     * ONE helper for two routes because the authenticated and public uploads
     * take the SAME part with the same name; only the size sentence differs, and
     * that is the argument. Two copies would eventually disagree about the field
     * name, which is the one thing a client cannot guess.
     *
     * @return array{request: array<string, mixed>}
     */
    private static function formUploadMultipartBody(string $description): array
    {
        return [
            'request' => [
                'required' => true,
                'content' => [
                    'multipart/form-data' => [
                        'schema' => self::object([
                            'file' => [
                                'type' => 'string',
                                'format' => 'binary',
                                'description' => $description,
                            ],
                        ], ['file']),
                    ],
                ],
            ],
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
     * CONVENING (#convening, migrations 130/131): deliberative bodies, their
     * meetings, and the decisions taken at them.
     *
     * THE ONE ROUTE WORTH READING FIRST is
     * `POST /api/v1/meetings/{id}/agenda/{itemId}/decision`. It is the only
     * endpoint in this subsystem that can move somebody ELSE's document: where
     * the agenda item carries a document and that document's route has reached
     * this body, the decision is applied through the existing routing engine —
     * `DocumentRouter::act()`, with a verdict, as a person the route actually
     * asked. Nothing here writes to a routing table.
     *
     * PERMISSIONS: `convening:read` for every read, `convening:manage` for the
     * secretarial acts (agenda, dates, invitations), `convening:decide` for
     * minuting a decision. Answering an INVITATION is unpermissioned — being
     * invited is the authorization, the same posture migration 113 takes on
     * acting on a route that reached you.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function conveningRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/convening-bodies', 'convening:read', [
                'summary' => "List the tenant's convening bodies",
                'description' => 'Active bodies first, then by key. A retired body stays readable — '
                    . 'its minute-book outlives its usefulness — but takes no new meetings.',
                'tags' => ['convening'],
                'parameters' => [
                    self::queryParam('active', 'string', 'Send `true` to list only active bodies.'),
                ],
                'responses' => [
                    200 => self::jsonResponse("The tenant's convening bodies", 'ConveningBodyListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/convening-bodies', 'convening:manage', [
                'summary' => 'Constitute a convening body',
                'description' => '`body_key` is immutable once set: every decision number the body '
                    . 'mints quotes it. `name` may be a plain string or an object of language code '
                    . 'to text — a body has as many real names as it has languages.',
                'tags' => ['convening'],
                'request' => 'ConveningBodyCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created body', 'ConveningBodyResponse'),
                    422 => self::errorResponse('A malformed or already-taken key, or an empty name'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/convening-bodies/{id:\d+}', 'convening:read', [
                'summary' => 'Get one body, with its current seats',
                'description' => 'The membership travels with the body because the two are never '
                    . 'wanted apart. `?history=true` includes PAST seats, which is how a decision '
                    . 'taken last March is attributed to the body as it was constituted then.',
                'tags' => ['convening'],
                'parameters' => [
                    self::queryParam('history', 'string', 'Send `true` to include seats that have ended.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The body and its members', 'ConveningBodyDetailResponse'),
                    404 => self::errorResponse('Convening body not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/convening-bodies/{id:\d+}', 'convening:manage', [
                'summary' => 'Rename a body, re-home it, retire it or revive it',
                'description' => '`body_key` is refused: decision numbers already quote it, so '
                    . 'editing it would leave them naming a body that no longer exists.',
                'tags' => ['convening'],
                'request' => 'ConveningBodyUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated body', 'ConveningBodyResponse'),
                    404 => self::errorResponse('Convening body not found'),
                    422 => self::errorResponse('No updatable field supplied, or an attempt to change body_key'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/convening-bodies/{id:\d+}', 'convening:manage', [
                'summary' => 'Delete a convening body',
                'description' => 'Refused, never forced, once the body has met: deleting it would '
                    . 'destroy agendas and decisions, some of which have already approved documents. '
                    . 'A body that has finished its work is deactivated.',
                'tags' => ['convening'],
                'responses' => [
                    200 => self::jsonResponse('Deleted', self::dataEnvelope(self::object(
                        ['deleted' => ['type' => 'boolean']],
                        ['deleted']
                    ))),
                    404 => self::errorResponse('Convening body not found'),
                    409 => self::errorResponse('The body has meetings on record'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/convening-bodies/{id:\d+}/members', 'convening:read', [
                'summary' => "List a body's seats",
                'tags' => ['convening'],
                'parameters' => [
                    self::queryParam('history', 'string', 'Send `true` to include seats that have ended.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The seats', 'ConveningBodyMemberListResponse'),
                    404 => self::errorResponse('Convening body not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/convening-bodies/{id:\d+}/members', 'convening:manage', [
                'summary' => 'Seat somebody on a body, or move the seat they hold',
                'description' => 'Appointing a current member to a different seat updates the seat '
                    . 'they already hold rather than closing it and opening another — a chair who '
                    . 'becomes secretary did not leave the body for an instant.',
                'tags' => ['convening'],
                'request' => 'ConveningBodyMemberRequest',
                'responses' => [
                    201 => self::jsonResponse('The body\'s current seats', 'ConveningBodyMemberListResponse'),
                    404 => self::errorResponse('Convening body not found'),
                    422 => self::errorResponse('A missing profile_id, or a seat outside the vocabulary'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute(
                'DELETE',
                '/api/convening-bodies/{id:\d+}/members/{profileId:\d+}',
                'convening:manage',
                [
                    'summary' => 'End somebody\'s seat on a body',
                    'description' => 'A DEPARTURE, not a deletion: the row is kept with an end date, '
                        . 'so a decision taken while they sat remains attributable to the body as it '
                        . 'was then.',
                    'tags' => ['convening'],
                    'responses' => [
                        200 => self::jsonResponse('The remaining seats', 'ConveningBodyMemberListResponse'),
                        404 => self::errorResponse('That person does not currently sit on this body'),
                    ] + self::authErrors(),
                ]
            ),

            self::permissionRoute('GET', '/api/meetings', 'convening:read', [
                'summary' => 'List meetings, narrowed by body and status',
                'description' => 'Most recent first, by id rather than by date: a draft has no date '
                    . 'at all, and ordering on a nullable column heaps every draft at whichever end '
                    . 'the engine sorts nulls.',
                'tags' => ['convening'],
                'parameters' => [
                    self::queryParam('body_id', 'integer', 'Restrict to one convening body.'),
                    self::queryParam('status', 'string', 'Comma-separated: draft, scheduled, held, cancelled.'),
                ],
                'responses' => [
                    200 => self::jsonResponse('The matching meetings', 'MeetingListResponse'),
                    422 => self::errorResponse('A malformed filter'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/meetings', 'convening:manage', [
                'summary' => 'Open a meeting on a body, in draft',
                'description' => 'Always `draft`, never straight to `scheduled`. Scheduling is its '
                    . 'own act with its own meaning ("this is fixed, tell people"), and a sitting '
                    . 'must not become scheduled as a side effect of somebody starting an agenda.',
                'tags' => ['convening'],
                'request' => 'MeetingCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created meeting', 'MeetingResponse'),
                    422 => self::errorResponse('No such body, or the body is not active'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/meetings/{id:\d+}', 'convening:read', [
                'summary' => 'Get one meeting, with its agenda, decisions and invitations',
                'description' => 'Everything behind one request, because nobody has ever wanted '
                    . 'three of the four. A screen that fetched them separately would render an '
                    . 'agenda before it knew which items had been decided.',
                'tags' => ['convening'],
                'responses' => [
                    200 => self::jsonResponse('The whole sitting', 'MeetingDetailResponse'),
                    404 => self::errorResponse('Meeting not found'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/meetings/{id:\d+}/schedule', 'convening:manage', [
                'summary' => 'Fix a date and a place; re-scheduling is the same call',
                'description' => 'When the sitting had already been announced, EVERYBODY holding an '
                    . 'invitation is told it moved — including the people who declined, because '
                    . 'somebody who could not make the old date may well make the new one.',
                'tags' => ['convening'],
                'request' => 'MeetingScheduleRequest',
                'responses' => [
                    200 => self::jsonResponse('The scheduled meeting', 'MeetingScheduleResponse'),
                    422 => self::errorResponse('A meeting that is held or cancelled, or an unreadable date'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/meetings/{id:\d+}/hold', 'convening:manage', [
                'summary' => 'Record that the meeting took place',
                'description' => 'Terminal: nothing un-holds a meeting, because decisions minuted at '
                    . 'it may already have advanced somebody\'s document. `held_at` is supplied '
                    . 'rather than stamped by the server — a body routinely minutes yesterday\'s '
                    . 'sitting, and the date chooses the year each decision number is minted under.',
                'tags' => ['convening'],
                'request' => 'MeetingHoldRequest',
                'responses' => [
                    200 => self::jsonResponse('The held meeting', 'MeetingResponse'),
                    422 => self::errorResponse('A meeting that is already held or was cancelled'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/meetings/{id:\d+}/cancel', 'convening:manage', [
                'summary' => 'Call off a meeting that has not happened',
                'description' => 'A state rather than a deletion: a called-off sitting is a fact the '
                    . 'minute-book needs, and deleting the row would take its agenda with it.',
                'tags' => ['convening'],
                'responses' => [
                    200 => self::jsonResponse('The cancelled meeting', 'MeetingResponse'),
                    422 => self::errorResponse('A meeting that has already been held'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/meetings/{id:\d+}/invitations', 'convening:manage', [
                'summary' => "Invite the body's current members",
                'description' => 'Membership is resolved NOW, not stored earlier — the same '
                    . 'rule-not-roster principle the routing engine enforces on its steps. '
                    . 'Idempotent: somebody already invited is not re-invited, not re-notified, and '
                    . 'does not have their answer reset, so this is safe to call again after a '
                    . 'person joins the body.',
                'tags' => ['convening'],
                'responses' => [
                    200 => self::jsonResponse('The meeting\'s invitations', 'MeetingInviteResponse'),
                    422 => self::errorResponse('A draft, held or cancelled meeting, or a body with no members'),
                ] + self::authErrors(),
            ]),
            // No helper: this is the one route in the subsystem with NO
            // permission, and `permissionRoute()` cannot express that. Spelled
            // out so the null is visible rather than defaulted.
            [
                'method' => 'POST',
                'path' => '/api/meetings/{id:\d+}/invitations/respond',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Accept, decline, or answer tentatively',
                    'description' => 'UNPERMISSIONED on purpose: being invited IS the authorization, '
                        . 'the same posture `/api/me/notifications` takes. The answering person comes '
                        . 'from the SESSION and never from the request body. `invited` is not among '
                        . 'the answers — it is the state the system puts the row in, and '
                        . '"un-answering" means nothing.',
                    'tags' => ['convening'],
                    'request' => 'MeetingInvitationRespondRequest',
                    'responses' => [
                        200 => self::jsonResponse('Your answer', 'MeetingInvitationResponse'),
                        403 => self::errorResponse('Answering an invitation requires a signed-in person'),
                        422 => self::errorResponse('Not an answer, or you hold no invitation to this meeting'),
                    ],
                ],
            ],
            self::permissionRoute('POST', '/api/meetings/{id:\d+}/agenda', 'convening:manage', [
                'summary' => 'Put an item — often a document — on a meeting\'s agenda',
                'description' => 'A draft or scheduled meeting accumulates items freely. Attaching '
                    . 'to a meeting that has ALREADY BEEN HELD is possible and must be asked for '
                    . '(`allow_held: true`): it asserts the body considered the item at a sitting '
                    . 'that is over, which is right for a paper tabled on the day and wrong if you '
                    . 'meant the next meeting. A cancelled meeting is refused outright.',
                'tags' => ['convening'],
                'request' => 'MeetingAgendaItemCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The agenda item', 'MeetingAgendaItemResponse'),
                    404 => self::errorResponse('Meeting not found'),
                    422 => self::errorResponse('A cancelled meeting, or a held meeting without allow_held'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/meetings/{id:\d+}/agenda/order', 'convening:manage', [
                'summary' => "Rewrite the whole agenda's order",
                'description' => 'The list must name every item on the agenda exactly once. A '
                    . 'partial list describes an order that omits items, and both readings of that '
                    . '— leave them where they are, or append them — are guesses.',
                'tags' => ['convening'],
                'request' => 'MeetingAgendaReorderRequest',
                'responses' => [
                    200 => self::jsonResponse('The reordered agenda', 'MeetingAgendaItemListResponse'),
                    404 => self::errorResponse('Meeting not found'),
                    422 => self::errorResponse('The list is not a permutation of this meeting\'s items'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute(
                'DELETE',
                '/api/meetings/{id:\d+}/agenda/{itemId:\d+}',
                'convening:manage',
                [
                    'summary' => 'Remove an agenda item, closing the gap it leaves',
                    'description' => 'Refused once a decision has been recorded against the item: a '
                        . 'decision may already have approved a document, and deleting what it was '
                        . 'about would leave it quoting an item nobody can read.',
                    'tags' => ['convening'],
                    'responses' => [
                        200 => self::jsonResponse('Deleted', self::dataEnvelope(self::object(
                            ['deleted' => ['type' => 'boolean']],
                            ['deleted']
                        ))),
                        404 => self::errorResponse('Agenda item not found on this meeting'),
                        409 => self::errorResponse('A decision has been recorded against this item'),
                    ] + self::authErrors(),
                ]
            ),
            self::permissionRoute(
                'POST',
                '/api/meetings/{id:\d+}/agenda/{itemId:\d+}/decision',
                'convening:decide',
                [
                    'summary' => "Minute the body's decision, and drive the document's approval route",
                    'description' => 'THE ONE ENDPOINT HERE THAT CAN MOVE SOMEBODY ELSE\'S DOCUMENT. '
                        . 'One call allocates the decision number from the platform counter, applies '
                        . 'the verdict through the existing routing engine, and writes the decision '
                        . 'row — all three in one transaction, in that order, so a decision can never '
                        . 'claim an approval the engine refused. Approved advances or fires the '
                        . 'approve edge; rejected fires the reject edge or goes nowhere; a deferral '
                        . 'is recorded and moves nothing. The `routing` object always says what '
                        . 'actually happened, including the ordinary cases where nothing did.',
                    'tags' => ['convening'],
                    'request' => 'MeetingDecisionRequest',
                    'responses' => [
                        201 => self::jsonResponse('The decision, and what it did', 'MeetingDecisionResponse'),
                        404 => self::errorResponse('Agenda item not found on this meeting'),
                        422 => self::errorResponse(
                            'A meeting that has not been held, a verdict outside the vocabulary, or a '
                            . 'refusal from the routing engine (returned in its own words)'
                        ),
                    ] + self::authErrors(),
                ]
            ),

            self::permissionRoute('GET', '/api/agenda-items', 'convening:read', [
                'summary' => "One meeting's agenda, in order",
                'description' => 'A FLAT, FILTERED collection read: a tabular client addresses a '
                    . 'collection with query parameters and cannot build a nested path out of a '
                    . 'selection. `meeting_id` is required — an unfiltered tenant-wide list is not a '
                    . 'question anybody asks, and answering one would make a forgotten filter look '
                    . 'like a working call.',
                'tags' => ['convening'],
                'parameters' => [
                    self::queryParam('meeting_id', 'integer', 'The meeting whose agenda to read.', true),
                ],
                'responses' => [
                    200 => self::jsonResponse('The agenda', 'MeetingAgendaItemListResponse'),
                    404 => self::errorResponse('Meeting not found'),
                    422 => self::errorResponse('meeting_id is required'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/meeting-decisions', 'convening:read', [
                'summary' => "One meeting's decisions",
                'tags' => ['convening'],
                'parameters' => [
                    self::queryParam('meeting_id', 'integer', 'The meeting whose decisions to read.', true),
                ],
                'responses' => [
                    200 => self::jsonResponse('The decisions', 'MeetingDecisionListResponse'),
                    404 => self::errorResponse('Meeting not found'),
                    422 => self::errorResponse('meeting_id is required'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/meeting-invitations', 'convening:read', [
                'summary' => "One meeting's invitations and answers",
                'tags' => ['convening'],
                'parameters' => [
                    self::queryParam('meeting_id', 'integer', 'The meeting whose invitations to read.', true),
                ],
                'responses' => [
                    200 => self::jsonResponse('The invitations', 'MeetingInvitationListResponse'),
                    404 => self::errorResponse('Meeting not found'),
                    422 => self::errorResponse('meeting_id is required'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/meetings/{id:\d+}/attendance', 'convening:manage', [
                'summary' => 'Record who actually attended a meeting that has been held',
                'description' => 'A REPLACEMENT of the whole list, which is why it is a PUT: a '
                    . 'secretary reads a sign-in sheet and asserts the entire set, not a stream of '
                    . 'arrivals. Anybody omitted is removed from the record of who attended. '
                    . 'ATTENDANCE IS NOT AN INVITATION ANSWER — an acceptance is a prediction made '
                    . 'before the sitting and attendance is what happened at it, they disagree '
                    . 'constantly, and neither overwrites the other. Somebody who was never invited '
                    . 'can be recorded: give a `profile_id` for a person with an account or an '
                    . '`attendee_name` for a guest without one. Refused unless the meeting has been '
                    . 'HELD — attendance taken beforehand is a guess, and the platform already holds '
                    . 'guesses as invitation answers.',
                'tags' => ['convening'],
                'request' => 'MeetingAttendanceRequest',
                'responses' => [
                    200 => self::jsonResponse(
                        'The recorded attendance, and what was counted',
                        'MeetingAttendanceResponse'
                    ),
                    404 => self::errorResponse('Meeting not found'),
                    422 => self::errorResponse(
                        'A meeting that has not been held, an attendee that identifies nobody, a '
                        . 'duplicated profile, or a capacity outside the vocabulary'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/meeting-attendees', 'convening:read', [
                'summary' => "One meeting's attendance, and what each attendee had answered",
                'description' => 'Every row carries `was_invited` and `invitation_status` beside the '
                    . 'attendance, because the interesting rows are the ones where the two disagree: '
                    . 'somebody who declined and came anyway, somebody who holds no invitation at '
                    . 'all. `convening:read` and not `convening:manage`, so that a caller who can '
                    . "already see this meeting's invitations and decisions is not refused the "
                    . 'less sensitive fact of who was in the room.',
                'tags' => ['convening'],
                'parameters' => [
                    self::queryParam('meeting_id', 'integer', 'The meeting whose attendance to read.', true),
                ],
                'responses' => [
                    200 => self::jsonResponse(
                        'The attendance, and what was counted',
                        'MeetingAttendanceResponse'
                    ),
                    404 => self::errorResponse('Meeting not found'),
                    422 => self::errorResponse('meeting_id is required'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents/{id:\d+}/convening', 'convening:read', [
                'summary' => 'Which bodies has this document been in front of, and what did they decide?',
                'description' => 'THE REVERSE READ. Without it the subsystem is invisible from the '
                    . 'document side: somebody looking at a document that is sitting still has no '
                    . 'way to discover it is waiting for a body that meets on the 14th.',
                'tags' => ['convening', 'documents'],
                'responses' => [
                    200 => self::jsonResponse(
                        'Every agenda item naming this document, with its meeting, body and decisions',
                        'DocumentConveningResponse'
                    ),
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
            // #1049: build identity — which checkout the worker is RUNNING, as
            // opposed to the version constant /api/health reports. UNVERSIONED
            // beside /api/health so a runbook or alert rule survives an API
            // version bump, and unauthenticated so it is answerable by the
            // operator diagnosing a half-applied update, who frequently cannot
            // sign in. Reasoning in full on BuildApiHandler's docblock.
            [
                'method' => 'GET',
                'path' => '/api/build',
                'requiredRole' => null,
                'requiredPermission' => null,
                'unversioned' => true,
                'schema' => [
                    'summary' => 'Build identity of the running backend (the backend half of /web-build)',
                    'tags' => ['platform-ops'],
                    'responses' => [
                        200 => self::jsonResponse('The identity of the running process and the schema state it is in', 'BuildIdentityResponse'),
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
                'description' => 'Always paginated — this endpoint already returned one page before it gained '
                    . 'sort and search, so its default is unchanged. A client that needs every person must follow '
                    . 'the `pagination` envelope to the last page.',
                'tags' => ['relations'],
                'parameters' => [
                    self::queryParam('q', 'string', 'Case-insensitive substring match on the display name'),
                    self::queryParam('search', 'string', 'Deprecated spelling of q, kept for existing clients. An explicit q wins.'),
                    self::queryParam('sort', 'string', 'One of name (default), account, created. An unrecognised key is ignored rather than refused.'),
                    self::queryParam('dir', 'string', 'asc (default) or desc'),
                    self::queryParam('page', 'integer', 'Page number (1-based)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                ],
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
     * How this tenant wants its interface to PRESENT itself (#1068).
     *
     * Public and unauthenticated, exactly like {@see brandingRoutes()} and for
     * the same two reasons: the login screen and the public status page render
     * before a session exists, and the answer is a fact about how a page LOOKS
     * rather than about anything the tenant holds.
     *
     * Kept off `/api/v1/settings` deliberately. That surface is gated on
     * `settings:read` — an administrative right — and a preference governing
     * every screen has to reach the readers who will never hold it. See
     * {@see \Whity\Api\UiPreferencesApiHandler}.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function uiPreferenceRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/ui/preferences',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Display preferences for the resolved tenant (public)',
                    'description' =>
                        'How the interface should PRESENT this tenant, resolved per-tenant then global '
                        . 'then the registry default. A DISPLAY contract only: nothing behind it is '
                        . 'filtered, every timestamp is still written, still queryable, still returned by '
                        . 'every other endpoint and still in the audit trail. A client that ignores this '
                        . 'answer renders exactly what it renders today. Tenant resolution follows '
                        . 'branding: the authenticated tenant, else the request host, else the global '
                        . 'layer. Never fails — an unreachable settings layer answers with the defaults.',
                    'tags' => ['settings'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'The effective display preferences',
                            [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'hideDates' => [
                                                'type' => 'boolean',
                                                'description' =>
                                                    'When true, no date or time is rendered on any screen '
                                                    . '(`ui.hide_dates`). It does NOT govern the public '
                                                    . 'document-verification page, which has its own '
                                                    . 'disclosure control, `documents.qr_public_detail`.',
                                            ],
                                        ],
                                        'required' => ['hideDates'],
                                    ],
                                ],
                            ]
                        ),
                    ],
                ],
            ],
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

            // A PATCH, so BOTH fields are optional and neither is required: a
            // caller flipping `auto_provision` must not have to restate a role it
            // never meant to touch. `domain` is deliberately absent — renaming a
            // domain is registering a different one, and would silently carry the
            // old domain's ownership proof to a hostname nobody verified.
            'TenantEmailDomainUpdateRequest' => self::object([
                'default_role_id' => self::int(),
                'auto_provision' => self::bool(),
            ], []),

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
            'TagGroupListResponse' => self::optionallyPaginatedListEnvelope('TagGroup'),
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
            'TagListResponse' => self::optionallyPaginatedListEnvelope('Tag'),
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
                // The order APPLIED, for the same reason `view.ou_id` is echoed:
                // `direction` has a per-field default (A-Z for text,
                // newest-first for a date), so a client that assumed one would
                // draw its arrow the wrong way round half the time. A null
                // `field` is the order documents were RECORDED in, which is not
                // `created_at` under another name -- see
                // DocumentRepository::orderSql().
                'sort' => self::object([
                    // Read from the enum rather than written out, the same way
                    // the password fields are read from PasswordPolicy: a
                    // hand-copied list is one that drifts the first time a
                    // sortable column is added, and a generated client would
                    // then refuse a value the API accepts.
                    'field' => [
                        'type' => 'string',
                        'nullable' => true,
                        'enum' => [...array_column(DocumentSortField::cases(), 'value'), null],
                    ],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                ], ['field', 'direction']),
            ], ['data', 'pagination', 'view', 'sort']),
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
                // #1054. WHETHER ANYBODY HERE IS ASKED TO ACT. `delivery` means
                // the people this step reaches are TOLD: their inbox item is
                // closed by the event that created it and the document carries
                // straight on. A client needs this before it renders anything —
                // on such a step Forward, Acknowledge and Return are all 422s.
                'satisfied_by' => ['type' => 'string', 'enum' => ['act', 'delivery']],
                // #1037. HOW MANY TIMES A REJECTION HAS SENT THE DOCUMENT BACK
                // FROM HERE. A backwards reject edge - "to the author, to fix" -
                // is the most common approval design there is, and nothing
                // counted the laps: a document on its ninth rejection looked
                // exactly like one on its first in every surface.
                //
                // Derived from the trail's verdict rows rather than stored, so it
                // cannot disagree with the history it summarises. Always present,
                // never null: 0 is a real answer, and an absent field would be
                // ambiguous between "never rejected" and "this server does not
                // say".
                'rejection_count' => self::int(),
            ], ['id', 'position', 'rule_kind', 'rule_config', 'decision', 'satisfied_by', 'rejection_count']),
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
                // #1031, migration 123. WHICH DESIGN THIS CIRCULATION CAME FROM.
                // Both halves are published because neither implies the other:
                // `template_id` is null for a route composed by hand AND for one
                // whose design has since been deleted, while `template_name` is
                // the snapshot taken at issue and survives only the second case.
                // The steps below are a COPY, not a view - editing the design
                // afterwards cannot change a circulation already under way.
                'template_id' => self::int(true),
                'template_name' => self::str(true),
                'created_at' => self::str(),
                'steps' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentRouteStep')],
                'edges' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentRouteEdge')],
                // What a step whose `decision_quorum` is NULL actually does in
                // this tenant, already resolved through the settings chain
                // (#1041). Published with the route because the answer lives
                // behind `settings:read` and the person standing on a decision
                // step is the least likely person in the tenant to hold it - so
                // without this a client could not tell an approver whether their
                // single approval carries the gate or is one of four hundred.
                'default_quorum' => ['type' => 'string', 'enum' => ['all', 'any', 'majority']],
            ], ['id', 'document_id', 'title', 'created_at', 'steps', 'edges', 'default_quorum']),
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
                        // #1054. Absent means `act`, which is what every route
                        // authored before it means. `delivery` is refused
                        // together with `decision`: a gate needs somebody
                        // holding the item to answer it, and a delivery step
                        // closes every item the moment it is sent.
                        //
                        // There is no CHANNEL here, deliberately. The step says
                        // its people are told rather than asked; e-mail versus
                        // in-app is `documents.routing_notification_channels`,
                        // so a tenant can change transport without re-authoring
                        // a single route.
                        'satisfied_by' => [
                            'type' => 'string',
                            'enum' => ['act', 'delivery'],
                        ],
                    ], ['rule_kind']),
                ],
            ], ['steps']),

            // #1031. Applying a DESIGN carries the design's id and nothing else -
            // no steps, deliberately. The stages are read from the template
            // server-side and converted there, so a client cannot send a
            // `template_id` beside steps of its own and have the pair recorded as
            // though the design produced them. The tenant's
            // `documents.routing_max_steps` is re-checked at this moment, since
            // the setting can have moved since the design was authored.
            'DocumentRouteFromTemplateRequest' => self::object([
                'template_id' => self::int(),
                // Left out, the ROUTE is named after the DESIGN rather than after
                // the document - an author who applied "Purchase approval" is
                // naming the circulation after the flow it follows.
                'title' => self::str(true),
            ], ['template_id']),

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
                // #1054. TRUE when this row was closed by the document reaching
                // the person rather than by their acting — the rows a
                // `satisfied_by: delivery` step opens. Without it a delivery
                // step's three hundred closed rows read exactly like three
                // hundred people who acted.
                'closed_by_delivery' => self::bool(),
                'created_at' => self::str(),
            ], [
                'id', 'document_id', 'route_id', 'step_id', 'profile_id', 'created_by_event_id',
                'open', 'closed_by_delivery', 'created_at',
            ]),
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
            // A rail SECTION: the heading a set of folders sits under, and where
            // it goes. Sent because the client used to decide which groups
            // existed — it admitted two names and silently dropped the rest, so
            // a folder in a third group reached this payload and never the
            // screen (#998).
            'DocumentViewGroup' => self::object([
                'key' => self::str(),
                // English, translated by key where a client knows one — the same
                // rule a view's label follows, and for the same reason: a client
                // cannot have a translation for a group it has never heard of.
                'label' => self::str(),
                'order' => self::int(),
            ], ['key', 'label', 'order']),
            'DocumentViewListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentView')],
                // One entry per group that has at least one available view, in
                // render order. A group with no folders is not a section; a group
                // with folders but no declaration still appears, labelled with
                // its own key, because the one thing this must never do is
                // decide a view's group is not real.
                'groups' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentViewGroup')],
                'unavailable_substrates' => ['type' => 'array', 'items' => SchemaBuilder::ref('DocumentSubstrate')],
            ], ['data', 'groups', 'unavailable_substrates']),

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

            // #1027 — reusable, BRANCHING ROUTE TEMPLATES: the designs the
            // node-based flow editor edits. A template is to a route what
            // `document_templates` is to `documents` — the thing DESIGNED, with a
            // different lifetime from the thing that HAPPENED.
            //
            // `step_count` is the only decoration on a list row. A resolved-people
            // count is deliberately absent: it would resolve every rule of every
            // step on every render, and report a number already stale by the time
            // it was drawn. That count belongs to `POST /api/user-groups/preview`,
            // one rule at a time, where somebody asked for it.
            'RouteTemplate' => self::object([
                'id' => self::int(),
                'name' => self::str(),
                'description' => self::str(true),
                'step_count' => self::int(),
                // Null once the person who designed it has been deleted. The
                // design survives them: it is the institution's process, not that
                // person's private filing.
                'created_by' => self::int(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'name', 'step_count', 'created_at', 'updated_at']),
            // One stage. It names a RULE and never a person — there is no profile
            // field here and none in the table behind it, which is what makes
            // "one node for a thousand instructors" a property of the schema
            // rather than a convention the editor is trusted to keep.
            //
            // `decision` says whether the stage is a GATE. `decision_quorum` says
            // what "this node approved" means when the rule resolves to a
            // thousand people, and NULL means "follow the tenant setting" rather
            // than "no quorum" — the same reading migration 118 gives the
            // identical column on `document_route_steps`.
            //
            // Addressed by `position`, never by a database id: the ids churn on
            // every save because a graph write replaces rather than diffs, so
            // publishing one would invite a client to hold it across a save.
            'RouteTemplateStep' => self::object([
                'position' => self::int(),
                'rule_kind' => self::str(),
                'rule_config' => ['type' => 'object', 'additionalProperties' => true],
                'label' => self::str(true),
                'decision' => self::bool(),
                'decision_quorum' => self::str(true),
                // #1054, carried on the DESIGN as well as on the instance. A
                // stage the converter could not carry is a stage the design
                // silently loses: a delivery stage flattened into a circulation
                // hands every instructor in a faculty an item nothing can close.
                'satisfied_by' => ['type' => 'string', 'enum' => ['act', 'delivery']],
                'canvas_x' => self::int(),
                'canvas_y' => self::int(),
                // NOT in the required list, unlike `DocumentRouteStep` above.
                // This one component serves both the graph RESPONSE and the graph
                // REQUEST, and a canvas drawn before #1054 does not send the
                // field: the server reads its absence as `act`, which is what
                // that design has always meant. Marking it required would make
                // every existing editor's save read as non-conforming for a value
                // it correctly has no opinion about.
                //
                // (`decision` IS in that list, and is the wart this declines to
                // copy rather than the precedent it follows.)
            ], ['position', 'rule_kind', 'rule_config', 'decision']),
            // One transition, keyed by the verdict that takes it (#1014's
            // vocabulary, mirrored — `approved` or `rejected`).
            //
            // There is NO unconditional edge. A step with no edge for the verdict
            // it received falls through to the NEXT POSITION on an approval and
            // ends the chain on a rejection, so a plain linear route is a template
            // with steps and no edges at all — and the forward arrows an editor
            // draws come from `position` rather than from stored rows that could
            // disagree with it.
            'RouteTemplateEdge' => self::object([
                'from' => self::int(),
                'to' => self::int(),
                'verdict' => self::str(),
            ], ['from', 'to', 'verdict']),
            'RouteTemplateListResponse' => self::paginatedListEnvelope('RouteTemplate'),
            'RouteTemplateResponse' => self::dataEnvelope(SchemaBuilder::ref('RouteTemplate')),
            // The whole canvas. `default_quorum` and `max_steps` ride along so the
            // editor can show what an unset quorum will do and how many nodes it
            // may draw WITHOUT holding `settings:read` — which somebody who may
            // design a flow need not hold.
            'RouteTemplateGraphResponse' => self::dataEnvelope(self::object([
                'id' => self::int(),
                'name' => self::str(),
                'description' => self::str(true),
                'step_count' => self::int(),
                'default_quorum' => self::str(),
                'max_steps' => self::int(),
                'created_by' => self::int(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
                'steps' => ['type' => 'array', 'items' => SchemaBuilder::ref('RouteTemplateStep')],
                'edges' => ['type' => 'array', 'items' => SchemaBuilder::ref('RouteTemplateEdge')],
            ], ['id', 'name', 'default_quorum', 'max_steps', 'steps', 'edges'])),
            'RouteTemplateCreateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(true),
            ], ['name']),
            // PATCH: omitted fields keep their value. The GRAPH is not here — it
            // has its own verb, because renaming a design and redrawing it are
            // different acts, and a PATCH carrying both would make an omitted
            // `steps` indistinguishable from an author who meant to clear it.
            'RouteTemplateUpdateRequest' => self::object([
                'name' => self::str(),
                'description' => self::str(true),
            ], []),
            // PUT: REPLACES. `steps` is required and an empty array is a valid,
            // meaningful value — an author who really did delete every node.
            'RouteTemplateGraphRequest' => self::object([
                'steps' => ['type' => 'array', 'items' => SchemaBuilder::ref('RouteTemplateStep')],
                'edges' => ['type' => 'array', 'items' => SchemaBuilder::ref('RouteTemplateEdge')],
            ], ['steps']),
            'RouteTemplateDeleteResponse' => self::dataEnvelope(self::object([
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
                // The OTHER kind of user (#1186). Blocks may contain blocks, so
                // a block held only by another block used to report no users
                // here and then refuse to delete with a 409.
                'blocks' => ['type' => 'array', 'items' => self::object([
                    'id' => self::int(),
                    'name' => self::str(),
                    'scope' => ['type' => 'string', 'enum' => ['personal', 'tenant', 'global', 'system']],
                    'required_permission' => self::str(true),
                    'owner_ou_id' => self::int(true),
                    'is_system' => self::bool(),
                    'updated_at' => self::str(),
                ], ['id', 'name', 'scope', 'is_system', 'updated_at'])],
            ], ['block_id', 'total', 'hidden', 'templates', 'blocks']),
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

            // #1070. A period KIND. `key` is the stable identifier code binds
            // to — bare for a tenant's own vocabulary, `plugin:slug` for one a
            // plugin contributed. `parent_type_id` is the nesting: which kind
            // this kind sits inside. There is deliberately no rank column —
            // a kind's place is expressed by what contains it, which is a
            // structural fact, rather than by a sort order, which is an opinion.
            // FORMS (migrations 127/128). `name` and `label` are the bilingual
            // `{ar?, en?}` object, so Arabic and English are both first-class
            // rather than one being the "real" value and the other a translation.
            //
            // `available_transitions` and `accepts_submissions` are DERIVED from
            // `status` and are emitted so a client rendering the lifecycle
            // controls does not carry a second copy of the transition table —
            // which is how a client ends up offering a control the server refuses.
            'Form' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'form_key' => self::str(),
                'name' => self::localizedText(),
                'description' => self::str(true),
                'status' => ['type' => 'string', 'enum' => ['draft', 'published', 'archived']],
                'version' => self::int(),
                // Null means "collect only, do not circulate" — a legitimate
                // configuration, not an unset field.
                'route_template_id' => self::int(true),
                'created_by_profile_id' => self::int(true),
                'created_at' => self::str(),
                'updated_at' => self::str(),
                'available_transitions' => ['type' => 'array', 'items' => ['type' => 'string']],
                'accepts_submissions' => self::bool(),
                // THE PUBLIC LINK (migration 132). Off by default on every form
                // in every install: `public_enabled` is only ever true because
                // somebody holding `forms:manage` called
                // POST /api/v1/forms/{id}/public-link.
                'public_enabled' => self::bool(),
                // 64 hex characters — 256 bits from a CSPRNG. It is the ONLY
                // credential the public endpoints have, so treat it as one: it is
                // returned to tenant members (who must be able to hand the link
                // out) and to nobody else. Null when the form has no link.
                'public_slug' => self::str(true),
                // The absolute address the form is served from, composed from the
                // slug and this instance's own APP_URL rather than stored — an
                // instance that moves domain must not serve a column pointing at
                // where it used to live. Null when there is no link, and ALSO
                // null when the instance has never been told its own address.
                'public_url' => self::str(true),
                // The submission window. Either may be null, meaning "no boundary
                // on this side". Naive local date-times in the instance's own
                // clock, like every other timestamp in this schema.
                'public_opens_at' => self::str(true),
                'public_closes_at' => self::str(true),
                'public_enabled_at' => self::str(true),
                'public_enabled_by_profile_id' => self::int(true),
                // DERIVED, computed by the database against its own clock: is the
                // form inside its window right now? Emitted so a client does not
                // compare timestamps in a second timezone and disagree with the
                // server about whether a link is live.
                'public_window_open' => self::bool(),
            ], [
                'id', 'tenant_id', 'form_key', 'name', 'status', 'version',
                'available_transitions', 'accepts_submissions', 'public_enabled',
            ]),
            'FormResponse' => self::dataEnvelope(SchemaBuilder::ref('Form')),
            'FormListResponse' => self::listEnvelope('Form'),
            'FormDetailResponse' => self::dataEnvelope([
                'allOf' => [
                    SchemaBuilder::ref('Form'),
                    self::object([
                        'fields' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormField')],
                        'sections' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormSection')],
                        'submission_count' => self::int(),
                    ], []),
                ],
            ]),
            // `field_type` mirrors the CHECK constraint on `form_fields` and
            // FieldType's whitelist; a unit test reads the migration and fails
            // the moment the three disagree.
            //
            // `prefill_backed` is emitted BESIDE `prefill_source` rather than
            // left for a client to derive: two of the declared sources have no
            // column in this schema to read, and an author choosing one must see
            // in the field editor that it will never produce a value — not
            // discover it as an empty box after publishing.
            'FormField' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'form_id' => self::int(),
                'field_key' => self::str(),
                'field_type' => ['type' => 'string', 'enum' => [
                    'text', 'textarea', 'number', 'date', 'select',
                    'multiselect', 'checkbox', 'file', 'profile_ref', 'ou_ref',
                ]],
                'label' => self::localizedText(),
                'help_text' => self::str(true),
                'is_required' => self::bool(),
                'options' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormFieldOption')],
                'validation' => SchemaBuilder::ref('FormFieldValidation'),
                'prefill_source' => self::str(true),
                'prefill_backed' => self::bool(),
                'section_key' => self::str(true),
                'position' => self::int(),
                'multi_valued' => self::bool(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], ['id', 'tenant_id', 'form_id', 'field_key', 'field_type', 'label', 'is_required', 'position']),
            'FormFieldOption' => self::object([
                'value' => self::name(nonEmpty: true),
                'label' => self::localizedText(),
            ], ['value', 'label']),
            // Every rule is optional and unknown keys are DROPPED on write rather
            // than stored: a rule nothing enforces is a promise on the row that no
            // code keeps. `maxLength` may only TIGHTEN the platform ceiling.
            'FormFieldValidation' => self::object([
                'min' => ['type' => 'number'],
                'max' => ['type' => 'number'],
                'maxLength' => self::int(),
                'pattern' => ['type' => 'string', 'maxLength' => 512],
            ], []),
            // DERIVED from the fields' `section_key`, never stored. There is no
            // `form_sections` table, so a section cannot exist with no fields and
            // a field cannot point at a section that was deleted.
            'FormSection' => self::object([
                'key' => self::str(true),
                'field_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
            ], ['key', 'field_keys']),
            'FormFieldResponse' => self::dataEnvelope(SchemaBuilder::ref('FormField')),
            'FormFieldListResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormField')],
                'meta' => SchemaBuilder::ref('FormBuilderVocabularies'),
            ], ['data']),
            // Served by the server so a builder cannot hold a stale copy of the
            // field kinds or of which prefill sources actually resolve here.
            'FormBuilderVocabularies' => self::object([
                'field_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                'option_bearing_field_types' => ['type' => 'array', 'items' => ['type' => 'string']],
                'prefill_sources' => [
                    'type' => 'array',
                    'items' => self::object([
                        'source' => self::str(),
                        // False means nothing in this install stores that detail,
                        // so the field starts empty. Declared anyway — omitting it
                        // would push authors into adding a plain text box and
                        // making every submitter retype it.
                        'backed' => self::bool(),
                        'reason' => self::str(true),
                    ], ['source', 'backed']),
                ],
            ], ['field_types', 'prefill_sources']),
            'FormCreateRequest' => self::object([
                'form_key' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 128,
                    'pattern' => '^[a-z][a-z0-9_-]*$',
                ],
                // A bare string is accepted and read as English: a form named in
                // one language only is the ordinary case, and demanding the object
                // shape would 422 a request that meant something perfectly clear.
                'name' => self::localizedText(),
                'description' => self::text(),
                'route_template_id' => self::int(true),
            ], ['form_key', 'name']),
            // `form_key` and `status` are absent because both are REFUSED with a
            // 422 rather than ignored — a caller who sent one meant something, and
            // dropping it silently would leave them believing a change happened.
            'FormUpdateRequest' => self::object([
                'name' => self::localizedText(),
                'description' => self::text(),
                'route_template_id' => self::int(true),
            ], []) + ['minProperties' => 1],
            'FormFieldCreateRequest' => self::object([
                'field_key' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'maxLength' => 128,
                    'pattern' => '^[a-z][a-z0-9_]*$',
                ],
                'field_type' => ['type' => 'string', 'enum' => [
                    'text', 'textarea', 'number', 'date', 'select',
                    'multiselect', 'checkbox', 'file', 'profile_ref', 'ou_ref',
                ]],
                'label' => self::localizedText(),
                'help_text' => self::text(),
                'is_required' => self::bool(),
                'options' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormFieldOption')],
                'validation' => SchemaBuilder::ref('FormFieldValidation'),
                'prefill_source' => self::str(true),
                'section_key' => self::name(),
                // Absent means the server appends it after the current maximum.
                'position' => self::int(),
            ], ['field_key', 'field_type', 'label']),
            // The WHOLE set, in order. Each entry is a create-shaped field; the
            // list's order IS the position, so a caller that shows a sequence
            // does not also have to maintain an integer that agrees with it.
            'FormFieldSetRequest' => self::object([
                'fields' => [
                    'type' => 'array',
                    'items' => SchemaBuilder::ref('FormFieldCreateRequest'),
                    'description' => 'Every field the form should have after this call. A stored '
                        . 'field_key absent from this list is withdrawn; answers already given to it '
                        . 'stay recorded and stop having a label.',
                ],
            ], ['fields']),
            'FormFieldUpdateRequest' => self::object([
                'field_type' => ['type' => 'string', 'enum' => [
                    'text', 'textarea', 'number', 'date', 'select',
                    'multiselect', 'checkbox', 'file', 'profile_ref', 'ou_ref',
                ]],
                'label' => self::localizedText(),
                'help_text' => self::text(),
                'is_required' => self::bool(),
                'options' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormFieldOption')],
                'validation' => SchemaBuilder::ref('FormFieldValidation'),
                'prefill_source' => self::str(true),
                'section_key' => self::name(),
                'position' => self::int(),
            ], []) + ['minProperties' => 1],
            // `prefill` is keyed by FIELD KEY, not by source: two fields may name
            // the same source and each wants its own entry. It is kept OUT of the
            // field objects on purpose — a field is the same for everybody and a
            // prefill value is not, and merging them would produce a payload that
            // looks cacheable and is not.
            'FormRenderResponse' => self::dataEnvelope(self::object([
                'form' => SchemaBuilder::ref('Form'),
                'fields' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormField')],
                'sections' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormSection')],
                'prefill' => ['type' => 'object', 'additionalProperties' => ['type' => 'string']],
                'unresolved_prefill' => [
                    'type' => 'array',
                    'items' => self::object([
                        'field_key' => self::str(),
                        'source' => self::str(),
                        'reason' => self::str(),
                    ], ['field_key', 'source', 'reason']),
                ],
                'accepts_submissions' => self::bool(),
            ], ['form', 'fields', 'sections', 'prefill', 'accepts_submissions'])),
            'FormSubmissionCreateRequest' => self::object([
                // Answers keyed by field key. Values are typed per the field's
                // kind — a string, a number, a boolean, or a list of choices —
                // which is why this is `additionalProperties: true` rather than a
                // narrower map: one schema cannot express "shaped by another row".
                'data' => ['type' => 'object', 'additionalProperties' => true],
            ], ['data']),
            // ---- the public link (migration 132) ----
            // Both boundaries optional and either nullable: null means "no
            // boundary on this side", which is the ordinary case and must not
            // need a sentinel date. A UTC offset is REFUSED rather than applied —
            // the column is a naive TIMESTAMP compared against the database's own
            // clock, so accepting one would move the deadline somebody typed
            // without saying so.
            'FormPublicLinkRequest' => self::object([
                'opens_at' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'YYYY-MM-DD or YYYY-MM-DD HH:MM[:SS] in the instance\'s own '
                        . 'time zone. A bare date means the START of that day.',
                ],
                'closes_at' => [
                    'type' => 'string',
                    'nullable' => true,
                    'description' => 'Same format. A bare date closes at MIDNIGHT THAT MORNING, so '
                        . '"all of the 30th" is written as the 31st or as an explicit time.',
                ],
            ], []),
            // `meta.closed` is the honest way to be idempotent: the call always
            // succeeds, and this says whether it was the one that changed
            // anything.
            'FormPublicLinkClosedResponse' => self::object([
                'data' => SchemaBuilder::ref('Form'),
                'meta' => self::object(['closed' => self::bool()], ['closed']),
            ], ['data']),
            // WHAT A STRANGER SEES. Deliberately NOT `Form` plus omissions — it is
            // its own, much smaller shape, built key by key, so a column added to
            // `forms` next year is invisible here unless somebody adds it. No id,
            // no tenant id, no form key, no author, no route template, no
            // submission count, no status, no version, and no prefill.
            'PublicFormResponse' => self::dataEnvelope(self::object([
                'slug' => self::str(),
                'name' => self::localizedText(),
                'description' => self::str(true),
                'fields' => ['type' => 'array', 'items' => SchemaBuilder::ref('PublicFormField')],
                'sections' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormSection')],
                'accepts_submissions' => self::bool(),
                'opens_at' => self::str(true),
                'closes_at' => self::str(true),
            ], ['slug', 'name', 'fields', 'sections', 'accepts_submissions'])),
            // A field, reduced to what drawing it requires. `id`, `tenant_id` and
            // `form_id` are internal identifiers a stranger has no use for and
            // could try elsewhere; `prefill_source` is withheld even though it
            // holds no value of this caller's, because naming `profile.ou` tells
            // an outsider the platform models organisational units and that this
            // form expects one — a free sentence about internals, for a field
            // that renders identically without it.
            //
            // `validation` IS disclosed: the server enforces it either way, so
            // withholding it only means the person discovers the rule by being
            // refused.
            'PublicFormField' => self::object([
                'field_key' => self::str(),
                'field_type' => ['type' => 'string', 'enum' => [
                    'text', 'textarea', 'number', 'date', 'select', 'multiselect', 'checkbox',
                ]],
                'label' => self::localizedText(),
                'help_text' => self::str(true),
                'is_required' => self::bool(),
                'options' => ['type' => 'array', 'items' => ['type' => 'object', 'additionalProperties' => true]],
                'validation' => ['type' => 'object', 'additionalProperties' => true],
                'section_key' => self::str(true),
                'position' => self::int(),
                'multi_valued' => self::bool(),
            ], ['field_key', 'field_type', 'label', 'is_required', 'position']),
            // A RECEIPT, not the submission row. An anonymous caller gets
            // confirmation and the timestamp of their own act — never the
            // submission id, the document id or the tenant id, which would hand
            // them integers to try against every other surface.
            // The reference a `file` answer carries, plus what the server saw.
            // `reference` IS the storage key, and handing it over is safe because
            // a key is not a capability anywhere in this platform: NO route
            // accepts one as input. Bytes are read at
            // GET /api/v1/documents/{id}/artifacts/{artifactId}/content, which
            // resolves the document through the visibility policy, binds the
            // artifact to that document AND tenant, and takes the key OFF THE
            // ROW. And a key from elsewhere cannot become such a row: the submit
            // path accepts a `file` answer only by CLAIMING an unspent
            // `form_uploads` row bound to this tenant, this form and this
            // uploader (migration 133).
            //
            // `checksum_sha256` is the server's own hash of the bytes it stored,
            // returned so a client can verify the upload before committing to a
            // submission — and recorded onto `document_artifacts` when the upload
            // is claimed, so "is this the file that was sent" stays answerable.
            'FormUploadResponse' => self::object([
                'data' => self::object([
                    'reference' => self::str(),
                    'filename' => self::str(true),
                    'content_type' => self::str(),
                    'byte_size' => self::int(),
                    'checksum_sha256' => self::str(),
                ], ['reference', 'content_type', 'byte_size', 'checksum_sha256']),
            ], ['data']),
            'PublicFormSubmissionResponse' => self::object([
                'data' => self::object([
                    'received' => self::bool(),
                    'submitted_at' => self::str(true),
                ], ['received']),
                'meta' => self::object([
                    'routed' => self::bool(),
                    'ignored_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], ['routed', 'ignored_keys']),
            ], ['data']),
            'FormSubmission' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'form_id' => self::int(),
                // The version the answers were given against. See the publish
                // route for exactly what this stamp does and does not promise.
                'form_version' => self::int(),
                // NULL has two ordinary causes and neither is an error: a service
                // principal has no profile, and a submission made through a
                // PUBLIC LINK (migration 132) has no account behind it at all.
                // There is no sentinel "anonymous" profile — a fake person is
                // something every membership check and permission resolution
                // would have to know to special-case, and the ones that did not
                // would treat it as real.
                'submitted_by_profile_id' => self::int(true),
                // Null is ORDINARY: a form with no route template records the
                // answers and mints no document, and a document deleted later
                // leaves the answers untouched.
                'document_id' => self::int(true),
                'data' => ['type' => 'object', 'additionalProperties' => true],
                'submitted_at' => self::str(),
                'created_at' => self::str(),
                // Present only on the reads that join `forms`. Absent means "not
                // fetched by this read", never "empty".
                'form_key' => self::str(),
                'form_name' => self::localizedText(),
            ], ['id', 'tenant_id', 'form_id', 'form_version', 'data', 'submitted_at']),
            'FormSubmissionListResponse' => self::listEnvelope('FormSubmission'),
            'FormSubmissionDetailResponse' => self::dataEnvelope([
                'allOf' => [
                    SchemaBuilder::ref('FormSubmission'),
                    self::object([
                        'fields' => ['type' => 'array', 'items' => SchemaBuilder::ref('FormField')],
                        'form_version_now' => self::int(true),
                    ], []),
                ],
            ]),
            'FormSubmissionCreateResponse' => self::object([
                'data' => SchemaBuilder::ref('FormSubmission'),
                'meta' => self::object([
                    'routed' => self::bool(),
                    'ignored_keys' => ['type' => 'array', 'items' => ['type' => 'string']],
                ], ['routed', 'ignored_keys']),
            ], ['data', 'meta']),

            'TimeWindowType' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'key' => self::str(),
                'label' => self::str(),
                'parent_type_id' => self::int(true),
                'source' => self::str(),
                'created_at' => self::str(true),
                'updated_at' => self::str(true),
            ], ['id', 'tenant_id', 'key', 'label', 'parent_type_id', 'source', 'created_at', 'updated_at']),
            'TimeWindowTypeResponse' => self::dataEnvelope(SchemaBuilder::ref('TimeWindowType')),
            'TimeWindowTypeListResponse' => self::dataEnvelope([
                'type' => 'array',
                'items' => SchemaBuilder::ref('TimeWindowType'),
            ]),
            // `parent_key` is already namespaced, because a plugin may only nest
            // inside its own vocabulary and the prefix is the host's to apply.
            'TimeWindowTypeCatalogEntry' => self::object([
                'key' => self::str(),
                'source' => self::str(),
                'label' => self::str(),
                'parent_key' => self::str(true),
                'adopted' => ['type' => 'boolean'],
                'adopted_id' => self::int(true),
            ], ['key', 'source', 'label', 'parent_key', 'adopted', 'adopted_id']),
            'TimeWindowTypeCatalogResponse' => self::dataEnvelope([
                'type' => 'array',
                'items' => SchemaBuilder::ref('TimeWindowTypeCatalogEntry'),
            ]),
            'TimeWindowTypeCreateRequest' => self::object([
                // Trimmed before validation. A NAMESPACED key must already be
                // declared by a plugin, and the reserved key `none` is always a
                // 422 — neither is expressible as a pattern.
                'key' => self::windowTypeKey() + ['minLength' => 1],
                // Empty, whitespace-only and absent all mean the same: the label
                // falls back to the declared one, then to the key itself.
                'label' => self::name(),
                // Absent inherits the declared nesting when the tenant has
                // already adopted the parent kind, and otherwise adopts the kind
                // un-nested — a declaration is a default, not a precondition.
                'parent_type_id' => self::int(true),
            ], ['key']),
            // The key is immutable: it is what code binds to, so editing it in
            // place would repoint every reference at a kind that no longer
            // exists. It is absent here rather than declared-and-ignored.
            'TimeWindowTypeUpdateRequest' => self::object([
                'label' => self::name(nonEmpty: true),
                'parent_type_id' => self::int(true),
            ], []) + ['minProperties' => 1],

            // #1070. One PERIOD. `starts_on` and `ends_on` are DATES, inclusive
            // at both ends, and they are authored rather than derived: a period
            // may begin on any day, run for any span, and differ in length from
            // its siblings. Nothing in the platform computes them from a
            // calendar, which is the whole point of the concept.
            'TimeWindow' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'window_type_id' => self::int(),
                'parent_window_id' => self::int(true),
                'key' => self::str(),
                'label' => self::str(),
                'starts_on' => self::date(),
                'ends_on' => self::date(),
                'state' => ['type' => 'string', 'enum' => WindowState::states()],
                'created_at' => self::str(true),
                'updated_at' => self::str(true),
            ], [
                'id', 'tenant_id', 'window_type_id', 'parent_window_id', 'key', 'label',
                'starts_on', 'ends_on', 'state', 'created_at', 'updated_at',
            ]),
            'TimeWindowResponse' => self::dataEnvelope(SchemaBuilder::ref('TimeWindow')),
            'TimeWindowListResponse' => self::dataEnvelope([
                'type' => 'array',
                'items' => SchemaBuilder::ref('TimeWindow'),
            ]),
            // The append-only seal trail. A reopen never amends the close it
            // undoes; it is a new row that supersedes it, and both remain
            // readable. `cascaded_from_window_id` says this seal was a
            // consequence of closing the period named, rather than an act
            // performed on this one.
            'TimeWindowStateEvent' => self::object([
                'id' => self::int(),
                'window_id' => self::int(),
                'action' => ['type' => 'string', 'enum' => WindowState::acts()],
                'actor_profile_id' => self::int(true),
                'reason' => self::str(true),
                'cascaded_from_window_id' => self::int(true),
                'occurred_at' => self::str(),
            ], [
                'id', 'window_id', 'action', 'actor_profile_id', 'reason',
                'cascaded_from_window_id', 'occurred_at',
            ]),
            'TimeWindowDetailResponse' => self::dataEnvelope(
                ['allOf' => [
                    SchemaBuilder::ref('TimeWindow'),
                    self::object(
                        ['trail' => ['type' => 'array', 'items' => SchemaBuilder::ref('TimeWindowStateEvent')]],
                        ['trail']
                    ),
                ]]
            ),
            'TimeWindowCreateRequest' => self::object([
                'window_type_id' => self::int(),
                'key' => self::name(nonEmpty: true),
                'label' => self::name(),
                'starts_on' => self::date(),
                'ends_on' => self::date(),
                // Required when the kind nests inside another kind, and refused
                // when it does not — the kind decides, not the caller.
                'parent_window_id' => self::int(true),
            ], ['window_type_id', 'key', 'starts_on', 'ends_on']),
            'TimeWindowUpdateRequest' => self::object([
                'label' => self::name(nonEmpty: true),
                'starts_on' => self::date(),
                'ends_on' => self::date(),
                'parent_window_id' => self::int(true),
            ], []) + ['minProperties' => 1],
            // Contributed by whatever holds records in the period; core ships no
            // contributor, so an empty list with `unfinished_reported: false`
            // means nothing volunteered a count rather than nothing being
            // unfinished.
            'TimeWindowUnfinishedGroup' => self::object([
                'label' => self::str(),
                'count' => self::int(),
                'source' => self::str(),
            ], ['label', 'count', 'source']),
            'TimeWindowCloseReport' => self::object([
                'window' => SchemaBuilder::ref('TimeWindow'),
                'blocked' => ['type' => 'boolean'],
                'open_children' => ['type' => 'array', 'items' => SchemaBuilder::ref('TimeWindow')],
                'unfinished' => [
                    'type' => 'array',
                    'items' => SchemaBuilder::ref('TimeWindowUnfinishedGroup'),
                ],
                'unfinished_total' => self::int(),
                'unfinished_reported' => ['type' => 'boolean'],
            ], [
                'window', 'blocked', 'open_children', 'unfinished',
                'unfinished_total', 'unfinished_reported',
            ]),
            'TimeWindowCloseReportResponse' => self::dataEnvelope(
                SchemaBuilder::ref('TimeWindowCloseReport')
            ),
            'TimeWindowCloseRequest' => self::object([
                // Optional: sealing a period on schedule is the ordinary case,
                // and demanding a justification for the ordinary case trains
                // people to type nothing meaningful.
                'reason' => self::text(),
                'cascade' => ['type' => 'boolean'],
            ], []),
            'TimeWindowCloseResponse' => self::dataEnvelope(self::object([
                'window' => SchemaBuilder::ref('TimeWindow'),
                'closed_ids' => ['type' => 'array', 'items' => self::int()],
                'report' => SchemaBuilder::ref('TimeWindowCloseReport'),
            ], ['window', 'closed_ids', 'report'])),
            'TimeWindowReopenRequest' => self::object([
                // REQUIRED, unlike a close's. This is the one act that undoes
                // something other people relied on, and the question afterwards
                // is never whether it happened but why.
                'reason' => self::text() + ['minLength' => 1],
            ], ['reason']),

            // #convening (migrations 130/131). DELIBERATIVE BODIES that meet,
            // minute numbered decisions, and drive a document's existing approval
            // route with what they decided.
            //
            // `name` and `title` are OBJECTS of language code => text, not
            // strings: this platform's Arabic/RTL support is not a display
            // setting, and a body HAS two names of which both are the real one.
            // `display_name` / `display_title` ride alongside for the surfaces
            // that can carry only ONE string (a notification subject, a cell in a
            // server-driven table); a localizing client reads the map and ignores
            // them.
            'LocalizedLabel' => [
                'type' => 'object',
                'additionalProperties' => self::str(),
                'description' => 'Language code => text. At least one entry.',
            ],
            'ConveningBody' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'body_key' => self::str(),
                'name' => SchemaBuilder::ref('LocalizedLabel'),
                'display_name' => self::str(),
                'ou_id' => self::int(true),
                'description' => self::str(true),
                'is_active' => self::bool(),
                'created_at' => self::str(),
                'updated_at' => self::str(),
            ], [
                'id', 'tenant_id', 'body_key', 'name', 'display_name', 'ou_id',
                'description', 'is_active', 'created_at', 'updated_at',
            ]),
            // A SEAT, never a permission: holding the chair grants nothing in
            // RBAC. What it decides is whose name carries the body's decision to
            // a routing step, among people the route already reached.
            'ConveningBodyMember' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'body_id' => self::int(),
                'profile_id' => self::int(),
                'member_role' => ['type' => 'string', 'enum' => MemberRole::all()],
                'joined_at' => self::str(),
                // NULL means "still a member". A departure is recorded rather
                // than deleted, so a decision taken in March remains attributable
                // to the body as it was then.
                'left_at' => self::str(true),
            ], ['id', 'tenant_id', 'body_id', 'profile_id', 'member_role', 'joined_at', 'left_at']),
            'ConveningBodyListResponse' => self::listEnvelope('ConveningBody'),
            'ConveningBodyResponse' => self::dataEnvelope(SchemaBuilder::ref('ConveningBody')),
            'ConveningBodyDetailResponse' => self::dataEnvelope(
                ['allOf' => [
                    SchemaBuilder::ref('ConveningBody'),
                    self::object(
                        ['members' => ['type' => 'array', 'items' => SchemaBuilder::ref('ConveningBodyMember')]],
                        ['members']
                    ),
                ]]
            ),
            'ConveningBodyMemberListResponse' => self::listEnvelope('ConveningBodyMember'),
            'ConveningBodyCreateRequest' => self::object([
                // Lower-case letters, digits, '-' and '_'. Narrow because it is
                // quoted inside every decision number the body mints, and a key
                // with a slash or a space produces numbers nobody can quote
                // unambiguously.
                'body_key' => self::str() + ['pattern' => '^[a-z0-9][a-z0-9_-]{0,63}$'],
                'name' => ['oneOf' => [self::str(), SchemaBuilder::ref('LocalizedLabel')]],
                'ou_id' => self::int(true),
                'description' => self::text(),
            ], ['body_key', 'name']),
            // `body_key` is absent rather than declared-and-ignored: it is
            // immutable, because decision numbers already quote it.
            'ConveningBodyUpdateRequest' => self::object([
                'name' => ['oneOf' => [self::str(), SchemaBuilder::ref('LocalizedLabel')]],
                'ou_id' => self::int(true),
                'description' => self::text(),
                'is_active' => self::bool(),
            ], []) + ['minProperties' => 1],
            'ConveningBodyMemberRequest' => self::object([
                'profile_id' => self::int(),
                'member_role' => ['type' => 'string', 'enum' => MemberRole::all()],
            ], ['profile_id']),

            // One SITTING. `scheduled_at` and `held_at` are both nullable and
            // both real: a draft has neither, a scheduled meeting has the first,
            // and only a meeting that actually took place has the second.
            // Nothing derives one from the other.
            'Meeting' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'body_id' => self::int(),
                'meeting_number' => self::int(),
                'title' => SchemaBuilder::ref('LocalizedLabel'),
                'display_title' => self::str(),
                'scheduled_at' => self::str(true),
                'held_at' => self::str(true),
                'location' => self::str(true),
                'status' => ['type' => 'string', 'enum' => MeetingStatus::all()],
                'created_by_profile_id' => self::int(true),
                'created_at' => self::str(),
            ], [
                'id', 'tenant_id', 'body_id', 'meeting_number', 'title', 'display_title',
                'scheduled_at', 'held_at', 'location', 'status', 'created_by_profile_id', 'created_at',
            ]),
            'MeetingAgendaItem' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'meeting_id' => self::int(),
                'position' => self::int(),
                'title' => SchemaBuilder::ref('LocalizedLabel'),
                'display_title' => self::str(),
                // THE JOIN to the rest of the platform. An item with a document
                // is an item a decision can move.
                'document_id' => self::int(true),
                'notes' => self::str(true),
                'created_at' => self::str(),
            ], [
                'id', 'tenant_id', 'meeting_id', 'position', 'title', 'display_title',
                'document_id', 'notes', 'created_at',
            ]),
            // `verdict` carries a third value the routing engine does not have.
            // A deferral IS a decision — numbered, minuted — and it is
            // deliberately not mapped onto approve or reject, because forcing it
            // onto either would advance a document nobody approved or reject one
            // nobody refused.
            //
            // `route_id` / `route_event_id` are what separate "the body approved
            // it and the document advanced" from "the body approved it and
            // nothing moved". Without them those two render identically.
            'MeetingDecision' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'meeting_id' => self::int(),
                'agenda_item_id' => self::int(),
                'decision_number' => self::str(),
                'verdict' => ['type' => 'string', 'enum' => DecisionVerdict::all()],
                'rationale' => self::str(true),
                'decided_at' => self::str(),
                'recorded_by_profile_id' => self::int(true),
                'route_id' => self::int(true),
                'route_event_id' => self::int(true),
            ], [
                'id', 'tenant_id', 'meeting_id', 'agenda_item_id', 'decision_number', 'verdict',
                'rationale', 'decided_at', 'recorded_by_profile_id', 'route_id', 'route_event_id',
            ]),
            // `invited` means "has not answered", never "declined" — which is
            // why `responded_at` is a separate nullable field.
            'MeetingInvitation' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'meeting_id' => self::int(),
                'profile_id' => self::int(),
                'status' => ['type' => 'string', 'enum' => InvitationStatus::all()],
                'sent_at' => self::str(true),
                'responded_at' => self::str(true),
            ], ['id', 'tenant_id', 'meeting_id', 'profile_id', 'status', 'sent_at', 'responded_at']),
            // WHO WAS IN THE ROOM. A separate record from the invitation, not a
            // state on it: an acceptance is a PREDICTION made before the sitting
            // and this is what happened at it, and the two disagree constantly.
            //
            // `profile_id` is nullable and `attendee_name` is why: a guest from
            // outside the institution has no account, and requiring one would
            // mean either refusing to record them or inventing a person in the
            // identity system to satisfy a foreign key.
            //
            // `was_invited` / `invitation_status` are DERIVED at read time and
            // are the fields that make the disagreement visible. `was_invited`
            // false is somebody who came without being asked — the case this
            // table exists for.
            'MeetingAttendee' => self::object([
                'id' => self::int(),
                'tenant_id' => self::int(),
                'meeting_id' => self::int(),
                'profile_id' => self::int(true),
                'attendee_name' => self::attendeeName(),
                'capacity' => ['type' => 'string', 'enum' => AttendanceCapacity::all()],
                'note' => self::attendanceNote(),
                'recorded_at' => self::str(),
                'recorded_by_profile_id' => self::int(true),
                'was_invited' => self::bool(),
                'invitation_status' => self::str(true),
            ], [
                'id', 'tenant_id', 'meeting_id', 'profile_id', 'attendee_name', 'capacity', 'note',
                'recorded_at', 'recorded_by_profile_id', 'was_invited', 'invitation_status',
            ]),
            // WHAT WAS COUNTED, named so it cannot be mistaken for a quorum
            // check. `quorum_evaluated` is always false and always present:
            // Whity holds no quorum rule for any body and evaluates none, and a
            // bare attendee count on a meeting record reads as "the body was
            // quorate" on every screen it reaches. A field that only appeared
            // when something HAD been checked would be one consumers learn to
            // ignore.
            'MeetingAttendanceCount' => self::object([
                'attendees' => self::int(),
                'attendees_who_held_an_invitation' => self::int(),
                'attendees_who_did_not' => self::int(),
                'invitations_issued' => self::int(),
                'invited_who_did_not_attend' => self::int(),
                'quorum_evaluated' => self::bool(),
                'basis' => self::str(),
            ], [
                'attendees', 'attendees_who_held_an_invitation', 'attendees_who_did_not',
                'invitations_issued', 'invited_who_did_not_attend', 'quorum_evaluated', 'basis',
            ]),
            'MeetingAttendanceResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('MeetingAttendee')],
                'counted' => SchemaBuilder::ref('MeetingAttendanceCount'),
            ], ['data', 'counted']),
            'MeetingAttendanceEntryRequest' => self::object([
                // ONE of these two identifies the attendee, and a row with
                // neither is refused. `profile_id` for somebody with an account;
                // `attendee_name` for a guest who has none.
                'profile_id' => self::int(true),
                'attendee_name' => self::attendeeName(),
                // DESCRIPTIVE ONLY. Nothing branches on it: it is not a
                // permission, not a vote weight, and not an input to any count
                // that claims to be a quorum. It exists because an attendance
                // list on which a substitute is indistinguishable from a member
                // cannot answer the question anybody asks it afterwards.
                'capacity' => ['type' => 'string', 'enum' => AttendanceCapacity::all()],
                'note' => self::attendanceNote(),
            ], []),
            'MeetingAttendanceRequest' => self::object([
                // REQUIRED, and its absence is not an empty list: a client that
                // forgot the key means something different from one that sent
                // `[]`, which records that nobody attended. Sending the whole
                // set REPLACES the stored one.
                'attendees' => [
                    'type' => 'array',
                    'items' => SchemaBuilder::ref('MeetingAttendanceEntryRequest'),
                    'maxItems' => AttendanceEntry::MAX_ATTENDEES,
                ],
            ], ['attendees']),
            'MeetingListResponse' => self::listEnvelope('Meeting'),
            'MeetingResponse' => self::dataEnvelope(SchemaBuilder::ref('Meeting')),
            'MeetingAgendaItemListResponse' => self::listEnvelope('MeetingAgendaItem'),
            'MeetingAgendaItemResponse' => self::dataEnvelope(SchemaBuilder::ref('MeetingAgendaItem')),
            'MeetingDecisionListResponse' => self::listEnvelope('MeetingDecision'),
            'MeetingInvitationListResponse' => self::listEnvelope('MeetingInvitation'),
            'MeetingInvitationResponse' => self::dataEnvelope(SchemaBuilder::ref('MeetingInvitation')),
            'MeetingDetailResponse' => self::dataEnvelope(
                ['allOf' => [
                    SchemaBuilder::ref('Meeting'),
                    self::object([
                        'body' => SchemaBuilder::ref('ConveningBody'),
                        'agenda' => ['type' => 'array', 'items' => SchemaBuilder::ref('MeetingAgendaItem')],
                        'decisions' => ['type' => 'array', 'items' => SchemaBuilder::ref('MeetingDecision')],
                        'invitations' => ['type' => 'array', 'items' => SchemaBuilder::ref('MeetingInvitation')],
                        // BOTH, and neither derived from the other. Who was
                        // asked and who came are separate facts recorded at
                        // different times, and they disagree constantly.
                        'attendance' => ['type' => 'array', 'items' => SchemaBuilder::ref('MeetingAttendee')],
                    ], ['body', 'agenda', 'decisions', 'invitations', 'attendance']),
                ]]
            ),
            'MeetingCreateRequest' => self::object([
                'body_id' => self::int(),
                'title' => ['oneOf' => [self::str(), SchemaBuilder::ref('LocalizedLabel')]],
            ], ['body_id', 'title']),
            'MeetingScheduleRequest' => self::object([
                'scheduled_at' => self::str(),
                'location' => self::name(),
            ], ['scheduled_at']),
            'MeetingScheduleResponse' => self::object([
                'data' => SchemaBuilder::ref('Meeting'),
                // How many people were told the date moved. Zero is a real
                // answer: the sitting had not been announced yet.
                'notified' => self::int(),
            ], ['data', 'notified']),
            'MeetingHoldRequest' => self::object([
                // Supplied rather than defaulted to now(): a body routinely
                // minutes yesterday's sitting, and a server-stamped date would
                // put every such meeting — and the YEAR each of its decision
                // numbers is minted under — on the wrong day.
                'held_at' => self::str(),
            ], []),
            'MeetingInviteResponse' => self::object([
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref('MeetingInvitation')],
                // Two numbers, because they answer different questions: how many
                // people were newly told, and how many already held an invitation
                // and were deliberately left alone. One count would make a no-op
                // re-send indistinguishable from a failure.
                'invited' => self::int(),
                'already_invited' => self::int(),
            ], ['data', 'invited', 'already_invited']),
            'MeetingInvitationRespondRequest' => self::object([
                'status' => ['type' => 'string', 'enum' => InvitationStatus::responses()],
            ], ['status']),
            'MeetingAgendaItemCreateRequest' => self::object([
                'title' => ['oneOf' => [self::str(), SchemaBuilder::ref('LocalizedLabel')]],
                'document_id' => self::int(true),
                'notes' => self::text(),
                // The EXPLICIT confirmation that an item is being attached to a
                // sitting that already happened. Allowed, because a paper tabled
                // on the day is minuted afterwards — and never silent, because
                // the other reading is somebody on the wrong screen.
                'allow_held' => self::bool(),
            ], ['title']),
            'MeetingAgendaReorderRequest' => self::object([
                // EVERY item on the agenda, exactly once. A partial list
                // describes an order that omits some items, and both readings of
                // that — leave them, or append them — are guesses.
                'item_ids' => ['type' => 'array', 'items' => self::int(), 'minItems' => 1],
            ], ['item_ids']),
            'MeetingDecisionRequest' => self::object([
                'verdict' => ['type' => 'string', 'enum' => DecisionVerdict::all()],
                'rationale' => self::text(),
                // BOTH OF THESE ARE THE INSTITUTION'S TO ASSIGN, and they are
                // the two halves of one fact: a minute book records that
                // decision N was taken on date D. Both are written by hand, in
                // the institution's own format, often weeks after the sitting.
                //
                // `decision_number` is a STRING and no shape is imposed —
                // `CE-CM-2026-014` and `ق.ع/٢٠٢٦/١٤` are both real. What is
                // bounded is the length and the refusal of characters that are
                // not text. Omit it (or send an empty string) and one is
                // allocated from the body's per-year counter exactly as before,
                // so callers written before this field existed are unaffected.
                //
                // A supplied number that another decision in this tenant already
                // holds is REFUSED, not silently accepted: two minutes under one
                // number cannot be told apart afterwards, which is the whole
                // reason a decision has a number.
                'decision_number' => ['type' => 'string', 'maxLength' => DecisionNumbers::MAX_LENGTH],
                'decided_at' => self::str(),
            ], ['verdict']),
            // WHAT THE DECISION DID, always present beside what it SAID.
            // `applied` false with a `reason` is an ordinary outcome, not an
            // error: the item carried no document, the document has no route, the
            // route never reached this body, or the step it reached is a
            // circulation rather than a gate.
            'MeetingDecisionRouting' => self::object([
                'applied' => self::bool(),
                'reason' => self::str(),
                'explanation' => self::str(),
                'route_id' => self::int(true),
                'step_id' => self::int(true),
                'actor_profile_id' => self::int(true),
                'event_id' => self::int(true),
                // What the STEP concluded, which is not what this body said:
                // under a quorum of `all`, the first of three approvals decides
                // nothing and this is null.
                'decided' => self::str(true),
            ], [
                'applied', 'reason', 'explanation', 'route_id', 'step_id',
                'actor_profile_id', 'event_id', 'decided',
            ]),
            'MeetingDecisionResponse' => self::object([
                'data' => SchemaBuilder::ref('MeetingDecision'),
                'routing' => SchemaBuilder::ref('MeetingDecisionRouting'),
            ], ['data', 'routing']),
            'DocumentConveningEntry' => self::object([
                'agenda_item' => SchemaBuilder::ref('MeetingAgendaItem'),
                'meeting' => SchemaBuilder::ref('Meeting'),
                'body' => SchemaBuilder::ref('ConveningBody'),
                'decisions' => ['type' => 'array', 'items' => SchemaBuilder::ref('MeetingDecision')],
            ], ['agenda_item', 'meeting', 'body', 'decisions']),
            'DocumentConveningResponse' => self::listEnvelope('DocumentConveningEntry'),

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

            // GET /api/build — top-level (not data-enveloped), like /api/health.
            //
            // #1049. Every identifying field is NULLABLE and that is the
            // contract, not an omission: a deployment that cannot establish its
            // own commit must say so, because a plausible-looking wrong value is
            // worse than no value to the monitor comparing this document against
            // /web-build. `source` names which of the three sources answered, so
            // a consumer can tell a baked build identity from a checkout read
            // without inferring it from the presence of a hash.
            //
            // No `build_id`: /web-build has one because Next produces a bundle
            // with an id; PHP loads source, so a second name for the commit
            // could only ever agree with `commit` or be wrong.
            'BuildIdentityResponse' => self::object([
                'commit' => self::str(true),
                'source' => ['type' => 'string', 'enum' => ['build', 'checkout', 'unknown']],
                'core_version' => self::str(),
                'built_at' => self::str(true),
                'booted_at' => self::str(),
                'uptime_seconds' => self::int(),
                'checkout_commit' => self::str(true),
                'applied_migration_count' => self::int(true),
                'latest_applied_migration' => self::str(true),
                'pending_migration_count' => self::int(true),
            ], [
                'commit',
                'source',
                'core_version',
                'built_at',
                'booted_at',
                'uptime_seconds',
                'checkout_commit',
                'applied_migration_count',
                'latest_applied_migration',
                'pending_migration_count',
            ]),

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
                'method' => 'PATCH',
                'path' => '/api/email-domains/{id:\d+}',
                'requiredRole' => 'admin',
                'requiredPermission' => null,
                'schema' => [
                    'summary' => "Change a domain's default role or whether it auto-provisions",
                    'tags' => ['email-domains'],
                    'request' => 'TenantEmailDomainUpdateRequest',
                    'responses' => [
                        200 => self::jsonResponse('The updated domain registration', 'TenantEmailDomainResponse'),
                        400 => self::errorResponse('Tenant context is required'),
                        404 => self::errorResponse('Domain registration not found'),
                        422 => self::errorResponse(
                            'No changes given, or default_role_id is not a role this tenant may assign'
                        ),
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
                'description' => 'Returns EVERY matching group unless `page` or `per_page` is sent, in which case '
                    . 'one page comes back with a `pagination` envelope. Pagination is opt-in because this list '
                    . 'also populates dropdowns and the tags screen\'s id-to-label map, which would silently '
                    . 'truncate; `sort`, `dir` and `q` apply either way. There is no sort by display name: it is '
                    . 'a bilingual JSON object with no member-extraction syntax common to both supported engines. '
                    . 'Searching it works.',
                'tags' => ['taxonomy'],
                'parameters' => [
                    self::queryParam('q', 'string', 'Case-insensitive substring match on the group key and its display names'),
                    self::queryParam('sort', 'string', 'One of key, created, updated. An unrecognised key is ignored rather than refused.'),
                    self::queryParam('dir', 'string', 'asc (default) or desc'),
                    self::queryParam('page', 'integer', 'Page number (1-based). Sending it opts this list into pagination.'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100). Sending it opts this list into pagination.'),
                ],
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
                'description' => 'Returns EVERY matching tag unless `page` or `per_page` is sent, in which case '
                    . 'one page comes back with a `pagination` envelope. Pagination is opt-in because this list '
                    . 'also populates pickers and id-to-label maps that would silently truncate; `sort`, `dir` '
                    . 'and `q` apply either way.',
                'tags' => ['taxonomy'],
                'parameters' => [
                    self::queryParam('group_id', 'integer', 'Only tags in this group'),
                    self::queryParam('q', 'string', 'Case-insensitive substring match on the tag name and its group\'s key and display name'),
                    self::queryParam('sort', 'string', 'One of name, group, created. An unrecognised key is ignored rather than refused.'),
                    self::queryParam('dir', 'string', 'asc (default) or desc'),
                    self::queryParam('page', 'integer', 'Page number (1-based). Sending it opts this list into pagination.'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100). Sending it opts this list into pagination.'),
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
                    self::queryParam(
                        'sort',
                        'string',
                        'Order by one of "title", "created_at" or "template_name". Omit for the order '
                        . 'documents were recorded in, newest first. An unknown value is a 400 rather '
                        . 'than an ignored parameter, so a client can never draw a sort indicator on a '
                        . 'column the rows are not ordered by.'
                    ),
                    self::queryParam(
                        'direction',
                        'string',
                        '"asc" or "desc". Defaults per field — ascending for the two text columns, '
                        . 'descending for created_at — and the order actually applied is echoed back '
                        . 'in `sort`. Requires `sort`.'
                    ),
                ],
                'responses' => [
                    200 => self::jsonResponse('The documents the caller may see, with pagination', 'DocumentListResponse'),
                    400 => self::errorResponse('A required view parameter is missing, ou_id is not a unit in this tenant, or the sort is not one this list offers'),
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
            self::permissionRoute(
                'POST',
                '/api/documents/{id:\\d+}/routes/from-template',
                'documents:route',
                [
                    'summary' => 'Apply a route template to a document: copy its stages and branches into a live route',
                    'tags' => ['documents'],
                    'request' => 'DocumentRouteFromTemplateRequest',
                    'responses' => [
                        201 => self::jsonResponse(
                            'The issued route with the copied steps and edges, its template provenance, '
                            . 'and how many recipients the first step resolved to and delivered',
                            'DocumentRouteResponse'
                        ),
                        403 => self::errorResponse(
                            'The caller may route documents but may not read route templates '
                            . '(route_templates:read)'
                        ),
                        404 => self::errorResponse(
                            'Document not visible to the caller, or no such template in this tenant'
                        ),
                        422 => self::errorResponse(
                            'A design with no stages, a branch leaving a stage that produces no verdict, '
                            . 'a rule kind nothing registers any more, or more stages than the tenant\'s '
                            . 'documents.routing_max_steps allows right now'
                        ),
                    ] + self::authErrors(),
                ]
            ),
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
                    self::queryParam('sort', 'string', 'Sort key: `name` (default) or `rule`. `rule` orders by the rule KIND slug, not by the localised label the screen renders — the server cannot order by a string the client computes, but the kind still groups every row that renders the same label together. An unrecognised key falls back to the default rather than erroring.'),
                    self::queryParam('dir', 'string', 'Sort direction, `asc` or `desc`. Anything else is read as `asc`.'),
                    self::queryParam('q', 'string', 'Case-insensitive substring match on a group\'s name or description — the two fields rendered verbatim. The rule kind is a slug the screen never shows, so it is not searched. The term narrows `pagination.total` too.'),
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
     * Document ROUTE TEMPLATES (#1027) — the API the node-based flow editor
     * speaks.
     *
     * A template is a reusable, BRANCHING route DESIGN: the seam migration 112
     * named ("a `document_route_templates` / `document_route_template_steps`
     * pair") and migration 120 takes. It is to a route what `document_templates`
     * is to `documents` — the thing DESIGNED, with a different lifetime from the
     * thing that HAPPENED, and the append-only trail hangs off the second.
     *
     * READING A DESIGN AND DESIGNING ONE ARE SEPARATE PERMISSIONS
     * -----------------------------------------------------------
     * `route_templates:read` and `route_templates:write`, not one slug and not
     * `documents:route`. Routing a document is an everyday act many people
     * perform; designing the flow every document of a kind will follow is an act
     * of organisational policy. A clerk who may send a form onward should not
     * thereby be able to rewrite where every form goes, and collapsing the two
     * would make that distinction inexpressible.
     *
     * THE GRAPH HAS ITS OWN VERB, AND IT IS A PUT
     * --------------------------------------------
     * `PATCH /{id}` renames; `PUT /{id}/graph` replaces the canvas. The editor's
     * unit of work is the whole drawing — an author moves four nodes, deletes
     * one, draws an edge and presses save — and expressing that as a diff would
     * mean the client computing which was which, on the side of the wire that
     * cannot verify it.
     *
     * THERE IS NO PREVIEW ROUTE HERE, AND THAT IS THE POINT
     * -----------------------------------------------------
     * "How many people does this node reach?" is already answered exactly by
     * `POST /api/user-groups/preview` (#1003) — a count plus a sample bounded by
     * `groups.preview_sample_size`. The editor calls it per node. A second
     * preview would be a second implementation of the resolver's semantics
     * (active memberships only, the direct membership role, resource-scoped
     * grants excluded) free to drift from the first in whichever direction was
     * last edited.
     *
     * NOTHING HERE INSTANTIATES A TEMPLATE ONTO A DOCUMENT
     * ----------------------------------------------------
     * That needs the engine to follow verdict edges (#1014). A route that
     * "applied" a branching design today would have to flatten it into a linear
     * one — silently doing less than the canvas draws, which is the precise
     * failure the routing subsystem is written against. Filed with migration
     * 112's own seam rather than half-built.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function documentRouteTemplateRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/document-route-templates', 'route_templates:read', [
                'summary' => "This tenant's route template DESIGNS, by name (paginated, no people counts)",
                'tags' => ['document-route-templates'],
                'parameters' => [
                    self::queryParam('page', 'integer', '1-indexed page (default 1)'),
                    self::queryParam('per_page', 'integer', 'Page size (default 25, max 100)'),
                ],
                'responses' => [
                    200 => self::jsonResponse("The tenant's route templates with pagination", 'RouteTemplateListResponse'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/document-route-templates', 'route_templates:write', [
                'summary' => 'Start a route template. Created EMPTY — the graph is saved by its own verb',
                'tags' => ['document-route-templates'],
                'request' => 'RouteTemplateCreateRequest',
                'responses' => [
                    201 => self::jsonResponse('The created template', 'RouteTemplateResponse'),
                    409 => self::errorResponse('A template with that name already exists in this tenant'),
                    422 => self::errorResponse('A missing, empty or over-long name'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PUT', '/api/document-route-templates/{id:\\d+}/graph', 'route_templates:write', [
                'summary' => 'REPLACE the template\'s whole graph — every step and every edge, atomically',
                'tags' => ['document-route-templates'],
                'request' => 'RouteTemplateGraphRequest',
                'responses' => [
                    200 => self::jsonResponse('The saved graph', 'RouteTemplateGraphResponse'),
                    404 => self::errorResponse('Route template not found in this tenant'),
                    422 => self::errorResponse(
                        'A rule kind nothing registered, a config the rule refused, an edge naming a position '
                        . 'that is not on the canvas, an edge leaving a step that is not a decision, or more '
                        . 'steps than documents.routing_max_steps allows'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/document-route-templates/{id:\\d+}', 'route_templates:read', [
                'summary' => 'One design, with its steps, its edges and the quorum an unset step will follow',
                'tags' => ['document-route-templates'],
                'responses' => [
                    200 => self::jsonResponse('The template and its graph', 'RouteTemplateGraphResponse'),
                    404 => self::errorResponse('Route template not found in this tenant'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('PATCH', '/api/document-route-templates/{id:\\d+}', 'route_templates:write', [
                'summary' => 'Rename or re-describe a template. The graph is untouched — it has its own verb',
                'tags' => ['document-route-templates'],
                'request' => 'RouteTemplateUpdateRequest',
                'responses' => [
                    200 => self::jsonResponse('The updated template', 'RouteTemplateResponse'),
                    404 => self::errorResponse('Route template not found in this tenant'),
                    409 => self::errorResponse('Another template in this tenant already has that name'),
                    422 => self::errorResponse('An empty or over-long name, or a non-text description'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/document-route-templates/{id:\\d+}', 'route_templates:write', [
                'summary' => 'Discard a design. Routes already issued from it are untouched — they carry their own steps',
                'tags' => ['document-route-templates'],
                'responses' => [
                    200 => self::jsonResponse('The deleted template id', 'RouteTemplateDeleteResponse'),
                    404 => self::errorResponse('Route template not found in this tenant'),
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
     * Tabular reports, emitted as documents (#947 item 6).
     *
     * Both routes are gated on `documents:render`, and the source is gated a
     * SECOND time on its own permission inside the handler. The two mean
     * different things: producing a report spends a headless-browser page and
     * writes to the tenant's storage, which is what `documents:render` governs;
     * seeing the rows is governed by whatever already governs reading that
     * data. There is deliberately no `reports:run` permission — a report is a
     * READ, and a second vocabulary for it would be a second answer to a
     * question that already has one.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function reportRoutes(): array
    {
        return [
            self::permissionRoute('GET', '/api/reports', 'documents:render', [
                'summary' => 'List the reports this caller may run',
                'description' => 'FILTERED to what the caller may actually run, not annotated with a '
                    . 'permitted flag: listing a report over data the caller cannot see would publish '
                    . 'its existence, and would leave every client to re-implement the same filter '
                    . 'differently. `required_permission` is carried so a screen can hide what it '
                    . 'must without asking.',
                'tags' => ['reports'],
                'responses' => [
                    200 => self::jsonResponse('The runnable reports', self::object(
                        ['data' => ['type' => 'array', 'items' => self::object(
                            [
                                'key' => ['type' => 'string'],
                                'label' => ['type' => 'string'],
                                'origin' => ['type' => 'string'],
                                'required_permission' => ['type' => 'string'],
                            ],
                            ['key', 'label', 'origin', 'required_permission']
                        )]],
                        ['data']
                    )),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/reports/{source:[a-z][a-z0-9_]*}/document', 'documents:render', [
                'summary' => 'Run a report and issue it as a document',
                'description' => 'Runs the named source and renders its rows as a flowing, paginated '
                    . 'document — a real `documents` record with an immutable artifact, so routing, '
                    . 'verification and the organizer all apply to it. Bounded by '
                    . '`documents.flow_max_table_rows`; when the ceiling bites, `truncated` is true '
                    . 'AND the document says so on its own first page, because a reader holding a '
                    . 'printed subset has no other way to know it is one. Answers 202 rather than 201 '
                    . 'when the record was created but the render did not produce an artifact — the '
                    . 'document exists and can be re-rendered against the same id.',
                'tags' => ['reports'],
                'responses' => [
                    201 => self::jsonResponse('The issued document', self::dataEnvelope(self::object(
                        [
                            'document_id' => ['type' => 'integer'],
                            'title' => ['type' => 'string'],
                            'page_count' => ['type' => 'integer'],
                            'row_count' => ['type' => 'integer'],
                            'total_rows' => ['type' => 'integer'],
                            'truncated' => ['type' => 'boolean'],
                            'content_url' => ['type' => 'string', 'nullable' => true],
                        ],
                        ['document_id', 'title', 'row_count', 'total_rows', 'truncated']
                    ))),
                    202 => self::errorResponse('The document was recorded but no artifact was stored'),
                    404 => self::errorResponse('No such report, or the caller may not read its data'),
                    422 => self::errorResponse('The document was refused (a tenant ceiling, or a tree the renderer would not accept)'),
                    503 => self::errorResponse('The report could not be run, or rendering is unavailable'),
                ] + self::authErrors(),
            ]),
        ];
    }

    /**
     * QR verification on documents (#1036) — the public scan surface, and the
     * authenticated code management beside it.
     *
     * THE SECURITY CLAIM THESE SHAPES ENCODE, because an OpenAPI document is
     * where an integrator forms their model of what a token is worth: the token
     * IDENTIFIES a document and never AUTHORISES access to one. The public route
     * has no response field that could carry a document id, and the
     * `by-verification` route is gated on `documents:read` and answers 404 to a
     * caller the ordinary visibility policy refuses — the same 404, with the
     * same sentence, that `GET /api/documents/{id}` gives them.
     *
     * Response schemas are declared INLINE rather than as named components.
     * These four shapes have exactly one consumer each and none is referenced by
     * another route, so a named component would be a second place to look for a
     * thing that is only ever read here.
     *
     * @return list<array{method: string, path: string, requiredRole: ?string, requiredPermission: ?string, schema: array<string, mixed>}>
     */
    private static function documentQrRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/document-verifications/{token}',
                'requiredRole' => null,
                'requiredPermission' => null,
                'schema' => [
                    'summary' => 'Verify a document from the QR code printed on it (public, rate-limited)',
                    'description' =>
                        'PUBLIC and unauthenticated by design: the caller is somebody holding a printed '
                        . 'sheet, and the paper is the whole of their relationship with this system. '
                        . 'Always 200. An unknown token, a malformed one, a withdrawn one and a superseded '
                        . 'one produce the SAME body at the default disclosure level, so this endpoint '
                        . 'cannot be asked whether a document exists. A tenant may raise '
                        . '`documents.qr_public_detail` to `stage`, which adds the current routing verb '
                        . 'and distinguishes a revoked code from an unrecognised one, or LOWER it to '
                        . '`undated`, which withholds `issued_on` and leaves everything else as '
                        . '`minimal`. It never returns a '
                        . 'document id, a title, any content, any recipient, or any name of a person or '
                        . 'unit — a signed-in reader who wants the record calls '
                        . 'GET /api/documents/by-verification/{token}, where RBAC decides unchanged.',
                    'tags' => ['documents'],
                    'responses' => [
                        200 => self::jsonResponse(
                            'Whether the code verifies, and the minimum that makes that meaningful',
                            [
                                'type' => 'object',
                                'properties' => [
                                    'data' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'verified' => ['type' => 'boolean'],
                                            'reason' => [
                                                'type' => 'string',
                                                'description' =>
                                                    'Present only when verified is false. `unrecognised` '
                                                    . 'covers unknown, malformed, withdrawn and superseded '
                                                    . 'at the default disclosure level.',
                                                'enum' => ['unrecognised', 'withdrawn', 'superseded'],
                                            ],
                                            'revoked_on' => [
                                                'type' => 'string',
                                                'nullable' => true,
                                                'description' => 'Date only, and only at the `stage` level',
                                            ],
                                            'reference' => [
                                                'type' => 'string',
                                                'description' => 'The short reference printed beneath the code',
                                            ],
                                            'issuer' => [
                                                'type' => 'string',
                                                'description' => 'The issuing ORGANISATION, never a person or a unit',
                                            ],
                                            'issued_on' => [
                                                'type' => 'string',
                                                'nullable' => true,
                                                'description' =>
                                                    'The issue DATE (YYYY-MM-DD), not a timestamp. ABSENT '
                                                    . 'at the `undated` disclosure level — absent rather '
                                                    . 'than null, because null would be a statement about '
                                                    . 'the document and this is a statement about the page.',
                                            ],
                                            'stage' => [
                                                'type' => 'string',
                                                'description' => 'Only at the `stage` disclosure level',
                                                'enum' => ['issued', 'forwarded', 'acknowledged', 'returned', 'noted'],
                                            ],
                                            'stage_on' => [
                                                'type' => 'string',
                                                'nullable' => true,
                                                'description' => 'Date only, and only at the `stage` level',
                                            ],
                                        ],
                                        'required' => ['verified'],
                                    ],
                                ],
                            ]
                        ),
                        429 => self::errorResponse('Too many verification attempts from this address'),
                        503 => self::errorResponse('Verification is temporarily unavailable'),
                    ],
                ],
            ],
            self::permissionRoute('GET', '/api/documents/by-verification/{token}', 'documents:read', [
                'summary' => 'Resolve a scanned QR code to the record it names, under the existing RBAC',
                'description' =>
                    'The scan-through. The token selects a ROW; DocumentVisibilityPolicy then decides, '
                    . 'unchanged and with no knowledge that a token was involved. A caller without reach '
                    . 'gets 404 with the same message GET /api/documents/{id} gives them — holding the '
                    . 'paper confers nothing. A code minted in another tenant collapses into the same 404. '
                    . '`code_honoured` says whether the printing that got the caller here is still the '
                    . 'current one.',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The document this code names', [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'integer'],
                                    'code_honoured' => ['type' => 'boolean'],
                                ],
                                'required' => ['id', 'code_honoured'],
                            ],
                        ],
                    ]),
                    404 => self::errorResponse(
                        'No such code, or the document it names is not visible to the caller'
                    ),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('GET', '/api/documents/{id:\d+}/qr', 'documents:read', [
                'summary' => 'The verification code on a document, and the record of it being scanned',
                'description' =>
                    'The record page panel. `enabled` composes the tenant setting with the template '
                    . 'flag; `configured` is separate because "this instance has no public address" and '
                    . '"this tenant switched it off" are different problems with different fixes. '
                    . '`token` is null in TWO different states — never minted, and withdrawn — so '
                    . '`retired` is what separates them: it lists the codes this document has carried '
                    . 'and stopped honouring, newest first, each with the reason (`withdrawn` or '
                    . '`superseded`). A retired entry carries the human reference, never the token and '
                    . 'never a verification URL. '
                    . 'Anonymous scans appear with `scanner_profile_id: null` and carry nothing else '
                    . 'about the scanner — no address, no device — because nothing else is stored.',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The live code, if any, and the scan trail', [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'properties' => [
                                    'enabled' => ['type' => 'boolean'],
                                    'configured' => ['type' => 'boolean'],
                                    'token' => [
                                        'type' => 'object',
                                        'nullable' => true,
                                        'properties' => [
                                            'reference' => ['type' => 'string'],
                                            'verification_url' => ['type' => 'string'],
                                            'issued_at' => ['type' => 'string', 'nullable' => true],
                                            'issued_by' => ['type' => 'integer', 'nullable' => true],
                                        ],
                                    ],
                                    'retired' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'total' => ['type' => 'integer'],
                                            'recent' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'reference' => ['type' => 'string'],
                                                        'issued_at' => [
                                                            'type' => 'string',
                                                            'nullable' => true,
                                                        ],
                                                        'revoked_at' => ['type' => 'string'],
                                                        'revoked_by' => [
                                                            'type' => 'integer',
                                                            'nullable' => true,
                                                        ],
                                                        'reason' => [
                                                            'type' => 'string',
                                                            'enum' => ['withdrawn', 'superseded'],
                                                        ],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                    'scans' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'total' => ['type' => 'integer'],
                                            'recent' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'id' => ['type' => 'integer'],
                                                        'document_id' => ['type' => 'integer'],
                                                        'qr_token_id' => ['type' => 'integer'],
                                                        'scanner_profile_id' => [
                                                            'type' => 'integer',
                                                            'nullable' => true,
                                                        ],
                                                        'outcome' => [
                                                            'type' => 'string',
                                                            'enum' => ['verified', 'refused'],
                                                        ],
                                                        'scanned_at' => ['type' => 'string'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ]),
                    404 => self::errorResponse('No such document, or it is not visible to the caller'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('POST', '/api/documents/{id:\d+}/qr', 'documents:render', [
                'summary' => 'Issue a new verification code, retiring the current one',
                'description' =>
                    'ALWAYS ROTATES. The previous code is retired as `superseded` in the same '
                    . 'transaction, so anybody holding an older printing stops being able to confirm it — '
                    . 'which is the reason to call this, and why re-rendering a document deliberately '
                    . 'does NOT do it.',
                'tags' => ['documents'],
                'responses' => [
                    201 => self::jsonResponse('The new code', [
                        'type' => 'object',
                        'properties' => [
                            'data' => [
                                'type' => 'object',
                                'properties' => [
                                    'reference' => ['type' => 'string'],
                                    'verification_url' => ['type' => 'string'],
                                    'issued_at' => ['type' => 'string', 'nullable' => true],
                                    'issued_by' => ['type' => 'integer', 'nullable' => true],
                                ],
                            ],
                        ],
                    ]),
                    404 => self::errorResponse('No such document, or it is not visible to the caller'),
                    409 => self::errorResponse('QR verification is switched off for this template or tenant'),
                    503 => self::errorResponse('This instance has no public address configured'),
                ] + self::authErrors(),
            ]),
            self::permissionRoute('DELETE', '/api/documents/{id:\d+}/qr', 'documents:render', [
                'summary' => 'Stop honouring the verification code on a document',
                'description' =>
                    'The answer to "paper cannot be recalled". The symbol stays legible on every copy in '
                    . 'the world and stops confirming anything; the row survives with its timestamps. '
                    . '204 whether or not a code was live, so a second click is not an error and the '
                    . 'route does not report whether a document has one.',
                'tags' => ['documents'],
                'responses' => [
                    204 => ['description' => 'The code is no longer honoured'],
                    404 => self::errorResponse('No such document, or it is not visible to the caller'),
                ] + self::authErrors(),
            ]),
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
                'summary' => 'What would break if this block changed: the templates and blocks that instance it',
                'description' =>
                    'A block is POINTER-referenced (a `blockInstance` element), so editing it propagates '
                    . 'to everything that instances it — and unlike delete, an edit is never refused. '
                    . 'This is the answer a client needs before offering either action. There are TWO kinds '
                    . 'of user: `templates`, and `blocks`, since a block may contain another block. Both are '
                    . 'row-filtered to what the caller may see; `total` counts EVERY reference of both kinds '
                    . 'in the tenant and `hidden` is the difference, so a caller with narrow reach is told '
                    . 'the edit reaches further than they can see instead of being quietly understated. '
                    . '`total > 0` means exactly that a DELETE would be refused with 409.',
                'tags' => ['documents'],
                'responses' => [
                    200 => self::jsonResponse('The referencing templates and blocks, plus the unfiltered total', 'DocumentBlockUsageResponse'),
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
     * A list whose `pagination` block appears ONLY when the caller asked to page.
     *
     * For an endpoint that returned every row before it adopted the shared list
     * contract and kept that default (see {@see \Whity\Api\TagsApiHandler::list()}):
     * `data` is always there, `pagination` is present exactly when `page` or
     * `per_page` was sent. Declaring it optional rather than required is what
     * stops a generated client from insisting on a field the unpaginated
     * response does not carry.
     *
     * @return array<string, mixed>
     */
    private static function optionallyPaginatedListEnvelope(string $component): array
    {
        return self::object(
            [
                'data' => ['type' => 'array', 'items' => SchemaBuilder::ref($component)],
                'pagination' => SchemaBuilder::ref('Pagination'),
            ],
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
    /**
     * An ATTENDEE'S typed name: nullable, and bounded by the same column shape
     * every other VARCHAR(255) identifier is.
     *
     * Nullable and not merely optional, because null is the meaningful value —
     * it is how a row says "this attendee is identified by their profile, not by
     * a name I typed". {@see self::name()} cannot express that: its flag means
     * NON-EMPTY, which is the opposite question.
     *
     * @return array<string, mixed>
     */
    private static function attendeeName(): array
    {
        return [
            'type' => 'string',
            'maxLength' => AttendanceRepository::NAME_MAX,
            'nullable' => true,
        ];
    }

    /**
     * The free-text note on one attendance row.
     *
     * Bounded at {@see AttendanceEntry::NOTE_MAX} rather than
     * {@see InputLimits::TEXT_MAX}, because that is what the handler actually
     * refuses past. A declaration citing the larger bound would let a generated
     * client build a request the schema calls valid and the API answers 422 to —
     * the exact drift {@see self::password()} exists to record.
     *
     * @return array<string, mixed>
     */
    private static function attendanceNote(): array
    {
        return [
            'type' => 'string',
            'maxLength' => AttendanceEntry::NOTE_MAX,
            'nullable' => true,
        ];
    }

    /**
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
     * A TIME-WINDOW TYPE KEY, as {@see WindowTypeRegistry::isValidKey()} accepts
     * it: a lowercase slug, optionally namespaced with one colon.
     *
     * Not nullable, and no nullable variant exists: unlike an OU type, which a
     * unit may legitimately not have, a period always has a kind — the key IS
     * the identity of the row being created.
     *
     * @return array<string, mixed>
     */
    private static function windowTypeKey(): array
    {
        return [
            'type' => 'string',
            'maxLength' => WindowTypeRegistry::KEY_MAX_LENGTH,
            'pattern' => '^[a-z][a-z0-9_]*(:[a-z][a-z0-9_]*)?$',
        ];
    }

    /**
     * A calendar DATE, `YYYY-MM-DD`, with no time and no zone.
     *
     * A period boundary is a DAY. Rendering it as an instant invites a timezone
     * to move it, which moves which period a record falls into — the one thing
     * this subsystem cannot allow to happen quietly. The pattern is enforced as
     * well as the format because `format` is advisory to most generators, and
     * the handler additionally refuses a date that merely LOOKS valid
     * (`2026-02-30` parses in a lenient reader and rolls into March).
     *
     * @return array<string, mixed>
     */
    private static function date(): array
    {
        return ['type' => 'string', 'format' => 'date', 'pattern' => '^\\d{4}-\\d{2}-\\d{2}$'];
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
        return (new Router())->versionedPath($unversionedPath);
    }
}
