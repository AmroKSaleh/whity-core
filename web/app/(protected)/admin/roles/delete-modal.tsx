'use client';

import { useState } from 'react';
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
import { Alert, AlertDescription } from '@/components/ui/alert';
import { IconAlertCircle } from '@tabler/icons-react';
import type { Role } from './types';

interface DeleteRoleModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  role: Role;
  onSuccess: () => void;
}

export function DeleteRoleModal({
  isOpen,
  onOpenChange,
  role,
  onSuccess,
}: DeleteRoleModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isDeleting, setIsDeleting] = useState(false);

  const handleDelete = async () => {
    try {
      setIsDeleting(true);

      const response = await apiClient(`/api/roles/${role.id}`, {
        method: 'DELETE',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || 'Failed to delete role'
        );
      }

      addToast('Role deleted successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to delete role';
      addToast(message, 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete Role</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete this role? This action cannot be undone.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-slate-100 p-3 dark:bg-slate-800">
            <div className="text-sm font-medium text-slate-900 dark:text-slate-50">
              {role.name}
            </div>
            <div className="text-xs text-slate-600 dark:text-slate-400">
              {role.description}
            </div>
            {role.permissionCount && (
              <div className="text-xs text-slate-600 dark:text-slate-400 mt-2">
                Permissions: {role.permissionCount}
              </div>
            )}
          </div>

          <Alert>
            <IconAlertCircle className="h-4 w-4" />
            <AlertDescription>
              If this role is assigned to users, they will lose the permissions associated with this role.
            </AlertDescription>
          </Alert>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isDeleting}
          >
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleDelete}
            disabled={isDeleting}
          >
            {isDeleting ? 'Deleting...' : 'Delete Role'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
