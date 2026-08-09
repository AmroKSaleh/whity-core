<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
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
    private ?AuditLoggerInterface $auditLogger;

    public function __construct(
        TagGroupRepository $groups,
        RoleChecker $roleChecker,
        ?AuditLoggerInterface $auditLogger = null
    ) {
        $this->groups = $groups;
        $this->roleChecker = $roleChecker;
        $this->auditLogger = $auditLogger;
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
     * Delete a tag group.
     *
     * DESTRUCTIVE-DELETE GUARD (WC-714 §5). The FK cascade runs two levels deep
     * — dropping a group drops all its tags, which drops every `entity_tags`
     * row referencing them. Those associations belong to OTHER plugins'
     * records, so an unguarded delete let any holder of `tags:manage` silently
     * destroy an unbounded amount of other subsystems' data with no warning and
     * no record of what was lost.
     *
     * So: refuse with 409 while associations exist, reporting the exact count,
     * and proceed only when the caller explicitly passes `?force=true`. A
     * forced delete is recorded in the audit log with the full blast radius.
     * This mirrors the refuse-while-dependents-exist guard already used by
     * {@see DocumentBlocksApiHandler::delete()} and {@see OusApiHandler::delete()}.
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

        // Resolve first: a foreign-tenant id must stay indistinguishable from
        // "does not exist" (404), and the guard below needs the group's key for
        // the audit record anyway.
        $group = $this->groups->find($tenantId, $id);
        if ($group === null) {
            return Response::error('Tag group not found', 404);
        }

        $associations = $this->groups->countAssociations($tenantId, $id);
        $tagCount = $this->groups->countTags($tenantId, $id);
        $forced = self::isForced($request);

        if ($associations > 0 && !$forced) {
            return Response::error(
                'Cannot delete a tag group while ' . $associations . ' entity association(s) still reference its tags. '
                . 'Retry with ?force=true to delete them as well.',
                409,
                ['tags' => $tagCount, 'associations' => $associations]
            );
        }

        if (!$this->groups->delete($tenantId, $id)) {
            return Response::error('Tag group not found', 404);
        }

        // Record what was destroyed. A cascading delete is otherwise invisible
        // to the plugins whose associations it removed.
        $this->auditLogger?->record('taxonomy.tag_group.deleted', [
            'tenant_id'     => $tenantId,
            'actor_user_id' => $callerId,
            'target_type'   => 'tag_group',
            'target_id'     => $id,
            'metadata'      => [
                'group_key'             => $group['key'],
                'forced'                => $forced,
                'tags_deleted'          => $tagCount,
                'associations_deleted'  => $associations,
            ],
        ]);

        return Response::json([], 204);
    }

    /**
     * Was the destructive delete explicitly forced (`?force=true`)?
     *
     * Read from the QUERY STRING rather than a request body: DELETE bodies are
     * inconsistently supported across HTTP clients and proxies, and a body that
     * silently fails to parse must never be mistaken for consent to destroy
     * data. Only the exact tokens `true` and `1` count as consent.
     */
    private static function isForced(Request $request): bool
    {
        $force = self::queryParams($request)['force'] ?? '';

        return $force === 'true' || $force === '1';
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
