'use client';

import { useState } from 'react';
import { Button } from '@amroksaleh/ui/button';
import { Textarea } from '@amroksaleh/ui/textarea';
import { IconCheck, IconPencil, IconTrash, IconX } from '@tabler/icons-react';

interface EditableTranslationCellProps {
  /** The current translation text, or null when no row exists for this scope yet. */
  value: string | null;
  /** Whether the CALLER may edit/create this cell (its own scope only). */
  editable: boolean;
  /** Shown in place of an empty value. */
  placeholder: string;
  /** Create (value===null) or update (value!==null) the row. */
  onSave: (value: string) => Promise<void>;
  /** Present only when a row already exists and may be removed. */
  onDelete?: () => Promise<void>;
}

/**
 * A click-to-edit table cell for one translation row's key (system-default or
 * tenant-override column). Read-only cells (the column the caller cannot
 * write, per the System-Tenant Context asymmetry — see `page.tsx`) render as
 * plain text with no affordances.
 */
export function EditableTranslationCell({
  value,
  editable,
  placeholder,
  onSave,
  onDelete,
}: EditableTranslationCellProps) {
  const [isEditing, setIsEditing] = useState(false);
  const [draft, setDraft] = useState(value ?? '');
  const [isSaving, setIsSaving] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  if (!editable) {
    return (
      <span className="text-sm text-muted-foreground">{value ?? '—'}</span>
    );
  }

  if (isEditing) {
    return (
      <div className="flex items-start gap-2">
        <Textarea
          value={draft}
          onChange={(e) => setDraft(e.target.value)}
          rows={2}
          autoFocus
          className="min-w-[220px] text-sm"
        />
        <div className="flex flex-col gap-1">
          <Button
            type="button"
            size="icon-sm"
            variant="ghost"
            disabled={isSaving || draft.trim() === ''}
            aria-label="Save"
            onClick={() => {
              void (async () => {
                setIsSaving(true);
                try {
                  await onSave(draft.trim());
                  setIsEditing(false);
                } finally {
                  setIsSaving(false);
                }
              })();
            }}
          >
            <IconCheck size={14} />
          </Button>
          <Button
            type="button"
            size="icon-sm"
            variant="ghost"
            aria-label="Cancel"
            onClick={() => {
              setDraft(value ?? '');
              setIsEditing(false);
            }}
          >
            <IconX size={14} />
          </Button>
        </div>
      </div>
    );
  }

  return (
    <div className="group flex items-start justify-between gap-2">
      <span className="text-sm">
        {value ?? <span className="italic text-muted-foreground">{placeholder}</span>}
      </span>
      <div className="flex shrink-0 gap-1 opacity-0 transition-opacity group-hover:opacity-100">
        <Button
          type="button"
          size="icon-sm"
          variant="ghost"
          aria-label="Edit"
          onClick={() => {
            setDraft(value ?? '');
            setIsEditing(true);
          }}
        >
          <IconPencil size={14} />
        </Button>
        {value !== null && onDelete && (
          <Button
            type="button"
            size="icon-sm"
            variant="ghost"
            className="text-destructive hover:text-destructive"
            aria-label="Delete"
            disabled={isDeleting}
            onClick={() => {
              void (async () => {
                setIsDeleting(true);
                try {
                  await onDelete();
                } finally {
                  setIsDeleting(false);
                }
              })();
            }}
          >
            <IconTrash size={14} />
          </Button>
        )}
      </div>
    </div>
  );
}
