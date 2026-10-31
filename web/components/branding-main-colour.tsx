'use client';

/**
 * The workspace main colour (decision 2.8, TplAdminBranding card): a row of
 * swatches plus a custom picker, saved as the `branding_main_color` setting.
 * The server derives the light and dark token sets from it (TenantTheme) and
 * the root layout applies them, so after a save a router refresh re-themes the
 * whole app — that is the preview. Reset clears the setting, which falls back
 * to the global default, then to Whity Blue.
 */

import { useState } from 'react';
import { useRouter } from 'next/navigation';
import { api } from '@/lib/api/client';
import { Button } from '@whity-org/ui/button';
import { useTranslation } from '@whity-org/features/i18n';
import { IconCheck, IconRotate } from '@tabler/icons-react';

const HEX_RE = /^#[0-9a-f]{6}$/;

/**
 * The card's swatches (TplAdminBranding), as hex because the setting only
 * takes #RRGGBB: Whity Blue, the Example Bakery orange, then the card's four
 * oklch swatches converted.
 */
export const MAIN_COLOUR_SWATCHES = ['#0961bb', '#b74c05', '#00792f', '#c53829', '#643b9a', '#edb417'];

export interface BrandingMainColourProps {
  /** 'tenant' writes this workspace's colour; 'global' the platform default. */
  scope: 'tenant' | 'global';
  /** The effective colour from GET /api/v1/branding, or null for Whity Blue. */
  current: string | null;
  disabled: boolean;
  /** Called after a save or reset so the caller can refetch branding. */
  onSaved: () => void;
  onError: (message: string) => void;
  onSuccess: (message: string) => void;
}

export function BrandingMainColour({ scope, current, disabled, onSaved, onError, onSuccess }: BrandingMainColourProps) {
  const t = useTranslation('admin');
  const router = useRouter();
  const [draft, setDraft] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const saved = current?.toLowerCase() ?? null;
  const selected = draft ?? saved;
  const dirty = draft !== null && draft !== saved;

  const write = async (value: string) => {
    setSaving(true);
    try {
      const body = { settings: { branding_main_color: value } };
      const { error } =
        scope === 'global'
          ? await api.PATCH('/api/v1/settings/global', { body })
          : await api.PATCH('/api/v1/settings', { body });
      if (error) {
        const message =
          typeof error === 'object' && error !== null && 'error' in error && typeof error.error === 'string'
            ? error.error
            : t('branding.colour.error', 'Failed to save the main colour');
        throw new Error(message);
      }
      setDraft(null);
      onSaved();
      router.refresh();
      onSuccess(
        value === ''
          ? t('branding.colour.reset.success', 'Main colour reset.')
          : t('branding.colour.success', 'Main colour saved.')
      );
    } catch (err) {
      onError(err instanceof Error && err.message ? err.message : t('branding.colour.error', 'Failed to save the main colour'));
    } finally {
      setSaving(false);
    }
  };

  return (
    <div className="space-y-3" data-testid={`branding-main-colour-${scope}`}>
      <div>
        <p className="text-sm font-medium text-foreground">{t('branding.colour.label', 'Main colour')}</p>
        <p className="text-xs text-muted-foreground mt-0.5">
          {t(
            'branding.colour.description',
            'The label on it is white or near-black, whichever reaches 4.5:1. The tint, the colour on dark and the plane are derived from it.'
          )}
        </p>
      </div>

      <div className="flex flex-wrap items-center gap-2" role="group" aria-label={t('branding.colour.label', 'Main colour')}>
        {MAIN_COLOUR_SWATCHES.map((swatch) => (
          <button
            key={swatch}
            type="button"
            disabled={disabled || saving}
            aria-label={swatch}
            aria-pressed={selected === swatch}
            onClick={() => setDraft(swatch)}
            className="h-7 w-7 rounded-lg border border-border outline-offset-2 aria-pressed:outline aria-pressed:outline-2 aria-pressed:outline-foreground disabled:cursor-not-allowed disabled:opacity-50"
            style={{ background: swatch }}
            data-testid={`branding-colour-swatch-${swatch.slice(1)}`}
          />
        ))}
        <label className="flex items-center gap-2 ms-2 text-sm text-muted-foreground">
          <input
            type="color"
            value={selected ?? MAIN_COLOUR_SWATCHES[0]}
            disabled={disabled || saving}
            onChange={(e) => {
              const value = e.target.value.toLowerCase();
              if (HEX_RE.test(value)) setDraft(value);
            }}
            className="h-7 w-9 cursor-pointer rounded-lg border border-border bg-transparent p-0.5 disabled:cursor-not-allowed"
            data-testid={`branding-colour-custom-${scope}`}
          />
          {t('branding.colour.custom', 'Custom')}
        </label>
        <span dir="ltr" className="ms-auto font-mono text-sm text-foreground" data-testid={`branding-colour-value-${scope}`}>
          {selected ?? t('branding.colour.default', 'Whity Blue (default)')}
        </span>
      </div>

      {!disabled && (
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={saving || !dirty}
            onClick={() => draft !== null && write(draft)}
            className="gap-1.5"
            data-testid={`branding-colour-save-${scope}`}
          >
            <IconCheck className="h-3.5 w-3.5" />
            {saving ? t('branding.colour.pending', 'Saving…') : t('branding.colour.save', 'Save colour')}
          </Button>
          {saved !== null && (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              disabled={saving}
              onClick={() => write('')}
              className="gap-1.5 text-muted-foreground"
              data-testid={`branding-colour-reset-${scope}`}
            >
              <IconRotate className="h-3.5 w-3.5" />
              {t('branding.colour.reset', 'Reset')}
            </Button>
          )}
        </div>
      )}
    </div>
  );
}
