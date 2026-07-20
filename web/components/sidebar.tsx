'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import type { Membership } from '@/lib/auth-context';
import { useNavigation } from '@/lib/navigation-context';
import { useBranding } from '@/lib/branding-context';
import { useDirection } from '@/lib/direction-context';
import { useThemeMode } from '@/lib/theme-mode-context';
import { useToast } from '@/lib/toast-context';
import { Button } from '@amroksaleh/ui/button';
import {
  DropdownMenu,
  DropdownMenuTrigger,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
} from '@amroksaleh/ui/dropdown-menu';
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
  IconChevronDown,
  IconCheck,
  IconLanguage,
  IconSun,
  IconMoon,
} from '@tabler/icons-react';
import { useState, useEffect, useCallback } from 'react';
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
// TenantSwitcher — renders a dropdown when the profile has 2+ active
// memberships, or a plain label when there is only one (or zero).
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

  const activeMembership = memberships.find((m) => m.tenant_id === activeTenantId);
  const displayName = activeMembership?.tenant_name ?? 'No tenant';

  const handleSwitch = useCallback(
    async (tenantId: number) => {
      if (tenantId === activeTenantId || isSwitching) return;
      setIsSwitching(true);
      try {
        await switchTenant(tenantId);
        await refreshNav();
      } catch (err) {
        // A 403/401/network failure must not vanish silently: switchTenant()
        // rejects, the current tenant is unchanged, and we surface it. Prefer
        // the server's message when present, else a stable fallback.
        const message = err instanceof Error ? err.message : 'Couldn’t switch tenant';
        addToast(message, 'error');
      } finally {
        setIsSwitching(false);
      }
    },
    [activeTenantId, isSwitching, switchTenant, refreshNav, addToast],
  );

  // 0 or 1 membership — static label only.
  if (memberships.length < 2) {
    if (collapsed) {
      return (
        <div
          className="flex justify-center px-2 py-2 bg-background rounded-lg"
          title={displayName}
          aria-label={`Current tenant: ${displayName}`}
        >
          <IconBuilding size={20} className="text-muted-foreground shrink-0" />
        </div>
      );
    }
    return (
      <div className="flex items-center gap-2 px-2 py-2 bg-background rounded-lg">
        <IconBuilding size={20} className="shrink-0 text-muted-foreground" />
        <span className="min-w-0">
          <span className="block text-xs text-muted-foreground">Tenant</span>
          <span className="block text-sm font-medium truncate">{displayName}</span>
        </span>
      </div>
    );
  }

  // 2+ memberships — dropdown.
  if (collapsed) {
    return (
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <button
            className="flex justify-center px-2 py-2 bg-background rounded-lg hover:bg-background/70 transition-colors w-full"
            title={`Switch tenant (current: ${displayName})`}
            aria-label={`Switch tenant, current: ${displayName}`}
            disabled={isSwitching}
          >
            <IconBuilding size={20} className="text-muted-foreground shrink-0" />
          </button>
        </DropdownMenuTrigger>
        <DropdownMenuContent side="right" align="end">
          <DropdownMenuLabel>Switch tenant</DropdownMenuLabel>
          <DropdownMenuSeparator />
          {memberships.map((m) => (
            <DropdownMenuItem
              key={m.tenant_id}
              onSelect={() => { void handleSwitch(m.tenant_id); }}
              disabled={isSwitching}
            >
              {m.tenant_id === activeTenantId && (
                <IconCheck size={14} className="me-1 shrink-0" />
              )}
              <span className="truncate">{m.tenant_name}</span>
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    );
  }

  return (
    <DropdownMenu>
      <DropdownMenuTrigger asChild>
        <button
          className="flex items-center gap-2 px-2 py-2 bg-background rounded-lg hover:bg-background/70 transition-colors w-full text-start"
          aria-label={`Switch tenant, current: ${displayName}`}
          disabled={isSwitching}
        >
          <IconBuilding size={20} className="shrink-0 text-muted-foreground" />
          <span className="min-w-0 flex-1">
            <span className="block text-xs text-muted-foreground">Tenant</span>
            <span className="block text-sm font-medium truncate">{displayName}</span>
          </span>
          <IconChevronDown
            size={14}
            className="shrink-0 text-muted-foreground ms-auto"
            aria-hidden
          />
        </button>
      </DropdownMenuTrigger>
      <DropdownMenuContent side="top" align="start">
        <DropdownMenuLabel>Switch tenant</DropdownMenuLabel>
        <DropdownMenuSeparator />
        {memberships.map((m) => (
          <DropdownMenuItem
            key={m.tenant_id}
            onSelect={() => { void handleSwitch(m.tenant_id); }}
            disabled={isSwitching}
          >
            {m.tenant_id === activeTenantId && (
              <IconCheck size={14} className="me-1 shrink-0" />
            )}
            <span className="truncate">{m.tenant_name}</span>
          </DropdownMenuItem>
        ))}
      </DropdownMenuContent>
    </DropdownMenu>
  );
}

// ---------------------------------------------------------------------------

export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const { logout, user, memberships } = useAuth();
  const { getGroupedItems } = useNavigation();
  const branding = useBranding();
  const { dir, toggle: toggleDirection } = useDirection();
  const { resolved: resolvedTheme, toggle: toggleTheme } = useThemeMode();
  const groupedItems = getGroupedItems();
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
                    const hrefSegments = item.href.split('/').filter(Boolean).length;
                    const isActive = pathname === item.href ||
                      (pathname.startsWith(item.href + '/') && hrefSegments > 1);

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
          {/* Interface direction (LTR / RTL) — Arabic support (WC-rtl). */}
          <Button
            onClick={toggleDirection}
            variant="outline"
            size={isCollapsed && !isMobile ? 'icon' : 'default'}
            className={`w-full ${isCollapsed && !isMobile ? 'justify-center' : 'justify-start'}`}
            title={dir === 'rtl' ? 'Switch to left-to-right' : 'التبديل إلى العربية (RTL)'}
            aria-label="Toggle interface direction"
            data-testid="direction-toggle"
          >
            <IconLanguage size={20} className={isCollapsed && !isMobile ? '' : 'me-3 shrink-0'} />
            {(!isCollapsed || isMobile) && (dir === 'rtl' ? 'English (LTR)' : 'العربية (RTL)')}
          </Button>
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
