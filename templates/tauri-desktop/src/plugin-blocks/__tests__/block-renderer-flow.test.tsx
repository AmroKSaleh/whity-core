/**
 * #950: the `flow` graph block on the desktop renderer.
 *
 * The desktop draws no canvas — this template carries no graph library, and
 * shipping one to an offline bundle to draw what is usually a straight line of
 * steps is a poor trade. It renders the same information as an ordered list of
 * nodes naming what each leads to.
 *
 * What is pinned here is therefore NOT the picture; it is the half that must
 * match the web renderer exactly, because it is contract behaviour rather than
 * rendering: which rows become nodes, which references become edges, where the
 * graph is cut, and that the cut is announced. The desktop renderer is a
 * hand-written twin that has silently diverged twice (#826, #847), and a
 * divergence in any of those four is a wrong diagram on a device, offline,
 * where nobody is looking.
 */

import React from "react"
import { render, screen, waitFor } from "@testing-library/react"
import { userEvent } from "@testing-library/user-event"
import { invoke } from "@tauri-apps/api/core"

import { BlockRenderer } from "../block-renderer"
import { FLOW_MAX_NODES, type Block, type PluginFeature } from "../types"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
const mockInvoke = invoke as jest.MockedFunction<typeof invoke>

type PhpRequest = { method: string; path: string; body: unknown }

/** An expense route that BRANCHES, which is the case worth pinning. */
const STEPS = [
  { id: "submitted", name: "Submitted", owner: "Requester", next: ["review"] },
  { id: "review", name: "Manager review", owner: "Line manager", next: ["finance", "rejected"] },
  { id: "finance", name: "Finance approval", owner: "Finance team", next: ["paid"] },
  { id: "paid", name: "Paid", owner: "Payroll", next: [] },
  { id: "rejected", name: "Rejected", owner: "Requester", next: [] },
]

function stubHost(rows: unknown = STEPS) {
  mockInvoke.mockImplementation((_command: string, args?: unknown) => {
    const { path } = args as PhpRequest
    if (path === "/api/acme/steps") return Promise.resolve({ status: 200, body: { data: rows } })
    return Promise.resolve({ status: 200, body: { data: {} } })
  })
}

function renderBlocks(blocks: Block[]) {
  const feature: PluginFeature = {
    id: "flow",
    plugin: "demo",
    label: "Flow",
    icon: null,
    group: "Demo",
    order: 1,
    screen: "blocks",
    requiredPermission: "",
    blocks,
  }
  return render(<BlockRenderer feature={feature} />)
}

const base = {
  type: "flow" as const,
  source: "/api/acme/steps",
  nodeIdField: "id",
  nodeLabelField: "name",
}

beforeEach(() => {
  jest.clearAllMocks()
  stubHost()
})

describe("flow block", () => {
  it("names each node's successors, expanding a list-valued edge field into a branch", async () => {
    renderBlocks([{ ...base, edgeToField: "next", nodeSubtitleField: "owner" } as Block])

    await waitFor(() => expect(screen.getAllByRole("listitem")).toHaveLength(STEPS.length))
    const items = screen.getAllByRole("listitem")

    // The branch is the interesting case: one step leading to two.
    expect(items[1]).toHaveTextContent("Manager review")
    expect(items[1]).toHaveTextContent("Line manager")
    expect(items[1]).toHaveTextContent("Finance approval, Rejected")
    // A terminal step names nothing after it, rather than an empty arrow.
    expect(items[3]).toHaveTextContent("Paid")
    expect(items[3].textContent).not.toContain("→")
  })

  it("chains the nodes in payload order when no edge field is declared", async () => {
    renderBlocks([{ ...base } as Block])

    await waitFor(() => expect(screen.getAllByRole("listitem")).toHaveLength(STEPS.length))
    const items = screen.getAllByRole("listitem")

    // Successor of each node is the next one, by position alone.
    expect(items[0]).toHaveTextContent("Submitted")
    expect(items[0]).toHaveTextContent("Manager review")
    expect(items[1]).toHaveTextContent("Finance approval")
  })

  it("drops a reference to an id no row declared rather than inventing a node", async () => {
    stubHost([
      { id: "a", name: "A", next: ["ghost", "b"] },
      { id: "b", name: "B", next: [] },
    ])
    renderBlocks([{ ...base, edgeToField: "next" } as Block])

    await waitFor(() => expect(screen.getAllByRole("listitem")).toHaveLength(2))
    const items = screen.getAllByRole("listitem")

    expect(screen.queryByText(/ghost/)).not.toBeInTheDocument()
    expect(items[0]).toHaveTextContent("A")
    expect(items[0].textContent).toContain("B")
  })

  it("cuts at the declared ceiling and SAYS it did, with the numbers", async () => {
    stubHost(Array.from({ length: 9 }, (_, i) => ({ id: `n${i}`, name: `Node ${i}` })))
    renderBlocks([{ ...base, maxNodes: 3 } as Block])

    await waitFor(() => expect(screen.getByText("Node 0")).toBeInTheDocument())

    expect(screen.getByText(/Showing the first 3 of 9 nodes/)).toBeInTheDocument()
    expect(screen.queryByText("Node 3")).not.toBeInTheDocument()
  })

  /**
   * The validator refuses a `maxNodes` above the contract ceiling, and the
   * renderer must not trust that: the block arrives over a wire.
   */
  it("refuses to be raised above the contract ceiling by a block that asks", async () => {
    stubHost(Array.from({ length: FLOW_MAX_NODES + 20 }, (_, i) => ({ id: `n${i}`, name: `Node ${i}` })))
    renderBlocks([{ ...base, maxNodes: FLOW_MAX_NODES + 10 } as Block])

    await waitFor(() => expect(screen.getByText("Node 0")).toBeInTheDocument())
    expect(screen.getByText(new RegExp(`Showing the first ${FLOW_MAX_NODES} of ${FLOW_MAX_NODES + 20}`))).toBeInTheDocument()
  })

  it("renders the plugin's own emptyText for an empty source", async () => {
    stubHost([])
    renderBlocks([{ ...base, emptyText: "No steps configured yet" } as Block])

    await waitFor(() => expect(screen.getByText("No steps configured yet")).toBeInTheDocument())
  })

  /**
   * `flow` declares no endpoint and no verb, so the block itself must never
   * issue a write — the same property `timeline` has.
   */
  it("issues no write of any kind", async () => {
    renderBlocks([{ ...base, edgeToField: "next" } as Block])

    await waitFor(() => expect(screen.getAllByRole("listitem")).toHaveLength(STEPS.length))

    for (const [, args] of mockInvoke.mock.calls) {
      expect((args as PhpRequest).method).toBe("GET")
    }
  })

  it("opens the targeted overlay from the node itself, publishing that node row", async () => {
    const user = userEvent.setup()
    renderBlocks([
      { ...base, nodeActions: [{ label: "Details", open: "step-drawer" }] } as Block,
      {
        type: "drawer",
        id: "step-drawer",
        title: "Step detail",
        children: [{ type: "heading", level: 3, text: "Step", textFrom: "step-drawer.name" }],
      } as Block,
    ])

    await waitFor(() => expect(screen.getByRole("button", { name: "Finance approval" })).toBeInTheDocument())
    await user.click(screen.getByRole("button", { name: "Finance approval" }))

    await waitFor(() =>
      expect(screen.getByRole("heading", { name: "Finance approval" })).toBeInTheDocument()
    )
  })
})
