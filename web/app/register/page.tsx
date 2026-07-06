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
      errors.email = 'Email is required';
    }
    if (!password) {
      errors.password = 'Password is required';
    } else if (password.length < PASSWORD_MIN_LENGTH) {
      errors.password = `Password must be at least ${PASSWORD_MIN_LENGTH} characters`;
    }
    if (!tenantName.trim()) {
      errors.tenantName = 'Workspace name is required';
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
          addToast('Welcome! Your workspace is ready.', 'success');
          router.push('/dashboard');
        } else {
          // Account exists but auto-login did not complete (unexpected for a
          // fresh single-membership owner) — send them to the login page.
          addToast('Account created. Please sign in to continue.', 'success');
          router.push('/login');
        }
        return;
      }

      const errorData = await response.json().catch(() => ({}));
      const message =
        response.status === 409
          ? (errorData.error as string | undefined) ?? 'That email or workspace name is already taken'
          : response.status === 422
            ? (errorData.error as string | undefined) ?? 'Please check the details and try again'
            : (errorData.error as string | undefined) ?? 'Registration failed';
      setRegisterError(message);
      addToast(`Registration failed (${response.status}): ${message}`, 'error');
    } catch {
      const message = 'Unable to reach the server. Please try again.';
      setRegisterError(message);
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const isFormDisabled = isMounted ? (isSubmitting || isLoading) : false;
  const buttonText = isFormDisabled ? 'Creating your workspace…' : 'Create workspace';

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
          <CardTitle className="text-2xl">{`Create your ${branding.siteName} workspace`}</CardTitle>
          <CardDescription>Set up a new workspace and your owner account</CardDescription>
        </CardHeader>
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
                Workspace name
              </label>
              <Input
                id="tenantName"
                type="text"
                placeholder="Acme Inc"
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
                Email
              </label>
              <Input
                ref={emailInputRef}
                id="email"
                type="email"
                placeholder="you@example.com"
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
              <label htmlFor="displayName" className="text-sm font-medium">
                Your name <span className="text-muted-foreground">(optional)</span>
              </label>
              <Input
                id="displayName"
                type="text"
                placeholder="Jane Doe"
                value={displayName}
                onChange={(e) => setDisplayName(e.target.value)}
                disabled={isFormDisabled}
              />
            </div>

            {/* Password */}
            <div className="space-y-2">
              <label htmlFor="password" className="text-sm font-medium">
                Password
              </label>
              <Input
                id="password"
                type="password"
                placeholder={`At least ${PASSWORD_MIN_LENGTH} characters`}
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

            <p className="text-sm text-center text-muted-foreground">
              Already have an account?{' '}
              <Link href="/login" className="font-medium text-primary hover:underline">
                Sign in
              </Link>
            </p>
          </form>
        </CardContent>
      </Card>
    </div>
  );
}
