'use client';

import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { Badge } from '@amroksaleh/ui/badge';
import { useDateDisplay } from '@amroksaleh/features/datetime';
import { useTranslation } from '@amroksaleh/features/i18n';
import { formatMoney } from '@amroksaleh/ui/money/currency';
import { AvailablePlans } from './available-plans';

/**
 * WHAT THIS WORKSPACE IS PAYING FOR, AND WHAT IT HAS PAID.
 *
 * Two bugs shaped this file, both reported by somebody who had just paid:
 *
 * 1. The billing screen kept offering "Activate your workspace" to a workspace
 *    that was already active. Buying again opens a SECOND subscription beside
 *    the first — the billing service has no notion the customer already has one
 *    — so they would be charged twice a month until somebody noticed. The
 *    endpoint refuses it now, and this stops asking.
 *
 * 2. The invoice table was empty for a customer with a receipt. A tenant billed
 *    externally has NO local invoice, by design: the local billing run stands
 *    down for them precisely so nobody is charged twice. So the page was
 *    accurate about our records and a lie about their money.
 *
 * THE OFFER AND THE STATE ARE MUTUALLY EXCLUSIVE, decided by the server's
 * `has_access` rather than by anything this file infers. A page that worked out
 * for itself whether somebody had paid would be a second copy of that rule,
 * wrong the first time the other one changed.
 */

interface AccessState {
  has_access: boolean;
  status: string | null;
  plan: string | null;
  access_until: string | null;
  cancel_at_period_end: boolean;
}

interface Receipt {
  number: string;
  status: string;
  total_minor: number;
  currency: string;
  paid_at: string | null;
  issued_at: string | null;
}

export function SubscriptionPanel() {
  const { apiClient } = useAuth();
  const t = useTranslation('admin');
  const dates = useDateDisplay();

  const { data: access, loading } = useFetch(async () => {
    const res = await apiClient('/api/v1/billing/return');
    if (!res.ok) {
      return null;
    }
    return (await res.json()).data as AccessState;
  }, [apiClient]);

  const { data: receipts } = useFetch(async () => {
    const res = await apiClient('/api/v1/billing/receipts');
    if (!res.ok) {
      return [] as Receipt[];
    }
    return ((await res.json()).data ?? []) as Receipt[];
  }, [apiClient]);

  // Until the answer is known, offer nothing. Rendering the "activate" block
  // first and withdrawing it a moment later would invite the exact double
  // payment the endpoint now refuses.
  if (loading) {
    return null;
  }

  const paid = (receipts ?? []).filter((r) => r.status === 'paid');

  return (
    <div className="space-y-6">
      {access?.has_access === true ? (
        <section className="space-y-2" data-testid="subscription-state">
          <div className="flex flex-wrap items-center gap-2">
            <h2 className="text-base font-medium">
              {t('billing.subscription.title', 'Your subscription')}
            </h2>
            <Badge variant={access.status === 'past_due' ? 'secondary' : 'success'}>
              {access.status === 'past_due'
                ? t('billing.subscription.pastDue', 'Payment overdue')
                : t('billing.subscription.active', 'Active')}
            </Badge>
          </div>

          <dl className="grid gap-1 text-sm">
            {access.plan !== null && (
              <div className="flex gap-2">
                <dt className="text-muted-foreground">{t('billing.subscription.plan', 'Plan')}</dt>
                <dd className="font-medium">{access.plan}</dd>
              </div>
            )}
            {dates.date(access.access_until) !== null && (
              <div className="flex gap-2">
                <dt className="text-muted-foreground">
                  {/* `cancel_at_period_end` does NOT mean locked now: access is
                      live until this date, so the wording changes and nothing
                      else does. */}
                  {access.cancel_at_period_end
                    ? t('billing.subscription.endsOn', 'Ends on')
                    : t('billing.subscription.renewsOn', 'Renews on')}
                </dt>
                <dd className="font-medium">{dates.date(access.access_until)}</dd>
              </div>
            )}
          </dl>

          {/* SAID PLAINLY RATHER THAN LEFT AS A MISSING BUTTON. "Why can I not
              upgrade" is a question somebody will ask, and the honest answer is
              that changing plan is not something the billing service can do in
              place — not that we forgot. */}
          <p className="text-xs text-muted-foreground">
            {t(
              'billing.subscription.changeHint',
              'To change plan, contact support — your current plan stays active until then.'
            )}
          </p>
        </section>
      ) : (
        /* Offered ONLY when the server says there is no access. */
        <AvailablePlans />
      )}

      <section className="space-y-2" data-testid="payment-history">
        <h2 className="text-base font-medium">
          {t('billing.receipts.title', 'Payment history')}
        </h2>

        {paid.length === 0 ? (
          <p className="text-sm text-muted-foreground">
            {t('billing.receipts.empty', 'No payments yet.')}
          </p>
        ) : (
          <ul className="divide-y rounded-lg border text-sm">
            {paid.map((receipt) => (
              <li key={receipt.number} className="flex flex-wrap items-baseline justify-between gap-2 p-3">
                <span className="font-medium">{receipt.number}</span>
                <span className="text-muted-foreground">
                  {dates.date(receipt.paid_at) ?? ''}
                </span>
                <span className="font-medium">
                  {formatMoney(receipt.total_minor, receipt.currency)}
                </span>
              </li>
            ))}
          </ul>
        )}
      </section>
    </div>
  );
}
