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
import { Button } from '@amroksaleh/ui/button';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { User } from './page';
import { useApproverCoverage, wouldStrandTenant } from '@/hooks/useApproverCoverage';

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
  const t = useTranslation('admin');
  const [isDeleting, setIsDeleting] = useState(false);
  // WC-797 §4a: removal reaches the same unrecoverable state the approval-gate
  // toggle does, and does it looking like an unrelated edit.
  const { coverage } = useApproverCoverage(isOpen);
  const removalWouldStrandTenant = wouldStrandTenant(coverage, user.id, null);

  const handleDelete = async () => {
    try {
      setIsDeleting(true);

      const { error, response } = await api.DELETE('/api/v1/users/{id}', {
        params: { path: { id: user.id } },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(error?.error ?? t('users.delete.error', 'Failed to delete user'));
      }

      addToast(t('users.delete.success', 'User deleted successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : t('users.delete.error', 'Failed to delete user');
      addToast(message, 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.delete.title', 'Delete User')}</DialogTitle>
          <DialogDescription>
            {t(
              'users.delete.description',
              'Are you sure you want to delete this user? This action cannot be undone.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium text-foreground">
              {user.name}
            </div>
            <div className="text-xs text-muted-foreground">
              {user.email}
            </div>
          </div>

          {removalWouldStrandTenant && (
            <p
              role="alert"
              data-testid="delete-user-approver-warning"
              className="rounded-md border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive"
            >
              {t(
                'users.delete.approverWarning',
                'This is one of the few accounts here that can approve a password reset. Removing it can leave this tenant unable to approve any reset — including its remaining administrator’s own, which nobody could then recover from inside the product.'
              )}
            </p>
          )}
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isDeleting}
          >
            {t('users.delete.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleDelete}
            disabled={isDeleting}
          >
            {isDeleting
              ? t('users.delete.submitting', 'Deleting...')
              : t('users.delete.submit', 'Delete User')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
