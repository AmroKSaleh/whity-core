'use client';

import Link from 'next/link';
import { useRouter } from 'next/navigation';
import { ScreenTooSmall, useViewportAtLeast } from '@/components/ui/screen-too-small';
import { Button } from '@amroksaleh/ui/button';
import { DocumentDesigner } from '@/components/documents/document-designer';

/**
 * Document & Label Designer (WC-doceditor) — a canvas editor for printable
 * labels, docs and sheets. Templates carry placeholders bound to company logos,
 * dynamic text and barcodes/QR codes.
 *
 * Lives in the `(editor)` route group, so it renders FULL-SCREEN: no app
 * sidebar and no page header, because the editor's own top bar (menu bar +
 * toolbar, Google Docs style) is the chrome — and the whole viewport is needed
 * for a millimetre-accurate page flanked by two rails. The URL is unchanged
 * (`/admin/documents`), so the nav entry and every existing link still resolve.
 *
 * Access is gated on the granular documents:read/write/publish/render
 * permissions (see CorePermissions), enforced server-side by
 * DocumentTemplatesApiHandler; the nav entry mirrors documents:read. This page
 * itself does no direct data-fetching (that lives in DocumentDesigner and its
 * supporting hooks), so there is no client-side 403 handling to add here.
 */

/**
 * Below this width the three-pane editor cannot be made honest: the rails alone
 * exceed a phone's viewport before the page itself gets any room. Gate rather
 * than degrade — see `ScreenTooSmall`.
 */
const MIN_EDITOR_WIDTH = 1024;

export default function DocumentsPage() {
  const router = useRouter();
  const wideEnough = useViewportAtLeast(MIN_EDITOR_WIDTH);

  // `undefined` until measured on the client: render neither branch for that
  // one paint, so desktop users never flash the gate and phones never mount
  // (and immediately tear down) the whole editor.
  if (wideEnough === undefined) {
    return null;
  }

  if (!wideEnough) {
    return (
      <ScreenTooSmall
        title="The document editor needs a larger screen"
        description="Designing print-accurate labels and documents needs room for the page plus its layers and properties panels. Open this on a tablet in landscape, laptop or desktop."
        minWidth={MIN_EDITOR_WIDTH}
        action={
          <Button asChild variant="outline" size="sm" className="mt-2">
            <Link href="/admin">Back to dashboard</Link>
          </Button>
        }
      />
    );
  }

  return <DocumentDesigner onClose={() => router.push('/admin')} />;
}
