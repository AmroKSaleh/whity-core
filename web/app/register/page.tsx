'use client';

import { useEffect, useState, useRef } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useBranding } from '@/lib/branding-context';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { useRichTranslation, useTranslation } from '@amroksaleh/features/i18n';

/**
 * Self-service registration (WC-235). Provisions a NEW workspace (tenant) with
 * the registrant as its owner via POST /api/v1/register, then signs them in by
 * chaining POST /api/v1/login with the same credentials (a fresh owner has a
 * single active membership, so login mints the session directly) and redirects
 * to the dashboard. Mirrors the login page's structure, components, and CSRF
 * (X-Requested-With) convention.
 */
const PASSWORD_MIN_LENGTH = 8;

export default function RegisterPage() {
  const router = useRouter();
  const { isAuthenticated, isLoading, refreshAuth } = useAuth();
  const { addToast } = useToast();
  const branding = useBranding();
  const t = useTranslation('auth');
  const rt = useRichTranslation('auth');

  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [tenantName, setTenantName] = useState('');
  const [displayName, setDisplayName] = useState('');
  const [fieldErrors, setFieldErrors] = useState<{
    email?: string;
    password?: string;
    tenantName?: string;
  }>({});
  const [registerError, setRegisterError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isMounted, setIsMounted] = useState(false);
  // WC-235: when admin approval is enforced the owner cannot log in yet, so we
  // show a "pending approval" confirmation instead of chaining a login.
  const [pendingApproval, setPendingApproval] = useState(false);
  // WC-235: when email verification is enforced the owner must confirm their
  // address first, so we show a "check your email" confirmation instead of
  // chaining a login into the dashboard with an unverified address.
  const [pendingVerification, setPendingVerification] = useState(false);
  const emailInputRef = useRef<HTMLInputElement>(null);

  // Match the login page's SSR-safe "enabled until mounted" timing so the
  // server markup matches hydration; focus the first field on mount. The flag
  // flip is scheduled off the synchronous effect tick (a microtask) to stay
  // clear of React's set-state-in-effect rule while preserving the timing.
  useEffect(() => {
    emailInputRef.current?.focus();
    void Promise.resolve().then(() => setIsMounted(true));
  }, []);

  // Already signed in → no need to register.
  useEffect(() => {
    if (isMounted && isAuthenticated()) {
      router.push('/dashboard');
    }
  }, [isAuthenticated, router, isMounted]);

  const validateFields = (): boolean => {
    const errors: { email?: string; password?: string; tenantName?: string } = {};

    if (!email.trim()) {
      errors.email = t('register.email.required', 'Email is required');
    }
    if (!password) {
      errors.password = t('register.password.required', 'Password is required');
    } else if (password.length < PASSWORD_MIN_LENGTH) {
      // The minimum is a hole in the sentence, not a spliced-in number — the
      // digit's position differs between languages.
      errors.password = t(
        'register.password.tooShort',
        'Password must be at least {min} characters',
        { min: PASSWORD_MIN_LENGTH }
      );
    }
    if (!tenantName.trim()) {
      errors.tenantName = t('register.tenantName.required', 'Workspace name is required');
    }

    setFieldErrors(errors);
    return Object.keys(errors).length === 0;
  };

  const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();

    if (!validateFields()) {
      return;
    }

    setIsSubmitting(true);
    setRegisterError(null);
    try {
      const response = await fetch('/api/v1/register', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on state-changing POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({
          email,
          password,
          tenant_name: tenantName,
          ...(displayName.trim() ? { display_name: displayName } : {}),
        }),
        credentials: 'include',
      });

      if (response.status === 201) {
        // WC-235: when admin approval is enforced the owner membership is
        // 'invited' (pending), so a login would be refused. Show a pending
        // confirmation instead of chaining login.
        const created = await response.json().catch(() => ({}));
        if (created?.data?.approval_required === true) {
          setPendingApproval(true);
          addToast(
            t('register.toast.pendingApproval', 'Workspace created — awaiting administrator approval.'),
            'success'
          );
          return;
        }

        // Email verification enforced: the address starts unverified and the
        // owner must confirm it (via the emailed link → /verify-email) before
        // signing in, so show a "check your email" panel rather than chaining a
        // login. Independent of the approval gate above.
        if (created?.data?.verification_required === true) {
          setPendingVerification(true);
          addToast(
            t(
              'register.toast.pendingVerification',
              'Workspace created — check your email to verify your address.'
            ),
            'success'
          );
          return;
        }

        // Account + workspace created. Sign in with the same credentials — a
        // fresh owner has exactly one active membership, so login mints the
        // session directly.
        const loginRes = await fetch('/api/v1/login', {
          method: 'POST',
          headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
          },
          body: JSON.stringify({ email, password }),
          credentials: 'include',
        });

        if (loginRes.ok) {
          await refreshAuth();
          addToast(t('register.toast.welcome', 'Welcome! Your workspace is ready.'), 'success');
          router.push('/dashboard');
        } else {
          // Account exists but auto-login did not complete (unexpected for a
          // fresh single-membership owner) — send them to the login page.
          addToast(
            t('register.toast.signInRequired', 'Account created. Please sign in to continue.'),
            'success'
          );
          router.push('/login');
        }
        return;
      }

      const errorData = await response.json().catch(() => ({}));
      const message =
        response.status === 409
          ? (errorData.error as string | undefined) ??
            t('register.error.conflict', 'That email or workspace name is already taken')
          : response.status === 422
            ? (errorData.error as string | undefined) ??
              t('register.error.invalid', 'Please check the details and try again')
            : (errorData.error as string | undefined) ??
              t('register.error.generic', 'Registration failed');
      setRegisterError(message);
      addToast(
        t('register.error.withStatus', 'Registration failed ({status}): {message}', {
          status: response.status,
          message,
        }),
        'error'
      );
    } catch {
      const message = t('register.error.transport', 'Unable to reach the server. Please try again.');
      setRegisterError(message);
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const isFormDisabled = isMounted ? (isSubmitting || isLoading) : false;
  const buttonText = isFormDisabled
    ? t('register.submit.pending', 'Creating your workspace…')
    : t('register.submit', 'Create workspace');

  const clearErrorsOnChange = () => {
    if (registerError) {
      setRegisterError(null);
    }
  };

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          {branding.logoWideUrl ? (
            <Image
              src={branding.logoWideUrl}
              alt={branding.siteName}
              width={220}
              height={40}
              className="h-10 w-auto max-w-[220px] object-contain mx-auto mb-2"
            />
          ) : null}
          <CardTitle className="text-2xl">
            {pendingApproval
              ? t('register.title.pendingApproval', 'Workspace created')
              : pendingVerification
                ? t('register.title.pendingVerification', 'Check your email')
                : t('register.title', 'Create your {site} workspace', {
                    site: branding.siteName,
                  })}
          </CardTitle>
          <CardDescription>
            {pendingApproval
              ? t(
                  'register.subtitle.pendingApproval',
                  'Your workspace is awaiting administrator approval'
                )
              : pendingVerification
                ? t(
                    'register.subtitle.pendingVerification',
                    'Confirm your email address to finish signing up'
                  )
                : t('register.subtitle', 'Set up a new workspace and your owner account')}
          </CardDescription>
        </CardHeader>
        {pendingApproval ? (
          <CardContent>
            <div className="space-y-4 text-center" data-testid="registration-pending-approval">
              <Alert>
                <AlertDescription>
                  {t(
                    'register.pendingApproval.body',
                    'Thanks for signing up! An administrator needs to approve your new workspace before you can sign in. You’ll be able to log in with your email and password once it’s approved.'
                  )}
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full">
                <Link href="/login">{t('register.backToSignIn', 'Back to sign in')}</Link>
              </Button>
            </div>
          </CardContent>
        ) : pendingVerification ? (
          <CardContent>
            <div className="space-y-4 text-center" data-testid="registration-pending-verification">
              <Alert>
                {/* One key for the whole sentence, with the address as a hole
                    rather than a seam: `<0>` can move anywhere the translator's
                    grammar needs it. Splitting this into "…link to" + address +
                    ". Open it…" would have frozen English word order and handed
                    a translator a fragment beginning with a full stop. */}
                <AlertDescription>
                  {rt(
                    'register.pendingVerification.body',
                    'Thanks for signing up! We’ve sent a verification link to <0>{email}</0>. ' +
                      'Open it to confirm your address, then sign in. The link expires in 24 hours.',
                    { email },
                    [<span key="email" className="font-medium" />]
                  )}
                </AlertDescription>
              </Alert>
              <Button asChild className="w-full">
                <Link href="/verify-email">
                  {t('register.pendingVerification.resend', 'Didn’t get it? Resend the link')}
                </Link>
              </Button>
              <p className="text-sm text-center text-muted-foreground">
                <Link href="/login" className="font-medium text-primary hover:underline">
                  {t('register.backToSignIn', 'Back to sign in')}
                </Link>
              </p>
            </div>
          </CardContent>
        ) : (
        <CardContent>
          <form onSubmit={handleSubmit} className="space-y-4">
            {registerError && (
              <Alert variant="destructive">
                <AlertDescription>{registerError}</AlertDescription>
              </Alert>
            )}

            {/* Workspace name */}
            <div className="space-y-2">
              <label htmlFor="tenantName" className="text-sm font-medium">
                {t('register.tenantName.label', 'Workspace name')}
              </label>
              <Input
                id="tenantName"
                type="text"
                placeholder={t('register.tenantName.placeholder', 'Acme Inc')}
                value={tenantName}
                onChange={(e) => {
                  setTenantName(e.target.value);
                  if (fieldErrors.tenantName) {
                    setFieldErrors({ ...fieldErrors, tenantName: undefined });
                  }
                  clearErrorsOnChange();
                }}
                disabled={isFormDisabled}
                className={fieldErrors.tenantName ? 'border-destructive' : ''}
              />
              {fieldErrors.tenantName && (
                <p className="text-xs text-destructive">{fieldErrors.tenantName}</p>
              )}
            </div>

            {/* Email */}
            <div className="space-y-2">
              <label htmlFor="email" className="text-sm font-medium">
                {t('register.email.label', 'Email')}
              </label>
              <Input
                ref={emailInputRef}
                id="email"
                type="email"
                placeholder={t('register.email.placeholder', 'you@example.com')}
                value={email}
                onChange={(e) => {
                  setEmail(e.target.value);
                  if (fieldErrors.email) {
                    setFieldErrors({ ...fieldErrors, email: undefined });
                  }
                  clearErrorsOnChange();
                }}
                disabled={isFormDisabled}
                className={fieldErrors.email ? 'border-destructive' : ''}
              />
              {fieldErrors.email && <p className="text-xs text-destructive">{fieldErrors.email}</p>}
            </div>

            {/* Display name (optional) */}
            <div className="space-y-2">
              {/* Two keys because "(optional)" is its own ELEMENT (a muted
                  span) and a self-contained qualifier, not half of a sentence
                  split for convenience — same reasoning as the sign-in
                  screen's <strong>Recovery codes</strong>. */}
              <label htmlFor="displayName" className="text-sm font-medium">
                {t('register.displayName.label', 'Your name')}{' '}
                <span className="text-muted-foreground">
                  {t('register.displayName.optional', '(optional)')}
                </span>
              </label>
              <Input
                id="displayName"
                type="text"
                placeholder={t('register.displayName.placeholder', 'Jane Doe')}
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
                disabled={isFormDisabled}
              />
            </div>

            {/* Password */}
            <div className="space-y-2">
              <label htmlFor="password" className="text-sm font-medium">
                {t('register.password.label', 'Password')}
              </label>
              <Input
                id="password"
                type="password"
                placeholder={t('register.password.placeholder', 'At least {min} characters', {
                  min: PASSWORD_MIN_LENGTH,
                })}
                value={password}
                onChange={(e) => {
                  setPassword(e.target.value);
                  if (fieldErrors.password) {
                    setFieldErrors({ ...fieldErrors, password: undefined });
                  }
                  clearErrorsOnChange();
                }}
                disabled={isFormDisabled}
                className={fieldErrors.password ? 'border-destructive' : ''}
              />
              {fieldErrors.password && (
                <p className="text-xs text-destructive">{fieldErrors.password}</p>
              )}
            </div>

            <Button type="submit" className="w-full" disabled={isFormDisabled}>
              {buttonText}
            </Button>

            {/* Prompt and link text are SEPARATE keys because they are
                separate elements — the same shape the sign-in screen uses for
                its `login.register.prompt` / `login.register.link` footer. */}
            <p className="text-sm text-center text-muted-foreground">
              {t('register.signIn.prompt', 'Already have an account?')}{' '}
              <Link href="/login" className="font-medium text-primary hover:underline">
                {t('register.signIn.link', 'Sign in')}
              </Link>
            </p>
          </form>
        </CardContent>
        )}
      </Card>
    </div>
  );
}
