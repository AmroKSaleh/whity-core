<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * `profiles.auth_method` — which authority holds an account's credentials
 * (#917, migration 104) — and the ONLY writer of `profiles.password_hash`.
 *
 * WHAT THIS REPLACES
 * ------------------
 * Before migration 104 the platform did not know whether an account was backed
 * by an identity provider; it guessed, from `password_hash = ''`, in the one
 * place that happened to care ({@see \Whity\Api\MeIdentitiesApiHandler}) and
 * nowhere else. So `PATCH /api/users/{id}` with a `password` against an
 * SSO-provisioned profile returned 200 and minted a local credential that
 * survives the IdP deprovisioning the account, ignores the IdP's MFA, and shows
 * up in no SSO audit trail. Every other password-write path had the same hole.
 *
 * Now the fact is held, this class owns it, and no other file in `src/` writes
 * `profiles.password_hash` — {@see \Tests\Unit\Core\Identity\ProfileCredentialWriteSitesTest}
 * fails the build if one appears. That is deliberately a structural gate rather
 * than a note in a docblock: this defect is the second of its kind (#895 was
 * the first), and both times the missing piece was that the rule lived only in
 * the heads of the people who already knew it.
 *
 * THE VOCABULARY
 * --------------
 * `auth_method` summarises two independent booleans as one of three values:
 *
 *   'local'  a local credential; no external identity linked
 *   'idp'    an external identity linked; NO local credential
 *   'both'   an external identity linked AND a local credential
 *
 * {@see compose()} is the whole mapping, including why the fourth combination
 * folds into 'local'.
 *
 * WHAT REFUSES AND WHAT DOES NOT
 * ------------------------------
 * Only 'idp' refuses a local-password write. A 'both' account already has a
 * local credential, and changing an existing credential is not what went wrong:
 * the defect was the silent CREATION of a second way in. Coexistence stays
 * possible — deliberately, since the reporter asked the platform to know which
 * case it is in, not to forbid one of them — but it now takes an explicit
 * override at the entry point, and the transition to 'both' is recorded.
 *
 * KEEPING THE FACT TRUE
 * ---------------------
 * The value is a denormalisation of two things nobody writes atomically, so
 * each half is maintained by the writer that owns it, and each reads the held
 * fact for the half it does not:
 *
 *   - this class, on setting a local credential: 'idp' → 'both', otherwise
 *     unchanged (a profile that already has one stays where it is);
 *   - {@see ExternalIdentityRepository::link()}, on linking: 'local' → 'both',
 *     anything else → 'idp' — it reads `auth_method`, never `password_hash`;
 *   - {@see ExternalIdentityRepository::unlink()}, on removing the last link:
 *     back to 'local' or 'idp' by whether a local credential is held.
 *
 * Nothing at runtime infers from `password_hash` any more. Migration 104's
 * backfill is the single exception, which is the one place where converting the
 * old physical state into the new held fact is what is being asked for.
 */
final class AuthMethod
{
    /** A local credential; no external identity linked. */
    public const LOCAL = 'local';

    /** An external identity linked and NO local credential — the refusing state. */
    public const IDP = 'idp';

    /** An external identity linked AND a local credential. */
    public const BOTH = 'both';

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * The three permitted values, in the order migration 104's CHECK lists them.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return [self::LOCAL, self::IDP, self::BOTH];
    }

    /**
     * The value describing a profile that holds (or does not hold) each of the
     * two credentials.
     *
     * The fourth combination — no local credential and no external identity —
     * is an account that can sign in by no means at all, and it folds into
     * 'local'. That reads as "no external authority governs this account",
     * which is what it is: whatever stranded it, there is no IdP left to
     * contradict an administrator who gives it a password. Modelling the
     * stranding itself is not this column's job; it is a pre-existing condition
     * that `password_hash = ''` already describes.
     */
    public static function compose(bool $hasLocalCredential, bool $hasExternalIdentity): string
    {
        if (!$hasExternalIdentity) {
            return self::LOCAL;
        }

        return $hasLocalCredential ? self::BOTH : self::IDP;
    }

    /** Whether a value is one this column may hold. */
    public static function isValid(string $authMethod): bool
    {
        return in_array($authMethod, self::all(), true);
    }

    /** Whether an identity provider is involved in this account at all. */
    public static function involvesIdp(string $authMethod): bool
    {
        return $authMethod === self::IDP || $authMethod === self::BOTH;
    }

    /** Whether a local password can currently verify for this account. */
    public static function holdsLocalCredential(string $authMethod): bool
    {
        return $authMethod === self::LOCAL || $authMethod === self::BOTH;
    }

    /**
     * The held fact for a profile, or null when there is no such profile.
     *
     * An unrecognised stored value (only reachable on SQLite, which cannot take
     * migration 104's CHECK constraint) is reported as 'idp' — the refusing
     * state — so a corrupted column fails closed rather than open.
     */
    public function of(int $profileId): ?string
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare('SELECT auth_method FROM profiles WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $profileId]);
        $value = $stmt->fetchColumn();

        if ($value === false) {
            return null;
        }

        $authMethod = (string) $value;

        return self::isValid($authMethod) ? $authMethod : self::IDP;
    }

    /**
     * Whether giving this profile a local password would silently create a
     * SECOND way into an account an identity provider currently governs alone.
     *
     * The question every entry point asks before doing any work, so the caller
     * can answer with a status code and a sentence instead of an exception. A
     * profile that does not exist does not refuse — the caller's own
     * not-found handling is the right answer there, not a policy message about
     * an account that is not real.
     */
    public function refusesLocalPassword(int $profileId): bool
    {
        return $this->of($profileId) === self::IDP;
    }

    /**
     * Write a local credential, and keep `auth_method` true while doing it.
     *
     * The refusal is in the `WHERE` clause rather than in a preceding `SELECT`,
     * so there is no window between deciding and writing: a profile that is
     * 'idp' at the moment of the UPDATE matches no row and nothing is written.
     *
     * The caller owns the transaction. `token_epoch` is bumped WITH the hash and
     * never separately — a credential change must invalidate every live session,
     * which is the entire point when an administrator is resetting an account
     * they believe is compromised.
     *
     * @param int    $profileId    The profile receiving the credential.
     * @param string $passwordHash An already-hashed password (bcrypt).
     * @param bool   $override     Set only where a caller has explicitly asked to
     *                             give an IdP-backed account a local password;
     *                             moves it to 'both'.
     *
     * @throws LocalPasswordRefusedException When the profile is IdP-only and no
     *                                       override was given, or does not exist.
     */
    public function setPasswordHash(int $profileId, string $passwordHash, bool $override = false): void
    {
        $refusal = $override ? '' : ' AND auth_method <> :refused';

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $stmt = $this->db->prepare(
            'UPDATE profiles
                SET password_hash = :hash,
                    auth_method = CASE WHEN auth_method = :idp THEN :both ELSE auth_method END,
                    token_epoch = token_epoch + 1,
                    updated_at = NOW()
              WHERE id = :id' . $refusal
        );

        $params = [
            ':hash' => $passwordHash,
            ':idp'  => self::IDP,
            ':both' => self::BOTH,
            ':id'   => $profileId,
        ];
        if (!$override) {
            $params[':refused'] = self::IDP;
        }

        $stmt->execute($params);

        if ($stmt->rowCount() === 1) {
            return;
        }

        // Nothing changed: either the profile is not there, or the refusal
        // predicate excluded it. Only now is a read worth paying for, and only
        // to say which of the two it was.
        throw $this->of($profileId) === null
            ? LocalPasswordRefusedException::forMissingProfile($profileId)
            : LocalPasswordRefusedException::forIdpBackedProfile($profileId);
    }

    /**
     * Record that an external identity now exists for this profile.
     *
     * Called by {@see ExternalIdentityRepository::link()} inside the caller's
     * transaction. Reads the held fact, never `password_hash`: a profile that
     * already holds a local credential becomes (or stays) 'both', one that does
     * not becomes 'idp'.
     */
    public function onExternalIdentityLinked(int $profileId): void
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $this->db->prepare(
            'UPDATE profiles
                SET auth_method = CASE
                        WHEN auth_method IN (:local, :bothIn) THEN :both
                        ELSE :idp
                    END,
                    updated_at = NOW()
              WHERE id = :id'
        )->execute([
            ':local'  => self::LOCAL,
            ':bothIn' => self::BOTH,
            ':both'   => self::BOTH,
            ':idp'    => self::IDP,
            ':id'     => $profileId,
        ]);
    }

    /**
     * Recompute the held fact after an external identity was removed.
     *
     * Only the LAST link changes anything: while others remain the account is
     * still IdP-backed and already says so. When none remain the answer is
     * 'local' whether or not a local credential exists, because
     * {@see compose()} maps both of those cases there — the column records
     * which authority governs the account, and with the last link gone the
     * answer is "no external one".
     *
     * Called by {@see ExternalIdentityRepository::unlink()} after the DELETE, in
     * the caller's transaction, so the `NOT EXISTS` sees the row already gone.
     */
    public function onExternalIdentityUnlinked(int $profileId): void
    {
        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $this->db->prepare(
            'UPDATE profiles
                SET auth_method = :local, updated_at = NOW()
              WHERE id = :id
                AND auth_method <> :localCmp
                AND NOT EXISTS (
                    SELECT 1 FROM external_identities ei WHERE ei.profile_id = profiles.id
                )'
        )->execute([
            ':local'    => self::LOCAL,
            ':localCmp' => self::LOCAL,
            ':id'       => $profileId,
        ]);
    }
}
