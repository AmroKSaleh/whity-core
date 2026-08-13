'use client';

import Link from 'next/link';
import { cn } from '@/lib/utils';
import { tabsListVariants } from '@amroksaleh/ui/tabs';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * Shared tab navigation for the unified "Approval Gating" admin surface
 * (WC-password-reset-2fa-recovery): Signup approvals (the pre-existing WC-235
 * pending-registrations queue, folded in as the first tab) / Password reset
 * approvals / 2FA auth reset approvals.
 *
 * Mirrors the STRUCTURAL pattern already established by
 * web/app/(protected)/admin/settings/settings-tabs.tsx — separate pages/routes
 * (each keeps its own RBAC gate and test suite), unified only by a shared,
 * `<Link>`-based tab bar styled with the same primitives.
 *
 * Deliberately SIMPLER than SettingsTabs: this bar is STATIC (three fixed
 * tabs, no `GET /api/v1/settings/tabs`-style backend visibility fetch). Each
 * destination page already enforces its own precise access gate server-side
 * AND renders its own `AccessDenied` fallback client-side (mirroring the
 * pre-existing registrations page), so an ineligible caller who follows a tab
 * they cannot use sees that fallback rather than silently missing a tab entry
 * — the same tradeoff the pre-existing top-level nav item already made
 * (visible to any 'admin'-role caller; the page underneath is the real gate).
 */

export type ApprovalGatingTabId = 'signup' | 'password-resets' | 'two-factor-recovery';

interface ApprovalGatingTabsProps {
  active: ApprovalGatingTabId;
}

/**
 * The tab labels reach `t()` through this table rather than as literals at the
 * call site, which no static scanner can read — so they are declared here and
 * the extractor takes the catalogue from this block. The English stays on the
 * record as the runtime fallback.
 *
 * @i18n-keys admin
 *   approvalGating.tab.signup = Signup
 *   approvalGating.tab.passwordResets = Password reset
 *   approvalGating.tab.twoFactorRecovery = 2FA auth reset
 */
export const TABS: { id: ApprovalGatingTabId; key: string; label: string; href: string }[] = [
  {
    id: 'signup',
    key: 'approvalGating.tab.signup',
    label: 'Signup',
    href: '/admin/registrations',
  },
  {
    id: 'password-resets',
    key: 'approvalGating.tab.passwordResets',
    label: 'Password reset',
    href: '/admin/approval-gating/password-resets',
  },
  {
    id: 'two-factor-recovery',
    key: 'approvalGating.tab.twoFactorRecovery',
    label: '2FA auth reset',
    href: '/admin/approval-gating/two-factor-recovery',
  },
];

export function ApprovalGatingTabs({ active }: ApprovalGatingTabsProps) {
  const t = useTranslation('admin');

  return (
    <nav
      aria-label={t('approvalGating.nav.label', 'Approval gating sections')}
      data-testid="approval-gating-tabs"
      className={cn(tabsListVariants({ variant: 'default' }), 'w-full flex-wrap md:w-fit')}
    >
      {TABS.map((tab) => {
        const isActive = tab.id === active;
        return (
          <Link
            key={tab.id}
            href={tab.href}
            data-testid={`approval-gating-tab-${tab.id}`}
            aria-current={isActive ? 'page' : undefined}
            className={cn(
              'relative inline-flex h-[calc(100%-1px)] flex-1 items-center justify-center gap-1.5 rounded-md border border-transparent px-3 py-1 text-xs font-medium whitespace-nowrap text-foreground/60 transition-all hover:text-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-1 focus-visible:outline-ring',
              isActive &&
                'bg-background text-foreground shadow-sm dark:border-input dark:bg-input/30 dark:text-foreground'
            )}
          >
            {t(tab.key, tab.label)}
          </Link>
        );
      })}
    </nav>
  );
}
