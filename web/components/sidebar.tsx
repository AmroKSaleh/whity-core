'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import type { Membership } from '@/lib/auth-context';
import { useNavigation } from '@/lib/navigation-context';
import { useBranding } from '@/lib/branding-context';
import { useThemeMode } from '@/lib/theme-mode-context';
import { useToast } from '@/lib/toast-context';
import { Button } from '@amroksaleh/ui/button';
import { Switcher } from '@amroksaleh/ui/switcher';
import { AppSidebar } from '@amroksaleh/ui/app-sidebar';
import { navGroupsFromServerItems } from '@amroksaleh/features/nav';
import { LanguageSwitcher, useI18nEnabled, useTranslation } from '@amroksaleh/features/i18n';
import * as TablerIcons from '@tabler/icons-react';
import {
  IconLogout,
  IconDashboard,
  IconUserCog,
  IconBuilding,
  IconWorld,
  IconSun,
  IconMoon,
} from '@tabler/icons-react';
import { useState, useCallback, useMemo } from 'react';
import type { Icon } from '@tabler/icons-react';

/**
 * Resolve a navigation `icon` name to a Tabler icon component.
 *
 * Core nav items emit kebab-case names (e.g. `"building-community"`), but
 * plugins may supply any Tabler icon by its kebab/snake name or its full
 * PascalCase component name (e.g. `"IconUsers"`). We normalize the name to the
 * `Icon<PascalCase>` export and look it up dynamically against the full
 * `@tabler/icons-react` set, falling back to a safe default for unknown names
 * so a plugin can never render a missing-icon hole.
 */
const tablerIcons = TablerIcons as unknown as Record<string, Icon | undefined>;

function resolveIcon(name: string | undefined): Icon {
  if (!name) {
    return IconDashboard;
  }

  // Split on hyphen/underscore/whitespace, capitalize each segment, join.
  const pascal = name
    .trim()
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');

  const componentName = pascal.startsWith('Icon') ? pascal : `Icon${pascal}`;

  return tablerIcons[componentName] ?? IconDashboard;
}

/**
 * The order the nav's groups appear in, and the only place that sequence is
 * decided. The server says which group each page belongs to (`group` on every
 * `navigation.register` item) and where it sits WITHIN that group (`order`);
 * it deliberately does not say which group comes first, because that is a
 * layout question and the answer differs per client — a Flutter nav rail may
 * want the same pages in a different sequence.
 *
 * A group id missing from this list still renders, after the declared ones —
 * a plugin inventing its own group appears rather than vanishing. `plugins` is
 * listed because `PluginNavigationBridge` defaults every plugin-contributed
 * feature to it, so it is a known-but-not-core group.
 */
const NAV_GROUP_ORDER = [
  'overview',
  'access',
  'documents',
  'records',
  'extend',
  'system',
  'plugins',
] as const;

/** Fallback heading for a group the shell has no translated label for. */
function prettifyGroupId(groupId: string): string {
  return groupId
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join(' ');
}

// ---------------------------------------------------------------------------
// TenantSwitcher — uses the shared Switcher primitive from @amroksaleh/ui.
// ---------------------------------------------------------------------------

interface TenantSwitcherProps {
  /** The profile's active memberships (from auth-context). */
  memberships: Membership[];
  /** The currently active tenant_id (from user.tenant_id). */
  activeTenantId: number | undefined;
  /** Whether the sidebar is in icon-only (collapsed) mode. */
  collapsed: boolean;
}

function TenantSwitcher({ memberships, activeTenantId, collapsed }: TenantSwitcherProps) {
  const { switchTenant } = useAuth();
  const { refresh: refreshNav } = useNavigation();
  const { addToast } = useToast();
  const t = useTranslation('common');
  const [isSwitching, setIsSwitching] = useState(false);

  const items = useMemo(
    () => memberships.map((m) => ({ id: String(m.tenant_id), label: m.tenant_name })),
    [memberships],
  );

  const handleSwitch = useCallback(
    (idStr: string) => {
      const tenantId = Number(idStr);
      if (tenantId === activeTenantId || isSwitching) return;
      setIsSwitching(true);
      void (async () => {
        try {
          await switchTenant(tenantId);
          await refreshNav();
        } catch (err) {
          const message =
            err instanceof Error
              ? err.message
              : t('sidebar.tenantSwitcher.error', 'Couldn’t switch tenant');
          addToast(message, 'error');
        } finally {
          setIsSwitching(false);
        }
      })();
    },
    [activeTenantId, isSwitching, switchTenant, refreshNav, addToast, t],
  );

  return (
    <Switcher
      items={items}
      activeId={activeTenantId !== undefined ? String(activeTenantId) : undefined}
      onChange={handleSwitch}
      icon={<IconBuilding size={20} />}
      switchLabel={t('sidebar.tenantSwitcher.label', 'Tenant')}
      emptyLabel={t('sidebar.tenantSwitcher.empty', 'No tenant')}
      collapsed={collapsed}
      disabled={isSwitching}
    />
  );
}

// ---------------------------------------------------------------------------

/**
 * The app's composed sidebar: it owns the DATA (nav items from
 * `/api/navigation`, branding, tenant memberships, theme/language controls)
 * and hands the CHROME to `AppSidebar` from the UI kit — the split that
 * component's own docblock describes. Collapse/expand, the mobile drawer,
 * group disclosure, the filter box and RTL mirroring all live there, so the
 * Tauri desktop client gets the same behaviour from the same code.
 */
export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const { logout, user, memberships } = useAuth();
  const { items: navItems } = useNavigation();
  const branding = useBranding();
  const { resolved: resolvedTheme, toggle: toggleTheme } = useThemeMode();
  const t = useTranslation('common');
  // Whether this instance offers a language choice at all (`i18n.enabled`).
  const isI18nEnabled = useI18nEnabled();

  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isMobileOpen, setIsMobileOpen] = useState(false);

  // The icon rail is a DESKTOP mode: the mobile drawer is full-width, so a
  // collapse chosen on a wide viewport must not follow the user into it and
  // leave them with unlabelled icons in a 16rem-wide sheet.
  const collapsed = isCollapsed && !isMobileOpen;

  // Literal `t()` calls, not a computed key — `i18n:extract` rebuilds the
  // catalogue by scanning the source for them, so a dynamic
  // `t('sidebar.group.' + id)` would never make it into the translations
  // table and every heading would render as a raw key.
  const groupLabels = useMemo<Record<string, string>>(
    () => ({
      overview: t('sidebar.group.overview', 'Overview'),
      access: t('sidebar.group.access', 'Access'),
      documents: t('sidebar.group.documents', 'Documents'),
      records: t('sidebar.group.records', 'Records'),
      extend: t('sidebar.group.extend', 'Extend'),
      system: t('sidebar.group.system', 'System'),
      plugins: t('sidebar.group.plugins', 'Plugins'),
    }),
    [t],
  );

  // The same literal-`t()` discipline as the group headings above, for the
  // same reason and one more: an item's `label` arrives from
  // `GET /api/v1/navigation`, where it is a hardcoded English string in the
  // registry rather than a `t()` call. Nothing scans that registry, so none of
  // these names has ever reached the catalogue — which is why an Arabic sidebar
  // used to read its GROUP headings in Arabic and every PAGE name under them in
  // English.
  //
  // Keyed by the server's stable item `id`, never by its English text, so
  // rewording a label upstream is a translation edit and not a key rename. An
  // id this map does not know keeps the server's label: that is the case for
  // every plugin-contributed entry, whose name is a plugin's to give.
  const itemLabels = useMemo<Record<string, string>>(
    () => ({
      dashboard: t('sidebar.item.dashboard', 'Dashboard'),
      inbox: t('sidebar.item.inbox', 'Inbox'),
      users: t('sidebar.item.users', 'Users'),
      roles: t('sidebar.item.roles', 'Roles'),
      ous: t('sidebar.item.ous', 'Organizational Units'),
      delegations: t('sidebar.item.delegations', 'Delegations'),
      relations: t('sidebar.item.relations', 'Family Relations'),
      'tag-groups': t('sidebar.item.tagGroups', 'Tag Groups'),
      tags: t('sidebar.item.tags', 'Tags'),
      tenants: t('sidebar.item.tenants', 'Tenants'),
      'audit-logs': t('sidebar.item.auditLogs', 'Audit Logs'),
      errors: t('sidebar.item.errors', 'Errors'),
      plugins: t('sidebar.item.plugins', 'Plugins'),
      'plugin-store': t('sidebar.item.pluginStore', 'Plugin Store'),
      'website-settings': t('sidebar.item.websiteSettings', 'Website Settings'),
      documents: t('sidebar.item.documentDesigner', 'Document Designer'),
      'document-library': t('sidebar.item.documentLibrary', 'Documents'),
      'document-templates': t('sidebar.item.documentTemplates', 'Templates & Blocks'),
      'approval-gating': t('sidebar.item.approvalGating', 'Approval Gating'),
      'ai-principals': t('sidebar.item.aiPrincipals', 'AI Principals'),
      'mcp-tools': t('sidebar.item.mcpTools', 'MCP Tools'),
      languages: t('sidebar.item.languages', 'Languages'),
      translations: t('sidebar.item.translations', 'Translations'),
      settings: t('sidebar.item.settings', 'Settings'),
    }),
    [t],
  );

  const groups = useMemo(
    () =>
      navGroupsFromServerItems(navItems, {
        currentPath: pathname,
        groupOrder: NAV_GROUP_ORDER,
        groupLabel: (groupId) => groupLabels[groupId] ?? prettifyGroupId(groupId),
        itemLabel: (itemId) => itemLabels[itemId],
        renderIcon: (icon) => {
          const IconComponent = resolveIcon(icon);
          return <IconComponent size={20} />;
        },
      }),
    [navItems, pathname, groupLabels, itemLabels],
  );

  const handleLogout = () => {
    logout();
    router.push('/login');
  };

  const header = collapsed ? (
    branding.logoSquareUrl ? (
      <img
        src={branding.logoSquareUrl}
        alt={branding.siteName}
        className="mx-auto h-8 w-8 object-contain"
      />
    ) : (
      <div className="text-center text-xl font-black">
        {branding.siteName.charAt(0).toUpperCase()}
      </div>
    )
  ) : (
    <>
      {branding.logoWideUrl ? (
        <img
          src={branding.logoWideUrl}
          alt={branding.siteName}
          className="h-8 w-auto max-w-[180px] object-contain"
        />
      ) : (
        <h1 className="text-2xl font-bold">{branding.siteName}</h1>
      )}
      <p className="mt-1 text-sm text-muted-foreground">{t('sidebar.subtitle', 'Admin')}</p>
    </>
  );

  const footer = (
    <div className="space-y-2">
      {/*
        User menu: the "logged in as" footer doubles as the entry point to the
        self-service profile page (WC-64), which was previously orphaned (no
        nav link pointed to /settings). Linking it here guarantees the page is
        reachable regardless of the dynamic navigation set.
      */}
      {!collapsed ? (
        <Link
          href="/settings"
          onClick={() => setIsMobileOpen(false)}
          aria-label={t('sidebar.accountSettings', 'Account settings')}
          className="flex items-center gap-2 rounded-lg bg-background px-2 py-2 text-center transition-colors hover:bg-background/70 md:text-start"
          title={t('sidebar.accountSettings', 'Account settings')}
        >
          <IconUserCog size={20} className="shrink-0 text-muted-foreground" />
          <span className="min-w-0">
            <span className="block truncate text-xs text-muted-foreground">
              {t('sidebar.loggedInAs', 'Logged in as')}
            </span>
            <span className="block truncate text-sm font-medium">{user?.email}</span>
          </span>
        </Link>
      ) : (
        <Link
          href="/settings"
          aria-label={t('sidebar.accountSettings', 'Account settings')}
          className="flex justify-center rounded-lg bg-background px-2 py-2 transition-colors hover:bg-background/70"
          title={t('sidebar.accountSettings', 'Account settings')}
        >
          <IconUserCog size={20} className="shrink-0 text-muted-foreground" />
        </Link>
      )}
      {/* Tenant switcher (WC-f8164c87) */}
      <TenantSwitcher
        memberships={memberships}
        activeTenantId={user?.tenant_id}
        collapsed={collapsed}
      />
      {/*
        Interface language. This is ALSO the direction control: each
        language carries its own writing direction, so choosing Arabic
        mirrors the interface and choosing English un-mirrors it (see
        lib/direction-context.tsx). There is deliberately no separate
        direction toggle — a language and a direction that disagree is not
        a state a user can usefully be in, and the pair used to drift
        apart. The choice is stored on the profile, so it follows the user
        across devices.

        The WHOLE ROW — frame, globe icon and control — is gated on
        `i18n.enabled`. The switcher self-suppresses too, but this wrapper
        has to ask as well: otherwise an instance with i18n off would show
        an empty bordered box with a globe in it, which is a worse
        affordance than the switcher was.
      */}
      {isI18nEnabled && !collapsed && (
        <div
          className="flex w-full items-center gap-2 rounded-lg border border-input bg-input/20 px-3 py-2"
          data-testid="language-switcher"
        >
          <IconWorld size={20} className="shrink-0 text-muted-foreground" />
          <LanguageSwitcher
            variant="dropdown"
            className="h-7 min-w-0 flex-1 cursor-pointer border-0 bg-transparent text-sm font-medium text-foreground outline-none"
          />
        </div>
      )}
      {/* Light / dark color scheme (see lib/theme-mode-context.tsx). */}
      <Button
        onClick={toggleTheme}
        variant="outline"
        size={collapsed ? 'icon' : 'default'}
        className={`w-full ${collapsed ? 'justify-center' : 'justify-start'}`}
        title={
          resolvedTheme === 'dark'
            ? t('sidebar.theme.switchToLight', 'Switch to light mode')
            : t('sidebar.theme.switchToDark', 'Switch to dark mode')
        }
        aria-label={t('sidebar.theme.toggle', 'Toggle color scheme')}
        data-testid="theme-toggle"
      >
        {resolvedTheme === 'dark' ? (
          <IconSun size={20} className={collapsed ? '' : 'me-3 shrink-0'} />
        ) : (
          <IconMoon size={20} className={collapsed ? '' : 'me-3 shrink-0'} />
        )}
        {!collapsed &&
          (resolvedTheme === 'dark'
            ? t('sidebar.theme.light', 'Light mode')
            : t('sidebar.theme.dark', 'Dark mode'))}
      </Button>
      <Button
        onClick={handleLogout}
        variant="outline"
        size={collapsed ? 'icon' : 'default'}
        className={`w-full ${collapsed ? 'justify-center' : 'justify-start'}`}
        title={collapsed ? t('sidebar.logout', 'Logout') : undefined}
      >
        <IconLogout size={20} className={collapsed ? '' : 'me-3 shrink-0'} />
        {!collapsed && t('sidebar.logout', 'Logout')}
      </Button>
    </div>
  );

  return (
    <AppSidebar
      groups={groups}
      header={header}
      footer={footer}
      collapsed={collapsed}
      onCollapsedChange={setIsCollapsed}
      mobileOpen={isMobileOpen}
      onMobileOpenChange={setIsMobileOpen}
      linkComponent={Link}
      collapsibleGroups
      searchable
      searchPlaceholder={t('sidebar.search.placeholder', 'Search pages')}
      clearSearchLabel={t('sidebar.search.clear', 'Clear search')}
      searchNoResultsLabel={t('sidebar.search.noResults', 'No matching pages')}
      navLabel={t('sidebar.navLabel', 'Main')}
      openNavLabel={t('sidebar.openNav', 'Open navigation')}
      closeNavLabel={t('sidebar.closeNav', 'Close navigation')}
      collapseLabel={t('sidebar.collapseShort', 'Collapse')}
      collapseAriaLabel={t('sidebar.collapse', 'Collapse sidebar')}
      expandAriaLabel={t('sidebar.expand', 'Expand sidebar')}
    />
  );
}
