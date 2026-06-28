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
} from '@whity/ui/dialog';
import { Button } from '@whity/ui/button';
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

      const response = await apiClient(`/api/v1/tenants/${tenant.id}`, {
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
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium text-foreground">
              {tenant.name}
            </div>
            <div className="text-xs text-muted-foreground">
              Slug: {tenant.slug}
            </div>
          </div>

          {tenant.userCount > 0 && (
            <div className="flex gap-2 rounded-lg border border-warning/50 bg-warning/10 p-3">
              <IconAlertCircle size={16} className="mt-0.5 shrink-0 text-warning" />
              <div className="text-sm text-warning-foreground">
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
