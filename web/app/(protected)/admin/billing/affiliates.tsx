'use client';

import { Fragment, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { Input } from '@/components/ui/input';
import { EmptyState } from '@amroksaleh/ui/empty-state';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { IconPlus, IconChevronDown, IconChevronRight } from '@tabler/icons-react';
import { AffiliatePayouts } from './affiliate-payouts';

/**
 * WHO SENDS US CUSTOMERS, AND WHAT WE OWE THEM.
 *
 * Until this screen existed there was no way to create an affiliate at all —
 * which meant no code existed, so no referral could be attributed and no
 * commission could ever be earned. Every other part of the programme was built,
 * tested and completely unreachable: the kind of gap that produces a feature
 * which looks finished and earns nobody anything.
 *
 * ── The rate is entered in basis points, and the field says so ─────────────
 *
 * 2000 is twenty per cent. That is deliberate rather than lazy, and it is the
 * same decision the price fields on this page make about minor units: a field
 * accepting "20%" has to decide what "20.5" means and where to round it, and a
 * rate held as a fraction is a rounding argument with somebody about their own
 * money. The live preview underneath shows the percentage, so nobody has to do
 * the arithmetic to check they typed what they meant.
 *
 * ── Balances are per currency, never summed ────────────────────────────────
 *
 * Commissions are recorded in whatever the customer paid in. One total across
 * dinars and dollars would be wrong in a way nobody can see on a screen, so
 * each currency gets its own line — uglier, and the only honest shape. A payout
 * is made in one currency at a time anyway.
 *
 * ── There is no delete ─────────────────────────────────────────────────────
 *
 * Deactivating stops future earning; nothing removes an affiliate, because the
 * commissions owed to somebody point at the row. This mirrors how a retired
 * tier is kept rather than dropped, for the same reason: the record is the
 * evidence of what was agreed.
 */

interface Balance {
  currency: string;
  amount_minor: number;
}

interface Affiliate {
  id: number;
  code: string;
  name: string;
  email: string | null;
  commission_bp: number;
  window_months: number;
  promotion_id: number | null;
  is_active: boolean;
  referral_count: number;
  converted_count: number;
  balances: Balance[];
}

/** 100% in basis points, mirroring the server's CommissionCalculator. */
const FULL_RATE_BP = 10000;

/** The ceiling the server enforces. Stated here so the form can say so first. */
const MAX_COMMISSION_BP = 5000;

export function Affiliates() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [creating, setCreating] = useState(false);
  // WHOSE PAYOUTS ARE OPEN. Loaded on demand rather than with the list: most
  // rows are not being paid today, and one request per affiliate on every
  // render would make a long list slow for a screen nobody is using that way.
  const [expanded, setExpanded] = useState<number | null>(null);
  const [busy, setBusy] = useState(false);
  const [code, setCode] = useState('');
  const [name, setName] = useState('');
  const [email, setEmail] = useState('');
  const [rate, setRate] = useState('2000');
  const [window, setWindow] = useState('12');

  const { data, loading, error, refetch } = useFetch(async () => {
    const res = await apiClient('/api/v1/affiliates');
    if (!res.ok) {
      throw new Error(t('affiliate.error.load', 'Failed to load the affiliate list'));
    }
    return ((await res.json()).data ?? []) as Affiliate[];
  }, [apiClient]);

  const reset = () => {
    setCode('');
    setName('');
    setEmail('');
    setRate('2000');
    setWindow('12');
  };

  const create = async () => {
    setBusy(true);
    try {
      const res = await apiClient('/api/v1/affiliates', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          code,
          name,
          commission_bp: Number(rate),
          window_months: Number(window),
          ...(email.trim() ? { email } : {}),
        }),
      });

      if (!res.ok) {
        // THE SERVER'S OWN WORDS. It names which field and why — "codes are
        // matched without regard to case", "a rate is basis points" — and no
        // rewording here would be more accurate than that.
        const body = (await res.json().catch(() => null)) as
          | { error?: string; details?: Record<string, string> }
          | null;
        addToast(
          Object.values(body?.details ?? {})[0] ??
            body?.error ??
            t('affiliate.create.failed', 'Could not create this affiliate.'),
          'error'
        );
        return;
      }

      addToast(t('affiliate.created', 'Affiliate created.'), 'success');
      setCreating(false);
      reset();
      refetch();
    } finally {
      setBusy(false);
    }
  };

  const setActive = async (affiliate: Affiliate, active: boolean) => {
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/affiliates/${affiliate.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_active: active }),
      });
      addToast(
        res.ok
          ? active
            ? t('affiliate.reactivated', 'Affiliate reactivated. New referrals will earn again.')
            : t(
                'affiliate.deactivated',
                'Affiliate deactivated. They stop earning; what they are already owed is unchanged.'
              )
          : t('affiliate.update.failed', 'Could not update this affiliate.'),
        res.ok ? 'success' : 'error'
      );
      if (res.ok) {
        refetch();
      }
    } finally {
      setBusy(false);
    }
  };

  const percent = (bp: number): string => (bp / (FULL_RATE_BP / 100)).toFixed(2).replace(/\.00$/, '');

  const affiliates = data ?? [];

  return (
    <section className="space-y-3 pt-6">
      <div className="flex items-center justify-between gap-3 flex-wrap">
        <div>
          <h2 className="text-lg font-semibold">{t('affiliate.title', 'Affiliates')}</h2>
          <p className="text-sm text-muted-foreground">
            {t(
              'affiliate.description',
              'Who sends us customers, at what rate, and what they are owed. Amounts are minor units and already net of refunds.'
            )}
          </p>
        </div>
        <Button onClick={() => setCreating(true)} disabled={busy}>
          <IconPlus className="size-4" aria-hidden="true" />
          {t('affiliate.new', 'New affiliate')}
        </Button>
      </div>

      {loading && <p className="text-sm text-muted-foreground">{t('common.loading', 'Loading…')}</p>}
      {error && <p className="text-sm text-destructive">{error}</p>}

      {!loading && !error && affiliates.length === 0 && (
        <EmptyState
          title={t('affiliate.empty.title', 'No affiliates yet')}
          description={t(
            'affiliate.empty.description',
            'An affiliate is a code somebody puts in a link. Create one, and any workspace that signs up through it earns them a share of what it pays.'
          )}
        />
      )}

      {affiliates.length > 0 && (
        // The table scrolls in its own container rather than pushing the page
        // sideways, which is what a wide table does at phone width.
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-start text-muted-foreground border-b">
                <th className="text-start font-medium py-2 pe-3">{t('affiliate.col.code', 'Code')}</th>
                <th className="text-start font-medium py-2 pe-3">{t('affiliate.col.name', 'Name')}</th>
                <th className="text-start font-medium py-2 pe-3">{t('affiliate.col.rate', 'Rate')}</th>
                <th className="text-start font-medium py-2 pe-3">{t('affiliate.col.window', 'Window')}</th>
                <th className="text-start font-medium py-2 pe-3">
                  {t('affiliate.col.referrals', 'Referred')}
                </th>
                <th className="text-start font-medium py-2 pe-3">{t('affiliate.col.owed', 'Owed')}</th>
                <th className="text-start font-medium py-2" />
              </tr>
            </thead>
            <tbody>
              {affiliates.map((a) => (
                <Fragment key={a.id}>
                <tr className="border-b last:border-0">
                  <td className="py-2 pe-3 font-mono">{a.code}</td>
                  <td className="py-2 pe-3">
                    <div>{a.name}</div>
                    {a.email && <div className="text-xs text-muted-foreground">{a.email}</div>}
                  </td>
                  {/* Tabular figures so the rates line up down the column. */}
                  <td className="py-2 pe-3 tabular-nums">{percent(a.commission_bp)}%</td>
                  <td className="py-2 pe-3 tabular-nums">
                    {t('affiliate.window.months', '{count} months', { count: a.window_months })}
                  </td>
                  <td className="py-2 pe-3 tabular-nums">
                    {/* BOTH NUMBERS, because they answer different questions: how
                        many workspaces the link produced, and how many of those
                        ever paid for anything. */}
                    {t('affiliate.referrals.count', '{converted} of {total} paid', {
                      converted: a.converted_count,
                      total: a.referral_count,
                    })}
                  </td>
                  <td className="py-2 pe-3 tabular-nums">
                    {a.balances.length === 0 ? (
                      <span className="text-muted-foreground">—</span>
                    ) : (
                      a.balances.map((b) => (
                        <div key={b.currency}>
                          {b.amount_minor} {b.currency}
                        </div>
                      ))
                    )}
                  </td>
                  <td className="py-2">
                    <div className="flex items-center gap-2 justify-end">
                      {!a.is_active && (
                        <Badge variant="secondary">{t('affiliate.retired', 'Retired')}</Badge>
                      )}
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={() => setExpanded(expanded === a.id ? null : a.id)}
                        aria-expanded={expanded === a.id}
                      >
                        {expanded === a.id ? (
                          <IconChevronDown className="size-4" aria-hidden="true" />
                        ) : (
                          <IconChevronRight className="size-4" aria-hidden="true" />
                        )}
                        {t('affiliate.payouts', 'Payouts')}
                      </Button>
                      <Button
                        variant="ghost"
                        size="sm"
                        disabled={busy}
                        onClick={() => setActive(a, !a.is_active)}
                      >
                        {a.is_active
                          ? t('affiliate.deactivate', 'Deactivate')
                          : t('affiliate.reactivate', 'Reactivate')}
                      </Button>
                    </div>
                  </td>
                </tr>
                {expanded === a.id && (
                  <tr>
                    <td colSpan={7} className="p-0">
                      <AffiliatePayouts
                        affiliateId={a.id}
                        balances={a.balances}
                        onChanged={refetch}
                      />
                    </td>
                  </tr>
                )}
                </Fragment>
              ))}
            </tbody>
          </table>
        </div>
      )}

      <Dialog open={creating} onOpenChange={setCreating}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('affiliate.new', 'New affiliate')}</DialogTitle>
            <DialogDescription>
              {t(
                'affiliate.new.description',
                'The code goes in their link. Anyone who signs up through it earns them a share of what that workspace pays, for as long as the window lasts.'
              )}
            </DialogDescription>
          </DialogHeader>

          <div className="space-y-3">
            <div className="space-y-1">
              <label htmlFor="affiliate-code" className="text-sm font-medium">
                {t('affiliate.field.code', 'Code')}
              </label>
              <Input
                id="affiliate-code"
                value={code}
                onChange={(e) => setCode(e.target.value)}
                placeholder="SPRING26"
              />
              <p className="text-xs text-muted-foreground">
                {t(
                  'affiliate.field.code.help',
                  'Letters, digits, dots, dashes and underscores. It travels in a URL and gets typed by hand, so anything else would break somebody’s link.'
                )}
              </p>
            </div>

            <div className="space-y-1">
              <label htmlFor="affiliate-name" className="text-sm font-medium">
                {t('affiliate.field.name', 'Name')}
              </label>
              <Input id="affiliate-name" value={name} onChange={(e) => setName(e.target.value)} />
              <p className="text-xs text-muted-foreground">
                {t('affiliate.field.name.help', 'Who the payout is for.')}
              </p>
            </div>

            <div className="space-y-1">
              <label htmlFor="affiliate-email" className="text-sm font-medium">
                {t('affiliate.field.email', 'Email (optional)')}
              </label>
              <Input
                id="affiliate-email"
                type="email"
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                {t('affiliate.field.email.help', 'Where to reach them about a payout.')}
              </p>
            </div>

            <div className="space-y-1">
              <label htmlFor="affiliate-rate" className="text-sm font-medium">
                {t('affiliate.field.rate', 'Commission (basis points)')}
              </label>
              <Input
                id="affiliate-rate"
                inputMode="numeric"
                value={rate}
                onChange={(e) => setRate(e.target.value)}
              />
              {/* THE LIVE PREVIEW IS THE POINT OF THE UNIT CHOICE. Basis points
                  keep the arithmetic exact; this line means nobody has to do it
                  in their head to check they typed what they meant. */}
              <p className="text-xs text-muted-foreground">
                {t('affiliate.field.rate.help', '2000 = 20%. Up to {max}, on what a customer pays after discounts, excluding tax.', {
                  max: `${percent(MAX_COMMISSION_BP)}%`,
                })}
                {Number.isFinite(Number(rate)) && Number(rate) > 0 && (
                  <span className="ms-1 font-medium text-foreground">
                    {t('affiliate.field.rate.preview', '→ {percent}%', { percent: percent(Number(rate)) })}
                  </span>
                )}
              </p>
            </div>

            <div className="space-y-1">
              <label htmlFor="affiliate-window" className="text-sm font-medium">
                {t('affiliate.field.window', 'Earning window (months)')}
              </label>
              <Input
                id="affiliate-window"
                inputMode="numeric"
                value={window}
                onChange={(e) => setWindow(e.target.value)}
              />
              <p className="text-xs text-muted-foreground">
                {t(
                  'affiliate.field.window.help',
                  'Counted from the customer’s FIRST PAYMENT, not from the referral — a customer who takes two months to convert still earns a full window.'
                )}
              </p>
            </div>
          </div>

          <DialogFooter>
            <Button variant="outline" onClick={() => setCreating(false)} disabled={busy}>
              {t('common.cancel', 'Cancel')}
            </Button>
            <Button onClick={create} disabled={busy || !code.trim() || !name.trim()}>
              {t('affiliate.create', 'Create affiliate')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  );
}
