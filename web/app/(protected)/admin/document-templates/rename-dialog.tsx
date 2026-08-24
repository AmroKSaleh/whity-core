'use client';

import { useState } from 'react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { patchRow, type AddToast, type ApiClient, type ResourceKind } from './api';
import type { BlockRow, TemplateRow } from './types';

/**
 * Rename a template or a block.
 *
 * The name is the only field here on purpose: it is the one attribute that
 * changes nobody's access, so it is the one write an ordinary `documents:write`
 * holder can make without a publish decision. Everything that moves the
 * audience lives in ./scope-dialog.tsx behind `documents:publish`.
 *
 * A renamed STARTER keeps its identity: `starter_key` is what the seeder matches
 * on, not the name, so renaming a shipped starter does not cause it to be
 * re-seeded as a duplicate. Worth knowing, and worth saying on screen, because
 * the opposite assumption is the natural one.
 */
export function RenameDialog({
  kind,
  row,
  apiClient,
  addToast,
  onClose,
  onSaved,
}: {
  kind: ResourceKind;
  row: TemplateRow | BlockRow;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onSaved: () => void;
}) {
  const t = useTranslation('admin');
  const [name, setName] = useState(row.name);
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    const trimmed = name.trim();
    if (trimmed === '') {
      setError(t('documentTemplates.rename.empty', 'A name is required.'));
      return;
    }
    if (trimmed === row.name) {
      onClose();
      return;
    }

    setError(null);
    setSubmitting(true);
    try {
      const failure = await patchRow(
        apiClient,
        kind,
        row.id,
        { name: trimmed },
        t('documentTemplates.rename.failed', 'Failed to rename')
      );
      if (failure !== null) {
        setError(failure);
        return;
      }
      addToast(t('documentTemplates.rename.saved', 'Renamed to {name}', { name: trimmed }), 'success');
      onSaved();
    } catch {
      setError(t('documentTemplates.rename.failed', 'Failed to rename'));
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {kind === 'template'
              ? t('documentTemplates.rename.titleTemplate', 'Rename template')
              : t('documentTemplates.rename.titleBlock', 'Rename block')}
          </DialogTitle>
          <DialogDescription>
            {row.is_system
              ? t(
                  'documentTemplates.rename.starterNote',
                  'This is a shipped starter. Renaming it is safe — the seeder recognises starters by a stable key, not by name, so it will not come back as a duplicate.'
                )
              : t(
                  'documentTemplates.rename.note',
                  'The name is a label only. It changes nothing about who can see this.'
                )}
          </DialogDescription>
        </DialogHeader>

        <Input
          label={t('documentTemplates.rename.label', 'Name')}
          value={name}
          onChange={(event) => setName(event.target.value)}
          errorText={error ?? undefined}
          required
          autoFocus
        />

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
          <Button onClick={submit} loading={submitting}>
            {t('documentTemplates.rename.submit', 'Rename')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
