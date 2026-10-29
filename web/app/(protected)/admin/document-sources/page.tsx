'use client';

import { useCallback, useState } from 'react';
import Link from 'next/link';
import { useRouter } from 'next/navigation';
import type { DocumentKind } from '@whity-org/ui/editor/contract';
import { useTranslation } from '@whity-org/features/i18n';
import { useDateDisplay } from '@whity-org/features/datetime';
import { Badge } from '@whity-org/ui/badge';
import { Button } from '@whity-org/ui/button';
import { EmptyState, ErrorState } from '@whity-org/ui/empty-state';
import { Skeleton } from '@whity-org/ui/skeleton';
import { AdminHeader } from '@/components/admin/admin-header';
import { useCapabilities } from '@/hooks/useCapabilities';
import { useFetch } from '@/hooks/useFetch';
import { DOCUMENTS_WRITE } from '@/lib/capabilities';
import { useToast } from '@/lib/toast-context';
import { createDocumentSource, listDocumentSources } from '@/lib/documents/sources';

/**
 * Notes and wiki pages written in the block editor (editor M2). Templates and
 * the Document designer move onto the same editor in M4; until then this list
 * is where a person starts a note or a wiki page and finds the ones they wrote
 * or that were shared with them. Rows are filtered server-side
 * (DocumentAccessPolicy).
 */
export default function DocumentSourcesPage() {
  const t = useTranslation('documents');
  const router = useRouter();
  const { addToast } = useToast();
  const dates = useDateDisplay();
  const { hasPermission } = useCapabilities();
  const canWrite = hasPermission(DOCUMENTS_WRITE);
  const [creating, setCreating] = useState<DocumentKind | null>(null);

  const load = useCallback(() => listDocumentSources(), []);
  const { data: rows, loading, error, refetch } = useFetch(load);

  const kindLabel: Record<string, string> = {
    note: t('blockEditor.list.kindNote', 'Note'),
    wiki: t('blockEditor.list.kindWiki', 'Wiki page'),
    template: t('blockEditor.list.kindTemplate', 'Template'),
    letter: t('blockEditor.list.kindLetter', 'Letter'),
    report: t('blockEditor.list.kindReport', 'Report'),
    label: t('blockEditor.list.kindLabel', 'Label sheet'),
  };

  const create = async (kind: DocumentKind) => {
    setCreating(kind);
    try {
      const row = await createDocumentSource(kind, '');
      router.push(`/admin/document-sources/${row.id}`);
    } catch {
      addToast(t('blockEditor.list.createFailed', 'The document wasn’t created. Try again.'), 'error');
      setCreating(null);
    }
  };

  return (
    <div className="grid gap-6">
      <AdminHeader
        title={t('blockEditor.list.title', 'Notes and wiki')}
        description={t(
          'blockEditor.list.description',
          'Notes and wiki pages written in the document editor. They save as you type; only you see a new one until you share it.'
        )}
        action={
          canWrite ? (
            <div className="flex gap-2">
              <Button variant="outline" icon="world" loading={creating === 'wiki'} disabled={creating !== null} onClick={() => void create('wiki')}>
                {t('blockEditor.list.newWiki', 'New wiki page')}
              </Button>
              <Button icon="plus" loading={creating === 'note'} disabled={creating !== null} onClick={() => void create('note')}>
                {t('blockEditor.list.newNote', 'New note')}
              </Button>
            </div>
          ) : undefined
        }
      />

      {loading ? (
        <div className="grid gap-2" aria-busy="true">
          {[0, 1, 2].map((i) => (
            <Skeleton key={i} className="h-11 rounded-card" />
          ))}
        </div>
      ) : error ? (
        <ErrorState
          title={t('blockEditor.list.loadFailed', 'The documents didn’t load')}
          action={
            <Button variant="outline" onClick={() => void refetch()}>
              {t('blockEditor.list.retry', 'Try again')}
            </Button>
          }
        />
      ) : !rows || rows.length === 0 ? (
        <EmptyState
          icon={<span aria-hidden="true" />}
          title={t('blockEditor.list.emptyTitle', 'No notes or wiki pages yet')}
          description={
            canWrite
              ? t('blockEditor.list.emptyWriter', 'Start one with New note.')
              : t('blockEditor.list.emptyReader', 'Notes and wiki pages shared with you appear here.')
          }
        />
      ) : (
        <ul className="m-0 grid list-none divide-y divide-divider rounded-card border border-border bg-card p-0">
          {rows.map((row) => (
            <li key={row.id}>
              <Link
                href={`/admin/document-sources/${row.id}`}
                className="flex min-h-11 items-center gap-3 px-4 py-2 text-foreground no-underline hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-ring"
              >
                <span className="min-w-0 flex-1 truncate font-medium" dir="auto">
                  {row.title || t('blockEditor.untitled', 'Untitled')}
                </span>
                <Badge variant="secondary">{kindLabel[row.kind] ?? row.kind}</Badge>
                <span className="shrink-0 text-xs text-muted-foreground">{dates.dateTime(row.updated_at)}</span>
              </Link>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}
