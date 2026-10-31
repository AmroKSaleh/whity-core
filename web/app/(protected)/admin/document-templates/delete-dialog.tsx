'use client';

import { useState } from 'react';
import { useTranslation } from '@whity-org/features/i18n';
import { Alert, AlertDescription, AlertTitle } from '@whity-org/ui/alert';
import { Button } from '@whity-org/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { deleteTemplate, type AddToast, type ApiClient } from './api';
import type { TemplateRow } from './types';

/**
 * Confirm deleting a template or label sheet.
 *
 * The consequence worth stating is the one that is not obvious: documents
 * already issued from it are kept. Each issued document stores what it was
 * issued from and its own file, so it outlives the template, and somebody about
 * to delete one has every reason to fear otherwise.
 */
export function DeleteDialog({
  row,
  name,
  ouName,
  apiClient,
  addToast,
  onClose,
  onDeleted,
}: {
  row: TemplateRow;
  /** The row's title as the page shows it ("Untitled" for an empty one). */
  name: string;
  ouName: string | null;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onDeleted: () => void;
}) {
  const t = useTranslation('admin');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    setError(null);
    setSubmitting(true);
    try {
      const failure = await deleteTemplate(apiClient, row.id, t('documentTemplates.delete.failed', 'Failed to delete'));
      if (failure !== null) {
        setError(failure);
        return;
      }
      addToast(t('documentTemplates.delete.done', 'Deleted “{name}”', { name }), 'success');
      onDeleted();
    } catch {
      setError(t('documentTemplates.delete.failed', 'Failed to delete'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {row.kind === 'label'
              ? t('documentTemplates.delete.titleLabel', 'Delete label sheet “{name}”?', { name })
              : t('documentTemplates.delete.titleTemplate', 'Delete template “{name}”?', { name })}
          </DialogTitle>
          <DialogDescription>
            {ouName !== null
              ? t('documentTemplates.delete.filedAt', 'Filed at {unit}. This cannot be undone.', { unit: ouName })
              : t('documentTemplates.delete.notFiled', 'This cannot be undone.')}
          </DialogDescription>
        </DialogHeader>

        <Alert variant="info">
          <AlertTitle>{t('documentTemplates.delete.documentsSurvive', 'Documents already issued from it are kept')}</AlertTitle>
          <AlertDescription>
            {t(
              'documentTemplates.delete.documentsKept',
              'Each one keeps the name it was issued under and its stored file stays readable. Only the template goes.'
            )}
          </AlertDescription>
        </Alert>

        {row.starter_key !== null && (
          <Alert variant="warning">
            <AlertTitle>{t('documentTemplates.delete.whityStarterTitle', 'This is a Whity starter')}</AlertTitle>
            <AlertDescription>
              {t(
                'documentTemplates.delete.whityStarterBody',
                'Deleting it removes it for everyone who can see it. To change a starter, use a copy instead.'
              )}
            </AlertDescription>
          </Alert>
        )}

        {error !== null && <p className="text-sm text-destructive">{error}</p>}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
          <Button variant="destructive" onClick={submit} loading={submitting}>
            {t('documentTemplates.delete.submit', 'Delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
