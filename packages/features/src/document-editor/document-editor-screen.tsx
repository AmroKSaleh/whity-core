'use client';

import * as React from 'react';

import { Button } from '@whity-org/ui/button';
import { DocEditor, type SaveState } from '@whity-org/ui/editor/doc-editor';
import type { DocumentKind, EditorMode, WhityDocument } from '@whity-org/ui/editor/contract';
import type { FieldDef } from '@whity-org/ui/editor/fields';
import type { EditorServices, FieldPreview } from '@whity-org/ui/editor/services';
import { useTranslation } from '../i18n';
import { useEditorLabels } from './editor-labels';
import { IssueBar } from './issue-bar';
import { PrintLabelsDialog } from './print-labels-dialog';
import { RecordPicker } from './record-picker';
import type { DocumentEditorAdapter, RecordChoice, TemplateSetup } from './types';

/**
 * Issuing mode (editor M4c): the screen opened to issue a document rather than
 * to author one. On a template it shows the PUBLISHED version, read-only, with
 * the preview on; on a letter (a copy made by "Edit before issuing") it edits
 * and autosaves as usual with the preview on, so each edit shows as it will
 * print. Either way the issuing bar carries the record, the template's own
 * fields and Issue.
 */
export interface IssueOptions {
  /** The record to issue for (`type:id`), when the person came with one. */
  record?: string | null;
  /** The document was issued: go to its record page. */
  onIssued: (documentId: number) => void;
  /** "Edit before issuing" made a letter: open it in issuing mode with the same record. */
  onEditCopy: (copyId: string, record: string | null) => void;
}

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
  issue,
}: {
  id: string;
  adapter: DocumentEditorAdapter;
  canEdit: boolean;
  onBack?: () => void;
  path?: string;
  onCommandPalette?: () => void;
  /** Open in issuing mode (M4c). */
  issue?: IssueOptions;
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
  // Issuing mode (M4c).
  const issuingMode = !!issue;
  const issueRecord = issue?.record ?? null;
  const [ownValues, setOwnValues] = React.useState<Record<string, string>>({});
  const [issuing, setIssuing] = React.useState(false);
  const [copying, setCopying] = React.useState(false);
  const [refusal, setRefusal] = React.useState<{ message: string; missing: string[] } | null>(null);
  const [notPublished, setNotPublished] = React.useState(false);
  const previewStarted = React.useRef(false);

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
    setNotPublished(false);
    previewStarted.current = false;
    adapter
      .load(id)
      .then(async (loaded) => {
        let document = loaded.document;
        // Issuing from a template issues its published version, so that is what shows.
        if (issuingMode && document.kind === 'template' && adapter.loadVersion) {
          if (document.publishedVersion == null) {
            if (live) setNotPublished(true);
          } else {
            const content = await adapter.loadVersion(id, document.publishedVersion);
            document = { ...document, ...content, status: 'published' };
          }
        }
        if (!live) return;
        version.current = loaded.version;
        conflictRef.current = false;
        setConflict(false);
        setDoc(document);
        setPublishedVersion(loaded.document.publishedVersion ?? null);
        setSave(undefined);
      })
      .catch(() => live && setLoadError(true));
    return () => {
      live = false;
    };
  }, [adapter, id, loadCount, issuingMode]);

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

  // Issuing from a template shows its published version: nothing there is edited or saved.
  const editCapable = canEdit && !(issuingMode && doc?.kind === 'template');

  const onChange = React.useCallback(
    (next: WhityDocument) => {
      setDoc(next);
      if (!editCapable || conflictRef.current) return;
      pending.current = next;
      clearTimeout(timer.current);
      timer.current = setTimeout(() => void flush(), SAVE_DELAY_MS);
    },
    [editCapable, flush]
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

  // Issuing: the preview is on from the start, with the record the person came
  // with; a template bound to a record type and opened without one asks for it.
  React.useEffect(() => {
    if (!issuingMode || !doc || !printed || previewStarted.current) return;
    previewStarted.current = true;
    if (recordType && !issueRecord) setPickerFor('preview');
    else void showPreview(recordType ? issueRecord : null).catch(() => undefined);
  }, [issuingMode, doc, printed, recordType, issueRecord, showPreview]);

  const ownFields = React.useMemo(() => doc?.ownFields ?? [], [doc?.ownFields]);
  const filledValues = React.useMemo(
    () =>
      Object.fromEntries(
        ownFields.map((f) => [f.key, (ownValues[f.key] ?? '').trim()] as const).filter(([, v]) => v !== '')
      ),
    [ownFields, ownValues]
  );

  // The own fields' values join the record's in the preview chips as they are typed.
  const shownPreview = React.useMemo(() => {
    if (!issuingMode || !preview) return preview;
    const own = Object.fromEntries(Object.entries(filledValues).map(([k, v]) => [`template.${k}`, v]));
    return { ...preview, values: { ...preview.values, ...own } };
  }, [issuingMode, preview, filledValues]);

  /** A field path from a refusal, in the template's own words. */
  const fieldName = React.useCallback(
    (path: string) => {
      const key = path.startsWith('template.') ? path.slice('template.'.length) : null;
      const own = key !== null ? ownFields.find((f) => f.key === key) : undefined;
      if (own) return own.label || own.key;
      return template?.fields.flatMap((g) => g.items).find((f) => f.path === path)?.label ?? path;
    },
    [ownFields, template]
  );

  const doIssue = React.useCallback(async () => {
    if (!issue || !adapter.issue || !doc) return;
    setRefusal(null);
    setIssuing(true);
    try {
      // A letter is issued as it is now: its draft has to be on the server first.
      if (doc.kind !== 'template') await flushNow();
      const result = await adapter.issue(id, { record: recordType ? previewRecord : null, values: filledValues });
      if (result.ok) {
        issue.onIssued(result.documentId);
        return;
      }
      setRefusal({ message: result.message, missing: result.missing.map(fieldName) });
    } catch {
      setRefusal({ message: t('blockEditor.issue.failed', 'The document wasn’t issued. Try again.'), missing: [] });
    } finally {
      setIssuing(false);
    }
  }, [issue, adapter, doc, id, recordType, previewRecord, filledValues, flushNow, fieldName, t]);

  const editCopy = React.useCallback(async () => {
    if (!issue || !adapter.copyForIssue) return;
    setRefusal(null);
    setCopying(true);
    try {
      const copyId = await adapter.copyForIssue(id);
      issue.onEditCopy(copyId, recordType ? previewRecord : null);
    } catch {
      setRefusal({ message: t('blockEditor.issue.copyFailed', 'The copy to edit wasn’t made. Try again.'), missing: [] });
      setCopying(false);
    }
  }, [issue, adapter, id, recordType, previewRecord, t]);

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
    // A column, not a grid: an empty first row must take no space whatever classes the host app generates.
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
      ) : issuingMode && printed && adapter.issue ? (
        <IssueBar
          recordType={recordType ? (services?.recordTypes?.find((r) => r.key === recordType)?.label ?? recordType) : null}
          recordLabel={previewRecord ? (preview?.label ?? null) : null}
          onChangeRecord={recordType && adapter.findRecords ? () => setPickerFor('preview') : undefined}
          fields={ownFields}
          values={ownValues}
          onValue={(key, value) => {
            setOwnValues((prev) => ({ ...prev, [key]: value }));
            setRefusal(null);
          }}
          onIssue={() => void doIssue()}
          issuing={issuing}
          canIssue={!notPublished && !conflict && (!recordType || !!previewRecord)}
          onEditCopy={doc.kind === 'template' && adapter.copyForIssue && !notPublished ? () => void editCopy() : undefined}
          copying={copying}
          notice={
            notPublished
              ? t('blockEditor.issue.notPublished', 'This template hasn’t been published, so no document can be issued from it yet.')
              : null
          }
          refusal={refusal}
        />
      ) : null}
      <div className="min-h-0 flex-1">
        <DocEditor
          key={loadCount}
          document={doc}
          onChange={onChange}
          canEdit={editCapable && !conflict}
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
                preview: shownPreview,
                // Issuing a letter: edit with the record's values showing (M4c).
                editWhilePreviewing: issuingMode && doc.kind !== 'template',
                onPreview: adapter.preview ? () => (recordType ? setPickerFor('preview') : void showPreview(null)) : undefined,
                // While issuing the preview stays on: it is what will be issued.
                onClosePreview: issuingMode
                  ? undefined
                  : () => {
                      setPreview(null);
                      setPreviewRecord(null);
                    },
                // E.43: a label sheet prints labels, one per record or CSV row, not one PDF.
                onExportPdf:
                  adapter.exportPdf && doc.kind !== 'label'
                    ? () => (recordType && !previewRecord ? setPickerFor('pdf') : exportPdf(previewRecord))
                    : undefined,
                onPrintLabels: adapter.printLabels && doc.kind === 'label' ? () => setPrintingLabels(true) : undefined,
                // Publish is not offered while issuing: Issue is the one main action.
                publish:
                  !issuingMode && adapter.publishCheck && adapter.publish
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
          title={
            pickerFor === 'pdf'
              ? t('blockEditor.recordPicker.pdfTitle', 'Print with a record')
              : issuingMode
                ? t('blockEditor.recordPicker.issueTitle', 'Issue for a record')
                : t('blockEditor.recordPicker.title', 'Preview with a record')
          }
          find={findRecords}
          onPick={(choice) => (pickerFor === 'pdf' ? exportPdf(choice.ref) : void showPreview(choice.ref))}
        />
      )}
    </div>
  );
}
