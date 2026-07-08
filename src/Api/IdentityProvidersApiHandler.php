<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Per-tenant identity-provider (SSO/OIDC) admin CRUD (WC-e6287).
 *
 * Routes (registered in public/index.php), all gated on `auth_providers:manage`
 * and tenant-scoped via TenantContext:
 *   GET    /api/identity-providers        → list()
 *   POST   /api/identity-providers        → create()
 *   PATCH  /api/identity-providers/{id}   → update()
 *   DELETE /api/identity-providers/{id}   → delete()
 *
 * The client secret is accepted as plaintext on write, encrypted at rest via
 * {@see EncryptedSecretStore}, and NEVER returned (the repository omits it and
 * exposes only `has_secret`). Error bodies are generic; internal detail is logged.
 */
final class IdentityProvidersApiHandler
{
    /** Provider keys we currently support configuring. */
    private const ALLOWED_PROVIDERS = ['google', 'microsoft', 'oidc'];

    private IdentityProviderRepository $repo;

    public function __construct(PDO $db, private readonly EncryptedSecretStore $secrets)
    {
        $this->repo = new IdentityProviderRepository($db);
    }

    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        return Response::json(['data' => $this->repo->listForTenant($tenantId)]);
    }

    public function create(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $body = JsonBody::parsed($request);

        $providerKey = strtolower(trim((string) ($body['provider_key'] ?? '')));
        if (!in_array($providerKey, self::ALLOWED_PROVIDERS, true)) {
            return Response::error('provider_key must be one of: ' . implode(', ', self::ALLOWED_PROVIDERS), 422);
        }

        $displayName = trim((string) ($body['display_name'] ?? ''));
        $clientId    = trim((string) ($body['client_id'] ?? ''));
        $issuer      = trim((string) ($body['issuer'] ?? ''));
        if ($displayName === '' || $clientId === '' || $issuer === '') {
            return Response::error('display_name, client_id and issuer are required', 422);
        }
        if (!$this->isHttpsUrl($issuer)) {
            return Response::error('issuer must be an https URL', 422);
        }
        $discoveryUrl = isset($body['discovery_url']) ? trim((string) $body['discovery_url']) : '';
        if ($discoveryUrl !== '' && !$this->isHttpsUrl($discoveryUrl)) {
            return Response::error('discovery_url must be an https URL', 422);
        }

        $data = [
            'provider_key'  => $providerKey,
            'display_name'  => $displayName,
            'client_id'     => $clientId,
            'issuer'        => $issuer,
            'discovery_url' => $discoveryUrl !== '' ? $discoveryUrl : null,
            'scopes'        => $this->normalizeScopes($body['scopes'] ?? null),
            'domain'        => $this->normalizeDomain($body['domain'] ?? null),
            'enabled'       => !isset($body['enabled']) || (bool) $body['enabled'],
            'client_secret_encrypted' => $this->encryptSecretOrNull($body['client_secret'] ?? null),
        ];

        try {
            $id = $this->repo->insert($tenantId, $data);
        } catch (\PDOException $e) {
            if (stripos($e->getMessage(), 'unique') !== false) {
                return Response::error('This provider is already configured for your tenant', 409);
            }
            error_log('[identity-providers] create failed: ' . $e->getMessage());
            return Response::error('Failed to create identity provider', 500);
        }

        return Response::json(['data' => $this->repo->findById($id, $tenantId)], 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('Invalid id', 400);
        }

        $body = JsonBody::parsed($request);
        $data = [];

        if (array_key_exists('provider_key', $body)) {
            $providerKey = strtolower(trim((string) $body['provider_key']));
            if (!in_array($providerKey, self::ALLOWED_PROVIDERS, true)) {
                return Response::error('provider_key must be one of: ' . implode(', ', self::ALLOWED_PROVIDERS), 422);
            }
            $data['provider_key'] = $providerKey;
        }
        foreach (['display_name', 'client_id'] as $field) {
            if (array_key_exists($field, $body)) {
                $value = trim((string) $body[$field]);
                if ($value === '') {
                    return Response::error("{$field} cannot be empty", 422);
                }
                $data[$field] = $value;
            }
        }
        foreach (['issuer', 'discovery_url'] as $urlField) {
            if (array_key_exists($urlField, $body)) {
                $value = trim((string) $body[$urlField]);
                if ($value !== '' && !$this->isHttpsUrl($value)) {
                    return Response::error("{$urlField} must be an https URL", 422);
                }
                $data[$urlField] = $value !== '' ? $value : null;
            }
        }
        if (array_key_exists('scopes', $body)) {
            $data['scopes'] = $this->normalizeScopes($body['scopes']);
        }
        if (array_key_exists('domain', $body)) {
            $data['domain'] = $this->normalizeDomain($body['domain']);
        }
        if (array_key_exists('enabled', $body)) {
            $data['enabled'] = (bool) $body['enabled'];
        }
        // Only re-encrypt/replace the secret when the caller actually sends one.
        if (array_key_exists('client_secret', $body)) {
            $data['client_secret_encrypted'] = $this->encryptSecretOrNull($body['client_secret']);
        }

        if ($data === []) {
            return Response::error('No updatable fields supplied', 422);
        }

        $affected = $this->repo->update($id, $tenantId, $data);
        if ($affected === 0) {
            return Response::error('Identity provider not found', 404);
        }

        return Response::json(['data' => $this->repo->findById($id, $tenantId)]);
    }

    /**
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('Invalid id', 400);
        }

        if ($this->repo->delete($id, $tenantId) === 0) {
            return Response::error('Identity provider not found', 404);
        }
        return Response::json([], 204);
    }

    private function encryptSecretOrNull(mixed $secret): ?string
    {
        if (!is_string($secret) || trim($secret) === '') {
            return null;
        }
        return $this->secrets->encrypt($secret);
    }

    private function normalizeScopes(mixed $scopes): string
    {
        if (is_array($scopes)) {
            $scopes = implode(' ', array_map(static fn($s): string => trim((string) $s), $scopes));
        }
        $scopes = trim((string) $scopes);
        return $scopes !== '' ? $scopes : 'openid email profile';
    }

    private function normalizeDomain(mixed $domain): ?string
    {
        $domain = strtolower(trim((string) $domain));
        return $domain !== '' ? $domain : null;
    }

    private function isHttpsUrl(string $url): bool
    {
        return filter_var($url, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($url), 'https://');
    }
}
