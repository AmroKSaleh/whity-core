'use client';

import { useState, useEffect } from 'react';
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
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from '@/components/ui/form';
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

  useEffect(() => {
    if (isOpen) {
      fetchPermissions();
      fetchRole();
    }
  }, [isOpen, role.id, apiClient, addToast]);

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

  const fetchPermissions = async () => {
    try {
      setIsLoadingPermissions(true);
      const response = await apiClient('/api/permissions');

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
  };

  const fetchRole = async () => {
    try {
      setIsLoadingRole(true);
      const response = await apiClient(`/api/roles/${role.id}`);

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
  };

  const onSubmit = async (data: EditRoleFormData) => {
    try {
      setIsSubmitting(true);

      const response = await apiClient(`/api/roles/${role.id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          name: data.name,
          description: data.description,
          permissions: data.permissionIds,
        }),
      });

      if (!response.ok) {
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
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
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
                control={form.control as any}
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
                control={form.control as any}
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
