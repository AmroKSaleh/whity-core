import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

/**
 * Platform versions on the dashboard (`/admin/stats`).
 *
 * The core and plugin-SDK versions come from `GET /api/v1/platform/version`,
 * which is gated on `settings:manage` AND the system tenant — a STRICTLY
 * NARROWER audience than the `admin` role this page itself requires. So the
 * interesting behaviour is not that the versions render; it is what a viewer
 * outside that narrower gate sees, and these tests exist to pin it:
 *
 *   - a 403 produces ABSENCE — no rows, no error alert, no console output;
 *   - the rest of the System card is unaffected, including the PHP version,
 *     which is sourced from the wider-gated `/api/v1/admin/stats`;
 *   - the two requests fail independently in both directions.
 *
 * Every failure path collapsing to the same silent absence is the design, so
 * each path is asserted separately rather than assumed equivalent.
 */

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

const mockApiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: mockApiClient }),
}));

import AdminStats from '@/app/(protected)/admin/stats/page';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const STATS = {
  totals: { users: 12, tenants: 3, roles: 4, permissions: 55 },
  breakdown: { users_per_role: [{ name: 'admin', count: 2 }] },
  growth: { users: [{ date: '2026-08-20', count: 1 }], tenants: [] },
  maintenance: { migrations_executed: 102, migrations_total: 102, pending_migrations: 0 },
  database: { size: '24 MB', version: 'PostgreSQL 16.2' },
  system: {
    php_version: '8.3.10',
    memory_usage: '18 MB',
    peak_memory: '22 MB',
    os: 'Linux',
    server: 'FrankenPHP',
  },
};

const PLATFORM = {
  core_version: '0.2.4',
  sdk_version: '1.32.0',
  php_version: '8.3.10',
};

/** A minimal stand-in for the parts of Response the page touches. */
function jsonResponse(body: unknown, ok = true, status = 200) {
  return { ok, status, json: async () => body };
}

/**
 * Route by URL rather than by call order. The page fires two independent
 * requests from two effects, and an ordered mock would pin an ordering that
 * neither effect promises — the exact way a mount-time fetch desyncs a suite.
 */
function routeApi(handlers: { stats?: unknown; platform?: unknown }) {
  mockApiClient.mockImplementation((url: string) => {
    if (url.includes('/platform/version')) {
      const platform = handlers.platform;
      if (platform instanceof Error) return Promise.reject(platform);
      return Promise.resolve(platform ?? jsonResponse(PLATFORM));
    }
    const stats = handlers.stats;
    if (stats instanceof Error) return Promise.reject(stats);
    return Promise.resolve(stats ?? jsonResponse({ stats: STATS }));
  });
}

let consoleError: jest.SpyInstance;

beforeEach(() => {
  mockApiClient.mockReset();
  consoleError = jest.spyOn(console, 'error').mockImplementation(() => {});
});

afterEach(() => {
  consoleError.mockRestore();
});

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

describe('/admin/stats — platform versions', () => {
  it('renders the core and plugin-SDK versions for a permitted operator', async () => {
    routeApi({});
    render(<AdminStats />);

    await waitFor(() => {
      expect(screen.getByText('Core Version')).toBeInTheDocument();
    });
    expect(screen.getByText('0.2.4')).toBeInTheDocument();
    expect(screen.getByText('Plugin SDK')).toBeInTheDocument();
    expect(screen.getByText('1.32.0')).toBeInTheDocument();

    // Both requests were made; the versions are not smuggled out of the stats
    // payload, which carries no version of its own.
    const urls = mockApiClient.mock.calls.map((call) => call[0] as string);
    expect(urls).toEqual(
      expect.arrayContaining(['/api/v1/admin/stats', '/api/v1/platform/version'])
    );
  });

  it('shows a clean absence — not an error — when the caller lacks settings:manage', async () => {
    routeApi({ platform: jsonResponse({ error: 'Insufficient permissions' }, false, 403) });
    render(<AdminStats />);

    // Wait on a LOADED VALUE, never on a label: the labels are static and are
    // in the document from the first paint, so waiting on one would let the
    // absence assertions below run before either request had settled and pass
    // for the wrong reason. The stats request also takes strictly more ticks to
    // resolve than the denied version request, so its value appearing proves
    // both have finished.
    await waitFor(() => {
      expect(screen.getByText('8.3.10')).toBeInTheDocument();
    });
    expect(screen.getByText('PHP Version')).toBeInTheDocument();
    expect(screen.getByText('Memory Usage')).toBeInTheDocument();

    // The version rows are simply not there.
    expect(screen.queryByText('Core Version')).not.toBeInTheDocument();
    expect(screen.queryByText('Plugin SDK')).not.toBeInTheDocument();

    // And nothing anywhere reports a problem: no error alert, no toast surface,
    // no console noise. A 403 here is the endpoint behaving correctly.
    expect(screen.queryByTestId('stats-fetch-error')).not.toBeInTheDocument();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('degrades identically when the version request fails at the network level', async () => {
    routeApi({ platform: new Error('network down') });
    render(<AdminStats />);

    await waitFor(() => {
      expect(screen.getByText('8.3.10')).toBeInTheDocument();
    });
    expect(screen.queryByText('Core Version')).not.toBeInTheDocument();
    expect(screen.queryByText('Plugin SDK')).not.toBeInTheDocument();
    expect(screen.queryByTestId('stats-fetch-error')).not.toBeInTheDocument();
    expect(consoleError).not.toHaveBeenCalled();
  });

  it('still shows the versions when the stats request itself fails', async () => {
    routeApi({ stats: new Error('stats backend wedged') });
    render(<AdminStats />);

    // The stats failure surfaces on its own alert, as it did before...
    await waitFor(() => {
      expect(screen.getByTestId('stats-fetch-error')).toBeInTheDocument();
    });
    // ...and does not take the version readout down with it. The two requests
    // are independent in both directions, which is the point of not folding
    // them into one payload.
    expect(screen.getByText('Core Version')).toBeInTheDocument();
    expect(screen.getByText('0.2.4')).toBeInTheDocument();
  });
});
