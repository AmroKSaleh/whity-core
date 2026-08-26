<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\TimeWindow\WindowTypeRegistry;
use Whity\Core\TimeWindow\WindowTypeRepository;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * Tenant-scoped CRUD for the tenant's own TIME-WINDOW TYPE vocabulary (#1070) —
 * the kinds of period its records are scoped to, and how those kinds nest.
 *
 * PERMISSIONS: reads on `time_windows:read`, writes on `time_windows:write`. New
 * slugs rather than reused ones, because nothing existing means "may govern this
 * tenant's periods" — see {@see \Whity\Core\RBAC\CorePermissions} for why there
 * are four of them, and migration 126 for who they were granted to and why by
 * capability rather than by role name.
 *
 * THE VOCABULARY IS NOT THE CATALOGUE. `GET /api/v1/time-window-types` returns
 * what THIS TENANT uses. `GET /api/v1/time-window-types/catalog` returns what
 * code has DECLARED and could be adopted. They are different questions and
 * conflating them is how a plugin's contribution becomes undiscoverable: an
 * administrator would have to read the plugin's source to learn a key exists
 * before they could adopt it, and a mistyped key would create a tenant-authored
 * type that merely looks like the plugin's.
 *
 * Error bodies are generic and a foreign-tenant id is indistinguishable from
 * "not found" (404), never a cross-tenant leak.
 */
final class TimeWindowTypesApiHandler
{
    public function __construct(
        private readonly WindowTypeRepository $types,
        private readonly WindowTypeRegistry $registry,
    ) {
    }

    /**
     * GET /api/v1/time-window-types — the tenant's vocabulary.
     */
    public function list(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            return Response::json(['data' => $this->types->listForTenant($tenantId)]);
        } catch (\Exception $e) {
            error_log('[TimeWindowTypesApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch time window types', 500);
        }
    }

    /**
     * GET /api/v1/time-window-types/catalog — the types DECLARED in code, with
     * adoption state resolved per tenant.
     */
    public function catalog(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $adopted = [];
            foreach ($this->types->listForTenant($tenantId) as $row) {
                $adopted[(string) $row['key']] = (int) $row['id'];
            }

            $data = [];
            foreach ($this->registry->all() as $key => $definition) {
                $data[] = $definition->toArray() + [
                    'adopted' => array_key_exists($key, $adopted),
                    'adopted_id' => $adopted[$key] ?? null,
                ];
            }

            return Response::json(['data' => $data]);
        } catch (\Exception $e) {
            error_log('[TimeWindowTypesApiHandler] catalog failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the time window type catalogue', 500);
        }
    }

    /**
     * POST /api/v1/time-window-types — adopt a declared key, or author one.
     */
    public function create(Request $request): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $body = JsonBody::parsed($request);

            $key = trim((string) ($body['key'] ?? ''));
            if ($key === '') {
                return Response::error('key is required', 422);
            }
            if (!WindowTypeRegistry::isValidKey($key)) {
                return Response::error(
                    'key must be a lowercase slug (letters, digits, underscores), optionally '
                    . 'namespaced as plugin:slug',
                    422
                );
            }

            $declared = $this->registry->get($key);

            // A key the registry does not know must be one the TENANT may
            // author: bare, and not the reserved sentinel. A prefixed key is an
            // ATTRIBUTION — writing `acme:growing_season` by hand claims the Acme
            // plugin said so — so an unregistered prefixed key is refused rather
            // than silently created as a look-alike of the real thing.
            if ($declared === null && !WindowTypeRegistry::isTenantAuthorable($key)) {
                if (str_contains($key, WindowTypeRegistry::NAMESPACE_SEPARATOR)) {
                    return Response::error(
                        'No installed plugin declares this namespaced key; a tenant may only author '
                        . 'un-namespaced keys',
                        422
                    );
                }

                return Response::error(
                    "'" . WindowTypeRegistry::UNTYPED . "' is reserved — it is how ?type= asks for "
                    . 'periods of no particular kind',
                    422
                );
            }

            $label = array_key_exists('label', $body) ? trim((string) $body['label']) : '';
            if ($label === '') {
                $label = $declared?->label() ?? $key;
            }
            if ($tooLong = InputLimits::firstViolation(['label' => [$label, InputLimits::NAME_MAX]])) {
                return $tooLong;
            }

            $parent = self::parentTypeId($body);
            if ($parent === false) {
                return Response::error('parent_type_id must be an integer or null', 422);
            }
            if ($parent === null && $declared?->parentKey() !== null) {
                // Adoption inherits the declared nesting when the tenant has
                // already adopted the parent. When it has not, the type is
                // adopted un-nested rather than refused: a declaration is a
                // default, not a precondition, and an administrator adopting a
                // sub-period first can attach it afterwards.
                $declaredParent = $this->types->findByKey($tenantId, (string) $declared->parentKey());
                $parent = $declaredParent === null ? null : (int) $declaredParent['id'];
            }
            if (is_int($parent) && $this->types->find($tenantId, $parent) === null) {
                return Response::error('parent_type_id does not name a type in this tenant', 422);
            }

            $id = $this->types->create(
                $tenantId,
                $key,
                $label,
                is_int($parent) ? $parent : null,
                $declared?->source() ?? WindowTypeRegistry::TENANT_SOURCE
            );
            if ($id === null) {
                return Response::error('A time window type with this key already exists', 409);
            }

            return Response::json(['data' => $this->types->find($tenantId, $id)], 201);
        } catch (\Exception $e) {
            error_log('[TimeWindowTypesApiHandler] create failed: ' . $e->getMessage());

            return Response::error('Failed to create time window type', 500);
        }
    }

    /**
     * PATCH /api/v1/time-window-types/{id} — relabel, or re-nest.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            if ($this->types->find($tenantId, $id) === null) {
                return Response::error('Time window type not found', 404);
            }

            $body = JsonBody::parsed($request);
            $fields = [];

            if (array_key_exists('label', $body)) {
                $label = trim((string) $body['label']);
                if ($label === '') {
                    return Response::error('label must not be empty', 422);
                }
                if ($tooLong = InputLimits::firstViolation(['label' => [$label, InputLimits::NAME_MAX]])) {
                    return $tooLong;
                }
                $fields['label'] = $label;
            }

            if (array_key_exists('parent_type_id', $body)) {
                $parent = self::parentTypeId($body);
                if ($parent === false) {
                    return Response::error('parent_type_id must be an integer or null', 422);
                }
                if (is_int($parent)) {
                    if ($this->types->find($tenantId, $parent) === null) {
                        return Response::error('parent_type_id does not name a type in this tenant', 422);
                    }
                    if ($this->types->wouldCycle($tenantId, $id, $parent)) {
                        return Response::error(
                            'That would make a type nest inside itself, directly or through its ancestors',
                            422
                        );
                    }
                }
                $fields['parent_type_id'] = is_int($parent) ? $parent : null;
            }

            if ($fields === []) {
                return Response::error('Nothing to update', 422);
            }

            $this->types->update($tenantId, $id, $fields);

            return Response::json(['data' => $this->types->find($tenantId, $id)]);
        } catch (\Exception $e) {
            error_log('[TimeWindowTypesApiHandler] update failed: ' . $e->getMessage());

            return Response::error('Failed to update time window type', 500);
        }
    }

    /**
     * DELETE /api/v1/time-window-types/{id} — only when nothing depends on it.
     *
     * Refused, never forced. The migration's foreign key would cascade a type's
     * periods away with it, and a period is a thing an institution has closed the
     * books on — not something a vocabulary edit gets to destroy. The refusal
     * reports the blast radius so the caller knows what to do instead.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }
            $id = (int) ($params['id'] ?? 0);
            if ($this->types->find($tenantId, $id) === null) {
                return Response::error('Time window type not found', 404);
            }

            $windows = $this->types->countWindows($tenantId, $id);
            if ($windows > 0) {
                return Response::error(
                    "Cannot delete: {$windows} period(s) are of this kind. Periods are never removed "
                    . 'by a vocabulary change.',
                    409
                );
            }
            $children = $this->types->countChildTypes($tenantId, $id);
            if ($children > 0) {
                return Response::error(
                    "Cannot delete: {$children} type(s) nest inside this one. Detach or remove those first.",
                    409
                );
            }

            $this->types->delete($tenantId, $id);

            return Response::json(['data' => ['deleted' => true]]);
        } catch (\Exception $e) {
            error_log('[TimeWindowTypesApiHandler] delete failed: ' . $e->getMessage());

            return Response::error('Failed to delete time window type', 500);
        }
    }

    private static function tenantId(): int|Response
    {
        $tenantId = TenantContext::getTenantId();

        return $tenantId ?? Response::error('Tenant context is required', 403);
    }

    /**
     * Parse an optional `parent_type_id`.
     *
     * Three outcomes, all needed: `null` means "absent, or explicitly detached",
     * an int means "attach to this", and `false` means "present but not an
     * integer" — a 422 rather than a silent cast, or `parent_type_id: "top"`
     * would quietly detach a type from its parent.
     *
     * @param array<string, mixed> $body
     * @return int|null|false
     */
    private static function parentTypeId(array $body): int|null|false
    {
        if (!array_key_exists('parent_type_id', $body)) {
            return null;
        }
        $raw = $body['parent_type_id'];
        if ($raw === null) {
            return null;
        }
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return false;
    }
}
