'use client';

import { useToast } from '@/lib/toast-context';
import { DocumentDesignerScreen } from '@amroksaleh/features/document-designer';
import { webDocumentsAdapter } from '@/lib/documents/storage';

/**
 * Web's mount of the shared Document & Label Designer.
 *
 * Unlike its sibling files in this directory, this is a WRAPPER rather than a
 * bare re-export, because the two things the shared screen cannot supply for
 * itself are exactly the two that are app-specific: the toast runtime (the
 * screen takes an `onNotify` callback; `useToast` throws without a
 * `ToastProvider`, so it has to be injected from inside the app) and the data
 * source (`webDocumentsAdapter`, which speaks the typed OpenAPI client — the
 * desktop client injects a `remote_request`-backed one instead).
 *
 * The `{ onClose }` signature is unchanged, so the `(editor)` route, the six
 * Storybook stories and `e2e/document-designer.spec.ts` all keep working
 * without edits.
 */
export function DocumentDesigner({ onClose }: { onClose?: () => void } = {}) {
  const { addToast } = useToast();

  return (
    <DocumentDesignerScreen adapter={webDocumentsAdapter} onNotify={addToast} onClose={onClose} />
  );
}
