<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Issues and consumes self-service "forgot password" tokens
 * (WC-password-reset-2fa-recovery).
 *
 * Mirrors {@see EmailVerificationService} almost exactly — `bin2hex(random_bytes(32))`
 * (256-bit token), only `sha256()` hash persisted (the raw token is returned
 * once from {@see self::issue()} and never stored), single-use via a
 * `consumed_at` marker, and issuing a new token supersedes/deletes any prior
 * outstanding one for the same profile. Two deliberate deviations:
 *
 *  - a SHORTER default TTL (1 hour vs email-verification's 24 hours) — a
 *    password-reset link is more sensitive (it grants a credential change) and
 *    is normally used within minutes of being requested;
 *  - an APPROVAL BRANCH: when the operator requires admin approval
 *    (`auth.password_reset_approval_required`), {@see self::confirm()} does
 *    NOT touch `profiles.password_hash` at all — it STAGES the new bcrypt hash
 *    on the `password_resets` row (status 'awaiting_approval') and an admin
 *    must {@see self::approveForTenant()} it before it takes effect. The
 *    staged hash is populated ONLY here, after the requester has proven token
 *    ownership — never before, so an attacker who merely knows an email
 *    address can never get a password staged for approval.
 *
 * `profiles.token_epoch` is bumped on final application (both the immediate
 * and the admin-approved path) — mirroring {@see \Whity\Auth\AuthHandler::handleUpdateMe()}'s
 * `token_epoch = token_epoch + 1` — since a password change is a credential
 * change and must invalidate every existing session, unlike email
 * verification. This service NEVER touches any `two_factor_*` column: a
 * self-service password reset must not accidentally strip 2FA from an account
 * that still has an authenticator enrolled (see
 * {@see TwoFactorRecoveryService} for the separate, admin-approved action that
 * clears 2FA).
 *
 * `password_resets` is a sanctioned GLOBAL table (no tenant_id) — see
 * {@see \Whity\Core\Tenant\SanctionedGlobalTables}; the admin approval QUEUE is
 * tenant-scoped at query time via a JOIN to `memberships`, with one deliberate
 * exception: the PLATFORM tenant ({@see self::SYSTEM_TENANT_ID}) drops that
 * JOIN and can list/approve/reject across every tenant (WC-797 §4d). Without it
 * a tenant whose only approver is the person whose own reset is parked has no
 * exit that is not direct database access.
 */
final class PasswordResetService
{
    /** Default token lifetime: 1 hour (shorter than email-verification's 24h — more sensitive). */
    public const DEFAULT_TTL_SECONDS = 3600;

    /** Raw-token entropy in bytes (256-bit → 64 hex chars). */
    private const TOKEN_BYTES = 32;

    /**
     * The platform (system) tenant. It owns no per-tenant resource but may act
     * across every tenant — the same authority it already carries everywhere
     * else in the system, applied here to the approval queue (WC-797 §4d).
     */
    public const SYSTEM_TENANT_ID = 0;

    /** Row states. */
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPLIED = 'applied';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(private readonly PDO $db) {}

    /**
     * Mint a password-reset token for a profile and persist its hash.
     *
     * Invalidates any prior outstanding (unconsumed) token for the same
     * profile so a superseded link stops working. Returns the RAW token —
     * store nothing of it beyond the returned value; only its hash is
     * persisted.
     *
     * Refuses outright for an IdP-backed profile (#917). A reset link is a
     * local credential in transit: mailing one to an account an identity
     * provider governs alone is how the account quietly acquires a second way
     * in that outlives the IdP. The refusal is here, at issuance, rather than at
     * {@see confirm()} — a token that can never be redeemed is still a live
     * credential-recovery secret sitting in a mailbox, and letting the person
     * choose a password before telling them it will not work is a worse answer
     * than not sending the mail.
     *
     * @param int $profileId The profiles.id the reset is for.
     * @param int $ttlSeconds Lifetime; defaults to {@see DEFAULT_TTL_SECONDS}.
     * @return string The raw token to embed in the reset link.
     *
     * @throws LocalPasswordRefusedException When the profile is IdP-backed.
     *         Callers decide how that surfaces: the public "forgot password"
     *         endpoint must answer exactly as it does for an unknown address,
     *         an administrator can be told plainly.
     */
    public function issue(int $profileId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        if ((new AuthMethod($this->db))->refusesLocalPassword($profileId)) {
            throw LocalPasswordRefusedException::forIdpBackedProfile($profileId);
        }

        $rawToken  = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            // Supersede any outstanding (unconsumed) token for this profile.
            $this->db->prepare(
                'DELETE FROM password_resets WHERE profile_id = :pid AND consumed_at IS NULL'
            )->execute([':pid' => $profileId]);

            $this->db->prepare(
                "INSERT INTO password_resets (profile_id, token_hash, status, expires_at, created_at, updated_at)
                 VALUES (:pid, :hash, '" . self::STATUS_PENDING . "', :expires_at, NOW(), NOW())"
            )->execute([
                ':pid'        => $profileId,
                ':hash'       => $tokenHash,
                ':expires_at' => $expiresAt,
            ]);

            if ($ownTx) {
                $this->db->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }

        return $rawToken;
    }

    /**
     * Consume a raw reset token, applying (or staging for approval) the new
     * password.
     *
     * Matches an unconsumed, unexpired row by token hash. The token is burned
     * (consumed_at set) EITHER WAY — a reset request is single-use regardless
     * of whether it is applied immediately or staged for approval, so a
     * replayed token can never queue a second request.
     *
     *  - `$requireApproval === false`: applies the new password immediately
     *    (profiles.password_hash + token_epoch bump), row -> 'applied'.
     *  - `$requireApproval === true`: stages the bcrypt hash of the new
     *    password on this row (row -> 'awaiting_approval'); `profiles` is left
     *    untouched until an admin approves.
     *
     * Returns null (without side effects) for an unknown, expired, or
     * already-consumed token, so the caller can respond generically.
     *
     * @return array{profile_id: int, request_id: int, applied: bool}|null
     */
    public function confirm(string $rawToken, string $newPassword, bool $requireApproval): ?array
    {
        if ($rawToken === '') {
            return null;
        }

        $tokenHash = hash('sha256', $rawToken);
        $now       = gmdate('Y-m-d H:i:s');

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $stmt = $this->db->prepare(
                'SELECT id, profile_id
                 FROM password_resets
                 WHERE token_hash = :hash
                   AND consumed_at IS NULL
                   AND expires_at > :now
                 LIMIT 1'
            );
            $stmt->execute([':hash' => $tokenHash, ':now' => $now]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                if ($ownTx) {
                    $this->db->commit();
                }
                return null;
            }

            $requestId = (int) $row['id'];
            $profileId = (int) $row['profile_id'];

            // #917: no token should exist for an IdP-backed profile — issue()
            // refuses to mint one — but a profile can become IdP-backed after a
            // token was issued, and a token minted before migration 104 landed
            // knows nothing of any of this. Treat it exactly like an unknown
            // token: burn nothing, say nothing, change nothing. The caller
            // answers generically either way, so this leaks no more than an
            // expired link does.
            if ((new AuthMethod($this->db))->refusesLocalPassword($profileId)) {
                if ($ownTx) {
                    $this->db->commit();
                }
                return null;
            }

            $newHash = password_hash($newPassword, PASSWORD_BCRYPT);

            if ($requireApproval) {
                // Stage only — profiles is untouched until an admin approves.
                $this->db->prepare(
                    "UPDATE password_resets
                        SET status = '" . self::STATUS_AWAITING_APPROVAL . "',
                            staged_password_hash = :hash,
                            consumed_at = :now,
                            updated_at = NOW()
                      WHERE id = :id AND consumed_at IS NULL"
                )->execute([':hash' => $newHash, ':now' => $now, ':id' => $requestId]);
            } else {
                // Apply immediately: new password hash + token_epoch bump
                // (invalidates every existing session for this profile, exactly
                // like a self-service password change via PATCH /api/me).
                // Written through AuthMethod, the single writer of
                // profiles.password_hash (#917), which refuses an IdP-backed
                // profile a second time in the same statement that writes.
                (new AuthMethod($this->db))->setPasswordHash($profileId, $newHash);

                $this->db->prepare(
                    "UPDATE password_resets
                        SET status = '" . self::STATUS_APPLIED . "',
                            consumed_at = :now,
                            updated_at = NOW()
                      WHERE id = :id AND consumed_at IS NULL"
                )->execute([':now' => $now, ':id' => $requestId]);
            }

            if ($ownTx) {
                $this->db->commit();
            }

            return [
                'profile_id' => $profileId,
                'request_id' => $requestId,
                'applied'    => !$requireApproval,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Find the outstanding awaiting-approval request for a profile, if any.
     *
     * Exists so the public `forgot` endpoint can recognise a REPEAT of a
     * request that is already parked and answer it idempotently instead of
     * minting a second token nobody asked for (WC-797 §4c). Reads only — the
     * caller decides what to do with the answer.
     *
     * @return array{id:int, created_at:string}|null
     */
    public function findPendingApprovalForProfile(int $profileId): ?array
    {
        // @tenant-guard-ignore: password_resets is a sanctioned GLOBAL table (no tenant_id); scoped to a single profile
        $stmt = $this->db->prepare(
            "SELECT id, created_at
             FROM password_resets
             WHERE profile_id = :pid AND status = '" . self::STATUS_AWAITING_APPROVAL . "'
             ORDER BY created_at ASC
             LIMIT 1"
        );
        $stmt->execute([':pid' => $profileId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return ['id' => (int) $row['id'], 'created_at' => (string) ($row['created_at'] ?? '')];
    }

    /**
     * List pending (awaiting-approval) password-reset requests for profiles
     * that hold an ACTIVE membership in the given tenant.
     *
     * Tenant-scoped via a JOIN to `memberships` (the requester's OWN tenant, not
     * system-tenant-restricted — the account and its tenant admin already
     * exist). A profile with active memberships in multiple tenants surfaces
     * its request to each of those tenants' admins; whichever approves first
     * wins (the atomic, status-guarded UPDATE in {@see self::approveForTenant()}
     * makes a second approve/reject a safe no-op).
     *
     * The PLATFORM tenant ({@see self::SYSTEM_TENANT_ID}) is the one exception
     * and the reason WC-797 §4d exists: a tenant whose only approver is the
     * person whose own reset is parked can never resolve it, because the
     * membership JOIN below hides the request from everyone who could. The
     * platform tenant holds no membership in the tenants it acts for, so for it
     * the JOIN is dropped entirely and it sees every parked request. This
     * widens NOTHING for a tenant administrator — $tenantId comes from the
     * authenticated tenant context, so only a caller genuinely acting in
     * tenant 0 can reach the unscoped branch.
     *
     * @return list<array{id:int, profile_id:int, email:string, display_name:string, created_at:string}>
     */
    public function listPendingForTenant(int $tenantId): array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            // @tenant-guard-ignore: platform (system tenant 0) break-glass — cross-tenant by design; every other caller takes the membership-scoped branch below
            $stmt = $this->db->prepare(
                "SELECT pr.id, pr.profile_id, pr.created_at,
                        p.display_name,
                        pe.email AS email
                 FROM password_resets pr
                 JOIN profiles p ON p.id = pr.profile_id
                 LEFT JOIN profile_emails pe ON pe.profile_id = pr.profile_id AND pe.is_primary = TRUE
                 WHERE pr.status = '" . self::STATUS_AWAITING_APPROVAL . "'
                 ORDER BY pr.created_at ASC"
            );
            $stmt->execute();
        } else {
            // @tenant-guard-ignore: joins the global password_resets/profiles tables to memberships, scoped by the tenant_id predicate below
            $stmt = $this->db->prepare(
                "SELECT DISTINCT pr.id, pr.profile_id, pr.created_at,
                        p.display_name,
                        pe.email AS email
                 FROM password_resets pr
                 JOIN memberships m ON m.profile_id = pr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
                 JOIN profiles p ON p.id = pr.profile_id
                 LEFT JOIN profile_emails pe ON pe.profile_id = pr.profile_id AND pe.is_primary = TRUE
                 WHERE pr.status = '" . self::STATUS_AWAITING_APPROVAL . "'
                 ORDER BY pr.created_at ASC"
            );
            $stmt->execute([':tenant_id' => $tenantId]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'id'           => (int) $row['id'],
                'profile_id'   => (int) $row['profile_id'],
                'email'        => (string) ($row['email'] ?? ''),
                'display_name' => (string) ($row['display_name'] ?? ''),
                'created_at'   => (string) ($row['created_at'] ?? ''),
            ];
        }

        return $items;
    }

    /**
     * Admin-approve a staged password reset: apply the staged hash to
     * `profiles.password_hash`, bump `token_epoch`, and mark the row approved.
     *
     * Tenant-scoped: the target profile must hold an ACTIVE membership in
     * $tenantId — enforced by the JOIN below (never trust a bare request id).
     * The atomic `status = 'awaiting_approval'` guard makes a concurrent
     * double-approve (e.g. from two tenants sharing the same profile) a safe
     * no-op for the loser.
     *
     * The PLATFORM tenant ({@see self::SYSTEM_TENANT_ID}) approves across
     * tenants — see {@see self::listPendingForTenant()} for why that break-glass
     * exists and why it does not widen any tenant administrator's reach.
     *
     * @return array{profile_id:int, email:string}|null null = not found, not
     *   awaiting approval, or the target is not a member of $tenantId.
     */
    public function approveForTenant(int $requestId, int $tenantId): ?array
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                // @tenant-guard-ignore: platform (system tenant 0) break-glass — cross-tenant by design; every other caller takes the membership-scoped branch below
                $stmt = $this->db->prepare(
                    "SELECT pr.id, pr.profile_id, pr.staged_password_hash, pe.email
                     FROM password_resets pr
                     LEFT JOIN profile_emails pe ON pe.profile_id = pr.profile_id AND pe.is_primary = TRUE
                     WHERE pr.id = :id AND pr.status = '" . self::STATUS_AWAITING_APPROVAL . "'
                     LIMIT 1"
                );
                $stmt->execute([':id' => $requestId]);
            } else {
                // @tenant-guard-ignore: joins the global password_resets table to memberships, scoped by the tenant_id predicate below
                $stmt = $this->db->prepare(
                    "SELECT pr.id, pr.profile_id, pr.staged_password_hash, pe.email
                     FROM password_resets pr
                     JOIN memberships m ON m.profile_id = pr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
                     LEFT JOIN profile_emails pe ON pe.profile_id = pr.profile_id AND pe.is_primary = TRUE
                     WHERE pr.id = :id AND pr.status = '" . self::STATUS_AWAITING_APPROVAL . "'
                     LIMIT 1"
                );
                $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false || $row['staged_password_hash'] === null) {
                if ($ownTx) {
                    $this->db->commit();
                }
                return null;
            }

            $profileId = (int) $row['profile_id'];

            // #917: a staged hash can outlive the state it was staged under —
            // the profile may have been linked to an identity provider between
            // the request and this approval, and a queue entry from before
            // migration 104 predates the question entirely. Refuse rather than
            // apply. Reported as null, the same answer an already-approved or
            // out-of-tenant request gets: an administrator approving a reset
            // for an account that no longer takes local passwords has not found
            // an approvable request.
            if ((new AuthMethod($this->db))->refusesLocalPassword($profileId)) {
                if ($ownTx) {
                    $this->db->commit();
                }
                return null;
            }

            (new AuthMethod($this->db))->setPasswordHash(
                $profileId,
                (string) $row['staged_password_hash']
            );

            // The status guard makes this idempotent-safe under a concurrent
            // second approve for the same row (e.g. via a different tenant).
            $stmt2 = $this->db->prepare(
                "UPDATE password_resets
                    SET status = '" . self::STATUS_APPROVED . "',
                        staged_password_hash = NULL,
                        updated_at = NOW()
                  WHERE id = :id AND status = '" . self::STATUS_AWAITING_APPROVAL . "'"
            );
            $stmt2->execute([':id' => $requestId]);

            if ($stmt2->rowCount() === 0) {
                // Lost the race to a concurrent approval/rejection.
                if ($ownTx) {
                    $this->db->rollBack();
                }
                return null;
            }

            if ($ownTx) {
                $this->db->commit();
            }

            return [
                'profile_id' => $profileId,
                'email'      => (string) ($row['email'] ?? ''),
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Admin-reject a staged password reset. `profiles` is left untouched;
     * the staged hash is discarded.
     *
     * Tenant-scoped identically to {@see self::approveForTenant()}, including
     * the platform-tenant break-glass branch.
     *
     * @return array{profile_id:int}|null null = not found, not awaiting
     *   approval, or the target is not a member of $tenantId.
     */
    public function rejectForTenant(int $requestId, int $tenantId): ?array
    {
        $platform = $tenantId === self::SYSTEM_TENANT_ID;

        if ($platform) {
            // @tenant-guard-ignore: platform (system tenant 0) break-glass — cross-tenant by design; every other caller takes the membership-scoped branch below
            $lookup = $this->db->prepare(
                "SELECT profile_id
                 FROM password_resets
                 WHERE id = :id AND status = '" . self::STATUS_AWAITING_APPROVAL . "'
                 LIMIT 1"
            );
            $lookup->execute([':id' => $requestId]);
        } else {
            // @tenant-guard-ignore: joins the global password_resets table to memberships, scoped by the tenant_id predicate below
            $lookup = $this->db->prepare(
                "SELECT pr.profile_id
                 FROM password_resets pr
                 JOIN memberships m ON m.profile_id = pr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
                 WHERE pr.id = :id AND pr.status = '" . self::STATUS_AWAITING_APPROVAL . "'
                 LIMIT 1"
            );
            $lookup->execute([':id' => $requestId, ':tenant_id' => $tenantId]);
        }

        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        if ($platform) {
            // @tenant-guard-ignore: platform (system tenant 0) break-glass — cross-tenant by design; every other caller takes the membership-scoped branch below
            $stmt = $this->db->prepare(
                "UPDATE password_resets
                    SET status = '" . self::STATUS_REJECTED . "',
                        staged_password_hash = NULL,
                        updated_at = NOW()
                  WHERE id = :id
                    AND status = '" . self::STATUS_AWAITING_APPROVAL . "'"
            );
            $stmt->execute([':id' => $requestId]);
        } else {
            // @tenant-guard-ignore: joins the global password_resets table to memberships; tenant_id predicate is on the correlated subquery below
            $stmt = $this->db->prepare(
                "UPDATE password_resets
                    SET status = '" . self::STATUS_REJECTED . "',
                        staged_password_hash = NULL,
                        updated_at = NOW()
                  WHERE id = :id
                    AND status = '" . self::STATUS_AWAITING_APPROVAL . "'
                    AND EXISTS (
                        SELECT 1 FROM memberships m
                        WHERE m.profile_id = password_resets.profile_id
                          AND m.tenant_id = :tenant_id
                          AND m.status = 'active'
                    )"
            );
            $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId]);
        }

        return $stmt->rowCount() > 0 ? ['profile_id' => (int) $row['profile_id']] : null;
    }
}
