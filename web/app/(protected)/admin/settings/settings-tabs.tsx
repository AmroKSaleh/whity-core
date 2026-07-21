'use client';

import Link from 'next/link';
import { cn } from '@/lib/utils';
import { tabsListVariants } from '@amroksaleh/ui/tabs';
import { api } from '@/lib/api/client';
import { useFetch } from '@/hooks/useFetch';
import type { components } from '@/lib/api/schema';

/**
 * Shared tab navigation across the settings routes (WC-tabs-nav /
 * WC-tabs-nav-be).
 *
 * These stay SEPARATE PAGES/ROUTES (each has its own RBAC gate and an
 * extensive existing test suite built around that separation, notably the
 * WC-235 guarantee that system-wide defaults never render on the tenant
 * page) — this is a purely visual/navigational unification: a real tab bar
 * styled with the same primitives as {@link import('@amroksaleh/ui/tabs')},
 * where each "tab" is a `<Link>` to its own route rather than a
 * Radix-controlled panel.
 *
 * The tab SET and its per-caller visibility are fetched from
 * `GET /api/v1/settings/tabs` (RBAC-filtered server-side, mirroring the
 * sidebar's `/api/v1/navigation`) rather than computed here from a pile of
 * `show*` booleans threaded through every settings page. That prop-drilling
 * pattern had drifted inconsistent across pages (some passed
 * `showSignup={isSystemTenant}`, others a bare `showSignup` — same intent,
 * different derivations, one source of truth lost) — this component now
 * has exactly one job: render whatever the backend says is visible. Only
 * `active` (the current page's own tab id) still comes from the caller —
 * no `usePathname()`, keeping this dependency-free for the existing RTL
 * unit tests, which render each page directly without a router context.
 */

export type SettingsTabId = 'general' | 'branding' | 'signup' | 'sso' | 'email' | 'storage' | 'security';

interface SettingsTabsProps {
  active: SettingsTabId;
}

type SettingsTab = components['schemas']['SettingsTab'];

export function SettingsTabs({ active }: SettingsTabsProps) {
  const { data: tabs } = useFetch<SettingsTab[]>(async () => {
    const { data: body } = await api.GET('/api/v1/settings/tabs');
    return body?.data ?? [];
  }, []);

  const visible = tabs ?? [];

  // Nothing to switch between yet (still loading), or nothing the caller may
  // see beyond the current page (e.g. a regular tenant admin without
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
