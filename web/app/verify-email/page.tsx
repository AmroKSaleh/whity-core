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
 * Email verification landing page (WC-235). This is where the verification link
 * emailed by the backend lands (EMAIL_VERIFICATION_URL should point here).
 *
 * Two modes, driven by the `?token=` query param:
 *  - token present → auto-confirm via POST /api/v1/email/verify, showing a
 *    success or a generic "invalid/expired" state (with a resend option).
 *  - no token → the resend form (POST /api/v1/email/request-verification),
 *    which always reports a generic "if that address needs verification, a link
 *    has been sent" so it never reveals whether an address exists.
 *
 * Public + unauthenticated (a user verifying an email is typically not signed
 * in). Mirrors the login/register standalone-screen idiom and CSRF convention.
 */

type VerifyStatus = 'verifying' | 'success' | 'error' | 'no-token';

function VerifyEmailInner() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token');
  const { addToast } = useToast();
  const branding = useBranding();

  const [status, setStatus] = useState<VerifyStatus>(token ? 'verifying' : 'no-token');
  const [verifiedEmail, setVerifiedEmail] = useState<string | null>(null);

  // Resend form state.
  const [resendEmail, setResendEmail] = useState('');
  const [resendSubmitting, setResendSubmitting] = useState(false);
  const [resendSent, setResendSent] = useState(false);
  const [resendError, setResendError] = useState<string | null>(null);

  // The verification token is SINGLE-USE, so the confirm POST must fire exactly
  // once even under React strict-mode's double effect invocation in dev — a
  // second POST would consume-fail and flip a genuine success to an error.
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
        const response = await fetch('/api/v1/email/verify', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            // CSRF defense (WC-160): required on state-changing POSTs.
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ token }),
          credentials: 'include',
        });

        if (response.status === 200) {
          const data = await response.json().catch(() => ({}));
          setVerifiedEmail((data?.data?.email as string | undefined) ?? null);
          setStatus('success');
          addToast('Your email address has been verified.', 'success');
          return;
        }

        // Any non-200 (400 invalid/expired, 422 missing) is a generic failure —
        // the backend never distinguishes bad from expired, and neither do we.
        setStatus('error');
      } catch {
        setStatus('error');
      }
    })();
  }, [token, addToast]);

  const handleResend = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setResendError(null);

    const email = resendEmail.trim();
    if (!email) {
      setResendError('Email is required');
      return;
    }

    setResendSubmitting(true);
    try {
      const response = await fetch('/api/v1/email/request-verification', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ email }),
        credentials: 'include',
      });

      if (response.status === 422) {
        setResendError('Please enter a valid email address');
        return;
      }
      if (response.status === 429) {
        setResendError('Too many requests. Please wait a little while and try again.');
        return;
      }

      // 202 (and any other non-error) → generic confirmation. We do NOT reveal
      // whether the address exists or still needs verification.
      setResendSent(true);
      addToast('If that address needs verification, a link is on its way.', 'success');
    } catch {
      setResendError('Unable to reach the server. Please try again.');
    } finally {
      setResendSubmitting(false);
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
          <CardTitle className="text-2xl">Verify your email</CardTitle>
          <CardDescription>
            {status === 'success'
              ? 'Your email address is confirmed'
              : status === 'verifying'
                ? 'Confirming your email address…'
                : 'Confirm your email address to finish setting up your account'}
          </CardDescription>
        </CardHeader>

        <CardContent>
          {status === 'verifying' && (
            <p className="text-sm text-center text-muted-foreground" data-testid="verify-pending">
              Verifying your link…
            </p>
          )}

          {status === 'success' && (
            <div className="space-y-4 text-center" data-testid="verify-success">
              <Alert>
                <AlertDescription>
                  {verifiedEmail
                    ? `${verifiedEmail} has been verified. You can now sign in.`
                    : 'Your email address has been verified. You can now sign in.'}
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full">
                <Link href="/login">Continue to sign in</Link>
              </Button>
            </div>
          )}

          {(status === 'error' || status === 'no-token') && (
            <div className="space-y-4" data-testid="verify-resend">
              {status === 'error' && (
                <Alert variant="destructive">
                  <AlertDescription>
                    This verification link is invalid or has expired. Enter your email below and
                    we&rsquo;ll send you a new one.
                  </AlertDescription>
                </Alert>
              )}

              {resendSent ? (
                <Alert data-testid="verify-resend-sent">
                  <AlertDescription>
                    If that address needs verification, a new link is on its way. Check your inbox.
                  </AlertDescription>
                </Alert>
              ) : (
                <form onSubmit={handleResend} className="space-y-4">
                  {resendError && (
                    <Alert variant="destructive">
                      <AlertDescription>{resendError}</AlertDescription>
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
                      value={resendEmail}
                      onChange={(e) => {
                        setResendEmail(e.target.value);
                        if (resendError) {
                          setResendError(null);
                        }
                      }}
                      disabled={resendSubmitting}
                      className={resendError ? 'border-destructive' : ''}
                    />
                  </div>
                  <Button type="submit" className="w-full" disabled={resendSubmitting}>
                    {resendSubmitting ? 'Sending…' : 'Send verification link'}
                  </Button>
                </form>
              )}

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

export default function VerifyEmailPage() {
  // useSearchParams requires a Suspense boundary so the rest of the route can
  // prerender while this client subtree hydrates (Next.js app-router).
  return (
    <Suspense
      fallback={
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
          <p className="text-sm text-muted-foreground">Loading…</p>
        </div>
      }
    >
      <VerifyEmailInner />
    </Suspense>
  );
}
