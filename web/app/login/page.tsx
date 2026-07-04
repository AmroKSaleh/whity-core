'use client';

import { useEffect, useState, useRef } from 'react';
import Image from 'next/image';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { useBranding } from '@/lib/branding-context';
import { Button } from '@whity/ui/button';
import { Input } from '@whity/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@whity/ui/card';
import { Alert, AlertDescription } from '@whity/ui/alert';

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

  const validateFields = (): boolean => {
    const errors: { email?: string; password?: string } = {};

    if (!email.trim()) {
      errors.email = 'Email is required';
    }
    if (!password.trim()) {
      errors.password = 'Password is required';
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
        // 2FA required
        setRequires2fa(true);
        setEmail('');
        setPassword('');
        setFieldErrors({});
        // Focus on 2FA input after render
        setTimeout(() => {
          twoFactorInputRef.current?.focus();
        }, 0);
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
            ? 'Invalid credentials'
            : errorData.message || 'Login failed';
        // Keep the inline Alert (WC-98) and also surface the failure as a
        // toast, including the HTTP status code for context.
        setLoginError(message);
        addToast(`Login failed (${response.status}): ${message}`, 'error');
      }
    } catch (err) {
      // Network/transport error — no HTTP status is available.
      const message = err instanceof Error ? err.message : 'Login failed';
      setLoginError(message);
      addToast(`Login failed: ${message}`, 'error');
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
            ? 'You are not a member of that workspace.'
            : errorData.message || 'Could not select workspace';
        setLoginError(message);
        addToast(`Workspace selection failed (${response.status}): ${message}`, 'error');
      }
    } catch (err) {
      const message = err instanceof Error ? err.message : 'Could not select workspace';
      setLoginError(message);
      addToast(`Workspace selection failed: ${message}`, 'error');
    } finally {
      setSelectingTenant(false);
    }
  };

  const handleTwoFactorSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();

    // Validate code length based on mode
    if (backupCodeMode) {
      if (twoFactorCode.length !== BACKUP_CODE_LENGTH) {
        setTwoFactorError('Recovery code must be in the format XXXX-XXXX-XXXX');
        return;
      }
    } else {
      if (twoFactorCode.length !== 6) {
        setTwoFactorError('Code must be exactly 6 digits');
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
        const errorMsg = backupCodeMode ? 'Invalid recovery code. Please try again.' : 'Invalid authenticator code. Please try again.';
        setTwoFactorError(errorMsg);
        addToast(`Verification failed (401): ${errorMsg}`, 'error');
        setTwoFactorCode('');
        twoFactorInputRef.current?.focus();
      } else {
        const errorData = await response.json().catch(() => ({}));
        const errorMsg = errorData.message || 'Verification failed. Please try again.';
        setTwoFactorError(errorMsg);
        addToast(`Verification failed (${response.status}): ${errorMsg}`, 'error');
        setTwoFactorCode('');
      }
    } catch {
      // Network/transport error — no HTTP status is available.
      const errorMsg = 'An error occurred. Please try again.';
      setTwoFactorError(errorMsg);
      addToast(errorMsg, 'error');
      setTwoFactorCode('');
    } finally {
      setTwoFactorLoading(false);
    }
  };

  // On server, always render as enabled to match client hydration
  // After mount, use actual state
  const isFormDisabled = isMounted ? (isSubmitting || isLoading) : false;
  const buttonText = isFormDisabled ? 'Signing in...' : 'Sign in';

  return (
    <div className="min-h-screen flex items-center justify-center bg-gradient-to-br from-background to-muted p-4">
      <Card className="w-full max-w-md">
        <CardHeader className="text-center">
          {branding.logoWideUrl ? (
            <Image src={branding.logoWideUrl} alt={branding.siteName} width={220} height={40} className="h-10 w-auto max-w-[220px] object-contain mx-auto mb-2" />
          ) : null}
          <CardTitle className="text-2xl">{`Welcome to ${branding.siteName}`}</CardTitle>
          <CardDescription>
            {requires2fa
              ? 'Enter your authenticator code'
              : pendingMemberships
                ? 'Choose a workspace to continue'
                : 'Sign in to your account to continue'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {/* LOGIN FORM */}
          {!requires2fa && !pendingMemberships && (
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
                  Email
                </label>
                <Input
                  ref={emailInputRef}
                  id="email"
                  type="email"
                  placeholder="Enter your email"
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
                <label htmlFor="password" className="text-sm font-medium">
                  Password
                </label>
                <Input
                  id="password"
                  type="password"
                  placeholder="Enter your password"
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
                Your account has access to multiple workspaces. Choose one to continue.
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
                Back to login
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
                    Enter the 6-digit code from your authenticator app or a backup code
                  </p>

                  {/* 2FA Code Input */}
                  <div className="space-y-2">
                    <label htmlFor="twoFactorCode" className="text-sm font-medium">
                      Authenticator Code
                    </label>
                    <Input
                      ref={twoFactorInputRef}
                      id="twoFactorCode"
                      type="text"
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
                    {twoFactorLoading ? 'Verifying...' : 'Verify'}
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
                    Back to Login
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
                    <p className="text-sm text-muted-foreground">
                      <strong>Recovery codes</strong> are the XXXX-XXXX-XXXX codes you saved when setting up two-factor authentication. Enter one exactly as it was issued.
                    </p>
                  </div>

                  {/* Recovery Code Input */}
                  <div className="space-y-2">
                    <label htmlFor="recoveryCode" className="text-sm font-medium">
                      Recovery Code
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
                    <p className="text-xs text-muted-foreground">Format: XXXX-XXXX-XXXX (e.g., A1B2-C3D4-E5F6)</p>
                  </div>

                  {/* Verify Recovery Button */}
                  <Button
                    type="submit"
                    className="w-full bg-primary hover:bg-primary/90 text-primary-foreground"
                    disabled={twoFactorCode.length !== BACKUP_CODE_LENGTH || twoFactorLoading}
                  >
                    {twoFactorLoading ? 'Verifying...' : 'Verify Recovery Code'}
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
                    Back to Authenticator
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
                  Can&apos;t access your authenticator? Use a recovery code instead
                </button>
              </p>
            </>
          )}

        </CardContent>
      </Card>
    </div>
  );
}
