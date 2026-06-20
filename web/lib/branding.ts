import 'server-only';
import { cache } from 'react';
import { cookies, headers } from 'next/headers';
import { backendUrl } from '@/lib/backend-url';

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
 * Resolve effective branding server-side, forwarding the request host (so the
 * backend host-resolver can pick the tenant pre-auth) and the auth cookie (so an
 * authenticated request resolves to the JWT tenant). React.cache dedupes the
 * call between generateMetadata and the layout body within one request.
 */
export const getBranding = cache(async (): Promise<Branding> => {
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
    if (!res.ok) return FALLBACK;
    const body = (await res.json()) as { data?: { siteName?: unknown; logoWideUrl?: unknown; logoSquareUrl?: unknown; faviconUrl?: unknown } };
    const d = body?.data;
    if (!d || typeof d.siteName !== 'string') return FALLBACK;
    return {
      siteName: d.siteName,
      logoWideUrl: typeof d.logoWideUrl === 'string' ? d.logoWideUrl : null,
      logoSquareUrl: typeof d.logoSquareUrl === 'string' ? d.logoSquareUrl : null,
      faviconUrl: typeof d.faviconUrl === 'string' ? d.faviconUrl : null,
    };
  } catch {
    return FALLBACK;
  }
});
