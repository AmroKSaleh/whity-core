'use client';

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
import type { Permission, Role, RoleWithPermissions, RolesAdapter, TranslateFn } from './types';

// Built from `t` rather than declared at module scope: a validation message is
// user-facing text like any other, and a schema frozen at import time would
// always speak English.
const buildEditRoleSchema = (t: TranslateFn) =>
  z.object({
    name: z.string().min(1, t('roles.edit.validation.nameRequired', 'Name is required')),
    description: z
      .string()
      .min(1, t('roles.edit.validation.descriptionRequired', 'Description is required')),
    permissionIds: z.array(z.number()),
  });

type EditRoleFormData = z.infer<ReturnType<typeof buildEditRoleSchema>>;

interface EditRoleModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  role: Role;
  onSuccess: () => void;
  /** Injected data-source adapter. */
  adapter: RolesAdapter;
  /** Injected translator (resolved by RolesScreen). */
  t: TranslateFn;
  /** Optional notifier. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
}

export function EditRoleModal({
  isOpen,
  onOpenChange,
  role,
  onSuccess,
  adapter,
  t,
  onNotify,
}: EditRoleModalProps) {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [roleData, setRoleData] = useState<RoleWithPermissions | null>(null);
  const [isLoading, setIsLoading] = useState(false);

  const schema = useMemo(() => buildEditRoleSchema(t), [t]);

  const form = useForm<EditRoleFormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: role.name,
      description: role.description,
      permissionIds: [],
    },
  });

  useEffect(() => {
    if (!isOpen) return;
    let cancelled = false;
    setIsLoading(true);
    Promise.all([adapter.listPermissions(), adapter.getRole(role.id)])
      .then(([permissionList, detail]) => {
        if (cancelled) return;
        setPermissions(permissionList);
        setRoleData(detail);
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        const message =
          error instanceof Error && error.message
            ? error.message
            : t('roles.edit.roleDetailsError', 'Failed to fetch role details');
        onNotify?.(message, 'error');
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // Re-run only when the modal opens for a given role; t/onNotify/adapter
    // identity changes must not refetch.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, role.id]);

  useEffect(() => {
    if (roleData) {
      const permissionIds = roleData.permissions.map((p) => p.id);
      form.reset({
        name: roleData.name,
        description: roleData.description,
        permissionIds,
      });
    }
  }, [roleData, form]);

  const onSubmit = async (data: EditRoleFormData) => {
    try {
      setIsSubmitting(true);
      // SAFETY NET (WC-222): a 'not-manageable' result means the role is a
      // global NULL-tenant base role, managed only by the system tenant
      // (WC-110). The row's Edit action is already gated on `manageable`, but
      // should that gate ever be bypassed we surface a friendly toast instead
      // of a generic error / console noise.
      const result = await adapter.updateRole(role.id, {
        name: data.name,
        description: data.description,
        permissions: data.permissionIds,
      });
      if (result === 'not-manageable') {
        onNotify?.(
          t(
            'roles.edit.notManageable',
            "This role can't be modified by your tenant — global base roles are managed by the system tenant."
          ),
          'error'
        );
        return;
      }
      onNotify?.(t('roles.edit.success', 'Role updated successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error && error.message
          ? error.message
          : t('roles.edit.error', 'Failed to update role');
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
          <DialogTitle>{t('roles.edit.title', 'Edit Role')}</DialogTitle>
          <DialogDescription>
            {t('roles.edit.subtitle', 'Update role information and permissions.')}
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="text-sm text-muted-foreground py-8 text-center">
            {t('roles.edit.loading', 'Loading role details...')}
          </div>
        ) : (
          <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t('roles.edit.name.label', 'Role Name')}</FormLabel>
                    <FormControl>
                      <Input
                        placeholder={t('roles.edit.name.placeholder', 'e.g., Editor')}
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
                    <FormLabel>{t('roles.edit.description.label', 'Description')}</FormLabel>
                    <FormControl>
                      <Input
                        placeholder={t('roles.edit.description.placeholder', 'Role description')}
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <div className="space-y-2">
                <FormLabel>{t('roles.edit.permissions.label', 'Permissions')}</FormLabel>
                <PermissionCheckbox
                  permissions={permissions}
                  selectedIds={form.watch('permissionIds')}
                  onChange={handlePermissionChange}
                  t={t}
                />
              </div>

              <DialogFooter>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => onOpenChange(false)}
                >
                  {t('roles.edit.cancel', 'Cancel')}
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting
                    ? t('roles.edit.submitting', 'Saving...')
                    : t('roles.edit.submit', 'Save Changes')}
                </Button>
              </DialogFooter>
            </form>
          </Form>
        )}
      </DialogContent>
    </Dialog>
  );
}
