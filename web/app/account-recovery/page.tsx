'use client';

import { Suspense, useEffect, useRef, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useToast } from '@/lib/toast-context';
import { useBranding } from '@/lib/branding-context';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';

/**
 * "I lost my 2FA device" account-recovery entry point
 * (WC-password-reset-2fa-recovery), for a user locked out because they lost
 * BOTH their password and their 2FA device/backup codes and so cannot log in
 * at all.
 *
 * Two modes, driven by the `?token=` query param — mirrors
 * web/app/verify-email/page.tsx's shape exactly:
 *  - no token → the request form (POST /api/v1/auth/2fa-recovery/request),
 *    which always reports the same generic "if that address has an account, a
 *    confirmation link is on its way" so it never reveals whether an address
 *    exists or whether this flow is even enabled for this instance.
 *  - token present → auto-confirm via POST /api/v1/auth/2fa-recovery/confirm
 *    (the same useRef single-fire guard as verify-email, since the confirmation
 *    token is SINGLE-USE and React strict-mode would otherwise double-consume
 *    it). Unlike a password reset, there is NO password field here — confirming
 *    only SUBMITS the request into the admin approval queue; nothing is
 *    cleared or changed until an admin reviews it.
 *
 * Public + unauthenticated (a user who lost both factors has no session).
 */

type Status = 'confirming' | 'submitted' | 'error' | 'request-form';

function AccountRecoveryInner() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token');
  const { addToast } = useToast();
  const branding = useBranding();

  const [status, setStatus] = useState<Status>(token ? 'confirming' : 'request-form');

  // Request-form state.
  const [email, setEmail] = useState('');
  const [requestSubmitting, setRequestSubmitting] = useState(false);
  const [requestSent, setRequestSent] = useState(false);
  const [requestError, setRequestError] = useState<string | null>(null);

  // The confirmation token is SINGLE-USE, so the confirm POST must fire
  // exactly once even under React strict-mode's double effect invocation.
  const confirmStarted = useRef(false);

  useEffect(() => {
    if (!token) {
      return;
    }
    if (confirmStarted.current) {
      return;
    }
    confirmStarted.current = true;

    void (async () => {
      try {
        const response = await fetch('/api/v1/auth/2fa-recovery/confirm', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ token }),
          credentials: 'include',
        });

        if (response.status === 200) {
          setStatus('submitted');
          addToast('Your account-recovery request has been submitted for review.', 'success');
          return;
        }

        // Any non-200 (400 invalid/expired, 422 missing) is a generic failure.
        setStatus('error');
      } catch {
        setStatus('error');
      }
    })();
  }, [token, addToast]);

  const handleRequestSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setRequestError(null);

    const trimmed = email.trim();
    if (!trimmed) {
      setRequestError('Email is required');
      return;
    }

    setRequestSubmitting(true);
    try {
      const response = await fetch('/api/v1/auth/2fa-recovery/request', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ email: trimmed }),
        credentials: 'include',
      });

      if (response.status === 422) {
        setRequestError('Please enter a valid email address');
        return;
      }
      if (response.status === 429) {
        setRequestError('Too many requests. Please wait a little while and try again.');
        return;
      }

      // 202 (and any other non-error) → generic confirmation. We do NOT
      // reveal whether the address has an account.
      setRequestSent(true);
      addToast('If that address has an account, a confirmation link is on its way.', 'success');
    } catch {
      setRequestError('Unable to reach the server. Please try again.');
    } finally {
      setRequestSubmitting(false);
    }
  };

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
          <CardTitle className="text-2xl">Account recovery</CardTitle>
          <CardDescription>
            {status === 'confirming'
              ? 'Confirming your recovery request…'
              : status === 'submitted'
                ? 'Your request has been submitted'
                : 'Lost your password and your two-factor device? Start recovery here'}
          </CardDescription>
        </CardHeader>

        <CardContent>
          {status === 'confirming' && (
            <p className="text-sm text-center text-muted-foreground" data-testid="account-recovery-pending">
              Confirming your request…
            </p>
          )}

          {status === 'submitted' && (
            <div className="space-y-4 text-center" data-testid="account-recovery-submitted">
              <Alert>
                <AlertDescription>
                  Your account-recovery request has been submitted for administrator review. Once
                  approved, your two-factor authentication will be cleared and you&rsquo;ll receive a
                  password-reset link by email.
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full">
                <Link href="/login">Back to sign in</Link>
              </Button>
            </div>
          )}

          {(status === 'error' || status === 'request-form') && (
            <div className="space-y-4">
              {status === 'error' && (
                <Alert variant="destructive">
                  <AlertDescription>
                    This confirmation link is invalid or has expired. Enter your email below to start
                    a new recovery request.
                  </AlertDescription>
                </Alert>
              )}

              {requestSent ? (
                <Alert data-testid="account-recovery-request-sent">
                  <AlertDescription>
                    If that address has an account, a confirmation link is on its way. Check your
                    inbox.
                  </AlertDescription>
                </Alert>
              ) : (
                <form onSubmit={handleRequestSubmit} className="space-y-4" data-testid="account-recovery-form">
                  <p className="text-sm text-muted-foreground">
                    This is for the rare case you&rsquo;ve lost BOTH your password and your
                    authenticator/backup codes. An administrator must review and approve every
                    request before anything changes on your account.
                  </p>
                  {requestError && (
                    <Alert variant="destructive">
                      <AlertDescription>{requestError}</AlertDescription>
                    </Alert>
                  )}
                  <div className="space-y-2">
                    <label htmlFor="email" className="text-sm font-medium">
                      Email
                    </label>
                    <Input
                      id="email"
                      type="email"
                      placeholder="you@example.com"
                      value={email}
                      onChange={(e) => {
                        setEmail(e.target.value);
                        if (requestError) setRequestError(null);
                      }}
                      disabled={requestSubmitting}
                      className={requestError ? 'border-destructive' : ''}
                    />
                  </div>
                  <Button type="submit" className="w-full" disabled={requestSubmitting}>
                    {requestSubmitting ? 'Sending…' : 'Request recovery'}
                  </Button>
                </form>
              )}

              <p className="text-sm text-center text-muted-foreground">
                Only lost your password?{' '}
                <Link href="/forgot-password" className="font-medium text-primary hover:underline">
                  Reset it instead
                </Link>
              </p>
              <p className="text-sm text-center text-muted-foreground">
                <Link href="/login" className="font-medium text-primary hover:underline">
                  Back to sign in
                </Link>
              </p>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

export default function AccountRecoveryPage() {
  return (
    <Suspense
      fallback={
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
          <p className="text-sm text-muted-foreground">Loading…</p>
        </div>
      }
    >
      <AccountRecoveryInner />
    </Suspense>
  );
}
