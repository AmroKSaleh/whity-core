<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * "Lost my 2FA device" recovery request (WC-password-reset-2fa-recovery).
 *
 * For a user locked out because they lost BOTH their password and their 2FA
 * device/backup codes and so cannot log in at all. This is a REQUEST, never an
 * instant self-service action — disabling someone's second factor unattended
 * would defeat the point of having one. It always lands in an admin approval
 * queue; only on admin approval does the system clear the profile's 2FA AND
 * issue+send a password-reset link (see {@see \Whity\Core\Identity\PasswordResetService}),
 * giving a full clean recovery.
 *
 * Two-step lifecycle (mirrors email-verification's request+confirm shape, see
 * {@see \Whity\Core\Identity\TwoFactorRecoveryService}):
 *   1. The user submits their email (public, rate-limited, generic response).
 *      A token is issued here with status='pending_confirmation' — this step
 *      alone does NOT create anything an admin can see; it only proves the
 *      caller controls the mailbox once they click the emailed link.
 *   2. The user presents the raw token from that link. This CONSUMES the token
 *      (consumed_at) and flips the row to status='pending' — only NOW does the
 *      request become visible in the admin approval queue. Requiring proof of
 *      email ownership before a request becomes admin-visible prevents an
 *      unauthenticated caller from flooding the queue with requests against
 *      email addresses they do not control.
 *   3. An admin approves ('approved': 2FA cleared + a password-reset token
 *      issued+sent) or rejects ('rejected': profile untouched) a 'pending' row.
 *
 * GLOBAL (non-tenant-scoped), like `email_verifications`/`password_resets`:
 * profile_id points at the global `profiles` identity anchor (ADR 0005). The
 * admin approval QUEUE is tenant-scoped at query time via a JOIN to
 * `memberships` (the requester's tenant), not via a column on this table.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateTwoFactorRecoveryRequests
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS two_factor_recovery_requests (
                id           BIGSERIAL     NOT NULL PRIMARY KEY,
                profile_id   INTEGER       NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                token_hash   VARCHAR(64)   NOT NULL,
                status       VARCHAR(24)   NOT NULL DEFAULT 'pending_confirmation',
                expires_at   TIMESTAMP     NOT NULL,
                consumed_at  TIMESTAMP,
                created_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE(token_hash)
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_2fa_recovery_requests_profile_id
                ON two_factor_recovery_requests (profile_id)
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_2fa_recovery_requests_expires_at
                ON two_factor_recovery_requests (expires_at)
        ");

        // Speeds the admin queue's WHERE status = 'pending' scan.
        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_2fa_recovery_requests_status
                ON two_factor_recovery_requests (status)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS two_factor_recovery_requests CASCADE');
    }
}
