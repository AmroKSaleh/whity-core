'use client';

import { AdminHeader } from '@/components/admin/admin-header';
import { DocumentDesigner } from '@/components/documents/document-designer';

/**
 * Document & Label Designer (WC-doceditor) — a canvas editor for printable
 * labels, docs and sheets. Templates carry placeholders bound to company logos,
 * dynamic text and barcodes/QR codes.
 *
 * MVP: any authenticated user; templates persist to the browser + JSON
 * export/import. TODO(WC-doceditor / backend): a tenant-scoped `document_templates`
 * table + API and a `documents:manage` permission gate.
 */
export default function DocumentsPage() {
  return (
    <div className="space-y-6">
      <AdminHeader
        title="Document & Label Designer"
        description="Design printable labels, documents and sheets — with placeholders for logos, dynamic text and barcodes/QR codes."
      />
      <DocumentDesigner />
    </div>
  );
}
