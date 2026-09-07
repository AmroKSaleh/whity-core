import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';

/**
 * The tenant-facing billing screen.
 *
 * Two things are worth testing hardest here, because both fail SILENTLY:
 *
 *   1. AMOUNTS. The Jordanian dinar has three decimal places, so 5000 fils is
 *      5.000 JOD. A screen that divides by 100 shows 50.00 — ten times too
 *      much — and nothing errors. The test asserts the rendered figure, not
 *      that a formatter was called.
 *
 *   2. THE PAY FLOW BRANCHING ON `kind` RATHER THAN ON A PROVIDER NAME. If it
 *      branched on the provider, everything would still work today (CliQ is the
 *      only real rail) and break the day a card provider is added. So there is
 *      a test that drives a `redirect` instruction, which no rail on this
 *      instance produces yet.
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

const hasPermission = jest.fn<boolean, [string]>();
jest.mock('@/hooks/useCapabilities', () => ({
  useCapabilities: () => ({ loading: false, permissions: [], hasPermission }),
}));

const navigateExternal = jest.fn();
jest.mock('@/lib/external-navigate', () => ({
  navigateExternal: (url: string) => navigateExternal(url),
}));

jest.mock('@amroksaleh/features/i18n', () => ({
  // The fallback text, so assertions read as the English a user sees.
  useTranslation: () => (_key: string, fallback: string) => fallback,
}));

import BillingPage from '@/app/(protected)/billing/page';

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------

const OPEN_INVOICE = {
  id: 7,
  number: 'INV-2026-00001',
  status: 'open',
  currency: 'JOD',
  total_minor: 5000,
  amount_paid_minor: 0,
  balance_minor: 5000,
  total_formatted: '5.000 JOD',
  balance_formatted: '5.000 JOD',
  issued_at: '2026-09-01 00:00:00',
  due_at: '2026-09-15 00:00:00',
  paid_at: null,
  seller_name: 'Whity Operator',
  buyer_name: 'Acme Ltd',
};

function jsonOk(data: unknown) {
  return Promise.resolve({ ok: true, json: () => Promise.resolve({ data }) });
}

/** Route each request by path, so tests declare only what they care about. */
function routeApi(routes: Record<string, unknown>, notFound: string[] = []) {
  apiClient.mockImplementation((path: string) => {
    for (const [prefix, data] of Object.entries(routes)) {
      if (path.startsWith(prefix)) {
        return typeof data === 'function' ? (data as () => unknown)() : jsonOk(data);
      }
    }
    if (notFound.some((p) => path.startsWith(p))) {
      return Promise.resolve({ ok: false, json: () => Promise.resolve({}) });
    }
    return jsonOk([]);
  });
}

beforeEach(() => {
  jest.clearAllMocks();
  hasPermission.mockReturnValue(true);
});

// ---------------------------------------------------------------------------

describe('amounts', () => {
  /**
   * THE HEADLINE. 5000 fils is 5.000 JOD. Divide by 100 and every customer is
   * shown ten times what they owe, with nothing to indicate anything is wrong.
   */
  it('renders JOD at three decimal places, not two', async () => {
    routeApi({ '/api/v1/billing/invoices': [OPEN_INVOICE] });

    render(<BillingPage />);

    await waitFor(() => expect(screen.getByText('INV-2026-00001')).toBeInTheDocument());

    // Whatever Intl chose for the symbol, the FIGURE must be 5.000.
    const figures = screen.getAllByText((_, node) => /5\.000/.test(node?.textContent ?? ''));
    expect(figures.length).toBeGreaterThan(0);
    expect(screen.queryByText(/50\.00(?!0)/)).not.toBeInTheDocument();
  });

  it('renders a two-decimal currency at two places', async () => {
    routeApi({
      '/api/v1/billing/invoices': [
        { ...OPEN_INVOICE, currency: 'USD', total_minor: 5000, balance_minor: 5000 },
      ],
    });

    render(<BillingPage />);

    await waitFor(() =>
      expect(
        screen.getAllByText((_, node) => /50\.00/.test(node?.textContent ?? '')).length
      ).toBeGreaterThan(0)
    );
  });
});

describe('what a customer is told', () => {
  /**
   * An empty table and a failed request look identical, and "no invoices" is a
   * far more comforting thing to read than "we could not fetch them" — so a
   * customer who owes money would be told the opposite of the truth.
   */
  it('says so when the invoices could not be loaded', async () => {
    apiClient.mockResolvedValue({ ok: false, json: () => Promise.resolve({}) });

    render(<BillingPage />);

    await waitFor(() =>
      expect(screen.getByTestId('invoices-unreadable')).toBeInTheDocument()
    );
  });

  /** No pay button without the permission — reading is not spending. */
  it('hides the pay button from somebody who may only view', async () => {
    hasPermission.mockImplementation((slug: string) => slug !== 'billing:pay');
    routeApi({ '/api/v1/billing/invoices': [OPEN_INVOICE] });

    render(<BillingPage />);

    await waitFor(() => expect(screen.getByText('INV-2026-00001')).toBeInTheDocument());
    expect(screen.queryByTestId('pay-7')).not.toBeInTheDocument();
  });

  /** And none on an invoice that cannot take one. */
  it('hides the pay button on an invoice that is already paid', async () => {
    routeApi({
      '/api/v1/billing/invoices': [
        { ...OPEN_INVOICE, status: 'paid', balance_minor: 0, amount_paid_minor: 5000 },
      ],
    });

    render(<BillingPage />);

    await waitFor(() => expect(screen.getByText('INV-2026-00001')).toBeInTheDocument());
    expect(screen.queryByTestId('pay-7')).not.toBeInTheDocument();
  });
});

describe('paying', () => {
  /**
   * The CliQ path: a reference the customer types into their own bank app. It
   * is pinned `dir="ltr"` because a Latin-and-digits code must not be mirrored
   * when the interface is Arabic.
   */
  it('shows the transfer reference for a push rail', async () => {
    const user = userEvent.setup();
    routeApi({
      '/api/v1/billing/invoices/7/pay': () =>
        jsonOk({
          kind: 'transfer',
          provider: 'cliq',
          reference: 'WHT-4H7K2M9P42',
          display: { alias: 'WHITY.JO', bank: 'Test Bank' },
        }),
      '/api/v1/billing/invoices': [OPEN_INVOICE],
      '/api/v1/billing/methods': [
        { provider: 'cliq', uses_redirect: false, uses_push_transfer: true, supports_unattended_charge: false },
      ],
    });

    render(<BillingPage />);
    await waitFor(() => expect(screen.getByTestId('pay-7')).toBeInTheDocument());

    await user.click(screen.getByTestId('pay-7'));
    await waitFor(() => expect(screen.getByTestId('method-cliq')).toBeInTheDocument());
    await user.click(screen.getByTestId('method-cliq'));

    await waitFor(() => expect(screen.getByTestId('transfer-reference')).toBeInTheDocument());
    const reference = screen.getByTestId('transfer-reference');
    expect(reference).toHaveTextContent('WHT-4H7K2M9P42');
    expect(reference).toHaveAttribute('dir', 'ltr');
    expect(screen.getByText('WHITY.JO')).toBeInTheDocument();
  });

  /**
   * THE TEST THAT PROVES THE FLOW IS NOT CLIQ-SHAPED. No rail on this instance
   * produces a `redirect` yet — the card provider is undecided — so this drives
   * the branch that only a future provider will use. If the screen branched on
   * the provider name instead of on `kind`, everything above would still pass
   * and this would fail.
   */
  it('follows a redirect instruction, which only a future card rail produces', async () => {
    const user = userEvent.setup();

    routeApi({
      '/api/v1/billing/invoices/7/pay': () =>
        jsonOk({
          kind: 'redirect',
          provider: 'card',
          redirect_url: 'https://pay.example.test/checkout/abc',
        }),
      '/api/v1/billing/invoices': [OPEN_INVOICE],
      '/api/v1/billing/methods': [
        { provider: 'card', uses_redirect: true, uses_push_transfer: false, supports_unattended_charge: true },
      ],
    });

    render(<BillingPage />);
    await waitFor(() => expect(screen.getByTestId('pay-7')).toBeInTheDocument());

    await user.click(screen.getByTestId('pay-7'));
    await waitFor(() => expect(screen.getByTestId('method-card')).toBeInTheDocument());
    await user.click(screen.getByTestId('method-card'));

    await waitFor(() =>
      expect(navigateExternal).toHaveBeenCalledWith('https://pay.example.test/checkout/abc')
    );
  });

  /**
   * An honest empty state. No rail configured is an operator problem the
   * customer cannot solve by pressing the button again.
   */
  it('says plainly when no payment method is configured', async () => {
    const user = userEvent.setup();
    routeApi({
      '/api/v1/billing/invoices': [OPEN_INVOICE],
      '/api/v1/billing/methods': [],
    });

    render(<BillingPage />);
    await waitFor(() => expect(screen.getByTestId('pay-7')).toBeInTheDocument());

    await user.click(screen.getByTestId('pay-7'));

    await waitFor(() =>
      expect(screen.getByTestId('no-payment-methods')).toBeInTheDocument()
    );
  });
});

describe('the invoice detail', () => {
  /**
   * A FAILED ATTEMPT IS SHOWN, with the bank's reason. "Why does it say I have
   * not paid" is answered by the attempt that failed; a history of successes
   * only cannot answer it, and the customer then asks a human.
   */
  it('shows failed attempts and the provider’s reason', async () => {
    const user = userEvent.setup();
    routeApi({
      '/api/v1/billing/invoices/7': () =>
        jsonOk({
          ...OPEN_INVOICE,
          lines: [
            { id: 1, description: 'Professional plan', quantity: 1, unit_amount_minor: 5000, tax_minor: 0, total_minor: 5000 },
          ],
          payments: [
            {
              id: 2,
              provider: 'cliq',
              status: 'failed',
              amount_minor: 5000,
              currency: 'JOD',
              failure_reason: 'Beneficiary account closed',
              occurred_at: '2026-09-10 09:00:00',
            },
          ],
        }),
      '/api/v1/billing/invoices': [OPEN_INVOICE],
    });

    render(<BillingPage />);
    await waitFor(() => expect(screen.getByText('INV-2026-00001')).toBeInTheDocument());

    await user.click(screen.getByRole('button', { name: 'View' }));

    await waitFor(() => expect(screen.getByTestId('invoice-payments')).toBeInTheDocument());
    expect(screen.getByText('Beneficiary account closed')).toBeInTheDocument();
    // The badge renders through the computed status key; under the test's
    // fallback-returning translator that is the raw status.
    expect(screen.getByTestId('invoice-payments')).toHaveTextContent('failed');
    expect(screen.getByTestId('invoice-lines')).toHaveTextContent('Professional plan');
  });
});
