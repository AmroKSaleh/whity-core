import type { DocTemplate } from '@amroksaleh/ui/documents/types';
import type { DocBlock } from '@amroksaleh/ui/documents/blocks';

/**
 * The injected-adapter contract for the Document & Label Designer.
 *
 * Modelled on `../roles/types.ts`, deliberately: the desktop client's Rust
 * `remote_request` command already returns `{status, body}`, and web maps a
 * `fetch` Response into the same shape, so a transport is the natural
 * least-common-denominator seam between the two.
 *
 * `Transport`/`TransportResponse` are REDECLARED here rather than imported
 * from the roles slice — each slice stays self-contained (the same reason
 * `RolesTranslate` redeclares `TranslateFn`), and the two are structurally
 * identical, so one client transport object satisfies both. This is also why
 * this slice must NOT be added to `packages/features/src/index.ts`: that
 * barrel is `export *`, and two slices exporting `Transport` would be a
 * duplicate-export error. Roles is absent from it for the same reason.
 */

/** A single transport round-trip result. */
export interface TransportResponse {
  /** HTTP-equivalent status code. */
  status: number;
  /** Parsed JSON response body (or `null` when there was no body). */
  body: unknown;
}

export interface Transport {
  /** method + app-relative path (e.g. "/api/v1/document-templates"); JSON body optional. */
  request(method: string, path: string, body?: unknown): Promise<TransportResponse>;
}

/** A stored template as the designer holds it: identity plus the v2-migrated doc. */
export interface SavedTemplate {
  id: string;
  name: string;
  updatedAt: string;
  data: DocTemplate;
}

/**
 * The injected data-source adapter the designer consumes.
 *
 * Every method THROWS on failure rather than returning a result union. That is
 * the contract the designer already relies on — it catches and reports through
 * `onNotify` at ~25 call sites — so changing it here would ripple through all
 * of them for no benefit.
 */
export interface DocumentDesignerAdapter {
  /** GET /document-templates — RBAC-filtered server-side, newest first. */
  listTemplates(): Promise<SavedTemplate[]>;
  /** POST (no id) or PATCH (id); returns the template's id. */
  saveTemplate(template: DocTemplate, id?: string): Promise<string>;
  /** DELETE /document-templates/{id}. */
  deleteTemplate(id: string): Promise<void>;
  /** GET /document-blocks — RBAC-filtered. */
  listBlocks(): Promise<DocBlock[]>;
  /** Upsert: a purely-numeric `block.id` means update, anything else create. */
  saveBlock(block: DocBlock): Promise<string>;
  /** DELETE /document-blocks/{id}; a 409 is the reference-integrity guard. */
  deleteBlock(id: string): Promise<void>;
}

export type DocumentsNotifyType = 'success' | 'error' | 'warning' | 'info';

export type DocumentsNotify = (message: string, type: DocumentsNotifyType) => void;

/**
 * Module-level singleton, NOT an inline default.
 *
 * Same rationale as `identityTranslate` (`../nav/types.ts`): an inline
 * `() => {}` default reallocates on every render, re-firing any effect that
 * lists it as a dependency — which caused a real infinite-render loop in the
 * DemoCatalog pilot.
 */
export const noopNotify: DocumentsNotify = () => {};

export interface DocumentDesignerScreenProps {
  /** Injected data source: web's typed api-client adapter, or the desktop's
   *  `remote_request` transport adapter. */
  adapter: DocumentDesignerAdapter;
  /** Notifier; web wires the app's ToastProvider, desktop wires a floating
   *  Alert. Defaults to a no-op so the screen renders standalone. */
  onNotify?: DocumentsNotify;
  /** Exit affordance (File ▸ Close editor). */
  onClose?: () => void;
}
