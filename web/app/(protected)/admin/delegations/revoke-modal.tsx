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
import { Alert, AlertDescription } from '@/components/ui/alert';
import { IconAlertCircle } from '@tabler/icons-react';
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
  const [isRevoking, setIsRevoking] = useState(false);

  const handleRevoke = async () => {
    try {
      setIsRevoking(true);
      const { error, response } = await api.DELETE('/api/delegations/{id}', {
        params: { path: { id: delegation.id } },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(error?.error ?? 'Failed to revoke delegation');
      }

      addToast('Delegation revoked successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to revoke delegation';
      addToast(message, 'error');
    } finally {
      setIsRevoking(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>Revoke Delegation</DialogTitle>
          <DialogDescription>
            Are you sure you want to revoke this delegation? The grantee will lose
            the delegated access immediately.
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium">{delegation.permission}</div>
            <div className="mt-1 text-xs text-muted-foreground">
              Delegated to {delegation.granteeType} #{delegation.granteeId}
              {delegation.ouId !== null
                ? ` (OU #${delegation.ouId})`
                : ' (tenant-wide)'}
            </div>
          </div>

          <Alert>
            <IconAlertCircle className="h-4 w-4" />
            <AlertDescription>
              This action cannot be undone. To restore access you would create a
              new delegation.
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
            Cancel
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleRevoke}
            disabled={isRevoking}
          >
            {isRevoking ? 'Revoking...' : 'Revoke Delegation'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
