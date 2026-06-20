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
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
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
const SETTINGS_MANAGE = 'settings:manage';

// ---------------------------------------------------------------------------
// Asset metadata
// ---------------------------------------------------------------------------

type AssetKey = 'logo_wide' | 'logo_square' | 'favicon';

interface AssetMeta {
  key: AssetKey;
  label: string;
  accept: string;
  urlField: keyof Branding;
  description: string;
}

const ASSET_META: AssetMeta[] = [
  {
    key: 'logo_wide',
    label: 'Wide logo',
    accept: 'image/png,image/webp,image/svg+xml',
    urlField: 'logoWideUrl',
    description: 'Shown in the expanded sidebar. PNG, WebP or SVG, max 2 MB.',
  },
  {
    key: 'logo_square',
    label: 'Square logo',
    accept: 'image/png,image/webp,image/svg+xml',
    urlField: 'logoSquareUrl',
    description: 'Shown in the collapsed sidebar. PNG, WebP or SVG, max 2 MB.',
  },
  {
    key: 'favicon',
    label: 'Favicon',
    accept: 'image/x-icon,image/png',
    urlField: 'faviconUrl',
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
  const [uploading, setUploading] = useState(false);
  const [clearing, setClearing] = useState(false);
  const fileInputRef = useRef<HTMLInputElement>(null);

  const handleFileChange = async (e: React.ChangeEvent<HTMLInputElement>) => {
    const file = e.target.files?.[0];
    if (!file) return;

    setUploading(true);
    try {
      const updated = await uploadBrandingAsset(scope, meta.key, file);
      onSuccess(updated);
    } catch (err) {
      onError(toErrorMessage(err, `Failed to upload ${meta.label.toLowerCase()}`));
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
      onError(toErrorMessage(err, `Failed to clear ${meta.label.toLowerCase()}`));
    } finally {
      setClearing(false);
    }
  };

  return (
    <div className="space-y-3">
      <div className="flex items-start justify-between gap-4">
        <div className="min-w-0 flex-1">
          <p className="text-sm font-medium text-foreground">{meta.label}</p>
          <p className="text-xs text-muted-foreground mt-0.5">{meta.description}</p>
        </div>

        {/* Live preview */}
        {currentUrl ? (
          <div className="shrink-0">
            <img
              src={currentUrl}
              alt={`Current ${meta.label}`}
              className="h-10 max-w-[120px] rounded border border-border object-contain bg-muted/20 p-1"
              data-testid={`branding-preview-${meta.key}-${scope}`}
            />
          </div>
        ) : (
          <div
            className="shrink-0 flex items-center justify-center h-10 w-16 rounded border border-dashed border-border bg-muted/20"
            data-testid={`branding-preview-${meta.key}-${scope}`}
            aria-label={`No ${meta.label} set`}
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
          aria-label={`Upload ${meta.label}`}
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
          {uploading ? 'Uploading…' : 'Upload'}
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
            aria-label={`Clear ${meta.label}`}
          >
            <IconTrash className="h-3.5 w-3.5" />
            {clearing ? 'Clearing…' : 'Clear'}
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
  tenantOverridable: boolean;
}

export function BrandingSettings({ tenantOverridable }: BrandingSettingsProps) {
  const { user } = useAuth();
  const { addToast } = useToast();
  const { hasPermission } = useCapabilities();

  const canWrite = hasPermission(SETTINGS_WRITE);
  const canManage = hasPermission(SETTINGS_MANAGE);

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

  const handleSuccess = (_scope: 'tenant' | 'global', key: AssetKey, updated: Branding) => {
    setMutationResult(updated);
    refetchBranding();
    const label = ASSET_META.find((m) => m.key === key)?.label ?? key;
    addToast(`${label} uploaded successfully.`, 'success');
  };

  const handleClearSuccess = (_scope: 'tenant' | 'global', key: AssetKey, updated: Branding) => {
    setMutationResult(updated);
    refetchBranding();
    const label = ASSET_META.find((m) => m.key === key)?.label ?? key;
    addToast(`${label} cleared.`, 'success');
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
      addToast('Custom host saved.', 'success');
    } catch (err) {
      addToast(toErrorMessage(err, 'Failed to save custom host'), 'error');
    } finally {
      setSavingHost(false);
    }
  };

  // ---------------------------------------------------------------------------
  // Render: Tenant branding card
  // ---------------------------------------------------------------------------

  return (
    <>
      {/* ---- Tenant branding card ---- */}
      <Card className="border border-border bg-card shadow-sm">
        <CardHeader>
          <CardTitle className="text-lg font-bold font-heading">
            <h2>Branding</h2>
          </CardTitle>
          <CardDescription className="text-sm">
            Upload logos and a favicon to white-label the app for this tenant. Cleared assets fall
            back to the global default.
            {tenantOverridable && !canWrite &&
              ' You have read-only access (settings:write required to upload).'}
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
              <p>
                As the system tenant, you have no per-tenant branding overrides. Edit the
                platform-wide branding assets in{' '}
                <strong className="font-medium text-foreground">Global branding defaults</strong>{' '}
                below.
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

      {/* ---- Global branding defaults card (settings:manage only) ---- */}
      {canManage && (
        <Card className="border border-border bg-card shadow-sm">
          <CardHeader>
            <div className="flex items-center gap-2">
              <div className="p-2 bg-primary/10 rounded-lg text-primary">
                <IconWorld className="w-5 h-5" />
              </div>
              <div>
                <CardTitle className="text-lg font-bold font-heading">
                  <h2>Global branding defaults</h2>
                </CardTitle>
                <CardDescription className="text-sm">
                  Platform-wide branding applied to every tenant that has not uploaded its own
                  assets (settings:manage).
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
                <p className="text-sm font-medium text-foreground">Custom hostname</p>
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
                  aria-label="Custom hostname"
                />
                <Button
                  type="button"
                  disabled={savingHost || customHost.trim() === ''}
                  onClick={handleSaveHost}
                  className="gap-2 shrink-0"
                  data-testid="branding-custom-host-save"
                >
                  <IconDeviceFloppy className="w-4 h-4" />
                  {savingHost ? 'Saving…' : 'Save host'}
                </Button>
              </div>
            </div>
          </CardContent>
        </Card>
      )}
    </>
  );
}
