<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Tenant-scoped, RBAC-gated CRUD for tag groups (WC-621) — the named buckets
 * that hold tags (e.g. "priority", "department").
 *
 * Reads require `tags:read`, writes require `tags:manage`. The route is gated by
 * {@see \Whity\Http\RbacMiddleware}; this handler ALSO re-checks the permission
 * against the authoritative store ({@see RoleChecker}) as defence in depth.
 * Error bodies are generic (never `$e->getMessage()`); a foreign-tenant id is
 * indistinguishable from "not found" (404), never a cross-tenant leak.
 */
final class TagGroupsApiHandler
{
    /** A tag group key must be a compact token (no whitespace). */
    private const KEY_PATTERN = '/^[A-Za-z0-9_.:-]{1,64}$/';

    private TagGroupRepository $groups;
    private RoleChecker $roleChecker;

    public function __construct(TagGroupRepository $groups, RoleChecker $roleChecker)
    {
        $this->groups = $groups;
        $this->roleChecker = $roleChecker;
    }

    public function list(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_READ);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        return Response::json(['data' => $this->groups->listForTenant($tenantId)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_READ);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $group = $this->groups->find($tenantId, (int) ($params['id'] ?? 0));
        if ($group === null) {
            return Response::error('Tag group not found', 404);
        }

        return Response::json(['data' => $group]);
    }

    public function create(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $body = JsonBody::parsed($request);

        $key = trim((string) ($body['key'] ?? ''));
        if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
            return Response::error('key is required and must be a token of up to 64 chars (letters, digits, _.:-)', 422);
        }

        $displayName = self::extractDisplayName($body);
        if ($displayName === null) {
            return Response::error('display_name must be an object of {ar?, en?} string values', 422);
        }

        $id = $this->groups->create($tenantId, $key, $displayName);
        if ($id === null) {
            return Response::error('A tag group with this key already exists', 409);
        }

        return Response::json(['data' => $this->groups->find($tenantId, $id)], 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $id = (int) ($params['id'] ?? 0);
        $body = JsonBody::parsed($request);

        $fields = [];
        if (array_key_exists('key', $body)) {
            $key = trim((string) $body['key']);
            if ($key === '' || preg_match(self::KEY_PATTERN, $key) !== 1) {
                return Response::error('key must be a token of up to 64 chars (letters, digits, _.:-)', 422);
            }
            $fields['group_key'] = $key;
        }
        if (array_key_exists('display_name', $body)) {
            $displayName = self::extractDisplayName($body);
            if ($displayName === null) {
                return Response::error('display_name must be an object of {ar?, en?} string values', 422);
            }
            $fields['display_name'] = $displayName;
        }
        if ($fields === []) {
            return Response::error('No updatable fields supplied (key, display_name)', 422);
        }

        $result = $this->groups->update($tenantId, $id, $fields);
        if ($result === null) {
            return Response::error('A tag group with this key already exists', 409);
        }
        if ($result === false) {
            return Response::error('Tag group not found', 404);
        }

        return Response::json(['data' => $this->groups->find($tenantId, $id)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        if (!$this->groups->delete($tenantId, (int) ($params['id'] ?? 0))) {
            return Response::error('Tag group not found', 404);
        }

        return Response::json([], 204);
    }

    /**
     * Extract the bilingual {ar?, en?} label from the body. Returns the
     * normalized map, or null when `display_name` is present but not an object
     * of string values. Absent → empty map (allowed).
     *
     * @param array<string, mixed> $body
     * @return array<string, string>|null
     */
    private static function extractDisplayName(array $body): ?array
    {
        if (!array_key_exists('display_name', $body)) {
            return [];
        }
        $raw = $body['display_name'];
        if (!is_array($raw)) {
            return null;
        }

        $out = [];
        foreach (['ar', 'en'] as $locale) {
            if (array_key_exists($locale, $raw)) {
                if (!is_string($raw[$locale])) {
                    return null;
                }
                $out[$locale] = $raw[$locale];
            }
        }

        return $out;
    }

    /**
     * Resolve (tenantId, callerProfileId) after re-asserting the permission, or
     * return an early error Response.
     *
     * @return array{0: int, 1: int}|Response
     */
    private function authorize(Request $request, string $permission): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, $permission, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }

        return [$tenantId, $userId];
    }
}
