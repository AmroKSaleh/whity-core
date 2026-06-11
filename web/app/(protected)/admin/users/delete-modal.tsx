'use client';

import { useState } from 'react';
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
import { Button } from '@/components/ui/button';
import type { User } from './page';

interface DeleteUserModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  user: User;
  onSuccess: () => void;
}

export function DeleteUserModal({
  isOpen,
  onOpenChange,
  user,
  onSuccess,
}: DeleteUserModalProps) {
  const { addToast } = useToast();
  const [isDeleting, setIsDeleting] = useState(false);

  const handleDelete = async () => {
    try {
      setIsDeleting(true);

      const { error, response } = await api.DELETE('/api/users/{id}', {
        params: { path: { id: user.id } },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(error?.error ?? 'Failed to delete user');
      }

      addToast('User deleted successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to delete user';
      addToast(message, 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete User</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete this user? This action cannot be undone.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-slate-100 p-3 dark:bg-slate-800">
            <div className="text-sm font-medium text-slate-900 dark:text-slate-50">
              {user.name}
            </div>
            <div className="text-xs text-slate-600 dark:text-slate-400">
              {user.email}
            </div>
          </div>
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
            {isDeleting ? 'Deleting...' : 'Delete User'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
