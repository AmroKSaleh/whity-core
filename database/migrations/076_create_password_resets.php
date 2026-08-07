<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Self-service "forgot password" recovery (WC-password-reset-2fa-recovery).
 *
 * Mirrors `email_verifications` (migration 046) almost exactly — single-use,
 * time-boxed, hashed-at-rest tokens — with one addition: an optional admin
 * approval branch (`auth.password_reset_approval_required`).
 *
 * Lifecycle (see {@see \Whity\Core\Identity\PasswordResetService}):
 *   - issue() inserts a row with status='pending', token_hash only (the raw
 *     token is emailed, never stored).
 *   - confirm() (the requester presents the raw token + their NEW password,
 *     proving ownership) burns the token (consumed_at) and either:
 *       - applies it immediately (approval not required): profiles.password_hash
 *         is updated, profiles.token_epoch is bumped, and this row moves to
 *         status='applied'; OR
 *       - stages it (approval required): the bcrypt hash of the NEW password is
 *         stored in staged_password_hash and this row moves to
 *         status='awaiting_approval'. Crucially, staged_password_hash is NEVER
 *         populated before the requester has proven token ownership — an
 *         attacker who merely knows an email address can never get a password
 *         staged for approval.
 *   - An admin approve/reject transitions an 'awaiting_approval' row to
 *     'approved' (applying the staged hash + bumping token_epoch, then
 *     clearing staged_password_hash) or 'rejected' (profiles untouched).
 *
 * GLOBAL (non-tenant-scoped), like `email_verifications`: profile_id points at
 * the global `profiles` identity anchor (ADR 0005), and a password credential
 * belongs to a person, not a tenant. The admin approval QUEUE is tenant-scoped
 * at query time via a JOIN to `memberships` (the requester's tenant), not via a
 * column on this table.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreatePasswordResets
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS password_resets (
                id                   BIGSERIAL     NOT NULL PRIMARY KEY,
                profile_id           INTEGER       NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                token_hash           VARCHAR(64)   NOT NULL,
                status               VARCHAR(20)   NOT NULL DEFAULT 'pending',
                staged_password_hash VARCHAR(255),
                expires_at           TIMESTAMP     NOT NULL,
                consumed_at          TIMESTAMP,
                created_at           TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at           TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE(token_hash)
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_password_resets_profile_id
                ON password_resets (profile_id)
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_password_resets_expires_at
                ON password_resets (expires_at)
        ");

        // Speeds the admin queue's WHERE status = 'awaiting_approval' scan.
        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_password_resets_status
                ON password_resets (status)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS password_resets CASCADE');
    }
}
