<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\SettingsValidationException;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Website Settings API handler (Website Settings feature).
 *
 * Exposes the global + per-tenant website settings as an RBAC-protected,
 * tenant-scoped surface:
 *  - GET   /api/v1/settings        (settings:read)   — the caller tenant's
 *        effective values, the registry shape, and which keys are overridden.
 *  - PATCH /api/v1/settings        (settings:write)  — upsert the CURRENT
 *        tenant's overrides; a null/empty value clears an override.
 *  - GET   /api/v1/settings/global (settings:manage) — the global defaults.
 *  - PATCH /api/v1/settings/global (settings:manage) — upsert global defaults.
 *
 * API-layer separation: this handler issues NO SQL — every read/write goes
 * through {@see SettingsService} and its repositories, which carry the explicit
 * `tenant_id` predicate for the tenant-owned `tenant_settings` table. The
 * tenant always comes from {@see TenantContext}, never from the request body, so
 * a caller can only ever edit ITS OWN tenant's overrides.
 *
 * The route-level guard ({@see \Whity\Http\RbacMiddleware}) enforces the
 * required permission; this handler RE-checks it against the authoritative
 * {@see RoleChecker} as defence in depth, so the endpoint stays safe even if it
 * were ever mounted without the route gate. Failures use the uniform
 * `{ error, details? }` envelope ({@see Response::error()}); raw exceptions are
 * never leaked and validation failures return 422 with per-field details.
 *
 * Holds no request state — safe for a FrankenPHP worker.
 */
final class SettingsApiHandler
{
    private SettingsService $settings;
    private RoleChecker $roleChecker;

    public function __construct(SettingsService $settings, RoleChecker $roleChecker)
    {
        $this->settings = $settings;
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/v1/settings — the caller tenant's effective settings.
     */
    public function get(Request $request): Response
    {
        $context = $this->authorize($request, CorePermissions::SETTINGS_READ);
        if ($context instanceof Response) {
            return $context;
        }
        ['tenantId' => $tenantId] = $context;

        try {
            $textKeys = array_flip(SettingsRegistry::textKeys());
            $allEffective = $this->settings->effective($tenantId);
            $allOverridden = $this->settings->overriddenKeys($tenantId);

            return Response::json([
                'data' => [
                    // Asset-kind keys are excluded from the settings API surface:
                    // they are managed via the branding endpoints. Only text-kind
                    // keys are visible here (WC-233).
                    'effective' => array_intersect_key($allEffective, $textKeys),
                    'registry' => SettingsRegistry::describeText(),
                    'overridden' => array_values(array_filter(
                        $allOverridden,
                        static fn (string $k): bool => isset($textKeys[$k])
                    )),
                    // WC-224: whether THIS caller's tenant has a per-tenant override
                    // layer. The system tenant (0) has globals only — it can never
                    // persist a per-tenant override — so the client must hide the
                    // editable tenant form and point the user at Global defaults
                    // instead of letting a write 422 (writeTenant rejects tenant 0).
                    'tenant_overridable' => $tenantId !== SettingsService::SYSTEM_TENANT_ID,
                ],
            ], 200);
        } catch (\Throwable $e) {
            error_log('[SettingsApiHandler] get failed: ' . $e->getMessage());
            return Response::error('Failed to fetch settings', 500);
        }
    }

    /**
     * PATCH /api/v1/settings — upsert the current tenant's overrides.
     *
     * Body: `{ "settings": { "<key>": <value|null>, ... } }`. A null or empty
     * value clears the override (falls back to global/default).
     */
    public function patch(Request $request): Response
    {
        $context = $this->authorize($request, CorePermissions::SETTINGS_WRITE);
        if ($context instanceof Response) {
            return $context;
        }
        ['tenantId' => $tenantId] = $context;

        return $this->applyWrites(
            $request,
            fn (string $key, ?string $value): null => $this->writeTenant($tenantId, $key, $value),
            fn (int $tenantIdInner): array => array_intersect_key(
                $this->settings->effective($tenantIdInner),
                array_flip(SettingsRegistry::textKeys())
            ),
            $tenantId
        );
    }

    /**
     * GET /api/v1/settings/global — the global defaults.
     */
    public function getGlobal(Request $request): Response
    {
        $context = $this->authorize($request, CorePermissions::SETTINGS_MANAGE);
        if ($context instanceof Response) {
            return $context;
        }

        try {
            $textKeys = array_flip(SettingsRegistry::textKeys());
            $allGlobal = $this->settings->getGlobal();

            return Response::json([
                'data' => [
                    // Asset-kind keys excluded from the settings API surface (WC-233).
                    'global' => array_intersect_key($allGlobal, $textKeys),
                    'registry' => SettingsRegistry::describeText(),
                ],
            ], 200);
        } catch (\Throwable $e) {
            error_log('[SettingsApiHandler] getGlobal failed: ' . $e->getMessage());
            return Response::error('Failed to fetch global settings', 500);
        }
    }

    /**
     * PATCH /api/v1/settings/global — upsert the global defaults.
     *
     * Body: `{ "settings": { "<key>": <value|null>, ... } }`. A null value
     * clears the global default (falls back to the registry default).
     */
    public function patchGlobal(Request $request): Response
    {
        $context = $this->authorize($request, CorePermissions::SETTINGS_MANAGE);
        if ($context instanceof Response) {
            return $context;
        }

        return $this->applyWrites(
            $request,
            fn (string $key, ?string $value): null => $this->writeGlobal($key, $value),
            fn (): array => array_intersect_key(
                $this->settings->getGlobal(),
                array_flip(SettingsRegistry::textKeys())
            )
        );
    }

    /**
     * Shared write path for both PATCH endpoints: parse the `settings` map,
     * apply each key through $writer (registry-validated), and return the
     * recomputed view on success or a 422 with per-field details on the first
     * validation failure.
     *
     * @param Request                     $request The incoming request.
     * @param callable(string,?string):void $writer Persists one (key, value); null clears.
     * @param callable(int):array<string,string> $recompute Recomputes the response view.
     * @param int                         $tenantId Tenant for the recompute (0 for global).
     */
    private function applyWrites(
        Request $request,
        callable $writer,
        callable $recompute,
        int $tenantId = 0
    ): Response {
        $body = JsonBody::parsed($request);
        $settings = $body['settings'] ?? null;

        if (!is_array($settings) || $settings === [] || array_is_list($settings)) {
            return Response::error(
                'Request body must include a non-empty "settings" object',
                400
            );
        }

        // Normalise + validate the WHOLE payload first so a partial write never
        // happens: any unknown key or invalid value rejects the entire request.
        $normalised = [];
        $details = [];
        foreach ($settings as $key => $value) {
            if (!is_string($key)) {
                $details['_'] = 'Setting keys must be strings.';
                continue;
            }
            if (!SettingsRegistry::isKnown($key)) {
                $details[$key] = "Unknown setting key: {$key}";
                continue;
            }

            // null or empty-string clears the override/default.
            if ($value === null || $value === '') {
                $normalised[$key] = null;
                continue;
            }
            if (!is_scalar($value)) {
                $details[$key] = 'Value must be a string (or null/empty to clear).';
                continue;
            }

            $stringValue = (string) $value;
            $reason = SettingsRegistry::validate($key, $stringValue);
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
                $writer($key, $value);
            }

            return Response::json(['data' => $recompute($tenantId)], 200);
        } catch (SettingsValidationException $e) {
            // Defence in depth: the registry already validated above, but the
            // service revalidates — surface it as a clean 422, never the raw text.
            return Response::error('Validation failed', 422, [$e->settingKey() => $e->reason()]);
        } catch (\Throwable $e) {
            error_log('[SettingsApiHandler] write failed: ' . $e->getMessage());
            return Response::error('Failed to update settings', 500);
        }
    }

    /**
     * Persist one per-tenant override (helper kept for a precise callable type).
     */
    private function writeTenant(int $tenantId, string $key, ?string $value): null
    {
        $this->settings->setTenant($tenantId, $key, $value);
        return null;
    }

    /**
     * Persist one global default (helper kept for a precise callable type).
     */
    private function writeGlobal(string $key, ?string $value): null
    {
        $this->settings->setGlobal($key, $value);
        return null;
    }

    /**
     * Resolve the tenant + acting user and re-assert the required permission.
     *
     * @param Request $request    The incoming request.
     * @param string  $permission The required `resource:action` permission.
     * @return array{tenantId: int, userId: int}|Response The context, or an error
     *         Response (403) when the tenant is unresolved or the permission is
     *         not held.
     */
    private function authorize(Request $request, string $permission): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->user_id) && is_int($actor->user_id)
            ? $actor->user_id
            : null;

        if ($userId === null || !$this->roleChecker->hasPermission($userId, $permission, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }
}
