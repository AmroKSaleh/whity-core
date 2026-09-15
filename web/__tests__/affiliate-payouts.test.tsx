import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * Paying an affiliate what they are owed.
 *
 * ── What must not blur on this screen ──────────────────────────────────────
 *
 * A payout has THREE amounts and they are different facts: what the affiliate
 * earned, what is kept back and remitted on their behalf, and what actually
 * leaves the bank. Showing one where another belongs is how a payout register
 * stops reconciling with a bank statement — and because the gap is exactly the
 * tax, it reads as a rounding problem until somebody adds it up.
 *
 * The withholding rate is asserted EVEN AT ZERO. A default of zero that nobody
 * notices is the failure this whole area keeps producing; the screen has to say
 * it out loud rather than render a blank that looks like "not applicable".
 *
 * And nothing here moves money. Every button either drafts an amount for a
 * person to transfer, or records that they did.
 */

const apiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: (...args: unknown[]) => apiClient(...args) }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

jest.mock('@amroksaleh/features/i18n', () => ({
  useTranslation:
    () =>
    (_key: string, fallback: string, vars?: Record<string, string | number>) =>
      Object.entries(vars ?? {}).reduce(
        (text, [name, value]) => text.replace(`{${name}}`, String(value)),
        fallback
      ),
}));

import { AffiliatePayouts } from '@/app/(protected)/admin/billing/affiliate-payouts';

const DRAFT = {
  id: 5,
  total_minor: 10000,
  withholding_bp: 500,
  withholding_minor: 500,
  net_minor: 9500,
  currency: 'JOD',
  status: 'draft',
  reference: null,
  paid_at: null,
  commission_count: 3,
};

const PAID = {
  ...DRAFT,
  id: 6,
  status: 'paid',
  reference: 'BANK-2026-0412',
  paid_at: '2026-04-12 10:00:00',
};

function jsonOk(data: unknown) {
  return Promise.resolve({ ok: true, json: () => Promise.resolve({ data }) });
}

function withPayouts(...payouts: unknown[]) {
  apiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (url.endsWith('/payouts') && (init?.method ?? 'GET') === 'GET') {
      return jsonOk(payouts);
    }
    return jsonOk({});
  });
}

const onChanged = jest.fn();

beforeEach(() => {
  jest.clearAllMocks();
});

function mount(balances: Array<{ currency: string; amount_minor: number }> = []) {
  return render(<AffiliatePayouts affiliateId={1} balances={balances} onChanged={onChanged} />);
}

describe('the three amounts', () => {
  /**
   * WHAT LEAVES THE BANK IS NOT WHAT THEY EARNED. All three are on the row, and
   * none of them is the other.
   */
  it('shows earned, withheld and transferred as separate figures', async () => {
    withPayouts(DRAFT);

    mount();
    await screen.findByText('at 5%');

    // READ CELL BY CELL, not by searching for the numbers. A loose text match
    // finds "500 JOD" inside "9500 JOD" — which would let the withheld and
    // transferred figures swap places without the test noticing, and that swap
    // is the exact bug this describes.
    const cells = screen.getAllByRole('cell').map((c) => c.textContent?.replace(/\s+/g, ' ').trim());

    // Anchored at the START of each cell — the separation between the figure
    // and its annotation is CSS margin, so it is not in the text. Anchoring is
    // what makes a swapped pair fail: "9500…" cannot satisfy /^500 JOD/.
    expect(cells[0]).toMatch(/^10000 JOD/);
    expect(cells[1]).toMatch(/^500 JOD/);
    expect(cells[2]).toBe('9500 JOD');
  });

  it('states the rate the payout was assembled at', async () => {
    withPayouts(DRAFT);

    mount();

    expect(await screen.findByText('at 5%')).toBeInTheDocument();
  });

  /**
   * A ZERO RATE IS SAID OUT LOUD. Rendering nothing would look like "not
   * applicable" rather than "nothing was withheld, deliberately" — and a zero
   * nobody notices is exactly how this area fails.
   */
  it('says so even when nothing was withheld', async () => {
    withPayouts({ ...DRAFT, withholding_bp: 0, withholding_minor: 0, net_minor: 10000 });

    mount();

    expect(await screen.findByText('at 0%')).toBeInTheDocument();
  });

  it('shows a fractional rate without rounding it away', async () => {
    withPayouts({ ...DRAFT, withholding_bp: 250 });

    mount();

    expect(await screen.findByText('at 2.50%')).toBeInTheDocument();
  });

  it('says how many commissions a payout covers', async () => {
    withPayouts(DRAFT);

    mount();

    expect(await screen.findByText('(3 commissions)')).toBeInTheDocument();
  });
});

describe('assembling', () => {
  /** One button per currency, because a payout covers one currency. */
  it('offers a payout per currency owed', async () => {
    withPayouts();

    mount([
      { currency: 'JOD', amount_minor: 9000 },
      { currency: 'USD', amount_minor: 500 },
    ]);

    expect(await screen.findByRole('button', { name: 'Pay 9000 JOD' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Pay 500 USD' })).toBeInTheDocument();
  });

  it('asks the server for that currency only', async () => {
    withPayouts();
    const user = userEvent.setup();

    mount([{ currency: 'JOD', amount_minor: 9000 }]);
    await user.click(await screen.findByRole('button', { name: 'Pay 9000 JOD' }));

    await waitFor(() => {
      const post = apiClient.mock.calls.find(
        ([, init]) => (init as RequestInit | undefined)?.method === 'POST'
      );
      expect(post![0]).toBe('/api/v1/affiliates/1/payouts');
      expect(JSON.parse((post![1] as RequestInit).body as string)).toEqual({ currency: 'JOD' });
    });
  });

  /**
   * A NEGATIVE BALANCE IS NOT A DEBT TO COLLECT. Refunds took back more than was
   * earned; it nets against what comes next. The button is disabled AND the
   * reason is stated — a disabled control with no explanation invites somebody
   * to keep clicking it.
   */
  it('will not offer to pay a balance that refunds took below zero', async () => {
    withPayouts();

    mount([{ currency: 'JOD', amount_minor: -2000 }]);

    expect(await screen.findByRole('button', { name: 'Pay -2000 JOD' })).toBeDisabled();
    expect(screen.getByText(/carries forward/)).toBeInTheDocument();
  });

  it('explains a refusal in the server\'s own words', async () => {
    const user = userEvent.setup();
    apiClient.mockImplementation((url: string, init?: RequestInit) => {
      if ((init?.method ?? 'GET') === 'GET') {
        return jsonOk([]);
      }
      return Promise.resolve({
        ok: false,
        json: () =>
          Promise.resolve({
            error: 'There is nothing payable in that currency. A balance reduced to zero or below by refunds carries forward to the next payout.',
          }),
      });
    });

    mount([{ currency: 'JOD', amount_minor: 9000 }]);
    await user.click(await screen.findByRole('button', { name: 'Pay 9000 JOD' }));

    await waitFor(() => {
      expect(addToast).toHaveBeenCalledWith(expect.stringContaining('carries forward'), 'error');
    });
  });
});

describe('recording a transfer', () => {
  /**
   * THE CONFIRMATION QUOTES THE NET, not the gross. It is the number the person
   * is about to type into a bank, and showing them what the affiliate earned
   * instead would have them transfer the tax as well.
   */
  it('confirms the amount that actually leaves the bank', async () => {
    withPayouts(DRAFT);
    const user = userEvent.setup();

    mount();
    await user.click(await screen.findByRole('button', { name: 'Record payment' }));

    expect(await screen.findByText(/9500 JOD has been transferred/)).toBeInTheDocument();
  });

  it('cannot be confirmed without a reference', async () => {
    withPayouts(DRAFT);
    const user = userEvent.setup();

    mount();
    await user.click(await screen.findByRole('button', { name: 'Record payment' }));

    expect(screen.getByRole('button', { name: 'Mark as paid' })).toBeDisabled();
  });

  it('sends the reference the operator typed', async () => {
    withPayouts(DRAFT);
    const user = userEvent.setup();

    mount();
    await user.click(await screen.findByRole('button', { name: 'Record payment' }));
    await user.type(screen.getByLabelText('Payment reference'), 'BANK-2026-0412');
    await user.click(screen.getByRole('button', { name: 'Mark as paid' }));

    await waitFor(() => {
      const patch = apiClient.mock.calls.find(
        ([, init]) => (init as RequestInit | undefined)?.method === 'PATCH'
      );
      expect(patch![0]).toBe('/api/v1/affiliate-payouts/5');
      expect(JSON.parse((patch![1] as RequestInit).body as string)).toEqual({
        reference: 'BANK-2026-0412',
      });
    });
  });
});

describe('a payout that has been paid', () => {
  it('shows the reference that settled it', async () => {
    withPayouts(PAID);

    mount();

    expect(await screen.findByText('BANK-2026-0412')).toBeInTheDocument();
    expect(screen.getByText('Paid')).toBeInTheDocument();
  });

  /**
   * NEITHER PAYABLE NOR DISCARDABLE. The money has gone: re-recording would
   * overwrite the reference of a real transfer, and discarding would put an
   * amount already paid back onto the balance to be paid again.
   */
  it('offers no way to re-record or discard it', async () => {
    withPayouts(PAID);

    mount();
    await screen.findByText('Paid');

    expect(screen.queryByRole('button', { name: 'Record payment' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Discard' })).not.toBeInTheDocument();
  });

  /** A draft, by contrast, offers both. */
  it('but a draft offers both', async () => {
    withPayouts(DRAFT);

    mount();

    expect(await screen.findByRole('button', { name: 'Record payment' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Discard' })).toBeInTheDocument();
  });
});

describe('discarding a draft', () => {
  it('releases what it covered', async () => {
    withPayouts(DRAFT);
    const user = userEvent.setup();

    mount();
    await user.click(await screen.findByRole('button', { name: 'Discard' }));

    await waitFor(() => {
      const del = apiClient.mock.calls.find(
        ([, init]) => (init as RequestInit | undefined)?.method === 'DELETE'
      );
      expect(del![0]).toBe('/api/v1/affiliate-payouts/5');
    });

    expect(addToast).toHaveBeenCalledWith(expect.stringContaining('owed again'), 'success');
  });
});

describe('an affiliate with no payouts', () => {
  it('says so rather than showing an empty table', async () => {
    withPayouts();

    mount();

    expect(await screen.findByText('No payouts yet.')).toBeInTheDocument();
  });
});
