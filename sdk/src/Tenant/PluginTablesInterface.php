<?php

declare(strict_types=1);

namespace Whity\Sdk\Tenant;

/**
 * Declares the DATABASE TABLES a plugin owns (SDK 1.19, WC-723).
 *
 * Why the host needs this
 * ----------------------
 * {@see TenantTableRegistry} answers "is this table tenant-scoped?" — a
 * question about the SHAPE of a table. It cannot answer "WHO owns this table?",
 * because it stores a free-text rationale rather than a structured owner, and
 * because {@see TenantTableRegistry::merge()} folds the host's tables into a
 * plugin's set with no record of origin: after merging, a plugin's registry
 * happily reports the host's `users` as one of its own.
 *
 * Ownership is what gates a plugin from declaring a referential guard over
 * somebody else's data. Without it, "which rows still reference this record?"
 * becomes a way to probe a table the plugin can otherwise not read.
 *
 * Attribution comes from the LOADER, never the declaration
 * -------------------------------------------------------
 * A plugin declares WHICH tables it claims; the host stamps WHO claimed them,
 * from the plugin name the loader already holds. A plugin can therefore claim
 * any table name, but it cannot claim to be another plugin — the same
 * attribution model the host applies to declared permissions and to
 * {@see \Whity\Sdk\Rbac\PluginResourceTypesInterface}.
 *
 * A table already claimed by the host or by an earlier plugin is REFUSED: the
 * whole declaration is rejected with a logged warning, and the plugin owns
 * nothing. Claiming a core table is therefore not a way to acquire authority
 * over it; it is a way to acquire nothing at all.
 *
 * OPTIONAL. Implement it only if the plugin owns tables and wants to declare
 * data types or referential guards over them; the host skips plugins that do
 * not implement it, so adding this interface breaks nothing that exists.
 *
 *     public function getOwnedTables(): array
 *     {
 *         return [
 *             'acme_records' => self::SCOPE_TENANT,
 *             'acme_entries' => self::SCOPE_TENANT,
 *         ];
 *     }
 */
interface PluginTablesInterface
{
    /**
     * The table carries a `tenant_id` column and holds rows belonging to one
     * tenant. Every read/write against it must bind a tenant predicate.
     */
    public const SCOPE_TENANT = 'tenant';

    /**
     * The table holds no tenant data (a platform-unique counter, a catalogue).
     *
     * Declaring `global` is a statement about the SHAPE of the table, not a
     * request for an exemption: the host will not build a data type or a
     * referential guard over a table declared global, precisely because it
     * cannot bind a tenant predicate to one.
     */
    public const SCOPE_GLOBAL = 'global';

    /**
     * The tables this plugin owns, mapped to their tenant scope.
     *
     * Keys are bare table names as they exist in the database — lowercase,
     * starting with a letter, letters/digits/underscores only, at most 63
     * characters (the PostgreSQL identifier limit). Values are
     * {@see self::SCOPE_TENANT} or {@see self::SCOPE_GLOBAL}.
     *
     * A malformed name, an unknown scope, or a table another source already
     * owns rejects the WHOLE declaration with a logged warning rather than
     * crashing the host — and rather than leaving a half-applied claim, which
     * would make ownership depend on iteration order.
     *
     * @return array<string, string> table name => self::SCOPE_TENANT|self::SCOPE_GLOBAL
     */
    public function getOwnedTables(): array;
}
