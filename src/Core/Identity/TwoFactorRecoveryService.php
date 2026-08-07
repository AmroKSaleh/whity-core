<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Auth\BackupCodesService;

/**
 * "I lost my 2FA device" recovery-request lifecycle
 * (WC-password-reset-2fa-recovery).
 *
 * For a user locked out because they lost BOTH their password and their 2FA
 * device/backup codes and so cannot log in at all. This is a REQUEST, never an
 * instant self-service action — there is no self-service way to disable
 * someone's second factor, which would defeat the point of having one. It
 * always lands in an admin approval queue.
 *
 * Two-step token lifecycle (mirrors {@see EmailVerificationService} /
 * {@see PasswordResetService}'s shape, `bin2hex(random_bytes(32))`, only
 * `sha256()` hash persisted, single-use):
 *   1. {@see self::issue()} — the user submitted their email; a token is
 *      minted proving nothing yet except that an email was sent. Any prior
 *      outstanding token for the profile is superseded.
 *   2. {@see self::confirm()} — the user presents the raw token from the
 *      emailed link. This PROVES mailbox ownership and is the point the
 *      pending request becomes admin-visible (status 'pending') — requiring
 *      this proof before queue-visibility prevents an unauthenticated caller
 *      from flooding the admin queue with requests against emails they do not
 *      control.
 *   3. An admin {@see self::approveForTenant()}s (clears the TARGET profile's
 *      2FA — mirrors {@see \Whity\Api\TwoFactorHandler::disable()}'s fields
 *      exactly, but applied to the target, not the caller — then issues+returns
 *      a fresh password-reset token via the injected {@see PasswordResetService}
 *      so the caller can email it, giving a full clean recovery) or
 *      {@see self::rejectForTenant()}s (profile untouched) a 'pending' row.
 *
 * A secondary fallback, {@see self::forceResetForTenant()}, lets an admin apply
 * the SAME clear-2FA-and-trigger-reset primitive directly to a named profile in
 * their own tenant with NO prior request — for the case where the locked-out
 * user genuinely cannot even receive email (no channel at all) and reaches an
 * admin out-of-band. Same permission, same audit trail as the queue path.
 *
 * `two_factor_recovery_requests` is a sanctioned GLOBAL table (no tenant_id) —
 * see {@see \Whity\Core\Tenant\SanctionedGlobalTables}; the admin approval QUEUE
 * is tenant-scoped at query time via a JOIN to `memberships`.
 */
final class TwoFactorRecoveryService
{
    /** Default token lifetime: 1 hour (mirrors PasswordResetService — sensitive, short-lived). */
    public const DEFAULT_TTL_SECONDS = 3600;

    private const TOKEN_BYTES = 32;

    /** Row states. */
    public const STATUS_PENDING_CONFIRMATION = 'pending_confirmation';
    public const STATUS_PENDING = 'pending';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    public function __construct(
        private readonly PDO $db,
        private readonly PasswordResetService $passwordResets,
        private readonly BackupCodesService $backupCodes,
    ) {}

    /**
     * Mint an ownership-proof token for a profile. Supersedes any prior
     * outstanding (unconsumed) token for the same profile.
     *
     * @return string The raw token to embed in the confirmation link.
     */
    public function issue(int $profileId, int $ttlSeconds = self::DEFAULT_TTL_SECONDS): string
    {
        $rawToken  = bin2hex(random_bytes(self::TOKEN_BYTES));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            $this->db->prepare(
                'DELETE FROM two_factor_recovery_requests WHERE profile_id = :pid AND consumed_at IS NULL'
            )->execute([':pid' => $profileId]);

            $this->db->prepare(
                "INSERT INTO two_factor_recovery_requests (profile_id, token_hash, status, expires_at, created_at, updated_at)
                 VALUES (:pid, :hash, '" . self::STATUS_PENDING_CONFIRMATION . "', :expires_at, NOW(), NOW())"
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
     * Consume the ownership-proof token: burns it (single-use) and flips the
     * row to 'pending' — the point it becomes visible in the admin queue.
     *
     * @return array{request_id: int, profile_id: int}|null null for an
     *   unknown, expired, or already-consumed token.
     */
    public function confirm(string $rawToken): ?array
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
                 FROM two_factor_recovery_requests
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

            $this->db->prepare(
                "UPDATE two_factor_recovery_requests
                    SET status = '" . self::STATUS_PENDING . "',
                        consumed_at = :now,
                        updated_at = NOW()
                  WHERE id = :id AND consumed_at IS NULL"
            )->execute([':now' => $now, ':id' => $requestId]);

            if ($ownTx) {
                $this->db->commit();
            }

            return [
                'request_id' => $requestId,
                'profile_id' => (int) $row['profile_id'],
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * List pending (admin-visible) 2FA-recovery requests for profiles that
     * hold an ACTIVE membership in the given tenant. Mirrors
     * {@see PasswordResetService::listPendingForTenant()}.
     *
     * @return list<array{id:int, profile_id:int, email:string, display_name:string, created_at:string}>
     */
    public function listPendingForTenant(int $tenantId): array
    {
        // @tenant-guard-ignore: joins the global two_factor_recovery_requests/profiles tables to memberships, scoped by the tenant_id predicate below
        $stmt = $this->db->prepare(
            "SELECT DISTINCT tfr.id, tfr.profile_id, tfr.created_at,
                    p.display_name,
                    pe.email AS email
             FROM two_factor_recovery_requests tfr
             JOIN memberships m ON m.profile_id = tfr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
             JOIN profiles p ON p.id = tfr.profile_id
             LEFT JOIN profile_emails pe ON pe.profile_id = tfr.profile_id AND pe.is_primary = TRUE
             WHERE tfr.status = '" . self::STATUS_PENDING . "'
             ORDER BY tfr.created_at ASC"
        );
        $stmt->execute([':tenant_id' => $tenantId]);
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
     * Admin-approve a pending 2FA-recovery request: clear the TARGET profile's
     * 2FA (same fields {@see \Whity\Api\TwoFactorHandler::disable()} clears —
     * two_factor_secret, two_factor_enabled, two_factor_backup_codes_version —
     * plus invalidating its outstanding backup codes), mark the row approved,
     * then issue a fresh password-reset token for the SAME profile so the
     * caller can email it (a full clean recovery).
     *
     * Tenant-scoped: the target profile must hold an ACTIVE membership in
     * $tenantId (never trust a bare request id). The atomic status guard makes
     * a concurrent double-approve a safe no-op for the loser.
     *
     * @return array{profile_id:int, email:string, reset_token:string}|null
     *   null = not found, not pending, or the target is not a member of $tenantId.
     */
    public function approveForTenant(int $requestId, int $tenantId): ?array
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            // @tenant-guard-ignore: joins the global two_factor_recovery_requests table to memberships, scoped by the tenant_id predicate below
            $stmt = $this->db->prepare(
                "SELECT tfr.id, tfr.profile_id, pe.email, p.two_factor_backup_codes_version
                 FROM two_factor_recovery_requests tfr
                 JOIN memberships m ON m.profile_id = tfr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
                 JOIN profiles p ON p.id = tfr.profile_id
                 LEFT JOIN profile_emails pe ON pe.profile_id = tfr.profile_id AND pe.is_primary = TRUE
                 WHERE tfr.id = :id AND tfr.status = '" . self::STATUS_PENDING . "'
                 LIMIT 1"
            );
            $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                if ($ownTx) {
                    $this->db->commit();
                }
                return null;
            }

            // The status guard makes this idempotent-safe under a concurrent
            // second approve for the same row.
            $stmt2 = $this->db->prepare(
                "UPDATE two_factor_recovery_requests
                    SET status = '" . self::STATUS_APPROVED . "', updated_at = NOW()
                  WHERE id = :id AND status = '" . self::STATUS_PENDING . "'"
            );
            $stmt2->execute([':id' => $requestId]);

            if ($stmt2->rowCount() === 0) {
                if ($ownTx) {
                    $this->db->rollBack();
                }
                return null;
            }

            $profileId = (int) $row['profile_id'];
            $email     = (string) ($row['email'] ?? '');

            $this->clearTwoFactor($profileId, (int) $row['two_factor_backup_codes_version']);
            $resetToken = $this->passwordResets->issue($profileId);

            if ($ownTx) {
                $this->db->commit();
            }

            return [
                'profile_id'  => $profileId,
                'email'       => $email,
                'reset_token' => $resetToken,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Admin-reject a pending 2FA-recovery request. The target profile is left
     * completely untouched.
     *
     * @return array{profile_id:int}|null null = not found, not pending, or the
     *   target is not a member of $tenantId.
     */
    public function rejectForTenant(int $requestId, int $tenantId): ?array
    {
        // @tenant-guard-ignore: joins the global two_factor_recovery_requests table to memberships, scoped by the tenant_id predicate below
        $lookup = $this->db->prepare(
            "SELECT tfr.profile_id
             FROM two_factor_recovery_requests tfr
             JOIN memberships m ON m.profile_id = tfr.profile_id AND m.tenant_id = :tenant_id AND m.status = 'active'
             WHERE tfr.id = :id AND tfr.status = '" . self::STATUS_PENDING . "'
             LIMIT 1"
        );
        $lookup->execute([':id' => $requestId, ':tenant_id' => $tenantId]);
        $row = $lookup->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        // @tenant-guard-ignore: joins the global two_factor_recovery_requests table to memberships; tenant_id predicate is on the correlated subquery below
        $stmt = $this->db->prepare(
            "UPDATE two_factor_recovery_requests
                SET status = '" . self::STATUS_REJECTED . "', updated_at = NOW()
              WHERE id = :id
                AND status = '" . self::STATUS_PENDING . "'
                AND EXISTS (
                    SELECT 1 FROM memberships m
                    WHERE m.profile_id = two_factor_recovery_requests.profile_id
                      AND m.tenant_id = :tenant_id
                      AND m.status = 'active'
                )"
        );
        $stmt->execute([':id' => $requestId, ':tenant_id' => $tenantId]);

        return $stmt->rowCount() > 0 ? ['profile_id' => (int) $row['profile_id']] : null;
    }

    /**
     * Secondary fallback (no prior request): an admin directly forces the same
     * clear-2FA-and-trigger-reset primitive onto a named profile, for a user
     * who cannot even receive email and reaches an admin out-of-band.
     *
     * Tenant-scoped: $targetProfileId must hold an ACTIVE membership in
     * $tenantId.
     *
     * @return array{profile_id:int, email:string, reset_token:string}|null
     *   null = target not found or not a member of $tenantId.
     */
    public function forceResetForTenant(int $targetProfileId, int $tenantId): ?array
    {
        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }

        try {
            // @tenant-guard-ignore: joins the global profiles table to memberships, scoped by the tenant_id predicate below
            $stmt = $this->db->prepare(
                "SELECT p.id, pe.email, p.two_factor_backup_codes_version
                 FROM profiles p
                 JOIN memberships m ON m.profile_id = p.id AND m.tenant_id = :tenant_id AND m.status = 'active'
                 LEFT JOIN profile_emails pe ON pe.profile_id = p.id AND pe.is_primary = TRUE
                 WHERE p.id = :pid
                 LIMIT 1"
            );
            $stmt->execute([':pid' => $targetProfileId, ':tenant_id' => $tenantId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                if ($ownTx) {
                    $this->db->commit();
                }
                return null;
            }

            $this->clearTwoFactor($targetProfileId, (int) $row['two_factor_backup_codes_version']);
            $resetToken = $this->passwordResets->issue($targetProfileId);

            if ($ownTx) {
                $this->db->commit();
            }

            return [
                'profile_id'  => $targetProfileId,
                'email'       => (string) ($row['email'] ?? ''),
                'reset_token' => $resetToken,
            ];
        } catch (\Throwable $e) {
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Clear a profile's 2FA state. Mirrors
     * {@see \Whity\Api\TwoFactorHandler::disable()} exactly (same fields,
     * same backup-codes invalidation) but applied to a TARGET profile chosen
     * by an admin, never the caller.
     */
    private function clearTwoFactor(int $profileId, int $currentBackupCodesVersion): void
    {
        if ($currentBackupCodesVersion > 0) {
            $this->backupCodes->invalidateOldCodes($profileId, $currentBackupCodesVersion);
        }

        // @tenant-guard-ignore: profiles is a sanctioned GLOBAL identity table (ADR 0005 §1)
        $this->db->prepare(
            'UPDATE profiles
                SET two_factor_secret = NULL, two_factor_enabled = false,
                    two_factor_backup_codes_version = 0, updated_at = NOW()
              WHERE id = :pid'
        )->execute([':pid' => $profileId]);
    }
}
