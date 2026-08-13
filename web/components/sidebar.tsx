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
import { LanguageSwitcher, useI18nEnabled } from '@amroksaleh/features/i18n';
import * as TablerIcons from '@tabler/icons-react';
import {
  IconLogout,
  IconMenu2,
  IconX,
  IconChevronLeft,
  IconChevronRight,
  IconDashboard,
  IconUserCog,
  IconBuilding,
  IconWorld,
  IconSun,
  IconMoon,
} from '@tabler/icons-react';
import { useState, useEffect, useCallback, useMemo } from 'react';
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
          const message = err instanceof Error ? err.message : 'Couldn’t switch tenant';
          addToast(message, 'error');
        } finally {
          setIsSwitching(false);
        }
      })();
    },
    [activeTenantId, isSwitching, switchTenant, refreshNav, addToast],
  );

  return (
    <Switcher
      items={items}
      activeId={activeTenantId !== undefined ? String(activeTenantId) : undefined}
      onChange={handleSwitch}
      icon={<IconBuilding size={20} />}
      switchLabel="Tenant"
      emptyLabel="No tenant"
      collapsed={collapsed}
      disabled={isSwitching}
    />
  );
}

// ---------------------------------------------------------------------------

export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const { logout, user, memberships } = useAuth();
  const { items: navItemsFlat, getGroupedItems } = useNavigation();
  const branding = useBranding();
  const { resolved: resolvedTheme, toggle: toggleTheme } = useThemeMode();
  // Whether this instance offers a language choice at all (`i18n.enabled`).
  const isI18nEnabled = useI18nEnabled();
  const groupedItems = getGroupedItems();

  // The single most-specific nav item matching the current path (e.g. on
  // /admin/plugins/store, "Plugin Store" (/admin/plugins/store) wins over
  // "Plugins" (/admin/plugins) even though both prefix-match) — without this,
  // any parent-ish item whose href is a PREFIX of a more specific sibling's
  // href highlights alongside it. An exact match always wins outright; among
  // prefix matches, the longest href (most specific route) wins.
  const activeItemId = useMemo(() => {
    let bestId: string | null = null;
    let bestLength = -1;
    for (const item of navItemsFlat) {
      const hrefSegments = item.href.split('/').filter(Boolean).length;
      const isExact = pathname === item.href;
      const isPrefix = hrefSegments > 1 && pathname.startsWith(item.href + '/');
      if (!isExact && !isPrefix) continue;
      // An exact match is always at least as specific as any prefix match of
      // the same or shorter length, so scoring exact matches one tier higher
      // than the longest possible prefix length is sufficient.
      const score = item.href.length + (isExact ? 1_000_000 : 0);
      if (score > bestLength) {
        bestLength = score;
        bestId = item.id;
      }
    }
    return bestId;
  }, [navItemsFlat, pathname]);

  const [isOpen, setIsOpen] = useState(false);
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const handleResize = () => {
      const mobile = window.innerWidth < 768;
      setIsMobile(mobile);
      if (!mobile) {
        setIsOpen(true);
      }
    };

    handleResize();
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  const handleLogout = () => {
    logout();
    router.push('/login');
  };

  const toggleSidebar = () => {
    if (isMobile) {
      setIsOpen(!isOpen);
    } else {
      setIsCollapsed(!isCollapsed);
    }
  };

  const sidebarWidth = isCollapsed ? 'w-20' : 'w-64';

  return (
    <>
      {/* Mobile toggle button - only show on mobile */}
      <button
        onClick={toggleSidebar}
        className="fixed top-4 inset-s-4 z-50 md:hidden p-2 rounded-lg bg-background border border-border hover:bg-muted transition-colors"
        aria-label="Toggle sidebar"
      >
        {isOpen ? <IconX size={24} /> : <IconMenu2 size={24} />}
      </button>

      {/* Mobile overlay */}
      {isMobile && isOpen && (
        <div
          className="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 md:hidden"
          onClick={() => setIsOpen(false)}
        />
      )}

      {/* Sidebar */}
      <aside
        className={`
          transition-all duration-300 ease-in-out
          ${isMobile
            ? `fixed top-0 inset-s-0 h-screen ${sidebarWidth} bg-sidebar text-sidebar-foreground border-e border-sidebar-border flex flex-col z-40 ${
                isOpen ? 'translate-x-0' : 'ltr:-translate-x-full rtl:translate-x-full'
              }`
            : `relative h-screen ${sidebarWidth} bg-sidebar text-sidebar-foreground border-e border-sidebar-border flex flex-col`
          }
        `}
      >
        {/* Header with collapse button for desktop */}
        <div className={`border-b border-sidebar-border transition-all duration-300 flex items-center justify-between ${isCollapsed ? 'p-3' : 'p-6'}`}>
          <div className="flex-1">
            {!isCollapsed ? (
              <>
                {branding.logoWideUrl ? (
                  <img src={branding.logoWideUrl} alt={branding.siteName} className="h-8 w-auto max-w-[180px] object-contain" />
                ) : (
                  <h1 className="text-2xl font-bold">{branding.siteName}</h1>
                )}
                <p className="text-sm text-muted-foreground mt-1">Admin</p>
              </>
            ) : (
              branding.logoSquareUrl ? (
                <img src={branding.logoSquareUrl} alt={branding.siteName} className="h-8 w-8 object-contain mx-auto" />
              ) : (
                <div className="text-xl font-bold text-center font-black">{branding.siteName.charAt(0).toUpperCase()}</div>
              )
            )}
          </div>

          {/* Collapse/Expand button - only show on desktop */}
          {!isMobile && (
            <button
              onClick={() => setIsCollapsed(!isCollapsed)}
              className="p-1 hover:bg-background rounded transition-colors ms-2"
              title={isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            >
              {isCollapsed ? (
                <IconChevronRight size={20} />
              ) : (
                <IconChevronLeft size={20} />
              )}
            </button>
          )}
        </div>

        {/* Navigation */}
        <nav className="flex-1 p-2 space-y-3 overflow-y-auto">
          {Array.from(groupedItems.entries()).map(([groupId, navItems]) => {
            if (navItems.length === 0) return null;

            const isUngrouped = groupId === '_ungrouped';
            const groupLabel = groupId.charAt(0).toUpperCase() + groupId.slice(1);

            return (
              <div key={groupId}>
                {!isUngrouped && (
                  <div className={`text-xs font-semibold uppercase text-muted-foreground px-2 mb-2 ${isCollapsed && !isMobile ? 'text-center' : ''}`}>
                    {!isCollapsed && !isMobile ? groupLabel : ''}
                  </div>
                )}
                <div className="space-y-1">
                  {navItems.map((item, index) => {
                    const Icon = resolveIcon(item.icon);
                    const isActive = item.id === activeItemId;

                    return (
                      <Link
                        key={item.id}
                        href={item.href}
                        onClick={() => isMobile && setIsOpen(false)}
                      >
                        <Button
                          variant={isActive ? 'default' : 'ghost'}
                          size={isCollapsed && !isMobile ? 'icon' : 'default'}
                          className={`w-full ${isCollapsed && !isMobile ? 'justify-center' : 'justify-start'}`}
                          title={isCollapsed && !isMobile ? item.label : `${index + 1}. ${item.label}`}
                        >
                          <Icon size={20} className={isCollapsed && !isMobile ? '' : 'me-3 shrink-0'} />
                          {(!isCollapsed || isMobile) && (
                            <>
                              <span className="text-xs text-muted-foreground me-2 w-5">
                                {index + 1}
                              </span>
                              <span className="flex-1 text-start">{item.label}</span>
                            </>
                          )}
                        </Button>
                      </Link>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </nav>

        {/* Footer */}
        <div className={`border-t border-sidebar-border transition-all duration-300 ${isCollapsed ? 'p-2' : 'p-4'} space-y-2`}>
          {/*
            User menu: the "logged in as" footer doubles as the entry point to the
            self-service profile page (WC-64), which was previously orphaned (no
            nav link pointed to /settings). Linking it here guarantees the page is
            reachable regardless of the dynamic navigation set.
          */}
          {(!isCollapsed || isMobile) ? (
            <Link
              href="/settings"
              onClick={() => isMobile && setIsOpen(false)}
              aria-label="Account settings"
              className="flex items-center gap-2 px-2 py-2 bg-background rounded-lg text-center md:text-start hover:bg-background/70 transition-colors"
              title="Account settings"
            >
              <IconUserCog size={20} className="shrink-0 text-muted-foreground" />
              <span className="min-w-0">
                <span className="block text-xs text-muted-foreground truncate">Logged in as</span>
                <span className="block text-sm font-medium truncate">{user?.email}</span>
              </span>
            </Link>
          ) : (
            <Link
              href="/settings"
              aria-label="Account settings"
              className="flex justify-center px-2 py-2 bg-background rounded-lg hover:bg-background/70 transition-colors"
              title="Account settings"
            >
              <IconUserCog size={20} className="shrink-0 text-muted-foreground" />
            </Link>
          )}
          {/* Tenant switcher (WC-f8164c87) */}
          <TenantSwitcher
            memberships={memberships}
            activeTenantId={user?.tenant_id}
            collapsed={isCollapsed && !isMobile}
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
          {isI18nEnabled && (!isCollapsed || isMobile) && (
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
            size={isCollapsed && !isMobile ? 'icon' : 'default'}
            className={`w-full ${isCollapsed && !isMobile ? 'justify-center' : 'justify-start'}`}
            title={resolvedTheme === 'dark' ? 'Switch to light mode' : 'Switch to dark mode'}
            aria-label="Toggle color scheme"
            data-testid="theme-toggle"
          >
            {resolvedTheme === 'dark' ? (
              <IconSun size={20} className={isCollapsed && !isMobile ? '' : 'me-3 shrink-0'} />
            ) : (
              <IconMoon size={20} className={isCollapsed && !isMobile ? '' : 'me-3 shrink-0'} />
            )}
            {(!isCollapsed || isMobile) && (resolvedTheme === 'dark' ? 'Light mode' : 'Dark mode')}
          </Button>
          <Button
            onClick={handleLogout}
            variant="outline"
            size={isCollapsed && !isMobile ? 'icon' : 'default'}
            className={`w-full ${isCollapsed && !isMobile ? 'justify-center' : 'justify-start'}`}
            title={isCollapsed && !isMobile ? 'Logout' : undefined}
          >
            <IconLogout size={20} className={isCollapsed && !isMobile ? '' : 'me-3 shrink-0'} />
            {(!isCollapsed || isMobile) && 'Logout'}
          </Button>
        </div>
      </aside>
    </>
  );
}
