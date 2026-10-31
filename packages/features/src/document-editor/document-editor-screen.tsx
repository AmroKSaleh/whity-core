'use client';

import * as React from 'react';

import { Button } from '@whity-org/ui/button';
import { DocEditor, type SaveState } from '@whity-org/ui/editor/doc-editor';
import type { DocumentKind, EditorMode, WhityDocument } from '@whity-org/ui/editor/contract';
import type { FieldDef } from '@whity-org/ui/editor/fields';
import type { EditorServices, FieldPreview } from '@whity-org/ui/editor/services';
import { useTranslation } from '../i18n';
import { useEditorLabels } from './editor-labels';
import { PrintLabelsDialog } from './print-labels-dialog';
import { RecordPicker } from './record-picker';
import type { DocumentEditorAdapter, RecordChoice, TemplateSetup } from './types';

/** The kinds Whity Render prints, which have fields, a published version and a PDF (E.7, E.9). */
const PRINTED: DocumentKind[] = ['template', 'letter', 'report', 'label'];

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
 *
 * A printed kind (a template, letter, report or label sheet) is the Document
 * designer (A33, editor M4): it opens in the page view with the letterhead and
 * merge fields, previews with a record, and is published through the check.
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
  const [template, setTemplate] = React.useState<TemplateSetup | null>(null);
  const [preview, setPreview] = React.useState<FieldPreview | null>(null);
  const [previewRecord, setPreviewRecord] = React.useState<string | null>(null);
  const [pickerFor, setPickerFor] = React.useState<'preview' | 'pdf' | null>(null);
  const [printingLabels, setPrintingLabels] = React.useState(false);
  const [publishedVersion, setPublishedVersion] = React.useState<number | null>(null);

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
        setPublishedVersion(loaded.document.publishedVersion ?? null);
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

  // Templates: the record type's fields, the letterhead and print colour.
  const printed = doc ? PRINTED.includes(doc.kind) && !!adapter.loadTemplate : false;
  const recordType = doc?.recordType ?? null;
  React.useEffect(() => {
    if (!printed || !adapter.loadTemplate) return;
    let live = true;
    adapter.loadTemplate(recordType).then(
      (next) => live && setTemplate(next),
      () => live && setTemplate(null)
    );
    return () => {
      live = false;
    };
  }, [adapter, printed, recordType]);

  const flush = React.useCallback(async () => {
    const next = pending.current;
    if (!next || saving.current || conflictRef.current) return;
    pending.current = null;
    saving.current = true;
    setSave({ state: 'saving' });
    try {
      // A label sheet's grid and sheet size are saved with it (E.43), and a template's own fields.
      const sheet = next.kind === 'label' ? { page: next.page, labels: next.labels } : {};
      const own = next.ownFields ? { ownFields: next.ownFields } : {};
      const result = await adapter.save(id, { title: next.title, blocks: next.blocks, ...sheet, ...own }, version.current);
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

  /** Save what's waiting now; publishing and printing need the draft on the server. */
  const flushNow = React.useCallback(async () => {
    clearTimeout(timer.current);
    await flush();
    if (pending.current || conflictRef.current) throw new Error('The draft is not saved');
  }, [flush]);

  const showPreview = React.useCallback(
    async (record: string | null) => {
      if (!adapter.preview) return;
      const next = await adapter.preview(id, record);
      setPreview(next);
      setPreviewRecord(record);
    },
    [adapter, id]
  );

  const exportPdf = React.useCallback(
    (record: string | null) => {
      if (!adapter.exportPdf || !doc) return;
      void adapter.exportPdf(id, record, doc.title);
    },
    [adapter, id, doc]
  );

  const findRecords = React.useCallback(
    (query: string): Promise<RecordChoice[]> => (recordType && adapter.findRecords ? adapter.findRecords(recordType, query) : Promise.resolve([])),
    [adapter, recordType]
  );

  // The letterhead's end side: the record's own required field (its number) and today.
  const pageFields = React.useMemo(() => {
    if (!template) return undefined;
    const all: FieldDef[] = template.fields.flatMap((g) => g.items);
    const reference = recordType ? template.fields[0]?.items.find((f) => f.required) : undefined;
    return { reference, date: all.find((f) => f.path === 'today') };
  }, [template, recordType]);

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
    // A column, not a grid: with no bar there is no first row to take space,
    // whatever classes the host app happens to generate.
    <div className="flex h-full flex-col">
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
      ) : null}
      <div className="min-h-0 flex-1">
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
          {...(printed && template
            ? {
                fields: template.fields,
                letterhead: template.letterhead,
                accent: template.accent,
                pageFields,
                preview,
                onPreview: adapter.preview ? () => (recordType ? setPickerFor('preview') : void showPreview(null)) : undefined,
                onClosePreview: () => {
                  setPreview(null);
                  setPreviewRecord(null);
                },
                // E.43: a label sheet prints labels, one per record or CSV row, not one PDF.
                onExportPdf:
                  adapter.exportPdf && doc.kind !== 'label'
                    ? () => (recordType && !previewRecord ? setPickerFor('pdf') : exportPdf(previewRecord))
                    : undefined,
                onPrintLabels: adapter.printLabels && doc.kind === 'label' ? () => setPrintingLabels(true) : undefined,
                publish:
                  adapter.publishCheck && adapter.publish
                    ? {
                        publishedVersion,
                        check: async () => {
                          await flushNow();
                          return adapter.publishCheck!(id);
                        },
                        publish: async () => {
                          await flushNow();
                          setPublishedVersion(await adapter.publish!(id, version.current));
                        },
                      }
                    : undefined,
              }
            : {})}
        />
      </div>
      {printed && template && adapter.printLabels && doc.kind === 'label' && (
        <PrintLabelsDialog
          open={printingLabels}
          onOpenChange={setPrintingLabels}
          fields={
            doc.ownFields?.length
              ? [
                  ...template.fields,
                  {
                    group: labels.fieldsThisTemplate,
                    items: doc.ownFields.map((f) => ({ path: `template.${f.key}`, label: f.label, sample: f.sample })),
                  },
                ]
              : template.fields
          }
          findRecords={findRecords}
          canUseRecords={!!recordType && !!adapter.findRecords}
          onPrint={(source) => adapter.printLabels!(id, source, doc.title)}
        />
      )}
      {recordType && (
        <RecordPicker
          open={pickerFor !== null}
          onOpenChange={(open) => !open && setPickerFor(null)}
          title={pickerFor === 'pdf' ? t('blockEditor.recordPicker.pdfTitle', 'Print with a record') : t('blockEditor.recordPicker.title', 'Preview with a record')}
          find={findRecords}
          onPick={(choice) => (pickerFor === 'pdf' ? exportPdf(choice.ref) : void showPreview(choice.ref))}
        />
      )}
    </div>
  );
}
