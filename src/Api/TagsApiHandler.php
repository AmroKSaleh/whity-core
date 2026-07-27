<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Tenant-scoped, RBAC-gated CRUD for tags (WC-621) — the individual tags that
 * live inside a tag group.
 *
 * Reads require `tags:read`, writes require `tags:manage`. `GET /api/tags`
 * accepts an optional `group_id` filter. Creating a tag verifies the target
 * group belongs to the caller's tenant (a foreign group is reported as 422, not
 * a leak). Error bodies are generic; a foreign-tenant id is 404, never a
 * cross-tenant leak.
 */
final class TagsApiHandler
{
    private const MAX_NAME_LENGTH = 128;

    private TagRepository $tags;
    private TagGroupRepository $groups;
    private RoleChecker $roleChecker;

    public function __construct(TagRepository $tags, TagGroupRepository $groups, RoleChecker $roleChecker)
    {
        $this->tags = $tags;
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

        $query = self::queryParams($request);
        $groupId = null;
        if (isset($query['group_id']) && $query['group_id'] !== '') {
            if (!ctype_digit($query['group_id'])) {
                return Response::error('group_id must be a positive integer', 422);
            }
            $groupId = (int) $query['group_id'];
        }

        return Response::json(['data' => $this->tags->listForTenant($tenantId, $groupId)]);
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

        $tag = $this->tags->find($tenantId, (int) ($params['id'] ?? 0));
        if ($tag === null) {
            return Response::error('Tag not found', 404);
        }

        return Response::json(['data' => $tag]);
    }

    public function create(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $body = JsonBody::parsed($request);

        $groupId = self::intOrZero($body['group_id'] ?? null);
        if ($groupId <= 0) {
            return Response::error('group_id is required and must be a positive integer', 422);
        }

        $name = trim((string) ($body['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return Response::error('name is required and must be at most 128 characters', 422);
        }

        // The group must belong to the caller's tenant. A foreign/absent group is
        // reported as 422 (a validation failure), never confirming its existence.
        if ($this->groups->find($tenantId, $groupId) === null) {
            return Response::error('tag group not found', 422, ['group_id' => $groupId]);
        }

        $id = $this->tags->create($tenantId, $groupId, $name);
        if ($id === null) {
            return Response::error('A tag with this name already exists in this group', 409);
        }

        return Response::json(['data' => $this->tags->find($tenantId, $id)], 201);
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

        if (!array_key_exists('name', $body)) {
            return Response::error('No updatable fields supplied (name)', 422);
        }
        $name = trim((string) $body['name']);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return Response::error('name is required and must be at most 128 characters', 422);
        }

        $result = $this->tags->rename($tenantId, $id, $name);
        if ($result === null) {
            return Response::error('A tag with this name already exists in this group', 409);
        }
        if ($result === false) {
            return Response::error('Tag not found', 404);
        }

        return Response::json(['data' => $this->tags->find($tenantId, $id)]);
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

        if (!$this->tags->delete($tenantId, (int) ($params['id'] ?? 0))) {
            return Response::error('Tag not found', 404);
        }

        return Response::json([], 204);
    }

    private static function intOrZero(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return 0;
    }

    /**
     * Query params from $_GET (production) merged with the path query string
     * (tests), as string values.
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

    /**
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
