'use client';

/**
 * New document: choose the published template to issue from (editor M4c).
 *
 * The dialog only chooses. Issuing happens in the editor, opened in issuing
 * mode on the template (`/admin/document-sources/{id}?issue=1`): the published
 * version with the record's values showing, the template's own fields, and
 * Issue — so the person sees what they are issuing, and can "Edit before
 * issuing" when this one needs a change. A form here would issue blind.
 *
 * The list is the caller's published templates, already row-filtered by the
 * server (`DocumentAccessPolicy`); nothing is filtered again here.
 */

import { IconFileText } from '@tabler/icons-react';
import { EmptyState } from '@whity-org/ui/empty-state';
import { Spinner } from '@whity-org/ui/spinner';
import { useTranslation } from '@whity-org/features/i18n';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';

/** A published editor template, as the chooser needs it. */
export interface CreatableTemplate {
  id: number;
  title: string;
  /** The record type it is filled from, or null when it needs no record. */
  recordType: string | null;
  /** That record type's name ("Invoice"), when the host could read it. */
  recordTypeLabel?: string | null;
}

export interface CreateDocumentDialogProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  /** The published templates this caller may issue from — already filtered server-side. */
  templates: CreatableTemplate[];
  templatesLoading: boolean;
  /** Why `templates` could not be read, when that is the reason it is empty. */
  templatesError: string | null;
  /** A template was chosen: open it in issuing mode. */
  onChoose: (template: CreatableTemplate) => void;
}

export function CreateDocumentDialog({
  open,
  onOpenChange,
  templates,
  templatesLoading,
  templatesError,
  onChoose,
}: CreateDocumentDialogProps) {
  const t = useTranslation('documents');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-md">
        <DialogHeader>
          <DialogTitle>{t('create.title', 'New document')}</DialogTitle>
          <DialogDescription>
            {t(
              'create.chooseDescription',
              'Choose the template to issue from. It opens in the editor with the record’s values, so you see what you issue.'
            )}
          </DialogDescription>
        </DialogHeader>

        {templatesLoading ? (
          <div className="flex justify-center py-8">
            <Spinner />
          </div>
        ) : templatesError !== null ? (
          <EmptyState
            variant="error"
            title={t('create.templates.errorTitle', 'Templates could not be loaded')}
            description={templatesError}
          />
        ) : templates.length === 0 ? (
          // No list at all, rather than an empty one. The reason is actionable.
          <EmptyState
            title={t('create.templates.emptyTitle', 'No templates available')}
            description={t(
              'create.templates.nonePublished',
              'A document is issued from a published template, and none are available to you. Ask whoever manages document templates to publish one.'
            )}
          />
        ) : (
          <ul className="m-0 grid list-none gap-1 p-0" aria-label={t('create.template.label', 'Template')}>
            {templates.map((tpl) => (
              <li key={tpl.id}>
                <button
                  type="button"
                  onClick={() => onChoose(tpl)}
                  className="flex w-full items-center gap-3 rounded-control px-2.5 py-2 text-start hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring"
                >
                  <span
                    aria-hidden="true"
                    className="grid size-8 shrink-0 place-items-center rounded-[8px] bg-primary-tint text-primary-tint-foreground"
                  >
                    <IconFileText className="size-4" />
                  </span>
                  <span className="grid min-w-0 gap-px">
                    <span className="truncate text-sm font-medium" dir="auto">
                      {tpl.title}
                    </span>
                    <span className="text-xs text-muted-foreground">
                      {tpl.recordType
                        ? t('create.record.label', 'Record: {type}', { type: tpl.recordTypeLabel ?? tpl.recordType })
                        : t('create.template.noRecord', 'No record needed')}
                    </span>
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </DialogContent>
    </Dialog>
  );
}
