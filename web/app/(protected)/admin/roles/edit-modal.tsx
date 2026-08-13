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
import type { Permission, Role, RoleWithPermissions } from './types';

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
}

export function EditRoleModal({
  isOpen,
  onOpenChange,
  role,
  onSuccess,
}: EditRoleModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [isLoadingPermissions, setIsLoadingPermissions] = useState(false);
  const [roleData, setRoleData] = useState<RoleWithPermissions | null>(null);
  const [isLoadingRole, setIsLoadingRole] = useState(false);

  const schema = useMemo(() => buildEditRoleSchema(t), [t]);

  const form = useForm<EditRoleFormData>({
    resolver: zodResolver(schema),
    defaultValues: {
      name: role.name,
      description: role.description,
      permissionIds: [],
    },
  });

  const fetchPermissions = useCallback(async () => {
    try {
      setIsLoadingPermissions(true);
      const response = await apiClient('/api/v1/permissions?per_page=100');

      if (!response.ok) {
        throw new Error(t('roles.edit.permissionsError', 'Failed to fetch permissions'));
      }

      const data = await response.json();
      setPermissions(data.data || []);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('roles.edit.permissionsError', 'Failed to fetch permissions');
      addToast(message, 'error');
    } finally {
      setIsLoadingPermissions(false);
    }
  }, [apiClient, addToast, t]);

  const fetchRole = useCallback(async () => {
    try {
      setIsLoadingRole(true);
      const response = await apiClient(`/api/v1/roles/${role.id}`);

      if (!response.ok) {
        throw new Error(t('roles.edit.roleError', 'Failed to fetch role'));
      }

      const data = await response.json();
      setRoleData(data.data);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('roles.edit.roleDetailsError', 'Failed to fetch role details');
      addToast(message, 'error');
    } finally {
      setIsLoadingRole(false);
    }
  }, [apiClient, role.id, addToast, t]);

  useEffect(() => {
    if (isOpen) {
      void (async () => {
        await Promise.all([fetchPermissions(), fetchRole()]);
      })();
    }
  }, [isOpen, fetchPermissions, fetchRole]);

  useEffect(() => {
    if (roleData) {
      const permissionIds = roleData.permissions.map(p => p.id);
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

      const response = await apiClient(`/api/v1/roles/${role.id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          name: data.name,
          description: data.description,
          permissions: data.permissionIds,
        }),
      });

      if (!response.ok) {
        // SAFETY NET (WC-222): a 404 here means the role is not manageable by
        // the current tenant (a global NULL-tenant base role — managed only by
        // the system tenant, WC-110). The row's Edit action is already gated on
        // `manageable`, but should that gate ever be bypassed we surface a
        // friendly toast instead of a generic error / console noise.
        if (response.status === 404) {
          addToast(
            t(
              'roles.edit.notManageable',
              "This role can't be modified by your tenant — global base roles are managed by the system tenant."
            ),
            'error'
          );
          return;
        }

        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || t('roles.edit.error', 'Failed to update role')
        );
      }

      addToast(t('roles.edit.success', 'Role updated successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : t('roles.edit.error', 'Failed to update role');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handlePermissionChange = (selectedIds: number[]) => {
    form.setValue('permissionIds', selectedIds);
  };

  const isLoading = isLoadingPermissions || isLoadingRole;

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-3xl max-h-[90vh] overflow-y-auto">
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
