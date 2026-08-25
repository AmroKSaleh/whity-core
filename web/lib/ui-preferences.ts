import 'server-only';
import { cache } from 'react';
import { cookies, headers } from 'next/headers';
import { backendUrl } from '@/lib/backend-url';

/**
 * How this tenant wants its interface to present itself (#1068), resolved on
 * the SERVER so the first paint is already right.
 *
 * Mirrors `lib/branding.ts` exactly, and for the same reason: the alternative is
 * a client fetch after hydration, which means every screen renders its dates,
 * then blanks them a moment later. A flash of information a tenant has asked to
 * hide is not a cosmetic defect — it is the setting being briefly false on every
 * navigation.
 *
 * The request forwards the host (so the backend's host-resolver can pick the
 * tenant before anyone has signed in) and the auth cookie (so an authenticated
 * request resolves to the JWT tenant). `React.cache` dedupes it within one
 * request.
 *
 * FAILS OPEN. Every error path returns today's behaviour rather than blanking
 * the interface — see UiPreferencesApiHandler for the argument, which is that
 * nothing here is a confidentiality control and the timestamps are on the wire
 * either way.
 */
export type UiPreferences = {
  hideDates: boolean;
};

const FALLBACK: UiPreferences = { hideDates: false };

export const getUiPreferences = cache(async (): Promise<UiPreferences> => {
  try {
    const h = await headers();
    const cookieHeader = (await cookies())
      .getAll()
      .map((c) => `${c.name}=${c.value}`)
      .join('; ');
    const host = h.get('x-forwarded-host') ?? h.get('host') ?? '';

    const res = await fetch(`${backendUrl()}/api/v1/ui/preferences`, {
      headers: {
        cookie: cookieHeader,
        'X-Forwarded-Host': host,
      },
      cache: 'no-store',
    });
    if (!res.ok) return FALLBACK;

    const body = (await res.json()) as { data?: { hideDates?: unknown } };

    return { hideDates: body?.data?.hideDates === true };
  } catch {
    return FALLBACK;
  }
});
