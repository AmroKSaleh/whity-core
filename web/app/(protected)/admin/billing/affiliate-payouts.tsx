'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { Input } from '@/components/ui/input';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

/**
 * PAYING AN AFFILIATE WHAT THEY ARE OWED.
 *
 * The ledger accrued nightly and `affiliate_payouts` had nothing writing to it,
 * so a balance grew with no way to settle it. Paying somebody out of band would
 * have left the ledger still claiming the money was owed — and the next person
 * assembling a payout would have paid it again.
 *
 * ── Three amounts, because they are three different facts ──────────────────
 *
 * What they earned, what is kept back and remitted on their behalf, and what
 * actually leaves the bank. Showing one and calling it another is how a payout
 * register stops reconciling with a bank statement, and the gap is exactly the
 * tax — so it reads as a rounding problem until somebody adds it up.
 *
 * The withholding rate is stated on every row EVEN WHEN IT IS ZERO. A default of
 * zero that nobody notices is the failure this whole area keeps producing, so it
 * is said out loud rather than left implicit.
 *
 * ── Nothing here moves money ───────────────────────────────────────────────
 *
 * Assembling produces a draft with a net figure for a person to transfer. They
 * come back afterwards and record the bank reference, which is why there is no
 * state between draft and paid: the software cannot know the money moved, only
 * be told.
 */

interface Payout {
  id: number;
  total_minor: number;
  withholding_bp: number;
  withholding_minor: number;
  net_minor: number;
  currency: string;
  status: string;
  reference: string | null;
  paid_at: string | null;
  commission_count: number;
}

interface Balance {
  currency: string;
  amount_minor: number;
}

export function AffiliatePayouts({
  affiliateId,
  balances,
  onChanged,
}: {
  affiliateId: number;
  balances: Balance[];
  onChanged: () => void;
}) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [busy, setBusy] = useState(false);
  const [settling, setSettling] = useState<Payout | null>(null);
  const [reference, setReference] = useState('');

  const { data, refetch } = useFetch(async () => {
    const res = await apiClient(`/api/v1/affiliates/${affiliateId}/payouts`);
    if (!res.ok) {
      throw new Error(t('payout.error.load', 'Failed to load payouts'));
    }
    return ((await res.json()).data ?? []) as Payout[];
  }, [apiClient, affiliateId]);

  const refresh = () => {
    refetch();
    onChanged();
  };

  /** The server's own words when it refuses — it names the reason, we do not. */
  const reasonFrom = async (res: Response, fallback: string): Promise<string> => {
    const body = (await res.json().catch(() => null)) as
      | { error?: string; details?: Record<string, string> }
      | null;
    return Object.values(body?.details ?? {})[0] ?? body?.error ?? fallback;
  };

  const assemble = async (currency: string) => {
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/affiliates/${affiliateId}/payouts`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ currency }),
      });
      if (!res.ok) {
        addToast(
          await reasonFrom(res, t('payout.assemble.failed', 'Could not assemble a payout.')),
          'error'
        );
        return;
      }
      addToast(
        t('payout.assembled', 'Payout drafted. Make the transfer, then record its reference here.'),
        'success'
      );
      refresh();
    } finally {
      setBusy(false);
    }
  };

  const settle = async () => {
    if (settling === null) {
      return;
    }
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/affiliate-payouts/${settling.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ reference }),
      });
      if (!res.ok) {
        addToast(await reasonFrom(res, t('payout.settle.failed', 'Could not record this payment.')), 'error');
        return;
      }
      addToast(t('payout.settled', 'Payment recorded.'), 'success');
      setSettling(null);
      setReference('');
      refresh();
    } finally {
      setBusy(false);
    }
  };

  const discard = async (payout: Payout) => {
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/affiliate-payouts/${payout.id}`, { method: 'DELETE' });
      if (!res.ok) {
        addToast(await reasonFrom(res, t('payout.discard.failed', 'Could not discard this draft.')), 'error');
        return;
      }
      addToast(
        t('payout.discarded', 'Draft discarded. What it covered is owed again.'),
        'success'
      );
      refresh();
    } finally {
      setBusy(false);
    }
  };

  const percent = (bp: number): string => (bp / 100).toFixed(2).replace(/\.00$/, '');

  const payouts = data ?? [];

  return (
    <div className="space-y-3 ps-4 border-s py-3">
      {/* WHAT CAN BE PAID RIGHT NOW, one button per currency — because a payout
          covers one currency and a combined one would need a conversion rate
          nobody stored. */}
      {balances.length > 0 && (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-sm text-muted-foreground">
            {t('payout.owed', 'Owed now:')}
          </span>
          {balances.map((b) => (
            <Button
              key={b.currency}
              size="sm"
              variant="outline"
              disabled={busy || b.amount_minor <= 0}
              onClick={() => assemble(b.currency)}
            >
              {t('payout.assemble', 'Pay {amount} {currency}', {
                amount: b.amount_minor,
                currency: b.currency,
              })}
            </Button>
          ))}
          {/* A NEGATIVE BALANCE IS NOT A DEBT TO COLLECT. Refunds took more back
              than was earned; it nets against what comes next. Said plainly
              rather than shown as a disabled button with no explanation. */}
          {balances.some((b) => b.amount_minor <= 0) && (
            <span className="text-xs text-muted-foreground">
              {t(
                'payout.carried',
                'A balance at or below zero carries forward and nets against later earnings.'
              )}
            </span>
          )}
        </div>
      )}

      {payouts.length === 0 && (
        <p className="text-sm text-muted-foreground">
          {t('payout.none', 'No payouts yet.')}
        </p>
      )}

      {payouts.length > 0 && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-muted-foreground border-b">
                <th className="text-start font-medium py-1 pe-3">{t('payout.col.earned', 'Earned')}</th>
                <th className="text-start font-medium py-1 pe-3">{t('payout.col.withheld', 'Withheld')}</th>
                <th className="text-start font-medium py-1 pe-3">{t('payout.col.net', 'Transferred')}</th>
                <th className="text-start font-medium py-1 pe-3">{t('payout.col.status', 'Status')}</th>
                <th className="text-start font-medium py-1" />
              </tr>
            </thead>
            <tbody>
              {payouts.map((p) => (
                <tr key={p.id} className="border-b last:border-0">
                  <td className="py-1 pe-3 tabular-nums">
                    {p.total_minor} {p.currency}
                    <span className="text-xs text-muted-foreground ms-1">
                      {t('payout.lines', '({count} commissions)', { count: p.commission_count })}
                    </span>
                  </td>
                  <td className="py-1 pe-3 tabular-nums">
                    {p.withholding_minor} {p.currency}
                    {/* STATED EVEN AT ZERO. A withholding of nothing is a
                        decision that was made, not a blank. */}
                    <span className="text-xs text-muted-foreground ms-1">
                      {t('payout.rate', 'at {percent}%', { percent: percent(p.withholding_bp) })}
                    </span>
                  </td>
                  <td className="py-1 pe-3 tabular-nums font-medium">
                    {p.net_minor} {p.currency}
                  </td>
                  <td className="py-1 pe-3">
                    {p.status === 'paid' ? (
                      <div>
                        <Badge variant="secondary">{t('payout.paid', 'Paid')}</Badge>
                        {p.reference && (
                          <div className="text-xs text-muted-foreground font-mono">{p.reference}</div>
                        )}
                      </div>
                    ) : (
                      <Badge>{t('payout.draft', 'Draft')}</Badge>
                    )}
                  </td>
                  <td className="py-1">
                    {p.status !== 'paid' && (
                      <div className="flex gap-1 justify-end">
                        <Button
                          size="sm"
                          variant="ghost"
                          disabled={busy}
                          onClick={() => {
                            setSettling(p);
                            setReference('');
                          }}
                        >
                          {t('payout.record', 'Record payment')}
                        </Button>
                        <Button size="sm" variant="ghost" disabled={busy} onClick={() => discard(p)}>
                          {t('payout.discard', 'Discard')}
                        </Button>
                      </div>
                    )}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Dialog open={settling !== null} onOpenChange={(open) => !open && setSettling(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('payout.record', 'Record payment')}</DialogTitle>
            <DialogDescription>
              {settling &&
                t(
                  'payout.record.description',
                  'Confirm that {amount} {currency} has been transferred. This cannot be undone — the reference is the only record of which payment settled this payout.',
                  { amount: settling.net_minor, currency: settling.currency }
                )}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-1">
            <label htmlFor="payout-reference" className="text-sm font-medium">
              {t('payout.field.reference', 'Payment reference')}
            </label>
            <Input
              id="payout-reference"
              value={reference}
              onChange={(e) => setReference(e.target.value)}
              placeholder="BANK-2026-0412"
            />
            <p className="text-xs text-muted-foreground">
              {t(
                'payout.field.reference.help',
                'Whatever you can quote back when they ask where their money went — a bank or transfer reference.'
              )}
            </p>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setSettling(null)} disabled={busy}>
              {t('common.cancel', 'Cancel')}
            </Button>
            <Button onClick={settle} disabled={busy || !reference.trim()}>
              {t('payout.record.confirm', 'Mark as paid')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
