<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-621 native taxonomy/tagging subsystem — a domain-neutral tagging primitive
 * (as generic as RBAC) so plugins stop reinventing per-domain tag tables.
 *
 * Three tenant-scoped tables:
 *  - `tag_groups`  — a named bucket of tags (e.g. "priority", "department"),
 *    unique per tenant by `group_key`; `display_name` is a bilingual {ar,en}
 *    JSONB label (the WC-532 LocalizedText shape).
 *  - `tags`        — an individual tag inside a group, unique per (tenant, group)
 *    by `name`.
 *  - `entity_tags` — a POLYMORPHIC association between a tag and any entity in
 *    any plugin: `entity_type` is an opaque plugin-supplied string with NO FK,
 *    so a resource in any plugin becomes taggable with zero bespoke join tables.
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every table
 * carries `tenant_id` NOT NULL + ON DELETE CASCADE, and every SELECT/UPDATE/
 * DELETE binds an explicit `tenant_id` predicate so a tag written under one
 * tenant can never be read or mutated under another. `entity_tags.tenant_id` is
 * denormalised from the tag's tenant purely to keep the tenant predicate + the
 * reverse-lookup index on a single row (a tag_id already pins exactly one
 * tenant, so the two can never disagree).
 *
 * Idempotent (IF NOT EXISTS) and reversible via down().
 */
class CreateTaxonomyTables
{
    public static function up(Database $db): void
    {
        // A named bucket of tags, scoped to one tenant. `group_key` is the stable
        // machine token (unique per tenant); `display_name` is the human {ar,en}
        // label stored as JSONB. (`group_key`, not `key`, to dodge the reserved
        // word across the Postgres + SQLite-test engines.)
        $db->exec("
            CREATE TABLE IF NOT EXISTS tag_groups (
                id           BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id    INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                group_key    VARCHAR(64)   NOT NULL,
                display_name JSONB         NOT NULL DEFAULT '{}'::jsonb,
                created_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at   TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, group_key)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tag_groups_tenant_id ON tag_groups(tenant_id)');

        // An individual tag inside a group, unique per (tenant, group) by `name`.
        $db->exec("
            CREATE TABLE IF NOT EXISTS tags (
                id         BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id  INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                group_id   BIGINT        NOT NULL REFERENCES tag_groups(id) ON DELETE CASCADE,
                name       VARCHAR(128)  NOT NULL,
                created_at TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, group_id, name)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tags_tenant_id ON tags(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_tags_group_id ON tags(group_id)');

        // Polymorphic tag<->entity association. `entity_type` is an opaque
        // plugin-supplied string (NO FK) so ANY resource is taggable. The PK
        // (entity_type, entity_id, tag_id) makes attach idempotent; the
        // (tenant_id, entity_type, tag_id) index serves the "entities of type T
        // carrying tag X" reverse lookup.
        $db->exec("
            CREATE TABLE IF NOT EXISTS entity_tags (
                tenant_id   INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                entity_type VARCHAR(128)  NOT NULL,
                entity_id   BIGINT        NOT NULL,
                tag_id      BIGINT        NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
                created_at  TIMESTAMP     NOT NULL DEFAULT NOW(),
                PRIMARY KEY (entity_type, entity_id, tag_id)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_entity_tags_reverse ON entity_tags(tenant_id, entity_type, tag_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_entity_tags_tag_id ON entity_tags(tag_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS entity_tags CASCADE');
        $db->exec('DROP TABLE IF EXISTS tags CASCADE');
        $db->exec('DROP TABLE IF EXISTS tag_groups CASCADE');
    }
}
