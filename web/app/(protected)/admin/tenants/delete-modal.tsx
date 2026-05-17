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
import { IconAlertCircle } from '@tabler/icons-react';
import type { Tenant } from './page';

interface DeleteTenantModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  tenant: Tenant;
  onSuccess: () => void;
}

export function DeleteTenantModal({
  isOpen,
  onOpenChange,
  tenant,
  onSuccess,
}: DeleteTenantModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [isDeleting, setIsDeleting] = useState(false);

  const handleDelete = async () => {
    try {
      setIsDeleting(true);

      const response = await apiClient(`/api/tenants/${tenant.id}`, {
        method: 'DELETE',
      });

      if (!response.ok) {
        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || 'Failed to delete tenant'
        );
      }

      addToast('Tenant deleted successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to delete tenant';
      addToast(message, 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Delete Tenant</DialogTitle>
          <DialogDescription>
            Are you sure you want to delete this tenant? This action cannot be undone.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-slate-100 p-3 dark:bg-slate-800">
            <div className="text-sm font-medium text-slate-900 dark:text-slate-50">
              {tenant.name}
            </div>
            <div className="text-xs text-slate-600 dark:text-slate-400">
              Slug: {tenant.slug}
            </div>
          </div>

          {tenant.userCount > 0 && (
            <div className="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 dark:border-amber-900 dark:bg-amber-950">
              <IconAlertCircle size={16} className="mt-0.5 flex-shrink-0 text-amber-600 dark:text-amber-500" />
              <div className="text-sm text-amber-800 dark:text-amber-200">
                This tenant has {tenant.userCount} associated user{tenant.userCount !== 1 ? 's' : ''}. Deleting it may impact those users.
              </div>
            </div>
          )}
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
            {isDeleting ? 'Deleting...' : 'Delete Tenant'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
