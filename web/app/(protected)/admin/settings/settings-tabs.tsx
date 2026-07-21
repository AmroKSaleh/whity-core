'use client';

import Link from 'next/link';
import { cn } from '@/lib/utils';
import { tabsListVariants } from '@amroksaleh/ui/tabs';

/**
 * Shared tab navigation across the settings routes (WC-tabs-nav).
 *
 * These stay SEPARATE PAGES/ROUTES (each has its own RBAC gate and an
 * extensive existing test suite built around that separation, notably the
 * WC-235 guarantee that system-wide defaults never render on the tenant
 * page) — this is a purely visual/navigational unification: a real tab bar
 * styled with the same primitives as {@link import('@amroksaleh/ui/tabs')},
 * where each "tab" is a `<Link>` to its own route rather than a
 * Radix-controlled panel.
 *
 * Each page passes its own `active` id (no `usePathname()` — keeps this
 * dependency-free for the existing RTL unit tests, which render each page
 * directly without a router context) and the conditional tabs' visibility,
 * computed the SAME way each page already gated the content it owns.
 *
 * General and Branding are always shown (any settings:read caller). Sign-up,
 * Email, and Storage are system-tenant-only surfaces (mirroring the removed
 * inline links' `isSystemTenant` gate); Single sign-on is shown to whoever
 * holds `auth_providers:manage`, tenant or system. Security (admin-enforced
 * 2FA policy, WC-525) is tenant-scoped self-service like Storage's
 * `storage:manage` — shown to whoever holds `security:manage`, tenant or
 * system.
 */

export type SettingsTabId = 'general' | 'branding' | 'signup' | 'sso' | 'email' | 'storage' | 'security';

interface SettingsTabsProps {
  active: SettingsTabId;
  showSignup: boolean;
  showEmail: boolean;
  showStorage: boolean;
  showSso: boolean;
  showSecurity: boolean;
}

const TAB_DEFS: ReadonlyArray<{ id: SettingsTabId; href: string; label: string }> = [
  { id: 'general', href: '/admin/settings', label: 'General' },
  { id: 'branding', href: '/admin/settings/branding', label: 'Branding' },
  { id: 'signup', href: '/admin/settings/signup', label: 'Sign-up' },
  { id: 'sso', href: '/admin/settings/sso', label: 'Single sign-on' },
  { id: 'email', href: '/admin/settings/email', label: 'Email' },
  { id: 'storage', href: '/admin/settings/storage', label: 'Storage' },
  { id: 'security', href: '/admin/settings/security', label: 'Security' },
];

export function SettingsTabs({ active, showSignup, showEmail, showStorage, showSso, showSecurity }: SettingsTabsProps) {
  const visible = TAB_DEFS.filter((tab) => {
    if (tab.id === 'signup') return showSignup;
    if (tab.id === 'email') return showEmail;
    if (tab.id === 'storage') return showStorage;
    if (tab.id === 'sso') return showSso;
    if (tab.id === 'security') return showSecurity;
    return true;
  });

  // Nothing to switch between (e.g. a regular tenant admin without
  // auth_providers:manage) — no point rendering a single-item tab bar.
  if (visible.length <= 1) {
    return null;
  }

  return (
    <nav
      aria-label="Settings sections"
      data-testid="settings-tabs"
      className={cn(tabsListVariants({ variant: 'default' }), 'w-full flex-wrap md:w-fit')}
    >
      {visible.map((tab) => {
        const isActive = tab.id === active;
        return (
          <Link
            key={tab.id}
            href={tab.href}
            data-testid={`settings-tab-${tab.id}`}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
              'relative inline-flex h-[calc(100%-1px)] flex-1 items-center justify-center gap-1.5 rounded-md border border-transparent px-3 py-1 text-xs font-medium whitespace-nowrap text-foreground/60 transition-all hover:text-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-1 focus-visible:outline-ring',
              isActive &&
                'bg-background text-foreground shadow-sm dark:border-input dark:bg-input/30 dark:text-foreground'
            )}
          >
            {tab.label}
          </Link>
        );
      })}
    </nav>
  );
}
