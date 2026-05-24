'use client';

import { useEffect, useState, useRef } from 'react';
import { useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Alert, AlertDescription } from '@/components/ui/alert';

export default function LoginPage() {
  const router = useRouter();
  const { isAuthenticated, login, isLoading, error, refreshAuth } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; password?: string }>({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isMounted, setIsMounted] = useState(false);
  const [requires2fa, setRequires2fa] = useState(false);
  const [twoFactorCode, setTwoFactorCode] = useState('');
  const [twoFactorLoading, setTwoFactorLoading] = useState(false);
  const [twoFactorError, setTwoFactorError] = useState<string | null>(null);
  const emailInputRef = useRef<HTMLInputElement>(null);
  const twoFactorInputRef = useRef<HTMLInputElement>(null);

  // Handle hydration and mount
  useEffect(() => {
    setIsMounted(true);
    emailInputRef.current?.focus();
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
    try {
      // Check for 2FA requirement first
      const response = await fetch('/api/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
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
        // Login successful - refresh auth state and redirect
        await refreshAuth();
        router.push('/dashboard');
      } else {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.message || 'Login failed');
      }
    } catch (err) {
      // Error is handled by displaying below
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleTwoFactorSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
    e.preventDefault();

    if (twoFactorCode.length !== 6) {
      setTwoFactorError('Code must be exactly 6 digits');
      return;
    }

    setTwoFactorLoading(true);
    setTwoFactorError(null);

    try {
      const response = await fetch('/api/login/2fa', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({ code: twoFactorCode }),
        credentials: 'include',
      });

      if (response.ok) {
        // 2FA successful - refresh auth state and redirect
        await refreshAuth();
        router.push('/dashboard');
      } else if (response.status === 401) {
        setTwoFactorError('Invalid authenticator code. Please try again.');
        setTwoFactorCode('');
        twoFactorInputRef.current?.focus();
      } else {
        const errorData = await response.json().catch(() => ({}));
        setTwoFactorError(errorData.message || 'Verification failed. Please try again.');
        setTwoFactorCode('');
      }
    } catch (err) {
      setTwoFactorError('An error occurred. Please try again.');
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
          <CardTitle className="text-2xl">Welcome to Whity</CardTitle>
          <CardDescription>
            {requires2fa ? 'Enter your authenticator code' : 'Sign in to your account to continue'}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {/* LOGIN FORM */}
          {!requires2fa && (
            <form onSubmit={handleSubmit} className="space-y-4">
              {/* Error Alert */}
              {error && (
                <Alert variant="destructive">
                  <AlertDescription>{error}</AlertDescription>
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
                  }}
                  disabled={isFormDisabled}
                  className={fieldErrors.email ? 'border-red-500' : ''}
                />
                {fieldErrors.email && (
                  <p className="text-xs text-red-500">{fieldErrors.email}</p>
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
                  }}
                  disabled={isFormDisabled}
                  className={fieldErrors.password ? 'border-red-500' : ''}
                />
                {fieldErrors.password && (
                  <p className="text-xs text-red-500">{fieldErrors.password}</p>
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

          {/* 2FA FORM */}
          {requires2fa && (
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
                className="w-full bg-blue-600 hover:bg-blue-700"
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

          {/* Demo Credentials - only show on login form */}
          {!requires2fa && (
            <div className="mt-6 pt-6 border-t text-center text-sm text-muted-foreground">
              <p className="font-medium mb-2">Demo Credentials</p>
              <div className="space-y-1 text-xs">
                <p>Email: <code className="bg-muted px-2 py-1 rounded">admin@whity.local</code></p>
                <p>Password: <code className="bg-muted px-2 py-1 rounded">password</code></p>
              </div>
            </div>
          )}
        </CardContent>
      </Card>
    </div>
  );
}
