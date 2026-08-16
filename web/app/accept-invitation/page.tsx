'use client';

import { Suspense, useEffect, useRef, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useBranding } from '@/lib/branding-context';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { useTranslation } from '@amroksaleh/features/i18n';

/**
 * Invitation landing page (WHIT-417). This is where the link a tenant
 * administrator sent lands — INVITATION_ACCEPT_URL points here.
 *
 * Mirrors web/app/reset-password/page.tsx (Suspense-wrapped useSearchParams,
 * generic error messaging, the X-Requested-With CSRF header, credentials:
 * 'include'), with the one difference that decides this screen's shape: the
 * invitee MAY ALREADY HAVE AN ACCOUNT, in another tenant. So the page asks the
 * backend first and renders one of two quite different things:
 *
 *   requires_password  → choose a password; accepting creates the profile.
 *   !requires_password → a single confirm button. Somebody who already has a
 *                        password must never be asked to invent a second one,
 *                        and accepting does not touch the one they have.
 *
 * Neither branch signs the invitee in. Accepting grants a MEMBERSHIP; proving
 * you are the person still happens at /login, where an existing account's own
 * password, 2FA and tenant-selection prompt all still apply.
 *
 * Public + unauthenticated: an invitee has no session, and in the case this
 * feature exists for may have no account at all.
 *
 * Direction-agnostic by construction: no physical margins or alignment, so the
 * whole screen mirrors from <html dir> alone (see lib/direction-context.tsx).
 */

type Status = 'loading' | 'form' | 'accepted' | 'error' | 'suspended';

interface InvitationPreview {
  email: string;
  tenantName: string;
  requiresPassword: boolean;
}

function AcceptInvitationInner() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token');
  const branding = useBranding();
  const t = useTranslation('auth');

  const [status, setStatus] = useState<Status>(token ? 'loading' : 'error');
  const [invitation, setInvitation] = useState<InvitationPreview | null>(null);
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const previewStarted = useRef(false);

  // The preview fetch is declared INSIDE the effect and guarded by a ref, the
  // same shape verify-email/page.tsx uses: it keeps every setState indirect
  // (the lint rule against synchronous setState in an effect) and stops a
  // second mount in development from asking twice.
  useEffect(() => {
    if (!token) {
      return;
    }
    if (previewStarted.current) {
      return;
    }
    previewStarted.current = true;

    void (async () => {
      try {
        const response = await fetch(
          `/api/v1/invitations/accept?token=${encodeURIComponent(token)}`,
          { credentials: 'include' }
        );
        if (!response.ok) {
          setStatus('error');
          return;
        }
        const body = await response.json();
        const data = body?.data;
        setInvitation({
          email: typeof data?.email === 'string' ? data.email : '',
          tenantName: typeof data?.tenant_name === 'string' ? data.tenant_name : '',
          requiresPassword: data?.requires_password === true,
        });
        setStatus('form');
      } catch {
        setStatus('error');
      }
    })();
  }, [token]);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setError(null);

    if (!token || invitation === null) {
      setStatus('error');
      return;
    }

    if (invitation.requiresPassword) {
      if (password.length < 8) {
        setError(t('acceptInvitation.error.tooShort', 'Password must be at least 8 characters'));
        return;
      }
      if (password !== confirmPassword) {
        setError(t('acceptInvitation.error.mismatch', 'Passwords do not match'));
        return;
      }
    }

    setSubmitting(true);
    try {
      const response = await fetch('/api/v1/invitations/accept', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on state-changing POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify(
          invitation.requiresPassword ? { token, password } : { token }
        ),
        credentials: 'include',
      });

      if (response.status === 200) {
        setStatus('accepted');
        return;
      }
      if (response.status === 409) {
        setStatus('suspended');
        return;
      }
      if (response.status === 422) {
        const body = await response.json().catch(() => ({}));
        setError(
          typeof body?.error === 'string'
            ? body.error
            : t('acceptInvitation.error.weak', 'Please choose a stronger password')
        );
        return;
      }

      // 400 (invalid/expired/used) and anything else — the backend never
      // distinguishes those, and neither do we.
      setStatus('error');
    } catch {
      setStatus('error');
    } finally {
      setSubmitting(false);
    }
  };

  const workspace = invitation?.tenantName ?? '';

  const logo = branding.logoWideUrl ? (
    <Image
      src={branding.logoWideUrl}
      alt={branding.siteName}
      width={220}
      height={40}
      className="h-10 w-auto max-w-[220px] object-contain mx-auto mb-2"
    />
  ) : null;

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          {logo}
          <CardTitle className="text-2xl">
            {t('acceptInvitation.title', 'Accept your invitation')}
          </CardTitle>
          <CardDescription>
            {status === 'accepted'
              ? t('acceptInvitation.subtitle.accepted', 'You are all set')
              : workspace !== ''
                ? t('acceptInvitation.subtitle.workspace', 'You have been invited to join a workspace')
                : t('acceptInvitation.subtitle', 'Join the workspace you were invited to')}
          </CardDescription>
        </CardHeader>

        <CardContent>
          {status === 'loading' && (
            <p className="text-sm text-center text-muted-foreground">
              {t('acceptInvitation.loading', 'Checking your invitation…')}
            </p>
          )}

          {status === 'form' && invitation !== null && (
            <form onSubmit={handleSubmit} className="space-y-4" data-testid="accept-invitation-form">
              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}

              <Alert>
                <AlertDescription>
                  {workspace !== ''
                    ? t('acceptInvitation.summary.workspace', 'You have been invited to join {workspace} as {email}.', {
                        workspace,
                        email: invitation.email,
                      })
                    : t('acceptInvitation.summary', 'You have been invited to join as {email}.', {
                        email: invitation.email,
                      })}
                </AlertDescription>
              </Alert>

              {invitation.requiresPassword ? (
                <>
                  <div className="space-y-2">
                    <label htmlFor="password" className="text-sm font-medium">
                      {t('acceptInvitation.password.label', 'Choose a password')}
                    </label>
                    <Input
                      id="password"
                      type="password"
                      placeholder={t('acceptInvitation.password.placeholder', 'Enter a password')}
                      value={password}
                      onChange={(e) => {
                        setPassword(e.target.value);
                        if (error) setError(null);
                      }}
                      disabled={submitting}
                    />
                  </div>
                  <div className="space-y-2">
                    <label htmlFor="confirmPassword" className="text-sm font-medium">
                      {t('acceptInvitation.confirmPassword.label', 'Confirm password')}
                    </label>
                    <Input
                      id="confirmPassword"
                      type="password"
                      placeholder={t(
                        'acceptInvitation.confirmPassword.placeholder',
                        'Re-enter the password'
                      )}
                      value={confirmPassword}
                      onChange={(e) => {
                        setConfirmPassword(e.target.value);
                        if (error) setError(null);
                      }}
                      disabled={submitting}
                    />
                  </div>
                </>
              ) : (
                // They already have an account. Say so, and ask for nothing —
                // accepting adds a workspace, it does not change a credential.
                <p className="text-sm text-muted-foreground">
                  {t(
                    'acceptInvitation.existingAccount',
                    'You already have an account with this address. Accepting adds this workspace to it — your password does not change.'
                  )}
                </p>
              )}

              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting
                  ? t('acceptInvitation.submit.pending', 'Accepting…')
                  : t('acceptInvitation.submit', 'Accept invitation')}
              </Button>
            </form>
          )}

          {status === 'accepted' && (
            <div className="space-y-4 text-center" data-testid="accept-invitation-accepted">
              <Alert>
                <AlertDescription>
                  {t(
                    'acceptInvitation.accepted',
                    'Your invitation has been accepted. Sign in to continue.'
                  )}
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full">
                <Link href="/login">{t('acceptInvitation.continue', 'Continue to sign in')}</Link>
              </Button>
            </div>
          )}

          {status === 'suspended' && (
            <div className="space-y-4" data-testid="accept-invitation-suspended">
              <Alert variant="destructive">
                <AlertDescription>
                  {t(
                    'acceptInvitation.suspended',
                    'Your access to this workspace has been suspended. Contact an administrator.'
                  )}
                </AlertDescription>
              </Alert>
              <p className="text-sm text-center text-muted-foreground">
                <Link href="/login" className="font-medium text-primary hover:underline">
                  {t('acceptInvitation.backToSignIn', 'Back to sign in')}
                </Link>
              </p>
            </div>
          )}

          {status === 'error' && (
            <div className="space-y-4" data-testid="accept-invitation-error">
              <Alert variant="destructive">
                <AlertDescription>
                  {t(
                    'acceptInvitation.error.invalidLink',
                    'This invitation link is invalid, has expired, or has already been used. Ask an administrator to send you a new one.'
                  )}
                </AlertDescription>
              </Alert>
              <p className="text-sm text-center text-muted-foreground">
                <Link href="/login" className="font-medium text-primary hover:underline">
                  {t('acceptInvitation.backToSignIn', 'Back to sign in')}
                </Link>
              </p>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

export default function AcceptInvitationPage() {
  const t = useTranslation('auth');

  return (
    <Suspense
      fallback={
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
          <p className="text-sm text-muted-foreground">
            {t('acceptInvitation.loading', 'Checking your invitation…')}
          </p>
        </div>
      }
    >
      <AcceptInvitationInner />
    </Suspense>
  );
}
