import { redirect } from 'next/navigation';

/**
 * The old canvas Document designer lived here. Its templates were converted
 * into editor templates (editor M4c), which are listed on the Document
 * templates page and open in the document editor, so an old bookmark or link
 * lands there.
 */
export default function DocumentsPage() {
  redirect('/admin/document-templates');
}
