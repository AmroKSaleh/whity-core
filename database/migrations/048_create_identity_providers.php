<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-e6287 federated auth: per-tenant identity-provider (SSO/OIDC) registry.
 *
 * Each row is one configured provider for one tenant — e.g. "Sign in with
 * Google" for tenant 42. The generic OIDC relying-party engine reads a row to
 * drive the Authorization Code + PKCE flow; the per-tenant admin CRUD
 * (IdentityProvidersApiHandler, gated on auth_providers:manage) manages them.
 *
 * TENANT-OWNED: `tenant_id` NOT NULL + ON DELETE CASCADE. Registered in
 * TenantOwnedTables so the predicate guard polices every query, and isolation is
 * proven in IdentityProviderRepositoryRealEngineTest (a tenant can only see/edit
 * its own providers).
 *
 * Schema notes:
 *   - UNIQUE(tenant_id, provider_key): a tenant configures each provider once.
 *   - client_secret_encrypted: EncryptedSecretStore ciphertext, NEVER plaintext;
 *     nullable for public clients / secretless flows. The admin API never returns it.
 *   - issuer + discovery_url: the engine prefers OIDC discovery
 *     (.well-known/openid-configuration); issuer is validated against the ID token.
 *   - scopes: space-separated OAuth scopes (default the OIDC identity set).
 *   - domain: optional email-domain binding for JIT membership (WC-635, later PR).
 *   - enabled: a tenant can disable a provider without deleting its config.
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateIdentityProviders
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS identity_providers (
                id                      BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id               INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                provider_key            VARCHAR(64)   NOT NULL,
                display_name            VARCHAR(255)  NOT NULL,
                client_id               VARCHAR(512)  NOT NULL,
                client_secret_encrypted TEXT,
                issuer                  VARCHAR(512)  NOT NULL,
                discovery_url           VARCHAR(512),
                scopes                  VARCHAR(512)  NOT NULL DEFAULT 'openid email profile',
                domain                  VARCHAR(253),
                enabled                 BOOLEAN       NOT NULL DEFAULT TRUE,
                created_at              TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at              TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, provider_key)
            )
        ");

        $db->exec('CREATE INDEX IF NOT EXISTS idx_identity_providers_tenant_id ON identity_providers(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS identity_providers CASCADE');
    }
}
