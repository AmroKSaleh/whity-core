'use client';

import { useState } from 'react';
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
import { IconAlertCircle, IconCopy, IconCheck } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { NewCredential } from './types';

interface CredentialModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  credential: NewCredential;
}

export function CredentialModal({
  isOpen,
  onOpenChange,
  credential,
}: CredentialModalProps) {
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [copied, setCopied] = useState(false);

  const handleCopy = async () => {
    try {
      await navigator.clipboard.writeText(credential.token);
      setCopied(true);
      addToast(t('aiPrincipals.credential.copySuccess', 'Token copied to clipboard'), 'success');
      setTimeout(() => setCopied(false), 2000);
    } catch {
      addToast(t('aiPrincipals.credential.copyError', 'Failed to copy token'), 'error');
    }
  };

  const expiresAt = credential.expiresAt
    ? new Date(credential.expiresAt).toLocaleDateString()
    : t('aiPrincipals.credential.expiresUnknown', 'unknown');

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('aiPrincipals.credential.title', 'AI Principal Created')}</DialogTitle>
          <DialogDescription>
            {t(
              'aiPrincipals.credential.description',
              'Copy the token now — it will not be shown again.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4">
          <Alert>
            <IconAlertCircle className="h-4 w-4" />
            <AlertDescription>
              {t(
                'aiPrincipals.credential.warning',
                'This is the only time this token will be displayed. Store it ' +
                  'securely before closing this dialog.'
              )}
            </AlertDescription>
          </Alert>

          <div className="space-y-1">
            <p className="text-xs font-medium text-muted-foreground">
              {t('aiPrincipals.credential.name', 'Name')}
            </p>
            <p className="text-sm text-foreground">{credential.name}</p>
          </div>

          <div className="space-y-1">
            <p className="text-xs font-medium text-muted-foreground">
              {t('aiPrincipals.credential.scopes', 'Scopes')}
            </p>
            <div className="flex flex-wrap gap-1">
              {credential.scope.map((s) => (
                <span
                  key={s}
                  className="rounded-md border border-border bg-muted px-2 py-0.5 text-xs text-foreground"
                >
                  {s}
                </span>
              ))}
            </div>
          </div>

          <div className="space-y-1">
            <p className="text-xs font-medium text-muted-foreground">
              {t('aiPrincipals.credential.expires', 'Expires')}
            </p>
            <p className="text-sm text-foreground">{expiresAt}</p>
          </div>

          <div className="space-y-1">
            <p className="text-xs font-medium text-muted-foreground">
              {t('aiPrincipals.credential.token', 'Bearer token')}
            </p>
            <div className="flex items-center gap-2">
              <code className="flex-1 rounded-md border border-border bg-muted px-3 py-2 text-xs break-all text-foreground">
                {credential.token}
              </code>
              <Button
                type="button"
                variant="outline"
                size="icon-sm"
                onClick={handleCopy}
                aria-label={t('aiPrincipals.credential.copy', 'Copy token')}
              >
                {copied ? <IconCheck size={16} /> : <IconCopy size={16} />}
              </Button>
            </div>
          </div>
        </div>

        <DialogFooter>
          <Button onClick={() => onOpenChange(false)}>
            {t('aiPrincipals.credential.done', 'Done')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
