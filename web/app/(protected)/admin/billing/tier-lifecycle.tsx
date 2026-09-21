'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';

/**
 * RETIRING A TIER WITHOUT LOSING WHO WAS ON IT.
 *
 * Deleting a tier used to succeed on anything, and the database made it look
 * clean: `tenant_plan.plan_id` and `invoices.plan_id` are both ON DELETE SET
 * NULL, so tidying up an old tier silently detached its live subscribers and
 * blanked it out of invoices somebody had already paid.
 *
 * The server refuses that now. This screen's job is to make the refusal
 * ACTIONABLE rather than a dead end — which means showing what a tier still
 * holds BEFORE anybody reaches for delete, and offering the two ways out.
 *
 * ── Three states, three different affordances ──────────────────────────────
 *
 *   nothing ever used it        Delete is offered.
 *   workspaces on it now        Delete is not offered; "Move workspaces" is,
 *                               because that is the route to being able to
 *                               delete it.
 *   invoices against it         Delete is never offered and never will be.
 *                               Saying so plainly beats a disabled button that
 *                               invites somebody to keep trying.
 *
 * Retire is available in every state: it is the honest end for a tier that has
 * customers or history — off sale, with every reference still pointing at
 * something that has a name.
 */

interface Usage {
  subscribers: number;
  invoices: number;
  prices: number;
  limits: number;
  promotions: number;
  deletable: boolean;
  permanently_undeletable: boolean;
  refusal_reason: string | null;
}

interface TierRef {
  id: number;
  name: string;
  is_active: boolean;
}

export function TierLifecycle({
  plan,
  allPlans,
  onChanged,
}: {
  plan: TierRef;
  allPlans: TierRef[];
  onChanged: () => void;
}) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [moving, setMoving] = useState(false);
  const [destination, setDestination] = useState<string>('');
  const [busy, setBusy] = useState(false);

  const { data: usage, refetch } = useFetch(async () => {
    const res = await apiClient(`/api/v1/plans/${plan.id}/usage`);
    if (!res.ok) {
      return null;
    }
    return (await res.json()).data as Usage;
  }, [apiClient, plan.id]);

  if (usage === null || usage === undefined) {
    return null;
  }

  const refresh = () => {
    refetch();
    onChanged();
  };

  const remove = async () => {
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/plans/${plan.id}`, { method: 'DELETE' });
      if (!res.ok) {
        // The server's own words. It names the counts and the remedy, and no
        // rewording here would be more accurate than that.
        const body = (await res.json().catch(() => null)) as
          | { error?: string; details?: Record<string, string> }
          | null;
        addToast(
          Object.values(body?.details ?? {})[0] ??
            body?.error ??
            t('tier.delete.failed', 'Could not delete this tier.'),
          'error'
        );
        return;
      }
      addToast(t('tier.deleted', 'Tier deleted.'), 'success');
      onChanged();
    } finally {
      setBusy(false);
    }
  };

  const retire = async () => {
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/plans/${plan.id}`, {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ is_active: false }),
      });
      addToast(
        res.ok
          ? t('tier.retired', 'Tier retired. It is off sale; everyone on it keeps it.')
          : t('tier.retire.failed', 'Could not retire this tier.'),
        res.ok ? 'success' : 'error'
      );
      if (res.ok) {
        refresh();
      }
    } finally {
      setBusy(false);
    }
  };

  const move = async () => {
    if (destination === '') {
      return;
    }
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/plans/${plan.id}/move-subscribers`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ to_plan_id: Number(destination) }),
      });
      if (!res.ok) {
        const body = (await res.json().catch(() => null)) as
          | { error?: string; details?: Record<string, string> }
          | null;
        addToast(
          Object.values(body?.details ?? {})[0] ??
            body?.error ??
            t('tier.move.failed', 'Could not move these workspaces.'),
          'error'
        );
        return;
      }
      const moved = ((await res.json()).data?.moved ?? 0) as number;
      addToast(
        t('tier.moved', '{count} workspace(s) moved.', { count: String(moved) }),
        'success'
      );
      setMoving(false);
      setDestination('');
      refresh();
    } finally {
      setBusy(false);
    }
  };

  const elsewhere = allPlans.filter((p) => p.id !== plan.id);

  return (
    <div className="mt-3 flex flex-wrap items-center gap-2 border-t pt-3" data-testid={`lifecycle-${plan.id}`}>
      {/* WHAT IT HOLDS, always visible — not hidden behind the delete attempt.
          Somebody deciding whether to retire a tier needs to know it has
          subscribers before they act, not after they are refused. */}
      <span className="text-xs text-muted-foreground">
        {t('tier.usage.subscribers', '{n} workspace(s)', { n: String(usage.subscribers) })}
        {' · '}
        {t('tier.usage.invoices', '{n} invoice(s)', { n: String(usage.invoices) })}
      </span>

      {usage.permanently_undeletable && (
        <Badge variant="outline" title={usage.refusal_reason ?? undefined}>
          {t('tier.retireOnly', 'Retire only')}
        </Badge>
      )}

      <span className="flex-1" />

      {usage.subscribers > 0 && elsewhere.length > 0 && (
        <Button
          size="sm"
          variant="outline"
          disabled={busy}
          onClick={() => setMoving(true)}
          data-testid={`move-subscribers-${plan.id}`}
        >
          {t('tier.move', 'Move workspaces')}
        </Button>
      )}

      {plan.is_active && (
        <Button
          size="sm"
          variant="outline"
          disabled={busy}
          onClick={() => void retire()}
          data-testid={`retire-${plan.id}`}
        >
          {t('tier.retire', 'Retire')}
        </Button>
      )}

      {/* OFFERED ONLY WHEN IT WOULD SUCCEED. A disabled delete button with no
          explanation is how somebody ends up filing a bug; the badge and the
          counts above say why it is absent. */}
      {usage.deletable && (
        <Button
          size="sm"
          variant="destructive"
          disabled={busy}
          onClick={() => void remove()}
          data-testid={`delete-plan-${plan.id}`}
        >
          {t('tier.delete', 'Delete')}
        </Button>
      )}

      <Dialog open={moving} onOpenChange={(open) => !open && setMoving(false)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('tier.move.title', 'Move workspaces to another tier')}</DialogTitle>
            <DialogDescription>
              {t(
                'tier.move.description',
                'Every workspace on this tier moves to the one you choose, and gets that tier’s limits immediately.'
              )}
            </DialogDescription>
          </DialogHeader>

          <Select value={destination} onValueChange={setDestination}>
            <SelectTrigger data-testid={`move-destination-${plan.id}`}>
              <SelectValue placeholder={t('tier.move.choose', 'Choose a tier')} />
            </SelectTrigger>
            <SelectContent>
              {elsewhere.map((p) => (
                <SelectItem key={p.id} value={String(p.id)}>
                  {p.name}
                  {!p.is_active ? ` (${t('billing.plan.inactive', 'Inactive')})` : ''}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>

          <DialogFooter>
            <Button variant="outline" onClick={() => setMoving(false)}>
              {t('tier.move.cancel', 'Cancel')}
            </Button>
            <Button
              onClick={() => void move()}
              disabled={destination === '' || busy}
              data-testid={`confirm-move-${plan.id}`}
            >
              {t('tier.move.confirm', 'Move {n} workspace(s)', { n: String(usage.subscribers) })}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}
