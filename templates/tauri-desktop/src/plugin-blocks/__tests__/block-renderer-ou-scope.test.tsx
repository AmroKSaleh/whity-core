/**
 * #868: the `ouScopePicker` block on the desktop renderer — and, deliberately,
 * the SAME contract as `web/__tests__/block-renderer-ou-scope.test.tsx`.
 *
 * The desktop renderer is a hand-written twin of the web one and has silently
 * diverged twice: #826 declared props that nothing rendered, #847 shipped a form
 * that submitted a different payload than it showed. This block is a form input
 * whose entire output is one value in that payload, so the twin lands with the
 * VALUE pinned rather than the pixels:
 *
 *   - every write carries the complete rule `{unit, scope, type}`;
 *   - "this unit" and "this unit and its subtree" are distinguishable values;
 *   - `(unit: null, scope: 'unit')` is unreachable through the controls;
 *   - a `unit` scope always carries `type: null`;
 *   - `anchorType` narrows the fetch, `memberType` pins the value and costs no
 *     vocabulary request;
 *   - an untouched picker submits NOTHING;
 *   - the units come from the HOST's own OU endpoints and nowhere else.
 *
 * The `@tauri-apps/api` transport is mocked; everything else — the real block
 * tree, the real UI primitives — runs as it ships. A host that serves no OU
 * routes (today's offline host does not) takes the same code path and lands in
 * the error state asserted below, rather than in a hard-coded "not here" branch
 * that would rot the day a host does serve them.
 */

import React from "react"
import { render, screen, waitFor } from "@testing-library/react"
import { userEvent } from "@testing-library/user-event"
import { invoke } from "@tauri-apps/api/core"

import { BlockRenderer } from "../block-renderer"
import type { Block, PluginFeature } from "../types"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
const mockInvoke = invoke as jest.MockedFunction<typeof invoke>

/** A small hierarchy: two roots, one with two children. */
const UNITS = [
  { id: 1, name: "Engineering", parent_id: null, ou_type_key: "faculty" },
  { id: 2, name: "Science", parent_id: null, ou_type_key: "faculty" },
  { id: 3, name: "Software", parent_id: 1, ou_type_key: "department" },
  { id: 4, name: "Civil", parent_id: 1, ou_type_key: "department" },
]

const OU_TYPES = [
  { id: 10, key: "faculty", label: "Faculty", sort_order: 10 },
  { id: 11, key: "department", label: "Department", sort_order: 20 },
]

type PhpRequest = { method: string; path: string; body: unknown }

// Radix Select uses Pointer Capture + scrollIntoView, which jsdom does not
// implement — polyfill them so the dropdowns can open in tests.
beforeAll(() => {
  window.HTMLElement.prototype.hasPointerCapture = jest.fn()
  window.HTMLElement.prototype.setPointerCapture = jest.fn()
  window.HTMLElement.prototype.releasePointerCapture = jest.fn()
  window.HTMLElement.prototype.scrollIntoView = jest.fn()
})

function stubHost(options: { units?: unknown[]; types?: unknown[]; unitsFail?: boolean } = {}) {
  const { units = UNITS, types = OU_TYPES, unitsFail = false } = options

  mockInvoke.mockImplementation((_command: string, args?: unknown) => {
    const { path } = args as PhpRequest
    if (path.startsWith("/api/v1/ou-types")) {
      return Promise.resolve({ status: 200, body: { data: types } })
    }
    if (path.startsWith("/api/v1/ous")) {
      if (unitsFail) return Promise.resolve({ status: 404, body: {} })
      return Promise.resolve({ status: 200, body: { data: units } })
    }
    return Promise.resolve({ status: 200, body: { data: {} } })
  })
}

/** Every path requested, in order. */
function requestedPaths(): string[] {
  return mockInvoke.mock.calls.map(([, args]) => (args as PhpRequest).path)
}

/** The body of the last write request, or undefined if nothing was submitted. */
function submittedBody(): Record<string, unknown> | undefined {
  const write = [...mockInvoke.mock.calls].reverse().find(([, args]) => (args as PhpRequest).method !== "GET")
  return write === undefined ? undefined : ((write[1] as PhpRequest).body as Record<string, unknown>)
}

function renderBlocks(blocks: Block[]) {
  const feature: PluginFeature = {
    id: "scope",
    plugin: "demo",
    label: "Scope",
    icon: null,
    group: "Demo",
    order: 1,
    screen: "blocks",
    requiredPermission: "",
    blocks,
  }
  return render(<BlockRenderer feature={feature} />)
}

/** A form whose only input is the picker, plus a submit button. */
function pickerForm(extra: Record<string, unknown> = {}): Block {
  return {
    type: "form",
    submit: { method: "POST", endpoint: "/api/x/save" },
    children: [
      { type: "ouScopePicker", name: "appliesTo", label: "Applies to", ...extra } as Block,
      { type: "submitButton", label: "Save" } as Block,
    ],
  } as Block
}

/** Open the named combobox and click the option with the given text. */
async function choose(comboboxName: RegExp, optionText: RegExp | string): Promise<void> {
  await userEvent.click(screen.getByRole("combobox", { name: comboboxName }))
  await userEvent.click(await screen.findByRole("option", { name: optionText }))
}

/** Wait for the unit dropdown to finish loading. */
async function ready(): Promise<void> {
  await waitFor(() => expect(screen.getByRole("combobox", { name: /applies to/i })).not.toBeDisabled())
}

beforeEach(() => {
  jest.clearAllMocks()
  stubHost()
})

describe("ouScopePicker — where the data comes from", () => {
  it("asks the host for the OU list and type vocabulary, and for nothing plugin-owned", async () => {
    renderBlocks([pickerForm()])
    await ready()

    // `__no_data_source__` is FormRenderer's own sentinel for a form with no
    // `dataSource` — it belongs to the enclosing form, not to the picker.
    const paths = requestedPaths().filter((p) => p !== "__no_data_source__")
    expect(paths.some((p) => p.startsWith("/api/v1/ous"))).toBe(true)
    expect(paths.some((p) => p.startsWith("/api/v1/ou-types"))).toBe(true)
    // The block declares no `source`, so there is nothing else it could fetch.
    expect(paths.every((p) => p.startsWith("/api/v1/ous") || p.startsWith("/api/v1/ou-types"))).toBe(true)
  })

  it("narrows the ANCHOR list at the source with `anchorType`, not client-side", async () => {
    renderBlocks([pickerForm({ anchorType: "faculty" })])
    await ready()

    expect(requestedPaths().some((p) => p.startsWith("/api/v1/ous?type=faculty"))).toBe(true)
  })

  it("costs no vocabulary request when `memberType` pins the kind", async () => {
    renderBlocks([pickerForm({ memberType: "department" })])
    await ready()

    expect(requestedPaths().some((p) => p.startsWith("/api/v1/ou-types"))).toBe(false)
    expect(screen.queryByRole("combobox", { name: /kind/i })).not.toBeInTheDocument()
  })

  it("exhausts pagination rather than offering page 1 as the whole tree (#870/#824)", async () => {
    mockInvoke.mockImplementation((_command: string, args?: unknown) => {
      const { path } = args as PhpRequest
      if (path.startsWith("/api/v1/ou-types")) {
        return Promise.resolve({ status: 200, body: { data: OU_TYPES } })
      }
      if (path.includes("page=")) {
        return Promise.resolve({
          status: 200,
          body: { data: UNITS, pagination: { page: 1, perPage: 100, total: 4, totalPages: 1 } },
        })
      }
      return Promise.resolve({
        status: 200,
        body: { data: UNITS.slice(0, 2), pagination: { page: 1, perPage: 2, total: 4, totalPages: 2 } },
      })
    })

    renderBlocks([pickerForm()])
    await ready()
    await userEvent.click(screen.getByRole("combobox", { name: /applies to/i }))

    // Unit 4 lives past the first page; a truncated list would drop it.
    expect(await screen.findByRole("option", { name: /Civil/ })).toBeInTheDocument()
  })

  it("reports a retry when the host serves no OU routes — the offline host's answer today", async () => {
    stubHost({ unitsFail: true })
    renderBlocks([pickerForm()])

    expect(await screen.findByText(/couldn't load organizational units/i)).toBeInTheDocument()
    expect(screen.getByRole("button", { name: /retry/i })).toBeInTheDocument()
  })

  it("degrades to a placeholder outside a form, and fetches nothing", () => {
    renderBlocks([{ type: "ouScopePicker", name: "appliesTo", label: "Applies to" } as Block])

    expect(screen.queryByRole("combobox", { name: /applies to/i })).not.toBeInTheDocument()
    expect(mockInvoke).not.toHaveBeenCalled()
  })
})

describe("ouScopePicker — the unit list reads as a hierarchy", () => {
  it("orders parents before their children and keeps every unit", async () => {
    renderBlocks([pickerForm()])
    await ready()
    await userEvent.click(screen.getByRole("combobox", { name: /applies to/i }))

    const options = (await screen.findAllByRole("option")).map((el) => (el.textContent ?? "").trim())
    expect(options[0]).toMatch(/all organizational units/i)
    expect(options.slice(1)).toEqual(["Engineering", "Civil", "Software", "Science"])
  })

  it("keeps a unit whose parent is absent from the list rather than dropping it", async () => {
    stubHost({ units: [{ id: 3, name: "Software", parent_id: 1 }] })
    renderBlocks([pickerForm({ anchorType: "department" })])
    await ready()
    await userEvent.click(screen.getByRole("combobox", { name: /applies to/i }))

    expect(await screen.findByRole("option", { name: /Software/ })).toBeInTheDocument()
  })
})

describe("ouScopePicker — the value it writes", () => {
  it("submits nothing at all while untouched, so a stored rule is never blanked", async () => {
    renderBlocks([pickerForm()])
    await ready()
    await userEvent.click(screen.getByRole("button", { name: /save/i }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    expect(submittedBody()).toEqual({})
  })

  it("writes the COMPLETE rule on the first touch — never a partial patch", async () => {
    renderBlocks([pickerForm()])
    await ready()
    await choose(/applies to/i, /Engineering/)
    await userEvent.click(screen.getByRole("button", { name: /save/i }))

    await waitFor(() => expect(submittedBody()?.appliesTo).toBeDefined())
    const rule = submittedBody()?.appliesTo as Record<string, unknown>
    expect(Object.keys(rule).sort()).toEqual(["scope", "type", "unit"])
    expect(rule.unit).toBe(1)
    expect(typeof rule.scope).toBe("string")
  })

  it('distinguishes "this unit" from "this unit and its subtree"', async () => {
    renderBlocks([pickerForm()])
    await ready()
    await choose(/applies to/i, /Engineering/)

    await choose(/scope/i, /this unit only/i)
    await userEvent.click(screen.getByRole("button", { name: /save/i }))
    await waitFor(() => expect(submittedBody()?.appliesTo).toBeDefined())
    expect(submittedBody()?.appliesTo).toEqual({ unit: 1, scope: "unit", type: null })

    await choose(/scope/i, /everything below it/i)
    await userEvent.click(screen.getByRole("button", { name: /save/i }))
    await waitFor(() => expect((submittedBody()?.appliesTo as Record<string, unknown>)?.scope).toBe("subtree"))
    expect(submittedBody()?.appliesTo).toEqual({ unit: 1, scope: "subtree", type: null })
  })

  it("carries the chosen kind, and drops it the moment the scope becomes `unit`", async () => {
    renderBlocks([pickerForm({ scopes: ["subtree", "unit"] })])
    await ready()
    await choose(/applies to/i, /Engineering/)
    await choose(/kind/i, /Department/)

    await userEvent.click(screen.getByRole("button", { name: /save/i }))
    await waitFor(() => expect(submittedBody()?.appliesTo).toBeDefined())
    expect(submittedBody()?.appliesTo).toEqual({ unit: 1, scope: "subtree", type: "department" })

    await choose(/scope/i, /this unit only/i)
    expect(screen.queryByRole("combobox", { name: /kind/i })).not.toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: /save/i }))
    await waitFor(() => expect((submittedBody()?.appliesTo as Record<string, unknown>)?.scope).toBe("unit"))
    expect(submittedBody()?.appliesTo).toEqual({ unit: 1, scope: "unit", type: null })
  })

  it("pins the kind from `memberType` without a control, on every write", async () => {
    renderBlocks([pickerForm({ memberType: "department" })])
    await ready()
    await choose(/applies to/i, /Engineering/)
    await userEvent.click(screen.getByRole("button", { name: /save/i }))

    await waitFor(() => expect(submittedBody()?.appliesTo).toBeDefined())
    expect((submittedBody()?.appliesTo as Record<string, unknown>).type).toBe("department")
  })

  it('never produces the (unit: null, scope: "unit") row of the resolution table', async () => {
    renderBlocks([pickerForm()])
    await ready()
    await choose(/applies to/i, /Engineering/)
    await choose(/scope/i, /this unit only/i)

    await choose(/applies to/i, /all organizational units/i)
    await userEvent.click(screen.getByRole("button", { name: /save/i }))

    await waitFor(() => expect(submittedBody()?.appliesTo).toBeDefined())
    const rule = submittedBody()?.appliesTo as Record<string, unknown>
    expect(rule.unit).toBeNull()
    expect(rule.scope).not.toBe("unit")
  })

  it("writes a tenant-wide rule when no anchor is chosen", async () => {
    renderBlocks([pickerForm({ scopes: ["subtree", "children"] })])
    await ready()
    await choose(/scope/i, /direct children only/i)
    await userEvent.click(screen.getByRole("button", { name: /save/i }))

    await waitFor(() => expect(submittedBody()?.appliesTo).toBeDefined())
    expect(submittedBody()?.appliesTo).toEqual({ unit: null, scope: "children", type: null })
  })
})

describe("ouScopePicker — what the author controls", () => {
  it("offers only the declared scopes, in the declared order", async () => {
    renderBlocks([pickerForm({ scopes: ["children", "subtree"] })])
    await ready()
    await userEvent.click(screen.getByRole("combobox", { name: /scope/i }))

    const options = (await screen.findAllByRole("option")).map((el) => (el.textContent ?? "").trim())
    expect(options).toEqual(["Direct children only", "This unit and everything below it"])
  })

  it("collapses the scope control when only one scope is offerable", async () => {
    renderBlocks([pickerForm({ scopes: ["subtree"] })])
    await ready()

    expect(screen.queryByRole("combobox", { name: /scope/i })).not.toBeInTheDocument()
  })

  it("drops the tenant-wide option when `required` — the rule must be anchored", async () => {
    renderBlocks([pickerForm({ required: true })])
    await ready()
    await userEvent.click(screen.getByRole("combobox", { name: /applies to/i }))

    const options = (await screen.findAllByRole("option")).map((el) => (el.textContent ?? "").trim())
    expect(options).not.toContain("All organizational units")
    expect(options).toContain("Engineering")
  })
})
