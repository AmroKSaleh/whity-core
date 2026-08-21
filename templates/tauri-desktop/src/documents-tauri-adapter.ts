import {
  createDocumentDesignerAdapter,
  type DocumentDesignerAdapter,
  type Transport,
} from "@amroksaleh/features/document-designer"

import { remoteRequest } from "./remote-client"

/**
 * The desktop implementation of the Document Designer's data source.
 *
 * Document templates and blocks are SERVER-owned (`/api/v1/document-templates`,
 * tenant-scoped and RBAC-gated), not plugin-owned, so this goes through
 * `remote_request` — the enrolled backend, authenticated as this device — and
 * NOT through `php_request`, which reaches the bundled offline PHP host. Same
 * split as `roles-tauri-adapter.ts`; see the transport table in the template
 * README. The practical consequence is that the designer needs connectivity,
 * unlike the plugin block screens.
 *
 * `remoteRequest` already returns `{status, body}`, which is exactly the
 * `Transport` shape, so the transport is a pass-through and the whole adapter
 * is four lines. Contrast `roles-tauri-adapter.ts`, which carries a ~110-line
 * copy of web's factory kept in step by a comment: this slice exports its
 * factory from the package instead, so there is nothing here to drift.
 *
 * The offline path (the `plugins/Documents` plugin over `php_request`) is a
 * later addition and needs no change to the screen — only a second adapter.
 */
const remoteTransport: Transport = {
  request: (method, path, body) => remoteRequest(method, path, body),
}

export const documentsAdapter: DocumentDesignerAdapter =
  createDocumentDesignerAdapter(remoteTransport)
