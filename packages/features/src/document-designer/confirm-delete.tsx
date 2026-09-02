import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
} from '@amroksaleh/ui/alert-dialog';
import { useTranslation } from '../i18n';

/**
 * The designer's "are you sure" for the two actions that destroy something on
 * the server.
 *
 * BOTH DELETES USED TO FIRE ON THE FIRST CLICK. `File ▸ Delete this saved
 * template` and the palette's small × both went straight to the API. There is
 * no undo for either — the row is gone, and the designer's undo stack only ever
 * held document edits — so a mis-click on a menu item next to "Open saved", or
 * on an × six pixels from a scope dropdown, was final.
 *
 * The dialog exists as much to SAY WHAT WILL BE LOST as to add a step. A block
 * can be shared with the whole tenant and a template can be someone's daily
 * form; the designer knew both facts and mentioned neither. `consequence` is
 * where that goes.
 *
 * `AlertDialog` rather than `Dialog`, deliberately: it takes focus, traps it,
 * and has no dismiss-by-clicking-outside, which is the correct behaviour for a
 * question whose wrong answer cannot be taken back.
 */
export function ConfirmDelete({
  open,
  onOpenChange,
  title,
  body,
  consequence,
  confirmLabel,
  onConfirm,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  body: string;
  /** Who else this affects, when it affects anybody. Omitted when it does not. */
  consequence?: string | null;
  confirmLabel: string;
  onConfirm: () => void;
}) {
  const t = useTranslation('documents');

  return (
    <AlertDialog open={open} onOpenChange={onOpenChange}>
      <AlertDialogContent data-testid="doc-confirm-delete">
        <AlertDialogHeader>
          <AlertDialogTitle>{title}</AlertDialogTitle>
          <AlertDialogDescription>{body}</AlertDialogDescription>
        </AlertDialogHeader>
        {consequence ? (
          <p
            className="rounded-md bg-destructive/10 px-3 py-2 text-sm text-destructive"
            data-testid="doc-confirm-consequence"
          >
            {consequence}
          </p>
        ) : null}
        <AlertDialogFooter>
          <AlertDialogCancel data-testid="doc-confirm-cancel">
            {t('confirm.cancel', 'Cancel')}
          </AlertDialogCancel>
          <AlertDialogAction
            data-testid="doc-confirm-accept"
            onClick={onConfirm}
            className="bg-destructive text-white hover:bg-destructive/90"
          >
            {confirmLabel}
          </AlertDialogAction>
        </AlertDialogFooter>
      </AlertDialogContent>
    </AlertDialog>
  );
}
