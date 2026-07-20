'use client';

import Link from 'next/link';
import { cn } from '@/lib/utils';
import { tabsListVariants } from '@amroksaleh/ui/tabs';

/**
 * Shared tab navigation across the four settings routes (WC-tabs-nav).
 *
 * These stay SEPARATE PAGES/ROUTES (each has its own RBAC gate and an
 * extensive existing test suite built around that separation, notably the
 * WC-235 guarantee that global defaults never render on the tenant page) —
 * this is a purely visual/navigational unification: a real tab bar styled
 * with the same primitives as {@link import('@amroksaleh/ui/tabs')}, where
 * each "tab" is a `<Link>` to its own route rather than a Radix-controlled
 * panel. Replaces the old buried inline "→" link-cards, which is what made
 * jumping between Website Settings / Global / Email / SSO hard to discover.
 *
 * Each page passes its own `active` id (no `usePathname()` — keeps this
 * dependency-free for the existing RTL unit tests, which render each page
 * directly without a router context) and the three conditional tabs'
 * visibility, computed the SAME way each page already gated its removed
 * inline link (so behavior is unchanged, only the presentation is).
 */

export type SettingsTabId = 'general' | 'global' | 'email' | 'sso';

interface SettingsTabsProps {
  active: SettingsTabId;
  /** Mirrors the Website Settings page's prior `isSystemTenant` link gate. */
  showGlobal: boolean;
  /** Mirrors the Global Settings page's prior inline Email link (system-tenant only). */
  showEmail: boolean;
  /** Mirrors the Website Settings page's prior `canManageProviders` link gate. */
  showSso: boolean;
}

const TAB_DEFS: ReadonlyArray<{ id: SettingsTabId; href: string; label: string }> = [
  { id: 'general', href: '/admin/settings', label: 'General' },
  { id: 'global', href: '/admin/settings/global', label: 'Global defaults' },
  { id: 'email', href: '/admin/settings/email', label: 'Email' },
  { id: 'sso', href: '/admin/settings/sso', label: 'Single sign-on' },
];

export function SettingsTabs({ active, showGlobal, showEmail, showSso }: SettingsTabsProps) {
  const visible = TAB_DEFS.filter((tab) => {
    if (tab.id === 'global') return showGlobal;
    if (tab.id === 'email') return showEmail;
    if (tab.id === 'sso') return showSso;
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
      className={cn(tabsListVariants({ variant: 'default' }), 'w-full md:w-fit')}
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
