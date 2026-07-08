<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-7ad4 federated identity: link external (SSO/OIDC) accounts to profiles.
 *
 * Backs "Sign in with Google" (and future Microsoft/SAML/generic-OIDC providers).
 * Each row links one external account — identified by the provider's stable
 * `(issuer, subject)` pair — to exactly one local `profiles` row, so a returning
 * federated user resolves to their profile without a password.
 *
 * Schema notes:
 *   - `(issuer, subject)` is the identity key. `subject` is the provider's
 *     opaque, immutable user id (OIDC `sub`), never the email. UNIQUE(issuer,
 *     subject) is the STRUCTURAL anti-takeover guard: one external account maps
 *     to at most one profile, mirroring profile_emails' UNIQUE(email) (#181).
 *   - `provider_key` records which configured provider minted it (e.g. `google`)
 *     for display/unlink UX; the trust key remains (issuer, subject).
 *   - `email` is the address asserted at link time — informational only; the
 *     verified-email → profile linking decision is made in application code.
 *   - profile_id FK ON DELETE CASCADE: deleting a profile drops its links.
 *
 * GLOBAL (non-tenant-scoped), like `profiles`/`profile_emails` (ADR 0005): a
 * federated identity belongs to a person, not an org — no tenant_id. Enumerated
 * in SanctionedGlobalTables. Idempotent (IF NOT EXISTS) and reversible.
 */
class CreateExternalIdentities
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS external_identities (
                id            BIGSERIAL    NOT NULL PRIMARY KEY,
                profile_id    INTEGER      NOT NULL REFERENCES profiles(id) ON DELETE CASCADE,
                provider_key  VARCHAR(64)  NOT NULL,
                issuer        VARCHAR(255) NOT NULL,
                subject       VARCHAR(255) NOT NULL,
                email         VARCHAR(255),
                linked_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
                last_login_at TIMESTAMP,
                created_at    TIMESTAMP    NOT NULL DEFAULT NOW(),
                UNIQUE (issuer, subject)
            )
        ");

        $db->exec("
            CREATE INDEX IF NOT EXISTS idx_external_identities_profile_id
                ON external_identities (profile_id)
        ");
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS external_identities CASCADE');
    }
}
