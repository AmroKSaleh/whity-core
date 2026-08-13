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
import type { AiPrincipal } from './types';

interface RevokeAiPrincipalModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  principal: AiPrincipal;
  onSuccess: () => void;
}

export function RevokeAiPrincipalModal({
  isOpen,
  onOpenChange,
  principal,
  onSuccess,
}: RevokeAiPrincipalModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isRevoking, setIsRevoking] = useState(false);

  const handleRevoke = async () => {
    try {
      setIsRevoking(true);
      const response = await apiClient(
        `/api/v1/admin/mcp/tokens/${principal.jti}`,
        { method: 'DELETE' }
      );

      if (!response.ok) {
        if (response.status === 404) {
          addToast(
            t(
              'aiPrincipals.revoke.notFound',
              'Token not found — it may have already been revoked.'
            ),
            'error'
          );
          onSuccess();
          return;
        }
        const errorData = await response.json().catch(() => ({}));
        const errorObj = errorData as { message?: string };
        throw new Error(
          errorObj.message ?? t('aiPrincipals.revoke.error', 'Failed to revoke token')
        );
      }

      addToast(
        t('aiPrincipals.revoke.success', 'Token "{name}" revoked', { name: principal.name }),
        'success'
      );
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('aiPrincipals.revoke.error', 'Failed to revoke token');
      addToast(message, 'error');
    } finally {
      setIsRevoking(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('aiPrincipals.revoke.title', 'Revoke AI Principal')}</DialogTitle>
          <DialogDescription>
            {t(
              'aiPrincipals.revoke.description',
              'This will immediately invalidate the bearer token. Any AI client ' +
                'using it will receive 401 on the next request.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium text-foreground">
              {principal.name}
            </div>
            <div className="text-xs text-muted-foreground font-mono mt-1">
              {principal.jti}
            </div>
            <div className="mt-2 flex flex-wrap gap-1">
              {principal.scope.map((s) => (
                <span
                  key={s}
                  className="rounded border border-border bg-background px-1.5 py-0.5 text-xs text-foreground"
                >
                  {s}
                </span>
              ))}
            </div>
          </div>

          <Alert>
            <IconAlertCircle className="h-4 w-4" />
            <AlertDescription>
              {t(
                'aiPrincipals.revoke.warning',
                'Revocation is permanent. A new token must be issued if access is ' +
                  'needed again.'
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
            {t('aiPrincipals.revoke.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleRevoke}
            disabled={isRevoking}
          >
            {isRevoking
              ? t('aiPrincipals.revoke.submitting', 'Revoking...')
              : t('aiPrincipals.revoke.submit', 'Revoke Token')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
