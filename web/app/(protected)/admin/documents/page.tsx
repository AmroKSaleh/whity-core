'use client';

import { AdminHeader } from '@/components/admin/admin-header';
import { DocumentDesigner } from '@/components/documents/document-designer';

/**
 * Document & Label Designer (WC-doceditor) — a canvas editor for printable
 * labels, docs and sheets. Templates carry placeholders bound to company logos,
 * dynamic text and barcodes/QR codes.
 *
 * Access is gated on the granular documents:read/write/publish/render
 * permissions (see CorePermissions), enforced server-side by
 * DocumentTemplatesApiHandler; the nav entry mirrors documents:read. This page
 * itself does no direct data-fetching (that lives in DocumentDesigner and its
 * supporting hooks), so there is no client-side 403 handling to add here.
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
