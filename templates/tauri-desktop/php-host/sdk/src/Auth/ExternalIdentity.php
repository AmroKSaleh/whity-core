<?php

declare(strict_types=1);

namespace Whity\Sdk\Auth;

/**
 * A normalized external identity (SDK v1.9).
 *
 * The canonical, provider-agnostic shape the host receives after a federated
 * sign-in (OIDC/OAuth2). An {@see ExternalAuthProvider} maps its validated
 * claim set into this object so the host's linking/provisioning logic never has
 * to know provider-specific claim names.
 *
 * The `(issuer, subject)` pair is the STABLE, globally-unique key for a federated
 * account — `subject` is the provider's opaque, immutable user id (never the
 * email, which can change or be reassigned). The host links exactly one profile
 * to each `(issuer, subject)`.
 *
 * Immutable value object (readonly). Carries no secrets: it holds identity
 * claims only, never tokens.
 */
final class ExternalIdentity
{
    /**
     * @param string               $issuer        The provider's issuer identifier (OIDC `iss`).
     * @param string               $subject       The provider's stable, opaque user id (OIDC `sub`).
     * @param string|null          $email         The email asserted by the provider, if any.
     * @param bool                 $emailVerified Whether the provider asserts the email is verified.
     * @param string|null          $displayName   A human-readable name, if the provider supplies one.
     * @param array<string, mixed> $claims        The remaining validated claims (for auditing/mapping);
     *                                             MUST NOT contain tokens or secrets.
     */
    public function __construct(
        public readonly string $issuer,
        public readonly string $subject,
        public readonly ?string $email = null,
        public readonly bool $emailVerified = false,
        public readonly ?string $displayName = null,
        public readonly array $claims = [],
    ) {
    }

    /**
     * True when this identity carries a provider-verified email — the
     * precondition the host requires before it will auto-link to, or provision
     * from, an existing local account (an unverified email must never be trusted
     * for account takeover).
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->emailVerified && $this->email !== null && $this->email !== '';
    }

    /**
     * The normalized (lowercased, trimmed) email, or null when absent.
     */
    public function normalizedEmail(): ?string
    {
        if ($this->email === null) {
            return null;
        }
        $normalized = strtolower(trim($this->email));

        return $normalized === '' ? null : $normalized;
    }
}
