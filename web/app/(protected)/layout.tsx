'use client';

import { useEffect, useState } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { api } from '@/lib/api/client';
import { Sidebar } from '@/components/sidebar';
import { SETTINGS_MANAGE, SYSTEM_TENANT_ID } from './admin/settings/settings-shared';

export default function ProtectedLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  const { isLoading, user } = useAuth();
  const { hasPermission, loading: capsLoading } = useCapabilities();
  const router = useRouter();

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
  // /onboarding lives outside this layout and flips the flag on completion.
  const [firstRunChecked, setFirstRunChecked] = useState(false);
  useEffect(() => {
    if (isLoading || !isAuthenticated || capsLoading || firstRunChecked) {
      return;
    }
    const isOperator =
      user?.tenant_id === SYSTEM_TENANT_ID && hasPermission(SETTINGS_MANAGE);
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
  }, [
    isLoading,
    isAuthenticated,
    capsLoading,
    firstRunChecked,
    hasPermission,
    user,
    router,
  ]);

  if (isLoading) {
    return (
      <div className="flex items-center justify-center min-h-screen">
        <p className="text-lg">Loading...</p>
      </div>
    );
  }

  if (!isAuthenticated) {
    return null;
  }

  return (
    <div className="flex min-h-screen bg-background">
        {/* Sidebar - responsive widths handled in component */}
        <Sidebar />

        {/* Main Content */}
        <main className="flex-1 overflow-auto">
          <div className="p-6 md:p-8 max-w-7xl">
            {children}
          </div>
        </main>
      </div>
  );
}
