'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useCapabilities } from '@/hooks/useCapabilities';
import { PLANS_MANAGE } from '@/lib/capabilities';
import { AdminHeader } from '@/components/admin/admin-header';
import { DataTable, type DataTableColumn } from '@/components/ui/data-table';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import { Badge } from '@amroksaleh/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { IconPlus } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * PROMOTIONS: early birds, offers and promo codes — one list, because they are
 * one thing.
 *
 * The only structural difference is how a promotion is FOUND. One carrying a
 * code must be typed by the customer; one without applies automatically to
 * whoever qualifies. So the table shows the code column empty for an early bird
 * rather than splitting the screen in two, and the create form makes the code
 * field optional with the consequence spelled out beside it.
 *
 * REDEMPTIONS ARE SHOWN AGAINST THE CAP, because "how much of this early bird is
 * left" is the question this screen is opened to answer. A count with no cap
 * beside it says nothing an operator can act on.
 *
 * RETIRED PROMOTIONS STAY LISTED, greyed. A campaign that ended is the
 * explanation for a discount somebody is querying, and a list of only live ones
 * cannot give it.
 */

interface Promotion {
  id: number;
  name: string;
  code: string | null;
  percent_off: number | null;
  amount_off: number | null;
  currency: string | null;
  starts_at: string | null;
  ends_at: string | null;
  max_redemptions: number | null;
  max_redemptions_per_tenant: number;
  is_active: boolean;
  redemption_count: number;
}

export default function PromotionsPage() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');
  const canManage = hasPermission(PLANS_MANAGE);

  const { data, loading, error, refetch } = useFetch(async () => {
    const res = await apiClient('/api/v1/promotions');
    if (!res.ok) {
      throw new Error(t('promotions.error.load', 'Failed to load promotions'));
    }
    return ((await res.json()).data ?? []) as Promotion[];
  }, [apiClient]);

  const [creating, setCreating] = useState(false);

  const rows = (data ?? []).map((p) => ({
    ...p,
    // The discount as a person reads it. A percentage has no currency; a fixed
    // amount is meaningless without one, so both are rendered from the field
    // that is actually set rather than from a guess.
    discount:
      p.percent_off !== null
        ? `${p.percent_off}%`
        : `${p.amount_off} ${p.currency ?? ''}`.trim(),
    // "3 of 50" or just "3" when the allocation is unlimited — a bare count
    // against no cap tells an operator nothing they can act on.
    taken:
      p.max_redemptions === null
        ? String(p.redemption_count)
        : `${p.redemption_count} / ${p.max_redemptions}`,
    kind: p.code ?? t('promotions.kind.automatic', 'Automatic'),
  }));
  type Row = (typeof rows)[number];

  const columns: DataTableColumn<Row>[] = [
    {
      accessorKey: 'name',
      header: t('promotions.table.name', 'Name'),
      enableSorting: true,
      enableColumnFilter: true,
    },
    {
      accessorKey: 'kind',
      header: t('promotions.table.code', 'Code'),
      enableSorting: true,
      cell: (row) =>
        row.code ? (
          <code className="text-xs">{row.code}</code>
        ) : (
          <span className="text-xs text-muted-foreground">
            {t('promotions.kind.automatic', 'Automatic')}
          </span>
        ),
    },
    { accessorKey: 'discount', header: t('promotions.table.discount', 'Discount') },
    { accessorKey: 'taken', header: t('promotions.table.taken', 'Redeemed') },
    {
      accessorKey: 'is_active',
      header: t('promotions.table.status', 'Status'),
      cell: (row) =>
        row.is_active ? (
          <Badge variant="secondary">{t('promotions.status.live', 'Live')}</Badge>
        ) : (
          <Badge variant="outline">{t('promotions.status.retired', 'Retired')}</Badge>
        ),
    },
  ];

  const rowActions = (promotion: Row) =>
    canManage && promotion.is_active ? (
      <Button
        variant="ghost"
        size="sm"
        data-testid={`retire-${promotion.id}`}
        onClick={async () => {
          const res = await apiClient(`/api/v1/promotions/${promotion.id}`, { method: 'DELETE' });
          if (!res.ok) {
            const body = (await res.json().catch(() => null)) as { error?: string } | null;
            addToast(body?.error ?? t('promotions.retireFailed', 'Could not retire this promotion.'), 'error');
            return;
          }
          // "Retired", not "deleted" — the row survives and the list will show
          // it greyed. Saying "deleted" would describe something else.
          addToast(t('promotions.retired', 'Promotion retired.'), 'success');
          refetch();
        }}
      >
        {t('promotions.retire', 'Retire')}
      </Button>
    ) : null;

  return (
    <div className="space-y-4">
      <AdminHeader
        title={t('promotions.title', 'Promotions')}
        description={t(
          'promotions.description',
          'Early birds, offers and promo codes. A promotion with a code must be typed by the customer; one without applies automatically.'
        )}
        action={
          canManage ? (
            <Button className="gap-1" data-testid="new-promotion" onClick={() => setCreating(true)}>
              <IconPlus size={16} />
              {t('promotions.new', 'New promotion')}
            </Button>
          ) : null
        }
      />

      {error && <p className="text-sm text-destructive">{error}</p>}

      <DataTable
        columns={columns}
        data={rows}
        isLoading={loading}
        rowActions={rowActions}
        emptyState={{
          title: t('promotions.empty.title', 'No promotions yet'),
          description: t(
            'promotions.empty.description',
            'An early bird applies automatically to whoever qualifies; a promo code has to be typed. Both are made here.'
          ),
        }}
      />

      {creating && (
        <CreatePromotionDialog
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            refetch();
          }}
        />
      )}
    </div>
  );
}

/**
 * Creating one.
 *
 * THE CODE FIELD IS OPTIONAL AND SAYS WHAT LEAVING IT EMPTY MEANS. That single
 * field is the whole difference between a promo code and an early bird, and an
 * operator who leaves it blank expecting a code would otherwise have created a
 * discount that applies to everybody automatically — which is a costly thing to
 * discover from a revenue report.
 */
function CreatePromotionDialog({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [kind, setKind] = useState<'percent' | 'amount'>('percent');
  const [percent, setPercent] = useState('');
  const [amount, setAmount] = useState('');
  const [currency, setCurrency] = useState('SAR');
  const [endsAt, setEndsAt] = useState('');
  const [maxRedemptions, setMaxRedemptions] = useState('');
  const [saving, setSaving] = useState(false);

  const percentValue = Number(percent);
  const amountValue = Number(amount);
  const valueUsable =
    kind === 'percent'
      ? percent.trim() !== '' && Number.isInteger(percentValue) && percentValue > 0 && percentValue <= 100
      : amount.trim() !== '' && Number.isInteger(amountValue) && amountValue > 0;
  const canSave = name.trim() !== '' && valueUsable;

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('promotions.create.title', 'New promotion')}</DialogTitle>
          <DialogDescription>
            {t(
              'promotions.create.description',
              'Leave the code empty for an early bird or offer — it will apply automatically to everyone who qualifies.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3">
          <label className="block space-y-1">
            <span className="text-sm">{t('promotions.field.name', 'Name (for your own records)')}</span>
            <Input value={name} data-testid="promo-name" onChange={(e) => setName(e.target.value)} />
          </label>

          <label className="block space-y-1">
            <span className="text-sm">{t('promotions.field.code', 'Code (optional)')}</span>
            <Input
              value={code}
              placeholder={t('promotions.field.codePlaceholder', 'Empty = applies automatically')}
              data-testid="promo-code"
              onChange={(e) => setCode(e.target.value.toUpperCase())}
            />
          </label>

          <div className="flex gap-2">
            <Button
              type="button"
              variant={kind === 'percent' ? 'default' : 'outline'}
              size="sm"
              data-testid="promo-kind-percent"
              onClick={() => setKind('percent')}
            >
              {t('promotions.kind.percent', 'Percentage off')}
            </Button>
            <Button
              type="button"
              variant={kind === 'amount' ? 'default' : 'outline'}
              size="sm"
              data-testid="promo-kind-amount"
              onClick={() => setKind('amount')}
            >
              {t('promotions.kind.amount', 'Fixed amount off')}
            </Button>
          </div>

          {kind === 'percent' ? (
            <label className="block space-y-1">
              <span className="text-sm">{t('promotions.field.percent', 'Percentage (1–100)')}</span>
              <Input
                type="number"
                min={1}
                max={100}
                value={percent}
                data-testid="promo-percent"
                onChange={(e) => setPercent(e.target.value)}
              />
              <span className="text-xs text-muted-foreground">
                {t('promotions.field.percentHint', 'A percentage has no currency, so it works on every price.')}
              </span>
            </label>
          ) : (
            <div className="flex gap-2">
              <label className="block flex-1 space-y-1">
                <span className="text-sm">
                  {t('promotions.field.amount', 'Amount in minor units (5000 = 50.00)')}
                </span>
                <Input
                  type="number"
                  min={1}
                  step={1}
                  value={amount}
                  data-testid="promo-amount"
                  onChange={(e) => setAmount(e.target.value)}
                />
              </label>
              <label className="block w-24 space-y-1">
                <span className="text-sm">{t('promotions.field.currency', 'Currency')}</span>
                <Input
                  value={currency}
                  maxLength={3}
                  data-testid="promo-currency"
                  onChange={(e) => setCurrency(e.target.value.toUpperCase())}
                />
              </label>
            </div>
          )}

          <div className="flex gap-2">
            <label className="block flex-1 space-y-1">
              <span className="text-sm">{t('promotions.field.endsAt', 'Ends (optional)')}</span>
              <Input
                type="date"
                value={endsAt}
                data-testid="promo-ends"
                onChange={(e) => setEndsAt(e.target.value)}
              />
            </label>
            <label className="block flex-1 space-y-1">
              <span className="text-sm">{t('promotions.field.max', 'Total redemptions (optional)')}</span>
              <Input
                type="number"
                min={1}
                value={maxRedemptions}
                placeholder={t('promotions.field.maxPlaceholder', 'Unlimited')}
                data-testid="promo-max"
                onChange={(e) => setMaxRedemptions(e.target.value)}
              />
            </label>
          </div>
        </div>

        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('common.cancel', 'Cancel')}
          </Button>
          <Button
            disabled={!canSave || saving}
            data-testid="promo-save"
            onClick={async () => {
              setSaving(true);
              const payload: Record<string, unknown> = { name: name.trim() };
              if (code.trim() !== '') payload.code = code.trim();
              if (kind === 'percent') {
                payload.percent_off = percentValue;
              } else {
                payload.amount_off = amountValue;
                payload.currency = currency;
              }
              // A date input gives a bare day; the window closes at the START of
              // it, since the service treats `ends_at` as exclusive. Sending the
              // day alone would end the campaign a day early without saying so.
              if (endsAt !== '') payload.ends_at = `${endsAt} 00:00:00`;
              if (maxRedemptions.trim() !== '') payload.max_redemptions = Number(maxRedemptions);

              const res = await apiClient('/api/v1/promotions', {
                method: 'POST',
                body: JSON.stringify(payload),
              });
              setSaving(false);

              if (!res.ok) {
                const body = (await res.json().catch(() => null)) as
                  | { error?: string; details?: Record<string, string> }
                  | null;
                // The server's own words. A 409 explains that the code is taken
                // and what to do; a 422 names the field.
                const detail = body?.details ? Object.values(body.details)[0] : undefined;
                addToast(
                  detail ?? body?.error ?? t('promotions.saveFailed', 'Could not create this promotion.'),
                  'error'
                );
                return;
              }

              addToast(t('promotions.saved', 'Promotion created.'), 'success');
              onSaved();
            }}
          >
            {t('promotions.create.save', 'Create')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
