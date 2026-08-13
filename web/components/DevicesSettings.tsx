'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { Button } from '@amroksaleh/ui/button';
import { useTranslation } from '@amroksaleh/features/i18n';
import { deviceDisplayName } from '@/lib/device-label';
import { IconDeviceMobile } from '@tabler/icons-react';

/**
 * #409: native-device credential management on the Settings page — the
 * "Devices" half of the "two lists" design (see SessionsSettings for the
 * interactive-login half).
 *
 * Lists the caller's enrolled native-client devices (GET /api/v1/devices) —
 * long-lived credentials issued to non-browser clients (desktop and mobile
 * companion apps, etc. — see DeviceApiHandler / DeviceCredentialService). Each
 * can be revoked individually (DELETE /api/v1/devices/{id}), matching the
 * per-session revoke UX in SessionsSettings.
 *
 * The backend's `platform` is a validated enum but `name` is client-supplied
 * free text with no quality check (the reported "flutter" device name came
 * from a value like this) — deviceDisplayName() combines the trusted platform
 * with the name, falling back to a platform-derived label alone when the name
 * is missing or low-quality, so a device never renders as a bare raw string.
 */
interface DeviceCredential {
  id: number;
  name: string;
  platform: string;
  last_seen_at: string | null;
  expires_at: string;
  created_at: string;
}

function formatWhen(value: string): string {
  const parsed = Date.parse(value.replace(' ', 'T') + 'Z');
  return Number.isNaN(parsed) ? value : new Date(parsed).toLocaleString();
}

export function DevicesSettings() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('auth');
  const [devices, setDevices] = useState<DeviceCredential[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const res = await apiClient('/api/v1/devices', { method: 'GET' });
      if (!res.ok) {
        setLoadError(true);
        setDevices([]);
        return;
      }
      const body = (await res.json().catch(() => ({}))) as { devices?: DeviceCredential[] };
      setDevices(Array.isArray(body.devices) ? body.devices : []);
    } catch {
      setLoadError(true);
      setDevices([]);
    } finally {
      setLoading(false);
    }
  }, [apiClient]);

  useEffect(() => {
    // Deferred off the synchronous effect tick, matching SessionsSettings.
    void Promise.resolve().then(load);
  }, [load]);

  const revoke = async (device: DeviceCredential) => {
    setBusyId(device.id);
    try {
      const res = await apiClient(`/api/v1/devices/${device.id}`, { method: 'DELETE' });
      if (!res.ok && res.status !== 404) {
        addToast(
          t('devices.remove.errorWithStatus', 'Failed to remove that device ({status}).', {
            status: res.status,
          }),
          'error'
        );
        return;
      }
      addToast(t('devices.remove.success', 'Device removed.'), 'success');
      setDevices((prev) => prev.filter((d) => d.id !== device.id));
    } catch {
      addToast(t('devices.remove.error', 'Failed to remove that device.'), 'error');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <div className="space-y-4">
      {loading ? (
        <div className="flex items-center justify-center py-6">
          <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
        </div>
      ) : loadError ? (
        <div className="text-sm text-destructive" data-testid="devices-load-error">
          {t('devices.load.error', 'Failed to load your devices.')}{' '}
          <button type="button" onClick={() => void load()} className="underline font-medium">
            {t('devices.load.retry', 'Retry')}
          </button>
        </div>
      ) : devices.length === 0 ? (
        <p className="text-sm text-muted-foreground" data-testid="devices-empty">
          {t('devices.empty', 'No registered devices.')}
        </p>
      ) : (
        <ul className="divide-y divide-border" data-testid="devices-list">
          {devices.map((device) => {
            const busy = busyId === device.id;
            return (
              <li
                key={device.id}
                className="flex flex-col gap-2 py-3 first:pt-0 sm:flex-row sm:items-center sm:justify-between"
                data-testid={`device-row-${device.id}`}
              >
                <div className="min-w-0 flex items-start gap-3">
                  <IconDeviceMobile className="w-5 h-5 mt-0.5 shrink-0 text-muted-foreground" />
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-foreground truncate">
                      {deviceDisplayName(device.name, device.platform)}
                    </p>
                    <p className="text-xs text-muted-foreground">
                      {device.last_seen_at
                        ? t('devices.lastUsed', 'Last used {when}', {
                            when: formatWhen(device.last_seen_at),
                          })
                        : t('devices.neverUsed', 'Never used')}
                    </p>
                  </div>
                </div>
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={busy}
                  onClick={() => void revoke(device)}
                  className="shrink-0"
                  data-testid={`device-revoke-${device.id}`}
                >
                  {t('devices.remove', 'Remove')}
                </Button>
              </li>
            );
          })}
        </ul>
      )}
    </div>
  );
}
