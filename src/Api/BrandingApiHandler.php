<?php
declare(strict_types=1);
namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Branding\BrandingAssetKind;
use Whity\Core\Branding\BrandingAssetRejectedException;
use Whity\Core\Branding\BrandingService;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Branding\TenantHostRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsValidationException;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageKey;

/**
 * Tenant Branding API handler (Tenant Branding feature).
 *
 * Exposes the branding resolution and asset management surface:
 *  - GET  /api/v1/branding              — public effective branding
 *  - GET  /api/v1/branding/asset/{t}/{n} — public hardened asset serve
 *  - POST/DELETE /api/v1/branding/assets/{key}        — tenant override (settings:write)
 *  - POST/DELETE /api/v1/branding/global/assets/{key} — global default (settings:manage)
 *  - PUT /api/v1/tenants/{id}/branding-host           — custom host (settings:manage)
 *
 * Holds no request state — safe for a FrankenPHP worker.
 */
final class BrandingApiHandler
{
    public function __construct(
        private readonly BrandingService $branding,
        private readonly HostResolver $hostResolver,
        private readonly ?RoleChecker $roleChecker = null,
        private readonly ?TenantHostRepository $hostRepo = null,
        private readonly ?StorageDriverInterface $storage = null,
    ) {}

    /** GET /api/v1/branding — public effective branding. */
    public function get(Request $request): Response
    {
        try {
            $tenantId = $this->resolveDisplayTenant($request);
            $res = Response::json(['data' => $this->branding->effective($tenantId)->toArray()], 200);
            return new Response(200, $res->getBody(), $res->getHeaders() + ['Cache-Control' => 'public, max-age=60']);
        } catch (\Throwable $e) {
            error_log('[BrandingApiHandler] get failed: ' . $e->getMessage());
            return Response::error('Failed to resolve branding', 500);
        }
    }

    private function resolveDisplayTenant(Request $request): int
    {
        $ctx = TenantContext::getTenantId();
        if ($ctx !== null) {
            return $ctx;
        }
        $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';
        return $this->hostResolver->resolveTenantIdByHost($host) ?? 0;
    }

    /** GET /api/v1/branding/asset/{tenantId}/{name} — public, hardened. */
    public function serveAsset(Request $request, array $params = []): Response
    {
        if ($this->storage === null) {
            return Response::error('Branding storage unavailable', 500);
        }
        $tenantId = (int) ($params['tenantId'] ?? -1);
        $name = (string) ($params['name'] ?? '');
        if ($tenantId < 0 || $name === '') {
            return Response::error('Not found', 404);
        }
        $key = StorageKey::build($tenantId, 'branding', $name);
        try {
            if (!$this->storage->exists($key)) {
                return Response::error('Not found', 404);
            }
            $bytes = $this->storage->get($key);
            $mime = $this->storage->mimeType($key);
        } catch (\Throwable $e) {
            error_log('[BrandingApiHandler] serveAsset failed: ' . $e->getMessage());
            return Response::error('Not found', 404);
        }
        return new Response(200, $bytes, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'ETag' => '"' . substr(hash('sha256', $bytes), 0, 16) . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'",
            'Content-Disposition' => 'inline',
        ]);
    }

    /** POST /api/v1/branding/assets/{key} — tenant override upload (settings:write). */
    public function uploadTenant(Request $request, array $params = []): Response
    {
        return $this->handleUpload($request, $params, CorePermissions::SETTINGS_WRITE, false);
    }

    /** DELETE /api/v1/branding/assets/{key} — clear tenant override (settings:write). */
    public function clearTenant(Request $request, array $params = []): Response
    {
        return $this->handleClear($request, $params, CorePermissions::SETTINGS_WRITE, false);
    }

    /** POST /api/v1/branding/global/assets/{key} — global default upload (settings:manage). */
    public function uploadGlobal(Request $request, array $params = []): Response
    {
        return $this->handleUpload($request, $params, CorePermissions::SETTINGS_MANAGE, true);
    }

    /** DELETE /api/v1/branding/global/assets/{key} — clear global default (settings:manage). */
    public function clearGlobal(Request $request, array $params = []): Response
    {
        return $this->handleClear($request, $params, CorePermissions::SETTINGS_MANAGE, true);
    }

    private function handleUpload(Request $request, array $params, string $perm, bool $global): Response
    {
        $ctx = $this->authorize($request, $perm);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $assetKey = (string) ($params['key'] ?? '');
        if (!BrandingAssetKind::isValid($assetKey)) {
            return Response::error('Unknown branding asset', 404);
        }
        // Read file bytes from multipart body
        $bytes = $this->readUploadedFile($request);
        if ($bytes === null) {
            return Response::error('A file (field "file") is required.', 400);
        }
        $scopeTenant = $global ? 0 : $ctx['tenantId'];
        try {
            $this->branding->uploadAsset($scopeTenant, $assetKey, $bytes);
        } catch (SettingsValidationException $e) {
            return Response::error('Validation failed', 422, [$e->settingKey() => $e->reason()]);
        } catch (BrandingAssetRejectedException $e) {
            return Response::error($e->getMessage(), 422);
        } catch (\Throwable $e) {
            error_log('[BrandingApiHandler] upload failed: ' . $e->getMessage());
            return Response::error('Failed to store asset', 500);
        }
        $view = $this->branding->effective($global ? 0 : $ctx['tenantId']);
        return Response::json(['data' => $view->toArray()], 200);
    }

    private function handleClear(Request $request, array $params, string $perm, bool $global): Response
    {
        $ctx = $this->authorize($request, $perm);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        $assetKey = (string) ($params['key'] ?? '');
        if (!BrandingAssetKind::isValid($assetKey)) {
            return Response::error('Unknown branding asset', 404);
        }
        $scopeTenant = $global ? 0 : $ctx['tenantId'];
        try {
            $this->branding->clearAsset($scopeTenant, $assetKey);
        } catch (SettingsValidationException $e) {
            return Response::error('Validation failed', 422, [$e->settingKey() => $e->reason()]);
        } catch (\Throwable $e) {
            error_log('[BrandingApiHandler] clear failed: ' . $e->getMessage());
            return Response::error('Failed to clear asset', 500);
        }
        $view = $this->branding->effective($scopeTenant);
        return Response::json(['data' => $view->toArray()], 200);
    }

    /** PUT /api/v1/tenants/{id}/branding-host — set/clear a custom host (settings:manage). */
    public function setBrandingHost(Request $request, array $params = []): Response
    {
        $ctx = $this->authorize($request, CorePermissions::SETTINGS_MANAGE);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        if ($this->hostRepo === null) {
            return Response::error('Branding host store unavailable', 500);
        }
        $targetTenant = (int) ($params['id'] ?? 0);
        $body = JsonBody::parsed($request);
        $host = $body['host'] ?? null;
        if ($host === null || $host === '') {
            $this->hostRepo->setBrandingHost($targetTenant, null);
            return Response::json(['data' => ['branding_host' => null]], 200);
        }
        if (!is_string($host)) {
            return Response::error('Validation failed', 422, ['host' => 'host must be a string or null.']);
        }
        $host = strtolower(trim($host));
        if (strlen($host) > 255 || preg_match('/^[a-z0-9.-]+$/', $host) !== 1 || !str_contains($host, '.')) {
            return Response::error('Validation failed', 422, ['host' => 'host must be a bare hostname (e.g. app.acme.com).']);
        }
        if ($this->hostRepo->brandingHostExists($host, $targetTenant)) {
            return Response::error('That hostname is already claimed by another tenant.', 409, ['host' => $host]);
        }
        $this->hostRepo->setBrandingHost($targetTenant, $host);
        return Response::json(['data' => ['branding_host' => $host]], 200);
    }

    /** @return array{tenantId:int,userId:int}|Response */
    private function authorize(Request $request, string $permission): array|Response
    {
        if ($this->roleChecker === null) {
            return Response::error('Forbidden', 403);
        }
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->user_id) && is_int($actor->user_id) ? $actor->user_id : null;
        if ($userId === null || !$this->roleChecker->hasPermission($userId, $permission, $tenantId)) {
            return Response::error('Insufficient permissions', 403, ['required' => $permission]);
        }
        return ['tenantId' => $tenantId, 'userId' => $userId];
    }

    /** Read the 'file' part from a multipart/form-data body. Returns null if not found. */
    private function readUploadedFile(Request $request): ?string
    {
        // Try getUploadedFiles() — available in SDK v1.5+
        try {
            $files = $request->getUploadedFiles();
            $file = $files['file'] ?? null;
            if ($file !== null) {
                $path = $file->getStreamPath();
                if ($path !== '' && is_file($path)) {
                    $bytes = file_get_contents($path);
                    return $bytes !== false ? $bytes : null;
                }
                // Temp file may not exist in test context; try inline bytes via
                // the fallback multipart parser instead.
            }
        } catch (\Throwable) {
            // Fall through to body-based parser
        }
        // Fallback: parse multipart body manually (used in tests where
        // getUploadedFiles() spills to a temp file that may not exist)
        return $this->parseMultipartFile($request);
    }

    private function parseMultipartFile(Request $request): ?string
    {
        $contentType = $request->getHeader('Content-Type') ?? '';
        if (!preg_match('/boundary=(.+)$/i', $contentType, $m)) {
            return null;
        }
        $boundary = trim($m[1]);
        $body = $request->getBody();
        $parts = explode('--' . $boundary, $body);
        foreach ($parts as $part) {
            if (str_contains($part, 'name="file"') || str_contains($part, "name='file'")) {
                // Split headers from body on double CRLF
                $pos = strpos($part, "\r\n\r\n");
                if ($pos !== false) {
                    $fileBytes = substr($part, $pos + 4);
                    // Trim trailing CRLF
                    return rtrim($fileBytes, "\r\n");
                }
            }
        }
        return null;
    }
}
