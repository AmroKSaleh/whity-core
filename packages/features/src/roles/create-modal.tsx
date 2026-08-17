'use client';

/**
 * Injected-translator keys this file renders through `t`. Declared here for
 * the i18n catalogue extractor: it cannot infer a domain from a prop-injected
 * translator (see RolesTranslate — deliberately NOT typed `TranslateFn`, so
 * these files stay unscanned like DemoCatalog does via NavTranslate), so the
 * keys are enumerated below instead. Feature copy resolves in the `admin`
 * domain, shared UI chrome in `common`.
 *
 * @i18n-keys admin
 *   roles.create.cancel = Cancel
 *   roles.create.description.label = Description
 *   roles.create.description.placeholder = Role description
 *   roles.create.error = Failed to create role
 *   roles.create.name.label = Role Name
 *   roles.create.name.placeholder = e.g., Editor
 *   roles.create.permissions.label = Permissions
 *   roles.create.permissions.loading = Loading permissions...
 *   roles.create.permissionsError = Failed to fetch permissions
 *   roles.create.submit = Create Role
 *   roles.create.submitting = Creating...
 *   roles.create.subtitle = Add a new role to your system with permissions.
 *   roles.create.success = Role created successfully
 *   roles.create.title = Create New Role
 *   roles.create.validation.descriptionRequired = Description is required
 *   roles.create.validation.nameRequired = Name is required
 * @i18n-keys common
 *   ui.dialog.close = Close
 */

import { useMemo, useState, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
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
import { PermissionCheckbox } from './permission-checkbox';
import type { Permission, RolesAdapter, RolesTranslate } from './types';

// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildCreateRoleSchema = (t: RolesTranslate) =>
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
  /** Injected data-source adapter. */
  adapter: RolesAdapter;
  /** Injected translator (resolved by RolesScreen). */
  t: RolesTranslate;
  /** Optional notifier. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
  /** Optional prefill (used by "Clone role"); resets the form when the modal opens. */
  initial?: { name: string; description: string; permissionIds: number[] };
}

export function CreateRoleModal({
  isOpen,
  onOpenChange,
  onSuccess,
  adapter,
  t,
  onNotify,
  initial,
}: CreateRoleModalProps) {
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

  useEffect(() => {
    if (!isOpen || permissions.length > 0) return;
    let cancelled = false;
    setIsLoadingPermissions(true);
    adapter
      .listPermissions()
      .then((data) => {
        if (!cancelled) setPermissions(data);
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        const message =
          error instanceof Error && error.message
            ? error.message
            : t('roles.create.permissionsError', 'Failed to fetch permissions');
        onNotify?.(message, 'error');
      })
      .finally(() => {
        if (!cancelled) setIsLoadingPermissions(false);
      });
    return () => {
      cancelled = true;
    };
    // Only re-run on open; t/onNotify/adapter identity changes must not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, permissions.length]);

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
      await adapter.createRole({
        name: data.name,
        description: data.description,
        permissions: data.permissionIds,
      });
      onNotify?.(t('roles.create.success', 'Role created successfully'), 'success');
      form.reset();
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error && error.message
          ? error.message
          : t('roles.create.error', 'Failed to create role');
      onNotify?.(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handlePermissionChange = (selectedIds: number[]) => {
    form.setValue('permissionIds', selectedIds);
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto" closeLabel={t('ui.dialog.close', 'Close')}>
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
                  t={t}
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
