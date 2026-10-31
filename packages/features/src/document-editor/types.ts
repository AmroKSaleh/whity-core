import type { LabelPrintSource, PublishBlocker, ResolvedDocumentTheme, WhityDocument } from '@whity-org/ui/editor/contract';
import type { FieldGroup } from '@whity-org/ui/editor/fields';
import type { BrandLogos, Letterhead, LetterheadParts } from '@whity-org/ui/editor/page-view';
import type { EditorServices, FieldPreview } from '@whity-org/ui/editor/services';

/** A document as the server keeps it: the contract's document plus its draft version. */
export interface LoadedDocument {
  document: WhityDocument;
  /** The draft version this copy is at (the server's ETag). */
  version: number;
  /** E.44: the theme the draft resolves to (the print colour and letterhead); absent when the server sends none. */
  resolvedTheme?: ResolvedDocumentTheme | null;
}

/** What a save sends: the fields of the document the editor changes. */
export type DraftPatch = Pick<WhityDocument, 'title' | 'blocks'> &
  Partial<Pick<WhityDocument, 'lang' | 'defaultView' | 'fullWidth' | 'page' | 'labels' | 'ownFields'>>;

export type SaveResult =
  /** `resolvedTheme`: the saved draft's theme, when the server returns the row. */
  | { ok: true; version: number; updatedAt: string; resolvedTheme?: ResolvedDocumentTheme | null }
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
   * Templates (M4): the fields the record type offers and the tenant's
   * letterhead lines and logos; the document's theme picks among them (E.44). Without it, a template edits like any
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
  /** M4c: what a published version says, to issue from (read-only). */
  loadVersion?(id: string, version: number): Promise<VersionContent>;
  /**
   * M4c "Edit before issuing" (`POST .../drafts`): a personal letter made from the template's
   * published version, keeping its record type and own fields. Resolves to its id.
   */
  copyForIssue?(id: string): Promise<string>;
  /**
   * M4c: issues a document from a template's published version, or from a
   * letter as it is now. A refusal the person can act on (missing fields, no
   * published version) resolves as a result; anything else throws.
   */
  issue?(id: string, request: IssueRequest): Promise<IssueResult>;
}

/** What one published version holds: what a document issued from it prints. */
export type VersionContent = Pick<WhityDocument, 'title' | 'blocks'> &
  Partial<Pick<WhityDocument, 'page' | 'labels' | 'ownFields'>> & {
    /** E.44: the theme frozen when the version was published, which its documents print with. */
    resolvedTheme?: ResolvedDocumentTheme | null;
  };

/** What issuing sends: the record, and the values of the template's own fields by key. */
export interface IssueRequest {
  /** `type:id`; null when the template has no record type. */
  record: string | null;
  /** Own field values by key (`reference`, not `template.reference`); blanks are left out. */
  values: Record<string, string>;
}

export type IssueResult =
  | { ok: true; documentId: number }
  /** The server refused: its sentence, and the field paths it said have no value. */
  | { ok: false; message: string; missing: string[] };

/** What a label print did: how many labels, and how many were cut at their edge. */
export interface LabelPrintResult {
  count: number;
  clipped: number;
}

export interface TemplateSetup {
  fields: FieldGroup[];
  /** The tenant's name and its default letterhead lines, for a document without a theme. */
  letterhead: Letterhead;
  /** Every letterhead line by its theme name; the theme's `letterhead.lines` picks and orders them. */
  letterheadParts?: LetterheadParts;
  /** The tenant's uploaded logos; the theme's `letterhead.logo` picks one. */
  logos?: BrandLogos;
}

export interface RecordChoice {
  /** `type:id`, as preview and render take it. */
  ref: string;
  label: string;
}
