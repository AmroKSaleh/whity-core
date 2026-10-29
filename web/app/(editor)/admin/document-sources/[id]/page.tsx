'use client';

import { use } from 'react';
import { useRouter } from 'next/navigation';
import { DocumentEditorScreen } from '@amroksaleh/features/document-editor';
import { useTranslation } from '@amroksaleh/features/i18n';
import { useCapabilities } from '@/hooks/useCapabilities';
import { DOCUMENTS_WRITE } from '@/lib/capabilities';
import { documentSourcesAdapter } from '@/lib/documents/sources';

/**
 * A note or wiki page in the block editor (editor M2), full-screen in the
 * `(editor)` route group: the editor's own header carries Back. People without
 * documents:write read it; Suggesting for them comes with M5 (E.10).
 */
export default function DocumentSourcePage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const router = useRouter();
  const t = useTranslation('documents');
  const { hasPermission } = useCapabilities();

  return (
    <DocumentEditorScreen
      id={id}
      adapter={documentSourcesAdapter}
      canEdit={hasPermission(DOCUMENTS_WRITE)}
      onBack={() => router.push('/admin/document-sources')}
      path={t('blockEditor.list.title', 'Notes and wiki')}
    />
  );
}
