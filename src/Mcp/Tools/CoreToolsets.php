<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

/**
 * The toolsets every MCP operation belongs to, and core's always-on picks
 * (WHIT-747, docs/design/MCP-TOOL-SURFACE.md §5.1 and §5.2).
 *
 * A toolset groups operations for search, for token scope (`toolset:` entries,
 * WHIT-749) and for splits (WHIT-765). It never grants anything: who may call
 * an operation is still exposure, the token and RBAC.
 *
 * Core toolsets merge the OpenAPI tags into areas a person would name. A core
 * route takes its toolset from its first tag through {@see self::TAG_TOOLSET};
 * {@see self::PERMISSION_TOOLSET} wins over the tag where one area's routes are
 * tagged with another's (plugin management sits under `platform-ops`). A
 * route that belongs to a core sub-toolset names it itself, with
 * `schema['mcp']['toolset']` in CoreApiSchemas: the document theme routes are
 * tagged `documents` but sit in `core.documents.themes`.
 *
 * A plugin's operations default to the toolset named after the plugin
 * ({@see self::pluginToolsetId()}).
 *
 * One list for the owner to review: the always-on operations live here, not
 * scattered through CoreApiSchemas.
 */
final class CoreToolsets
{
    /** Where a core route lands when no tag or permission maps it; a test keeps this unused. */
    public const FALLBACK = 'core.platform';

    /**
     * id => title (English and Arabic) and one-sentence description (≤ 160 characters).
     *
     * @var array<string, array{title: string, titleAr: string, description: string, requiresEntitlement?: string}>
     */
    public const TOOLSETS = [
        'core.me' => [
            'title'       => 'My account',
            'titleAr'     => 'حسابي',
            'description' => 'Who you are signed in as, your permissions, emails and your own activity.',
        ],
        'core.search' => [
            'title'       => 'Search',
            'titleAr'     => 'البحث',
            'description' => 'Find documents, forms, people, units and plugin records you can see.',
        ],
        'core.inbox' => [
            'title'       => 'Inbox and notifications',
            'titleAr'     => 'الوارد والإشعارات',
            'description' => 'Items waiting for you and your notifications.',
        ],
        'core.people' => [
            'title'       => 'People and roles',
            'titleAr'     => 'الأشخاص والأدوار',
            'description' => 'Users, groups, organisational units, roles, invitations, delegations and relations.',
        ],
        'core.documents' => [
            'title'       => 'Documents',
            'titleAr'     => 'المستندات',
            'description' => 'Issued documents, templates and label sheets, collections, circulation routes and reports.',
        ],
        // Sub-toolset: its routes name it in CoreApiSchemas::documentTemplateSlotRoutes().
        'core.documents.automation' => [
            'title'       => 'Document automation',
            'titleAr'     => 'أتمتة المستندات',
            'description' => "Template slots: the places the workspace's plugins issue documents from, and which template fills each.",
        ],
        // Sub-toolset: its routes name it in CoreApiSchemas::documentThemeRoutes().
        'core.documents.themes' => [
            'title'       => 'Document themes',
            'titleAr'     => 'أنساق المستندات',
            'description' => "The workspace's document themes: print colour and letterhead, how a theme resolves, and where it is used.",
        ],
        'core.forms' => [
            'title'       => 'Forms',
            'titleAr'     => 'النماذج',
            'description' => 'Forms, their fields and their submissions.',
        ],
        'core.convening' => [
            'title'       => 'Meetings',
            'titleAr'     => 'الاجتماعات',
            'description' => 'Convening bodies, meetings, agendas and decisions.',
        ],
        'core.taxonomy' => [
            'title'       => 'Tags and data types',
            'titleAr'     => 'الوسوم وأنواع البيانات',
            'description' => 'Tag groups, tags and the records plugins declare as data types.',
        ],
        'core.time' => [
            'title'       => 'Time windows',
            'titleAr'     => 'الفترات الزمنية',
            'description' => 'Working hours, periods and other time windows.',
        ],
        'core.settings' => [
            'title'       => 'Workspace settings',
            'titleAr'     => 'إعدادات مساحة العمل',
            'description' => 'Settings, branding, theme, languages, translations and storage.',
        ],
        'core.billing' => [
            'title'       => 'Billing and plans',
            'titleAr'     => 'الفوترة والخطط',
            'description' => 'Invoices, payment methods, plans, subscriptions, entitlements and licences.',
        ],
        'core.security' => [
            'title'       => 'Security and sign-in',
            'titleAr'     => 'الأمان وتسجيل الدخول',
            'description' => 'Sign-in providers, SSO, two-factor policies, email domains and the audit log.',
        ],
        'core.plugins' => [
            'title'       => 'Plugins',
            'titleAr'     => 'الإضافات',
            'description' => 'Installed plugins and their versions.',
        ],
        'core.platform' => [
            'title'       => 'Platform',
            'titleAr'     => 'المنصة',
            'description' => 'Instance status, tenants and platform operations.',
        ],
    ];

    /** OpenAPI tag => core toolset. */
    public const TAG_TOOLSET = [
        'me'                       => 'core.me',
        'search'                   => 'core.search',
        'inbox'                    => 'core.inbox',
        'notifications'            => 'core.inbox',
        'users'                    => 'core.people',
        'user-groups'              => 'core.people',
        'roles'                    => 'core.people',
        'rbac'                     => 'core.people',
        'ous'                      => 'core.people',
        'invitations'              => 'core.people',
        'delegations'              => 'core.people',
        'relations'                => 'core.people',
        'documents'                => 'core.documents',
        'document-route-templates' => 'core.documents',
        'reports'                  => 'core.documents',
        'forms'                    => 'core.forms',
        'convening'                => 'core.convening',
        'taxonomy'                 => 'core.taxonomy',
        'data-types'               => 'core.taxonomy',
        'time-windows'             => 'core.time',
        'settings'                 => 'core.settings',
        'branding'                 => 'core.settings',
        'theme'                    => 'core.settings',
        'languages'                => 'core.settings',
        'frontend'                 => 'core.settings',
        'storage'                  => 'core.settings',
        'billing'                  => 'core.billing',
        'plans'                    => 'core.billing',
        'subscription'             => 'core.billing',
        'entitlements'             => 'core.billing',
        'licensing'                => 'core.billing',
        'auth'                     => 'core.security',
        'sso'                      => 'core.security',
        'security'                 => 'core.security',
        'email-domains'            => 'core.security',
        'audit-logs'               => 'core.security',
        'platform-ops'             => 'core.platform',
        'instance'                 => 'core.platform',
        'tenants'                  => 'core.platform',
    ];

    /** Permission resource => core toolset, taking precedence over the tag. */
    public const PERMISSION_TOOLSET = [
        'plugins' => 'core.plugins',
    ];

    /**
     * Core's always-on operations: read-only, everyday, listed directly next to
     * search and call (owner decision 4, 2026-10-31). "Search records" is
     * `GET /api/search` (WHIT-783). "Who am I" is answered by `init_session`
     * (WHIT-794), which is always-on beside these, so the set stays at five;
     * `get_api_me` is still found through search.
     */
    public const ALWAYS_ON = [
        'get_api_me_inbox',
        'get_api_documents_id',
        // The editor's templates (editor M4c replaced /api/document-templates).
        'get_api_document_sources',
        'get_api_search',
    ];

    /**
     * The core toolset a core route declaration maps to, or null when neither
     * its permission nor its first tag is mapped.
     *
     * @param array<string, mixed> $declaration
     */
    public static function mappedToolsetFor(array $declaration): ?string
    {
        $permission = $declaration['requiredPermission'] ?? null;
        if (is_string($permission) && $permission !== '') {
            $resource = strstr($permission, ':', true) ?: $permission;
            if (isset(self::PERMISSION_TOOLSET[$resource])) {
                return self::PERMISSION_TOOLSET[$resource];
            }
        }

        $schema = is_array($declaration['schema'] ?? null) ? $declaration['schema'] : [];
        $tags   = is_array($schema['tags'] ?? null) ? $schema['tags'] : [];
        $tag    = $tags[0] ?? null;

        return is_string($tag) ? (self::TAG_TOOLSET[$tag] ?? null) : null;
    }

    /** @param array<string, mixed> $declaration */
    public static function forCoreRoute(array $declaration): string
    {
        return self::mappedToolsetFor($declaration) ?? self::FALLBACK;
    }

    /**
     * The root toolset of a plugin, from the plugin's PHP namespace (the
     * `namespacePrefix` its routes carry): `Plugins\HelloWorld` gives
     * `hello-world`.
     */
    public static function pluginToolsetId(string $namespace): string
    {
        $segments = array_values(array_filter(explode('\\', $namespace), static fn (string $s): bool => $s !== ''));
        $name     = $segments === [] ? '' : $segments[count($segments) - 1];

        $kebab = strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $name));
        $kebab = trim((string) preg_replace('/[^a-z0-9_-]+/', '-', $kebab), '-');

        // `core` is reserved for core's own toolsets.
        return $kebab === '' ? 'plugin' : ($kebab === 'core' ? 'core-plugin' : $kebab);
    }

    /**
     * The same, from a plugin's class name (the key its authored tools are
     * registered under): `Plugins\HelloWorld\HelloWorldPlugin` gives
     * `hello-world`, because the class's namespace is the one its routes carry.
     */
    public static function pluginToolsetIdForClass(string $class): string
    {
        $class = trim($class, '\\');
        $cut   = strrpos($class, '\\');

        return self::pluginToolsetId($cut === false ? $class : substr($class, 0, $cut));
    }

    public static function isAlwaysOn(string $operationId): bool
    {
        return in_array($operationId, self::ALWAYS_ON, true);
    }
}
