import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * The operator screen for who sends us customers.
 *
 * ── The two things here that fail silently ─────────────────────────────────
 *
 *   1. THE RATE IS BASIS POINTS AND THE SCREEN SHOWS A PERCENTAGE. 2000 is
 *      twenty per cent. A conversion that is out by a factor of a hundred
 *      renders a plausible number — "2000%" or "0.2%" — and nothing errors;
 *      the operator sets a rate believing one thing while the ledger pays
 *      another, and it surfaces in somebody's first statement. So the tests
 *      assert the rendered figure rather than that a formatter was called.
 *
 *   2. BALANCES ARE PER CURRENCY AND MUST NOT BE ADDED UP. A single total
 *      across dinars and dollars is wrong in a way nobody can see on a screen.
 *      The test drives an affiliate holding both.
 *
 * Both are the same class of bug as this repo's dinar-decimal problem: a wrong
 * number that looks right.
 */

// ---------------------------------------------------------------------------
// Module mocks
// ---------------------------------------------------------------------------

const apiClient = jest.fn();
jest.mock('@/lib/auth-context', () => ({
  useAuth: () => ({ apiClient: (...args: unknown[]) => apiClient(...args) }),
}));

const addToast = jest.fn();
jest.mock('@/lib/toast-context', () => ({
  useToast: () => ({ addToast }),
}));

jest.mock('@amroksaleh/features/i18n', () => ({
  // The fallback text with its placeholders filled, so assertions read as the
  // English an operator actually sees.
  useTranslation:
    () =>
    (_key: string, fallback: string, vars?: Record<string, string | number>) =>
      Object.entries(vars ?? {}).reduce(
        (text, [name, value]) => text.replace(`{${name}}`, String(value)),
        fallback
      ),
}));

import { Affiliates } from '@/app/(protected)/admin/billing/affiliates';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const PARTNER = {
  id: 1,
  code: 'SPRING26',
  name: 'A partner',
  email: 'partner@example.test',
  commission_bp: 2000,
  window_months: 12,
  promotion_id: null,
  is_active: true,
  referral_count: 4,
  converted_count: 3,
  balances: [{ currency: 'JOD', amount_minor: 9000 }],
};

function jsonOk(data: unknown) {
  return Promise.resolve({ ok: true, json: () => Promise.resolve({ data }) });
}

/**
 * A working backend holding these affiliates.
 *
 * Writes succeed and return the row, because these tests are about what the
 * screen DOES with an answer — the refusal paths set their own mock. A helper
 * that threw on anything but the listing made every write look like a bug in
 * the component rather than a gap in the fixture.
 */
function listOf(...affiliates: unknown[]) {
  apiClient.mockImplementation((url: string, init?: RequestInit) => {
    if (url === '/api/v1/affiliates' && (init?.method ?? 'GET') === 'GET') {
      return jsonOk(affiliates);
    }
    return jsonOk(affiliates[0] ?? {});
  });
}

beforeEach(() => {
  jest.clearAllMocks();
});

// ---------------------------------------------------------------------------

describe('showing what an affiliate earns', () => {
  /**
   * BASIS POINTS RENDER AS A PERCENTAGE. Out by a hundred either way produces a
   * number that looks like a rate and is not one.
   */
  it.each([
    [2000, '20%'],
    [500, '5%'],
    [5000, '50%'],
    [250, '2.50%'],
    [1, '0.01%'],
  ])('shows %i basis points as %s', async (bp, shown) => {
    listOf({ ...PARTNER, commission_bp: bp });

    render(<Affiliates />);

    expect(await screen.findByText(shown)).toBeInTheDocument();
  });

  /**
   * BOTH REFERRAL NUMBERS, because they answer different questions: how many
   * workspaces the link produced, and how many of those ever paid. A screen
   * showing only the first flatters the affiliate and tells the operator
   * nothing about whether the traffic converts.
   */
  it('shows how many referrals actually paid, not just how many signed up', async () => {
    listOf(PARTNER);

    render(<Affiliates />);

    expect(await screen.findByText('3 of 4 paid')).toBeInTheDocument();
  });

  /**
   * CURRENCIES ARE LISTED, NEVER SUMMED. Adding dinars to dollars gives a
   * number that is wrong in a way nobody can see — and a payout is made in one
   * currency at a time anyway.
   */
  it('lists each currency separately rather than adding them together', async () => {
    listOf({
      ...PARTNER,
      balances: [
        { currency: 'JOD', amount_minor: 9000 },
        { currency: 'USD', amount_minor: 500 },
      ],
    });

    render(<Affiliates />);

    expect(await screen.findByText('9000 JOD')).toBeInTheDocument();
    expect(screen.getByText('500 USD')).toBeInTheDocument();
    // The sum that must NOT appear anywhere.
    expect(screen.queryByText(/9500/)).not.toBeInTheDocument();
  });

  it('shows a dash rather than a zero for an affiliate who has earned nothing', async () => {
    listOf({ ...PARTNER, balances: [] });

    render(<Affiliates />);

    expect(await screen.findByText('—')).toBeInTheDocument();
  });

  /** A retired affiliate is still listed, and marked. */
  it('keeps a deactivated affiliate visible and says so', async () => {
    listOf({ ...PARTNER, is_active: false });

    render(<Affiliates />);

    expect(await screen.findByText('Retired')).toBeInTheDocument();
    expect(screen.getByText('SPRING26')).toBeInTheDocument();
  });

  it('explains what an affiliate is when there are none', async () => {
    listOf();

    render(<Affiliates />);

    expect(await screen.findByText('No affiliates yet')).toBeInTheDocument();
  });
});

describe('creating one', () => {
  /**
   * THE LIVE PREVIEW IS WHY BASIS POINTS ARE ACCEPTABLE IN A FORM FIELD. It is
   * what stops somebody typing 20 for "twenty per cent" and not noticing.
   */
  it('shows what the typed rate means as a percentage', async () => {
    listOf();
    const user = userEvent.setup();

    render(<Affiliates />);
    await user.click(await screen.findByRole('button', { name: 'New affiliate' }));

    // The default in the field.
    expect(await screen.findByText('→ 20%')).toBeInTheDocument();

    const rate = screen.getByLabelText('Commission (basis points)');
    await user.clear(rate);
    await user.type(rate, '750');

    expect(await screen.findByText('→ 7.50%')).toBeInTheDocument();
  });

  it('sends the code, name and rate the operator entered', async () => {
    listOf();
    const user = userEvent.setup();

    render(<Affiliates />);
    await user.click(await screen.findByRole('button', { name: 'New affiliate' }));

    await user.type(screen.getByLabelText('Code'), 'AUTUMN26');
    await user.type(screen.getByLabelText('Name'), 'Another partner');

    apiClient.mockImplementation((url: string, init?: RequestInit) => {
      if (url === '/api/v1/affiliates' && init?.method === 'POST') {
        return jsonOk({ ...PARTNER, id: 2, code: 'AUTUMN26' });
      }
      return jsonOk([]);
    });

    await user.click(screen.getByRole('button', { name: 'Create affiliate' }));

    await waitFor(() => {
      const post = apiClient.mock.calls.find(
        ([, init]) => (init as RequestInit | undefined)?.method === 'POST'
      );
      expect(post).toBeDefined();
      expect(JSON.parse((post![1] as RequestInit).body as string)).toMatchObject({
        code: 'AUTUMN26',
        name: 'Another partner',
        // A NUMBER, not the string the input holds. A string here would be
        // refused by the server as "not basis points" for a value that is.
        commission_bp: 2000,
        window_months: 12,
      });
    });
  });

  /** An empty optional field is omitted rather than sent as an empty string. */
  it('omits an email nobody entered', async () => {
    listOf();
    const user = userEvent.setup();

    render(<Affiliates />);
    await user.click(await screen.findByRole('button', { name: 'New affiliate' }));
    await user.type(screen.getByLabelText('Code'), 'AUTUMN26');
    await user.type(screen.getByLabelText('Name'), 'Another partner');

    apiClient.mockImplementation(() => jsonOk({}));
    await user.click(screen.getByRole('button', { name: 'Create affiliate' }));

    await waitFor(() => {
      const post = apiClient.mock.calls.find(
        ([, init]) => (init as RequestInit | undefined)?.method === 'POST'
      );
      expect(Object.keys(JSON.parse((post![1] as RequestInit).body as string))).not.toContain('email');
    });
  });

  it('cannot be submitted without a code or a name', async () => {
    listOf();
    const user = userEvent.setup();

    render(<Affiliates />);
    await user.click(await screen.findByRole('button', { name: 'New affiliate' }));

    expect(screen.getByRole('button', { name: 'Create affiliate' })).toBeDisabled();
  });

  /**
   * THE SERVER'S REFUSAL REACHES THE OPERATOR IN ITS OWN WORDS. It names which
   * field and why — "codes are matched without regard to case" — and a generic
   * "could not create" would leave somebody retyping the same code.
   */
  it('shows the reason the server gave for a refusal', async () => {
    listOf();
    const user = userEvent.setup();

    render(<Affiliates />);
    await user.click(await screen.findByRole('button', { name: 'New affiliate' }));
    await user.type(screen.getByLabelText('Code'), 'SPRING26');
    await user.type(screen.getByLabelText('Name'), 'A partner');

    apiClient.mockImplementation(() =>
      Promise.resolve({
        ok: false,
        json: () =>
          Promise.resolve({
            error: 'Another affiliate already uses that code. Codes are matched without regard to case.',
          }),
      })
    );

    await user.click(screen.getByRole('button', { name: 'Create affiliate' }));

    await waitFor(() => {
      expect(addToast).toHaveBeenCalledWith(
        expect.stringContaining('without regard to case'),
        'error'
      );
    });
  });

  /** A field-level refusal is preferred over the generic message beside it. */
  it('prefers the field-level reason when the server gives one', async () => {
    listOf();
    const user = userEvent.setup();

    render(<Affiliates />);
    await user.click(await screen.findByRole('button', { name: 'New affiliate' }));
    await user.type(screen.getByLabelText('Code'), 'A B');
    await user.type(screen.getByLabelText('Name'), 'A partner');

    apiClient.mockImplementation(() =>
      Promise.resolve({
        ok: false,
        json: () =>
          Promise.resolve({
            error: 'Validation failed',
            details: { code: 'A code may use letters, digits, dots, dashes and underscores only.' },
          }),
      })
    );

    await user.click(screen.getByRole('button', { name: 'Create affiliate' }));

    await waitFor(() => {
      expect(addToast).toHaveBeenCalledWith(expect.stringContaining('letters, digits'), 'error');
    });
  });
});

describe('ending an arrangement', () => {
  /**
   * DEACTIVATING IS NOT DELETING, and the confirmation says so. An operator who
   * believed this removed the affiliate would be surprised to still owe them
   * money — which they do, and should.
   */
  it('deactivates and says what that does to the balance', async () => {
    listOf(PARTNER);
    const user = userEvent.setup();

    render(<Affiliates />);
    await user.click(await screen.findByRole('button', { name: 'Deactivate' }));

    await waitFor(() => {
      const patch = apiClient.mock.calls.find(
        ([, init]) => (init as RequestInit | undefined)?.method === 'PATCH'
      );
      expect(patch![0]).toBe('/api/v1/affiliates/1');
      expect(JSON.parse((patch![1] as RequestInit).body as string)).toEqual({ is_active: false });
    });

    expect(addToast).toHaveBeenCalledWith(
      expect.stringContaining('what they are already owed is unchanged'),
      'success'
    );
  });

  it('offers to bring a retired affiliate back', async () => {
    listOf({ ...PARTNER, is_active: false });
    const user = userEvent.setup();

    render(<Affiliates />);
    await user.click(await screen.findByRole('button', { name: 'Reactivate' }));

    await waitFor(() => {
      const patch = apiClient.mock.calls.find(
        ([, init]) => (init as RequestInit | undefined)?.method === 'PATCH'
      );
      expect(JSON.parse((patch![1] as RequestInit).body as string)).toEqual({ is_active: true });
    });
  });

  /** There is no way to delete one, and the screen must not offer one. */
  it('never offers to delete an affiliate', async () => {
    listOf(PARTNER);

    render(<Affiliates />);
    await screen.findByText('SPRING26');

    expect(screen.queryByRole('button', { name: /delete/i })).not.toBeInTheDocument();
  });
});

describe('when the list cannot be read', () => {
  it('says so rather than showing an empty programme', async () => {
    apiClient.mockImplementation(() => Promise.resolve({ ok: false, json: () => Promise.resolve({}) }));

    render(<Affiliates />);

    expect(await screen.findByText('Failed to load the affiliate list')).toBeInTheDocument();
    // AN EMPTY STATE HERE WOULD BE A LIE. "No affiliates yet" and "we could not
    // ask" are different facts, and the first one invites somebody to create a
    // duplicate of a code that already exists.
    expect(screen.queryByText('No affiliates yet')).not.toBeInTheDocument();
  });
});
