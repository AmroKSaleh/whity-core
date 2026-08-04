'use client';

import { Suspense, useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { useSearchParams } from 'next/navigation';
import { useBranding } from '@/lib/branding-context';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';

/**
 * Password-reset landing page (WC-password-reset-2fa-recovery). This is where
 * the reset link emailed by the backend lands (PASSWORD_RESET_URL points
 * here). Mirrors web/app/verify-email/page.tsx's token-consumption shape
 * (Suspense-wrapped useSearchParams, generic error messaging, the
 * X-Requested-With CSRF header, credentials:'include'), adapted for this
 * flow's one real difference: the token here is consumed together with a
 * NEW PASSWORD the requester supplies — there is nothing to auto-confirm on
 * mount, so this page shows a form instead of firing on load.
 *
 * Public + unauthenticated (a locked-out user has no session).
 */

type Status = 'form' | 'applied' | 'awaiting_approval' | 'error' | 'no-token';

function ResetPasswordInner() {
  const searchParams = useSearchParams();
  const token = searchParams.get('token');
  const branding = useBranding();

  const [status, setStatus] = useState<Status>(token ? 'form' : 'no-token');
  const [password, setPassword] = useState('');
  const [confirmPassword, setConfirmPassword] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setError(null);

    if (!token) {
      setStatus('no-token');
      return;
    }
    if (password.length < 8) {
      setError('Password must be at least 8 characters');
      return;
    }
    if (password !== confirmPassword) {
      setError('Passwords do not match');
      return;
    }

    setSubmitting(true);
    try {
      const response = await fetch('/api/v1/auth/password/reset', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on state-changing POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ token, password }),
        credentials: 'include',
      });

      if (response.status === 200) {
        const data = await response.json().catch(() => ({}));
        const resultStatus = data?.data?.status as string | undefined;
        setStatus(resultStatus === 'awaiting_approval' ? 'awaiting_approval' : 'applied');
        return;
      }
      if (response.status === 422) {
        const data = await response.json().catch(() => ({}));
        setError(typeof data?.error === 'string' ? data.error : 'Please choose a stronger password');
        return;
      }

      // 400 (invalid/expired token) and anything else — generic failure, the
      // backend never distinguishes bad from expired, and neither do we.
      setStatus('error');
    } catch {
      setStatus('error');
    } finally {
      setSubmitting(false);
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
          <CardTitle className="text-2xl">Reset your password</CardTitle>
          <CardDescription>
            {status === 'applied'
              ? 'Your password has been reset'
              : status === 'awaiting_approval'
                ? 'Submitted for approval'
                : 'Choose a new password for your account'}
          </CardDescription>
        </CardHeader>

        <CardContent>
          {status === 'form' && (
            <form onSubmit={handleSubmit} className="space-y-4" data-testid="reset-password-form">
              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
                </Alert>
              )}
              <div className="space-y-2">
                <label htmlFor="password" className="text-sm font-medium">
                  New password
                </label>
                <Input
                  id="password"
                  type="password"
                  placeholder="Enter a new password"
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
                  Confirm password
                </label>
                <Input
                  id="confirmPassword"
                  type="password"
                  placeholder="Re-enter the new password"
                  value={confirmPassword}
                  onChange={(e) => {
                    setConfirmPassword(e.target.value);
                    if (error) setError(null);
                  }}
                  disabled={submitting}
                />
              </div>
              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting ? 'Resetting…' : 'Reset password'}
              </Button>
            </form>
          )}

          {status === 'applied' && (
            <div className="space-y-4 text-center" data-testid="reset-password-applied">
              <Alert>
                <AlertDescription>
                  Your password has been reset. You can now sign in with your new password.
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full">
                <Link href="/login">Continue to sign in</Link>
              </Button>
            </div>
          )}

          {status === 'awaiting_approval' && (
            <div className="space-y-4 text-center" data-testid="reset-password-awaiting-approval">
              <Alert>
                <AlertDescription>
                  Your password reset has been submitted for administrator approval. You&rsquo;ll be
                  able to sign in with your new password once it&rsquo;s approved.
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full" variant="outline">
                <Link href="/login">Back to sign in</Link>
              </Button>
            </div>
          )}

          {(status === 'error' || status === 'no-token') && (
            <div className="space-y-4" data-testid="reset-password-error">
              <Alert variant="destructive">
                <AlertDescription>
                  This reset link is invalid or has expired. Request a new one below.
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full">
                <Link href="/forgot-password">Request a new link</Link>
              </Button>
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

export default function ResetPasswordPage() {
  return (
    <Suspense
      fallback={
        <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
          <p className="text-sm text-muted-foreground">Loading…</p>
        </div>
      }
    >
      <ResetPasswordInner />
    </Suspense>
  );
}
