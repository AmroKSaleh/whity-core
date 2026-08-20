<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Ou\OuTypeRegistry;
use Whity\Core\Ou\OuTypeRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\InputLimits;
use Whity\Http\JsonBody;

/**
 * Tenant-scoped CRUD for the tenant's own ORGANIZATIONAL-UNIT TYPE vocabulary
 * (#822) — the campus/faculty/department (or region/branch/team) levels its tree
 * is made of.
 *
 * PERMISSIONS: reads are gated on `ous:read`, writes on `ous:write` — the
 * existing OU grants, reused deliberately rather than minting an `ou_types:*`
 * pair. A NEW permission arrives with a grant migration that can only reach the
 * seeded `admin` role, so every operator running a custom administrative role
 * silently loses the capability on upgrade and discovers it as a 403; #834 is
 * that failure already having happened once here. The vocabulary is also not a
 * separate authority in practice — anyone who can create, rename and reparent
 * units already shapes the tree these types describe.
 *
 * The DELETE route is gated on `ous:write` rather than `ous:delete` because no
 * organizational unit is ever destroyed by it: its worst case, under an explicit
 * `?force=true`, is an UPDATE that sets `ou_type_id = NULL` on some units.
 * Gating an update-effect route on the delete grant would misdescribe its blast
 * radius in the direction that makes an operator less careful about the grant
 * that actually matters.
 *
 * Error bodies are generic and a foreign-tenant id is indistinguishable from
 * "not found" (404), never a cross-tenant leak.
 */
final class OuTypesApiHandler
{
    public function __construct(
        private readonly OuTypeRepository $types,
        private readonly OuTypeRegistry $registry,
    ) {
    }

    /**
     * GET /api/ou-types — the tenant's vocabulary, in rank order.
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
            error_log('[OuTypesApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to fetch organizational unit types', 500);
        }
    }

    /**
     * GET /api/ou-types/catalog — the types DECLARED in code, with adoption state.
     *
     * Without this a plugin's contribution is undiscoverable: an administrator
     * would have to read the plugin's source to learn that `acme:clinic` exists
     * before they could adopt it, and a mistyped key would create a
     * tenant-authored type that merely looks like the plugin's.
     *
     * `adopted` is resolved per tenant, so the same catalogue reads differently
     * for a tenant that has taken a type and one that has not.
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
            foreach ($this->registry->all() as $definition) {
                $data[] = $definition->toArray() + [
                    'adopted' => array_key_exists($definition->key(), $adopted),
                    'ou_type_id' => $adopted[$definition->key()] ?? null,
                ];
            }

            return Response::json(['data' => $data]);
        } catch (\Exception $e) {
            error_log('[OuTypesApiHandler] catalog failed: ' . $e->getMessage());

            return Response::error('Failed to fetch the organizational unit type catalog', 500);
        }
    }

    /**
     * GET /api/ou-types/{id}
     *
     * @param array<string, string> $params
     */
    public function get(Request $request, array $params): Response
    {
        try {
            $tenantId = self::tenantId();
            if ($tenantId instanceof Response) {
                return $tenantId;
            }

            $type = $this->types->find($tenantId, (int) ($params['id'] ?? 0));
            if ($type === null) {
                return Response::error('Organizational unit type not found', 404);
            }

            return Response::json(['data' => $type]);
        } catch (\Exception $e) {
            error_log('[OuTypesApiHandler] get failed: ' . $e->getMessage());

            return Response::error('Failed to fetch organizational unit type', 500);
        }
    }

    /**
     * POST /api/ou-types — author a new type, or ADOPT a declared one.
     *
     * The two are one operation on purpose. Adoption is just a create whose key
     * the registry recognises, so the label and rank fall back to what the
     * declaring plugin suggested instead of to the bare slug. The resolution
     * chain, per platform convention, is:
     *
     *     request body  ??  registry declaration  ??  a sensible default
     *
     * — so nothing here is a hardcoded tunable, and a tenant that wants "School"
     * where the plugin said "Faculty" simply says so.
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
            if (!OuTypeRegistry::isValidKey($key)) {
                return Response::error(
                    'key must be a lowercase slug (letters, digits, underscores), optionally '
                    . 'namespaced as plugin:slug',
                    422
                );
            }

            $declared = $this->registry->get($key);

            // A key the registry does not know must be one the TENANT is allowed
            // to author: bare, and not the reserved untyped sentinel. A prefixed
            // key is an ATTRIBUTION — writing `acme:clinic` by hand claims the
            // Acme plugin said so — so an unregistered prefixed key is refused
            // rather than silently created as a look-alike of the real thing.
            if ($declared === null && !OuTypeRegistry::isTenantAuthorable($key)) {
                if (str_contains($key, OuTypeRegistry::NAMESPACE_SEPARATOR)) {
                    return Response::error(
                        'No installed plugin declares this namespaced key; a tenant may only author '
                        . 'un-namespaced keys',
                        422
                    );
                }

                return Response::error(
                    "'" . OuTypeRegistry::UNTYPED . "' is reserved — it is how ?type= asks for units "
                    . 'with no type at all',
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

            $sortOrder = self::sortOrder($body);
            if ($sortOrder === false) {
                return Response::error('sort_order must be an integer', 422);
            }
            $sortOrder ??= $declared?->sortOrder();

            $id = $this->types->create(
                $tenantId,
                $key,
                $label,
                $sortOrder,
                $declared?->source() ?? OuTypeRegistry::TENANT_SOURCE
            );
            if ($id === null) {
                return Response::error('An organizational unit type with this key already exists', 409);
            }

            return Response::json(['data' => $this->types->find($tenantId, $id)], 201);
        } catch (\Exception $e) {
            error_log('[OuTypesApiHandler] create failed: ' . $e->getMessage());

            return Response::error('Failed to create organizational unit type', 500);
        }
    }

    /**
     * PATCH /api/ou-types/{id} — relabel or re-rank.
     *
     * The KEY is not updatable; see {@see OuTypeRepository::update()}. Editing it
     * in place would silently repoint every routing rule bound to the old key at
     * a type that no longer exists, which is the drift this feature was reported
     * to remove.
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
            $body = JsonBody::parsed($request);

            if (array_key_exists('key', $body)) {
                return Response::error(
                    'key cannot be changed — a routing rule binds to it. Create the new type and '
                    . 'retype the units that should move.',
                    422
                );
            }

            $fields = [];
            if (array_key_exists('label', $body)) {
                $label = trim((string) $body['label']);
                if ($label === '') {
                    return Response::error('label must be a non-empty string', 422);
                }
                if ($tooLong = InputLimits::firstViolation(['label' => [$label, InputLimits::NAME_MAX]])) {
                    return $tooLong;
                }
                $fields['label'] = $label;
            }

            $sortOrder = self::sortOrder($body);
            if ($sortOrder === false) {
                return Response::error('sort_order must be an integer', 422);
            }
            if ($sortOrder !== null) {
                $fields['sort_order'] = $sortOrder;
            }

            if ($fields === []) {
                return Response::error('No updatable fields supplied (label, sort_order)', 422);
            }

            if (!$this->types->update($tenantId, $id, $fields)) {
                return Response::error('Organizational unit type not found', 404);
            }

            return Response::json(['data' => $this->types->find($tenantId, $id)]);
        } catch (\Exception $e) {
            error_log('[OuTypesApiHandler] update failed: ' . $e->getMessage());

            return Response::error('Failed to update organizational unit type', 500);
        }
    }

    /**
     * DELETE /api/ou-types/{id}
     *
     * REFUSE-WHILE-IN-USE GUARD. Deleting a type in use untypes every unit that
     * carried it, and an untyped unit is invisible to every `?type=` rule that
     * used to match it — a silent, unbounded behaviour change with nothing in
     * the response to indicate it happened. So this refuses with 409 while any
     * unit still carries the type, reports the exact count, and proceeds only on
     * an explicit `?force=true`. Same shape as the child/member guards on
     * {@see OusApiHandler::delete()} and the association guard on
     * {@see TagGroupsApiHandler::delete()}.
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

            // Resolve first: a foreign-tenant id must stay indistinguishable from
            // "does not exist".
            $type = $this->types->find($tenantId, $id);
            if ($type === null) {
                return Response::error('Organizational unit type not found', 404);
            }

            $inUse = $this->types->countUnits($tenantId, $id);
            if ($inUse > 0 && !self::isForced($request)) {
                return Response::error(
                    'Cannot delete organizational unit type: ' . $inUse
                    . ' organizational unit(s) still carry it. Retype them first, or repeat the '
                    . 'request with ?force=true to untype them.',
                    409,
                    ['units' => $inUse]
                );
            }

            if (!$this->types->delete($tenantId, $id)) {
                return Response::error('Organizational unit type not found', 404);
            }

            return Response::json([], 204);
        } catch (\Exception $e) {
            error_log('[OuTypesApiHandler] delete failed: ' . $e->getMessage());

            return Response::error('Failed to delete organizational unit type', 500);
        }
    }

    /**
     * The acting tenant, or the 403 an unresolved one earns.
     *
     * Resolved once per request rather than cast: `(int) null` is 0, which is
     * the SYSTEM tenant, so a silent cast would point a caller with no tenant
     * context at the platform tenant's vocabulary. Mirrors the guard in
     * {@see TagGroupsApiHandler}.
     */
    private static function tenantId(): int|Response
    {
        $tenantId = TenantContext::getTenantId();

        return $tenantId ?? Response::error('Tenant context is required', 403);
    }

    /**
     * Parse an optional integer `sort_order` from a request body.
     *
     * Three outcomes, all needed: `null` means "absent, leave it alone", an int
     * means "set it to this", and `false` means "present but not an integer" —
     * which must be a 422 rather than a silent cast, or `sort_order: "first"`
     * would quietly rank a type at 0 and reorder the tenant's whole tree.
     *
     * @param array<string, mixed> $body
     * @return int|null|false
     */
    private static function sortOrder(array $body): int|null|false
    {
        if (!array_key_exists('sort_order', $body)) {
            return null;
        }

        $raw = $body['sort_order'];
        if (is_int($raw)) {
            return $raw;
        }
        if (is_string($raw) && preg_match('/^-?\d+$/', $raw) === 1) {
            return (int) $raw;
        }

        return false;
    }

    /**
     * Whether the caller explicitly opted into the destructive path.
     *
     * Read from the QUERY STRING rather than a request body, matching
     * {@see TagGroupsApiHandler::isForced()}: DELETE bodies are inconsistently
     * supported across HTTP clients and proxies, and a body that silently fails
     * to parse must never be mistaken for consent. Only the exact tokens `true`
     * and `1` count — the empty `?force=` a UI emits for an unchecked box leaves
     * the guard armed.
     */
    private static function isForced(Request $request): bool
    {
        $force = self::queryParams($request)['force'] ?? '';

        return $force === 'true' || $force === '1';
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), path last — the same precedence {@see OusApiHandler} uses, so a
     * test that puts params in the path and production traffic resolve
     * identically.
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
}
