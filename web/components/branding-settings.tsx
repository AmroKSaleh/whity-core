'use client';

/**
 * Branding section for the admin settings page (WC-233 Slice 5).
 *
 * Renders three asset uploaders (wide logo, square logo, favicon) with live
 * previews, Upload file controls, and Clear→revert buttons. A "Global
 * defaults" section appears for users with `settings:manage`. When the current
 * tenant is the system tenant (`tenantOverridable === false`), the per-tenant
 * uploaders are hidden and a WC-224-style notice is shown instead (mirroring
 * `TenantSettingsSection` in the settings page).
 *
 * A custom-host field is surfaced only with `settings:manage`.
 */

import { useRef, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useToast } from '@/lib/toast-context';
import { useFetch } from '@/hooks/useFetch';
import { api } from '@/lib/api/client';
import {
  uploadBrandingAsset,
  clearBrandingAsset,
  setBrandingHost,
  type Branding,
} from '@/lib/api/branding-upload';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@amroksaleh/features/i18n';
import {
  IconDeviceFloppy,
  IconInfoCircle,
  IconPhoto,
  IconTrash,
  IconUpload,
  IconWorld,
} from '@tabler/icons-react';

// ---------------------------------------------------------------------------
// RBAC permission slugs
// ---------------------------------------------------------------------------

const SETTINGS_WRITE = 'settings:write';

// ---------------------------------------------------------------------------
// Asset metadata
// ---------------------------------------------------------------------------

type AssetKey = 'logo_wide' | 'logo_square' | 'favicon';

interface AssetMeta {
  key: AssetKey;
  labelKey: string;
  label: string;
  accept: string;
  urlField: keyof Branding;
  descriptionKey: string;
  description: string;
}

/**
 * The asset labels and descriptions reach `t()` through this table rather than
 * as literals at the call site, which no static scanner can read — so they are
 * declared here and the extractor takes the catalogue from this block. The
 * English stays on the record as the runtime fallback.
 *
 * @i18n-keys admin
 *   branding.asset.logoWide.label = Wide logo
 *   branding.asset.logoWide.description = Shown in the expanded sidebar. PNG, WebP or SVG, max 2 MB.
 *   branding.asset.logoSquare.label = Square logo
 *   branding.asset.logoSquare.description = Shown in the collapsed sidebar. PNG, WebP or SVG, max 2 MB.
 *   branding.asset.favicon.label = Favicon
 *   branding.asset.favicon.description = Browser tab icon. ICO or PNG, max 256 KB.
 */
export const ASSET_META: AssetMeta[] = [
  {
    key: 'logo_wide',
    labelKey: 'branding.asset.logoWide.label',
    label: 'Wide logo',
    accept: 'image/png,image/webp,image/svg+xml',
    urlField: 'logoWideUrl',
    descriptionKey: 'branding.asset.logoWide.description',
    description: 'Shown in the expanded sidebar. PNG, WebP or SVG, max 2 MB.',
  },
  {
    key: 'logo_square',
    labelKey: 'branding.asset.logoSquare.label',
    label: 'Square logo',
    accept: 'image/png,image/webp,image/svg+xml',
    urlField: 'logoSquareUrl',
    descriptionKey: 'branding.asset.logoSquare.description',
    description: 'Shown in the collapsed sidebar. PNG, WebP or SVG, max 2 MB.',
  },
  {
    key: 'favicon',
    labelKey: 'branding.asset.favicon.label',
    label: 'Favicon',
    accept: 'image/x-icon,image/png',
    urlField: 'faviconUrl',
    descriptionKey: 'branding.asset.favicon.description',
    description: 'Browser tab icon. ICO or PNG, max 256 KB.',
  },
];

// ---------------------------------------------------------------------------
// Helper: extract a readable error message from a thrown value
// ---------------------------------------------------------------------------

function toErrorMessage(err: unknown, fallback: string): string {
  if (err instanceof Error && err.message) return err.message;
  return fallback;
}

// ---------------------------------------------------------------------------
// AssetUploader — single asset row (preview + upload + clear)
// ---------------------------------------------------------------------------

interface AssetUploaderProps {
  meta: AssetMeta;
  scope: 'tenant' | 'global';
  currentUrl: string | null;
  disabled: boolean;
  onSuccess: (updated: Branding) => void;
  /** Called after a successful clear; defaults to onSuccess when omitted. */
  onClearSuccess?: (updated: Branding) => void;
  onError: (message: string) => void;
}

function AssetUploader({ meta, scope, currentUrl, disabled, onSuccess, onClearSuccess, onError }: AssetUploaderProps) {
  const t = useTranslation('admin');
  const [uploading, setUploading] = useState(false);
  const [clearing, setClearing] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const label = t(meta.labelKey, meta.label);

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploading(true);
    try {
      const updated = await uploadBrandingAsset(scope, meta.key, file);
      onSuccess(updated);
    } catch (err) {
      onError(
        toErrorMessage(
          err,
          t('branding.upload.error', 'Failed to upload {asset}', {
            asset: label.toLowerCase(),
          })
        )
      );
    } finally {
      setUploading(false);
      // Reset so the same file can be re-selected after a clear.
      if (fileInputRef.current) fileInputRef.current.value = '';
    }
  };

  const handleClear = async () => {
    setClearing(true);
    try {
      const updated = await clearBrandingAsset(scope, meta.key);
      (onClearSuccess ?? onSuccess)(updated);
    } catch (err) {
      onError(
        toErrorMessage(
          err,
          t('branding.clear.error', 'Failed to clear {asset}', {
            asset: label.toLowerCase(),
          })
        )
      );
    } finally {
      setClearing(false);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-foreground">{label}</p>
          <p className="text-xs text-muted-foreground mt-0.5">
            {t(meta.descriptionKey, meta.description)}
          </p>
        </div>

        {/* Live preview */}
        {currentUrl ? (
          <div className="shrink-0">
            <img
              src={currentUrl}
              alt={t('branding.preview.alt', 'Current {asset}', { asset: label })}
              className="h-10 max-w-[120px] rounded border border-border object-contain bg-muted/20 p-1"
              data-testid={`branding-preview-${meta.key}-${scope}`}
            />
          </div>
        ) : (
          <div
            className="shrink-0 flex items-center justify-center h-10 w-16 rounded border border-dashed border-border bg-muted/20"
            data-testid={`branding-preview-${meta.key}-${scope}`}
            aria-label={t('branding.preview.empty', 'No {asset} set', { asset: label })}
          >
            <IconPhoto className="h-4 w-4 text-muted-foreground/50" />
          </div>
        )}
      </div>

      <div className="flex items-center gap-2">
        {/* Hidden file input */}
        <input
          ref={fileInputRef}
          type="file"
          accept={meta.accept}
          className="hidden"
          disabled={disabled || uploading}
          onChange={handleFileChange}
          data-testid={`branding-file-input-${meta.key}-${scope}`}
          aria-label={t('branding.upload.label', 'Upload {asset}', { asset: label })}
        />

        {/* Visible Upload button */}
        <Button
          type="button"
          variant="outline"
          size="sm"
          disabled={disabled || uploading}
          onClick={() => fileInputRef.current?.click()}
          className="gap-1.5"
          data-testid={`branding-upload-btn-${meta.key}-${scope}`}
        >
          <IconUpload className="h-3.5 w-3.5" />
          {uploading
            ? t('branding.upload.pending', 'Uploading…')
            : t('branding.upload', 'Upload')}
        </Button>

        {/* Clear button — only shown when there is an asset to clear */}
        {currentUrl && (
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={disabled || clearing}
            onClick={handleClear}
            className="gap-1.5 text-muted-foreground hover:text-destructive"
            data-testid={`branding-clear-btn-${meta.key}-${scope}`}
            aria-label={t('branding.clear.label', 'Clear {asset}', { asset: label })}
          >
            <IconTrash className="h-3.5 w-3.5" />
            {clearing
              ? t('branding.clear.pending', 'Clearing…')
              : t('branding.clear', 'Clear')}
          </Button>
        )}
      </div>
    </div>
  );
}

// ---------------------------------------------------------------------------
// BrandingSettings — the exported page section
// ---------------------------------------------------------------------------

export interface BrandingSettingsProps {
  /** False when the current tenant is the system tenant (no per-tenant layer). */
  tenantOverridable?: boolean;
  /**
   * Which surface to render (WC-235):
   *  - 'tenant' (default) → the per-tenant branding assets, for /admin/settings.
   *  - 'global'           → the platform-wide branding defaults + custom host,
   *    for the system-tenant-only /admin/settings/global page.
   * The global surface must never be rendered on the tenant page (a regular
   * tenant admin holds settings:manage but the backend gates global writes to
   * the system tenant).
   */
  variant?: 'tenant' | 'global';
}

export function BrandingSettings({ tenantOverridable = true, variant = 'tenant' }: BrandingSettingsProps) {
  const { user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();
  const t = useTranslation('admin');

  const canWrite = hasPermission(SETTINGS_WRITE);

  // ---------------------------------------------------------------------------
  // Fetch the current effective branding (live preview source)
  // ---------------------------------------------------------------------------

  const { data: branding, refetch: refetchBranding } = useFetch(async () => {
    const { data, error } = await api.GET('/api/v1/branding');
    if (!data) {
      throw new Error(
        error && typeof error === 'object' && 'error' in error && typeof error.error === 'string'
          ? error.error
          : 'Failed to load branding'
      );
    }
    return data.data;
  }, []);

  // Mutation overlay: null until a mutation (upload/clear) succeeds. The
  // display value is `mutationResult ?? branding` — the mutation response is
  // the freshest source immediately after a write; the fetch result is used
  // on initial load. No useEffect seeding needed (draft overlay pattern).
  const [mutationResult, setMutationResult] = useState<Branding | null>(null);

  const effectiveBranding = mutationResult ?? branding;

  /** The translated display name of an asset, falling back to its raw key. */
  const assetLabel = (key: AssetKey): string => {
    const meta = ASSET_META.find((m) => m.key === key);
    return meta ? t(meta.labelKey, meta.label) : key;
  };

  const handleSuccess = (_scope: 'tenant' | 'global', key: AssetKey, updated: Branding) => {
    setMutationResult(updated);
    refetchBranding();
    addToast(
      t('branding.upload.success', '{asset} uploaded successfully.', { asset: assetLabel(key) }),
      'success'
    );
  };

  const handleClearSuccess = (_scope: 'tenant' | 'global', key: AssetKey, updated: Branding) => {
    setMutationResult(updated);
    refetchBranding();
    addToast(
      t('branding.clear.success', '{asset} cleared.', { asset: assetLabel(key) }),
      'success'
    );
  };

  const handleError = (message: string) => {
    addToast(message, 'error');
  };

  // ---------------------------------------------------------------------------
  // Custom-host field (settings:manage only)
  // ---------------------------------------------------------------------------

  const [customHost, setCustomHost] = useState('');
  const [savingHost, setSavingHost] = useState(false);

  const handleSaveHost = async () => {
    // Guard: empty field must not silently clear an existing host (Fix 3).
    // The Save button is disabled when the field is empty, so this is a
    // belt-and-suspenders check only.
    const trimmed = customHost.trim();
    if (trimmed === '') return;

    setSavingHost(true);
    try {
      // Fix 1: use the acting tenant's real numeric id from the auth context.
      // For the system-tenant admin this is 0; for any other tenant it is their
      // own id, matching the PATH {id} the backend uses as the write target.
      const tenantId = user?.tenant_id ?? 0;
      await setBrandingHost(tenantId, trimmed);
      addToast(t('branding.host.success', 'Custom host saved.'), 'success');
    } catch (err) {
      addToast(
        toErrorMessage(err, t('branding.host.error', 'Failed to save custom host')),
        'error'
      );
    } finally {
      setSavingHost(false);
    }
  };

  // ---------------------------------------------------------------------------
  // Render: Tenant branding card
  // ---------------------------------------------------------------------------

  return (
    <>
      {/* ---- Tenant branding card (variant='tenant') ---- */}
      {variant === 'tenant' && (
      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>{t('branding.tenant.title', 'Branding')}</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            {t(
              'branding.tenant.description',
              'Upload logos and a favicon to white-label the app for this tenant. Cleared assets fall back to the global default.'
            )}
            {tenantOverridable && !canWrite &&
              ` ${t('branding.tenant.readOnly', 'You have read-only access (settings:write required to upload).')}`}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-6">
          {!tenantOverridable ? (
            // WC-224 pattern: system tenant has no per-tenant asset layer.
            <div
              data-testid="branding-no-override-notice"
              role="note"
              className="flex items-start gap-3 rounded-lg border border-border bg-muted/40 p-4 text-sm text-muted-foreground"
            >
              <IconInfoCircle className="mt-0.5 h-5 w-5 shrink-0 text-primary" aria-hidden="true" />
              {/*
                NOT translated, deliberately: "Global branding defaults" is
                emphasised with <strong> in the MIDDLE of the sentence, and
                `t()` returns a string — so preserving the emphasis would mean
                handing a translator three fragments in English word order.
                Needs the emphasis lifted out of the sentence first.
              */}
              <p>
                As the system tenant, you have no per-tenant branding overrides. Edit the
                platform-wide branding assets under{' '}
                <strong className="font-medium text-foreground">Global branding defaults</strong>{' '}
                on the Global Settings page.
              </p>
            </div>
          ) : (
            <div className="space-y-6 divide-y divide-border">
              {ASSET_META.map((meta) => (
                <div key={meta.key} className="pt-4 first:pt-0">
                  <AssetUploader
                    meta={meta}
                    scope="tenant"
                    currentUrl={effectiveBranding?.[meta.urlField] ?? null}
                    disabled={!canWrite}
                    onSuccess={(updated) => handleSuccess('tenant', meta.key, updated)}
                    onClearSuccess={(updated) => handleClearSuccess('tenant', meta.key, updated)}
                    onError={handleError}
                  />
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>
      )}

      {/* ---- Global branding defaults card (variant='global', system tenant only) ---- */}
      {variant === 'global' && (
        <Card className="border border-border bg-card shadow-sm">
          <CardHeader>
            <div className="flex items-center gap-2">
              <div className="p-2 bg-primary/10 rounded-lg text-primary">
                <IconWorld className="w-5 h-5" />
              </div>
              <div>
                <CardTitle className="text-lg font-bold font-heading">
                  <h2>{t('branding.global.title', 'Global branding defaults')}</h2>
                </CardTitle>
                <CardDescription className="text-sm">
                  {t(
                    'branding.global.description',
                    'Platform-wide branding applied to every tenant that has not uploaded its own assets (settings:manage).'
                  )}
                </CardDescription>
              </div>
            </div>
          </CardHeader>
          <CardContent className="space-y-6">
            <div className="space-y-6 divide-y divide-border">
              {ASSET_META.map((meta) => (
                <div key={meta.key} className="pt-4 first:pt-0">
                  <AssetUploader
                    meta={meta}
                    scope="global"
                    currentUrl={effectiveBranding?.[meta.urlField] ?? null}
                    disabled={false}
                    onSuccess={(updated) => handleSuccess('global', meta.key, updated)}
                    onClearSuccess={(updated) => handleClearSuccess('global', meta.key, updated)}
                    onError={handleError}
                  />
                </div>
              ))}
            </div>

            {/* Custom host field */}
            <div className="pt-4 border-t border-border space-y-3">
              <div>
                <p className="text-sm font-medium text-foreground">
                  {t('branding.host.title', 'Custom hostname')}
                </p>
                {/*
                  NOT translated, deliberately: the example domain is marked up
                  with <code> in the MIDDLE of the sentence, and `t()` returns a
                  string — preserving it would mean splitting the sentence into
                  fragments a translator cannot reorder. Needs the example
                  lifted out of the sentence first.
                */}
                <p className="text-xs text-muted-foreground mt-0.5">
                  Map a custom domain (e.g. <code>app.acme.com</code>) to this tenant for pre-auth
                  branding (login page, favicon, title). Leave blank to use the slug-subdomain only.
                </p>
              </div>
              <div className="flex items-center gap-2">
                <Input
                  type="text"
                  placeholder="app.example.com"
                  value={customHost}
                  onChange={(e) => setCustomHost(e.target.value)}
                  className="max-w-xs"
                  data-testid="branding-custom-host-input"
                  aria-label={t('branding.host.title', 'Custom hostname')}
                />
                <Button
                  type="button"
                  disabled={savingHost || customHost.trim() === ''}
                  onClick={handleSaveHost}
                  className="gap-2 shrink-0"
                  data-testid="branding-custom-host-save"
                >
                  <IconDeviceFloppy className="w-4 h-4" />
                  {savingHost
                    ? t('branding.host.pending', 'Saving…')
                    : t('branding.host.submit', 'Save host')}
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>
      )}
    </>
  );
}
