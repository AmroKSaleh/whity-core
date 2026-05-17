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
  const { isAuthenticated, login, isLoading, error } = useAuth();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [fieldErrors, setFieldErrors] = useState<{ email?: string; password?: string }>({});
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [isMounted, setIsMounted] = useState(false);
  const emailInputRef = useRef<HTMLInputElement>(null);

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
      await login(email, password);
      // Success - redirect happens via auth context/router
      router.push('/dashboard');
    } catch (err) {
      // Error is handled by auth context and displayed below
    } finally {
      setIsSubmitting(false);
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
          <CardDescription>Sign in to your account to continue</CardDescription>
        </CardHeader>
        <CardContent>
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

          {/* Demo Credentials */}
          <div className="mt-6 pt-6 border-t text-center text-sm text-muted-foreground">
            <p className="font-medium mb-2">Demo Credentials</p>
            <div className="space-y-1 text-xs">
              <p>Email: <code className="bg-muted px-2 py-1 rounded">admin@whity.local</code></p>
              <p>Password: <code className="bg-muted px-2 py-1 rounded">password</code></p>
            </div>
          </div>
        </CardContent>
      </Card>
    </div>
  );
}
