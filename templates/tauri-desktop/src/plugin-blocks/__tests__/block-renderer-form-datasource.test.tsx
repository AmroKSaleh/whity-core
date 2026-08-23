/**
 * #957: a desktop form's `dataSource.path` is interpolated, and NOT FETCHED AT
 * ALL until every `{token}` in it is bound — the twin of web's #949 suite.
 *
 * The path went to `usePluginData` verbatim, so a form declared on a record
 * pane asked the host for `/api/v1/people/%7Brecord%7D` and pre-populated with
 * nothing. What makes that worse than a broken read is what an empty form
 * MEANS: it is indistinguishable from a record that genuinely holds no values
 * yet, and against an update endpoint that replaces rather than merges,
 * submitting it writes blanks over every field the user did not retype — and
 * returns success.
 *
 * And this renderer is the offline one. A device runs against its own local
 * host with nobody watching, and the write syncs later: the blanked row
 * replicates as a legitimate edit, and by the time anyone notices, the original
 * values exist nowhere. Nothing downstream can tell that apart from an
 * intentional clear.
 *
 * So the assertions come in pairs, and the negative half is the load-bearing
 * one. It is not enough that the resolved path is right; the UNRESOLVED path
 * must produce no request. `/api/v1/people/{record}` truncated to
 * `/api/v1/people/` is the collection, and that request would not fail — it
 * would succeed and prefill the form with the first thing in the list.
 *
 * The `@tauri-apps/api` transport is mocked; the block tree and the UI
 * primitives are the ones that ship.
 */

import React from "react"
import { render, screen, waitFor } from "@testing-library/react"
import { userEvent } from "@testing-library/user-event"
import { invoke } from "@tauri-apps/api/core"

import { BlockRenderer } from "../block-renderer"
import type { Block, PluginFeature } from "../types"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
const mockInvoke = invoke as jest.MockedFunction<typeof invoke>

type PhpRequest = { method: string; path: string; body: unknown }

// Radix Select uses Pointer Capture + scrollIntoView, which jsdom does not
// implement — polyfill them so the selector's dropdown can open.
beforeAll(() => {
  window.HTMLElement.prototype.hasPointerCapture = jest.fn()
  window.HTMLElement.prototype.setPointerCapture = jest.fn()
  window.HTMLElement.prototype.releasePointerCapture = jest.fn()
  window.HTMLElement.prototype.scrollIntoView = jest.fn()
})

beforeEach(() => {
  jest.clearAllMocks()
  mockInvoke.mockImplementation((_command: string, args?: unknown) => {
    const { path } = args as PhpRequest
    // The selector's own options — and, deliberately, the answer the COLLECTION
    // endpoint gives. A truncated `/api/v1/people/` must not reach it, but if
    // it ever does this stub answers 200 rather than failing, exactly as the
    // real route would.
    if (path === "/api/v1/people" || path.startsWith("/api/v1/people?") || path === "/api/v1/people/") {
      return Promise.resolve({
        status: 200,
        body: { data: [{ id: "7", name: "Ada" }, { id: "8", name: "Grace" }] },
      })
    }
    // Any ONE person: the stored record the form is there to edit.
    if (path.startsWith("/api/v1/people/")) {
      return Promise.resolve({ status: 200, body: { data: { full_name: "Ada Lovelace", title: "Engineer" } } })
    }
    return Promise.resolve({ status: 200, body: { data: null } })
  })
})

function renderBlocks(blocks: Block[], record?: string) {
  const feature: PluginFeature = {
    id: "people",
    plugin: "demo",
    label: "People",
    icon: null,
    group: "Demo",
    order: 1,
    screen: "blocks",
    requiredPermission: "",
    blocks,
  }
  return render(<BlockRenderer feature={feature} record={record} />)
}

/**
 * The edit form at the heart of the issue: one record, named by a token.
 *
 * `token` is which binding names it — `record` is the reserved one a record
 * route seeds, anything else is a `selector` on the page. The form is the same
 * form either way, which is the property being tested.
 */
function editForm(token = "record"): Block {
  return {
    type: "form",
    submit: { method: "PATCH", endpoint: `/api/v1/people/{${token}}` },
    dataSource: { method: "GET", path: `/api/v1/people/{${token}}` },
    children: [
      { type: "textInput", name: "full_name", label: "Full name" },
      { type: "submitButton", label: "Save" },
    ],
  } as unknown as Block
}

/** Every path the host was asked for. */
function requestedPaths(): string[] {
  return mockInvoke.mock.calls.map(([, args]) => (args as PhpRequest).path)
}

/** Every path the form's own preload could have used, resolved or not. */
function recordFetches(): string[] {
  return requestedPaths().filter((path) => path.startsWith("/api/v1/people/"))
}

// ---------------------------------------------------------------------------

describe("#957 — an unbound token means no fetch", () => {
  it("does not fetch while the token naming the record is unresolved", async () => {
    // No `record` prop and no selection: nothing has said which person.
    renderBlocks([editForm()])

    await screen.findByRole("textbox", { name: /full name/i })
    // Give any effect that wanted to fire the chance to have fired.
    await waitFor(() => expect(screen.getByRole("button", { name: /save/i })).toBeInTheDocument())

    expect(recordFetches()).toEqual([])
    expect(mockInvoke).not.toHaveBeenCalled()
  })

  it("never sends the truncated path that would return the collection", async () => {
    // The failure mode this whole issue is about: "" for the missing token
    // turns /people/{record} into /people/, which succeeds and returns a list.
    renderBlocks([editForm()])

    await screen.findByRole("textbox", { name: /full name/i })
    for (const path of requestedPaths()) {
      expect(path).not.toBe("/api/v1/people/")
      expect(path).not.toBe("/api/v1/people")
    }
  })

  it("never sends the token through un-substituted either, which is what it used to do", async () => {
    renderBlocks([editForm()])

    await screen.findByRole("textbox", { name: /full name/i })
    for (const path of requestedPaths()) {
      expect(path).not.toContain("{")
      expect(path).not.toContain("%7B")
    }
  })

  it("leaves the form disabled and says why, rather than looking like an empty record", async () => {
    // The pair that matters: an enabled empty form is a data-loss path, because
    // submitting it blanks every field the user did not retype.
    renderBlocks([editForm()])

    expect(await screen.findByRole("textbox", { name: /full name/i })).toBeDisabled()
    expect(screen.getByText(/no record selected/i)).toBeInTheDocument()
  })

  it("disables the submit too — the button is what actually writes the blanks", async () => {
    renderBlocks([editForm()])

    expect(await screen.findByRole("button", { name: /save/i })).toBeDisabled()
    await userEvent.click(screen.getByRole("button", { name: /save/i }))
    // Not "no write yet": no request of any kind, so nothing was submitted.
    expect(mockInvoke).not.toHaveBeenCalled()
  })
})

describe("#957 — the same form fetches once the token resolves", () => {
  it("fetches the substituted path when the route names the record", async () => {
    renderBlocks([editForm()], "7")

    await waitFor(() =>
      expect(screen.getByRole("textbox", { name: /full name/i })).toHaveValue("Ada Lovelace"),
    )
    expect(recordFetches()).toEqual(["/api/v1/people/7"])
    expect(mockInvoke).toHaveBeenCalledWith(
      "php_request",
      expect.objectContaining({ method: "GET", path: "/api/v1/people/7" }),
    )
  })

  it("re-enables the form and drops the unbound notice once the record loads", async () => {
    renderBlocks([editForm()], "7")

    await waitFor(() => expect(screen.getByRole("textbox", { name: /full name/i })).not.toBeDisabled())
    expect(screen.queryByText(/no record selected/i)).not.toBeInTheDocument()
  })

  it("URL-encodes the resolved value, as every other interpolated path does", async () => {
    renderBlocks([editForm()], "a/b")

    await waitFor(() => expect(recordFetches()).toEqual(["/api/v1/people/a%2Fb"]))
  })

  it("goes from no fetch to exactly one fetch when a selector binds the token", async () => {
    renderBlocks([
      {
        type: "selector",
        name: "person",
        label: "Person",
        source: "/api/v1/people",
        valueField: "id",
        labelField: "name",
      } as unknown as Block,
      editForm("person"),
    ])

    // Before the choice: the selector has fetched its options, the form has
    // fetched nothing.
    await waitFor(() => expect(screen.getByRole("combobox")).not.toBeDisabled())
    expect(recordFetches()).toEqual([])

    await userEvent.click(screen.getByRole("combobox"))
    await userEvent.click(await screen.findByRole("option", { name: "Ada" }))

    await waitFor(() =>
      expect(screen.getByRole("textbox", { name: /full name/i })).toHaveValue("Ada Lovelace"),
    )
    // One fetch, at the resolved path — not one per render of the bound form.
    expect(recordFetches()).toEqual(["/api/v1/people/7"])
  })
})

describe("#957 — a path with no tokens is unaffected", () => {
  const settingsForm: Block = {
    type: "form",
    submit: { method: "PUT", endpoint: "/api/v1/x/settings" },
    dataSource: { method: "GET", path: "/api/v1/x/settings" },
    children: [
      { type: "textInput", name: "site_name", label: "Site name" },
      { type: "submitButton", label: "Save" },
    ],
  } as unknown as Block

  beforeEach(() => {
    mockInvoke.mockImplementation(() =>
      Promise.resolve({ status: 200, body: { data: { site_name: "Acme Corp" } } }),
    )
  })

  it("fetches the literal path on mount and pre-populates from it", async () => {
    renderBlocks([settingsForm])

    await waitFor(() => expect(screen.getByRole("textbox", { name: /site name/i })).toHaveValue("Acme Corp"))
    expect(requestedPaths()).toEqual(["/api/v1/x/settings"])
  })

  it("is never treated as unbound — a form with nothing to resolve is bound already", async () => {
    renderBlocks([settingsForm])

    await waitFor(() => expect(screen.getByRole("textbox", { name: /site name/i })).not.toBeDisabled())
    expect(screen.queryByText(/no record selected/i)).not.toBeInTheDocument()
  })
})

describe("#957 — a form that declares no dataSource asks for nothing", () => {
  // The `"__no_data_source__"` sentinel this renderer used to pass in its place
  // was not an empty string, so `usePluginData` FETCHED IT: every plain create
  // form cost the offline host one doomed round trip, and whatever came back
  // landed in that form's preload state. `""` is the hook's own "nothing to
  // fetch", which is what "no dataSource" and "an unbound one" now share.
  it("issues no request at all, and stays enabled", async () => {
    renderBlocks([
      {
        type: "form",
        submit: { method: "POST", endpoint: "/api/v1/people" },
        children: [
          { type: "textInput", name: "full_name", label: "Full name" },
          { type: "submitButton", label: "Save" },
        ],
      } as unknown as Block,
    ])

    const input = await screen.findByRole("textbox", { name: /full name/i })
    await waitFor(() => expect(screen.getByRole("button", { name: /save/i })).toBeInTheDocument())

    expect(requestedPaths()).toEqual([])
    expect(input).not.toBeDisabled()
    expect(screen.queryByText(/no record selected/i)).not.toBeInTheDocument()
  })
})
