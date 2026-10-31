<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentSourceFileStore;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Theme\DocumentThemeRef;
use Whity\Core\Document\Theme\DocumentThemeResolver;
use Whity\Core\Document\Theme\DocumentThemeTokens;
use Whity\Core\Form\FormRejectedException;
use Whity\Core\Form\FormUploadPolicy;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\UploadedFilePart;
use Whity\Http\UploadedFilePartException;
use Whity\Storage\StorageException;

/**
 * The block editor's documents (editor M2; docs/design/IMPLEMENTATION-NOTES.md
 * §3.2): templates, letters, reports, notes and wiki pages.
 *
 *   GET    /api/document-sources             → list()      (documents:read)
 *   POST   /api/document-sources             → create()    (documents:write)
 *   GET    /api/document-sources/{id}        → show()      (documents:read)
 *   PUT    /api/document-sources/{id}/draft  → saveDraft() (documents:write)
 *   DELETE /api/document-sources/{id}        → delete()    (documents:write)
 *   POST   /api/document-sources/{id}/files → uploadFile() (documents:write)
 *   GET    /api/document-sources/{id}/files/{fileId}/content → fileContent() (documents:read)
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
    private const MAX_OWN_FIELDS = 50;

    private DocumentSourceRepository $repo;
    private DocumentAccessPolicy $policy;
    private RoleChecker $roleChecker;
    private OuReachResolver $ouReach;

    public function __construct(
        DocumentSourceRepository $repo,
        DocumentAccessPolicy $policy,
        RoleChecker $roleChecker,
        OuReachResolver $ouReach,
        private readonly ?DocumentSourceFileStore $files = null,
        // "Edit before issuing" copies a template's PUBLISHED version (editor M4c).
        private readonly ?DocumentSourceVersionRepository $versions = null,
        // The theme a document shows with (editor M4d), served as resolvedTheme.
        private readonly ?DocumentThemeResolver $themes = null,
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

        return Response::json(['data' => $this->withResolvedTheme($tenantId, $row)])
            ->withHeaders(['ETag' => self::etag($row['draft_version'])]);
    }

    /**
     * The document with the theme it shows with now (editor M4d): its own
     * `{ ref, overrides }` resolved over the workspace defaults. The editor
     * reads its print colour and letterhead from this, and Whity Render prints
     * a draft preview with the same.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withResolvedTheme(int $tenantId, array $row): array
    {
        if ($this->themes !== null) {
            /** @var array<string, mixed>|null $theme */
            $theme = is_array($row['theme'] ?? null) ? $row['theme'] : null;
            $row['resolvedTheme'] = $this->themes->resolve($tenantId, $theme, (string) $row['kind']);
        }

        return $row;
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

        // A workspace plugin's record type is `<plugin>.<type>`, up to 129
        // characters; the column holds 160 (migration 178, WHIT-742).
        $recordType = $body['record_type'] ?? null;
        if ($recordType !== null && (!is_string($recordType) || $recordType === '' || strlen($recordType) > 160)) {
            return Response::error("'record_type' must be a record type of at most 160 characters", 422);
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
            'record_type'         => $recordType,
            'blocks'              => $fields['blocks'] ?? [],
            'own_fields'          => $fields['own_fields'] ?? null,
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

        $saved = $this->repo->findById($id, $tenantId);

        return Response::json(['data' => $saved !== null ? $this->withResolvedTheme($tenantId, $saved) : null])
            ->withHeaders(['ETag' => self::etag($version)]);
    }

    /**
     * PATCH /api/document-sources/{id}: who can see it (scope,
     * required_permission, owner_ou_id), from the Document templates page
     * (A31). Making a document shared is a publish action, as when it is
     * created: changing a sharing attribute into a shared state needs
     * documents:publish. A value sent back unchanged is not a change.
     *
     * @param array<string, string> $params
     */
    public function updateAudience(Request $request, array $params): Response
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

        $body = JsonBody::parsed($request);
        $fields = [];
        if (array_key_exists('scope', $body)) {
            $scope = $this->normalizeScope($body['scope']);
            if ($scope === null) {
                return Response::error('scope must be one of: ' . implode(', ', DocumentAccessPolicy::SCOPES), 422);
            }
            $fields['scope'] = $scope;
        }
        if (array_key_exists('required_permission', $body)) {
            $fields['required_permission'] = is_string($body['required_permission']) && trim($body['required_permission']) !== ''
                ? trim($body['required_permission'])
                : null;
        }
        if (array_key_exists('owner_ou_id', $body)) {
            $parsed = $this->parseOwnerOu($body['owner_ou_id'], $tenantId);
            if ($parsed instanceof Response) {
                return $parsed;
            }
            $fields['owner_ou_id'] = $parsed;
        }
        if ($fields === []) {
            return Response::error('Send scope, required_permission or owner_ou_id', 422);
        }

        $changes = (array_key_exists('scope', $fields) && $fields['scope'] !== $row['scope'])
            || (array_key_exists('required_permission', $fields) && $fields['required_permission'] !== $row['required_permission'])
            || (array_key_exists('owner_ou_id', $fields) && $fields['owner_ou_id'] !== $row['owner_ou_id']);
        $scope = $fields['scope'] ?? $row['scope'];
        $permission = array_key_exists('required_permission', $fields) ? $fields['required_permission'] : $row['required_permission'];
        $ou = array_key_exists('owner_ou_id', $fields) ? $fields['owner_ou_id'] : $row['owner_ou_id'];
        if ($changes
            && $this->policy->needsPublish($scope, $permission, $ou)
            && !$this->permissionResolver($callerId, $tenantId)(CorePermissions::DOCUMENTS_PUBLISH)) {
            return Response::error('Sharing a document requires documents:publish', 403);
        }

        $this->repo->updateAudience($id, $tenantId, $fields);

        return Response::json(['data' => $this->repo->findById($id, $tenantId)]);
    }

    /**
     * POST /api/document-sources/{id}/copy: "Use a copy" (A31), most of all
     * for Whity's starters. The copy is a personal draft of the caller's,
     * titled as sent (or as the original), with the original's draft content
     * and own fields; it isn't published, and it doesn't inherit an audience
     * chosen for a different document.
     *
     * @param array<string, string> $params
     */
    public function copy(Request $request, array $params): Response
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

        return $this->personalCopy(
            $tenantId,
            $callerId,
            $row,
            (string) $row['kind'],
            ['page' => $row['page'], 'blocks' => $row['blocks'], 'own_fields' => $row['own_fields'], 'title' => (string) $row['title'], 'theme' => $row['theme'] ?? null],
            JsonBody::parsed($request),
        );
    }

    /**
     * POST /api/document-sources/{id}/drafts: "start from a template" (editor
     * M4c, "Edit before issuing"; DOCUMENT-AUTOMATION.md 3.4). A template's
     * PUBLISHED version becomes a one-off `letter` with the same record type
     * and own fields, a personal draft for one person to change and issue;
     * issuing it freezes what it says (POST /api/documents).
     *
     * @param array<string, string> $params
     */
    public function drafts(Request $request, array $params): Response
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
        if ($row['kind'] !== 'template') {
            return Response::error('Only a template starts a document to issue', 422);
        }
        $published = $row['published_version'] !== null && $this->versions !== null
            ? $this->versions->find((int) $row['id'], $tenantId, (int) $row['published_version'])
            : null;
        if ($published === null) {
            return Response::error('This template has no published version yet', 409);
        }

        return $this->personalCopy(
            $tenantId,
            $callerId,
            $row,
            'letter',
            ['page' => $published['page'], 'blocks' => $published['blocks'], 'own_fields' => $published['own_fields'], 'title' => $published['title'], 'theme' => $published['theme']],
            JsonBody::parsed($request),
        );
    }

    /**
     * A personal, unpublished draft of the caller's with this content.
     *
     * @param array<string, mixed> $row     The original.
     * @param array{page: mixed, blocks: mixed, own_fields: mixed, title: string, theme: mixed} $content
     * @param array<string, mixed> $body    The request: an optional `title`.
     */
    private function personalCopy(int $tenantId, int $callerId, array $row, string $kind, array $content, array $body): Response
    {
        $title = is_string($body['title'] ?? null) && trim($body['title']) !== '' ? trim($body['title']) : $content['title'];
        if (mb_strlen($title) > 255) {
            return Response::error('title must be at most 255 characters', 422);
        }

        $id = $this->repo->create($tenantId, [
            'kind'                => $kind,
            'title'               => $title,
            'lang'                => (string) $row['lang'],
            'default_view'        => (string) $row['default_view'],
            'full_width'          => $row['full_width'] === true,
            'page'                => $content['page'],
            'record_type'         => $row['record_type'],
            'blocks'              => $content['blocks'],
            'own_fields'          => $content['own_fields'] !== [] ? $content['own_fields'] : null,
            'theme'               => is_array($content['theme']) ? $content['theme'] : null,
            'scope'               => DocumentAccessPolicy::SCOPE_PERSONAL,
            'required_permission' => null,
            'created_by'          => $callerId,
            'owner_ou_id'         => null,
        ]);

        $created = $this->repo->findById($id, $tenantId);

        return Response::json(['data' => $created !== null ? $this->withResolvedTheme($tenantId, $created) : null], 201)
            ->withHeaders(['ETag' => self::etag(1)]);
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
     *               page?: ?array<string, mixed>, blocks?: list<array<string, mixed>>,
     *               own_fields?: ?list<array<string, string>>}|Response
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
            if (is_array($page) && array_key_exists('labels', $page) && !self::validLabelGrid($page['labels'])) {
                return Response::error(
                    'page.labels must be {columns 1-10, rows 1-30, size {widthMm, heightMm 10-500}, gapMm 0-50, gapYMm? 0-50}',
                    422,
                );
            }
            $fields['page'] = $page;
        }
        if (array_key_exists('own_fields', $body)) {
            $own = self::validOwnFields($body['own_fields']);
            if ($own === false) {
                return Response::error(
                    'own_fields must be a list of at most ' . self::MAX_OWN_FIELDS . ' {key, label, sample?}: keys unique, '
                    . 'lowercase letters, digits and _ (at most 40); labels at most 80 characters; samples at most 200',
                    422,
                );
            }
            $fields['own_fields'] = $own;
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
        if (array_key_exists('theme', $body)) {
            // The document's theme (editor M4d, E.44): a ref and overrides of
            // the print colour and letterhead only. A ref the workspace doesn't
            // have still saves; it resolves to the next default and the
            // publish check names it.
            $theme = $body['theme'];
            if ($theme !== null) {
                $problem = self::themeProblem($theme);
                if ($problem !== null) {
                    return Response::error($problem, 422);
                }
                /** @var array<string, mixed> $theme */
                if (is_array($theme['overrides'] ?? null)) {
                    /** @var array<string, mixed> $overrides */
                    $overrides = $theme['overrides'];
                    $theme['overrides'] = DocumentThemeTokens::normalize($overrides);
                }
            }
            $fields['theme'] = $theme;
        }

        return $fields;
    }

    /** Why a document's `theme` is invalid, or null when it is valid. */
    private static function themeProblem(mixed $theme): ?string
    {
        if (!is_array($theme) || ($theme !== [] && array_is_list($theme))) {
            return 'theme must be an object { ref?, overrides? } or null';
        }
        foreach (array_keys($theme) as $key) {
            if (!in_array($key, ['ref', 'overrides'], true)) {
                return "theme.{$key} is not allowed; theme takes ref and overrides";
            }
        }
        if (array_key_exists('ref', $theme) && $theme['ref'] !== null
            && (!is_string($theme['ref']) || !DocumentThemeRef::isValid($theme['ref']))) {
            return 'theme.ref must be a theme ref such as whity.standard or tenant.<key>';
        }
        if (array_key_exists('overrides', $theme) && $theme['overrides'] !== null) {
            $errors = DocumentThemeTokens::errors($theme['overrides'], overridesOnly: true);
            if ($errors !== []) {
                return 'theme.overrides: ' . implode(' ', $errors);
            }
        }

        return null;
    }

    /**
     * POST /api/document-sources/{id}/files: an image or file for an Image or
     * File block (E.13), multipart with one `file` part.
     *
     * The caller must be able to see the document and hold documents:write
     * (the route gate), the same as saving its draft. The type is sniffed from
     * the bytes and checked against what the browser declared
     * ({@see FormUploadPolicy}); the response carries the file id and the
     * address the editor shows it from, never the storage key.
     *
     * @param array<string, string> $params
     */
    public function uploadFile(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;
        if ($this->files === null) {
            return Response::error('File storage is not configured', 503);
        }

        $id = (int) ($params['id'] ?? 0);
        if ($this->visibleRow($id, $tenantId, $callerId) === null) {
            return Response::error('Document not found', 404);
        }

        try {
            $part = UploadedFilePart::read($request, 'file');
            $contentType = FormUploadPolicy::assertAcceptable($part['bytes'], $part['media_type'], FormUploadPolicy::MAX_BYTES);
            $stored = $this->files->put($tenantId, $id, $part['bytes'], $contentType, $part['filename'], $callerId);
        } catch (UploadedFilePartException $e) {
            return Response::error($e->clientMessage, $e->status);
        } catch (FormRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        } catch (StorageException $e) {
            error_log('[DocumentSourcesApiHandler] storage write failed: ' . $e->getMessage());

            return Response::error('The file could not be stored. Please try again.', 503);
        }

        return Response::json(['data' => [
            'id' => $stored['id'],
            'url' => '/api/v1/document-sources/' . $id . '/files/' . $stored['id'] . '/content',
            'filename' => $stored['filename'],
            'content_type' => $stored['content_type'],
            'byte_size' => $stored['byte_size'],
            'checksum_sha256' => $stored['checksum_sha256'],
        ]], 201);
    }

    /**
     * GET /api/document-sources/{id}/files/{fileId}/content: the bytes of a
     * file, readable exactly when its document is. The file id is bound to the
     * document and the tenant, so a guessed id cannot be walked across others.
     *
     * @param array<string, string> $params
     */
    public function fileContent(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;
        if ($this->files === null) {
            return Response::error('File storage is not configured', 503);
        }

        $id = (int) ($params['id'] ?? 0);
        $file = $this->visibleRow($id, $tenantId, $callerId) === null
            ? null
            : $this->files->find((int) ($params['fileId'] ?? 0), $id, $tenantId);
        if ($file === null) {
            return Response::error('File not found', 404);
        }

        try {
            $bytes = $this->files->bytes((string) $file['storage_key']);
        } catch (StorageException $e) {
            error_log('[DocumentSourcesApiHandler] reading a stored file failed: ' . $e->getMessage());

            return Response::error('The file is temporarily unavailable', 503);
        }

        $type = (string) $file['content_type'];
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($file['filename'] ?? 'file')) ?: 'file';

        return new Response(200, $bytes, [
            'Content-Type' => $type,
            // Images show in the page; anything else downloads.
            'Content-Disposition' => (str_starts_with($type, 'image/') ? 'inline' : 'attachment') . '; filename="' . $name . '"',
            // A stored file never changes, so it caches hard, but privately: the bytes are permission-gated.
            'ETag' => '"' . $file['checksum_sha256'] . '"',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A template's own fields (editor M4b): null clears them, false when the
     * list isn't valid.
     *
     * @return list<array<string, string>>|null|false
     */
    private static function validOwnFields(mixed $list): array|null|false
    {
        if ($list === null) {
            return null;
        }
        if (!is_array($list) || !array_is_list($list) || count($list) > self::MAX_OWN_FIELDS) {
            return false;
        }
        $out = [];
        $seen = [];
        foreach ($list as $field) {
            if (!is_array($field)) {
                return false;
            }
            $key = $field['key'] ?? null;
            $label = $field['label'] ?? null;
            $sample = $field['sample'] ?? null;
            if (!is_string($key) || preg_match('/^[a-z0-9_]{1,40}$/', $key) !== 1 || isset($seen[$key])) {
                return false;
            }
            if (!is_string($label) || trim($label) === '' || mb_strlen($label) > 80) {
                return false;
            }
            if ($sample !== null && (!is_string($sample) || mb_strlen($sample) > 200)) {
                return false;
            }
            $seen[$key] = true;
            $out[] = $sample !== null && $sample !== '' ? ['key' => $key, 'label' => trim($label), 'sample' => $sample] : ['key' => $key, 'label' => trim($label)];
        }

        return $out;
    }

    /** E.43: a label sheet's grid, as the editor's LabelGrid. Whity Render checks it fits the sheet. */
    private static function validLabelGrid(mixed $grid): bool
    {
        if (!is_array($grid) || !is_array($grid['size'] ?? null)) {
            return false;
        }
        $whole = static fn (mixed $v, int $min, int $max): bool => is_int($v) && $v >= $min && $v <= $max;
        $mm = static fn (mixed $v, float $min, float $max): bool => (is_int($v) || is_float($v)) && $v >= $min && $v <= $max;

        return $whole($grid['columns'] ?? null, 1, 10)
            && $whole($grid['rows'] ?? null, 1, 30)
            && $mm($grid['size']['widthMm'] ?? null, 10, 500)
            && $mm($grid['size']['heightMm'] ?? null, 10, 500)
            && $mm($grid['gapMm'] ?? null, 0, 50)
            && (!array_key_exists('gapYMm', $grid) || $mm($grid['gapYMm'], 0, 50));
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
    public static function parseIfMatch(?string $header): ?int
    {
        if ($header === null || preg_match('/^\s*(?:W\/)?"(\d{1,9})"\s*$/', $header, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    /**
     * The row when this caller may see it, else null (not found and hidden
     * answer the same). Public so the publishing routes check exactly this.
     *
     * @return array<string, mixed>|null
     */
    public function visibleRow(int $id, int $tenantId, int $callerId): ?array
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
