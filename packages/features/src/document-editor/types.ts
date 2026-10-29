import type { WhityDocument } from '@whity-org/ui/editor/contract';
import type { EditorServices } from '@whity-org/ui/editor/services';

/** A document as the server keeps it: the contract's document plus its draft version. */
export interface LoadedDocument {
  document: WhityDocument;
  /** The draft version this copy is at (the server's ETag). */
  version: number;
}

/** What a save sends: the fields of the document the editor changes. */
export type DraftPatch = Pick<WhityDocument, 'title' | 'blocks'> & Partial<Pick<WhityDocument, 'lang' | 'defaultView' | 'fullWidth'>>;

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
}
