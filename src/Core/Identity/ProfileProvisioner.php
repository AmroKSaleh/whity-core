<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Find-or-create the global profile that owns an email address.
 *
 * `profile_emails.email` is globally UNIQUE (ADR 0005 §2), so an email that
 * already has a profile REUSES it — the same person added to a second tenant is
 * one identity with two memberships, never two identities. Creating a duplicate
 * here would not merely be untidy: it would split that person's credentials and
 * token epoch across two rows, so a password change or a forced logout would
 * apply to only one of them.
 *
 * This lives outside the API handlers because BOTH the user-creation path and
 * tenant provisioning need it, and an identity written two slightly different
 * ways is an identity that eventually diverges.
 *
 * Every method here MUST run inside the caller's transaction. A profile without
 * its primary email, or an email without its profile, is a broken identity that
 * no later request can repair — so the caller owns the transaction boundary,
 * because only the caller knows what else has to land with it.
 */
final class ProfileProvisioner
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The profile id owning `$email`, creating the profile if there is none.
     *
     * The password hash is used ONLY when creating; an existing profile keeps
     * the credential it already had. Silently re-hashing someone's password
     * because an administrator typed one into a provisioning form would be a
     * credential reset disguised as a membership grant.
     *
     * @param string $email        The address, already validated by the caller.
     * @param string $passwordHash A hash for the profile, when one is created.
     * @return int The profile id, existing or new.
     */
    public function findOrCreate(string $email, string $passwordHash): int
    {
        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2); UNIQUE(email)
        $existing = $this->db->prepare('SELECT profile_id FROM profile_emails WHERE email = ? LIMIT 1');
        $existing->execute([$email]);
        $profileId = $existing->fetchColumn();

        if ($profileId !== false) {
            return (int) $profileId;
        }

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $profile = $this->db->prepare(
            'INSERT INTO profiles
                 (display_name, password_hash, two_factor_enabled,
                  two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, NOW(), NOW())'
        );
        $profile->execute([self::localPart($email), $passwordHash]);
        $newId = (int) $this->db->lastInsertId();

        // @tenant-guard-ignore: profile_emails is a sanctioned GLOBAL identity table (ADR 0005 §2)
        $this->db->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$newId, $email]);

        return $newId;
    }

    /**
     * The local part of an email, used as a new profile's display name.
     *
     * `strrpos` rather than `strpos`: the local part may itself contain a quoted
     * '@', and the domain may not, so the LAST one is the separator.
     */
    public static function localPart(string $email): string
    {
        $at = strrpos($email, '@');

        return $at !== false ? substr($email, 0, $at) : $email;
    }
}
