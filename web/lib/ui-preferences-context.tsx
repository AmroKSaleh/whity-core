'use client';

/**
 * The app's DateDisplayProvider: seeded by the server render, refreshed when the
 * answer could have changed (#1068).
 *
 * WHY THIS IS NOT JUST `<DateDisplayProvider hidden={…}>`
 * ------------------------------------------------------
 * `ui.hide_dates` resolves PER TENANT, and `switchTenant()` deliberately does not
 * reload the page — it re-fetches `/api/v1/me` and lets the in-memory identity
 * change underneath the mounted tree. A value fixed at server-render time would
 * therefore survive the switch: a person moving from a tenant that hides dates
 * to one that does not would keep a blanked interface, and — the direction that
 * actually matters — a person moving INTO a tenant that hides dates would go on
 * seeing timestamps until they happened to hard-reload. That is exactly the "the
 * setting is 90% true" failure #1068 is written against, reached through session
 * state rather than through a missed call site.
 *
 * So this follows `CapabilitiesProvider`'s shape: one fetch, re-run only when the
 * identity that determines the answer changes (`user.id`, `user.tenant_id`), and
 * a sign-in or sign-out counted as such a change.
 *
 * It differs from CapabilitiesProvider in one deliberate way. That provider
 * RESETS to a fail-closed state before every re-fetch, because answering a
 * permission question with the previous identity's answer is a security bug.
 * Here the previous answer is kept until a new one arrives, because the failure
 * modes are not symmetric: this is a rendering preference, nothing behind it is
 * filtered, and flashing the whole interface's dates on and off during every
 * tenant switch would be a worse outcome than a few hundred milliseconds of the
 * previous tenant's preference. The SERVER's answer is the one that seeds the
 * first paint, so a fresh page load never shows the wrong thing at all.
 */

import { useEffect, useState, type ReactNode } from 'react';
import { DateDisplayProvider } from '@amroksaleh/features/datetime';

import { useAuth } from '@/lib/auth-context';
import type { UiPreferences } from '@/lib/ui-preferences';

export function UiPreferencesProvider({
  initial,
  children,
}: {
  initial: UiPreferences;
  children: ReactNode;
}) {
  const { user, apiClient } = useAuth();
  const [preferences, setPreferences] = useState<UiPreferences>(initial);

  // The stable identity driving refetches: a login, a logout, a profile switch
  // or an active-tenant switch changes one of these; a same-user token refresh
  // changes neither.
  const userId = user !== null ? user.id : null;
  const tenantId = user !== null ? user.tenant_id : null;

  // IT DOES NOT WAIT FOR AUTH TO RESOLVE, unlike every other auth-aware
  // provider in this shell, and the difference is the whole point of the
  // effect.
  //
  // The server render CANNOT see a signed-in reader's tenant on a page
  // request. `access_token` is scoped `Path=/api` (see CookieManager), so the
  // browser does not send it when fetching `/admin/anything` — Next's
  // `cookies()` never has it, and the SSR fetch falls through to host
  // resolution. On a deployment whose hosts map to tenants that is still the
  // right answer; on one where they do not, the seed is the GLOBAL value and
  // this fetch is what corrects it.
  //
  // Waiting for `isAuthLoading` made that correction take five to ten seconds
  // — a `/api/v1/me` round trip, sometimes a token refresh — and a browser
  // pass caught what that looks like: every date on the screen for five
  // seconds, on a tenant that had asked for none. There is nothing to wait
  // for. This request is made by the BROWSER, to a path under `/api`, so it
  // carries the cookie the server render could not see, and the endpoint is
  // public — it answers whether or not a session has been established yet.
  useEffect(() => {
    let cancelled = false;

    const load = async (): Promise<void> => {
      try {
        const response = await apiClient('/api/v1/ui/preferences');
        if (!response.ok) return;

        const body: unknown = await response.json();
        const hideDates =
          typeof body === 'object' &&
          body !== null &&
          'data' in body &&
          typeof (body as { data: unknown }).data === 'object' &&
          (body as { data: { hideDates?: unknown } }).data !== null &&
          (body as { data: { hideDates?: unknown } }).data.hideDates === true;

        if (!cancelled) setPreferences({ hideDates });
      } catch {
        // Keep whatever we have. The server already gave us this tenant's
        // answer at render time, and a transient network failure is not a
        // reason to change how the whole interface looks.
      }
    };

    void load();

    return () => {
      cancelled = true;
    };
  }, [userId, tenantId, apiClient]);

  return <DateDisplayProvider hidden={preferences.hideDates}>{children}</DateDisplayProvider>;
}
