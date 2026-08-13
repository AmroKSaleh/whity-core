'use client';

import { useEffect, useState, useRef } from 'react';
import Image from 'next/image';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useBranding } from '@/lib/branding-context';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { SsoLoginButtons } from '@/components/sso-login-buttons';
import { TwoFactorSetupWizard } from '@/components/TwoFactorSettings';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';

/**
 * THIS SCREEN IS THE TRANSLATION TEMPLATE — the first one converted from
 * hardcoded English to real translations, and the shape every other screen
 * should copy.
 *
 * The four rules it demonstrates:
 *
 *  1. ONE DOMAIN PER AREA, named for the area. This screen's is `auth` — a bare
 *     slug, which is the reserved CORE namespace. A plugin's domain carries its
 *     source slug (`acme:catalog`), exactly like the resource-type registry's
 *     `acme:record`. The rule lives in src/Core/i18n/TranslationDomain.php.
 *  2. KEYS NAME THE PLACE, NOT THE WORDS: `login.email.label`, never
 *     `enter_your_email`. Rewording copy must never require renaming a key —
 *     a rename orphans that string in every other language at once.
 *  3. THE ENGLISH TEXT IS THE FALLBACK, passed inline at the call site. The
 *     screen therefore reads normally in a diff, renders correctly before the
 *     bundle loads, and survives a key that was never seeded.
 *  4. SENTENCES STAY WHOLE, with `{placeholders}` for the variable parts —
 *     never assembled by concatenating fragments, whose order differs between
 *     languages.
 *
 * The strings themselves are seeded in database/migrations/091_seed_auth_translations.php.
 */

/**
 * SSO return markers the backend appends to /login?sso_error=… (see
 * SsoAuthHandler), mapped to their translation key and English fallback.
 *
 * Unknown reasons fall through to the generic failure, so a new backend reason
 * never surfaces a raw slug to a user. The map is keyed by the BACKEND's slug
 * and holds our key — the two namespaces stay separate deliberately, so
 * renaming a translation key never has to be coordinated with a backend release.
 *
 * The keys below reach `t()` through a variable (`t(entry.key, entry.fallback)`),
 * which no static scanner can read — so they are declared here, and the
 * extractor takes the catalogue from this block rather than pretending the scan
 * saw them. The declaration is what a translator gets, so the two must not
 * drift; `web/__tests__/login-sso-key-declaration.test.ts` fails if they do.
 *
 * @i18n-keys auth
 *   sso.error.disabled = Single sign-on is currently disabled for this instance.
 *   sso.error.providerUnavailable = That sign-in provider is unavailable right now. Please try again later.
 *   sso.error.unknownProvider = That sign-in provider is not available.
 *   sso.error.emailUnverified = Your email with that provider is not verified. Verify it and try again.
 *   sso.error.linkConflict = An account with that email already exists. Sign in with your password to link it.
 *   sso.error.noAccount = No account here matches that identity. Ask an administrator for an invite.
 *   sso.error.noMembership = Your account has no active workspace yet. Ask an administrator for access.
 *   sso.error.stateMismatch = Your sign-in session could not be verified. Please try again.
 *   sso.error.expired = Your sign-in attempt timed out. Please try again.
 *   sso.error.denied = Sign-in was cancelled.
 *   sso.error.failed = Sign-in failed. Please try again.
 */
export const SSO_ERROR_KEYS: Record<string, { key: string; fallback: string }> = {
  sso_disabled: {
    key: 'sso.error.disabled',
    fallback: 'Single sign-on is currently disabled for this instance.',
  },
  provider_unavailable: {
    key: 'sso.error.providerUnavailable',
    fallback: 'That sign-in provider is unavailable right now. Please try again later.',
  },
  unknown_provider: {
    key: 'sso.error.unknownProvider',
    fallback: 'That sign-in provider is not available.',
  },
  email_unverified: {
    key: 'sso.error.emailUnverified',
    fallback: 'Your email with that provider is not verified. Verify it and try again.',
  },
  link_conflict: {
    key: 'sso.error.linkConflict',
    fallback: 'An account with that email already exists. Sign in with your password to link it.',
  },
  no_account: {
    key: 'sso.error.noAccount',
    fallback: 'No account here matches that identity. Ask an administrator for an invite.',
  },
  no_membership: {
    key: 'sso.error.noMembership',
    fallback: 'Your account has no active workspace yet. Ask an administrator for access.',
  },
  state_mismatch: {
    key: 'sso.error.stateMismatch',
    fallback: 'Your sign-in session could not be verified. Please try again.',
  },
  expired: {
    key: 'sso.error.expired',
    fallback: 'Your sign-in attempt timed out. Please try again.',
  },
  denied: { key: 'sso.error.denied', fallback: 'Sign-in was cancelled.' },
  failed: { key: 'sso.error.failed', fallback: 'Sign-in failed. Please try again.' },
};

function ssoErrorMessage(t: TranslateFn, reason: string): string {
  const entry = SSO_ERROR_KEYS[reason] ?? SSO_ERROR_KEYS.failed;
  return t(entry.key, entry.fallback);
}

/**
 * Backup/recovery codes are issued by BackupCodesService in the exact form
 * XXXX-XXXX-XXXX: 12 uppercase alphanumeric characters (A-Z, 0-9) grouped in
 * threes by hyphens (14 chars total), and the backend validates the FULL string
 * via password_verify. The full length of an unhyphenated code is 12 chars; the
 * hyphenated, ready-to-submit length is 14.
 */
const BACKUP_CODE_DIGITS = 12;
const BACKUP_CODE_LENGTH = 14;

/**
 * Normalize free-form user input into the canonical XXXX-XXXX-XXXX backup-code
 * form so the value submitted to the backend matches the issued code exactly.
 * Accepts input pasted with or without hyphens (and lowercase) and never
 * truncates a complete code: strip to A-Z/0-9, cap at 12 characters, then
 * re-insert the group hyphens.
 */
function formatBackupCode(raw: string): string {
  const chars = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, BACKUP_CODE_DIGITS);
  const groups = chars.match(/.{1,4}/g) ?? [];
  return groups.join('-');
}

export default function LoginPage() {
  const router = useRouter();
  const { isAuthenticated, isLoading, refreshAuth } = useAuth();
  const { addToast } = useToast();
  const branding = useBranding();
  const t = useTranslation('auth');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; password?: string }>({});
  const [loginError, setLoginError] = useState<string | null>(null);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isMounted, setIsMounted] = useState(false);
  const [requires2fa, setRequires2fa] = useState(false);
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [twoFactorLoading, setTwoFactorLoading] = useState(false);
  const [twoFactorError, setTwoFactorError] = useState<string | null>(null);
  const [backupCodeMode, setBackupCodeMode] = useState(false);
  // Mandatory-2FA enrollment (WC-525): distinct from requires2fa above. The
  // backend returns this instead when the caller has NOT enrolled a device
  // and an admin-enforced policy's grace period has already expired — there
  // is no existing code to challenge, so it hands back a narrowly-scoped
  // enrollment_token (see TokenValidator::validateTwoFactorEnrollmentToken)
  // that lets TwoFactorSetupWizard call setup()/confirm() without a session.
  const [requiresEnrollment, setRequiresEnrollment] = useState(false);
  const [enrollmentToken, setEnrollmentToken] = useState<string | null>(null);
  // Multi-membership tenant selection (ADR 0005 §6): when login resolves to a
  // profile with 2+ active memberships, the backend returns
  // { requires_tenant_selection: true, memberships: [...] } WITHOUT minting a
  // session — the caller must pick a tenant (POST /api/v1/auth/select-tenant)
  // before a session is issued.
  const [pendingMemberships, setPendingMemberships] = useState<
    Array<{ tenant_id: number; tenant_name: string; role: string }> | null
  >(null);
  const [selectingTenant, setSelectingTenant] = useState(false);
  const emailInputRef = useRef<HTMLInputElement>(null);
  const twoFactorInputRef = useRef<HTMLInputElement>(null);
  const recoveryCodeInputRef = useRef<HTMLInputElement>(null);

  // Mark the component as mounted and move focus to the email field. The form
  // renders enabled on the server and the first client render (isMounted=false)
  // so SSR markup matches hydration; only afterwards does it reflect the live
  // auth/submit state. The flag flip is scheduled off the synchronous effect
  // tick (a microtask) rather than set directly in the effect body, which keeps
  // it clear of React's set-state-in-effect rule while preserving the original
  // "enabled until mounted" timing. Focusing the DOM is a plain side effect.
  useEffect(() => {
    emailInputRef.current?.focus();
    const flip = Promise.resolve().then(() => setIsMounted(true));
    void flip;
  }, []);

  // Redirect if already authenticated
  useEffect(() => {
    if (isMounted && isAuthenticated()) {
      router.push('/dashboard');
    }
  }, [isAuthenticated, router, isMounted]);

  // Surface the SSO return markers the backend appends on a hosted-login bounce
  // (…/login?sso_error=<reason> or ?sso=select), then strip them from the URL so
  // a refresh or back-navigation does not re-toast. Runs once on mount.
  useEffect(() => {
    const params = new URLSearchParams(window.location.search);
    const ssoError = params.get('sso_error');
    const ssoFlow = params.get('sso');
    if (!ssoError && ssoFlow !== 'select') {
      return;
    }
    if (ssoError) {
      addToast(ssoErrorMessage(t, ssoError), 'error');
    } else {
      addToast(
        t('sso.multipleWorkspaces', 'Your account has multiple workspaces — sign in to choose one.'),
        'info'
      );
    }
    params.delete('sso_error');
    params.delete('sso');
    const query = params.toString();
    window.history.replaceState(null, '', `${window.location.pathname}${query ? `?${query}` : ''}`);
  }, [addToast, t]);

  const validateFields = (): boolean => {
    const errors: { email?: string; password?: string } = {};

    if (!email.trim()) {
      errors.email = t('login.email.required', 'Email is required');
    }
    if (!password.trim()) {
      errors.password = t('login.password.required', 'Password is required');
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
    setLoginError(null);
    try {
      // Check for 2FA requirement first
      const response = await fetch('/api/v1/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on the auth POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ email, password }),
        credentials: 'include',
      });

      if (response.status === 202) {
        // try/catch, NOT `.json().catch()`: some 202 mocks in tests (and a
        // body-less real 202) have no `.json` method at all, which throws
        // SYNCHRONOUSLY when called — a `.catch()` chained onto that call
        // never runs since the call itself never returns a promise.
        let data: { requires_2fa_enrollment?: boolean; enrollment_token?: string } = {};
        try {
          data = await response.json();
        } catch {
          data = {};
        }
        if (data.requires_2fa_enrollment) {
          // Mandatory 2FA, never enrolled, grace period expired: there is no
          // code to enter — the user must set up an authenticator now.
          setRequiresEnrollment(true);
          setEnrollmentToken(typeof data.enrollment_token === 'string' ? data.enrollment_token : null);
          setEmail('');
          setPassword('');
          setFieldErrors({});
        } else {
          // 2FA required
          setRequires2fa(true);
          setEmail('');
          setPassword('');
          setFieldErrors({});
          // Focus on 2FA input after render
          setTimeout(() => {
            twoFactorInputRef.current?.focus();
          }, 0);
        }
      } else if (response.ok) {
        const data = await response.json().catch(() => ({}));
        if (data.requires_tenant_selection && Array.isArray(data.memberships)) {
          // Multi-membership profile: no session minted yet — prompt the user
          // to choose which tenant to sign in to before completing login.
          setPendingMemberships(data.memberships);
          setPassword('');
          setFieldErrors({});
        } else {
          // Single-membership: session cookie already set — redirect in.
          await refreshAuth();
          router.push('/dashboard');
        }
      } else {
        const errorData = await response.json().catch(() => ({}));
        const message =
          response.status === 401
            ? t('login.error.invalidCredentials', 'Invalid credentials')
            : errorData.message || t('login.error.generic', 'Login failed');
        // Keep the inline Alert (WC-98) and also surface the failure as a
        // toast, including the HTTP status code for context.
        setLoginError(message);
        addToast(
          t('login.error.withStatus', 'Login failed ({status}): {message}', {
            status: response.status,
            message,
          }),
          'error'
        );
      }
    } catch (err) {
      // Network/transport error — no HTTP status is available.
      const message = err instanceof Error ? err.message : t('login.error.generic', 'Login failed');
      setLoginError(message);
      addToast(t('login.error.transport', 'Login failed: {message}', { message }), 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleSelectTenant = async (tenantId: number) => {
    setSelectingTenant(true);
    setLoginError(null);
    try {
      const response = await fetch('/api/v1/auth/select-tenant', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on the auth POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ tenant_id: tenantId }),
        credentials: 'include',
      });

      if (response.ok) {
        // Session issued for the chosen tenant — redirect in.
        await refreshAuth();
        router.push('/dashboard');
      } else {
        const errorData = await response.json().catch(() => ({}));
        const message =
          response.status === 403
            ? t('workspace.error.notMember', 'You are not a member of that workspace.')
            : errorData.message || t('workspace.error.generic', 'Could not select workspace');
        setLoginError(message);
        addToast(
          t('workspace.error.withStatus', 'Workspace selection failed ({status}): {message}', {
            status: response.status,
            message,
          }),
          'error'
        );
      }
    } catch (err) {
      const message =
        err instanceof Error
          ? err.message
          : t('workspace.error.generic', 'Could not select workspace');
      setLoginError(message);
      addToast(
        t('workspace.error.transport', 'Workspace selection failed: {message}', { message }),
        'error'
      );
    } finally {
      setSelectingTenant(false);
    }
  };

  const handleTwoFactorSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();

    // Validate code length based on mode
    if (backupCodeMode) {
      if (twoFactorCode.length !== BACKUP_CODE_LENGTH) {
        setTwoFactorError(
          t('recovery.error.format', 'Recovery code must be in the format XXXX-XXXX-XXXX')
        );
        return;
      }
    } else {
      if (twoFactorCode.length !== 6) {
        setTwoFactorError(t('twoFactor.error.length', 'Code must be exactly 6 digits'));
        return;
      }
    }

    setTwoFactorLoading(true);
    setTwoFactorError(null);

    try {
      const response = await fetch('/api/v1/login/2fa', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          // CSRF defense (WC-160): required on the auth POSTs.
          'X-Requested-With': 'XMLHttpRequest',
        },
        body: JSON.stringify({ code: twoFactorCode }),
        credentials: 'include',
      });

      if (response.ok) {
        // 2FA successful - refresh auth state and redirect
        await refreshAuth();
        router.push('/dashboard');
      } else if (response.status === 401) {
        const errorMsg = backupCodeMode
          ? t('recovery.error.invalid', 'Invalid recovery code. Please try again.')
          : t('twoFactor.error.invalid', 'Invalid authenticator code. Please try again.');
        setTwoFactorError(errorMsg);
        addToast(
          t('twoFactor.error.withStatus', 'Verification failed ({status}): {message}', {
            status: 401,
            message: errorMsg,
          }),
          'error'
        );
        setTwoFactorCode('');
        twoFactorInputRef.current?.focus();
      } else {
        const errorData = await response.json().catch(() => ({}));
        const errorMsg =
          errorData.message || t('twoFactor.error.generic', 'Verification failed. Please try again.');
        setTwoFactorError(errorMsg);
        addToast(
          t('twoFactor.error.withStatus', 'Verification failed ({status}): {message}', {
            status: response.status,
            message: errorMsg,
          }),
          'error'
        );
        setTwoFactorCode('');
      }
    } catch {
      // Network/transport error — no HTTP status is available.
      const errorMsg = t('twoFactor.error.transport', 'An error occurred. Please try again.');
      setTwoFactorError(errorMsg);
      addToast(errorMsg, 'error');
      setTwoFactorCode('');
    } finally {
      setTwoFactorLoading(false);
    }
  };

  const handleEnrollmentComplete = (codes: string[]) => {
    // Auto-download backup codes, same as the in-settings wizard, since this
    // is the only time they're ever shown.
    const text = codes.join('\n');
    const element = document.createElement('a');
    element.setAttribute('href', 'data:text/plain;charset=utf-8,' + encodeURIComponent(text));
    element.setAttribute('download', 'whity-backup-codes.txt');
    element.click();

    // confirm() enables 2FA but does not mint a session (WC-525) — the user
    // must sign in again, this time completing the normal requires_2fa
    // challenge with the device they just enrolled.
    addToast(
      t(
        'twoFactor.enrolled',
        'Two-factor authentication is now set up. Please sign in again with your authenticator code.'
      ),
      'success'
    );
    setRequiresEnrollment(false);
    setEnrollmentToken(null);
    setTimeout(() => emailInputRef.current?.focus(), 0);
  };

  const handleEnrollmentCancel = () => {
    // Backing out doesn't waive the policy — the next login attempt will hit
    // the same requires_2fa_enrollment response. Just return to the login form.
    setRequiresEnrollment(false);
    setEnrollmentToken(null);
    setTimeout(() => emailInputRef.current?.focus(), 0);
  };

  // On server, always render as enabled to match client hydration
  // After mount, use actual state
  const isFormDisabled = isMounted ? (isSubmitting || isLoading) : false;
  const buttonText = isFormDisabled
    ? t('login.submit.pending', 'Signing in...')
    : t('login.submit', 'Sign in');

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          {branding.logoWideUrl ? (
            <Image src={branding.logoWideUrl} alt={branding.siteName} width={220} height={40} className="h-10 w-auto max-w-[220px] object-contain mx-auto mb-2" />
          ) : null}
          {/* One translatable sentence with a hole in it — never
              `t('welcome') + siteName`, whose word order is English-only. */}
          <CardTitle className="text-2xl">
            {t('login.welcome', 'Welcome to {site}', { site: branding.siteName })}
          </CardTitle>
          <CardDescription>
            {requiresEnrollment
              ? t('login.subtitle.enrollment', 'Set up two-factor authentication to continue')
              : requires2fa
                ? t('login.subtitle.twoFactor', 'Enter your authenticator code')
                : pendingMemberships
                  ? t('login.subtitle.workspace', 'Choose a workspace to continue')
                  : t('login.subtitle', 'Sign in to your account to continue')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {/* MANDATORY 2FA ENROLLMENT (WC-525): the account has no device
              enrolled and the grace period has already expired, so login
              cannot be completed without setting one up first. */}
          {requiresEnrollment && enrollmentToken && (
            <TwoFactorSetupWizard
              bearerToken={enrollmentToken}
              onComplete={handleEnrollmentComplete}
              onCancel={handleEnrollmentCancel}
            />
          )}

          {/* LOGIN FORM */}
          {!requiresEnrollment && !requires2fa && !pendingMemberships && (
            <form onSubmit={handleSubmit} className="space-y-4">
              {/* Error Alert */}
              {loginError && (
                <Alert variant="destructive">
                  <AlertDescription>{loginError}</AlertDescription>
                </Alert>
              )}

              {/* Email Field */}
              <div className="space-y-2">
                <label htmlFor="email" className="text-sm font-medium">
                  {t('login.email.label', 'Email')}
                </label>
                <Input
                  ref={emailInputRef}
                  id="email"
                  type="email"
                  placeholder={t('login.email.placeholder', 'Enter your email')}
                  value={email}
                  onChange={(e) => {
                    setEmail(e.target.value);
                    if (fieldErrors.email) {
                      setFieldErrors({ ...fieldErrors, email: undefined });
                    }
                    if (loginError) {
                      setLoginError(null);
                    }
                  }}
                  disabled={isFormDisabled}
                  className={fieldErrors.email ? 'border-destructive' : ''}
                />
                {fieldErrors.email && (
                  <p className="text-xs text-destructive">{fieldErrors.email}</p>
                )}
              </div>

              {/* Password Field */}
              <div className="space-y-2">
                <div className="flex items-center justify-between">
                  <label htmlFor="password" className="text-sm font-medium">
                    {t('login.password.label', 'Password')}
                  </label>
                  {/* WC-password-reset-2fa-recovery: self-service reset entry point. */}
                  <Link href="/forgot-password" className="text-xs font-medium text-primary hover:underline">
                    {t('login.password.forgot', 'Forgot password?')}
                  </Link>
                </div>
                <Input
                  id="password"
                  type="password"
                  placeholder={t('login.password.placeholder', 'Enter your password')}
                  value={password}
                  onChange={(e) => {
                    setPassword(e.target.value);
                    if (fieldErrors.password) {
                      setFieldErrors({ ...fieldErrors, password: undefined });
                    }
                    if (loginError) {
                      setLoginError(null);
                    }
                  }}
                  disabled={isFormDisabled}
                  className={fieldErrors.password ? 'border-destructive' : ''}
                />
                {fieldErrors.password && (
                  <p className="text-xs text-destructive">{fieldErrors.password}</p>
                )}
              </div>

              {/* Submit Button */}
              <Button
                type="submit"
                className="w-full"
                disabled={isFormDisabled}
              >
                {buttonText}
              </Button>

              {/* WC-235: self-service registration entry point. The prompt and
                  the link text are SEPARATE keys because they are separate
                  elements — not two halves of one sentence spliced together. */}
              <p className="text-sm text-center text-muted-foreground">
                {t('login.register.prompt', 'New here?')}{' '}
                <Link href="/register" className="font-medium text-primary hover:underline">
                  {t('login.register.link', 'Create a workspace')}
                </Link>
              </p>

              {/* WC-password-reset-2fa-recovery: a user who ALSO lost their 2FA
                  device (so "Forgot password?" alone can't get them back in)
                  needs this reachable from login too. */}
              <p className="text-sm text-center text-muted-foreground">
                {t('login.recovery.prompt', 'Lost your authenticator too?')}{' '}
                <Link href="/account-recovery" className="font-medium text-primary hover:underline">
                  {t('login.recovery.link', 'Recover your account')}
                </Link>
              </p>

              {/* Federated sign-in: one button per enabled provider (renders
                  nothing when none are configured). */}
              <SsoLoginButtons />
            </form>
          )}

          {/* TENANT SELECTION (multi-membership) */}
          {pendingMemberships && !requires2fa && (
            <div className="space-y-4">
              {loginError && (
                <Alert variant="destructive">
                  <AlertDescription>{loginError}</AlertDescription>
                </Alert>
              )}
              <p className="text-sm text-muted-foreground text-center">
                {t(
                  'workspace.prompt',
                  'Your account has access to multiple workspaces. Choose one to continue.'
                )}
              </p>
              <div className="space-y-2">
                {pendingMemberships.map((m) => (
                  <Button
                    key={m.tenant_id}
                    type="button"
                    variant="outline"
                    className="w-full justify-between"
                    disabled={selectingTenant}
                    onClick={() => handleSelectTenant(m.tenant_id)}
                  >
                    <span>{m.tenant_name}</span>
                    <span className="text-xs text-muted-foreground capitalize">{m.role}</span>
                  </Button>
                ))}
              </div>
              <Button
                type="button"
                variant="ghost"
                className="w-full"
                disabled={selectingTenant}
                onClick={() => {
                  setPendingMemberships(null);
                  setEmail('');
                  setPassword('');
                  setLoginError(null);
                  setTimeout(() => emailInputRef.current?.focus(), 0);
                }}
              >
                {t('workspace.back', 'Back to login')}
              </Button>
            </div>
          )}

          {/* 2FA FORM */}
          {requires2fa && (
            <>
              {!backupCodeMode && (
                <form onSubmit={handleTwoFactorSubmit} className="space-y-4">
                  {/* 2FA Error Alert */}
                  {twoFactorError && (
                    <Alert variant="destructive">
                      <AlertDescription>{twoFactorError}</AlertDescription>
                    </Alert>
                  )}

                  {/* 2FA Instructions */}
                  <p className="text-sm text-muted-foreground text-center">
                    {t(
                      'twoFactor.instructions',
                      'Enter the 6-digit code from your authenticator app or a backup code'
                    )}
                  </p>

                  {/* 2FA Code Input */}
                  <div className="space-y-2">
                    <label htmlFor="twoFactorCode" className="text-sm font-medium">
                      {t('twoFactor.code.label', 'Authenticator Code')}
                    </label>
                    <Input
                      ref={twoFactorInputRef}
                      id="twoFactorCode"
                      type="text"
                      // Format masks, not prose: the issued codes are literally
                      // six digits / A-Z0-9 groups, identical in every language.
                      // Deliberately NOT translated.
                      placeholder="000000"
                      value={twoFactorCode}
                      onChange={(e) => {
                        const cleaned = e.target.value.replace(/\D/g, '').slice(0, 6);
                        setTwoFactorCode(cleaned);
                        if (twoFactorError) {
                          setTwoFactorError(null);
                        }
                      }}
                      disabled={twoFactorLoading}
                      maxLength={6}
                      inputMode="numeric"
                      className="text-center text-2xl tracking-widest font-mono"
                    />
                  </div>

                  {/* Submit Button */}
                  <Button
                    type="submit"
                    className="w-full bg-primary hover:bg-primary/90"
                    disabled={twoFactorCode.length !== 6 || twoFactorLoading}
                  >
                    {twoFactorLoading
                      ? t('twoFactor.submit.pending', 'Verifying...')
                      : t('twoFactor.submit', 'Verify')}
                  </Button>

                  {/* Back Button */}
                  <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    onClick={() => {
                      setRequires2fa(false);
                      setTwoFactorCode('');
                      setTwoFactorError(null);
                      emailInputRef.current?.focus();
                    }}
                    disabled={twoFactorLoading}
                  >
                    {t('twoFactor.back', 'Back to Login')}
                  </Button>
                </form>
              )}

              {/* RECOVERY CODE FORM */}
              {backupCodeMode && (
                <form onSubmit={handleTwoFactorSubmit} className="space-y-4">
                  {/* 2FA Error Alert */}
                  {twoFactorError && (
                    <Alert variant="destructive">
                      <AlertDescription>{twoFactorError}</AlertDescription>
                    </Alert>
                  )}

                  {/* Recovery Instructions Box */}
                  <div className="bg-muted/50 border border-border rounded-md p-3">
                    {/* The emphasised term is its own key because it is its own
                        ELEMENT (a <strong>), not because the sentence was split
                        for convenience — the remainder stays one unit. */}
                    <p className="text-sm text-muted-foreground">
                      <strong>{t('recovery.instructions.term', 'Recovery codes')}</strong>{' '}
                      {t(
                        'recovery.instructions',
                        'are the XXXX-XXXX-XXXX codes you saved when setting up two-factor authentication. Enter one exactly as it was issued.'
                      )}
                    </p>
                  </div>

                  {/* Recovery Code Input */}
                  <div className="space-y-2">
                    <label htmlFor="recoveryCode" className="text-sm font-medium">
                      {t('recovery.code.label', 'Recovery Code')}
                    </label>
                    <Input
                      ref={recoveryCodeInputRef}
                      id="recoveryCode"
                      type="text"
                      placeholder="XXXX-XXXX-XXXX"
                      value={twoFactorCode}
                      onChange={(e) => {
                        setTwoFactorCode(formatBackupCode(e.target.value));
                        if (twoFactorError) {
                          setTwoFactorError(null);
                        }
                      }}
                      disabled={twoFactorLoading}
                      maxLength={BACKUP_CODE_LENGTH}
                      className="text-center text-lg tracking-wider font-mono"
                    />
                    <p className="text-xs text-muted-foreground">
                      {t('recovery.code.hint', 'Format: XXXX-XXXX-XXXX (e.g., A1B2-C3D4-E5F6)')}
                    </p>
                  </div>

                  {/* Verify Recovery Button */}
                  <Button
                    type="submit"
                    className="w-full bg-primary hover:bg-primary/90 text-primary-foreground"
                    disabled={twoFactorCode.length !== BACKUP_CODE_LENGTH || twoFactorLoading}
                  >
                    {twoFactorLoading
                      ? t('twoFactor.submit.pending', 'Verifying...')
                      : t('recovery.submit', 'Verify Recovery Code')}
                  </Button>

                  {/* Back to Authenticator Button */}
                  <Button
                    type="button"
                    variant="outline"
                    className="w-full"
                    onClick={() => {
                      setBackupCodeMode(false);
                      setTwoFactorCode('');
                      setTwoFactorError(null);
                      setTimeout(() => twoFactorInputRef.current?.focus(), 0);
                    }}
                    disabled={twoFactorLoading}
                  >
                    {t('recovery.back', 'Back to Authenticator')}
                  </Button>
                </form>
              )}

              {/* Recovery Link */}
              <p className="text-center text-sm mt-6">
                <button
                  type="button"
                  onClick={() => {
                    setBackupCodeMode(!backupCodeMode);
                    setTwoFactorCode('');
                    setTwoFactorError(null);

                    if (!backupCodeMode) {
                      // Entering recovery mode - focus recovery input
                      setTimeout(() => recoveryCodeInputRef.current?.focus(), 0);
                    } else {
                      // Returning to authenticator - focus authenticator input
                      setTimeout(() => twoFactorInputRef.current?.focus(), 0);
                    }
                  }}
                  className="text-primary hover:text-primary/80 underline"
                >
                  {t(
                    'recovery.switch',
                    "Can't access your authenticator? Use a recovery code instead"
                  )}
                </button>
              </p>
            </>
          )}

        </CardContent>
      </Card>
    </div>
  );
}
