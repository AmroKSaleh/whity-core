/**
 * #868: `timeline` and `inbox` on the desktop renderer — and, deliberately, the
 * SAME assertions as `web/__tests__/block-renderer-workflow.test.tsx`.
 *
 * The desktop renderer is a hand-written twin of the web one and has silently
 * diverged twice: #826 declared sort/filter/pageSize props that nothing
 * rendered, and #847 shipped a form that submitted a different payload than it
 * showed. Both were invisible to anyone testing in a browser. So the two block
 * types land here with their contract pinned rather than their pixels:
 *
 *   - the permitted-actions batch has the same shape and the same `ref` scheme
 *     on both hosts (an item id, a space, the action key), so a divergence in
 *     either half is a failing assertion rather than a silently empty action row;
 *   - a refused action is ABSENT, not disabled;
 *   - while resolving, and on any resolution failure, NO action renders;
 *   - the block's resource scope and the action's scopedPermission reach the host;
 *   - `timeline` issues no write of any kind.
 *
 * The `@tauri-apps/api` transport is mocked; everything else — the real block
 * tree, the real UI primitives — runs as it ships.
 */

import React from "react"
import { render, screen, waitFor } from "@testing-library/react"
import { userEvent } from "@testing-library/user-event"
import { invoke } from "@tauri-apps/api/core"

import { BlockRenderer } from "../block-renderer"
import type { Block, PluginFeature } from "../types"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
const mockInvoke = invoke as jest.MockedFunction<typeof invoke>

const TASKS = [
  { id: 1, title: "Expense claim #4821", requester: "Bjorn Larsen", submitted: "2026-08-16", status: "pending" },
  { id: 2, title: "Access request", requester: "Camille Dupont", submitted: "2026-08-15", status: "pending" },
]

const EVENTS = [
  {
    actor: "Anika Patel",
    action: "approved the request",
    at: "2026-08-17 09:12",
    note: "Within limit.",
    from: "in review",
    to: "approved",
  },
  { actor: "Bjorn Larsen", action: "submitted the request", at: "2026-08-16 14:03", note: "", from: "", to: "submitted" },
]

const inboxTree: Block[] = [
  {
    type: "inbox",
    source: "/api/tasks/mine",
    idField: "id",
    titleField: "title",
    subtitleField: "requester",
    timestampField: "submitted",
    statusField: "status",
    actions: [
      { key: "approve", label: "Approve", method: "POST", endpoint: "/api/tasks/{id}/approve" },
      { key: "reject", label: "Reject", method: "POST", endpoint: "/api/tasks/{id}/reject" },
    ],
  } as Block,
]

type PhpRequest = { method: string; path: string; body: unknown }

function requests(): PhpRequest[] {
  return mockInvoke.mock.calls.map(([, args]) => args as PhpRequest)
}

/** The `checks` array of the last permitted-actions request. */
function lastChecks(): Array<Record<string, unknown>> {
  const permission = [...requests()].reverse().find((r) => r.path === "/__whity/permitted-actions")
  return ((permission?.body as { checks?: Array<Record<string, unknown>> } | undefined)?.checks ?? [])
}

function stubHost(
  options: {
    rows?: unknown
    allow?: (ref: string) => boolean
    permissionsFail?: boolean
    permissionsPending?: boolean
  } = {},
) {
  const { rows = TASKS, allow = () => true, permissionsFail = false, permissionsPending = false } = options

  mockInvoke.mockImplementation((_command: string, args?: unknown) => {
    const { method, path, body } = args as PhpRequest

    if (path === "/__whity/permitted-actions") {
      if (permissionsPending) return new Promise<never>(() => {})
      if (permissionsFail) return Promise.resolve({ status: 500, body: {} })
      const checks = (body as { checks: Array<{ ref: string }> }).checks
      return Promise.resolve({
        status: 200,
        body: { data: checks.map((c) => ({ ref: c.ref, allowed: allow(c.ref), required: null })) },
      })
    }
    if (method === "GET" && path === "/api/tasks/mine") {
      return Promise.resolve({ status: 200, body: { data: rows } })
    }
    if (method === "GET" && path === "/api/tasks/1/events") {
      return Promise.resolve({ status: 200, body: { data: EVENTS } })
    }
    return Promise.resolve({ status: 200, body: { data: {} } })
  })
}

/** The desktop BlockRenderer takes a whole feature; wrap a bare tree in one. */
function renderBlocks(blocks: Block[]) {
  const feature: PluginFeature = {
    id: "workflow",
    plugin: "demo",
    label: "Workflow",
    icon: null,
    group: "Demo",
    order: 1,
    screen: "blocks",
    requiredPermission: "",
    blocks,
  }
  return render(<BlockRenderer feature={feature} />)
}

beforeEach(() => {
  jest.clearAllMocks()
  stubHost()
})

// ==================== timeline ====================

describe("timeline block", () => {
  it("renders each event with its actor, action, timestamp, note and from/to", async () => {
    renderBlocks([
      {
            type: "timeline",
            source: "/api/tasks/1/events",
            actorField: "actor",
            actionField: "action",
            timestampField: "at",
            noteField: "note",
            fromField: "from",
            toField: "to",
      } as Block,
    ])

    expect(await screen.findByText("Anika Patel")).toBeInTheDocument()
    expect(screen.getByText("approved the request")).toBeInTheDocument()
    expect(screen.getByText("2026-08-17 09:12")).toBeInTheDocument()
    expect(screen.getByText("Within limit.")).toBeInTheDocument()
    expect(screen.getByText("in review")).toBeInTheDocument()
    expect(screen.getByText("Bjorn Larsen")).toBeInTheDocument()
  })

  it("preserves declaration order — the order IS the information", async () => {
    renderBlocks([
      {
            type: "timeline",
            source: "/api/tasks/1/events",
            actorField: "actor",
            actionField: "action",
            timestampField: "at",
      } as Block,
    ])

    await screen.findByText("Anika Patel")
    const items = screen.getAllByText(/Anika Patel|Bjorn Larsen/)
    expect(items.map((el) => el.textContent)).toEqual(["Anika Patel", "Bjorn Larsen"])
  })

  it("makes no write request of any kind — it is read-only by construction", async () => {
    renderBlocks([
      {
            type: "timeline",
            source: "/api/tasks/1/events",
            actorField: "actor",
            actionField: "action",
            timestampField: "at",
      } as Block,
    ])

    await screen.findByText("Anika Patel")
    expect(requests().every((r) => r.method === "GET")).toBe(true)
  })

  it("shows the plugin emptyText when the source has no events", async () => {
    stubHost({ rows: [] })
    mockInvoke.mockResolvedValue({ status: 200, body: { data: [] } })

    renderBlocks([
      {
            type: "timeline",
            source: "/api/tasks/1/events",
            actorField: "actor",
            actionField: "action",
            timestampField: "at",
            emptyText: "No events recorded yet.",
      } as Block,
    ])

    expect(await screen.findByText("No events recorded yet.")).toBeInTheDocument()
  })
})

// ==================== inbox ====================

describe("inbox block", () => {
  it("renders the plugin-supplied items", async () => {
    renderBlocks(inboxTree)

    expect(await screen.findByText("Expense claim #4821")).toBeInTheDocument()
    expect(screen.getByText("Bjorn Larsen")).toBeInTheDocument()
    expect(screen.getByText("Access request")).toBeInTheDocument()
    expect(screen.getAllByText("pending")).toHaveLength(2)
  })

  /**
   * The batch shape and the `ref` scheme are PARITY assertions: the same
   * expectation is asserted in web/__tests__/block-renderer-workflow.test.tsx.
   * Change one half and this fails.
   */
  it("asks about the CONCRETE request each button would make, one check per item and action", async () => {
    renderBlocks(inboxTree)

    await screen.findByText("Expense claim #4821")
    await waitFor(() => expect(lastChecks()).toHaveLength(4))

    expect(lastChecks()).toEqual([
      { ref: "1 approve", method: "POST", path: "/api/tasks/1/approve" },
      { ref: "1 reject", method: "POST", path: "/api/tasks/1/reject" },
      { ref: "2 approve", method: "POST", path: "/api/tasks/2/approve" },
      { ref: "2 reject", method: "POST", path: "/api/tasks/2/reject" },
    ])
  })

  it("renders only the actions the host said this caller may take", async () => {
    stubHost({ allow: (ref) => ref === "1 approve" })

    renderBlocks(inboxTree)

    await screen.findByText("Expense claim #4821")
    await waitFor(() => expect(screen.getAllByRole("button", { name: "Approve" })).toHaveLength(1))
    expect(screen.queryByRole("button", { name: "Reject" })).not.toBeInTheDocument()
  })

  it("renders no action while the answer is still resolving", async () => {
    stubHost({ permissionsPending: true })

    renderBlocks(inboxTree)

    await screen.findByText("Expense claim #4821")
    expect(screen.queryByRole("button", { name: "Approve" })).not.toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Reject" })).not.toBeInTheDocument()
  })

  it("renders no action when the resolution fails, and says so", async () => {
    stubHost({ permissionsFail: true })

    renderBlocks(inboxTree)

    await screen.findByText("Expense claim #4821")
    expect(await screen.findByText(/permissions could not be resolved/i)).toBeInTheDocument()
    expect(screen.queryByRole("button", { name: "Approve" })).not.toBeInTheDocument()
  })

  it("sends the block resource scope and the action scoped permission with each check", async () => {
    renderBlocks([
      {
            type: "inbox",
            source: "/api/tasks/mine",
            idField: "id",
            titleField: "title",
            resourceType: "task",
            actions: [
              {
                key: "approve",
                label: "Approve",
                method: "POST",
                endpoint: "/api/tasks/{id}/approve",
                scopedPermission: "tasks:approve",
              },
            ],
      } as Block,
    ])

    await screen.findByText("Expense claim #4821")
    await waitFor(() => expect(lastChecks()).toHaveLength(2))

    expect(lastChecks()[0]).toEqual({
      ref: "1 approve",
      method: "POST",
      path: "/api/tasks/1/approve",
      resourceType: "task",
      resourceId: "1",
      scopedPermission: "tasks:approve",
    })
  })

  it("submits the same path it asked about, with the action verb", async () => {
    renderBlocks(inboxTree)

    await screen.findByText("Expense claim #4821")
    await waitFor(() => expect(screen.getAllByRole("button", { name: "Approve" })).toHaveLength(2))

    await userEvent.click(screen.getAllByRole("button", { name: "Approve" })[0])

    await waitFor(() => {
      const write = requests().find((r) => r.path === "/api/tasks/1/approve")
      expect(write?.method).toBe("POST")
    })
  })

  it("refetches BOTH the queue and the permission answer after a successful action", async () => {
    renderBlocks(inboxTree)

    await screen.findByText("Expense claim #4821")
    await waitFor(() => expect(screen.getAllByRole("button", { name: "Approve" })).toHaveLength(2))

    const queueBefore = requests().filter((r) => r.path === "/api/tasks/mine").length
    const permsBefore = requests().filter((r) => r.path === "/__whity/permitted-actions").length

    await userEvent.click(screen.getAllByRole("button", { name: "Approve" })[0])

    await waitFor(() => {
      expect(requests().filter((r) => r.path === "/api/tasks/mine").length).toBeGreaterThan(queueBefore)
      expect(requests().filter((r) => r.path === "/__whity/permitted-actions").length).toBeGreaterThan(permsBefore)
    })
  })

  it("shows the plugin emptyText when nothing awaits the caller", async () => {
    stubHost({ rows: [] })

    renderBlocks([
      {
            type: "inbox",
            source: "/api/tasks/mine",
            idField: "id",
            titleField: "title",
            emptyText: "Nothing awaiting you.",
            actions: [{ key: "a", label: "Approve", method: "POST", endpoint: "/api/tasks/{id}/approve" }],
      } as Block,
    ])

    expect(await screen.findByText("Nothing awaiting you.")).toBeInTheDocument()
  })
})
