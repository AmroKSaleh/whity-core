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
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { IconAlertCircle } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { Delegation } from './types';

interface RevokeDelegationModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  delegation: Delegation;
  onSuccess: () => void;
}

/**
 * Revoke-delegation confirmation dialog (WC-34). Revocation is non-destructive
 * server-side (sets `revoked_at`); the grantee loses the delegated access
 * immediately.
 */
export function RevokeDelegationModal({
  isOpen,
  onOpenChange,
  delegation,
  onSuccess,
}: RevokeDelegationModalProps) {
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isRevoking, setIsRevoking] = useState(false);

  const handleRevoke = async () => {
    try {
      setIsRevoking(true);
      const { error, response } = await api.DELETE('/api/v1/delegations/{id}', {
        params: { path: { id: delegation.id } },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('delegations.revoke.error', 'Failed to revoke delegation')
        );
      }

      addToast(t('delegations.revoke.success', 'Delegation revoked successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('delegations.revoke.error', 'Failed to revoke delegation');
      addToast(message, 'error');
    } finally {
      setIsRevoking(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('delegations.revoke.title', 'Revoke Delegation')}</DialogTitle>
          <DialogDescription>
            {t(
              'delegations.revoke.description',
              'Are you sure you want to revoke this delegation? The grantee will lose ' +
                'the delegated access immediately.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium">{delegation.permission}</div>
            {/*
              One sentence, one key. The grantee kind and the scope are holes in
              it rather than fragments concatenated around it, so a translator
              can move the id, the kind and the scope wherever their grammar
              wants them — which is the whole reason this is not
              `'Delegated to ' + type + ' #' + id`.
            */}
            <div className="mt-1 text-xs text-muted-foreground">
              {t('delegations.revoke.grantee', 'Delegated to {type} #{id} ({scope})', {
                type:
                  delegation.granteeType === 'role'
                    ? t('delegations.revoke.granteeType.role', 'role')
                    : t('delegations.revoke.granteeType.user', 'user'),
                id: delegation.granteeId,
                scope:
                  delegation.ouId !== null
                    ? t('delegations.revoke.scope.ou', 'OU #{id}', { id: delegation.ouId })
                    : t('delegations.revoke.scope.tenantWide', 'tenant-wide'),
              })}
            </div>
          </div>

          <Alert>
            <IconAlertCircle className="h-4 w-4" />
            <AlertDescription>
              {t(
                'delegations.revoke.warning',
                'This action cannot be undone. To restore access you would create a ' +
                  'new delegation.'
              )}
            </AlertDescription>
          </Alert>
        </div>

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isRevoking}
          >
            {t('delegations.revoke.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleRevoke}
            disabled={isRevoking}
          >
            {isRevoking
              ? t('delegations.revoke.submitting', 'Revoking...')
              : t('delegations.revoke.submit', 'Revoke Delegation')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
