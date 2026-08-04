'use client';

import { useState } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { useToast } from '@/lib/toast-context';
import { useBranding } from '@/lib/branding-context';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';

/**
 * Self-service "forgot password" entry point (WC-password-reset-2fa-recovery).
 * Mirrors web/app/verify-email/page.tsx's resend-form half almost exactly:
 * submits an email to POST /api/v1/auth/password/forgot and ALWAYS shows the
 * same generic confirmation, regardless of whether the address exists or
 * whether self-service reset is even enabled for this instance — the backend
 * never reveals either, so this page must not either.
 *
 * Public + unauthenticated (a user who forgot their password has no session).
 */
export default function ForgotPasswordPage() {
  const { addToast } = useToast();
  const branding = useBranding();

  const [email, setEmail] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();
    setError(null);

    const trimmed = email.trim();
    if (!trimmed) {
      setError('Email is required');
      return;
    }

    setSubmitting(true);
    try {
      const response = await fetch('/api/v1/auth/password/forgot', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): mirrors every other state-changing POST.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ email: trimmed }),
        credentials: 'include',
      });

      if (response.status === 422) {
        setError('Please enter a valid email address');
        return;
      }
      if (response.status === 429) {
        setError('Too many requests. Please wait a little while and try again.');
        return;
      }

      // 202 (and any other non-error) → generic confirmation. We do NOT reveal
      // whether the address has an account.
      setSent(true);
      addToast('If that address has an account, a password-reset link is on its way.', 'success');
    } catch {
      setError('Unable to reach the server. Please try again.');
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
          <CardTitle className="text-2xl">Forgot your password?</CardTitle>
          <CardDescription>
            Enter your email and we&rsquo;ll send you a link to reset it
          </CardDescription>
        </CardHeader>

        <CardContent>
          {sent ? (
            <div className="space-y-4 text-center" data-testid="forgot-password-sent">
              <Alert>
                <AlertDescription>
                  If that address has an account, a password-reset link is on its way. Check your
                  inbox.
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full">
                <Link href="/login">Back to sign in</Link>
              </Button>
            </div>
          ) : (
            <form onSubmit={handleSubmit} className="space-y-4" data-testid="forgot-password-form">
              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
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
                    if (error) {
                      setError(null);
                    }
                  }}
                  disabled={submitting}
                  className={error ? 'border-destructive' : ''}
                />
              </div>
              <Button type="submit" className="w-full" disabled={submitting}>
                {submitting ? 'Sending…' : 'Send reset link'}
              </Button>

              <p className="text-sm text-center text-muted-foreground">
                <Link href="/login" className="font-medium text-primary hover:underline">
                  Back to sign in
                </Link>
              </p>
              <p className="text-sm text-center text-muted-foreground">
                Lost your authenticator too?{' '}
                <Link href="/account-recovery" className="font-medium text-primary hover:underline">
                  Recover your account
                </Link>
              </p>
            </form>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
