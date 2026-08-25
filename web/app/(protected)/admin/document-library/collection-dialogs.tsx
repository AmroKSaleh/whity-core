'use client';

import { useTranslation } from '@amroksaleh/features/i18n';
import { Button } from '@amroksaleh/ui/button';
import { Checkbox } from '@amroksaleh/ui/checkbox';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { IconPlus } from '@tabler/icons-react';
import type { DocumentCollection, DocumentRow } from './types';

/**
 * Everything that can be DONE to a collection.
 *
 * #987 shipped a `collection` view and a create dialog, so a person could make a
 * pile and open it and nothing else: no rename, no way to put a document in one
 * except the star, and no way to take it out again. This file is the rest of
 * that surface, and the whole of it was already on the API — create, rename,
 * delete, file, unfile — which is worth stating because it means none of this
 * needed a new endpoint or a new permission.
 *
 * WHY A COLLECTION IS THE ONLY THING ON THIS SCREEN THAT IS STORED
 * ---------------------------------------------------------------
 * Every folder in the rail is a query. A collection is not: it is a row somebody
 * wrote. That is allowed here, and only here, because it claims nothing about
 * where a document LIVES — "I care about this one" is a fact about the person,
 * not about the organisation, so it cannot go stale when the organisation
 * changes and it cannot contradict another unit's equally correct view. Every
 * dialog below says some version of that out loud, because a control called
 * "add to collection" beside a folder-shaped rail will otherwise be read as
 * moving a file.
 */

const MAX_NAME_LENGTH = 160;

/** Create. The name is bounded to match `document_collections.name` (migration 114). */
export function CreateCollectionDialog({
  open,
  onOpenChange,
  name,
  onNameChange,
  busy,
  onSubmit,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  name: string;
  onNameChange: (name: string) => void;
  busy: boolean;
  onSubmit: () => void;
}) {
  const t = useTranslation('documents');

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('organizer.collection.newTitle', 'New collection')}</DialogTitle>
          <DialogDescription>
            {t(
              'organizer.collection.newHelp',
              'A collection is yours alone. Filing a document says nothing about where it lives, and nobody else can see your collections.'
            )}
          </DialogDescription>
        </DialogHeader>
        <Input
          label={t('organizer.collection.name', 'Name')}
          value={name}
          maxLength={MAX_NAME_LENGTH}
          onChange={(event) => onNameChange(event.target.value)}
        />
        <DialogFooter>
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('organizer.cancel', 'Cancel')}
          </Button>
          <Button disabled={busy || name.trim() === ''} onClick={onSubmit}>
            {t('organizer.collection.create', 'Create')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * Rename.
 *
 * Only reachable for a collection the person made: the API refuses a rename on a
 * keyed one (409), and the toolbar's menu is therefore DISABLED with that reason
 * rather than opening a dialog that would fail. Two places, one rule — the
 * server's, which is the one that is enforced.
 */
export function RenameCollectionDialog({
  collection,
  onClose,
  name,
  onNameChange,
  busy,
  onSubmit,
}: {
  collection: DocumentCollection | null;
  onClose: () => void;
  name: string;
  onNameChange: (name: string) => void;
  busy: boolean;
  onSubmit: () => void;
}) {
  const t = useTranslation('documents');

  return (
    <Dialog open={collection !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('organizer.collection.renameTitle', 'Rename this collection')}</DialogTitle>
          <DialogDescription>
            {t(
              'organizer.collection.renameHelp',
              'Only the name changes. The documents in it are untouched, and nobody else ever saw the old name.'
            )}
          </DialogDescription>
        </DialogHeader>
        <Input
          label={t('organizer.collection.name', 'Name')}
          value={name}
          maxLength={MAX_NAME_LENGTH}
          onChange={(event) => onNameChange(event.target.value)}
        />
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('organizer.cancel', 'Cancel')}
          </Button>
          <Button
            disabled={busy || name.trim() === '' || name.trim() === collection?.name}
            onClick={onSubmit}
          >
            {t('organizer.collection.saveName', 'Rename')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/** Delete. Nothing is destroyed except an opinion, and the copy says so. */
export function DeleteCollectionDialog({
  collection,
  onClose,
  busy,
  onSubmit,
}: {
  collection: DocumentCollection | null;
  onClose: () => void;
  busy: boolean;
  onSubmit: () => void;
}) {
  const t = useTranslation('documents');

  return (
    <Dialog open={collection !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('organizer.collection.deleteTitle', 'Delete this collection?')}</DialogTitle>
          <DialogDescription>
            {t(
              'organizer.collection.deleteHelp',
              'The documents in it are untouched — a collection holds pointers, not files. Only your own grouping is removed.'
            )}
          </DialogDescription>
        </DialogHeader>
        <DialogFooter>
          <Button variant="outline" onClick={onClose}>
            {t('organizer.cancel', 'Cancel')}
          </Button>
          <Button variant="destructive" disabled={busy} onClick={onSubmit}>
            {t('organizer.collection.confirmDelete', 'Delete')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

/**
 * File one document into any of the caller's collections.
 *
 * WHY IT WRITES ON EACH TOGGLE INSTEAD OF ON A SAVE BUTTON
 * -------------------------------------------------------
 * Both endpoints are idempotent and each handles exactly one (collection,
 * document) pair, so there is no transaction to batch into. A save button would
 * collect N changes and then issue N requests, and a half-failed batch would
 * have to explain WHICH of them failed — in this dialog, next to the checkboxes.
 * Writing per toggle puts the failure where the click was, and the checkbox
 * state comes back from the server's read-back rather than from optimism.
 *
 * WHY THE STARRED COLLECTION IS NOT IN THE LIST
 * ---------------------------------------------
 * It is a collection like any other, and it already has a control: the star on
 * the row. Listing it here too would give one thing two affordances that can
 * disagree on screen — the same reason the rail does not repeat it as a pile.
 */
export function FileIntoCollectionDialog({
  documentRow,
  onClose,
  collections,
  busy,
  onToggle,
  onCreateNew,
}: {
  /** Named `documentRow`, not `document`: shadowing the global would be a trap for the next editor. */
  documentRow: DocumentRow | null;
  onClose: () => void;
  collections: DocumentCollection[];
  busy: boolean;
  onToggle: (collectionId: number, next: boolean) => void;
  onCreateNew: () => void;
}) {
  const t = useTranslation('documents');

  // Keyed collections are excluded: see the note above.
  const own = collections.filter((collection) => collection.system_key === null);
  const filedIn = documentRow?.collection_ids ?? [];

  return (
    <Dialog open={documentRow !== null} onOpenChange={(open) => !open && onClose()}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('organizer.file.title', 'Add to a collection')}</DialogTitle>
          <DialogDescription>
            {t(
              'organizer.file.help',
              'A collection is a list of your own. Adding a document does not move it or change who else can see it, and removing it deletes nothing.'
            )}
          </DialogDescription>
        </DialogHeader>

        {/* The document being filed, named in a bidi isolate — it is tenant text
            in an unknown script. */}
        {documentRow && (
          <p dir="auto" className="truncate text-sm font-medium">
            {documentRow.title}
          </p>
        )}

        {own.length === 0 ? (
          // Not an empty checkbox list: there is nothing to check, and the next
          // action is to make one. #756 — say what is true rather than render an
          // empty control.
          <p className="text-sm text-muted-foreground">
            {t(
              'organizer.file.none',
              'You have no collections yet. Create one and this document goes straight into it.'
            )}
          </p>
        ) : (
          <ul className="max-h-64 space-y-1 overflow-y-auto">
            {own.map((collection) => {
              const checked = filedIn.includes(collection.id);
              return (
                <li key={collection.id}>
                  <label className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent/60">
                    <Checkbox
                      checked={checked}
                      disabled={busy}
                      onCheckedChange={() => onToggle(collection.id, !checked)}
                    />
                    <span dir="auto" className="flex-1 truncate">
                      {collection.name}
                    </span>
                    <span className="text-xs text-muted-foreground">{collection.item_count ?? 0}</span>
                  </label>
                </li>
              );
            })}
          </ul>
        )}

        <DialogFooter>
          <Button variant="ghost" onClick={onCreateNew}>
            <IconPlus size={14} className="me-2" aria-hidden />
            {t('organizer.collection.new', 'New collection')}
          </Button>
          <Button variant="outline" onClick={onClose}>
            {t('organizer.done', 'Done')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
