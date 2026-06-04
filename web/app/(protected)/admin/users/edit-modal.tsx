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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select';
import { useForm } from 'react-hook-form';
import { z } from 'zod';
import { zodResolver } from '@hookform/resolvers/zod';
import type { User } from './page';
import { useRoleOptions } from './use-role-options';

// Only `role` is editable on this endpoint (WC-113). `name` is derived from the
// email local-part (no users.name column) and `tenant` moves are intentionally
// out of scope server-side, so both are presented read-only and excluded from
// the editable schema.
const editUserSchema = z.object({
  role: z.string().min(1, 'Role is required'),
});

type EditUserFormData = z.infer<typeof editUserSchema>;

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
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isSubmitting, setIsSubmitting] = useState(false);
  // Role dropdown options come from the live tenant-visible role list, so only
  // roles that actually exist (and resolve server-side) are offered. This
  // removes the phantom "Moderator" option that 404'd on save (WC-121).
  const { roleOptions, isLoadingRoles } = useRoleOptions(isOpen);

  // Only the editable field (`role`) is bound to the form. Name and tenant are
  // displayed read-only directly from the user record (see below).
  const toFormValues = (u: User): EditUserFormData => ({
    role: u.role ?? '',
  });

  const form = useForm<EditUserFormData>({
    resolver: zodResolver(editUserSchema),
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

      // Only `role` is editable here. `name` is derived from the email
      // local-part (there is no users.name column) and `tenant` moves are out
      // of scope server-side (WC-113), so both are shown read-only and never
      // submitted — sending them would be ignored anyway.
      const response = await apiClient(`/api/users/${user.id}`, {
        method: 'PATCH',
        body: JSON.stringify({
          role: data.role,
        }),
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(errorData.error || errorData.message || 'Failed to update user');
      }

      // Confirm the change actually persisted before claiming success: the
      // backend returns the updated user, so we assert the role it reports back
      // matches what we asked for rather than trusting a bare 200 (WC-113).
      const payload = (await response.json().catch(() => null)) as
        | { data?: { role?: string } }
        | null;
      const persistedRole = payload?.data?.role;
      if (persistedRole !== undefined && persistedRole !== data.role) {
        throw new Error('User update did not persist the selected role');
      }

      addToast('User updated successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to update user';
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Edit User</DialogTitle>
          <DialogDescription>
            Update user information. Email cannot be changed.
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
                Email
              </label>
              <Input id="edit-user-email" type="email" value={user.email} disabled />
              <p className="text-xs text-muted-foreground">Email cannot be changed</p>
            </div>

            <div className="space-y-2">
              <label
                htmlFor="edit-user-name"
                className="text-sm font-medium leading-none"
              >
                Name
              </label>
              <Input id="edit-user-name" value={user.name} disabled />
              <p className="text-xs text-muted-foreground">
                Name is derived from the email and cannot be changed
              </p>
            </div>

            <FormField
              control={form.control as any}
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

            <div className="space-y-2">
              <label
                htmlFor="edit-user-tenant"
                className="text-sm font-medium leading-none"
              >
                Tenant
              </label>
              <Input
                id="edit-user-tenant"
                type="number"
                value={user.tenantId != null ? String(user.tenantId) : ''}
                disabled
              />
              <p className="text-xs text-muted-foreground">
                Moving a user between tenants is not supported here
              </p>
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
      </DialogContent>
    </Dialog>
  );
}
