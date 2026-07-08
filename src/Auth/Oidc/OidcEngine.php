<?php

declare(strict_types=1);

namespace Whity\Auth\Oidc;

use Whity\Auth\JwtParser;
use Whity\Core\Http\HttpClient;
use Whity\Sdk\Auth\ExternalAuthProvider;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * Generic OIDC/OAuth2 relying-party engine — Authorization Code + PKCE (WC-ae16).
 *
 * The host-owned mechanics behind federated sign-in, deliberately free of HTTP
 * routing/session concerns (those live in the SSO handler) so the security-
 * critical protocol logic is unit-testable in isolation:
 *   - discover(): fetch + validate the provider's OIDC discovery document;
 *   - generatePkce()/randomToken(): the per-flow secrets (PKCE verifier, state, nonce);
 *   - buildAuthorizationUrl(): the /authorize redirect with S256 PKCE;
 *   - exchangeCode(): the token-endpoint POST (Authorization Code + verifier);
 *   - verifyIdToken(): kid-aware JWKS signature + iss/aud/nonce validation, then
 *     mapping to the canonical {@see ExternalIdentity}.
 *
 * All network I/O goes through the SSRF-guarded {@see HttpClient} + JWKS-caching
 * {@see JwksProvider}; nothing here dereferences a URL directly.
 */
final class OidcEngine
{
    /** Discovery-document fields the engine requires. */
    private const REQUIRED_DISCOVERY = ['issuer', 'authorization_endpoint', 'token_endpoint', 'jwks_uri'];

    public function __construct(
        private readonly HttpClient $http,
        private readonly JwksProvider $jwks,
        private readonly JwtParser $jwtParser,
    ) {
    }

    /**
     * Fetch and validate a provider's OIDC discovery document.
     *
     * @return array<string, mixed>|null The document, or null if unreachable or
     *   missing a required endpoint. All endpoints must be https (the HttpClient
     *   enforces that on fetch; we also reject a non-https endpoint here so a bad
     *   doc fails fast before it is ever used).
     */
    public function discover(string $discoveryUrl): ?array
    {
        $doc = $this->http->getJson($discoveryUrl);
        if ($doc === null) {
            return null;
        }
        foreach (self::REQUIRED_DISCOVERY as $field) {
            if (!isset($doc[$field]) || !is_string($doc[$field]) || $doc[$field] === '') {
                return null;
            }
        }
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $endpoint) {
            if (!str_starts_with(strtolower((string) $doc[$endpoint]), 'https://')) {
                return null;
            }
        }
        return $doc;
    }

    /**
     * Generate a PKCE verifier + S256 challenge (RFC 7636).
     *
     * @return array{verifier: string, challenge: string}
     */
    public function generatePkce(): array
    {
        // 32 random bytes → 43-char base64url verifier (within the 43–128 range).
        $verifier = self::base64url(random_bytes(32));
        $challenge = self::base64url(hash('sha256', $verifier, true));

        return ['verifier' => $verifier, 'challenge' => $challenge];
    }

    /** A URL-safe random token for `state` / `nonce` (128-bit). */
    public function randomToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Build the /authorize redirect URL (Authorization Code flow + S256 PKCE).
     *
     * @param array<string, mixed> $discovery
     */
    public function buildAuthorizationUrl(
        ExternalAuthProvider $provider,
        array $discovery,
        string $clientId,
        string $redirectUri,
        string $state,
        string $nonce,
        string $codeChallenge,
    ): string {
        $params = array_merge(
            [
                'client_id'             => $clientId,
                'redirect_uri'          => $redirectUri,
                'response_type'         => 'code',
                'scope'                 => implode(' ', $provider->defaultScopes()),
                'state'                 => $state,
                'nonce'                 => $nonce,
                'code_challenge'        => $codeChallenge,
                'code_challenge_method' => 'S256',
            ],
            $provider->authorizationParameters(),
        );

        $endpoint = (string) $discovery['authorization_endpoint'];
        $separator = str_contains($endpoint, '?') ? '&' : '?';

        return $endpoint . $separator . http_build_query($params);
    }

    /**
     * Exchange an authorization code for tokens at the token endpoint.
     *
     * @param array<string, mixed> $discovery
     * @return array<string, mixed>|null The token response (id_token, access_token,
     *   refresh_token?), or null on failure.
     */
    public function exchangeCode(
        array $discovery,
        string $clientId,
        ?string $clientSecret,
        string $code,
        string $redirectUri,
        string $codeVerifier,
    ): ?array {
        $params = [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $redirectUri,
            'client_id'     => $clientId,
            'code_verifier' => $codeVerifier,
        ];
        if ($clientSecret !== null && $clientSecret !== '') {
            $params['client_secret'] = $clientSecret;
        }

        return $this->http->postForm((string) $discovery['token_endpoint'], $params);
    }

    /**
     * Verify an ID token and map it to a canonical identity.
     *
     * Asserts the discovery issuer matches the ADMIN-CONFIGURED expected issuer
     * (so a substituted discovery doc cannot redirect trust), fetches the JWKS by
     * the token's `kid`, verifies the signature + iss/aud/nonce/exp via
     * {@see JwtParser::verifyExternalIdToken()}, then normalizes via the provider.
     *
     * @param array<string, mixed> $discovery
     * @return ExternalIdentity|null Null on any validation failure.
     */
    public function verifyIdToken(
        string $idToken,
        array $discovery,
        string $clientId,
        string $expectedIssuer,
        ?string $nonce,
        ExternalAuthProvider $provider,
    ): ?ExternalIdentity {
        // The discovery doc's issuer must equal the configured issuer we trust.
        if ((string) $discovery['issuer'] !== $expectedIssuer) {
            return null;
        }

        $kid = self::kidFromToken($idToken);
        $jwks = $this->jwks->getForKid((string) $discovery['jwks_uri'], $kid);

        $claims = $this->jwtParser->verifyExternalIdToken(
            $idToken,
            $jwks,
            $expectedIssuer,
            $clientId,
            $nonce,
        );
        if ($claims === null) {
            return null;
        }

        $identity = $provider->normalizeClaims($claims);
        // A usable federated identity must carry both halves of the key.
        if ($identity->issuer === '' || $identity->subject === '') {
            return null;
        }
        return $identity;
    }

    private static function kidFromToken(string $jwt): ?string
    {
        $segments = explode('.', $jwt);
        if (count($segments) < 2) {
            return null;
        }
        $headerJson = self::base64urlDecode($segments[0]);
        if ($headerJson === null) {
            return null;
        }
        $header = json_decode($headerJson, true);
        if (is_array($header) && isset($header['kid']) && is_string($header['kid'])) {
            return $header['kid'];
        }
        return null;
    }

    private static function base64url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        return $decoded === false ? null : $decoded;
    }
}
