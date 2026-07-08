<?php

declare(strict_types=1);

namespace Whity\Auth\Oidc;

use Whity\Sdk\Auth\ExternalAuthProvider;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * Generic OIDC provider (WC-ae16). One implementation serves any OIDC-compliant
 * IdP — Google, Microsoft/Entra, Okta, generic OIDC — because the differences
 * that matter are configuration (endpoints via discovery, client id/secret,
 * scopes) not code. Constructed per provider config with its key + scopes.
 *
 * The only provider-specific behaviour is {@see authorizationParameters()}:
 * Google requires `access_type=offline` + `prompt=consent` to return a REFRESH
 * token (needed later so the same Google grant can authorize Drive storage).
 */
final class StandardOidcProvider implements ExternalAuthProvider
{
    /**
     * @param string       $key         Stable provider key (e.g. 'google').
     * @param string       $displayName Sign-in button label.
     * @param list<string> $scopes      OAuth scopes (must include 'openid').
     */
    public function __construct(
        private readonly string $key,
        private readonly string $displayName,
        private readonly array $scopes = ['openid', 'email', 'profile'],
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function defaultScopes(): array
    {
        return $this->scopes;
    }

    public function authorizationParameters(): array
    {
        // Google only issues a refresh token with offline access + a forced
        // consent prompt; harmless for other providers but only emitted for Google.
        if ($this->key === 'google') {
            return ['access_type' => 'offline', 'prompt' => 'consent'];
        }
        return [];
    }

    public function normalizeClaims(array $claims): ExternalIdentity
    {
        return new ExternalIdentity(
            issuer: (string) ($claims['iss'] ?? ''),
            subject: (string) ($claims['sub'] ?? ''),
            email: isset($claims['email']) && is_scalar($claims['email']) ? (string) $claims['email'] : null,
            emailVerified: self::claimIsTrue($claims['email_verified'] ?? false),
            displayName: isset($claims['name']) && is_scalar($claims['name']) ? (string) $claims['name'] : null,
            claims: $claims,
        );
    }

    /**
     * OIDC `email_verified` may arrive as a real bool (JSON) or a string ("true")
     * depending on the provider. Coerce without the `(bool) "false" === true` trap.
     */
    private static function claimIsTrue(mixed $value): bool
    {
        return $value === true || $value === 1 || $value === '1' || $value === 'true';
    }
}
