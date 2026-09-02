import { invoke } from "@tauri-apps/api/core"

import {
  blankTemplate,
  createDocumentDesignerAdapter,
  isDocTemplate,
  migrateTemplate,
  type DocumentDesignerAdapter,
  type SavedTemplate,
  type Transport,
} from "@amroksaleh/features/document-designer"
import type { DocTemplate } from "@amroksaleh/ui/documents/types"

import { remoteRequest } from "./remote-client"

/**
 * The desktop designer's data source: the SERVER's templates and the DEVICE's
 * templates in one list, both editable.
 *
 * The device runs two document stores and, before this, two screens that showed
 * them without saying so. `PLUGINS > Document Templates` is the `Documents`
 * plugin's own screen, served by the bundled offline PHP host out of
 * `whity-offline.sqlite`; the designer read the SERVER over `remote_request`.
 * Same feature name, same-looking rows, disjoint data — save in one, look in
 * the other, and your work appears to have vanished. Nothing relays between
 * them either: `sync::bridge` carries only `relations/persons`, and both of its
 * legs are currently broken (the local leg omits the plugins' `/api` prefix;
 * the remote leg needs a changes feed core's document routes do not serve).
 *
 * Merging the two lists is the demo-scoped answer. The real fix is to make the
 * offline rows relay to the server and be edited there, which needs server-side
 * sync endpoints for documents — the "cutover" `DocumentsPlugin` defers.
 *
 * BLOCKS ARE DELIBERATELY NOT MERGED, and that is the one asymmetry here worth
 * knowing about. A block's id is persisted INSIDE a template, as
 * `blockInstance.blockId`; namespacing block ids the way template ids are
 * namespaced below would write `srv:`/`dev:` prefixes into saved documents and
 * make this file's scheme a storage format. Blocks stay server-only until the
 * stores are genuinely unified.
 */

/** Which store a template id belongs to. The prefix is the routing key. */
const SERVER = "srv:"
const DEVICE = "dev:"

/** The plugin's routes on the offline host — no `/v1`, unlike core's. */
const DEVICE_TEMPLATES = "/api/document-templates"

interface PhpResponse {
  status: number
  body: unknown
}

function phpCall(method: string, path: string, body?: unknown): Promise<PhpResponse> {
  return invoke<PhpResponse>("php_request", { method, path, body: body ?? null })
}

/**
 * A row as `Whity\Sdk\Sync\SyncController` presents it. Note the casing: the
 * sync contract is camelCase (`updatedAt`), while core's REST rows are
 * snake_case (`updated_at`) — which is why the device store cannot simply reuse
 * `createDocumentDesignerAdapter` with a different transport.
 */
interface DeviceRow {
  id: number
  version: number
  name: string
  data: unknown
  scope: string
  requiredPermission: string | null
  isSystem: boolean
  updatedAt: string | null
}

/**
 * The fields the designer does not model but the device store requires on every
 * write. `SyncController`'s update is a full-row replace: omit `data` and the
 * row resets to the empty placeholder; omit `scope` and a tenant/global
 * template is silently demoted to personal. So the non-canvas fields are
 * remembered from the last list and sent back verbatim.
 */
const deviceMeta = new Map<string, { scope: string; requiredPermission: string | null; isSystem: boolean }>()

/**
 * A row the plugin's own "New template" form created carries the EMPTY
 * placeholder rather than a canvas — the form sets name and scope and nothing
 * else, and `{}` round-trips through PHP's json_decode/encode as `[]`.
 *
 * Such a row must open as a blank document to be designed, not be hidden.
 * Hiding it is precisely why a template created on the plugin screen never
 * reached the designer: `isDocTemplate([])` is false, so the row was skipped
 * and the list came back empty. Only a row that is neither a template NOR a
 * placeholder is genuinely malformed and skipped.
 */
function isEmptyPlaceholder(data: unknown): boolean {
  if (Array.isArray(data)) return data.length === 0
  return !!data && typeof data === "object" && Object.keys(data as object).length === 0
}

function rowsOf(body: unknown): unknown[] {
  const data = (body as { data?: unknown } | null)?.data
  return Array.isArray(data) ? data : []
}

function ensureOk(res: PhpResponse, failure: string): unknown {
  if (res.status < 200 || res.status >= 300) {
    const message =
      res.body && typeof res.body === "object" && typeof (res.body as { error?: unknown }).error === "string"
        ? (res.body as { error: string }).error
        : `${failure} (${res.status})`
    throw new Error(message)
  }
  return res.body
}

/** Label a merged entry so two same-named templates are still tellable apart. */
const label = (name: string, where: "server" | "device") => `${name} · ${where}`

async function listDeviceTemplates(): Promise<SavedTemplate[]> {
  const body = ensureOk(await phpCall("GET", DEVICE_TEMPLATES), "Failed to load device templates")
  const out: SavedTemplate[] = []
  for (const raw of rowsOf(body)) {
    const row = raw as DeviceRow

    let doc: DocTemplate
    if (isDocTemplate(row.data)) {
      doc = migrateTemplate(row.data as DocTemplate)
    } else if (isEmptyPlaceholder(row.data)) {
      // Keep the row's own name: it is what the operator typed on the plugin
      // screen, and what they will look for in the designer's list.
      doc = { ...blankTemplate(), name: row.name }
    } else {
      continue
    }

    const id = `${DEVICE}${row.id}`
    const scope = row.scope ?? "personal"
    deviceMeta.set(id, {
      scope,
      requiredPermission: row.requiredPermission ?? null,
      isSystem: row.isSystem ?? false,
    })
    out.push({
      id,
      name: label(row.name, "device"),
      updatedAt: row.updatedAt ?? "",
      data: doc,
      // The device row has always carried a scope — `deviceMeta` above has been
      // reading it, so `saveDeviceTemplate` could send every domain column back
      // unchanged. It just never reached the designer, which is the same
      // omission the server client had: the value was known and dropped on the
      // way to the only place that shows it to anybody.
      scope,
    })
  }
  return out
}

async function saveDeviceTemplate(template: DocTemplate, id: string): Promise<string> {
  const meta = deviceMeta.get(id) ?? { scope: "personal", requiredPermission: null, isSystem: false }
  // Every domain column, every time — see the note on `deviceMeta`. No
  // `baseVersion` is sent: the offline host is a single-writer store, so
  // last-write-wins is the honest semantic and it keeps a stale-version 409 out
  // of the demo path.
  const payload = {
    name: template.name,
    data: template as unknown as Record<string, unknown>,
    scope: meta.scope,
    requiredPermission: meta.requiredPermission,
    isSystem: meta.isSystem,
  }
  const numericId = id.slice(DEVICE.length)
  ensureOk(await phpCall("PATCH", `${DEVICE_TEMPLATES}/${numericId}`, payload), "Failed to save device template")
  return id
}

async function deleteDeviceTemplate(id: string): Promise<void> {
  const numericId = id.slice(DEVICE.length)
  ensureOk(await phpCall("DELETE", `${DEVICE_TEMPLATES}/${numericId}`), "Failed to delete device template")
  deviceMeta.delete(id)
}

const remoteTransport: Transport = {
  request: (method, path, body) => remoteRequest(method, path, body),
}
const server = createDocumentDesignerAdapter(remoteTransport)

export const documentsAdapter: DocumentDesignerAdapter = {
  async listTemplates(): Promise<SavedTemplate[]> {
    // Each store is fetched independently and one being unreachable must not
    // blank the other: offline, the server list fails and the device list is
    // still the whole point of having one.
    const [srv, dev] = await Promise.allSettled([server.listTemplates(), listDeviceTemplates()])

    const merged: SavedTemplate[] = []
    if (srv.status === "fulfilled") {
      merged.push(
        ...srv.value.map((t) => ({ ...t, id: `${SERVER}${t.id}`, name: label(t.name, "server") })),
      )
    }
    if (dev.status === "fulfilled") merged.push(...dev.value)

    // Both down is a real failure the caller should hear about; one down is not.
    if (srv.status === "rejected" && dev.status === "rejected") {
      throw srv.reason instanceof Error ? srv.reason : new Error("Failed to load templates")
    }
    return merged
  },

  async saveTemplate(template: DocTemplate, id?: string, scope?: string): Promise<string> {
    if (id?.startsWith(DEVICE)) return saveDeviceTemplate(template, id)
    if (id?.startsWith(SERVER)) {
      return `${SERVER}${await server.saveTemplate(template, id.slice(SERVER.length))}`
    }
    // A brand-new document goes to the SERVER: it is the shared store, and a
    // device-local template is reachable from the plugin screen that created it.
    //
    // `scope` must be forwarded, not dropped. An optional parameter left off an
    // implementation still satisfies the interface, so omitting it here would
    // type-check and quietly discard the visibility the author chose in the top
    // bar — filing every desktop-authored template as creator-only, which is
    // the exact defect the parameter was added to fix.
    return `${SERVER}${await server.saveTemplate(template, undefined, scope)}`
  },

  async deleteTemplate(id: string): Promise<void> {
    if (id.startsWith(DEVICE)) return deleteDeviceTemplate(id)
    return server.deleteTemplate(id.startsWith(SERVER) ? id.slice(SERVER.length) : id)
  },

  // Blocks stay server-only — see the file docblock.
  listBlocks: () => server.listBlocks(),
  saveBlock: (block) => server.saveBlock(block),
  deleteBlock: (id) => server.deleteBlock(id),
}
