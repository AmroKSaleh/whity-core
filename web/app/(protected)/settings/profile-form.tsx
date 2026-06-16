'use client';

import { useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
} from '@/components/ui/form';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { PasswordStrengthIndicator } from '@/components/PasswordStrengthIndicator';

/**
 * Self-service profile editor (WC-64).
 *
 * Backed by PATCH /api/me, which updates ONLY the currently authenticated user.
 * Two fields are editable here:
 *  - email: validated for format and (server-side) for per-tenant uniqueness.
 *  - password: optional; when set it requires confirmation and a minimum length,
 *    and the backend additionally verifies the current password.
 *
 * The current password is required to save ANY change (the backend rejects an
 * update without it). The display name is derived from the email local-part —
 * there is no `users.name` column — so it is shown read-only here and flagged as
 * a follow-up rather than edited.
 *
 * Validation mirrors the existing admin modals (zod + react-hook-form). On
 * success a toast is shown and the auth context is refreshed so the new email
 * propagates immediately (the backend re-issues the auth cookies).
 */
const MIN_PASSWORD_LENGTH = 8;

const profileSchema = z
  .object({
    email: z.string().email('Invalid email address'),
    currentPassword: z.string().min(1, 'Current password is required'),
    newPassword: z
      .string()
      .min(MIN_PASSWORD_LENGTH, `Password must be at least ${MIN_PASSWORD_LENGTH} characters`)
      .optional()
      .or(z.literal('')),
    confirmPassword: z.string().optional().or(z.literal('')),
  })
  .refine(
    (data) => (data.newPassword ?? '') === (data.confirmPassword ?? ''),
    {
      message: 'Passwords do not match',
      path: ['confirmPassword'],
    }
  );

type ProfileFormData = z.infer<typeof profileSchema>;

interface ProfilePayload {
  email: string;
  current_password: string;
  password?: string;
}

export function ProfileForm() {
  const { user, apiClient, refreshAuth } = useAuth();
  const { addToast } = useToast();
  const [isSubmitting, setIsSubmitting] = useState(false);

  const currentEmail = user?.email ?? '';
  // The display name is derived from the email local-part (no users.name column).
  const derivedName = currentEmail.includes('@')
    ? currentEmail.slice(0, currentEmail.indexOf('@'))
    : currentEmail;

  const form = useForm<ProfileFormData>({
    resolver: zodResolver(profileSchema),
    defaultValues: {
      email: currentEmail,
      currentPassword: '',
      newPassword: '',
      confirmPassword: '',
    },
  });

  // Keep the email field in sync once the auth context resolves /api/me.
  // `form` is stable from react-hook-form, so listing it does not re-run the
  // sync on every render — only a changed email triggers a reset.
  useEffect(() => {
    if (currentEmail) {
      form.reset({
        email: currentEmail,
        currentPassword: '',
        newPassword: '',
        confirmPassword: '',
      });
    }
  }, [currentEmail, form]);

  const onSubmit = async (data: ProfileFormData) => {
    const emailChanged = data.email !== currentEmail;
    const passwordChanged = (data.newPassword ?? '') !== '';

    if (!emailChanged && !passwordChanged) {
      addToast('Nothing to update', 'info');
      return;
    }

    try {
      setIsSubmitting(true);

      const payload: ProfilePayload = {
        email: data.email,
        current_password: data.currentPassword,
      };
      if (passwordChanged) {
        payload.password = data.newPassword;
      }

      const response = await apiClient('/api/v1/me', {
        method: 'PATCH',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
      });

      if (!response.ok) {
        const errorData = (await response.json().catch(() => ({}))) as {
          error?: string;
          message?: string;
        };
        throw new Error(errorData.error || errorData.message || 'Failed to update profile');
      }

      // Confirm the change actually persisted before claiming success: the
      // backend returns the updated user, so assert the email it reports back
      // matches what we asked for rather than trusting a bare 200.
      const result = (await response.json().catch(() => null)) as
        | { user?: { email?: string } }
        | null;
      const persistedEmail = result?.user?.email;
      if (persistedEmail !== undefined && persistedEmail !== data.email) {
        throw new Error('Profile update did not persist the new email');
      }

      addToast('Profile updated successfully', 'success');

      // Refresh the auth context so the new email shows immediately; the backend
      // re-issued the auth cookies, so /api/me reflects the change.
      await refreshAuth();

      // Clear the credential fields; keep the (possibly new) email populated.
      form.reset({
        email: data.email,
        currentPassword: '',
        newPassword: '',
        confirmPassword: '',
      });
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to update profile';
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-6">
        <div className="space-y-2">
          <label
            htmlFor="profile-name"
            className="text-sm font-medium leading-none"
          >
            Display Name
          </label>
          <Input id="profile-name" value={derivedName} disabled />
          <p className="text-xs text-muted-foreground">
            Name is derived from your email and cannot be changed yet.
          </p>
        </div>

        <FormField
          control={form.control}
          name="email"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Email Address</FormLabel>
              <FormControl>
                <Input type="email" placeholder="you@example.com" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="newPassword"
          render={({ field }) => (
            <FormItem>
              <FormLabel>New Password</FormLabel>
              <FormControl>
                <Input
                  type="password"
                  placeholder="••••••••"
                  autoComplete="new-password"
                  {...field}
                />
              </FormControl>
              <PasswordStrengthIndicator password={field.value ?? ''} />
              <FormDescription>
                Leave blank to keep your current password.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="confirmPassword"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Confirm New Password</FormLabel>
              <FormControl>
                <Input
                  type="password"
                  placeholder="••••••••"
                  autoComplete="new-password"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="currentPassword"
          render={({ field }) => (
            <FormItem>
              <FormLabel>Current Password</FormLabel>
              <FormControl>
                <Input
                  type="password"
                  placeholder="••••••••"
                  autoComplete="current-password"
                  {...field}
                />
              </FormControl>
              <FormDescription>
                Required to confirm any change to your email or password.
              </FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Saving...' : 'Save Changes'}
        </Button>
      </form>
    </Form>
  );
}
