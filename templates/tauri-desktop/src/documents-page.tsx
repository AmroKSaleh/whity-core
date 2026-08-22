import * as React from "react"

import { Alert, AlertDescription } from "@amroksaleh/ui/alert"
import {
  DocumentDesignerScreen,
  type DocumentsNotifyType,
} from "@amroksaleh/features/document-designer"

import { documentsAdapter } from "./documents-composite-adapter"

/**
 * Desktop mount of the shared Document & Label Designer
 * (@amroksaleh/features/document-designer) — the same React that renders
 * /admin/documents on web, injected with the desktop adapter (the enrolled
 * remote instance over `remote_request`) instead of web's cookie-authenticated
 * one. Same Path-B shape as `roles-page.tsx`: one screen, two transports.
 *
 * FULL-BLEED, and mounted OUTSIDE PageShell by App.tsx. The designer supplies
 * its own chrome — a menu bar and toolbar, Google-Docs style — and needs the
 * whole viewport for a millimetre-accurate page flanked by two rails. That
 * mirrors web exactly, where it lives in its own `(editor)` route group with
 * no sidebar and no page header rather than inside the `(protected)` layout's
 * `max-w-7xl` column. The exit affordance is the designer's own File > Close.
 *
 * NO TRANSLATOR IS PASSED, and that is deliberate rather than an oversight.
 * The designer's ~330 strings call `useTranslation('documents')` internally,
 * which optional-chains its context and returns each call's English fallback
 * when no <LanguageProvider> is mounted — which is the case here. So the
 * screen renders in English with no per-client wiring, and mounting the
 * provider later (over `remote_request`) localises it without touching any
 * component. See `sync-i18n.ts` for the literal-map approach the older screens
 * use; this slice needs none of it.
 */
export function DocumentsPage({ onClose }: { onClose?: () => void }) {
  const [notice, setNotice] = React.useState<{ message: string; type: DocumentsNotifyType } | null>(
    null,
  )

  React.useEffect(() => {
    if (!notice) return
    const timer = window.setTimeout(() => setNotice(null), 5000)
    return () => window.clearTimeout(timer)
  }, [notice])

  const onNotify = React.useCallback(
    (message: string, type: DocumentsNotifyType) => setNotice({ message, type }),
    [],
  )

  return (
    <div className="relative h-screen overflow-hidden">
      <DocumentDesignerScreen adapter={documentsAdapter} onNotify={onNotify} onClose={onClose} />

      {/* A floating overlay rather than a banner in normal flow: the designer
          fills the viewport, so anything in flow would steal height from a
          page whose dimensions are meant to be accurate. */}
      {notice && (
        <div className="pointer-events-none fixed bottom-4 end-4 z-50 max-w-sm">
          <Alert
            variant={
              notice.type === "error"
                ? "destructive"
                : notice.type === "success"
                  ? "success"
                  : notice.type === "warning"
                    ? "warning"
                    : "info"
            }
          >
            <AlertDescription>{notice.message}</AlertDescription>
          </Alert>
        </div>
      )}
    </div>
  )
}
