'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { BILLING_PAY } from '@/lib/capabilities';
import { Button } from '@amroksaleh/ui/button';
import { useTranslation } from '@amroksaleh/features/i18n';
import { formatMoney } from '@amroksaleh/ui/money/currency';
import { navigateExternal } from '@/lib/external-navigate';

/**
 * WHAT THIS TENANT CAN BUY, AND THE BUTTON THAT BUYS IT.
 *
 * A workspace behind the payment wall gets 402 on every call, so it renders a
 * dashboard with nothing in it. Sending the reader here is only half an answer:
 * the other half is that when they arrive there has to be something to DO.
 *
 * Every other plan endpoint is gated on `plans:manage`, an operator permission a
 * paying customer never holds — so until `/api/v1/billing/plans` existed, a
 * tenant could be told to pay and had no way to discover what for.
 *
 * AMOUNTS ARE FORMATTED FROM MINOR UNITS, never divided by 100. The Jordanian
 * dinar has three decimal places: 15000 fils is 15.000 JOD, and a screen that
 * divides by 100 shows every customer an amount ten times too large.
 * `formatMoney` knows the exponent and takes the reader's locale.
 *
 * RTL comes from logical properties — `ms-`/`me-`/`text-start` — so the layout
 * mirrors itself with no branch to keep in step.
 */

interface PurchasablePlan {
  plan_key: string;
  is_addon: boolean;
  name: string;
  description: string | null;
  unit_amount: number;
  currency: string;
  billing_period: string;
  is_per_seat: boolean;
  is_per_device: boolean;
}

/**
 * Which half of the catalogue to show.
 *
 * TIERS AND ADD-ONS HAVE OPPOSITE PRECONDITIONS: a tier is refused to a tenant
 * that already has one, an add-on to a tenant that does not. Listing them
 * together would show every tenant at least one button that answers 409, so the
 * caller says which half applies and the server enforces it regardless.
 */
export type PlanKind = 'tier' | 'addon';

export function AvailablePlans({ kind }: { kind: PlanKind }) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const [starting, setStarting] = useState<string | null>(null);

  const canPay = hasPermission(BILLING_PAY);

  const { data, loading } = useFetch(async () => {
    const res = await apiClient('/api/v1/billing/plans');
    if (!res.ok) {
      // Not shouted about. This block is an OFFER, and a deployment that sells
      // nothing answers here too; a red error where a price list would go would
      // be alarming and wrong on every self-hosted install.
      return [] as PurchasablePlan[];
    }
    return ((await res.json()).data ?? []) as PurchasablePlan[];
  }, [apiClient]);

  const wanted = kind === 'addon';
  const plans = (data ?? []).filter((p) => p.is_addon === wanted);

  // Nothing to sell, or still finding out. Rendering an empty "choose a plan"
  // heading would be worse than rendering nothing at all.
  if (loading || plans.length === 0) {
    return null;
  }

  const subscribe = async (planKey: string, billingPeriod: string) => {
    setStarting(planKey);
    try {
      const res = await apiClient('/api/v1/billing/checkout', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ plan_key: planKey, billing_period: billingPeriod }),
      });

      if (!res.ok) {
        addToast(
          t('billing.plans.error.start', 'Could not start checkout. Please try again.'),
          'error'
        );
        return;
      }

      const url = (await res.json()).data?.url as string | undefined;
      if (url === undefined || url === '') {
        addToast(
          t('billing.plans.error.start', 'Could not start checkout. Please try again.'),
          'error'
        );
        return;
      }

      // Leaves the app entirely: what is behind this URL is the billing
      // service's hosted page, and whether it is a card form or transfer
      // instructions is deliberately not knowable here.
      navigateExternal(url);
    } finally {
      setStarting(null);
    }
  };

  const periodLabel = (plan: PurchasablePlan): string => {
    const per = plan.billing_period === 'year'
      ? t('billing.plans.perYear', 'per year')
      : t('billing.plans.perMonth', 'per month');

    if (plan.is_per_seat) {
      return `${per} · ${t('billing.plans.perSeat', 'per seat')}`;
    }
    if (plan.is_per_device) {
      return `${per} · ${t('billing.plans.perDevice', 'per device')}`;
    }
    return per;
  };

  return (
    <section className="space-y-3" data-testid="available-plans">
      <div>
        <h2 className="text-base font-medium">
          {kind === 'addon'
            ? t('billing.plans.addons.title', 'Add-ons')
            : t('billing.plans.title', 'Activate your workspace')}
        </h2>
        <p className="text-sm text-muted-foreground">
          {kind === 'addon'
            ? t(
                'billing.plans.addons.description',
                'Bought alongside your plan. Devices are billed for each unit in service.'
              )
            : t(
                'billing.plans.description',
                'Choose a plan to activate this workspace. Everything else stays locked until then.'
              )}
        </p>
      </div>

      <ul className="grid gap-3 sm:grid-cols-2">
        {plans.map((plan) => (
          <li
            key={`${plan.plan_key}:${plan.billing_period}`}
            className="flex flex-col gap-3 rounded-lg border p-4"
          >
            <div>
              <p className="font-medium">{plan.name}</p>
              {plan.description !== null && (
                <p className="text-sm text-muted-foreground">{plan.description}</p>
              )}
            </div>
            <p className="text-sm">
              <span className="font-medium">
                {formatMoney(plan.unit_amount, plan.currency)}
              </span>
              <span className="ms-1 text-muted-foreground">{periodLabel(plan)}</span>
            </p>
            <Button
              onClick={() => void subscribe(plan.plan_key, plan.billing_period)}
              disabled={!canPay || starting !== null}
              data-testid={`subscribe-${plan.plan_key}`}
            >
              {starting === plan.plan_key
                ? t('billing.plans.starting', 'Opening checkout…')
                : t('billing.plans.subscribe', 'Subscribe')}
            </Button>
            {/* Said plainly rather than by a disabled button with no
                explanation: someone without the permission needs to know who to
                ask, not to wonder whether the page is broken. */}
            {!canPay && (
              <p className="text-xs text-muted-foreground">
                {t(
                  'billing.plans.needsPermission',
                  'You do not have permission to pay. Ask a workspace administrator.'
                )}
              </p>
            )}
          </li>
        ))}
      </ul>
    </section>
  );
}
