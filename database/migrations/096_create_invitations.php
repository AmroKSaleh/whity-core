<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Tenant invitations (WHIT-417 / #797 item 3) — how a tenant administrator
 * onboards somebody without an operator typing a password.
 *
 * Modelled on `password_resets` (migration 076): a single-use, time-boxed
 * token whose sha256 hash is the only thing persisted. One deliberate
 * difference in classification, and it is the whole point of the table.
 *
 * TENANT-OWNED, unlike `password_resets`
 * --------------------------------------
 * A password reset belongs to a PERSON, so its table is global and the admin
 * queue is tenant-scoped at query time via a JOIN. An invitation belongs to
 * the TENANT that issued it — it is that tenant's decision to extend access,
 * carrying that tenant's role and OU — so it carries `tenant_id` and is
 * registered in {@see \Whity\Core\Tenant\TenantOwnedTables}. Every read and
 * write from an administrator binds a `tenant_id` predicate.
 *
 * WHY THERE IS NO `profile_id` COLUMN
 * -----------------------------------
 * An invitation targets an EMAIL ADDRESS, not an identity, and the address may
 * or may not already have a profile — the important case being someone who
 * already has an account in a DIFFERENT tenant (#797 item 3). Resolving that to
 * a `profile_id` here and storing it would do two harmful things: it would go
 * stale the moment the address gains a profile between invite and accept, and
 * it would turn every row into a stored record of whether an address has an
 * account, readable by any administrator who can list invitations. The address
 * is resolved through {@see \Whity\Core\Identity\ProfileProvisioner} at ACCEPT
 * time instead, so the answer is always current and never persisted.
 *
 * THE PARTIAL UNIQUE INDEX
 * ------------------------
 * `UNIQUE (tenant_id, email) WHERE status = 'pending'` — at most ONE live
 * invitation per address per tenant. Re-inviting supersedes rather than
 * accumulates, so two usable tokens for the same person can never be in
 * circulation at once, and the rule is enforced by the engine rather than by
 * the service remembering to check. Superseded/accepted/revoked rows are
 * exempt from it, so the history stays intact. The same partial-index shape
 * migration 094 introduced on `memberships`; both engines support it.
 *
 * Idempotent (IF NOT EXISTS) and fully reversible via down().
 */
class CreateInvitations
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS invitations (
                id          BIGSERIAL    NOT NULL PRIMARY KEY,
                tenant_id   INTEGER      NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                email       VARCHAR(255) NOT NULL,
                role_id     INTEGER      NOT NULL REFERENCES roles(id) ON DELETE CASCADE,
                ou_id       INTEGER               REFERENCES organizational_units(id) ON DELETE SET NULL,
                token_hash  VARCHAR(64)  NOT NULL,
                status      VARCHAR(20)  NOT NULL DEFAULT 'pending',
                invited_by  INTEGER               REFERENCES profiles(id) ON DELETE SET NULL,
                expires_at  TIMESTAMP    NOT NULL,
                accepted_at TIMESTAMP,
                revoked_at  TIMESTAMP,
                created_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                updated_at  TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE(token_hash)
            )
        ");

        // At most one LIVE invitation per address per tenant (see docblock).
        $db->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_invitations_pending_email
                ON invitations (tenant_id, email) WHERE status = 'pending'
        ");

        // The administrator's list is always `WHERE tenant_id = ?`.
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_invitations_tenant_id
                ON invitations (tenant_id)
        ');

        // Supports sweeping expired rows without scanning the table.
        $db->exec('
            CREATE INDEX IF NOT EXISTS idx_invitations_expires_at
                ON invitations (expires_at)
        ');
    }

    /**
     * Drop the table. Its indexes go with it on both engines, so there is
     * nothing to unwind separately — and nothing outside this table depends on
     * it, since an ACCEPTED invitation has already become a `memberships` row
     * and rolling back must not take somebody's access away with it.
     */
    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS invitations CASCADE');
    }
}
