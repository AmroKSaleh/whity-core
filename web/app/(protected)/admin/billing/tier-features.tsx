'use client';

import { useMemo, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { Checkbox } from '@amroksaleh/ui/checkbox';
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
 * WHAT EACH TIER INCLUDES — the screen this feature exists for.
 *
 * Three tiers were priced at 5, 15 and 100 JOD and granted identical access,
 * because nothing ever read a plan's feature bundle at the moment access was
 * decided. Now it does, live, so the numbers typed here reach every workspace
 * on that tier immediately — including ones that subscribed months ago.
 *
 * ── Why a matrix and not a form per tier ───────────────────────────────────
 *
 * Pricing is a COMPARISON. Nobody decides what Pro includes in isolation; they
 * decide it against what Plus includes and what Professional includes, and the
 * question being answered is "is there a reason to move up". A form per tier
 * makes that comparison happen in the reader's memory, one screen at a time,
 * which is how you end up selling two tiers that differ in nothing.
 *
 * ── Live means live in both directions ─────────────────────────────────────
 *
 * Raising a limit needs no ceremony. LOWERING one is refused by the server with
 * a 409 naming how many workspaces it would restrict, and this turns that into
 * a confirmation carrying the number. It is deliberately not a checkbox on the
 * form: somebody reducing a limit should be told the consequence at the moment
 * they do it, not asked to have pre-agreed to it.
 *
 * ── Unlimited is not a big number ──────────────────────────────────────────
 *
 * -1 means no cap, so it is offered as its own control rather than expecting
 * anybody to know the convention. A blank field means "this tier says nothing
 * about it", which falls through to the platform baseline — different again
 * from both, and the difference is somebody's bill.
 */

interface CatalogueEntry {
  type: 'bool' | 'int';
  default: string;
  description: string;
  /** 'day' | 'week' | 'month' when the limit is consumed and resets. */
  period: string | null;
  /** The plugin that sells this limit, or null for core. */
  owner: string | null;
}

interface Tier {
  id: number;
  plan_key: string;
  name: string;
  is_active: boolean;
  is_addon: boolean;
  /**
   * The plugin that shipped this tier, or null for one the platform or the
   * operator owns. Shown so nobody wonders where a tier they never created came
   * from — a vertical product can replace the default catalogue with its own.
   */
  provider: string | null;
}

/** key => value, exactly as the tier stores it. A missing key is inherited. */
type Bundle = Record<string, string>;

interface LoadedTiers {
  catalogue: Record<string, CatalogueEntry>;
  tiers: Tier[];
  bundles: Record<number, Bundle>;
}

export function TierFeatures() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [edits, setEdits] = useState<Record<number, Bundle>>({});
  const [saving, setSaving] = useState<number | null>(null);
  const [reduction, setReduction] = useState<{ tierId: number; reason: string } | null>(null);

  // ONE FETCH FOR THE WHOLE SCREEN, through the shared hook rather than a
  // hand-rolled effect. The first draft called setState inside useEffect and
  // the lint rule caught it: that pattern cascades renders, and the hook exists
  // so every screen does not re-derive the cancellation and refetch logic.
  const { data, loading, refetch } = useFetch(async () => {
    const [catRes, plansRes] = await Promise.all([
      apiClient('/api/v1/plans/entitlement-catalogue'),
      apiClient('/api/v1/plans'),
    ]);

    // Not shouted about with a red banner: the commonest reason to land here
    // without permission is being an ordinary admin rather than whoever owns
    // pricing, and that is not an error state.
    if (!catRes.ok || !plansRes.ok) {
      return { catalogue: {}, tiers: [], bundles: {} } as LoadedTiers;
    }

    const catalogue = ((await catRes.json()).data ?? {}) as Record<string, CatalogueEntry>;
    const all = ((await plansRes.json()).data ?? []) as Tier[];

    // ADD-ONS ARE NOT TIERS and are left off. `devices` is bought beside a
    // subscription; giving it a column would invite somebody to price the whole
    // product into it.
    const tiers = all.filter((p) => !p.is_addon);

    const bundles: Record<number, Bundle> = {};
    await Promise.all(
      tiers.map(async (tier) => {
        const res = await apiClient(`/api/v1/plans/${tier.id}`);
        if (!res.ok) {
          bundles[tier.id] = {};
          return;
        }

        // The API returns each value already CAST — booleans as booleans,
        // limits as numbers. The editor works in the text form the API takes
        // back, so it is converted here rather than asking the server for a
        // second representation of the same rows.
        const typed = ((await res.json()).data?.entitlements ?? {}) as Record<
          string,
          boolean | number
        >;
        bundles[tier.id] = Object.fromEntries(
          Object.entries(typed).map(([k, v]) => [
            k,
            typeof v === 'boolean' ? (v ? 'true' : 'false') : String(v),
          ])
        );
      })
    );

    return { catalogue, tiers, bundles };
  }, [apiClient]);

  const catalogue = data?.catalogue ?? {};
  const tiers = data?.tiers ?? [];
  const bundles = data?.bundles ?? {};

  /** The value showing in a cell: an unsaved edit, else what is stored. */
  const valueOf = (tierId: number, key: string): string | undefined =>
    edits[tierId]?.[key] ?? bundles[tierId]?.[key];

  const setValue = (tierId: number, key: string, value: string) => {
    setEdits((prev) => ({ ...prev, [tierId]: { ...(prev[tierId] ?? {}), [key]: value } }));
  };

  const dirty = (tierId: number): boolean => Object.keys(edits[tierId] ?? {}).length > 0;

  const save = async (tierId: number, confirmReduction = false) => {
    const changed = edits[tierId];
    if (changed === undefined) {
      return;
    }

    setSaving(tierId);
    try {
      const res = await apiClient(`/api/v1/plans/${tierId}/entitlements`, {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          // An empty string means "say nothing about this", which the API takes
          // as null and removes — so the tier inherits the baseline again.
          entitlements: Object.fromEntries(
            Object.entries(changed).map(([k, v]) => [k, v === '' ? null : v])
          ),
          confirm_reduction: confirmReduction,
        }),
      });

      if (res.status === 409) {
        // The server refused because this takes something away from live
        // workspaces, and its message carries the count. Shown as-is rather
        // than reworded: the number is the whole point.
        const body = await res.json();
        const reason =
          typeof body?.details === 'object' && body.details !== null
            ? String(Object.values(body.details)[0] ?? '')
            : String(body?.error ?? '');
        setReduction({ tierId, reason });
        return;
      }

      if (!res.ok) {
        addToast(t('tiers.error.save', 'Could not save these changes.'), 'error');
        return;
      }

      addToast(t('tiers.saved', 'Tier updated. Everyone on it sees this now.'), 'success');
      setReduction(null);
      // The saved edits are now the stored values, so they stop being edits —
      // cleared before the refetch rather than after, so a slow reload cannot
      // leave the Save button live against changes that already landed.
      setEdits((prev) => {
        const next = { ...prev };
        delete next[tierId];
        return next;
      });
      refetch();
    } finally {
      setSaving(null);
    }
  };

  // Core limits first, then each plugin's, so a vertical product's own limits
  // sit together under the plugin that sells them instead of being scattered
  // through the platform's.
  const grouped = useMemo(() => {
    const groups = new Map<string, string[]>();
    for (const [key, entry] of Object.entries(data?.catalogue ?? {})) {
      const owner = entry.owner ?? '';
      groups.set(owner, [...(groups.get(owner) ?? []), key]);
    }
    return [...groups.entries()].sort(([a], [b]) => a.localeCompare(b));
    // Keyed on the fetch result itself, not on the derived `catalogue`: the
    // derivation is a fresh object literal every render, so depending on it
    // would rebuild these groups on every keystroke in the matrix.
  }, [data]);

  if (loading) {
    return null;
  }

  if (tiers.length === 0) {
    return (
      <section className="space-y-2" data-testid="tier-features-empty">
        <h2 className="text-base font-medium">{t('tiers.title', 'What each tier includes')}</h2>
        <p className="text-sm text-muted-foreground">
          {t('tiers.empty', 'Create a plan first, then decide what it includes.')}
        </p>
      </section>
    );
  }

  return (
    <section className="space-y-4" data-testid="tier-features">
      <div>
        <h2 className="text-base font-medium">{t('tiers.title', 'What each tier includes')}</h2>
        <p className="text-sm text-muted-foreground">
          {t(
            'tiers.description',
            'Changes apply immediately to every workspace already on the tier. Leave a box empty to use the platform default.'
          )}
        </p>
      </div>

      {/* The table scrolls inside its own container: a tier per column means the
          width grows with the price list, and the page itself must never scroll
          sideways. */}
      <div className="overflow-x-auto rounded-lg border">
        <table className="w-full min-w-[40rem] text-sm">
          <thead>
            <tr className="border-b bg-muted/40">
              <th className="p-3 text-start font-medium">{t('tiers.feature', 'Feature')}</th>
              {tiers.map((tier) => (
                <th key={tier.id} className="p-3 text-start font-medium">
                  <span className="flex flex-wrap items-center gap-2">
                    {tier.name}
                    {!tier.is_active && (
                      <Badge variant="secondary">{t('tiers.inactive', 'Not on sale')}</Badge>
                    )}
                    {tier.provider !== null && (
                      <Badge variant="outline" title={tier.provider}>
                        {tier.provider}
                      </Badge>
                    )}
                  </span>
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {grouped.map(([owner, keys]) => (
              <>
                {owner !== '' && (
                  <tr key={`group-${owner}`} className="border-b bg-muted/20">
                    <td className="p-2 ps-3 text-xs font-medium uppercase tracking-wide" colSpan={tiers.length + 1}>
                      {t('tiers.providedBy', 'Provided by')} {owner}
                    </td>
                  </tr>
                )}
                {keys.map((key) => {
                  const entry = catalogue[key];
                  return (
                    <tr key={key} className="border-b last:border-0 align-top">
                      <td className="p-3">
                        <p className="font-medium">{labelFor(key)}</p>
                        <p className="text-xs text-muted-foreground">{entry.description}</p>
                      </td>
                      {tiers.map((tier) => (
                        <td key={tier.id} className="p-3">
                          <Cell
                            entry={entry}
                            value={valueOf(tier.id, key)}
                            onChange={(v) => setValue(tier.id, key, v)}
                            testId={`tier-${tier.plan_key}-${key}`}
                            unlimitedLabel={t('tiers.unlimited', 'Unlimited')}
                            inheritLabel={t('tiers.inherit', 'Default')}
                            periodLabel={periodLabel(entry.period, t)}
                          />
                        </td>
                      ))}
                    </tr>
                  );
                })}
              </>
            ))}
          </tbody>
          <tfoot>
            <tr className="bg-muted/40">
              <td className="p-3" />
              {tiers.map((tier) => (
                <td key={tier.id} className="p-3">
                  <Button
                    onClick={() => void save(tier.id)}
                    disabled={!dirty(tier.id) || saving !== null}
                    data-testid={`save-${tier.plan_key}`}
                  >
                    {saving === tier.id
                      ? t('tiers.saving', 'Saving…')
                      : t('tiers.save', 'Save')}
                  </Button>
                </td>
              ))}
            </tr>
          </tfoot>
        </table>
      </div>

      <Dialog open={reduction !== null} onOpenChange={(open) => !open && setReduction(null)}>
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('tiers.reduce.title', 'This takes something away')}</DialogTitle>
            <DialogDescription>{reduction?.reason}</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setReduction(null)}>
              {t('tiers.reduce.cancel', 'Cancel')}
            </Button>
            <Button
              variant="destructive"
              onClick={() => reduction !== null && void save(reduction.tierId, true)}
              data-testid="confirm-reduction"
            >
              {t('tiers.reduce.confirm', 'Apply anyway')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </section>
  );
}

/**
 * One tier's answer for one limit.
 *
 * THREE STATES, NOT TWO, for a numeric limit: a number, unlimited, and "this
 * tier does not say". The third is not the same as unlimited — it inherits
 * whatever the platform baseline is, which an operator may change later — and
 * collapsing them would quietly turn every unset row into a promise.
 */
function Cell({
  entry,
  value,
  onChange,
  testId,
  unlimitedLabel,
  inheritLabel,
  periodLabel,
}: {
  entry: CatalogueEntry;
  value: string | undefined;
  onChange: (value: string) => void;
  testId: string;
  unlimitedLabel: string;
  inheritLabel: string;
  periodLabel: string | null;
}) {
  if (entry.type === 'bool') {
    return (
      <Checkbox
        checked={value === 'true'}
        onCheckedChange={(checked) => onChange(checked === true ? 'true' : 'false')}
        data-testid={testId}
        aria-label={testId}
      />
    );
  }

  const unlimited = value === '-1';

  return (
    <div className="flex flex-col gap-1">
      <div className="flex items-center gap-2">
        <Input
          type="number"
          min={0}
          className="w-24"
          value={unlimited || value === undefined ? '' : value}
          placeholder={unlimited ? unlimitedLabel : inheritLabel}
          onChange={(e) => onChange(e.target.value)}
          disabled={unlimited}
          data-testid={testId}
          aria-label={testId}
        />
        {periodLabel !== null && (
          <span className="text-xs text-muted-foreground">{periodLabel}</span>
        )}
      </div>
      <label className="flex items-center gap-1.5 text-xs text-muted-foreground">
        <Checkbox
          checked={unlimited}
          onCheckedChange={(checked) => onChange(checked === true ? '-1' : '')}
          data-testid={`${testId}-unlimited`}
          aria-label={`${testId}-unlimited`}
        />
        {unlimitedLabel}
      </label>
    </div>
  );
}

/**
 * A readable name from a dotted key.
 *
 * Deliberately derived rather than translated: the keys include ones plugins
 * invent at runtime, so a fixed lookup would show a raw key for exactly the
 * limits a vertical product sells. The DESCRIPTION beside it is the authored
 * text, and that is where the wording that matters lives.
 */
function labelFor(key: string): string {
  const last = key.split('.').slice(-2).join(' ');

  return last.replace(/[._]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

function periodLabel(period: string | null, t: (k: string, d: string) => string): string | null {
  if (period === null) {
    return null;
  }

  return period === 'day'
    ? t('tiers.perDay', 'per day')
    : period === 'week'
      ? t('tiers.perWeek', 'per week')
      : t('tiers.perMonth', 'per month');
}
