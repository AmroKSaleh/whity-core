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
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { IconAlertCircle } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
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
  const t = useTranslation('admin');
  const [isDeleting, setIsDeleting] = useState(false);

  const handleDelete = async () => {
    try {
      setIsDeleting(true);

      const response = await apiClient(`/api/v1/roles/${role.id}`, {
        method: 'DELETE',
      });

      if (!response.ok) {
        // SAFETY NET (WC-222): a 404 here means the role is not manageable by
        // the current tenant (a global NULL-tenant base role — managed only by
        // the system tenant, WC-110). The row's Delete action is already gated
        // on `manageable`, but should that gate ever be bypassed we surface a
        // friendly toast instead of a generic error / console noise.
        if (response.status === 404) {
          addToast(
            t(
              'roles.delete.notManageable',
              "This role can't be modified by your tenant — global base roles are managed by the system tenant."
            ),
            'error'
          );
          return;
        }

        const errorData = await response.json().catch(() => ({}));
        throw new Error(
          errorData.message || t('roles.delete.error', 'Failed to delete role')
        );
      }

      addToast(t('roles.delete.success', 'Role deleted successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : t('roles.delete.error', 'Failed to delete role');
      addToast(message, 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('roles.delete.title', 'Delete Role')}</DialogTitle>
          <DialogDescription>
            {t(
              'roles.delete.description',
              'Are you sure you want to delete this role? This action cannot be undone.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium text-foreground">
              {role.name}
            </div>
            <div className="text-xs text-muted-foreground">
              {role.description}
            </div>
            {role.permissionCount && (
              <div className="text-xs text-muted-foreground mt-2">
                {t('roles.delete.permissionCount', 'Permissions: {count}', {
                  count: role.permissionCount,
                })}
              </div>
            )}
          </div>

          <Alert>
            <IconAlertCircle className="h-4 w-4" />
            <AlertDescription>
              {t(
                'roles.delete.warning',
                'If this role is assigned to users, they will lose the permissions associated with this role.'
              )}
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
            {t('roles.delete.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleDelete}
            disabled={isDeleting}
          >
            {isDeleting
              ? t('roles.delete.submitting', 'Deleting...')
              : t('roles.delete.submit', 'Delete Role')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
