'use client';

import { useCallback, useMemo, useState, useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
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
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { PermissionCheckbox } from './permission-checkbox';
import type { Permission } from './types';

// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildCreateRoleSchema = (t: TranslateFn) =>
  z.object({
    name: z.string().min(1, t('roles.create.validation.nameRequired', 'Name is required')),
    description: z
      .string()
      .min(1, t('roles.create.validation.descriptionRequired', 'Description is required')),
    permissionIds: z.array(z.number()),
  });

type CreateRoleFormData = z.infer<ReturnType<typeof buildCreateRoleSchema>>;

interface CreateRoleModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
  /** Optional prefill (used by "Clone role"); resets the form when the modal opens. */
  initial?: { name: string; description: string; permissionIds: number[] };
}

export function CreateRoleModal({
  isOpen,
  onOpenChange,
  onSuccess,
  initial,
}: CreateRoleModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [isLoadingPermissions, setIsLoadingPermissions] = useState(false);

  const schema = useMemo(() => buildCreateRoleSchema(t), [t]);

  const form = useForm<CreateRoleFormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: '',
      description: '',
      permissionIds: [],
    },
  });

  const fetchPermissions = useCallback(async () => {
    try {
      setIsLoadingPermissions(true);
      const response = await apiClient('/api/v1/permissions?per_page=100');

      if (!response.ok) {
        throw new Error(t('roles.create.permissionsError', 'Failed to fetch permissions'));
      }

      const data = await response.json();
      setPermissions(data.data || []);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('roles.create.permissionsError', 'Failed to fetch permissions');
      addToast(message, 'error');
    } finally {
      setIsLoadingPermissions(false);
    }
  }, [apiClient, addToast, t]);

  useEffect(() => {
    if (isOpen && permissions.length === 0) {
      void (async () => { await fetchPermissions(); })();
    }
  }, [isOpen, permissions.length, fetchPermissions]);

  // On open, seed the form: a clone prefills name/description/permissions, a
  // plain create resets to blanks.
  useEffect(() => {
    if (isOpen) {
      form.reset({
        name: initial?.name ?? '',
        description: initial?.description ?? '',
        permissionIds: initial?.permissionIds ?? [],
      });
    }
  }, [isOpen, initial, form]);

  const onSubmit = async (data: CreateRoleFormData) => {
    try {
      setIsSubmitting(true);

      const response = await apiClient('/api/v1/roles', {
        method: 'POST',
        body: JSON.stringify({
          name: data.name,
          description: data.description,
          permissions: data.permissionIds,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || t('roles.create.error', 'Failed to create role')
        );
      }

      addToast(t('roles.create.success', 'Role created successfully'), 'success');
      form.reset();
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : t('roles.create.error', 'Failed to create role');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handlePermissionChange = (selectedIds: number[]) => {
    form.setValue('permissionIds', selectedIds);
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>{t('roles.create.title', 'Create New Role')}</DialogTitle>
          <DialogDescription>
            {t('roles.create.subtitle', 'Add a new role to your system with permissions.')}
          </DialogDescription>
        </DialogHeader>

        <Form {...form}>
          <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('roles.create.name.label', 'Role Name')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('roles.create.name.placeholder', 'e.g., Editor')}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <FormField
              control={form.control}
              name="description"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t('roles.create.description.label', 'Description')}</FormLabel>
                  <FormControl>
                    <Input
                      placeholder={t('roles.create.description.placeholder', 'Role description')}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />

            <div className="space-y-2">
              <FormLabel>{t('roles.create.permissions.label', 'Permissions')}</FormLabel>
              {isLoadingPermissions ? (
                <div className="text-sm text-muted-foreground py-4 text-center">
                  {t('roles.create.permissions.loading', 'Loading permissions...')}
                </div>
              ) : (
                <PermissionCheckbox
                  permissions={permissions}
                  selectedIds={form.watch('permissionIds')}
                  onChange={handlePermissionChange}
                />
              )}
            </div>

            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => onOpenChange(false)}
              >
                {t('roles.create.cancel', 'Cancel')}
              </Button>
              <Button type="submit" disabled={isSubmitting || isLoadingPermissions}>
                {isSubmitting
                  ? t('roles.create.submitting', 'Creating...')
                  : t('roles.create.submit', 'Create Role')}
              </Button>
            </DialogFooter>
          </form>
        </Form>
      </DialogContent>
    </Dialog>
  );
}
