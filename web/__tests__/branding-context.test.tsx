/**
 * WC-233: BrandingProvider seeds the branding context; useBranding() returns
 * the seeded value; the default context value is the safe fallback.
 */

import React from 'react';
import { render, screen } from '@testing-library/react';
import { BrandingProvider, useBranding } from '@/lib/branding-context';
import type { Branding } from '@/lib/branding';

/** Probe component that renders the branding values as data-testid spans. */
function Probe() {
  const b = useBranding();
  return (
    <div>
      <span data-testid="siteName">{b.siteName}</span>
      <span data-testid="logoWideUrl">{b.logoWideUrl ?? 'null'}</span>
      <span data-testid="logoSquareUrl">{b.logoSquareUrl ?? 'null'}</span>
      <span data-testid="faviconUrl">{b.faviconUrl ?? 'null'}</span>
    </div>
  );
}

const CUSTOM_BRANDING: Branding = {
  siteName: 'Acme Corp',
  logoWideUrl: '/api/v1/branding/asset/1/logo_wide-abc.png',
  logoSquareUrl: '/api/v1/branding/asset/1/logo_square-def.png',
  faviconUrl: '/api/v1/branding/asset/1/favicon-ghi.ico',
};

describe('BrandingProvider + useBranding()', () => {
  it('returns the seeded branding values when wrapped in BrandingProvider', () => {
    render(
      <BrandingProvider initial={CUSTOM_BRANDING}>
        <Probe />
      </BrandingProvider>
    );

    expect(screen.getByTestId('siteName').textContent).toBe('Acme Corp');
    expect(screen.getByTestId('logoWideUrl').textContent).toBe(
      '/api/v1/branding/asset/1/logo_wide-abc.png'
    );
    expect(screen.getByTestId('logoSquareUrl').textContent).toBe(
      '/api/v1/branding/asset/1/logo_square-def.png'
    );
    expect(screen.getByTestId('faviconUrl').textContent).toBe(
      '/api/v1/branding/asset/1/favicon-ghi.ico'
    );
  });

  it('returns the safe fallback default when no BrandingProvider is present', () => {
    render(<Probe />);

    expect(screen.getByTestId('siteName').textContent).toBe('Whity');
    expect(screen.getByTestId('logoWideUrl').textContent).toBe('null');
    expect(screen.getByTestId('logoSquareUrl').textContent).toBe('null');
    expect(screen.getByTestId('faviconUrl').textContent).toBe('null');
  });

  it('nested providers inherit the innermost value', () => {
    const OUTER: Branding = {
      siteName: 'Outer',
      logoWideUrl: null,
      logoSquareUrl: null,
      faviconUrl: null,
    };
    const INNER: Branding = {
      siteName: 'Inner',
      logoWideUrl: '/api/v1/branding/asset/2/logo_wide-xyz.png',
      logoSquareUrl: null,
      faviconUrl: null,
    };

    render(
      <BrandingProvider initial={OUTER}>
        <BrandingProvider initial={INNER}>
          <Probe />
        </BrandingProvider>
      </BrandingProvider>
    );

    expect(screen.getByTestId('siteName').textContent).toBe('Inner');
    expect(screen.getByTestId('logoWideUrl').textContent).toBe(
      '/api/v1/branding/asset/2/logo_wide-xyz.png'
    );
  });

  it('null logo/favicon URLs are exposed as null (not empty string)', () => {
    const branding: Branding = {
      siteName: 'No Assets',
      logoWideUrl: null,
      logoSquareUrl: null,
      faviconUrl: null,
    };

    render(
      <BrandingProvider initial={branding}>
        <Probe />
      </BrandingProvider>
    );

    expect(screen.getByTestId('logoWideUrl').textContent).toBe('null');
    expect(screen.getByTestId('faviconUrl').textContent).toBe('null');
  });
});
