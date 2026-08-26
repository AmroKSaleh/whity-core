<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Single source of truth for EVERY table whity-core's own migrations create
 * (WC-723).
 *
 * Why this exists alongside {@see TenantOwnedTables}
 * -------------------------------------------------
 * Those two lists answer different questions and neither substitutes for the
 * other. `TenantOwnedTables` answers "must a query on this table bind a tenant
 * predicate?" and therefore lists only the tables carrying a `tenant_id`
 * column. `SanctionedGlobalTables` answers "is this table a reviewed exception
 * to that rule?".
 *
 * Between them they omit a third group: core tables that are neither — the
 * catalogues (`permissions`, `relationship_types`), the transitively scoped
 * children (`role_permissions`, `backup_codes`) and the tenant registry itself
 * (`tenants`). Those tables are as much core's as `audit_log` is.
 *
 * {@see TableOwnershipRegistry} needs the COMPLETE set, because ownership there
 * is positive: a table nobody has claimed is claimable, and a plugin that
 * claimed `profiles` or `role_permissions` could declare a referential guard
 * over it and count rows it can otherwise not read. Registering only the
 * tenant-owned and sanctioned-global sets would leave exactly that hole.
 *
 * Derivation and drift
 * --------------------
 * The set is DERIVED from database/migrations/ — every `CREATE TABLE` in a
 * forward `up()`, minus every table a later `up()` drops. It is pinned here and
 * re-derived by CoreTablesTest, the same drift alarm TenantOwnedTablesTest
 * provides: add a table in a migration and the test fails until this list is
 * updated. Without that alarm the ownership hole would silently reopen for
 * every new core table.
 *
 * Each entry records the migration that creates it, so provenance is auditable.
 */
final class CoreTables
{
    /**
     * Every table created by whity-core's migrations, keyed by table name with
     * the migration file that creates it.
     *
     * @var array<string, string>
     */
    private const TABLES = [
        'app_settings' => '024_create_app_settings.php',
        'audit_log' => '016_create_audit_log.php',
        'backup_codes' => '007_add_two_factor_support.php',
        'core_schema_migrations' => '003_create_core_schema_migrations.php',
        'data_type_restore_states' => '089_create_data_type_restore_states.php',
        'deployments' => '004_create_deployment_tables.php',
        'desktop_app_releases' => '099_create_desktop_app_releases.php',
        'desktop_plugin_releases' => '097_create_desktop_plugin_releases.php',
        'devices' => '044_create_devices.php',
        'document_artifacts' => '108_create_documents.php',
        'document_blocks' => '059_create_document_designer_tables.php',
        'document_collection_items' => '114_create_document_collections.php',
        'document_collections' => '114_create_document_collections.php',
        'document_qr_scans' => '122_create_document_qr_tracking.php',
        'document_qr_tokens' => '122_create_document_qr_tracking.php',
        'document_route_edges' => '119_add_route_verdicts_and_branching.php',
        'document_route_events' => '112_create_document_routing.php',
        'document_route_recipients' => '112_create_document_routing.php',
        'document_route_steps' => '112_create_document_routing.php',
        'document_route_template_edges' => '120_create_document_route_templates.php',
        'document_route_template_steps' => '120_create_document_route_templates.php',
        'document_route_templates' => '120_create_document_route_templates.php',
        'document_routes' => '112_create_document_routing.php',
        'document_templates' => '059_create_document_designer_tables.php',
        'documents' => '108_create_documents.php',
        'domain_events' => '066_create_domain_events.php',
        'email_verifications' => '046_create_email_verifications.php',
        'entity_tags' => '063_create_taxonomy_tables.php',
        'error_groups' => '087_create_error_groups.php',
        'event_outbox' => '066_create_domain_events.php',
        'external_identities' => '047_create_external_identities.php',
        'health_samples' => '085_create_health_samples.php',
        'identity_providers' => '048_create_identity_providers.php',
        'invitations' => '096_create_invitations.php',
        'jobs' => '065_create_jobs.php',
        'languages' => '081_create_language_tables.php',
        'mcp_tokens' => '033_create_mcp_tokens.php',
        'memberships' => '030_create_memberships.php',
        'migration_rollbacks' => '004_create_deployment_tables.php',
        'notification_deliveries' => '070_create_notifications.php',
        'notification_templates' => '072_create_notification_templates.php',
        'notifications' => '070_create_notifications.php',
        'organizational_units' => '005_create_organizational_units.php',
        'ou_role_assignments' => '008_create_ou_role_assignments.php',
        'ou_types' => '102_create_ou_types.php',
        'password_resets' => '076_create_password_resets.php',
        'permission_delegations' => '014_create_permission_delegations.php',
        'permissions' => '002_create_permissions.php',
        'persons' => '018_create_persons.php',
        'plan_entitlements' => '055_create_plans.php',
        'plans' => '055_create_plans.php',
        'profile_emails' => '029_create_profile_emails.php',
        'profiles' => '028_create_profiles.php',
        'relations' => '020_create_relations.php',
        'relationship_types' => '019_create_relationship_types.php',
        'resource_role_assignments' => '088_create_resource_role_assignments.php',
        'revoked_tokens' => '011_create_revoked_tokens.php',
        'role_permissions' => '002_create_permissions.php',
        'roles' => '001_create_users_roles.php',
        'scheduled_jobs' => '069_create_scheduled_jobs.php',
        'sequence_counters' => '092_create_sequence_counters.php',
        'sessions' => '045_create_sessions.php',
        'shared_store' => '032_create_shared_store.php',
        'tag_groups' => '063_create_taxonomy_tables.php',
        'tags' => '063_create_taxonomy_tables.php',
        'tenant_email_domains' => '031_create_tenant_email_domains.php',
        'tenant_entitlements' => '051_create_tenant_entitlements.php',
        'tenant_notification_settings' => '073_create_tenant_notification_settings.php',
        'tenant_plan' => '055_create_plans.php',
        'tenant_settings' => '025_create_tenant_settings.php',
        'tenant_storage_config' => '053_create_tenant_storage_config.php',
        'tenants' => '001_create_users_roles.php',
        'time_window_state_events' => '126_create_time_windows.php',
        'time_window_types' => '126_create_time_windows.php',
        'time_windows' => '126_create_time_windows.php',
        'translations' => '081_create_language_tables.php',
        'two_factor_policies' => '061_create_two_factor_policies.php',
        'two_factor_recovery_requests' => '077_create_two_factor_recovery_requests.php',
        'user_groups' => '116_create_user_groups.php',
        'user_notification_preferences' => '071_create_user_notification_preferences.php',
    ];

    /**
     * Static catalogue only — never instantiated.
     */
    private function __construct()
    {
    }

    /**
     * Every core table name.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_keys(self::TABLES);
    }

    /**
     * Whether the named table is created by a core migration.
     *
     * Case-insensitive: a declaration spelled `Profiles` claims the same table
     * as `profiles`, and must be refused for the same reason.
     *
     * @param string $table The table name to test.
     */
    public static function isCoreTable(string $table): bool
    {
        return array_key_exists(strtolower($table), self::TABLES);
    }

    /**
     * The migration that creates the named table, or null when it is not a core
     * table.
     *
     * @param string $table The table name to look up.
     */
    public static function migrationFor(string $table): ?string
    {
        return self::TABLES[strtolower($table)] ?? null;
    }
}
