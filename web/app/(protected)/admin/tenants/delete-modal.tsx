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
import { IconAlertCircle } from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
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
  const t = useTranslation('admin');
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
          errorData.message || t('tenants.delete.error', 'Failed to delete tenant')
        );
      }

      addToast(t('tenants.delete.success', 'Tenant deleted successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('tenants.delete.error', 'Failed to delete tenant');
      addToast(message, 'error');
    } finally {
      setIsDeleting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('tenants.delete.title', 'Delete Tenant')}</DialogTitle>
          <DialogDescription>
            {t(
              'tenants.delete.description',
              'Are you sure you want to delete this tenant? This action cannot be undone.'
            )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-3 py-4">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium text-foreground">
              {tenant.name}
            </div>
            <div className="text-xs text-muted-foreground">
              {/* The published contract makes `slug` nullable, so it is
                  defaulted here rather than interpolated as "null" into a
                  sentence the operator is reading before a destructive act. */}
              {t('tenants.delete.slug', 'Slug: {slug}', { slug: tenant.slug ?? '—' })}
            </div>
          </div>

          {tenant.userCount > 0 && (
            <div className="flex gap-2 rounded-lg border border-warning/50 bg-warning/10 p-3">
              <IconAlertCircle size={16} className="mt-0.5 shrink-0 text-warning" />
              {/*
                Singular and plural are separate keys rather than a count with an
                's' appended to the noun: the plural rule differs by language, and
                a sentence assembled around a suffix cannot express any of them.
              */}
              <div className="text-sm text-warning-foreground">
                {tenant.userCount === 1
                  ? t(
                      'tenants.delete.userCount.one',
                      'This tenant has 1 associated user. Deleting it may impact those users.'
                    )
                  : t(
                      'tenants.delete.userCount.other',
                      'This tenant has {count} associated users. Deleting it may impact those users.',
                      { count: tenant.userCount }
                    )}
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
            {t('tenants.delete.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            variant="destructive"
            onClick={handleDelete}
            disabled={isDeleting}
          >
            {isDeleting
              ? t('tenants.delete.submitting', 'Deleting...')
              : t('tenants.delete.submit', 'Delete Tenant')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
