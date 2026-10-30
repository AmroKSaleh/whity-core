'use client';

import * as React from 'react';

import { Button } from '@whity-org/ui/button';
import { DocEditor, type SaveState } from '@whity-org/ui/editor/doc-editor';
import type { EditorMode, WhityDocument } from '@whity-org/ui/editor/contract';
import type { EditorServices } from '@whity-org/ui/editor/services';
import { useTranslation } from '../i18n';
import { useEditorLabels } from './editor-labels';
import type { DocumentEditorAdapter } from './types';

/** How long typing has to pause before the draft saves. */
const SAVE_DELAY_MS = 800;
/** After a failed save, wait this long, doubling up to a minute, before trying again. */
const RETRY_FIRST_MS = 2000;
const RETRY_MAX_MS = 60000;

function time(d: Date): string {
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

/**
 * The block editor on a stored document (editor M2): loads it, autosaves the
 * draft a moment after typing stops, and shows the save state in the header.
 *
 * Saves are optimistic (DocumentSourcesApiHandler): each one names the version
 * it was made against. When someone else saved in between, nothing is
 * overwritten: saving stops, the person's edits stay on screen, and a bar says
 * what happened with one way forward (load the newer version). Live merging of
 * concurrent edits comes with collaboration (E.3).
 */
export function DocumentEditorScreen({
  id,
  adapter,
  canEdit,
  onBack,
  path,
  onCommandPalette,
}: {
  id: string;
  adapter: DocumentEditorAdapter;
  canEdit: boolean;
  onBack?: () => void;
  path?: string;
  onCommandPalette?: () => void;
}) {
  const t = useTranslation('documents');
  const labels = useEditorLabels();
  const [doc, setDoc] = React.useState<WhityDocument | null>(null);
  const [loadError, setLoadError] = React.useState(false);
  const [save, setSave] = React.useState<SaveState | undefined>(undefined);
  const [conflict, setConflict] = React.useState(false);
  const [mode, setMode] = React.useState<EditorMode>('editing');
  const [loadCount, setLoadCount] = React.useState(0);
  const [services, setServices] = React.useState<EditorServices | undefined>(undefined);

  const version = React.useRef(0);
  const pending = React.useRef<WhityDocument | null>(null);
  const timer = React.useRef<ReturnType<typeof setTimeout> | undefined>(undefined);
  const saving = React.useRef(false);
  const conflictRef = React.useRef(false);
  const retryDelay = React.useRef(RETRY_FIRST_MS);

  React.useEffect(() => {
    let live = true;
    setDoc(null);
    setLoadError(false);
    adapter
      .load(id)
      .then((loaded) => {
        if (!live) return;
        version.current = loaded.version;
        conflictRef.current = false;
        setConflict(false);
        setDoc(loaded.document);
        setSave(undefined);
      })
      .catch(() => live && setLoadError(true));
    return () => {
      live = false;
    };
  }, [adapter, id, loadCount]);

  React.useEffect(() => {
    // A failure leaves the rich blocks without live data; the text still loads and saves.
    let live = true;
    setServices(undefined);
    adapter.loadServices?.(id).then(
      (next) => live && setServices(next),
      () => live && setServices({}),
    );
    return () => {
      live = false;
    };
  }, [adapter, id]);

  const flush = React.useCallback(async () => {
    const next = pending.current;
    if (!next || saving.current || conflictRef.current) return;
    pending.current = null;
    saving.current = true;
    setSave({ state: 'saving' });
    try {
      const result = await adapter.save(id, { title: next.title, blocks: next.blocks }, version.current);
      retryDelay.current = RETRY_FIRST_MS;
      if (result.ok) {
        version.current = result.version;
        setSave({ state: 'saved', at: time(new Date()) });
      } else {
        conflictRef.current = true;
        setConflict(true);
        setSave({ state: 'error' });
      }
    } catch {
      // Keep the edit so the next change (or the retry below) sends it again.
      pending.current = pending.current ?? next;
      setSave(typeof navigator !== 'undefined' && navigator.onLine === false ? { state: 'offline' } : { state: 'error' });
      clearTimeout(timer.current);
      timer.current = setTimeout(() => void flush(), retryDelay.current);
      retryDelay.current = Math.min(retryDelay.current * 2, RETRY_MAX_MS);
      return;
    } finally {
      saving.current = false;
    }
    // Typing during the save left a newer edit waiting.
    if (pending.current && !conflictRef.current) {
      clearTimeout(timer.current);
      timer.current = setTimeout(() => void flush(), SAVE_DELAY_MS);
    }
  }, [adapter, id]);

  React.useEffect(() => () => clearTimeout(timer.current), []);

  // Save what's pending before the page goes away.
  React.useEffect(() => {
    const beforeUnload = (e: BeforeUnloadEvent) => {
      if (pending.current || saving.current) {
        void flush();
        e.preventDefault();
      }
    };
    window.addEventListener('beforeunload', beforeUnload);
    return () => window.removeEventListener('beforeunload', beforeUnload);
  }, [flush]);

  const onChange = React.useCallback(
    (next: WhityDocument) => {
      setDoc(next);
      if (!canEdit || conflictRef.current) return;
      pending.current = next;
      clearTimeout(timer.current);
      timer.current = setTimeout(() => void flush(), SAVE_DELAY_MS);
    },
    [canEdit, flush]
  );

  if (loadError) {
    return (
      <div role="alert" className="grid h-full place-items-center p-6 text-center">
        <div className="grid max-w-sm gap-3">
          <p className="m-0 text-lg font-semibold">{t('blockEditor.loadFailed.title', 'This document didn’t open')}</p>
          <p className="m-0 text-muted-foreground">
            {t('blockEditor.loadFailed.description', 'It may have been deleted, or you may not have access to it. Check the address, or try again.')}
          </p>
          <div className="flex justify-center gap-2">
            {onBack && (
              <Button variant="ghost" onClick={onBack}>
                {labels.back}
              </Button>
            )}
            <Button onClick={() => setLoadCount((n) => n + 1)}>{t('blockEditor.loadFailed.retry', 'Try again')}</Button>
          </div>
        </div>
      </div>
    );
  }

  if (!doc) {
    return <div aria-busy="true" className="h-full bg-background" />;
  }

  return (
    <div className="grid h-full grid-rows-[auto_1fr]">
      {conflict ? (
        <div role="alert" className="flex items-center gap-3 border-b border-border bg-warning-tint px-4 py-2 text-sm">
          <span className="flex-1">
            {t(
              'blockEditor.conflict.message',
              'Someone else saved this document while you were editing. Your recent changes are still here but aren’t saved.'
            )}
          </span>
          <Button size="sm" variant="outline" onClick={() => setLoadCount((n) => n + 1)}>
            {t('blockEditor.conflict.reload', 'Load the newer version')}
          </Button>
        </div>
      ) : (
        <span />
      )}
      <DocEditor
        key={loadCount}
        document={doc}
        onChange={onChange}
        canEdit={canEdit && !conflict}
        mode={mode}
        onModeChange={setMode}
        back={onBack ? { label: labels.back, onClick: onBack } : undefined}
        path={path}
        save={save}
        onCommandPalette={onCommandPalette}
        labels={labels}
        services={services}
      />
    </div>
  );
}
