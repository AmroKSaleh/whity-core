'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { Input } from '@amroksaleh/ui/input';
import { IconMail, IconPlus } from '@tabler/icons-react';

/**
 * Self-service multi-email management (WC-54fb5c37): list/add/verify/
 * resend/set-primary/remove atop the backend's profile_emails-based
 * /api/v1/me/emails surface (MeEmailsApiHandler). The existing single-email
 * PATCH /api/v1/me field (see profile-form.tsx) stays as a special case —
 * this is the full multi-address surface.
 *
 * Per the System-Tenant Context & Per-Caller UX Gating rule: never present an
 * action the backend will reject — Remove is disabled for the only address
 * and for the current primary (the backend enforces both regardless; this
 * just avoids a guaranteed-failing round trip).
 */
interface ProfileEmail {
  id: number;
  email: string;
  verified: boolean;
  isPrimary: boolean;
  createdAt: string;
}

export function EmailAddressesSettings() {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [emails, setEmails] = useState<ProfileEmail[]>([]);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [adding, setAdding] = useState(false);
  const [newEmail, setNewEmail] = useState('');

  const load = useCallback(async () => {
    setLoading(true);
    setLoadError(false);
    try {
      const res = await apiClient('/api/v1/me/emails', { method: 'GET' });
      if (!res.ok) {
        setLoadError(true);
        setEmails([]);
        return;
      }
      const body = (await res.json().catch(() => ({}))) as { data?: ProfileEmail[] };
      setEmails(Array.isArray(body.data) ? body.data : []);
    } catch {
      setLoadError(true);
      setEmails([]);
    } finally {
      setLoading(false);
    }
  }, [apiClient]);

  useEffect(() => {
    // Deferred off the synchronous effect tick, matching SessionsSettings.
    void Promise.resolve().then(load);
  }, [load]);

  const handleAdd = async (event: React.FormEvent) => {
    event.preventDefault();
    const email = newEmail.trim();
    if (email === '') {
      return;
    }

    setAdding(true);
    try {
      const res = await apiClient('/api/v1/me/emails', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ email }),
      });
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { error?: string };
        addToast(body.error || `Failed to add email address (${res.status}).`, 'error');
        return;
      }
      addToast('Verification email sent — check your inbox.', 'success');
      setNewEmail('');
      await load();
    } catch {
      addToast('Failed to add email address.', 'error');
    } finally {
      setAdding(false);
    }
  };

  const resendVerification = async (item: ProfileEmail) => {
    setBusyId(item.id);
    try {
      const res = await apiClient(`/api/v1/me/emails/${item.id}/resend-verification`, { method: 'POST' });
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { error?: string };
        addToast(body.error || 'Failed to resend verification email.', 'error');
        return;
      }
      addToast('Verification email sent.', 'success');
    } catch {
      addToast('Failed to resend verification email.', 'error');
    } finally {
      setBusyId(null);
    }
  };

  const setPrimary = async (item: ProfileEmail) => {
    setBusyId(item.id);
    try {
      const res = await apiClient(`/api/v1/me/emails/${item.id}/set-primary`, { method: 'POST' });
      if (!res.ok) {
        const body = (await res.json().catch(() => ({}))) as { error?: string };
        addToast(body.error || 'Failed to set as primary.', 'error');
        return;
      }
      addToast('Primary email updated.', 'success');
      await load();
    } catch {
      addToast('Failed to set as primary.', 'error');
    } finally {
      setBusyId(null);
    }
  };

  const remove = async (item: ProfileEmail) => {
    setBusyId(item.id);
    try {
      const res = await apiClient(`/api/v1/me/emails/${item.id}`, { method: 'DELETE' });
      if (!res.ok && res.status !== 404) {
        const body = (await res.json().catch(() => ({}))) as { error?: string };
        addToast(body.error || 'Failed to remove email address.', 'error');
        return;
      }
      addToast('Email address removed.', 'success');
      setEmails((prev) => prev.filter((e) => e.id !== item.id));
    } catch {
      addToast('Failed to remove email address.', 'error');
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
        <div className="text-sm text-destructive" data-testid="emails-load-error">
          Failed to load your email addresses.{' '}
          <button type="button" onClick={() => void load()} className="underline font-medium">
            Retry
          </button>
        </div>
      ) : (
        <ul className="divide-y divide-border" data-testid="emails-list">
          {emails.map((item) => {
            const canRemove = !item.isPrimary && emails.length > 1;
            const busy = busyId === item.id;
            return (
              <li
                key={item.id}
                className="flex flex-col gap-2 py-3 first:pt-0 sm:flex-row sm:items-center sm:justify-between"
                data-testid={`email-row-${item.id}`}
              >
                <div className="min-w-0 flex items-start gap-3">
                  <IconMail className="w-5 h-5 mt-0.5 shrink-0 text-muted-foreground" />
                  <div className="min-w-0">
                    <p className="text-sm font-medium text-foreground truncate">
                      {item.email}
                      {item.isPrimary && (
                        <Badge className="ms-2 align-middle" data-testid="email-primary-badge">
                          Primary
                        </Badge>
                      )}
                      {!item.verified && (
                        <Badge
                          variant="outline"
                          className="ms-2 align-middle"
                          data-testid={`email-unverified-badge-${item.id}`}
                        >
                          Unverified
                        </Badge>
                      )}
                    </p>
                  </div>
                </div>
                <div className="flex shrink-0 gap-2">
                  {!item.verified && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={busy}
                      onClick={() => void resendVerification(item)}
                      data-testid={`email-resend-${item.id}`}
                    >
                      Resend verification
                    </Button>
                  )}
                  {item.verified && !item.isPrimary && (
                    <Button
                      type="button"
                      size="sm"
                      variant="outline"
                      disabled={busy}
                      onClick={() => void setPrimary(item)}
                      data-testid={`email-set-primary-${item.id}`}
                    >
                      Set as primary
                    </Button>
                  )}
                  <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={busy || !canRemove}
                    onClick={() => void remove(item)}
                    className="text-destructive"
                    title={
                      !canRemove
                        ? item.isPrimary
                          ? 'Set a different address as primary before removing this one'
                          : 'You must keep at least one email address'
                        : undefined
                    }
                    data-testid={`email-remove-${item.id}`}
                  >
                    Remove
                  </Button>
                </div>
              </li>
            );
          })}
        </ul>
      )}

      <form onSubmit={(e) => void handleAdd(e)} className="flex gap-2 pt-2">
        <Input
          type="email"
          placeholder="Add another email address"
          value={newEmail}
          onChange={(e) => setNewEmail(e.target.value)}
          disabled={adding}
          className="max-w-sm"
          data-testid="email-add-input"
        />
        <Button type="submit" variant="outline" disabled={adding || newEmail.trim() === ''} className="gap-2" data-testid="email-add-submit">
          <IconPlus className="w-4 h-4" />
          Add
        </Button>
      </form>
    </div>
  );
}
