'use client';

import { useMemo, useState, useEffect } from 'react';
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
import type { User } from './page';
import { useRoleOptions } from './use-role-options';
import { useOuOptions } from './use-ou-options';

// Only `role` and `ou_id` are editable on this endpoint (WC-113/WC-205). `name`
// is derived from the email local-part (no users.name column) and `tenant` moves
// are intentionally out of scope server-side, so both are presented read-only and
// excluded from the editable schema. `ou_id` is nullable — clearing the picker
// removes the user from any OU.
//
// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildEditUserSchema = (t: TranslateFn) =>
  z.object({
    role: z.string().min(1, t('users.edit.validation.role', 'Role is required')),
    ou_id: z.string().nullable(),
  });

type EditUserFormData = z.infer<ReturnType<typeof buildEditUserSchema>>;

interface EditUserModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  user: User;
  onSuccess: () => void;
}

export function EditUserModal({
  isOpen,
  onOpenChange,
  user,
  onSuccess,
}: EditUserModalProps) {
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);
  // Role dropdown options come from the live tenant-visible role list, so only
  // roles that actually exist (and resolve server-side) are offered. This
  // removes the phantom "Moderator" option that 404'd on save (WC-121).
  const { roleOptions, isLoadingRoles } = useRoleOptions(isOpen);
  // OU dropdown options come from the live tenant-scoped OU list (WC-205).
  const { ouOptions, isLoadingOus } = useOuOptions(isOpen);

  // Only the editable fields (`role`, `ou_id`) are bound to the form. Name and
  // tenant are displayed read-only directly from the user record (see below).
  const toFormValues = (u: User): EditUserFormData => ({
    role: u.role ?? '',
    ou_id: u.ou_id != null ? String(u.ou_id) : null,
  });

  const schema = useMemo(() => buildEditUserSchema(t), [t]);

  const form = useForm<EditUserFormData>({
    resolver: zodResolver(schema),
    defaultValues: toFormValues(user),
  });

  useEffect(() => {
    if (isOpen && user) {
      form.reset(toFormValues(user));
    }
  }, [isOpen, user, form]);

  const onSubmit = async (data: EditUserFormData) => {
    try {
      setIsSubmitting(true);

      // `role` and `ou_id` are editable here. `name` is derived from the email
      // local-part (there is no users.name column) and `tenant` moves are out
      // of scope server-side (WC-113/WC-205), so both are shown read-only and
      // never submitted — sending them would be ignored anyway.
      // Convert the string picker value back to a number (or null when cleared).
      const ouId = data.ou_id !== null && data.ou_id !== '' ? Number(data.ou_id) : null;
      const { data: payload, error, response } = await api.PATCH('/api/v1/users/{id}', {
        params: { path: { id: user.id } },
        body: { role: data.role, ou_id: ouId },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(error?.error ?? t('users.edit.error', 'Failed to update user'));
      }

      // Confirm the change actually persisted before claiming success: the
      // backend returns the updated user, so we assert the role it reports back
      // matches what we asked for rather than trusting a bare 200 (WC-113).
      const persistedRole = payload?.data.role;
      if (persistedRole !== undefined && persistedRole !== data.role) {
        throw new Error(
          t('users.edit.notPersisted', 'User update did not persist the selected role')
        );
      }

      addToast(t('users.edit.success', 'User updated successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : t('users.edit.error', 'Failed to update user');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.edit.title', 'Edit User')}</DialogTitle>
          <DialogDescription>
            {t('users.edit.description', 'Update user information. Email cannot be changed.')}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            {/*
              Read-only fields use native label/input association (explicit
              htmlFor + id) rather than FormLabel/FormControl, which derive their
              ids from react-hook-form's FormField context these fields are not
              part of. This keeps the label accessible-name query working.
            */}
            <div className="space-y-2">
              <label
                htmlFor="edit-user-email"
                className="text-sm font-medium leading-none"
              >
                {t('users.edit.email.label', 'Email')}
              </label>
              <Input id="edit-user-email" type="email" value={user.email} disabled />
              <p className="text-xs text-muted-foreground">
                {t('users.edit.email.hint', 'Email cannot be changed')}
              </p>
            </div>

            <div className="space-y-2">
              <label
                htmlFor="edit-user-name"
                className="text-sm font-medium leading-none"
              >
                {t('users.edit.name.label', 'Name')}
              </label>
              <Input id="edit-user-name" value={user.name} disabled />
              <p className="text-xs text-muted-foreground">
                {t(
                  'users.edit.name.hint',
                  'Name is derived from the email and cannot be changed'
                )}
              </p>
            </div>

            <FormField
              control={form.control}
              name="role"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('users.edit.role.label', 'Role')}</FormLabel>
                  <Select onValueChange={field.onChange} value={field.value}>
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue
                          placeholder={
                            isLoadingRoles
                              ? t('users.edit.role.loading', 'Loading roles…')
                              : t('users.edit.role.placeholder', 'Select a role')
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

            <FormField
              control={form.control}
              name="ou_id"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('users.edit.ou.label', 'Organisational Unit')}</FormLabel>
                  <Select
                    onValueChange={(val) => field.onChange(val === '__none__' ? null : val)}
                    value={field.value ?? '__none__'}
                  >
                    <FormControl>
                      <SelectTrigger>
                        <SelectValue
                          placeholder={
                            isLoadingOus
                              ? t('users.edit.ou.loading', 'Loading OUs…')
                              : t('users.edit.ou.none', 'None (root)')
                          }
                        />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      <SelectItem value="__none__">
                        {t('users.edit.ou.none', 'None (root)')}
                      </SelectItem>
                      {ouOptions.map((ou) => (
                        <SelectItem key={ou.value} value={ou.value}>
                          {ou.label}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />

            <div className="space-y-2">
              <label
                htmlFor="edit-user-tenant"
                className="text-sm font-medium leading-none"
              >
                {t('users.edit.tenant.label', 'Tenant')}
              </label>
              <Input
                id="edit-user-tenant"
                type="number"
                value={user.tenantId != null ? String(user.tenantId) : ''}
                disabled
              />
              <p className="text-xs text-muted-foreground">
                {t(
                  'users.edit.tenant.hint',
                  'Moving a user between tenants is not supported here'
                )}
              </p>
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                {t('users.edit.cancel', 'Cancel')}
              </Button>
              <Button type="submit" disabled={isSubmitting}>
                {isSubmitting
                  ? t('users.edit.submitting', 'Saving...')
                  : t('users.edit.submit', 'Save Changes')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
