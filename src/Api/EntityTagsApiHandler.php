<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Taxonomy\EntityTagRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Tenant-scoped, RBAC-gated management of the polymorphic tag<->entity
 * association (WC-621).
 *
 *  - POST   /api/entity-tags  {entity_type, entity_id, tag_id}  — attach (idempotent)
 *  - DELETE /api/entity-tags  {entity_type, entity_id, tag_id}  — detach
 *  - GET    /api/entity-tags?entity_type=T&entity_id=E          — the entity's tags
 *  - GET    /api/entity-tags?entity_type=T&tag_id=X             — entities of type T carrying tag X
 *
 * `entity_type` is an opaque plugin-supplied string (no FK), so any resource is
 * taggable. Reads require `tags:read`, writes require `tags:manage`. Attaching
 * verifies the tag belongs to the caller's tenant (a foreign/absent tag is 422,
 * never a leak). Error bodies are generic.
 */
final class EntityTagsApiHandler
{
    private const MAX_ENTITY_TYPE_LENGTH = 128;

    private EntityTagRepository $repo;
    private TagRepository $tags;
    private RoleChecker $roleChecker;

    public function __construct(EntityTagRepository $repo, TagRepository $tags, RoleChecker $roleChecker)
    {
        $this->repo = $repo;
        $this->tags = $tags;
        $this->roleChecker = $roleChecker;
    }

    public function attach(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $assoc = self::validateAssociation(JsonBody::parsed($request));
        if ($assoc instanceof Response) {
            return $assoc;
        }
        [$entityType, $entityId, $tagId] = $assoc;

        // The tag must belong to the caller's tenant. A foreign/absent tag is a
        // validation failure (422), never a confirmation of its existence.
        if ($this->tags->find($tenantId, $tagId) === null) {
            return Response::error('tag not found', 422, ['tag_id' => $tagId]);
        }

        $created = $this->repo->attach($tenantId, $entityType, $entityId, $tagId);

        return Response::json([
            'data' => ['entity_type' => $entityType, 'entity_id' => $entityId, 'tag_id' => $tagId],
        ], $created ? 201 : 200);
    }

    public function detach(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $assoc = self::validateAssociation(JsonBody::parsed($request));
        if ($assoc instanceof Response) {
            return $assoc;
        }
        [$entityType, $entityId, $tagId] = $assoc;

        if (!$this->repo->detach($tenantId, $entityType, $entityId, $tagId)) {
            return Response::error('Association not found', 404);
        }

        return Response::json([], 204);
    }

    public function list(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_READ);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $query = self::queryParams($request);

        $entityType = trim((string) ($query['entity_type'] ?? ''));
        if ($entityType === '' || mb_strlen($entityType) > self::MAX_ENTITY_TYPE_LENGTH) {
            return Response::error('entity_type is required', 422);
        }

        $hasEntityId = isset($query['entity_id']) && $query['entity_id'] !== '';
        $hasTagId = isset($query['tag_id']) && $query['tag_id'] !== '';

        if ($hasEntityId === $hasTagId) {
            return Response::error('provide exactly one of entity_id (the entity\'s tags) or tag_id (entities carrying the tag)', 422);
        }

        if ($hasEntityId) {
            if (!ctype_digit((string) $query['entity_id'])) {
                return Response::error('entity_id must be a positive integer', 422);
            }
            $rows = $this->repo->tagsForEntity($tenantId, $entityType, (int) $query['entity_id']);
        } else {
            if (!ctype_digit((string) $query['tag_id'])) {
                return Response::error('tag_id must be a positive integer', 422);
            }
            $rows = $this->repo->entitiesForTag($tenantId, $entityType, (int) $query['tag_id']);
        }

        return Response::json(['data' => $rows]);
    }

    /**
     * Validate and coerce the {entity_type, entity_id, tag_id} body of an
     * attach/detach. Returns [entityType, entityId, tagId] or an error Response.
     *
     * @param array<string, mixed> $body
     * @return array{0: string, 1: int, 2: int}|Response
     */
    private static function validateAssociation(array $body): array|Response
    {
        $entityType = trim((string) ($body['entity_type'] ?? ''));
        if ($entityType === '' || mb_strlen($entityType) > self::MAX_ENTITY_TYPE_LENGTH) {
            return Response::error('entity_type is required and must be at most 128 characters', 422);
        }

        $entityId = self::intOrZero($body['entity_id'] ?? null);
        if ($entityId <= 0) {
            return Response::error('entity_id is required and must be a positive integer', 422);
        }

        $tagId = self::intOrZero($body['tag_id'] ?? null);
        if ($tagId <= 0) {
            return Response::error('tag_id is required and must be a positive integer', 422);
        }

        return [$entityType, $entityId, $tagId];
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
