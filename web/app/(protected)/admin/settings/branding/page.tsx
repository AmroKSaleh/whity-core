'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import { useAuth } from '@/lib/auth-context';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { AdminHeader } from '@/components/admin/admin-header';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { IconDeviceFloppy } from '@tabler/icons-react';
import { AccessDenied } from '@amroksaleh/ui/access-denied';
import { BrandingSettings } from '@/components/branding-settings';
import { SettingsTabs } from '../settings-tabs';
import {
  SETTINGS_READ,
  SETTINGS_WRITE,
  SYSTEM_TENANT_ID,
  FIELD_LABELS,
  errorMessage,
  SettingsField,
  type AddToast,
} from '../settings-shared';

/**
 * Branding — the instance's visual + language identity: locale, logos,
 * favicon, and colors. Grouped together because they're all "what this
 * deployment looks and reads like", as distinct from General's operational
 * fields (site name, support email, timezone).
 */
export default function BrandingSettingsPage() {
  const { addToast } = useToast();
  const { user } = useAuth();
  const { hasPermission, loading: isCapabilitiesLoading } = useCapabilities();

  const canRead = hasPermission(SETTINGS_READ);
  const canWrite = hasPermission(SETTINGS_WRITE);
  const isSystemTenant = user?.tenant_id === SYSTEM_TENANT_ID;

  // Fetch tenant_overridable at the page level so it can be passed to
  // BrandingSettings. Must be called unconditionally (Rules of Hooks).
  const { data: settingsMeta } = useFetch(async () => {
    const { data: body } = await api.GET('/api/v1/settings');
    return body?.data ?? null;
  }, []);
  const tenantOverridable = settingsMeta?.tenant_overridable ?? true;

  if (isCapabilitiesLoading) {
    return (
      <div className="flex items-center justify-center min-h-[400px]">
        <div className="animate-spin rounded-full h-8 w-8 border-b-2 border-primary"></div>
      </div>
    );
  }

  if (!canRead) {
    return (
      <AccessDenied
        description={
          <>
            You do not have the required permission (<code>settings:read</code>) to view
            Branding.
          </>
        }
        action={
          <Button onClick={() => window.history.back()} variant="outline">
            Go Back
          </Button>
        }
      />
    );
  }

  return (
    <div className="space-y-8 max-w-4xl mx-auto px-4 md:px-0 pb-16">
      <AdminHeader
        title="Branding"
        description="Language, logos, favicon, and colors for this tenant."
      />
      <SettingsTabs active="branding" />
      <LocaleSection canWrite={canWrite} tenantOverridable={tenantOverridable} addToast={addToast} />
      <BrandingSettings variant="tenant" tenantOverridable={tenantOverridable} />
      {isSystemTenant && <BrandingSettings variant="global" />}
    </div>
  );
}

/**
 * The tenant's locale override — split out of the General tab's tenant form
 * (its own draft/save, PATCHing only `locale`) so Branding remains a single
 * self-contained page even though `locale` is still one of the four
 * `SettingsValueMap` keys the backend's per-tenant endpoint recognizes.
 */
function LocaleSection({
  canWrite,
  tenantOverridable,
  addToast,
}: {
  canWrite: boolean;
  tenantOverridable: boolean;
  addToast: AddToast;
}) {
  const { data, loading, error, refetch } = useFetch(async () => {
    const { data: body, error: getError } = await api.GET('/api/v1/settings');
    if (body === undefined) {
      throw new Error(errorMessage(getError, 'Failed to load settings'));
    }
    return body.data;
  }, []);

  const [draft, setDraft] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  const effective = data?.effective?.locale;
  const isOverridden = (data?.overridden ?? []).includes('locale');

  useEffect(() => {
    if (error) addToast(error, 'error');
  }, [error, addToast]);

  if (!tenantOverridable) {
    // WC-224: the system tenant has no per-tenant override layer — edits
    // happen on the global branding form below instead.
    return null;
  }

  const value = draft ?? effective ?? '';

  const handleSave = async () => {
    setSaving(true);
    try {
      const trimmed = value.trim();
      const { error: patchError } = await api.PATCH('/api/v1/settings', {
        body: { settings: { locale: trimmed === '' ? '' : trimmed } },
      });
      if (patchError) {
        throw new Error(errorMessage(patchError, 'Failed to save language'));
      }
      addToast('Language saved.', 'success');
      setDraft(null);
      refetch();
    } catch (err) {
      addToast(err instanceof Error ? err.message : 'Failed to save language', 'error');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Card className="border border-border bg-card shadow-sm">
      <CardHeader>
        <CardTitle className="text-lg font-bold font-heading">
          <h2>Language</h2>
        </CardTitle>
        <CardDescription className="text-sm">
          Default interface language for this tenant. Cleared falls back to the global default.
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-5">
        {loading ? (
          <div className="h-12 animate-pulse rounded-md bg-muted/40" />
        ) : (
          <SettingsField
            settingKey="locale"
            idPrefix="branding"
            label={FIELD_LABELS.locale}
            value={value}
            disabled={!canWrite}
            onChange={setDraft}
            status={isOverridden ? 'overridden' : 'inherited'}
          />
        )}
        {canWrite && (
          <div className="flex justify-end">
            <Button onClick={handleSave} disabled={saving || draft === null} className="gap-2">
              <IconDeviceFloppy className="w-4 h-4" />
              {saving ? 'Saving…' : 'Save language'}
            </Button>
          </div>
        )}
      </CardContent>
    </Card>
  );
}
