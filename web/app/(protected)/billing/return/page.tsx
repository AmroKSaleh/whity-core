'use client';

import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { Suspense } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * WHERE A PAYER LANDS COMING BACK FROM PAYING.
 *
 * THE QUERY STRING ON THIS PAGE IS EVIDENCE OF NOTHING. The payer arrives at
 * `?checkout=<ref>&status=<s>` in a browser they control, and anyone can type
 * `status=completed` into an address bar. Nothing in it is signed and nothing
 * about it proves a payment happened.
 *
 * So this screen renders NOTHING from those parameters. It passes `checkout`
 * through to the server, which re-reads the answer from the billing service and
 * returns what is actually true; every word below comes from that reply. The
 * `status` parameter is not read at all — not even to pick a message, because a
 * message is a claim too, and a screen that says "payment successful" on the
 * strength of a URL is a screen that lies to whoever forged it.
 *
 * WHAT THE SERVER DOES WITH THE VISIT MATTERS AS MUCH AS WHAT IT RETURNS: the
 * same request records the authoritative answer locally, so simply arriving
 * here repairs a missed notification for this tenant.
 *
 * RTL COMES FROM LOGICAL PROPERTIES, not a direction check — `ms-`/`me-`/
 * `text-start` rather than left/right — so the layout mirrors itself under
 * DirectionProvider with no branch to keep in step.
 */

interface BillingReturn {
  has_access: boolean;
  status: string | null;
  plan: string | null;
  access_until: string | null;
  cancel_at_period_end: boolean;
  checkout_status: string | null;
}

function BillingReturnContent() {
  const { apiClient } = useAuth();
  const t = useTranslation('admin');
  const searchParams = useSearchParams();

  // Passed through to the server, never trusted here. It only names WHICH
  // session to ask about; the server decides what it means.
  const reference = searchParams.get('checkout') ?? '';

  const { data, loading, error } = useFetch(async () => {
    const query = reference !== '' ? `?checkout=${encodeURIComponent(reference)}` : '';
    const res = await apiClient(`/api/v1/billing/return${query}`);
    if (!res.ok) {
      throw new Error(
        t('billing.return.error.load', 'We could not confirm your payment just now.')
      );
    }
    return (await res.json()).data as BillingReturn;
  }, [apiClient, reference]);

  if (loading) {
    return (
      <p className="text-muted-foreground">
        {t('billing.return.checking', 'Checking your payment…')}
      </p>
    );
  }

  // AN ERROR IS NOT A REFUSAL. Reaching the billing service can fail while the
  // payment is perfectly fine, so this says what is actually known — that we
  // could not check — and points at the page that will show the real state,
  // rather than announcing a failure the payer did not have.
  if (error !== null || data === null) {
    return (
      <div className="flex flex-col items-start gap-4">
        <Badge variant="secondary">{t('billing.return.unknown', 'Not confirmed yet')}</Badge>
        <p className="max-w-prose text-muted-foreground">
          {t(
            'billing.return.unknown.detail',
            'Your payment may still have gone through. We could not reach the billing service to confirm it just now, and your account will update by itself shortly.'
          )}
        </p>
        <Button asChild>
          <Link href="/billing">{t('billing.return.toBilling', 'Go to billing')}</Link>
        </Button>
      </div>
    );
  }

  if (data.has_access) {
    return (
      <div className="flex flex-col items-start gap-4">
        <Badge variant="success">{t('billing.return.active', 'Your subscription is active')}</Badge>
        <dl className="grid gap-2 text-sm">
          {data.plan !== null && (
            <div className="flex gap-2">
              <dt className="text-muted-foreground">{t('billing.return.plan', 'Plan')}</dt>
              <dd className="font-medium">{data.plan}</dd>
            </div>
          )}
          {data.access_until !== null && (
            <div className="flex gap-2">
              <dt className="text-muted-foreground">
                {/* `cancel_at_period_end` does NOT mean locked now: access is live
                    until this date, so the wording changes but nothing stops. */}
                {data.cancel_at_period_end
                  ? t('billing.return.endsOn', 'Your plan ends on')
                  : t('billing.return.renewsOn', 'Renews on')}
              </dt>
              <dd className="font-medium">
                {new Date(data.access_until).toLocaleDateString()}
              </dd>
            </div>
          )}
        </dl>
        <Button asChild>
          <Link href="/billing">{t('billing.return.toBilling', 'Go to billing')}</Link>
        </Button>
      </div>
    );
  }

  // No access. Said without blaming the payer for it: a card can be declined
  // after a 3-D Secure challenge is passed, and a push transfer can still be
  // settling, so "not active yet" is both kinder and more accurate than
  // "payment failed".
  return (
    <div className="flex flex-col items-start gap-4">
      <Badge variant="secondary">{t('billing.return.notActive', 'Not active yet')}</Badge>
      <p className="max-w-prose text-muted-foreground">
        {t(
          'billing.return.notActive.detail',
          'We have not been told your payment completed. If you have just paid, this can take a moment — your account will update by itself. If your card was declined, you can try again.'
        )}
      </p>
      <div className="flex gap-2">
        <Button asChild>
          <Link href="/billing">{t('billing.return.toBilling', 'Go to billing')}</Link>
        </Button>
      </div>
    </div>
  );
}

export default function BillingReturnPage() {
  const t = useTranslation('admin');

  return (
    <div className="space-y-6">
      <AdminHeader
        title={t('billing.return.title', 'Payment')}
        description={t('billing.return.subtitle', 'Confirming your payment with the billing service.')}
      />
      {/* useSearchParams needs a Suspense boundary to avoid opting the whole
          route into client-side rendering at build time. */}
      <Suspense fallback={null}>
        <BillingReturnContent />
      </Suspense>
    </div>
  );
}
