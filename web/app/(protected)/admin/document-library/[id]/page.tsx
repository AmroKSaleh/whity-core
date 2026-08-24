'use client';

/**
 * The document record ROUTE — `/admin/document-library/[id]` (#993).
 *
 * WHY IT LIVES UNDER `document-library` AND NOT UNDER `documents`.
 * `/admin/documents` is already taken, by the full-screen template DESIGNER in
 * the `(editor)` route group — the nav labels it "Document Designer" and it
 * renders without a sidebar. `/admin/documents/[id]` would technically not
 * collide, and would still be wrong twice over: it would put the record of an
 * ISSUED document under the path of the tool that authors templates, and it
 * would split one `admin/documents` directory across two route groups whose
 * layouts differ. This record belongs to the organizer that lists it — same
 * group, same sidebar, same padded column — so it is that list's child.
 *
 * Thin, like every other route in this app: it owns web's provider seams only —
 * the dynamic segment, the router — and hands them to the screen.
 *
 * NO CAPABILITY GATE HERE, and its absence is the design (#910/#975). This route
 * used to be the place a `useCapabilities().loading` guard would go, because
 * `hasPermission` is FAIL-CLOSED while that fetch is in flight and mounting
 * early would render a refusal to somebody who is not refused. The screen takes
 * no `can` prop at all: `GET /documents/{id}` carries a verdict per REGION,
 * resolved server-side, so the record's own response is the whole answer and
 * there is nothing left to wait on. The page paints as soon as the record
 * arrives rather than after two round trips.
 */

import { useCallback } from 'react';
import { useParams, useRouter } from 'next/navigation';
import { useTranslation } from '@amroksaleh/features/i18n';

import { DocumentRecordScreen } from './record-screen';

export default function Page() {
  const params = useParams<{ id: string | string[] }>();
  const router = useRouter();
  const t = useTranslation('documents');

  // Client pages read dynamic segments via useParams (Next 16 app router). The
  // single [id] segment is always a string, but the hook's honest type allows
  // string[] for catch-alls, so narrow defensively — the same guard the other
  // record routes carry.
  //
  // This is also the whole of the deep-link story: the page is rebuilt from the
  // ADDRESS and nothing else, so a hard reload and a pasted link reach exactly
  // the same screen as a click from the list. There is no state handed over by
  // the navigation that a reload could lose.
  const rawId = Array.isArray(params.id) ? params.id[0] : params.id;
  const documentId = Number(rawId);

  const handleBack = useCallback(() => {
    // push, not back(): a record reached from a pasted link has no history entry
    // to go back TO, and `back()` there leaves the reader wherever they came
    // from — which may be another site.
    router.push('/admin/document-library');
  }, [router]);

  // A non-numeric segment never reaches a fetch. The route pattern admits it
  // (Next does not constrain dynamic segments) and `Number('abc')` is NaN, which
  // would become a request for `/api/v1/documents/NaN`.
  if (!Number.isInteger(documentId) || documentId <= 0) {
    return (
      <p className="text-sm text-muted-foreground" data-testid="document-record-bad-id">
        {t('record.error.title', 'This document could not be loaded')}
      </p>
    );
  }

  return <DocumentRecordScreen documentId={documentId} onBack={handleBack} />;
}
