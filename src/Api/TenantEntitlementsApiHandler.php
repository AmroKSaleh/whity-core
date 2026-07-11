<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\EntitlementValidationException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * OPERATOR per-tenant entitlements admin API (WC-ent).
 *
 * Lets the platform owner grant/limit a TARGET tenant's capabilities per
 * subscription tier:
 *   GET   /api/tenants/{id}/entitlements  → get()   — the target's effective map
 *   PATCH /api/tenants/{id}/entitlements  → patch() — apply overrides (null clears)
 *
 * This is a cross-tenant PLATFORM operation. The `entitlements:manage` permission
 * is necessary but NOT sufficient: authorize() additionally requires the caller
 * to be acting in the SYSTEM tenant (id 0), so a regular tenant admin — who also
 * holds the permission via the global admin role — can never reach another
 * tenant's entitlements (the cross-tenant escalation guard, mirroring
 * RegistrationsApiHandler / SettingsApiHandler global writes). The target tenant
 * is taken from the URL path, never the body; every write still flows a concrete
 * `tenant_id` into the repository, so tenant isolation holds at the SQL layer too.
 *
 * Holds no request state beyond its injected collaborators — safe for a
 * FrankenPHP worker.
 */
final class TenantEntitlementsApiHandler
{
    private PDO $db;
    private EntitlementService $entitlements;
    private RoleChecker $roleChecker;

    public function __construct(PDO $db, EntitlementService $entitlements, RoleChecker $roleChecker)
    {
        $this->db = $db;
        $this->entitlements = $entitlements;
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/tenants/{id}/entitlements — the target tenant's effective
     * entitlements, which the operator has explicitly overridden, and the
     * registry catalogue (type/default/description) for rendering the editor.
     *
     * @param array<string, string> $params
     */
    public function get(Request $request, array $params): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        $targetId = (int) ($params['id'] ?? 0);
        if (!$this->tenantExists($targetId)) {
            return Response::error('Tenant not found', 404);
        }

        try {
            return Response::json([
                'data' => [
                    'tenant_id'  => $targetId,
                    'effective'  => $this->entitlements->effective($targetId),
                    'overridden' => $this->entitlements->overriddenKeys($targetId),
                    'registry'   => EntitlementRegistry::catalogue(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            error_log('[TenantEntitlementsApiHandler] get failed: ' . $e->getMessage());
            return Response::error('Failed to fetch entitlements', 500);
        }
    }

    /**
     * PATCH /api/tenants/{id}/entitlements — apply a map of entitlement overrides
     * to the target tenant. Body: `{ "entitlements": { "<key>": <value|null>, ... } }`.
     * A null value clears the override (falls back to the baseline default).
     *
     * The whole payload is validated before any write, so an unknown key or
     * invalid value rejects the entire request (never a partial apply).
     *
     * @param array<string, string> $params
     */
    public function patch(Request $request, array $params): Response
    {
        $ctx = $this->authorize($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        ['userId' => $userId] = $ctx;

        $targetId = (int) ($params['id'] ?? 0);
        if (!$this->tenantExists($targetId)) {
            return Response::error('Tenant not found', 404);
        }
        if ($targetId === EntitlementService::SYSTEM_TENANT_ID) {
            return Response::error(
                'The system tenant is implicitly unlimited and has no entitlement overrides',
                409
            );
        }

        $body = JsonBody::parsed($request);
        $entitlements = $body['entitlements'] ?? null;
        if (!is_array($entitlements) || $entitlements === [] || array_is_list($entitlements)) {
            return Response::error('Request body must include a non-empty "entitlements" object', 400);
        }

        $normalised = [];
        $details = [];
        foreach ($entitlements as $key => $value) {
            if (!is_string($key)) {
                $details['_'] = 'Entitlement keys must be strings.';
                continue;
            }
            if (!EntitlementRegistry::isKnown($key)) {
                $details[$key] = "Unknown entitlement key: {$key}";
                continue;
            }
            // Only null clears; a JSON boolean must map to 'true'/'false' BEFORE
            // the empty check so `false` sets false rather than clearing (a raw
            // (string) false is '', which would look like a clear).
            if ($value === null) {
                $normalised[$key] = null;
                continue;
            }
            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (!is_scalar($value)) {
                $details[$key] = 'Value must be a scalar (or null to clear).';
                continue;
            }
            $stringValue = trim((string) $value);
            if ($stringValue === '') {
                $normalised[$key] = null;
                continue;
            }
            $reason = EntitlementRegistry::validate($key, $stringValue);
            if ($reason !== null) {
                $details[$key] = $reason;
                continue;
            }
            $normalised[$key] = $stringValue;
        }

        if ($details !== []) {
            return Response::error('Validation failed', 422, $details);
        }

        try {
            foreach ($normalised as $key => $value) {
                $this->entitlements->set($targetId, $key, $value, $userId);
            }

            return Response::json([
                'data' => [
                    'tenant_id'  => $targetId,
                    'effective'  => $this->entitlements->effective($targetId),
                    'overridden' => $this->entitlements->overriddenKeys($targetId),
                ],
            ], 200);
        } catch (EntitlementValidationException $e) {
            // Defence in depth: the registry validated above, but the service
            // revalidates — surface a clean 422, never the raw text.
            return Response::error('Validation failed', 422, [$e->entitlementKey() => $e->reason()]);
        } catch (\Throwable $e) {
            error_log('[TenantEntitlementsApiHandler] patch failed: ' . $e->getMessage());
            return Response::error('Failed to update entitlements', 500);
        }
    }

    /**
     * Resolve the acting user and enforce `entitlements:manage` AND the
     * system-tenant gate. Returns the acting profile id (for the audit column)
     * or a 403 Response.
     *
     * @return array{userId: int}|Response
     */
    private function authorize(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::ENTITLEMENTS_MANAGE, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::ENTITLEMENTS_MANAGE]);
        }

        // Entitlements govern what a tenant may use — a PLATFORM operation. The
        // permission is necessary but not sufficient: the caller must be acting
        // in the system tenant (id 0). Otherwise any tenant admin (who holds
        // entitlements:manage on the global admin role) could edit another
        // tenant's entitlements (cross-tenant escalation, WC-235 pattern).
        if ($tenantId !== EntitlementService::SYSTEM_TENANT_ID) {
            return Response::error('Entitlements are managed by the system tenant only', 403);
        }

        return ['userId' => $userId];
    }

    /**
     * Whether a tenant row exists. `tenants` is a global registry table (its PK
     * is the tenant id), not tenant-owned, so no tenant predicate applies.
     */
    private function tenantExists(int $tenantId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM tenants WHERE id = :id');
        $stmt->execute([':id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }
}
