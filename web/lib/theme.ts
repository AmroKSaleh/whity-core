import 'server-only';
import { cache } from 'react';
import { cookies, headers } from 'next/headers';
import { backendUrl } from '@/lib/backend-url';

/** Token name -> '#rrggbb' hex value. Empty when no plugin contributes overrides. */
export type ThemeOverrides = Record<string, string>;

const HEX_COLOR_RE = /^#[0-9a-fA-F]{6}$/;

/**
 * Resolve effective theme color overrides server-side (WC-242), forwarding
 * the request host + auth cookie exactly like {@link getBranding} so the
 * backend resolves the same tenant. React.cache dedupes the call within one
 * request. The backend already validates every key/value (see
 * `ThemeApiHandler::sanitizeOverrides()`); this re-validates defensively —
 * never trust a network response blindly before it flows into a `<style>`
 * tag — and degrades to `{}` on any error, never breaking page render.
 */
export const getThemeOverrides = cache(async (): Promise<ThemeOverrides> => {
  try {
    const h = await headers();
    const cookieHeader = (await cookies())
      .getAll()
      .map((c) => `${c.name}=${c.value}`)
      .join('; ');
    const host = h.get('x-forwarded-host') ?? h.get('host') ?? '';

    const res = await fetch(`${backendUrl()}/api/v1/theme`, {
      headers: {
        cookie: cookieHeader,
        'X-Forwarded-Host': host,
      },
      cache: 'no-store',
    });
    if (!res.ok) return {};
    const body = (await res.json()) as { data?: unknown };
    const raw = body?.data;
    if (typeof raw !== 'object' || raw === null || Array.isArray(raw)) return {};

    const sanitized: ThemeOverrides = {};
    for (const [key, value] of Object.entries(raw as Record<string, unknown>)) {
      if (typeof value === 'string' && HEX_COLOR_RE.test(value)) {
        sanitized[key] = value;
      }
    }
    return sanitized;
  } catch {
    return {};
  }
});
