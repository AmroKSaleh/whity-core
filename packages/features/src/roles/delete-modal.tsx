'use client';

import { useState } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { IconAlertCircle } from '@tabler/icons-react';
import type { Role, RolesAdapter, TranslateFn } from './types';

interface DeleteRoleModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  role: Role;
  onSuccess: () => void;
  /** Injected data-source adapter. */
  adapter: RolesAdapter;
  /** Injected translator (resolved by RolesScreen). */
  t: TranslateFn;
  /** Optional notifier. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
}

export function DeleteRoleModal({
  isOpen,
  onOpenChange,
  role,
  onSuccess,
  adapter,
  t,
  onNotify,
}: DeleteRoleModalProps) {
  const [isDeleting, setIsDeleting] = useState(false);

  const handleDelete = async () => {
    try {
      setIsDeleting(true);
      // SAFETY NET (WC-222): a 'not-manageable' result means the role is a
      // global NULL-tenant base role, managed only by the system tenant
      // (WC-110). The row's Delete action is already gated on `manageable`, but
      // should that gate ever be bypassed we surface a friendly toast instead
      // of a generic error / console noise.
      const result = await adapter.deleteRole(role.id);
      if (result === 'not-manageable') {
        onNotify?.(
          t(
            'roles.delete.notManageable',
            "This role can't be modified by your tenant — global base roles are managed by the system tenant."
          ),
          'error'
        );
        return;
      }
      onNotify?.(t('roles.delete.success', 'Role deleted successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error && error.message
          ? error.message
          : t('roles.delete.error', 'Failed to delete role');
      onNotify?.(message, 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent closeLabel={t('ui.dialog.close', 'Close')}>
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
