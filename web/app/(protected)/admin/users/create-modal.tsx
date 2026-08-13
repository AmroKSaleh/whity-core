'use client';

import { useMemo, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@/components/ui/input';
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from '@amroksaleh/ui/form';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { useRoleOptions } from './use-role-options';
import { PasswordStrengthIndicator } from '@/components/PasswordStrengthIndicator';

// Only the fields the API actually reads (UserCreateRequest): the server
// derives `name` from the email local-part and always creates the user in the
// caller's tenant context, so the form offers neither (WC-168 — the previous
// Name/Tenant inputs were silently ignored server-side).
//
// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English (the shape `buildAddKeySchema(t)` established).
const buildCreateUserSchema = (t: TranslateFn) =>
  z.object({
    email: z.string().email(t('users.create.validation.email', 'Invalid email address')),
    password: z
      .string()
      .min(
        8,
        t('users.create.validation.password', 'Password must be at least 8 characters')
      ),
    role: z.string().min(1, t('users.create.validation.role', 'Role is required')),
  });

type CreateUserFormData = z.infer<ReturnType<typeof buildCreateUserSchema>>;

interface CreateUserModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

export function CreateUserModal({
  isOpen,
  onOpenChange,
  onSuccess,
}: CreateUserModalProps) {
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);
  // Role dropdown options come from the live tenant-visible role list, so only
  // roles that actually exist (and resolve server-side) are offered (WC-121).
  const { roleOptions, isLoadingRoles } = useRoleOptions(isOpen);

  const schema = useMemo(() => buildCreateUserSchema(t), [t]);
  const form = useForm<CreateUserFormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      email: '',
      password: '',
      role: '',
    },
  });

  const onSubmit = async (data: CreateUserFormData) => {
    try {
      setIsSubmitting(true);

      const { error, response } = await api.POST('/api/v1/users', {
        body: {
          email: data.email,
          password: data.password,
          role: data.role,
        },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(error?.error ?? t('users.create.error', 'Failed to create user'));
      }

      addToast(t('users.create.success', 'User created successfully'), 'success');
      form.reset();
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('users.create.error', 'Failed to create user');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.create.title', 'Create New User')}</DialogTitle>
          <DialogDescription>
            {t(
              'users.create.description',
              'Add a new user to your system. Fill in the form below.'
            )}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="email"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('users.create.email.label', 'Email')}</FormLabel>
                  <FormControl>
                    <Input
                      type="email"
                      placeholder="john@example.com"
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Password</FormLabel>
                  <FormControl>
                    <Input
                      type="password"
                      placeholder="••••••••"
                      {...field}
                    />
                  </FormControl>
                  <PasswordStrengthIndicator password={field.value} />
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="role"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>Role</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue
                          placeholder={
                            isLoadingRoles ? 'Loading roles…' : 'Select a role'
                          }
                        />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {roleOptions.map((role) => (
                        <SelectItem key={role.value} value={role.value}>
                          {role.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                Cancel
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting ? 'Creating...' : 'Create User'}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
