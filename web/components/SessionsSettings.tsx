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
import { IconDeviceDesktop, IconDeviceDesktopOff } from '@tabler/icons-react';

/**
 * WC-f-sessions-table: interactive session management on the Settings page.
 *
 * Lists the user's active login sessions (GET /api/v1/me/sessions) with user
 * agent, IP, and last-active time, flagging the current one. Each other session
 * can be signed out individually (DELETE /api/v1/me/sessions/{id}); a single
 * action signs out all other sessions (DELETE /api/v1/me/sessions). A stronger
 * "everywhere including devices" action (POST /api/v1/me/logout-others, WC-b)
 * additionally revokes native-device credentials via the token-epoch bump.
 *
 * Native-device enrollments themselves are managed on their own list (#409);
 * this surface is interactive logins only ("two lists").
 */
interface Session {
  id: number;
  user_agent: string | null;
  ip_address: string | null;
  created_at: string;
  last_seen_at: string;
  current: boolean;
}

function formatWhen(value: string): string {
  const t = Date.parse(value.replace(' ', 'T') + 'Z');
  return Number.isNaN(t) ? value : new Date(t).toLocaleString();
}

export function SessionsSettings() {
  const { apiClient, refreshAuth } = useAuth();
  const { addToast } = useToast();
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
        addToast(`Failed to sign out that session (${res.status}).`, 'error');
        return;
      }
      addToast('Session signed out.', 'success');
      setSessions((prev) => prev.filter((s) => s.id !== session.id));
    } catch {
      addToast('Failed to sign out that session.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const revokeAllOthers = async () => {
    setBusy(true);
    try {
      const res = await apiClient('/api/v1/me/sessions', { method: 'DELETE' });
      if (!res.ok) {
        addToast('Failed to sign out other sessions.', 'error');
        return;
      }
      addToast('Signed out of all other sessions.', 'success');
      await load();
    } catch {
      addToast('Failed to sign out other sessions.', 'error');
    } finally {
      setBusy(false);
    }
  };

  const logoutEverywhere = async () => {
    setBusy(true);
    try {
      const res = await apiClient('/api/v1/me/logout-others', { method: 'POST' });
      if (!res.ok) {
        addToast('Failed to sign out everywhere.', 'error');
        return;
      }
      await refreshAuth();
      addToast('Signed out of all other sessions and devices.', 'success');
      await load();
    } catch {
      addToast('Failed to sign out everywhere.', 'error');
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
          Failed to load your sessions.{' '}
          <button type="button" onClick={() => void load()} className="underline font-medium">
            Retry
          </button>
        </div>
      ) : sessions.length === 0 ? (
        <p className="text-sm text-muted-foreground" data-testid="sessions-empty">
          No active sessions.
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
                  <p className="text-sm font-medium text-foreground truncate">
                    {session.user_agent || 'Unknown device'}
                    {session.current && (
                      <Badge className="ml-2 align-middle" data-testid="session-current-badge">
                        This device
                      </Badge>
                    )}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {session.ip_address ? `${session.ip_address} · ` : ''}
                    Last active {formatWhen(session.last_seen_at)}
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
                  Sign out
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
            Sign out all other sessions
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
                Sign out everywhere (including devices)
              </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>Sign out everywhere, including devices?</AlertDialogTitle>
                <AlertDialogDescription>
                  This keeps you signed in here but signs you out of every other browser, app, and
                  registered device (they&rsquo;ll each need to sign in again). This can&rsquo;t be undone.
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>Cancel</AlertDialogCancel>
                <AlertDialogAction
                  onClick={() => void logoutEverywhere()}
                  data-testid="sessions-logout-everywhere-confirm"
                >
                  Sign out everywhere
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      )}
    </div>
  );
}
