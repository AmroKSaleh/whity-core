<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

/**
 * What each core permission actually does, in one place.
 *
 * WHY THIS EXISTS. `permissions.description` is rendered and searched in the
 * role editor, so it is what an administrator reads when deciding whether to
 * grant something. On a fresh database 43 of 63 rows said `Core permission
 * (groups:read)` — generated filler standing in for the one piece of text whose
 * whole job is to explain the grant.
 *
 * The cause was ordering, and the consequence was backwards. Migration 013 seeds
 * the WHOLE of {@see CorePermissions::all()} — read from today's class, not from
 * the catalogue as it stood when 013 was written — with placeholder text. Every
 * later migration carrying the real description then inserts `ON CONFLICT (name)
 * DO NOTHING`, finds the row already there, and discards it. So an old
 * installation kept the good text it was given, and a NEW one got filler for
 * everything: the fresh install was the degraded case.
 *
 * WHY A PLAIN MAP AND NOT ROW PROVENANCE. #1057 solved the same shape for
 * translations with a `source_managed` column, because a human can edit a
 * translation and that edit must survive a re-sync. Nothing edits a permission
 * description: there is no write path to that column anywhere outside
 * migrations, and no API that changes one. So the database has no opinion worth
 * protecting here, and the code can simply be authoritative.
 *
 * KEEPING IT HONEST. {@see CorePermissionDescriptionsTest} asserts that every
 * slug in {@see CorePermissions::all()} appears below and that none of the text
 * is filler, so adding a permission without describing it fails the build rather
 * than shipping another `Core permission (x)`.
 */
final class CorePermissionDescriptions
{
    /**
     * Slug => human description. Authoritative: the migration syncs the database
     * to this, it never reads back.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        // Not SQL. This file contains no database access of any kind — it is a
        // map of English sentences.
        //
        // The scanner stitches every string literal in a statement together and
        // looks for a DML verb beside a known table name, which is the right
        // design for `'DELETE FROM ' . $table`. Here it concatenates 68
        // descriptions into one line containing "Delete roles", "Create and
        // update roles" and "View roles and their assignments", and reasonably
        // concludes it has found an unscoped DELETE on `roles`.
        //
        // Annotated rather than reworded: writing around the scanner would mean
        // choosing worse descriptions to avoid saying "delete roles" on the page
        // whose entire job is to say what `roles:delete` does.
        //
        // The tag is last because only its own line counts, and the scanner
        // looks at most three lines above the statement.
        // @tenant-guard-ignore: prose, not a query — reasoning directly above
        return [
        CorePermissions::USERS_READ                   => 'List and view users',
        CorePermissions::USERS_WRITE                  => 'Create and update users',
        CorePermissions::USERS_DELETE                 => 'Delete users',
        CorePermissions::ROLES_READ                   => 'View roles and their assignments',
        CorePermissions::ROLES_WRITE                  => 'Create and update roles',
        CorePermissions::ROLES_DELETE                 => 'Delete roles',
        CorePermissions::ROLES_MANAGE                 => 'Grant and revoke a role\'s permissions, and assign roles on individual records',
        CorePermissions::TENANTS_READ                 => 'Read tenants',
        CorePermissions::TENANTS_WRITE                => 'Create and update tenants',
        CorePermissions::TENANTS_DELETE               => 'Delete tenants',
        CorePermissions::OUS_READ                     => 'View the organisational-unit tree, and the roles and members attached to each unit',
        CorePermissions::OUS_WRITE                    => 'Create and update organisational units',
        CorePermissions::OUS_DELETE                   => 'Delete an organisational unit',
        CorePermissions::OUS_ASSIGN                   => 'Attach roles to an organisational unit, and detach them again',
        CorePermissions::PERMISSIONS_READ             => 'See the permission catalogue and which permissions a role holds',
        CorePermissions::AUDIT_READ                   => 'Read the audit log — who did what, to what, and when',
        CorePermissions::PLUGINS_READ                 => 'List installed plugins and their lifecycle state',
        CorePermissions::PLUGINS_ENABLE               => 'Enable or re-enable a plugin',
        CorePermissions::PLUGINS_DISABLE              => 'Disable an active plugin',
        CorePermissions::PLUGINS_UPLOAD               => 'Upload and install a new plugin',
        CorePermissions::PLUGINS_UNINSTALL            => 'Uninstall a plugin (disable, roll back migrations, remove files)',
        CorePermissions::PLUGINS_RELOAD               => 'Reload the plugin registry',
        CorePermissions::DESKTOP_PLUGINS_READ         => 'Let an enrolled desktop device list and download desktop-plugin release packages',
        CorePermissions::DESKTOP_APP_UPDATES_READ     => 'Let an enrolled desktop device check whether a newer application build exists',
        CorePermissions::DELEGATION_MANAGE            => 'Create and revoke delegations: letting one person act with another\'s authority, for a time',
        CorePermissions::RELATIONS_READ               => 'Read family relations and persons',
        CorePermissions::RELATIONS_MANAGE             => 'Create, edit and delete family relations and persons',
        CorePermissions::SETTINGS_READ                => 'View website settings (effective and editable set)',
        CorePermissions::SETTINGS_WRITE               => 'Edit the current tenant\'s website-settings overrides',
        CorePermissions::SETTINGS_MANAGE              => 'Edit the global website-settings defaults',
        CorePermissions::MCP_TOKENS_MANAGE            => 'Mint and revoke MCP credentials — who may connect an AI client to this instance',
        CorePermissions::REGISTRATIONS_APPROVE        => 'Review and approve/reject pending self-service registrations (system tenant)',
        CorePermissions::AUTH_PROVIDERS_MANAGE        => 'Manage this tenant\'s identity-provider (SSO/OIDC) configurations',
        CorePermissions::ENTITLEMENTS_MANAGE          => 'Manage a target tenant\'s entitlements (operator, per subscription tier)',
        CorePermissions::STORAGE_MANAGE               => 'Manage this tenant\'s storage backend configuration',
        CorePermissions::PLANS_MANAGE                 => 'Manage subscription plans and apply them to tenants (operator)',
        CorePermissions::SUBSCRIPTIONS_MANAGE         => 'Manage a tenant\'s subscription / billing state (operator)',
        CorePermissions::DOCUMENTS_READ               => 'View and list document/label templates and blocks',
        CorePermissions::DOCUMENTS_WRITE              => 'Create, update and delete document/label templates and blocks',
        CorePermissions::DOCUMENTS_PUBLISH            => 'Publish a template/block tenant-wide or global, or set its required-permission tag',
        CorePermissions::DOCUMENTS_RENDER             => 'Render a document/label template server-side to PDF/PNG',
        CorePermissions::DOCUMENTS_READ_ALL           => 'See every issued document in the tenant, not only the ones you raised',
        CorePermissions::DOCUMENTS_ROUTE              => 'Put an issued document into circulation and choose the steps it follows',
        CorePermissions::SECURITY_MANAGE              => 'Set the tenant two-factor policy — who must enrol, and by when',
        CorePermissions::TAGS_READ                    => 'View tag groups, tags, an entity\'s tags, and filter entities by tag',
        CorePermissions::TAGS_MANAGE                  => 'Create/update/delete tag groups & tags and attach/detach tags to entities',
        CorePermissions::GROUPS_READ                  => 'See the tenant\'s user groups and how many people each one currently resolves to',
        CorePermissions::GROUPS_WRITE                 => 'Define, rename and delete user groups — the named rules that say which people a set contains',
        CorePermissions::ROUTE_TEMPLATES_READ         => 'See the tenant\'s document route templates and how many people each stage currently reaches',
        CorePermissions::ROUTE_TEMPLATES_WRITE        => 'Design, rename and delete document route templates — the reusable flows a document travels',
        CorePermissions::JOBS_SUBMIT                  => 'Submit async jobs via the API and read their status/result',
        CorePermissions::JOBS_READ                    => 'Read async job status, progress and result',
        CorePermissions::NOTIFICATIONS_MANAGE         => 'Administer notifications (templates, broadcasts) for the tenant',
        CorePermissions::NOTIFICATION_SETTINGS_MANAGE => 'Manage the tenant\'s notification sender configuration (from/reply-to, transport, credentials)',
        CorePermissions::PASSWORD_RESETS_APPROVE      => 'Review and approve/reject pending self-service password-reset requests (own tenant)',
        CorePermissions::TWO_FACTOR_RECOVERY_APPROVE  => 'Review and approve/reject "lost my 2FA device" recovery requests; clears the target profile\'s 2FA on approval (own tenant)',
        CorePermissions::LANGUAGES_MANAGE             => 'Create/update languages and toggle enabled/disabled (system tenant only)',
        CorePermissions::TRANSLATIONS_MANAGE          => 'Create/update/delete translation strings (system defaults or tenant overrides)',
        CorePermissions::TIME_WINDOWS_READ            => 'See the tenant\'s named periods, their boundaries, and whether each is open or closed',
        CorePermissions::TIME_WINDOWS_WRITE           => 'Define the tenant\'s period vocabulary, and create and adjust the periods themselves',
        CorePermissions::TIME_WINDOWS_CLOSE           => 'Close a period — seal it, after being told what it still holds unfinished',
        CorePermissions::TIME_WINDOWS_REOPEN          => 'Reopen a closed period, on the record, with a stated reason',
        CorePermissions::FORMS_READ                   => 'See the tenant\'s forms and the submissions made against them',
        CorePermissions::FORMS_MANAGE                 => 'Author forms — add and order fields, decide what is required, and set where submissions go',
        CorePermissions::FORMS_SUBMIT                 => 'Fill in and submit a published form, and read back one\'s own submissions',
        CorePermissions::CONVENING_READ               => 'See the tenant\'s convening bodies, their meetings, agendas and recorded decisions',
        CorePermissions::CONVENING_MANAGE             => 'Create and change convening bodies and their membership, build agendas, schedule ',
        CorePermissions::CONVENING_DECIDE             => 'Record a body\'s decision on an agenda item — the act that can approve or reject a ',
        ];
    }

    /**
     * The description for one slug, or null when it is not a core permission —
     * a plugin's own permissions are described by the plugin.
     */
    public static function for(string $slug): ?string
    {
        return self::all()[$slug] ?? null;
    }

    /** Static catalogue only — never instantiated. */
    private function __construct()
    {
    }
}
