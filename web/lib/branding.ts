import 'server-only';
import { cache } from 'react';
import { cookies, headers } from 'next/headers';
import { backendUrl } from '@/lib/backend-url';
import { brandThemeCss } from '@/lib/brand-theme-css';

export type Branding = {
  siteName: string;
  logoWideUrl: string | null;
  logoSquareUrl: string | null;
  faviconUrl: string | null;
};

const FALLBACK: Branding = {
  siteName: 'Whity',
  logoWideUrl: null,
  logoSquareUrl: null,
  faviconUrl: null,
};

/**
 * The raw `GET /api/v1/branding` body, fetched server-side once per request.
 * Forwards the request host (so the backend host-resolver can pick the tenant
 * pre-auth) and the auth cookie (so an authenticated request resolves to the
 * JWT tenant). React.cache dedupes the call between generateMetadata, the
 * layout body and getBrandThemeCss within one request.
 */
const fetchBranding = cache(async (): Promise<Record<string, unknown> | null> => {
  try {
    const h = await headers();
    const cookieHeader = (await cookies())
      .getAll()
      .map((c) => `${c.name}=${c.value}`)
      .join('; ');
    const host = h.get('x-forwarded-host') ?? h.get('host') ?? '';

    const res = await fetch(`${backendUrl()}/api/v1/branding`, {
      headers: {
        cookie: cookieHeader,
        'X-Forwarded-Host': host,
      },
      cache: 'no-store',
    });
    if (!res.ok) return null;
    const body = (await res.json()) as { data?: unknown };
    const d = body?.data;
    return typeof d === 'object' && d !== null && !Array.isArray(d) ? (d as Record<string, unknown>) : null;
  } catch {
    return null;
  }
});

/** Resolve effective branding server-side. */
export const getBranding = cache(async (): Promise<Branding> => {
  const d = await fetchBranding();
  if (!d || typeof d.siteName !== 'string') return FALLBACK;
  return {
    siteName: d.siteName,
    logoWideUrl: typeof d.logoWideUrl === 'string' ? d.logoWideUrl : null,
    logoSquareUrl: typeof d.logoSquareUrl === 'string' ? d.logoSquareUrl : null,
    faviconUrl: typeof d.faviconUrl === 'string' ? d.faviconUrl : null,
  };
});

/** The main colour's CSS for this request's workspace (see brandThemeCss). */
export const getBrandThemeCss = cache(async (): Promise<string> => {
  const d = await fetchBranding();
  return d ? brandThemeCss(d.theme) : '';
});
