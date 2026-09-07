import 'server-only';
import { cache } from 'react';
import { cookies, headers } from 'next/headers';
import { backendUrl } from '@/lib/backend-url';

/**
 * How this tenant wants its interface to present itself (#1068), resolved on
 * the SERVER so the first paint is already right.
 *
 * Mirrors `lib/branding.ts`, and for the same reason: a client-only fetch means
 * every screen renders its dates and then blanks them a moment later, and a
 * flash of information a tenant has asked to hide is not a cosmetic defect — it
 * is the setting being briefly false on every navigation.
 *
 * The request forwards the host, which is what the backend's host-resolver
 * needs, and the request's cookies. `React.cache` dedupes it within one
 * request.
 *
 * WHAT THIS CANNOT SEE, and it is worth being exact about because the docblock
 * that used to sit here was wrong: it does NOT resolve a signed-in reader's
 * tenant. `access_token` is scoped `Path=/api` ({@see CookieManager}), so a
 * browser fetching `/admin/anything` does not send it and `cookies()` never has
 * it — the same limitation `getBranding()` has, which is why the branding
 * handler documents host resolution as its practical path.
 *
 * So on a deployment whose hosts map to tenants (a custom `branding_host`, or a
 * slug subdomain of `BRANDING_BASE_DOMAIN`) this seed is the tenant's own
 * answer and the first paint is already right. On one where they do not, it is
 * the GLOBAL value, and `UiPreferencesProvider`'s own fetch — made by the
 * browser, to a path under `/api`, so it carries the cookie — corrects it in
 * one round trip. An operator who wants the setting to apply before that round
 * trip on every deployment sets it GLOBALLY as well as per-tenant.
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
