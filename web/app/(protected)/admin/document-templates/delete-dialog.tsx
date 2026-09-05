'use client';

import { useState } from 'react';
import { useTranslation } from '@amroksaleh/features/i18n';
import { Alert, AlertDescription, AlertTitle } from '@amroksaleh/ui/alert';
import { Button } from '@amroksaleh/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { deleteRow, type AddToast, type ApiClient, type ResourceKind } from './api';
import type { BlockRow, BlockUsage, TemplateRow } from './types';

/** How many referencing names to spell out inline before falling back to the count. */
const NAMES_SHOWN = 5;

/**
 * Confirm a delete — and for a block, refuse to offer one until the usage
 * question has been answered.
 *
 * THE ORDER MATTERS. A block is pointer-referenced, so deleting one that
 * templates still instance would orphan those pointers. The server refuses that
 * with a 409, which is correct and also arrives too late to be useful: by then
 * the person has already decided. So the button is disabled while `total > 0`,
 * with the referencing templates named, and the 409 stays as the backstop it
 * should be rather than the user interface.
 *
 * WHEN THE USAGE COUNT IS UNKNOWN the delete is still offered but not
 * encouraged: refusing outright would strand a block nobody can remove whenever
 * one request failed, and the server's guard is still there. What is NOT done is
 * treat the missing count as zero.
 *
 * FOR A TEMPLATE the consequence worth stating is the one that is not obvious:
 * documents already issued from it are DETACHED, not deleted
 * (`documents.document_template_id` is nulled and `template_name` keeps the
 * snapshot). The whole point of storing a rendered document is that it outlives
 * its template, and somebody about to delete a template has every reason to fear
 * otherwise.
 */
export function DeleteDialog({
  kind,
  row,
  usage,
  ouName,
  apiClient,
  addToast,
  onClose,
  onDeleted,
}: {
  kind: ResourceKind;
  row: TemplateRow | BlockRow;
  usage: BlockUsage | null;
  ouName: string | null;
  apiClient: ApiClient;
  addToast: AddToast;
  onClose: () => void;
  onDeleted: () => void;
}) {
  const t = useTranslation('admin');
  const [error, setError] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);

  const blockedByUsage = kind === 'block' && usage !== null && usage.total > 0;
  const usageUnknown = kind === 'block' && usage === null;

  const submit = async () => {
    setError(null);
    setSubmitting(true);
    try {
      const failure = await deleteRow(
        apiClient,
        kind,
        row.id,
        t('documentTemplates.delete.failed', 'Failed to delete')
      );
      if (failure !== null) {
        setError(failure);
        return;
      }
      addToast(t('documentTemplates.delete.done', 'Deleted “{name}”', { name: row.name }), 'success');
      onDeleted();
    } catch {
      setError(t('documentTemplates.delete.failed', 'Failed to delete'));
    } finally {
      setSubmitting(false);
    }
  };

  // BOTH kinds of user (#1186). A block may be held by another block, and a
  // delete that named only templates would list nothing while refusing —
  // leaving the person with a 409 and no idea what is holding it.
  const users = [...(usage?.templates ?? []), ...(usage?.blocks ?? [])];
  const names = users.slice(0, NAMES_SHOWN).map((row) => row.name);
  const unnamed = (usage?.total ?? 0) - names.length;

  return (
    <Dialog open onOpenChange={(open) => !open && onClose()}>
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>
            {kind === 'template'
              ? t('documentTemplates.delete.titleTemplate', 'Delete template “{name}”?', {
                  name: row.name,
                })
              : t('documentTemplates.delete.titleBlock', 'Delete block “{name}”?', { name: row.name })}
          </DialogTitle>
          <DialogDescription>
            {ouName !== null
              ? t('documentTemplates.delete.filedAt', 'Filed at {unit}. This cannot be undone.', {
                  unit: ouName,
                })
              : t('documentTemplates.delete.notFiled', 'This cannot be undone.')}
          </DialogDescription>
        </DialogHeader>

        {kind === 'template' && (
          <Alert variant="info">
            <AlertTitle>
              {t('documentTemplates.delete.documentsSurvive', 'Documents already issued from it are kept')}
            </AlertTitle>
            <AlertDescription>
              {t(
                'documentTemplates.delete.documentsSurviveBody',
                'They are detached from this template, not deleted — each one keeps the name it was issued under and its stored file stays readable. Only the template goes.'
              )}
            </AlertDescription>
          </Alert>
        )}

        {row.is_system && (
          <Alert variant="warning">
            <AlertTitle>{t('documentTemplates.delete.starterTitle', 'This is a shipped starter')}</AlertTitle>
            <AlertDescription>
              {t(
                'documentTemplates.delete.starterBody',
                'Starters exist so nobody faces an empty designer. Deleting one removes it for the whole tenant.'
              )}
            </AlertDescription>
          </Alert>
        )}

        {blockedByUsage && (
          <Alert variant="destructive">
            <AlertTitle>
              {t('documentTemplates.delete.inUseTitle', 'Still used in {count} places', {
                count: usage.total,
              })}
            </AlertTitle>
            <AlertDescription>
              <p>
                {names.length > 0
                  ? t('documentTemplates.delete.inUseNames', 'Including: {names}.', {
                      names: names.join(', '),
                    })
                  : t(
                      'documentTemplates.delete.inUseUnnamed',
                      'None of them are ones you can see, so none can be named here.'
                    )}
                {unnamed > 0 &&
                  ' ' +
                    t('documentTemplates.delete.inUseMore', 'And {count} more not listed.', {
                      count: unnamed,
                    })}
              </p>
              <p className="mt-2">
                {t(
                  'documentTemplates.delete.inUseFix',
                  'Remove the block from those templates first. Deleting it now would leave each of them pointing at nothing, so the server refuses it anyway.'
                )}
              </p>
            </AlertDescription>
          </Alert>
        )}

        {usageUnknown && (
          <Alert variant="warning">
            <AlertTitle>{t('documentTemplates.delete.usageUnknownTitle', 'Usage is unknown')}</AlertTitle>
            <AlertDescription>
              {t(
                'documentTemplates.delete.usageUnknownBody',
                'How many templates use this block could not be read, so this delete may be refused. A blank is not a zero.'
              )}
            </AlertDescription>
          </Alert>
        )}

        {error !== null && <p className="text-sm text-destructive">{error}</p>}

        <DialogFooter>
          <Button variant="outline" onClick={onClose} disabled={submitting}>
            {t('documentTemplates.cancel', 'Cancel')}
          </Button>
          <Button
            variant="destructive"
            onClick={submit}
            loading={submitting}
            disabled={blockedByUsage}
          >
            {t('documentTemplates.delete.submit', 'Delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
