'use client';

import { useState } from 'react';
import { useTranslation } from '@whity-org/features/i18n';
import { Button } from '@whity-org/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { copyTemplate, type ApiClient } from './api';
import type { TemplateRow } from './types';

/**
 * "Use a copy": asks for the copy's title, makes it, and hands the new id back
 * so the page can open it in the editor.
 *
 * The copy is a personal, unpublished draft of the caller's own, whatever the
 * original's audience was — copying somebody's shared template is not the same
 * act as sharing your version of it — so the dialog says so before the click.
 */
export function CopyDialog({
  row,
  name,
  apiClient,
  onClose,
  onCopied,
}: {
  row: TemplateRow;
  /** The row's title as the page shows it ("Untitled" for an empty one). */
  name: string;
  apiClient: ApiClient;
  onClose: () => void;
  onCopied: (id: number) => void;
}) {
  const t = useTranslation('admin');
  const [title, setTitle] = useState(() => t('documentTemplates.copy.defaultTitle', '{name} (copy)', { name }));
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const submit = async () => {
    setError(null);
    setSubmitting(true);
    try {
      const id = await copyTemplate(apiClient, row.id, title, t('documentTemplates.copy.failed', 'The copy wasn’t made. Try again.'));
      onCopied(id);
    } catch (e) {
      setError(e instanceof Error ? e.message : t('documentTemplates.copy.failed', 'The copy wasn’t made. Try again.'));
      setSubmitting(false);
    }
  };

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t('documentTemplates.copy.title', 'Use a copy of “{name}”', { name })}</DialogTitle>
          <DialogDescription>
            {t(
              'documentTemplates.copy.description',
              'The copy is your own draft: only you see it until you publish and share it. The original stays as it is.'
            )}
          </DialogDescription>
        </DialogHeader>

        <form
          className="space-y-1.5"
          onSubmit={(e) => {
            e.preventDefault();
            void submit();
          }}
        >
          <label className="text-sm font-medium" htmlFor="copy-title">
            {t('documentTemplates.copy.label', 'Title of the copy')}
          </label>
          <Input id="copy-title" dir="auto" autoFocus value={title} onChange={(e) => setTitle(e.target.value)} />
        </form>

        {error !== null && <p className="text-sm text-destructive">{error}</p>}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
          <Button onClick={() => void submit()} loading={submitting}>
            {t('documentTemplates.copy.submit', 'Make a copy')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
