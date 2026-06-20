'use client';

import { createContext, useContext } from 'react';
import type { Branding } from '@/lib/branding';

const BrandingContext = createContext<Branding>({
  siteName: 'Whity',
  logoWideUrl: null,
  logoSquareUrl: null,
  faviconUrl: null,
});

export function BrandingProvider({
  initial,
  children,
}: {
  initial: Branding;
  children: React.ReactNode;
}) {
  return <BrandingContext.Provider value={initial}>{children}</BrandingContext.Provider>;
}

export function useBranding(): Branding {
  return useContext(BrandingContext);
}
