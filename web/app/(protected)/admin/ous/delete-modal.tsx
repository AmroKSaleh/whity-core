'use client';

import { useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { IconAlertTriangle } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { OU } from './types';

interface DeleteOuModalProps {
  isOpen: boolean;
  onClose: () => void;
  onSuccess: () => void;
  ou: OU;
}

export function DeleteOuModal({ isOpen, onClose, onSuccess, ou }: DeleteOuModalProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const [isLoading, setIsLoading] = useState(false);

  const handleDelete = async () => {
    try {
      setIsLoading(true);
      const response = await apiClient(`/api/v1/ous/${ou.id}`, {
        method: 'DELETE',
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(
          error.error || t('ous.delete.error', 'Failed to delete organizational unit')
        );
      }

      addToast(t('ous.delete.success', 'Organizational unit deleted successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('ous.delete.error', 'Failed to delete organizational unit');
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle className="flex items-center gap-2">
            <IconAlertTriangle className="text-destructive" size={24} />
            {t('ous.delete.title', 'Delete Organizational Unit')}
          </DialogTitle>
          <DialogDescription>
            {t('ous.delete.subtitle', 'Are you sure you want to delete "{name}"?', {
              name: ou.name,
            })}
          </DialogDescription>
        </DialogHeader>

        <div className="bg-destructive/10 rounded-lg p-4 text-sm text-destructive">
          <p className="font-medium">{t('ous.delete.warning', 'Warning:')}</p>
          <ul className="mt-2 list-inside list-disc space-y-1">
            <li>{t('ous.delete.consequence.irreversible', 'This action cannot be undone')}</li>
            <li>
              {t(
                'ous.delete.consequence.roles',
                'Users assigned to this OU will no longer inherit its roles'
              )}
            </li>
            <li>
              {t('ous.delete.consequence.children', 'Child OUs cannot have this OU as parent')}
            </li>
          </ul>
        </div>

        <div className="flex justify-end gap-3">
          <Button
            variant="outline"
            onClick={onClose}
            disabled={isLoading}
          >
            {t('ous.delete.cancel', 'Cancel')}
          </Button>
          <Button
            variant="destructive"
            onClick={handleDelete}
            disabled={isLoading}
          >
            {isLoading
              ? t('ous.delete.submitting', 'Deleting...')
              : t('ous.delete.submit', 'Delete')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
