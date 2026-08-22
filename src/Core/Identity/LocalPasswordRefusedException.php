<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use RuntimeException;

/**
 * Raised when something tries to mint a local password for a profile whose
 * credentials belong to an identity provider (#916).
 *
 * This is thrown by {@see AuthMethod::setPasswordHash()} — the single writer of
 * `profiles.password_hash` — so it is the LAST line, not the first. Every entry
 * point that can be driven by a caller checks
 * {@see AuthMethod::refusesLocalPassword()} first and answers with a proper
 * status code and an explanation; reaching the exception means a path was added
 * that forgot to, and failing loudly there is the point. A guard that only
 * exists at the entry points is a guard the next entry point does not have.
 */
class LocalPasswordRefusedException extends RuntimeException
{
    /**
     * The profile whose local-password write was refused.
     *
     * @param int $profileId The profiles.id that is IdP-backed.
     */
    public static function forIdpBackedProfile(int $profileId): self
    {
        return new self(
            "Profile {$profileId} authenticates through an identity provider (auth_method='"
            . AuthMethod::IDP . "'), so it holds no local password and one may not be created "
            . 'implicitly. Pass the explicit override if a local credential is genuinely intended '
            . '— see ' . AuthMethod::class . '::setPasswordHash().'
        );
    }

    /**
     * The write named a profile that does not exist.
     *
     * Distinguished from the refusal above because the two mean opposite things
     * to a caller: one is a policy answer about a real account, the other is a
     * bad identifier.
     *
     * @param int $profileId The profiles.id that could not be found.
     */
    public static function forMissingProfile(int $profileId): self
    {
        return new self("Cannot set a local password: no profile with id {$profileId}");
    }
}
