<?php

declare(strict_types=1);

namespace Whity\Sdk\Auth;

/**
 * Contract for a federated sign-in provider (SDK v1.9).
 *
 * The extension seam behind "Sign in with Google/Microsoft/…". The host owns the
 * OIDC/OAuth2 relying-party MECHANICS that are identical across compliant
 * providers — discovery, the Authorization Code + PKCE redirect, the token
 * exchange, and ID-token signature validation against the provider's JWKS. A
 * provider implementation contributes only what varies between providers:
 *
 *   - identity/branding for the sign-in button ({@see key()}, {@see displayName()});
 *   - the OAuth SCOPES to request ({@see defaultScopes()});
 *   - any extra AUTHORIZE-endpoint parameters ({@see authorizationParameters()},
 *     e.g. Google's `access_type=offline` + `prompt=consent` to obtain a refresh
 *     token — needed when the same account will later authorize Drive access); and
 *   - how to MAP the provider's validated claim set to the canonical
 *     {@see ExternalIdentity} ({@see normalizeClaims()}).
 *
 * A provider does NO network I/O and holds NO secrets: the host performs the
 * exchange + validation and passes the already-verified claims to
 * {@see normalizeClaims()}. Per-provider configuration (client id/secret, issuer,
 * redirect URI) lives in the host's per-tenant identity-provider registry, not
 * in the implementation. A single generic OIDC implementation satisfies most
 * providers; a bespoke one is only needed for a non-standard provider.
 */
interface ExternalAuthProvider
{
    /**
     * A stable, lowercase key identifying this provider (e.g. `google`,
     * `microsoft`, `oidc`). Used as the routing/registry key and persisted with
     * linked identities; MUST NOT change once in use.
     */
    public function key(): string;

    /**
     * A human-readable name for the sign-in button (e.g. "Google").
     */
    public function displayName(): string;

    /**
     * The OAuth scopes to request at the authorization endpoint. For OIDC this
     * includes at least `openid`; typically also `email` and `profile`.
     *
     * @return list<string>
     */
    public function defaultScopes(): array;

    /**
     * Extra query parameters to append to the authorization-endpoint URL, as a
     * name => value map. Empty for a vanilla flow. Providers that must opt in to
     * a refresh token (e.g. Google) declare it here.
     *
     * @return array<string, string>
     */
    public function authorizationParameters(): array;

    /**
     * Map a set of validated OIDC/OAuth2 claims into the canonical
     * {@see ExternalIdentity}.
     *
     * The host guarantees the claims have already been verified (ID-token
     * signature checked against the provider JWKS; issuer/audience/nonce/expiry
     * validated) before calling this. Implementations translate provider-specific
     * claim names and MUST NOT perform any trust decision themselves.
     *
     * @param array<string, mixed> $claims The verified claim set (includes at
     *                                      least `iss` and `sub`).
     */
    public function normalizeClaims(array $claims): ExternalIdentity;
}
