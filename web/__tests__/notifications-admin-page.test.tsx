import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';

/**
 * Notification delivery health — `/admin/notifications`.
 *
 * ── The two things on this screen that can be wrong and still look right ──
 *
 *   1. THE FAILURE RATE IS A FRACTION AND THE SCREEN SHOWS A PERCENTAGE.
 *      The API returns 0..1 rounded to four places, so 0.0725 is 7.25%. An
 *      off-by-a-hundred conversion renders a plausible figure either way —
 *      "0.07%" reads as healthy, "725%" reads as broken — and nothing errors.
 *      Same class as this repo's dinar-decimal bug: a wrong number that looks
 *      right. So the tests assert the rendered string.
 *
 *   2. THE BOUNCED COUNT IS ALWAYS ZERO AND MUST NOT BE SHOWN AS ONE. Nothing
 *      in production ever writes `bounced` — a bounce is reported
 *      asynchronously by the receiving mail server and no webhook ingestion
 *      exists to hear it. A tile reading "Bounced 0" tells an operator there
 *      are no bounces, when the truth is that bounces are not counted. That is
 *      a reassuring answer to a question nobody asked, which is the worst
 *      shape a metric can take.
 */

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

let capabilities: string[] = [];
let capsLoading = false;
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({
    has: (slug: string) => capabilities.includes(slug),
    loading: capsLoading,
  }),
}));

jest.mock('@amroksaleh/features/i18n', () => ({
  // The English fallback, so assertions read as what an operator actually sees.
  useTranslation: () => (_key: string, fallback: string) => fallback,
}));

import NotificationsAdminPage from '@/app/(protected)/admin/notifications/page';
import { formatLatency, formatRate } from '@/app/(protected)/admin/notifications/format';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const METRICS = {
  total: 400,
  by_status: { queued: 5, sent: 366, failed: 29, bounced: 0 },
  queue_depth: 5,
  failure_rate: 0.0725,
  avg_latency_seconds: 12.4,
};

function respondWith(body: unknown, ok = true) {
  global.fetch = jest.fn().mockResolvedValue({
    ok,
    status: ok ? 200 : 500,
    json: async () => body,
  }) as unknown as typeof fetch;
}

beforeEach(() => {
  jest.clearAllMocks();
  capabilities = ['notifications:manage'];
  capsLoading = false;
  respondWith({ data: METRICS });
});

// ---------------------------------------------------------------------------
// The number that can be wrong by a factor of a hundred
// ---------------------------------------------------------------------------

describe('the failure rate', () => {
  it('renders a 0..1 fraction as a percentage', () => {
    expect(formatRate(0.0725)).toBe('7.2%');
    expect(formatRate(0)).toBe('0.0%');
    expect(formatRate(1)).toBe('100.0%');
  });

  /**
   * 0.0725 SHOWS AS 7.2%, NOT 7.3%, and that is arithmetic rather than a bug
   * worth fixing. `0.0725 * 100` is 7.249999999999999 in IEEE-754 — 0.0725
   * has no exact binary representation — so `.toFixed(1)` rounds it down,
   * correctly, for the value it was actually given.
   *
   * Recorded here because the next person to compute it by hand will make it
   * a bug report. Closing the gap needs decimal arithmetic on the string, and
   * a tenth of a percentage point on a failure rate changes no decision
   * anybody makes from this screen — so the behaviour is pinned instead of
   * engineered around, and a value one ulp away rounds up as expected.
   */
  it('rounds the value it is actually given, binary representation included', () => {
    expect(formatRate(0.0725)).toBe('7.2%');
    expect(formatRate(0.073)).toBe('7.3%');
  });

  /**
   * A rate below 1% keeps two decimals rather than rounding to "0.0%", which
   * would render a real and rising failure rate as no failures at all.
   */
  it('does not round a small but real rate away to zero', () => {
    expect(formatRate(0.0004)).toBe('0.04%');
    expect(formatRate(0.0004)).not.toBe('0.0%');
  });

  it('shows the percentage on the page, not the fraction', async () => {
    render(<NotificationsAdminPage />);

    expect(await screen.findByText('7.2%')).toBeInTheDocument();
    expect(screen.queryByText('0.0725')).not.toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// The metric that is structurally always zero
// ---------------------------------------------------------------------------

describe('the bounced count', () => {
  it('is shown as not tracked rather than as a zero', async () => {
    render(<NotificationsAdminPage />);

    expect(await screen.findByText('Not tracked')).toBeInTheDocument();
    expect(
      screen.getByText('Needs mail-provider webhooks, which are not set up')
    ).toBeInTheDocument();
  });

  /**
   * The counts that ARE real must still render as numbers — otherwise a fix
   * that labelled every tile "Not tracked" would pass the test above.
   */
  it('still shows the real per-status counts as numbers', async () => {
    render(<NotificationsAdminPage />);

    expect(await screen.findByText('366')).toBeInTheDocument();
    expect(screen.getByText('29')).toBeInTheDocument();
  });
});

// ---------------------------------------------------------------------------
// Latency
// ---------------------------------------------------------------------------

describe('the average send time', () => {
  const t = (_key: string, fallback: string) => fallback;

  it('keeps one decimal below a second, so a 400ms send is not shown as instant', () => {
    expect(formatLatency(0.4, t)).toBe('0.4s');
    expect(formatLatency(0.4, t)).not.toBe('0s');
  });

  it('rounds to whole seconds, then to minutes', () => {
    expect(formatLatency(12.4, t)).toBe('12s');
    expect(formatLatency(184, t)).toBe('3 min');
  });

  it('says so when nothing has been sent, rather than showing zero', () => {
    expect(formatLatency(null, t)).toBe('No deliveries sent yet');
    expect(formatLatency(null, t)).not.toContain('0');
  });
});

// ---------------------------------------------------------------------------
// States
// ---------------------------------------------------------------------------

describe('states', () => {
  it('refuses the page without notifications:manage', () => {
    capabilities = [];
    render(<NotificationsAdminPage />);

    expect(screen.getByText(/do not have the required permissions/i)).toBeInTheDocument();
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('asks the versioned route the router actually serves', async () => {
    render(<NotificationsAdminPage />);

    await waitFor(() => expect(global.fetch).toHaveBeenCalled());
    expect((global.fetch as jest.Mock).mock.calls[0][0]).toBe('/api/v1/notification-metrics');
  });

  /**
   * A workspace that has sent nothing gets an empty state, NOT a wall of
   * zeroes — "0 total, 0 failed, 0.0% failure rate" is indistinguishable from
   * a healthy deployment at a glance.
   */
  it('shows an empty state when nothing has ever been sent', async () => {
    respondWith({
      data: {
        total: 0,
        by_status: { queued: 0, sent: 0, failed: 0, bounced: 0 },
        queue_depth: 0,
        failure_rate: 0,
        avg_latency_seconds: null,
      },
    });
    render(<NotificationsAdminPage />);

    expect(await screen.findByText('No notifications sent yet')).toBeInTheDocument();
    expect(screen.queryByText('Failure rate')).not.toBeInTheDocument();
  });

  it('surfaces a failed load instead of rendering an empty dashboard', async () => {
    respondWith({}, false);
    render(<NotificationsAdminPage />);

    await waitFor(() =>
      expect(addToast).toHaveBeenCalledWith('Could not load notification metrics', 'error')
    );
    expect(await screen.findByText('Metrics unavailable')).toBeInTheDocument();
  });

  /**
   * The hand-off to the audit trail carries the filter. Without the query
   * string the link lands on an unfiltered log and the label — "Open the
   * delivery trail" — becomes a promise the page does not keep.
   */
  it('links to the audit trail pre-filtered to notification actions', async () => {
    render(<NotificationsAdminPage />);

    const link = await screen.findByRole('link', { name: 'Open the delivery trail' });
    expect(link).toHaveAttribute('href', '/admin/audit-logs?action=notification.');
  });
});
