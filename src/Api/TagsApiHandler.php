<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\ListQuery;

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
    private ?AuditLoggerInterface $auditLogger;

    public function __construct(
        TagRepository $tags,
        TagGroupRepository $groups,
        RoleChecker $roleChecker,
        ?AuditLoggerInterface $auditLogger = null
    ) {
        $this->tags = $tags;
        $this->groups = $groups;
        $this->roleChecker = $roleChecker;
        $this->auditLogger = $auditLogger;
    }

    /**
     * GET /api/tags — the tenant's tags, sortable and searchable, optionally
     * narrowed to one group.
     *
     * PAGINATION IS OPT-IN HERE, AND THAT IS A DECISION (#1102). This endpoint
     * returned EVERY tag before it adopted {@see ListQuery}, and the admin table
     * is not its only reader: `web/components/taxonomy/tag-picker.tsx` fetches
     * it whole to populate a picker, and the tag-group record screen fetches
     * `?group_id=N` to show a group's tags. Paginating it unconditionally would
     * cut those to twenty-five with nothing raising an error — the picker would
     * simply stop offering most of the tenant's tags, which reads as missing
     * data rather than as a truncated response. The desktop and mobile clients
     * that also read it are not updated in this repository, so they could not be
     * fixed in the same change.
     *
     * So: a caller that sends `page` or `per_page` gets one page and a
     * `pagination` envelope; a caller that sends neither gets what it gets
     * today — every row, and no `pagination` key. The choice is
     * {@see ListQuery::fromPath()}'s default rather than something this handler
     * computes.
     *
     * `sort`, `dir` and `q` apply either way, which is the actual fix: the admin
     * table can stop sorting and filtering client-side over a fetched slice
     * without any client being forced to learn paging first. The `group_id`
     * filter is unchanged and composes with all of it — including the count, so
     * a filtered page never reports an unfiltered total.
     */
    public function list(Request $request): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_READ);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId] = $auth;

        $params = self::queryParams($request);
        $groupId = null;
        if (isset($params['group_id']) && $params['group_id'] !== '') {
            if (!ctype_digit($params['group_id'])) {
                return Response::error('group_id must be a positive integer', 422);
            }
            $groupId = (int) $params['group_id'];
        }

        $query = ListQuery::fromPath($request->getPath(), TagRepository::listSpec());
        $rows = $this->tags->listForTenant($tenantId, $groupId, $query);

        if (!$query->paginated) {
            return Response::json(['data' => $rows]);
        }

        return Response::json([
            'data' => $rows,
            'pagination' => $query->meta($this->tags->countForTenant($tenantId, $groupId, $query)),
        ]);
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
     * Delete a tag.
     *
     * DESTRUCTIVE-DELETE GUARD (WC-714 §5) — the same guard as
     * {@see TagGroupsApiHandler::delete()}, one level shallower: every
     * `entity_tags` row referencing this tag is cascaded away, and those rows
     * belong to other plugins' records. Refuse with 409 while associations
     * exist, reporting the count; proceed only on an explicit `?force=true`,
     * and record the forced destruction in the audit log.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $auth = $this->authorize($request, CorePermissions::TAGS_MANAGE);
        if ($auth instanceof Response) {
            return $auth;
        }
        [$tenantId, $callerId] = $auth;

        $id = (int) ($params['id'] ?? 0);

        $tag = $this->tags->find($tenantId, $id);
        if ($tag === null) {
            return Response::error('Tag not found', 404);
        }

        $associations = $this->tags->countAssociations($tenantId, $id);
        $forced = self::isForced($request);

        if ($associations > 0 && !$forced) {
            return Response::error(
                'Cannot delete a tag while ' . $associations . ' entity association(s) still reference it. '
                . 'Retry with ?force=true to delete them as well.',
                409,
                ['associations' => $associations]
            );
        }

        if (!$this->tags->delete($tenantId, $id)) {
            return Response::error('Tag not found', 404);
        }

        $this->auditLogger?->record('taxonomy.tag.deleted', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $callerId,
            'target_type'   => 'tag',
            'target_id'     => $id,
            'metadata'      => [
                'name'                 => $tag['name'],
                'group_id'             => $tag['group_id'],
                'forced'               => $forced,
                'associations_deleted' => $associations,
            ],
        ]);

        return Response::json([], 204);
    }

    /**
     * Was the destructive delete explicitly forced (`?force=true`)? Read from
     * the query string, never a DELETE body — see
     * {@see TagGroupsApiHandler::isForced()} for the reasoning.
     */
    private static function isForced(Request $request): bool
    {
        $force = self::queryParams($request)['force'] ?? '';

        return $force === 'true' || $force === '1';
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
