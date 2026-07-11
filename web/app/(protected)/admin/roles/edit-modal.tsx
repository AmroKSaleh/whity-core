'use client';

import { useCallback, useState, useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
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
import type { Permission, Role, RoleWithPermissions } from './types';

const editRoleSchema = z.object({
  name: z.string().min(1, 'Name is required'),
  description: z.string().min(1, 'Description is required'),
  permissionIds: z.array(z.number()),
});

type EditRoleFormData = z.infer<typeof editRoleSchema>;

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
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [isLoadingPermissions, setIsLoadingPermissions] = useState(false);
  const [roleData, setRoleData] = useState<RoleWithPermissions | null>(null);
  const [isLoadingRole, setIsLoadingRole] = useState(false);

  const form = useForm<EditRoleFormData>({
    resolver: zodResolver(editRoleSchema),
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
        throw new Error('Failed to fetch permissions');
      }

      const data = await response.json();
      setPermissions(data.data || []);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to fetch permissions';
      addToast(message, 'error');
    } finally {
      setIsLoadingPermissions(false);
    }
  }, [apiClient, addToast]);

  const fetchRole = useCallback(async () => {
    try {
      setIsLoadingRole(true);
      const response = await apiClient(`/api/v1/roles/${role.id}`);

      if (!response.ok) {
        throw new Error('Failed to fetch role');
      }

      const data = await response.json();
      setRoleData(data.data);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to fetch role details';
      addToast(message, 'error');
    } finally {
      setIsLoadingRole(false);
    }
  }, [apiClient, role.id, addToast]);

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
            "This role can't be modified by your tenant — global base roles are managed by the system tenant.",
            'error'
          );
          return;
        }

        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || 'Failed to update role'
        );
      }

      addToast('Role updated successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to update role';
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
          <DialogTitle>Edit Role</DialogTitle>
          <DialogDescription>
            Update role information and permissions.
          </DialogDescription>
        </DialogHeader>

        {isLoading ? (
          <div className="text-sm text-muted-foreground py-8 text-center">
            Loading role details...
          </div>
        ) : (
          <Form {...form}>
            <form onSubmit={form.handleSubmit(onSubmit)} className="space-y-4">
              <FormField
                control={form.control}
                name="name"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>Role Name</FormLabel>
                    <FormControl>
                      <Input placeholder="e.g., Editor" {...field} />
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
                    <FormLabel>Description</FormLabel>
                    <FormControl>
                      <Input placeholder="Role description" {...field} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <div className="space-y-2">
                <FormLabel>Permissions</FormLabel>
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
                  Cancel
                </Button>
                <Button type="submit" disabled={isSubmitting}>
                  {isSubmitting ? 'Saving...' : 'Save Changes'}
                </Button>
              </DialogFooter>
            </form>
          </Form>
        )}
      </DialogContent>
    </Dialog>
  );
}
