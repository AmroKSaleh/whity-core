'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { api } from '@/lib/api/client';
import { useTranslation } from '@amroksaleh/features/i18n';
import { SETTINGS_MANAGE, SYSTEM_TENANT_ID } from '@/app/(protected)/admin/settings/settings-shared';

/**
 * The signed-in gate every authenticated route needs: bounce anonymous callers
 * to /login, and route the OPERATOR into first-run setup until the instance
 * reports configured.
 *
 * Extracted from `app/(protected)/layout.tsx` so a SECOND layout can reuse it
 * without restating the rules — specifically `app/(editor)/layout.tsx`, whose
 * full-screen chrome deliberately drops the sidebar and the padded/max-width
 * content column that `(protected)` imposes. Two layouts, one gate: an
 * authentication rule must never depend on which shell a page happens to use.
 *
 * Renders `children` only once the caller is known to be authenticated.
 */
export function AuthGate({ children }: { children: React.ReactNode }) {
  const { isLoading, user } = useAuth();
  const { hasPermission, loading: capsLoading } = useCapabilities();
  const router = useRouter();
  const t = useTranslation('common');

  const isAuthenticated = !!user;

  useEffect(() => {
    if (!isLoading && !isAuthenticated) {
      router.push('/login');
    }
  }, [isLoading, isAuthenticated, router]);

  // First-run funnel (WC-instance-first-run): route the OPERATOR — the system
  // tenant (id 0) account holding settings:manage — into the guided onboarding
  // wizard until the instance reports configured. Every other caller is left
  // untouched: an unconfigured instance never blocks normal use, it only nudges
  // the one account that can actually complete first-run setup. The check runs
  // once per mount and only redirects on an explicit `configured === false`
  // (never while the status is still loading), so it can't bounce or loop —
  // /onboarding lives outside this gate and flips the flag on completion.
  const [firstRunChecked, setFirstRunChecked] = useState(false);
  useEffect(() => {
    if (isLoading || !isAuthenticated || capsLoading || firstRunChecked) {
      return;
    }
    const isOperator = user?.tenant_id === SYSTEM_TENANT_ID && hasPermission(SETTINGS_MANAGE);
    if (!isOperator) {
      return;
    }

    let cancelled = false;
    void (async () => {
      const { data } = await api.GET('/api/v1/instance/status');
      if (cancelled) {
        return;
      }
      setFirstRunChecked(true);
      if (data?.configured === false) {
        router.replace('/onboarding');
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [isLoading, isAuthenticated, capsLoading, firstRunChecked, hasPermission, user, router]);

  if (isLoading) {
    return (
      <div className="flex min-h-screen items-center justify-center">
        <p className="text-lg">{t('authGate.loading', 'Loading...')}</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  return <>{children}</>;
}
