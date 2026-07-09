<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\AuthHandler;
use Whity\Auth\CookieManager;
use Whity\Auth\JwtParser;
use Whity\Auth\Oidc\OidcEngine;
use Whity\Auth\Oidc\StandardOidcProvider;
use Whity\Core\Branding\HostResolver;
use Whity\Core\Identity\ExternalIdentityRepository;
use Whity\Core\Identity\IdentityProviderRepository;
use Whity\Core\Identity\ProfileEmailRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;

/**
 * Federated sign-in ("Sign in with Google") over OIDC (WC-ae16).
 *
 * Two PUBLIC, unauthenticated GET routes:
 *   GET /api/v1/auth/sso/{provider}/start    → begin the Authorization Code + PKCE
 *     flow: resolve the tenant by host, load its enabled provider config, mint the
 *     flow-state (state/nonce/PKCE verifier) into a signed short-lived cookie, and
 *     302 to the provider's authorize endpoint.
 *   GET /api/v1/auth/sso/{provider}/callback → complete it: validate the flow-state
 *     cookie + `state` (CSRF/replay), exchange the code, verify the ID token, map
 *     (issuer, subject) → a LINKED local profile, and mint a session.
 *
 * SCOPE (A5c): this logs in an ALREADY-LINKED identity. A first-time identity with
 * no link is NOT auto-provisioned here — that (and its anti-takeover rules) is the
 * account-linking step (WC-f3b17bd2); an unlinked identity is bounced to /login
 * with a generic marker. Both routes are GET, so the CSRF guard (POST-only) does
 * not apply; `state` is the CSRF defense.
 */
final class SsoAuthHandler
{
    public function __construct(
        private readonly OidcEngine $engine,
        private readonly IdentityProviderRepository $providers,
        private readonly ExternalIdentityRepository $identities,
        private readonly ProfileEmailRepository $emails,
        private readonly HostResolver $hostResolver,
        private readonly JwtParser $jwtParser,
        private readonly EncryptedSecretStore $secrets,
        private readonly AuthHandler $auth,
        private readonly string $appUrl,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function start(Request $request, array $params): Response
    {
        $providerKey = $this->providerKey($params);
        if ($providerKey === null) {
            return $this->fail('unknown_provider');
        }

        $tenantId = $this->resolveTenantId($request);
        $config = $this->providers->findEnabledByProviderKey($tenantId, $providerKey);
        if ($config === null) {
            // Not configured for this tenant → generic bounce (no enumeration).
            return $this->fail('provider_unavailable');
        }

        $discovery = $this->engine->discover($this->discoveryUrl($config));
        if ($discovery === null) {
            return $this->fail('provider_unavailable');
        }

        $provider = $this->providerFor($config);
        $state = $this->engine->randomToken();
        $nonce = $this->engine->randomToken();
        $pkce  = $this->engine->generatePkce();
        $redirectUri = $this->redirectUri($providerKey);

        // Flow state → a signed, short-lived cookie (HttpOnly/Lax/Secure). Binding
        // the state inside the signed cookie to the state echoed by the provider is
        // the CSRF/replay defense; the verifier never leaves the server unencrypted
        // to the provider (only the S256 challenge does).
        $flowToken = $this->jwtParser->create([
            'state'         => $state,
            'nonce'         => $nonce,
            'code_verifier' => $pkce['verifier'],
            'provider_id'   => (int) $config['id'],
            'tenant_id'     => $tenantId,
            'provider_key'  => $providerKey,
        ], 600, 'oidc_flow');
        CookieManager::setSsoFlowToken($flowToken, 600);

        $authorizeUrl = $this->engine->buildAuthorizationUrl(
            $provider,
            $discovery,
            (string) $config['client_id'],
            $redirectUri,
            $state,
            $nonce,
            $pkce['challenge'],
        );

        return new Response(302, '', ['Location' => $authorizeUrl]);
    }

    /**
     * @param array<string, string> $params
     */
    public function callback(Request $request, array $params): Response
    {
        $providerKey = $this->providerKey($params);
        if ($providerKey === null) {
            return $this->fail('unknown_provider');
        }

        // The flow-state cookie is single-use — read then clear immediately.
        $flowCookie = CookieManager::getSsoFlowToken();
        CookieManager::clearSsoFlowToken();
        if ($flowCookie === null) {
            return $this->fail('expired');
        }

        $flow = $this->jwtParser->parse($flowCookie);
        if ($flow === null || ($flow['type'] ?? null) !== 'oidc_flow') {
            return $this->fail('expired');
        }

        // The provider may signal an error (user denied consent, etc.).
        if ($this->query('error') !== null) {
            return $this->fail('denied');
        }

        // CSRF/replay: the echoed state MUST equal the state minted into the cookie.
        $stateParam = $this->query('state');
        if (!is_string($flow['state'] ?? null) || !is_string($stateParam)
            || !hash_equals((string) $flow['state'], $stateParam)
        ) {
            return $this->fail('state_mismatch');
        }

        $code = $this->query('code');
        if ($code === null || $code === '') {
            return $this->fail('failed');
        }

        // Re-load the provider config by the id + tenant captured at /start (never
        // re-resolved from the callback host, so a different host can't swap config).
        $providerId = (int) ($flow['provider_id'] ?? 0);
        $tenantId   = (int) ($flow['tenant_id'] ?? -1);
        $config = $this->providers->findById($providerId, $tenantId);
        if ($config === null || (string) $config['provider_key'] !== $providerKey) {
            return $this->fail('provider_unavailable');
        }

        $discovery = $this->engine->discover($this->discoveryUrl($config));
        if ($discovery === null) {
            return $this->fail('provider_unavailable');
        }

        $ciphertext = $this->providers->findClientSecretCiphertext($providerId, $tenantId);
        $clientSecret = null;
        if ($ciphertext !== null) {
            try {
                $clientSecret = $this->secrets->decrypt($ciphertext);
            } catch (\Throwable $e) {
                error_log('[sso] client-secret decrypt failed for provider ' . $providerId);
                return $this->fail('provider_unavailable');
            }
        }

        $tokens = $this->engine->exchangeCode(
            $discovery,
            (string) $config['client_id'],
            $clientSecret,
            $code,
            $this->redirectUri($providerKey),
            is_string($flow['code_verifier'] ?? null) ? (string) $flow['code_verifier'] : '',
        );
        $idToken = is_array($tokens) && isset($tokens['id_token']) && is_string($tokens['id_token'])
            ? $tokens['id_token'] : null;
        if ($idToken === null) {
            return $this->fail('failed');
        }

        $nonce = is_string($flow['nonce'] ?? null) ? (string) $flow['nonce'] : null;
        $identity = $this->engine->verifyIdToken(
            $idToken,
            $discovery,
            (string) $config['client_id'],
            (string) $config['issuer'],
            $nonce,
            $this->providerFor($config),
        );
        if ($identity === null) {
            return $this->fail('failed');
        }

        // Map the verified external identity → a LINKED local profile. A5c does not
        // provision/link a first-time identity (that is WC-f3b17bd2) — bounce it.
        $link = $this->identities->findByIssuerSubject($identity->issuer, $identity->subject);
        if ($link === null) {
            return $this->fail('not_linked');
        }
        $this->identities->touchLastLogin((int) $link['id']);

        $profileId = (int) $link['profile_id'];
        $email = $this->loginEmailFor($profileId, $identity->normalizedEmail());

        // Mint the session (sets cookies as a side effect). Translate the JSON
        // result into a browser redirect: dashboard on success, tenant-selection or
        // a generic error otherwise.
        $result = $this->auth->completeFederatedLogin($profileId, $email, $request);
        return $this->redirectForLoginResult($result);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    /**
     * @param array<string, string> $params
     */
    private function providerKey(array $params): ?string
    {
        $key = strtolower(trim($params['provider'] ?? ''));
        return preg_match('/^[a-z][a-z0-9_]{1,63}$/', $key) === 1 ? $key : null;
    }

    private function resolveTenantId(Request $request): int
    {
        $host = $request->getHeader('X-Forwarded-Host') ?? $request->getHeader('Host') ?? '';
        return $this->hostResolver->resolveTenantIdByHost($host) ?? 0;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function discoveryUrl(array $config): string
    {
        $discovery = isset($config['discovery_url']) ? (string) $config['discovery_url'] : '';
        if ($discovery !== '') {
            return $discovery;
        }
        return rtrim((string) $config['issuer'], '/') . '/.well-known/openid-configuration';
    }

    /**
     * @param array<string, mixed> $config
     */
    private function providerFor(array $config): StandardOidcProvider
    {
        $scopes = array_values(array_filter(explode(' ', (string) $config['scopes']), static fn(string $s): bool => $s !== ''));
        if ($scopes === []) {
            $scopes = ['openid', 'email', 'profile'];
        }
        return new StandardOidcProvider(
            (string) $config['provider_key'],
            (string) $config['display_name'],
            $scopes,
        );
    }

    private function redirectUri(string $providerKey): string
    {
        return rtrim($this->appUrl, '/') . '/api/v1/auth/sso/' . $providerKey . '/callback';
    }

    /**
     * Read an OIDC callback query parameter. The framework does not model query
     * strings on {@see Request} (routing uses the path only), so the provider's
     * ?code/?state/?error come from $_GET, which FrankenPHP populates per request.
     */
    private function query(string $name): ?string
    {
        $value = $_GET[$name] ?? null;
        return is_string($value) ? $value : null;
    }

    /**
     * The email to stamp on the session: prefer the profile's primary address,
     * fall back to the IdP-asserted one.
     */
    private function loginEmailFor(int $profileId, ?string $identityEmail): string
    {
        $primary = $this->emails->findPrimaryForProfile($profileId);
        if ($primary !== null && isset($primary['email'])) {
            return (string) $primary['email'];
        }
        return $identityEmail ?? '';
    }

    /**
     * Translate completeFederatedLogin's JSON result into a browser 302. Session
     * cookies were already emitted as a side effect of the login; here we only
     * choose the destination.
     */
    private function redirectForLoginResult(Response $result): Response
    {
        $body = json_decode($result->getBody(), true);
        $data = is_array($body) ? $body : [];

        if ($result->getStatusCode() === 200 && isset($data['user'])) {
            return new Response(302, '', ['Location' => rtrim($this->appUrl, '/') . '/dashboard']);
        }
        if ($result->getStatusCode() === 200 && ($data['requires_tenant_selection'] ?? false) === true) {
            // The tenant-selection cookie is set; the login UI completes the choice.
            return new Response(302, '', ['Location' => rtrim($this->appUrl, '/') . '/login?sso=select']);
        }
        // No active membership, or anything unexpected.
        return $this->fail('no_membership');
    }

    /** Bounce to the login page with a generic, non-enumerating SSO marker. */
    private function fail(string $reason): Response
    {
        return new Response(302, '', ['Location' => rtrim($this->appUrl, '/') . '/login?sso_error=' . $reason]);
    }
}
