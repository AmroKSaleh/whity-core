import { invoke } from "@tauri-apps/api/core"

import { documentsAdapter } from "../documents-composite-adapter"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
const mockInvoke = invoke as jest.MockedFunction<typeof invoke>

/**
 * The desktop designer edits two stores at once — the server's templates and
 * the offline PHP host's — and the id prefix is the only thing routing a save
 * or a delete back to the store a row came from. Getting that wrong does not
 * throw; it writes to the wrong database. Hence these.
 *
 * Both stores are reached through `invoke`, so one mock covers both:
 * `remote_request` is the server, `php_request` is the device.
 */

const PAGE = { widthMm: 100, heightMm: 100, marginMm: 0, background: "#ffffff" }
const DOC = {
  version: 2,
  name: "T",
  page: PAGE,
  placeholders: [],
  pages: [{ id: "p1", elements: [] }],
} as never

/** Server rows are snake_case (core REST); device rows are camelCase (SyncController). */
const serverRow = (id: number, name: string) => ({
  id,
  name,
  updated_at: "2026-08-21 10:00:00",
  data: DOC,
})
const deviceRow = (id: number, name: string, scope = "personal") => ({
  id,
  version: 3,
  name,
  data: DOC,
  scope,
  requiredPermission: null,
  isSystem: false,
  updatedAt: "2026-08-21 11:00:00",
})

type Call = { cmd: string; args: Record<string, unknown> }

/** Route each invoke by command, recording what was asked. */
function wire(handlers: {
  remote?: (args: Record<string, unknown>) => unknown
  php?: (args: Record<string, unknown>) => unknown
}) {
  const calls: Call[] = []
  mockInvoke.mockImplementation((cmd: string, args?: Record<string, unknown>) => {
    const a = args ?? {}
    calls.push({ cmd, args: a })
    if (cmd === "remote_request") return Promise.resolve(handlers.remote?.(a) ?? { status: 200, body: { data: [] } })
    if (cmd === "php_request") return Promise.resolve(handlers.php?.(a) ?? { status: 200, body: { data: [] } })
    throw new Error(`unexpected command ${cmd}`)
  })
  return calls
}

beforeEach(() => mockInvoke.mockReset())

describe("documentsAdapter — merging two stores", () => {
  it("lists both stores, namespacing ids and labelling their origin", async () => {
    wire({
      remote: () => ({ status: 200, body: { data: [serverRow(2, "Test template")] } }),
      php: () => ({ status: 200, body: { data: [deviceRow(2, "Test from menu")] } }),
    })

    const out = await documentsAdapter.listTemplates()

    // Same numeric id in both stores — the prefix is what keeps them distinct.
    expect(out.map((t) => t.id).sort()).toEqual(["dev:2", "srv:2"])
    expect(out.find((t) => t.id === "srv:2")?.name).toBe("Test template · server")
    expect(out.find((t) => t.id === "dev:2")?.name).toBe("Test from menu · device")
  })

  it("still lists device templates when the server is unreachable", async () => {
    // The offline case: a designer that blanks itself because the server is
    // down would defeat the point of having a device store at all.
    wire({
      remote: () => {
        throw new Error("offline")
      },
      php: () => ({ status: 200, body: { data: [deviceRow(2, "Test from menu")] } }),
    })

    const out = await documentsAdapter.listTemplates()

    expect(out.map((t) => t.id)).toEqual(["dev:2"])
  })

  it("throws only when BOTH stores fail", async () => {
    wire({
      remote: () => {
        throw new Error("offline")
      },
      php: () => ({ status: 500, body: { error: "host down" } }),
    })

    await expect(documentsAdapter.listTemplates()).rejects.toThrow()
  })

  it("routes a device save to php_request as a full-row replace", async () => {
    // Prime the meta cache so the save can send back scope/isSystem verbatim.
    wire({
      remote: () => ({ status: 200, body: { data: [] } }),
      php: () => ({ status: 200, body: { data: [deviceRow(7, "Device doc", "tenant")] } }),
    })
    await documentsAdapter.listTemplates()

    const calls = wire({ php: () => ({ status: 200, body: { data: { id: 7 } } }) })
    const id = await documentsAdapter.saveTemplate(DOC, "dev:7")

    expect(id).toBe("dev:7")
    const call = calls.find((c) => c.cmd === "php_request")
    expect(call?.args.method).toBe("PATCH")
    expect(call?.args.path).toBe("/api/document-templates/7")
    // Every domain column: omitting `data` resets the row to empty, omitting
    // `scope` demotes a tenant template to personal.
    expect(call?.args.body).toMatchObject({ scope: "tenant", isSystem: false, requiredPermission: null })
    expect((call?.args.body as { data?: unknown }).data).toBeDefined()
  })

  it("routes a server save to remote_request and re-prefixes the returned id", async () => {
    const calls = wire({ remote: () => ({ status: 200, body: { data: { id: 2 } } }) })

    const id = await documentsAdapter.saveTemplate(DOC, "srv:2")

    expect(id).toBe("srv:2")
    const call = calls.find((c) => c.cmd === "remote_request")
    expect(call?.args.method).toBe("PATCH")
    // The prefix must be stripped — a path of /document-templates/srv:2 would 404.
    expect(call?.args.path).toBe("/api/v1/document-templates/2")
  })

  it("sends a brand-new document to the server", async () => {
    const calls = wire({ remote: () => ({ status: 200, body: { data: { id: 9 } } }) })

    const id = await documentsAdapter.saveTemplate(DOC)

    expect(id).toBe("srv:9")
    expect(calls.find((c) => c.cmd === "remote_request")?.args.method).toBe("POST")
    expect(calls.some((c) => c.cmd === "php_request")).toBe(false)
  })

  it("routes deletes to the store the id names", async () => {
    const devCalls = wire({ php: () => ({ status: 200, body: null }) })
    await documentsAdapter.deleteTemplate("dev:4")
    expect(devCalls[0]).toMatchObject({ cmd: "php_request" })
    expect(devCalls[0].args.path).toBe("/api/document-templates/4")

    const srvCalls = wire({ remote: () => ({ status: 200, body: null }) })
    await documentsAdapter.deleteTemplate("srv:4")
    expect(srvCalls[0]).toMatchObject({ cmd: "remote_request" })
    expect(srvCalls[0].args.path).toBe("/api/v1/document-templates/4")
  })

  it("keeps blocks server-only", async () => {
    // Block ids are persisted inside templates as `blockInstance.blockId`, so
    // namespacing them would write this file's scheme into saved documents.
    const calls = wire({ remote: () => ({ status: 200, body: { data: [] } }) })

    await documentsAdapter.listBlocks()

    expect(calls.every((c) => c.cmd === "remote_request")).toBe(true)
  })
})
