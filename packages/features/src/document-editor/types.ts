import type { LabelPrintSource, PublishBlocker, WhityDocument } from '@whity-org/ui/editor/contract';
import type { FieldGroup } from '@whity-org/ui/editor/fields';
import type { Letterhead } from '@whity-org/ui/editor/page-view';
import type { EditorServices, FieldPreview } from '@whity-org/ui/editor/services';

/** A document as the server keeps it: the contract's document plus its draft version. */
export interface LoadedDocument {
  document: WhityDocument;
  /** The draft version this copy is at (the server's ETag). */
  version: number;
}

/** What a save sends: the fields of the document the editor changes. */
export type DraftPatch = Pick<WhityDocument, 'title' | 'blocks'> &
  Partial<Pick<WhityDocument, 'lang' | 'defaultView' | 'fullWidth' | 'page' | 'labels' | 'ownFields'>>;

export type SaveResult =
  | { ok: true; version: number; updatedAt: string }
  /** Someone saved in between; nothing was written. */
  | { ok: false; conflict: true; currentVersion: number };

/**
 * How the editor screen reaches the server. Every method throws on a network
 * or API failure other than a conflict, which `save` reports as a result.
 */
export interface DocumentEditorAdapter {
  load(id: string): Promise<LoadedDocument>;
  save(id: string, patch: DraftPatch, version: number): Promise<SaveResult>;
  /**
   * Live data and storage for the document's rich blocks (M3): records,
   * charts and uploads, each answered within the reader's permissions.
   * Without it, those blocks say what is missing instead of loading.
   */
  loadServices?(id: string): Promise<EditorServices>;
  /**
   * Templates (M4): the fields the record type offers, the tenant's
   * letterhead and print colour. Without it, a template edits like any
   * document, without fields, preview or Publish.
   */
  loadTemplate?(recordType: string | null): Promise<TemplateSetup>;
  /** Records of a type the reader may preview with, newest first. */
  findRecords?(recordType: string, query: string): Promise<RecordChoice[]>;
  /** E.12: each field's value for one record (`type:id`), or null when hidden or missing. */
  preview?(id: string, record: string | null): Promise<FieldPreview | null>;
  /** E.37: what blocks publishing the draft at `version`. */
  publishCheck?(id: string): Promise<PublishBlocker[]>;
  /** E.9: publishes the draft at `version`; resolves to the new published version. Throws when refused. */
  publish?(id: string, version: number): Promise<number>;
  /** The PDF through Whity Render: the published version filled for one record. */
  exportPdf?(id: string, record: string | null, filename: string): Promise<void>;
  /**
   * E.43: a label sheet's PDF, one label per record or CSV row, downloaded as
   * `filename`. Throws with the server's sentence when refused.
   */
  printLabels?(id: string, source: LabelPrintSource, filename: string): Promise<LabelPrintResult>;
}

/** What a label print did: how many labels, and how many were cut at their edge. */
export interface LabelPrintResult {
  count: number;
  clipped: number;
}

export interface TemplateSetup {
  fields: FieldGroup[];
  letterhead: Letterhead;
  accent: string | null;
}

export interface RecordChoice {
  /** `type:id`, as preview and render take it. */
  ref: string;
  label: string;
}
