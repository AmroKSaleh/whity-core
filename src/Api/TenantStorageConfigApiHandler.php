<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Storage\TenantStorageConfigRepository;

/**
 * Per-tenant storage backend self-service CRUD (WC-storage).
 *
 * Routes (registered in public/index.php), tenant-scoped via TenantContext and
 * gated on `storage:manage`:
 *   GET    /api/storage-config → get()    — this tenant's config (+ entitlement)
 *   PUT    /api/storage-config → put()    — set/replace the config
 *   DELETE /api/storage-config → delete() — remove it (revert to platform default)
 *
 * The plan gate: a write additionally requires the `storage.custom_backend`
 * ENTITLEMENT (403 otherwise), so a tenant may only configure a custom backend
 * when its subscription includes it. The resolver enforces the same entitlement
 * at read time, so a tenant that later loses it silently falls back to the
 * default even if a config row lingers.
 *
 * The secret is accepted as plaintext on write, encrypted at rest via
 * {@see EncryptedSecretStore}, and NEVER returned (reads expose only `has_secret`).
 * A PUT that omits the secret keeps the stored one. Error bodies are generic.
 */
final class TenantStorageConfigApiHandler
{
    /** The object-storage backend kinds a tenant may configure today. */
    private const ALLOWED_DRIVERS = ['s3'];

    /**
     * Backing-column widths — an over-long value is a 422 here rather than a
     * Postgres 22001 → generic 500.
     *
     * @var array<string, int>
     */
    private const MAX_LENGTHS = [
        'endpoint'        => 512,
        'region'          => 128,
        'bucket'          => 255,
        'access_key'      => 512,
        'public_base_url' => 512,
    ];

    private TenantStorageConfigRepository $repo;

    public function __construct(
        PDO $db,
        private readonly EncryptedSecretStore $secrets,
        private readonly EntitlementService $entitlements,
    ) {
        $this->repo = new TenantStorageConfigRepository($db);
    }

    public function get(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        return Response::json([
            'data' => [
                'config'  => $this->repo->findForTenant($tenantId),
                'entitled' => $this->entitlements->isGranted($tenantId, EntitlementRegistry::STORAGE_CUSTOM_BACKEND),
                'drivers' => self::ALLOWED_DRIVERS,
            ],
        ]);
    }

    public function put(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        // Plan gate: configuring a custom backend requires the entitlement.
        if (!$this->entitlements->isGranted($tenantId, EntitlementRegistry::STORAGE_CUSTOM_BACKEND)) {
            return Response::error('A custom storage backend is not included in your plan', 403);
        }

        $body = JsonBody::parsed($request);

        $driver = strtolower(trim((string) ($body['driver'] ?? 's3')));
        if (!in_array($driver, self::ALLOWED_DRIVERS, true)) {
            return Response::error('driver must be one of: ' . implode(', ', self::ALLOWED_DRIVERS), 422);
        }

        $endpoint  = trim((string) ($body['endpoint'] ?? ''));
        $region    = trim((string) ($body['region'] ?? ''));
        $bucket    = trim((string) ($body['bucket'] ?? ''));
        $accessKey = trim((string) ($body['access_key'] ?? ''));
        if ($endpoint === '' || $region === '' || $bucket === '' || $accessKey === '') {
            return Response::error('endpoint, region, bucket and access_key are required', 422);
        }
        if (!$this->isHttpsUrl($endpoint)) {
            return Response::error('endpoint must be an https URL', 422);
        }

        $publicBaseUrl = isset($body['public_base_url']) ? trim((string) $body['public_base_url']) : '';
        if ($publicBaseUrl !== '' && !$this->isHttpsUrl($publicBaseUrl)) {
            return Response::error('public_base_url must be an https URL', 422);
        }

        // Secret: use the newly-supplied one, else keep the stored one; a brand-new
        // config with no secret is rejected (an S3 backend cannot work without it).
        $secretEncrypted = $this->resolveSecret($tenantId, $body['secret'] ?? null);
        if ($secretEncrypted === null) {
            return Response::error('secret is required', 422);
        }

        $data = [
            'driver'           => $driver,
            'endpoint'         => $endpoint,
            'region'           => $region,
            'bucket'           => $bucket,
            'access_key'       => $accessKey,
            'secret_encrypted' => $secretEncrypted,
            'path_style'       => !isset($body['path_style']) || (bool) $body['path_style'],
            'public_base_url'  => $publicBaseUrl !== '' ? $publicBaseUrl : null,
        ];

        $lengthError = $this->lengthViolation($data);
        if ($lengthError !== null) {
            return Response::error($lengthError, 422);
        }

        try {
            $this->repo->upsert($tenantId, $data);
        } catch (\PDOException $e) {
            error_log('[storage-config] upsert failed: ' . $e->getMessage());
            return Response::error('Failed to save storage configuration', 500);
        }

        return Response::json(['data' => $this->repo->findForTenant($tenantId)]);
    }

    public function delete(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        if ($this->repo->delete($tenantId) === 0) {
            return Response::error('No storage configuration to remove', 404);
        }

        return Response::json([], 204);
    }

    /**
     * Resolve the secret ciphertext to store: encrypt a freshly-supplied secret,
     * or fall back to the currently-stored ciphertext. Returns null when neither
     * is available (a new config with no secret).
     */
    private function resolveSecret(int $tenantId, mixed $submitted): ?string
    {
        if (is_string($submitted) && trim($submitted) !== '') {
            return $this->secrets->encrypt($submitted);
        }

        $existing = $this->repo->findSecretCiphertext($tenantId);
        return ($existing !== null && $existing !== '') ? $existing : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function lengthViolation(array $data): ?string
    {
        foreach (self::MAX_LENGTHS as $field => $max) {
            $value = $data[$field] ?? null;
            if (is_string($value) && strlen($value) > $max) {
                return "{$field} must be {$max} characters or fewer";
            }
        }
        return null;
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://');
    }
}
