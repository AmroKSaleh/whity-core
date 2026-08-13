'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from '@amroksaleh/ui/alert-dialog';
import { useTranslation } from '@amroksaleh/features/i18n';
import { IconDeviceDesktop, IconDeviceDesktopOff } from '@tabler/icons-react';

/**
 * WC-f-sessions-table: interactive session management on the Settings page.
 *
 * Lists the user's active login sessions (GET /api/v1/me/sessions) with a
 * friendly device label, IP, and last-active time, flagging the current one.
 * Each other session can be signed out individually (DELETE
 * /api/v1/me/sessions/{id}); a single action signs out all other sessions
 * (DELETE /api/v1/me/sessions). A stronger "everywhere including devices"
 * action (POST /api/v1/me/logout-others, WC-b) additionally revokes
 * native-device credentials via the token-epoch bump.
 *
 * The primary label is `device` — a "Chrome on Windows"-style string the
 * backend already computes from the raw User-Agent via DeviceLabel::
 * fromUserAgent() (WC-b3330495) — not the raw `user_agent` itself, which is
 * kept only as a hover tooltip for anyone who wants the exact UA string.
 *
 * Native-device enrollments themselves are managed on their own separate list
 * (see DevicesSettings, #409); this surface is interactive logins only ("two
 * lists").
 */
interface Session {
  id: number;
  user_agent: string | null;
  device?: string;
  ip_address: string | null;
  created_at: string;
  last_seen_at: string;
  current: boolean;
}

function formatWhen(value: string): string {
  const parsed = Date.parse(value.replace(' ', 'T') + 'Z');
  return Number.isNaN(parsed) ? value : new Date(parsed).toLocaleString();
}

export function SessionsSettings() {
  const { apiClient, refreshAuth } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('auth');
  const [sessions, setSessions] = useState<Session[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [busy, setBusy] = useState(false);

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const res = await apiClient('/api/v1/me/sessions', { method: 'GET' });
      if (!res.ok) {
        setLoadError(true);
        setSessions([]);
        return;
      }
      const body = (await res.json().catch(() => ({}))) as { sessions?: Session[] };
      setSessions(Array.isArray(body.sessions) ? body.sessions : []);
    } catch {
      setLoadError(true);
      setSessions([]);
    } finally {
      setLoading(false);
    }
  }, [apiClient]);

  useEffect(() => {
    // Deferred off the synchronous effect tick so load()'s setState does not run
    // synchronously within the effect body.
    void Promise.resolve().then(load);
  }, [load]);

  const revokeOne = async (session: Session) => {
    setBusy(true);
    try {
      const res = await apiClient(`/api/v1/me/sessions/${session.id}`, { method: 'DELETE' });
      if (!res.ok && res.status !== 404) {
        addToast(
          t('sessions.revoke.errorWithStatus', 'Failed to sign out that session ({status}).', {
            status: res.status,
          }),
          'error'
        );
        return;
      }
      addToast(t('sessions.revoke.success', 'Session signed out.'), 'success');
      setSessions((prev) => prev.filter((s) => s.id !== session.id));
    } catch {
      addToast(t('sessions.revoke.error', 'Failed to sign out that session.'), 'error');
    } finally {
      setBusy(false);
    }
  };

  const revokeAllOthers = async () => {
    setBusy(true);
    try {
      const res = await apiClient('/api/v1/me/sessions', { method: 'DELETE' });
      if (!res.ok) {
        addToast(t('sessions.revokeOthers.error', 'Failed to sign out other sessions.'), 'error');
        return;
      }
      addToast(
        t('sessions.revokeOthers.success', 'Signed out of all other sessions.'),
        'success'
      );
      await load();
    } catch {
      addToast(t('sessions.revokeOthers.error', 'Failed to sign out other sessions.'), 'error');
    } finally {
      setBusy(false);
    }
  };

  const logoutEverywhere = async () => {
    setBusy(true);
    try {
      const res = await apiClient('/api/v1/me/logout-others', { method: 'POST' });
      if (!res.ok) {
        addToast(t('sessions.logoutEverywhere.error', 'Failed to sign out everywhere.'), 'error');
        return;
      }
      await refreshAuth();
      addToast(
        t(
          'sessions.logoutEverywhere.success',
          'Signed out of all other sessions and devices.'
        ),
        'success'
      );
      await load();
    } catch {
      addToast(t('sessions.logoutEverywhere.error', 'Failed to sign out everywhere.'), 'error');
    } finally {
      setBusy(false);
    }
  };

  const hasOthers = sessions.some((s) => !s.current);

  return (
    <div className="space-y-4">
      {loading ? (
        <div className="flex items-center justify-center py-6">
          <div className="animate-spin rounded-full h-6 w-6 border-b-2 border-primary"></div>
        </div>
      ) : loadError ? (
        <div className="text-sm text-destructive" data-testid="sessions-load-error">
          {t('sessions.load.error', 'Failed to load your sessions.')}{' '}
          <button type="button" onClick={() => void load()} className="underline font-medium">
            {t('sessions.load.retry', 'Retry')}
          </button>
        </div>
      ) : sessions.length === 0 ? (
        <p className="text-sm text-muted-foreground" data-testid="sessions-empty">
          {t('sessions.empty', 'No active sessions.')}
        </p>
      ) : (
        <ul className="divide-y divide-border" data-testid="sessions-list">
          {sessions.map((session) => (
            <li
              key={session.id}
              className="flex flex-col gap-2 py-3 first:pt-0 sm:flex-row sm:items-center sm:justify-between"
              data-testid={`session-row-${session.id}`}
            >
              <div className="min-w-0 flex items-start gap-3">
                <IconDeviceDesktop className="w-5 h-5 mt-0.5 shrink-0 text-muted-foreground" />
                <div className="min-w-0">
                  <p
                    className="text-sm font-medium text-foreground truncate"
                    title={session.user_agent || undefined}
                  >
                    {session.device || t('sessions.unknownDevice', 'Unknown device')}
                    {session.current && (
                      <Badge className="ms-2 align-middle" data-testid="session-current-badge">
                        {t('sessions.currentBadge', 'This device')}
                      </Badge>
                    )}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {session.ip_address
                      ? t('sessions.ipAndLastActive', '{ip} · Last active {when}', {
                          ip: session.ip_address,
                          when: formatWhen(session.last_seen_at),
                        })
                      : t('sessions.lastActive', 'Last active {when}', {
                          when: formatWhen(session.last_seen_at),
                        })}
                  </p>
                </div>
              </div>
              {!session.current && (
                <Button
                  type="button"
                  size="sm"
                  variant="outline"
                  disabled={busy}
                  onClick={() => void revokeOne(session)}
                  className="shrink-0"
                  data-testid={`session-revoke-${session.id}`}
                >
                  {t('sessions.signOut', 'Sign out')}
                </Button>
              )}
            </li>
          ))}
        </ul>
      )}

      {hasOthers && (
        <div className="flex flex-col gap-2 pt-2 sm:flex-row">
          <Button
            type="button"
            variant="outline"
            disabled={busy}
            onClick={() => void revokeAllOthers()}
            className="gap-2"
            data-testid="sessions-revoke-others"
          >
            <IconDeviceDesktopOff className="w-4 h-4" />
            {t('sessions.signOutOthers', 'Sign out all other sessions')}
          </Button>

          <AlertDialog>
            <AlertDialogTrigger asChild>
              <Button
                type="button"
                variant="ghost"
                disabled={busy}
                className="gap-2 text-muted-foreground"
                data-testid="sessions-logout-everywhere"
              >
                {t('sessions.signOutEverywhere', 'Sign out everywhere (including devices)')}
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>
                  {t(
                    'sessions.signOutEverywhere.confirm.title',
                    'Sign out everywhere, including devices?'
                  )}
                </AlertDialogTitle>
                <AlertDialogDescription>
                  {t(
                    'sessions.signOutEverywhere.confirm.body',
                    'This keeps you signed in here but signs you out of every other browser, app, and registered device (they’ll each need to sign in again). This can’t be undone.'
                  )}
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>{t('sessions.confirm.cancel', 'Cancel')}</AlertDialogCancel>
                <AlertDialogAction
                  onClick={() => void logoutEverywhere()}
                  data-testid="sessions-logout-everywhere-confirm"
                >
                  {t('sessions.signOutEverywhere.confirm.submit', 'Sign out everywhere')}
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      )}
    </div>
  );
}
