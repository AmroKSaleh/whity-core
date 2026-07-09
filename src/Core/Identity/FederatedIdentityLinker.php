<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * Resolves a verified external (SSO/OIDC) identity to a local profile at
 * first login — linking to an existing account or provisioning a new one
 * (WC-f3b17bd2). This is the ANTI-TAKEOVER core of federated onboarding.
 *
 * Policy (given a verified {@see ExternalIdentity} for issuer/subject/email E):
 *   1. Already linked (issuer, subject) → that profile ("existing").
 *   2. Otherwise, an UNVERIFIED IdP email is NEVER used to link or provision — an
 *      attacker could create a provider account claiming a victim's address
 *      without proving control ("refused_unverified").
 *   3. With a verified IdP email E:
 *      a. E matches an existing, VERIFIED profile_email → link to that profile
 *         ("linked"). Verified↔verified is the only safe auto-link.
 *      b. E matches an existing but UNVERIFIED profile_email → REFUSE
 *         ("refused_conflict"): a local account claimed E without verifying it;
 *         auto-linking would let the IdP account seize a half-registered account.
 *      c. E matches no profile_email → PROVISION a new, passwordless profile with
 *         E as a verified primary email, and link ("provisioned").
 *
 * The `(issuer, subject)` and `email` UNIQUE constraints make concurrent
 * first-logins safe: a losing racer's insert violates the constraint and is
 * resolved to the winner's now-existing link.
 */
final class FederatedIdentityLinker
{
    public function __construct(
        private readonly PDO $db,
        private readonly ExternalIdentityRepository $identities,
        private readonly ProfileEmailRepository $emails,
    ) {
    }

    /**
     * @return array{status: 'existing'|'linked'|'provisioned'|'refused_unverified'|'refused_conflict', profile_id?: int}
     */
    public function resolveForLogin(ExternalIdentity $identity, string $providerKey): array
    {
        // 1. Already linked → log in that profile.
        $existing = $this->identities->findByIssuerSubject($identity->issuer, $identity->subject);
        if ($existing !== null) {
            return ['status' => 'existing', 'profile_id' => (int) $existing['profile_id']];
        }

        // 2. No email-based decision without a provider-VERIFIED email.
        if (!$identity->hasVerifiedEmail()) {
            return ['status' => 'refused_unverified'];
        }

        /** @var string $email */
        $email = $identity->normalizedEmail();
        $profileEmail = $this->emails->findByEmail($email);

        // 3a/3b. An existing local email for E.
        if ($profileEmail !== null) {
            if ($profileEmail['verified'] !== true) {
                // Unverified local claim on E — never auto-link (takeover risk).
                return ['status' => 'refused_conflict'];
            }
            return $this->linkOrResolve($identity, $providerKey, $email, (int) $profileEmail['profile_id']);
        }

        // 3c. Brand-new identity → provision a passwordless profile.
        return $this->provision($identity, $providerKey, $email);
    }

    /**
     * Link an identity to an existing profile; if a concurrent racer linked it
     * first (UNIQUE(issuer, subject) violation), resolve to that link instead.
     *
     * @return array{status: 'existing'|'linked', profile_id: int}
     */
    private function linkOrResolve(ExternalIdentity $identity, string $providerKey, string $email, int $profileId): array
    {
        try {
            $this->identities->link($profileId, $providerKey, $identity->issuer, $identity->subject, $email);
            return ['status' => 'linked', 'profile_id' => $profileId];
        } catch (\PDOException) {
            $row = $this->identities->findByIssuerSubject($identity->issuer, $identity->subject);
            if ($row !== null) {
                return ['status' => 'existing', 'profile_id' => (int) $row['profile_id']];
            }
            throw new \RuntimeException('federated link failed');
        }
    }

    /**
     * Provision a new passwordless profile (SSO-only: empty password_hash so no
     * password can ever verify), a verified primary email, and the identity link
     * — atomically. On a race (another provision won), resolve to the existing link.
     *
     * @return array{status: 'existing'|'provisioned', profile_id: int}
     */
    private function provision(ExternalIdentity $identity, string $providerKey, string $email): array
    {
        $displayName = $identity->displayName ?? '';
        if ($displayName === '') {
            $at = strpos($email, '@');
            $displayName = $at !== false ? substr($email, 0, $at) : $email;
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            // Passwordless profile: empty password_hash means password_verify() can
            // never succeed, so this account is reachable ONLY via its linked IdP.
            $profileId = $this->insertReturningId(
                "INSERT INTO profiles
                    (display_name, password_hash, two_factor_enabled, two_factor_secret,
                     two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (:dn, '', false, NULL, 0, 0, NOW(), NOW())",
                [':dn' => $displayName]
            );
            $this->emails->insert($profileId, $email, true, true);
            $this->identities->link($profileId, $providerKey, $identity->issuer, $identity->subject, $email);

            if ($ownTx) {
                $this->db->commit();
            }
            return ['status' => 'provisioned', 'profile_id' => $profileId];
        } catch (\PDOException $e) {
            // Flow analysis over-narrows $ownTx to always-true here; the guard is
            // still correct for the nested-transaction case (only roll back a tx
            // WE started).
            // @phpstan-ignore booleanAnd.leftAlwaysTrue
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Lost a race on UNIQUE(issuer,subject) or UNIQUE(email) → resolve to
            // whatever now exists rather than erroring the login.
            $row = $this->identities->findByIssuerSubject($identity->issuer, $identity->subject);
            if ($row !== null) {
                return ['status' => 'existing', 'profile_id' => (int) $row['profile_id']];
            }
            throw new \RuntimeException('federated provision failed', 0, $e);
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    private function insertReturningId(string $sql, array $params): int
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $this->db->prepare($sql . ' RETURNING id');
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        }
        $this->db->prepare($sql)->execute($params);
        return (int) $this->db->lastInsertId();
    }
}
