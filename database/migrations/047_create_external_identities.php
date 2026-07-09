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
 * Trust tiers (WC-f3b17bd2 — tiered federated trust)
 * ---------------------------------------------------
 * A configured IdP is trusted only as far as who configured it, so the identity
 * KEY depends on the provider's trust tier (encoded by `provider_id`):
 *   - GLOBAL-TRUST (operator IdP, configured at the system tenant): `provider_id`
 *     is NULL and the key is the global `(issuer, subject)`. Only the deployment
 *     operator can configure such a provider, so its `email_verified` assertion is
 *     authoritative over the global profile namespace — a real Google `sub` maps
 *     one person to one profile across all their tenants (ADR 0005).
 *   - TENANT-TRUST (a tenant's own bring-your-own IdP): `provider_id` is the
 *     configuring `identity_providers.id` and the key is `(provider_id, subject)`.
 *     A tenant admin controls their IdP's issuer/JWKS, so its assertions must NOT
 *     reach the global namespace — namespacing by `provider_id` stops a
 *     tenant-trust IdP from spoofing `issuer=accounts.google.com` and colliding
 *     with the operator's global Google links. Cross-tenant reach is denied in
 *     application code (link only to members of the configuring tenant).
 *
 * Schema notes:
 *   - `subject` is the provider's opaque, immutable user id (OIDC `sub`), never
 *     the email. The two PARTIAL UNIQUE indexes below are the STRUCTURAL
 *     anti-takeover guard — one external account maps to at most one profile
 *     within its trust namespace, mirroring profile_emails' UNIQUE(email) (#181).
 *   - `provider_id` is a plain (unconstrained) BIGINT, not a FK: identity_providers
 *     is created by the LATER migration 048, and the SQLite test shim cannot add a
 *     cross-table FK after the fact. Orphaned tenant-trust links (provider deleted)
 *     become unusable but remain unlinkable by their owner; the provider-delete
 *     path is responsible for any cleanup.
 *   - `provider_key` records which configured provider minted it (e.g. `google`)
 *     for display/unlink UX.
 *   - `email` is the address asserted at link time — informational only; the
 *     verified-email → profile linking decision is made in application code.
 *   - profile_id FK ON DELETE CASCADE: deleting a profile drops its links.
 *
 * GLOBAL (non-tenant-scoped) table, like `profiles`/`profile_emails` (ADR 0005):
 * a federated identity belongs to a person, not an org — no tenant_id. Enumerated
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
                provider_id   BIGINT,
                provider_key  VARCHAR(64)  NOT NULL,
                issuer        VARCHAR(255) NOT NULL,
                subject       VARCHAR(255) NOT NULL,
                email         VARCHAR(255),
                linked_at     TIMESTAMP    NOT NULL DEFAULT NOW(),
                last_login_at TIMESTAMP,
                created_at    TIMESTAMP    NOT NULL DEFAULT NOW()
            )
        ");

        // GLOBAL-TRUST namespace: at most one profile per (issuer, subject) among
        // operator-configured providers (provider_id IS NULL).
        $db->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_external_identities_global
                ON external_identities (issuer, subject)
                WHERE provider_id IS NULL
        ");

        // TENANT-TRUST namespace: at most one profile per (provider_id, subject),
        // isolated from the global namespace and from other tenants' providers.
        $db->exec("
            CREATE UNIQUE INDEX IF NOT EXISTS uq_external_identities_tenant
                ON external_identities (provider_id, subject)
                WHERE provider_id IS NOT NULL
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
