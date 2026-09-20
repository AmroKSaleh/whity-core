'use client';

import { useEffect } from 'react';
import { usePathname } from 'next/navigation';
import { captureReferral } from '@/lib/referral';

/**
 * Notices an affiliate link, wherever in the app it lands.
 *
 * ── Why this sits in the root layout and not on the form ───────────────────
 *
 * An affiliate points their link at whatever page makes their case — a feature
 * page, a pricing page, the front door — and almost nobody registers on the
 * page they arrive at. Capturing only where the form lives would credit the
 * affiliate for exactly the customers who signed up without thinking about it,
 * and nobody else.
 *
 * ── It never clears anything ───────────────────────────────────────────────
 *
 * Ordinary navigation carries no `?ref=`, so a component that wrote on every
 * route change would erase the code on the first click after arriving. The
 * capture only ever writes when there is something to write; forgetting is done
 * once, deliberately, after a workspace exists.
 *
 * ── window.location, not useSearchParams ───────────────────────────────────
 *
 * `useSearchParams` opts the whole subtree into client-side rendering unless it
 * is wrapped in Suspense, and this is mounted at the root of every page. Reading
 * the location inside an effect costs nothing and runs at exactly the moment the
 * URL is real.
 *
 * Renders nothing.
 */
export function ReferralCapture() {
  // The pathname is the dependency rather than the search string because the
  // latter is not reactive here — re-running on navigation is what catches an
  // affiliate link followed from inside the app, which is how a shared link in
  // a signed-in tab behaves.
  const pathname = usePathname();

  useEffect(() => {
    captureReferral(window.location.search);
  }, [pathname]);

  return null;
}
