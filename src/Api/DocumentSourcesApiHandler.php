<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The block editor's documents (editor M2; docs/design/IMPLEMENTATION-NOTES.md
 * §3.2): templates, letters, reports, notes and wiki pages.
 *
 *   GET    /api/document-sources             → list()      (documents:read)
 *   POST   /api/document-sources             → create()    (documents:write)
 *   GET    /api/document-sources/{id}        → show()      (documents:read)
 *   PUT    /api/document-sources/{id}/draft  → saveDraft() (documents:write)
 *   DELETE /api/document-sources/{id}        → delete()    (documents:write)
 *
 * Visibility is the designer's: rows are filtered by {@see DocumentAccessPolicy}
 * (scope, required_permission, creator, OU reach), a hidden row 404s, and
 * sharing a document beyond its creator needs documents:publish.
 *
 * SAVING IS OPTIMISTIC. show() returns the draft version as an ETag; a save
 * must send it back as If-Match and is refused with 409 (carrying the current
 * version) when someone saved in between, never silently overwriting their
 * work. A save without If-Match is refused with 428 rather than treated as
 * "overwrite whatever is there". The editor autosaves, so this is the normal
 * path, not an edge case.
 *
 * `blocks` is the WhityDocument contract's Block[]
 * (docs/design/handoff/editor/whity-document.ts). The server checks its shape
 * (a list of objects, each with a unique string id and a string type) and
 * stores it as JSON; later milestones add per-block validation (plugin data
 * against its schema, M7). Comments and suggestions are not part of it.
 * Error bodies are generic (WC-186).
 */
final class DocumentSourcesApiHandler
{
    public const KINDS = ['template', 'letter', 'report', 'note', 'wiki', 'label'];
    private const LANGS = ['en', 'ar'];
    private const VIEWS = ['page', 'continuous'];
    private const MAX_BLOCKS = 5000;
    private const MAX_TITLE = 255;

    private DocumentSourceRepository $repo;
    private DocumentAccessPolicy $policy;
    private RoleChecker $roleChecker;
    private OuReachResolver $ouReach;

    public function __construct(
        DocumentSourceRepository $repo,
        DocumentAccessPolicy $policy,
        RoleChecker $roleChecker,
        OuReachResolver $ouReach,
    ) {
        $this->repo = $repo;
        $this->policy = $policy;
        $this->roleChecker = $roleChecker;
        $this->ouReach = $ouReach;
    }

    public function list(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $kind = self::queryParam($request, 'kind');
        if ($kind !== null && !in_array($kind, self::KINDS, true)) {
            return Response::error('kind must be one of: ' . implode(', ', self::KINDS), 422);
        }

        $visible = $this->policy->filterVisible(
            $this->repo->listForTenant($tenantId, $kind),
            $callerId,
            $this->permissionResolver($callerId, $tenantId),
            $this->ouReach->reachFor($tenantId, $callerId),
        );

        return Response::json(['data' => array_values($visible)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $row = $this->visibleRow((int) ($params['id'] ?? 0), $tenantId, $callerId);
        if ($row === null) {
            return Response::error('Document not found', 404);
        }

        return Response::json(['data' => $row])->withHeaders(['ETag' => self::etag($row['draft_version'])]);
    }

    public function create(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $body = JsonBody::parsed($request);
        $kind = $body['kind'] ?? null;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            return Response::error('kind must be one of: ' . implode(', ', self::KINDS), 422);
        }
        $fields = $this->documentFields($body);
        if ($fields instanceof Response) {
            return $fields;
        }

        $scope = $this->normalizeScope($body['scope'] ?? null);
        if ($scope === null) {
            return Response::error('scope must be one of: ' . implode(', ', DocumentAccessPolicy::SCOPES), 422);
        }
        $requiredPermission = is_string($body['required_permission'] ?? null) && trim($body['required_permission']) !== ''
            ? trim($body['required_permission'])
            : null;
        $ownerOuId = null;
        if (array_key_exists('owner_ou_id', $body)) {
            $parsed = $this->parseOwnerOu($body['owner_ou_id'], $tenantId);
            if ($parsed instanceof Response) {
                return $parsed;
            }
            $ownerOuId = $parsed;
        }
        if ($this->policy->needsPublish($scope, $requiredPermission, $ownerOuId)
            && !$this->permissionResolver($callerId, $tenantId)(CorePermissions::DOCUMENTS_PUBLISH)) {
            return Response::error('Sharing a document requires documents:publish', 403);
        }

        // E.7: templates and anything Whity Render prints open in the page view;
        // notes and wiki pages open continuous.
        $defaultView = $fields['default_view'] ?? (in_array($kind, ['note', 'wiki'], true) ? 'continuous' : 'page');

        $id = $this->repo->create($tenantId, [
            'kind'                => $kind,
            'title'               => $fields['title'] ?? '',
            'lang'                => $fields['lang'] ?? 'en',
            'default_view'        => $defaultView,
            'full_width'          => $fields['full_width'] ?? false,
            'page'                => $fields['page'] ?? null,
            'record_type'         => is_string($body['record_type'] ?? null) ? $body['record_type'] : null,
            'blocks'              => $fields['blocks'] ?? [],
            'scope'               => $scope,
            'required_permission' => $requiredPermission,
            'created_by'          => $callerId,
            'owner_ou_id'         => $ownerOuId,
        ]);
        $row = $this->repo->findById($id, $tenantId);

        return Response::json(['data' => $row], 201)->withHeaders(['ETag' => self::etag(1)]);
    }

    /**
     * Save the draft (the editor's autosave). Needs If-Match with the version
     * the edit was made against.
     *
     * @param array<string, string> $params
     */
    public function saveDraft(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $id = (int) ($params['id'] ?? 0);
        $row = $this->visibleRow($id, $tenantId, $callerId);
        if ($row === null) {
            return Response::error('Document not found', 404);
        }

        $base = self::parseIfMatch($request->getHeader('If-Match'));
        if ($base === null) {
            return Response::error('If-Match with the draft version is required', 428);
        }

        $fields = $this->documentFields(JsonBody::parsed($request));
        if ($fields instanceof Response) {
            return $fields;
        }
        if ($fields === []) {
            return Response::error('No updatable fields supplied', 422);
        }

        $version = $this->repo->saveDraft($id, $tenantId, $base, $fields, $callerId);
        if ($version === null) {
            $current = $this->repo->findById($id, $tenantId);
            if ($current === null) {
                return Response::error('Document not found', 404);
            }

            return Response::error(
                'The document changed since this edit was made',
                409,
                ['draft_version' => $current['draft_version']],
            )->withHeaders(['ETag' => self::etag($current['draft_version'])]);
        }

        return Response::json(['data' => $this->repo->findById($id, $tenantId)])
            ->withHeaders(['ETag' => self::etag($version)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $id = (int) ($params['id'] ?? 0);
        if ($this->visibleRow($id, $tenantId, $callerId) === null) {
            return Response::error('Document not found', 404);
        }
        $this->repo->delete($id, $tenantId);

        return Response::json([], 204);
    }

    /**
     * The document fields a create or a save may set, validated. Only keys the
     * body carries are returned, so a save changes exactly what was sent.
     *
     * @param array<string, mixed> $body
     * @return array{title?: string, lang?: string, default_view?: string, full_width?: bool,
     *               page?: ?array<string, mixed>, blocks?: list<array<string, mixed>>}|Response
     */
    private function documentFields(array $body): array|Response
    {
        $fields = [];
        if (array_key_exists('title', $body)) {
            if (!is_string($body['title']) || mb_strlen($body['title']) > self::MAX_TITLE) {
                return Response::error('title must be a string of at most ' . self::MAX_TITLE . ' characters', 422);
            }
            $fields['title'] = trim($body['title']);
        }
        if (array_key_exists('lang', $body)) {
            if (!in_array($body['lang'], self::LANGS, true)) {
                return Response::error('lang must be one of: ' . implode(', ', self::LANGS), 422);
            }
            $fields['lang'] = (string) $body['lang'];
        }
        if (array_key_exists('default_view', $body)) {
            if (!in_array($body['default_view'], self::VIEWS, true)) {
                return Response::error('default_view must be one of: ' . implode(', ', self::VIEWS), 422);
            }
            $fields['default_view'] = (string) $body['default_view'];
        }
        if (array_key_exists('full_width', $body)) {
            if (!is_bool($body['full_width'])) {
                return Response::error('full_width must be a boolean', 422);
            }
            $fields['full_width'] = $body['full_width'];
        }
        if (array_key_exists('page', $body)) {
            if ($body['page'] !== null && !is_array($body['page'])) {
                return Response::error('page must be an object or null', 422);
            }
            /** @var array<string, mixed>|null $page */
            $page = $body['page'];
            $fields['page'] = $page;
        }
        if (array_key_exists('blocks', $body)) {
            $blocks = self::validBlocks($body['blocks']);
            if ($blocks === null) {
                return Response::error(
                    'blocks must be a list of at most ' . self::MAX_BLOCKS . ' blocks, each an object with a unique string id and a string type',
                    422,
                );
            }
            $fields['blocks'] = $blocks;
        }

        return $fields;
    }

    /**
     * The contract's top-level shape: a list of objects with a unique, non-empty
     * string `id` and a non-empty string `type`. Null when it isn't one.
     *
     * @return list<array<string, mixed>>|null
     */
    private static function validBlocks(mixed $blocks): ?array
    {
        if (!is_array($blocks) || !array_is_list($blocks) || count($blocks) > self::MAX_BLOCKS) {
            return null;
        }
        $ids = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                return null;
            }
            $id = $block['id'] ?? null;
            $type = $block['type'] ?? null;
            if (!is_string($id) || $id === '' || !is_string($type) || $type === '' || isset($ids[$id])) {
                return null;
            }
            $ids[$id] = true;
        }

        /** @var list<array<string, mixed>> $blocks */
        return $blocks;
    }

    /** A query-string parameter, read the way PersonsApiHandler reads one. */
    private static function queryParam(Request $request, string $name): ?string
    {
        $value = isset($_GET[$name]) && is_string($_GET[$name]) ? $_GET[$name] : null;
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $params = [];
            parse_str($query, $params);
            if (isset($params[$name]) && is_string($params[$name])) {
                $value = $params[$name];
            }
        }

        return $value;
    }

    private static function etag(int $version): string
    {
        return '"' . $version . '"';
    }

    /** `"7"` or `W/"7"` → 7; anything else → null. */
    private static function parseIfMatch(?string $header): ?int
    {
        if ($header === null || preg_match('/^\s*(?:W\/)?"(\d{1,9})"\s*$/', $header, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function visibleRow(int $id, int $tenantId, int $callerId): ?array
    {
        $row = $this->repo->findById($id, $tenantId);
        if ($row === null || !$this->policy->canView(
            $row,
            $callerId,
            $this->permissionResolver($callerId, $tenantId),
            $this->ouReach->reachFor($tenantId, $callerId),
        )) {
            return null;
        }

        return $row;
    }

    /**
     * Resolve (tenantId, callerProfileId) or an early error Response.
     *
     * @return array{0: int, 1: int}|Response
     */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }

    /**
     * @return callable(string, int|null=): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }

    private function parseOwnerOu(mixed $value, int $tenantId): int|null|Response
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_int($value) && !(is_string($value) && ctype_digit($value))) {
            return Response::error('owner_ou_id must be an organizational unit id, or null', 422);
        }
        $ouId = (int) $value;
        if ($ouId <= 0 || !$this->ouReach->existsInTenant($tenantId, $ouId)) {
            return Response::error('owner_ou_id is not an organizational unit of this tenant', 422);
        }

        return $ouId;
    }

    private function normalizeScope(mixed $scope): ?string
    {
        if ($scope === null) {
            return DocumentAccessPolicy::SCOPE_PERSONAL;
        }
        $scope = strtolower(trim((string) $scope));

        return in_array($scope, DocumentAccessPolicy::SCOPES, true) ? $scope : null;
    }
}
