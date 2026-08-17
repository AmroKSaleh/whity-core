/**
 * #847: what a desktop block form SUBMITS has to be what it SHOWS.
 *
 * The value map used to start empty and the resolved defaults only ever reached
 * the rendered `value=`, so a `defaultFrom`-seeded edit form displayed the row
 * and posted nothing. Against the sync endpoints — full-row replaces that write
 * every domain column as `values[col] ?? null` — editing one field nulled every
 * field the user had not touched, and reported success.
 *
 * These tests pin the payload, not the pixels: the map is seeded at mount, a
 * seed never beats a user edit, a context change after mount never re-seeds
 * (only a remount does), and an input that declares no default still submits
 * nothing at all.
 *
 * The renderer's `@tauri-apps/api` transport is mocked; everything else — the
 * real block tree, the real UI primitives — runs as it ships.
 */

import React from "react"
import { render, screen, waitFor } from "@testing-library/react"
import { userEvent } from "@testing-library/user-event"
import { invoke } from "@tauri-apps/api/core"

import { BlockRenderer } from "../block-renderer"
import type { Block, PluginFeature } from "../types"

jest.mock("@tauri-apps/api/core", () => ({ invoke: jest.fn() }))
const mockInvoke = invoke as jest.MockedFunction<typeof invoke>

/** Two rows with the shapes that matter: a populated one, and one carrying the
 * values a truthiness test would drop (an empty string and a `false`). */
const PEOPLE = [
  {
    id: 7,
    displayName: "Ada Lovelace",
    birthDate: "1815-12-10",
    notes: "Analytical engine",
    deceased: true,
  },
  { id: 9, displayName: "Grace Hopper", birthDate: "1906-12-09", notes: "", deceased: false },
]

type PhpRequest = { method: string; path: string; body: unknown }

/** Answers the host proxy: the people collection for the table, `{data: null}`
 * (→ "empty", never an error) for everything else, including submits. */
function stubHost(routes: Record<string, unknown> = {}) {
  mockInvoke.mockImplementation((_command: string, args?: unknown) => {
    const { method, path } = args as PhpRequest
    if (method === "GET" && path in routes) {
      return Promise.resolve({ status: 200, body: { data: routes[path] } })
    }
    return Promise.resolve({ status: 200, body: { data: null } })
  })
}

/** The body of the last write request, or undefined if nothing was submitted. */
function submittedBody(): Record<string, unknown> | undefined {
  const write = [...mockInvoke.mock.calls]
    .reverse()
    .find(([, args]) => (args as PhpRequest).method !== "GET")
  return write === undefined ? undefined : ((write[1] as PhpRequest).body as Record<string, unknown>)
}

function renderBlocks(blocks: Block[]) {
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
  return render(<BlockRenderer feature={feature} />)
}

/** A people table whose row action opens an edit form seeded from the row. */
function editTree(children: Block[]): Block[] {
  return [
    {
      type: "dataTable",
      source: "/api/persons",
      columns: [{ key: "displayName", label: "Name" }],
      rowActions: [{ label: "Edit", open: "edit-person" }],
    },
    {
      type: "modal",
      id: "edit-person",
      title: "Edit person",
      children: [
        {
          type: "form",
          submit: { method: "PATCH", endpoint: "/api/persons/{edit-person.id}" },
          children: [...children, { type: "submitButton", label: "Save" }],
        },
      ],
    },
  ]
}

const PERSON_FIELDS: Block[] = [
  {
    type: "textInput",
    name: "displayName",
    label: "Name",
    required: true,
    defaultFrom: "edit-person.displayName",
  },
  { type: "dateInput", name: "birthDate", label: "Born", defaultFrom: "edit-person.birthDate" },
  { type: "textArea", name: "notes", label: "Notes", defaultFrom: "edit-person.notes" },
  { type: "checkbox", name: "deceased", label: "Deceased", defaultFrom: "edit-person.deceased" },
]

/** Opens the edit modal for the row at `index` and waits for it to be there. */
async function openEditor(index: number) {
  const buttons = await screen.findAllByRole("button", { name: "Edit" })
  await userEvent.click(buttons[index])
  await screen.findByText("Edit person")
}

beforeEach(() => {
  jest.clearAllMocks()
  stubHost({ "/api/persons": PEOPLE })
})

describe("desktop form defaults — the submitted payload", () => {
  it("submits every seeded field of an edit form the user never touched", async () => {
    renderBlocks(editTree(PERSON_FIELDS))
    await openEditor(0)
    expect(await screen.findByDisplayValue("Ada Lovelace")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Save" }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    expect(submittedBody()).toEqual({
      displayName: "Ada Lovelace",
      birthDate: "1815-12-10",
      notes: "Analytical engine",
      deceased: true,
    })
  })

  it("keeps the untouched fields intact when one field is edited", async () => {
    renderBlocks(editTree(PERSON_FIELDS))
    await openEditor(0)

    const name = await screen.findByDisplayValue("Ada Lovelace")
    await userEvent.clear(name)
    await userEvent.type(name, "Ada King")
    await userEvent.click(screen.getByRole("button", { name: "Save" }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    expect(submittedBody()).toEqual({
      displayName: "Ada King",
      birthDate: "1815-12-10",
      notes: "Analytical engine",
      deceased: true,
    })
  })

  it("seeds the falsy values a truthiness test would have dropped", async () => {
    renderBlocks(editTree(PERSON_FIELDS))
    await openEditor(1)
    expect(await screen.findByDisplayValue("Grace Hopper")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Save" }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    // An empty string and a `false` are the row's REAL values, not absences —
    // dropping them is the data loss, since the endpoint replaces the row.
    expect(submittedBody()).toEqual({
      displayName: "Grace Hopper",
      birthDate: "1906-12-09",
      notes: "",
      deceased: false,
    })
  })

  it("leaves an input that declares no default out of the payload entirely", async () => {
    renderBlocks(
      editTree([
        {
          type: "textInput",
          name: "displayName",
          label: "Name",
          defaultFrom: "edit-person.displayName",
        },
        { type: "textInput", name: "nickname", label: "Nickname" },
        { type: "textArea", name: "epitaph", label: "Epitaph" },
        { type: "checkbox", name: "starred", label: "Starred" },
      ]),
    )
    await openEditor(0)
    await userEvent.click(screen.getByRole("button", { name: "Save" }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    // Seeding "" / false for these would be the same data loss in the opposite
    // direction: three stored columns blanked by a form that showed nothing.
    expect(submittedBody()).toEqual({ displayName: "Ada Lovelace" })
  })

  it("seeds literal defaults with no master-detail context in play", async () => {
    renderBlocks([
      {
        type: "form",
        submit: { method: "POST", endpoint: "/api/persons" },
        children: [
          { type: "textInput", name: "displayName", label: "Name", default: "Unnamed" },
          {
            type: "select",
            name: "status",
            label: "Status",
            default: "active",
            options: [
              { value: "active", label: "Active" },
              { value: "archived", label: "Archived" },
            ],
          },
          { type: "checkbox", name: "deceased", label: "Deceased", default: false },
          { type: "slider", name: "rating", label: "Rating", min: 0, max: 10, default: "4" },
          { type: "numberInput", name: "weight", label: "Weight", default: "70" },
          { type: "submitButton", label: "Create" },
        ],
      },
    ])

    await userEvent.click(await screen.findByRole("button", { name: "Create" }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    expect(submittedBody()).toEqual({
      displayName: "Unnamed",
      status: "active",
      deceased: false,
      // Numeric inputs seed in the type this renderer's own onChange stores, so
      // a seeded field and a dragged/typed one are indistinguishable downstream.
      rating: 4,
      weight: "70",
    })
  })

  it("seeds inputs nested any depth down inside layout containers", async () => {
    renderBlocks(
      editTree([
        {
          type: "card",
          title: "Identity",
          children: [
            {
              type: "grid",
              columns: 2,
              children: [
                {
                  type: "textInput",
                  name: "displayName",
                  label: "Name",
                  defaultFrom: "edit-person.displayName",
                },
                {
                  type: "section",
                  children: [
                    {
                      type: "textArea",
                      name: "notes",
                      label: "Notes",
                      defaultFrom: "edit-person.notes",
                    },
                  ],
                },
              ],
            },
          ],
        },
      ]),
    )
    await openEditor(0)
    await userEvent.click(screen.getByRole("button", { name: "Save" }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    expect(submittedBody()).toEqual({
      displayName: "Ada Lovelace",
      notes: "Analytical engine",
    })
  })

  it("seeds a min-constrained fieldArray with the rows it displays, and an unconstrained one with nothing", async () => {
    renderBlocks([
      {
        type: "form",
        submit: { method: "POST", endpoint: "/api/persons" },
        children: [
          {
            type: "fieldArray",
            name: "contacts",
            label: "Contacts",
            min: 2,
            children: [
              { type: "textInput", name: "kind", label: "Kind", default: "email" },
              { type: "textInput", name: "value", label: "Value" },
            ],
          },
          {
            type: "fieldArray",
            name: "awards",
            label: "Awards",
            children: [{ type: "textInput", name: "title", label: "Title" }],
          },
          { type: "submitButton", label: "Create" },
        ],
      },
    ])

    await userEvent.click(await screen.findByRole("button", { name: "Create" }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    // `min: 2` shows two rows, so two rows are what it submits; the optional
    // array shows none and stays absent rather than replacing a stored
    // collection with [].
    expect(submittedBody()).toEqual({
      contacts: [{ kind: "email" }, { kind: "email" }],
    })
  })
})

describe("desktop form defaults — when the seed runs", () => {
  it("does not re-seed when the master-detail context changes under an open form", async () => {
    renderBlocks([
      {
        type: "dataTable",
        source: "/api/persons",
        columns: [{ key: "displayName", label: "Name" }],
        rowActions: [{ label: "Pick", open: "picked" }],
      },
      {
        type: "form",
        submit: { method: "POST", endpoint: "/api/persons" },
        children: [
          {
            type: "textInput",
            name: "displayName",
            label: "Name",
            defaultFrom: "picked.displayName",
            default: "Unnamed",
          },
          { type: "submitButton", label: "Create" },
        ],
      },
    ])

    // Mounted with nothing published, so the literal default seeded it.
    const name = await screen.findByDisplayValue("Unnamed")
    await userEvent.clear(name)
    await userEvent.type(name, "Katherine Johnson")

    // A row is published while the form is open and half-filled.
    await userEvent.click((await screen.findAllByRole("button", { name: "Pick" }))[0])

    // The edit stands. The context object changes on any selector move or
    // overlay open anywhere in the feature, so re-seeding on it would throw
    // away typing because something unrelated on screen moved.
    expect(screen.getByDisplayValue("Katherine Johnson")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: "Create" }))
    await waitFor(() => expect(submittedBody()).toBeDefined())
    expect(submittedBody()).toEqual({ displayName: "Katherine Johnson" })
  })

  it("re-seeds from the new row when an overlay is reopened, because it remounts", async () => {
    renderBlocks(editTree(PERSON_FIELDS))

    await openEditor(0)
    expect(await screen.findByDisplayValue("Ada Lovelace")).toBeInTheDocument()

    // Dismissed, not submitted — the overlay unmounts its content...
    await userEvent.keyboard("{Escape}")
    await waitFor(() => expect(screen.queryByText("Edit person")).not.toBeInTheDocument())

    // ...so opening another row mounts a fresh form, which seeds from that row.
    await openEditor(1)
    expect(await screen.findByDisplayValue("Grace Hopper")).toBeInTheDocument()

    await userEvent.click(screen.getByRole("button", { name: "Save" }))
    await waitFor(() => expect(submittedBody()).toBeDefined())
    expect(submittedBody()).toMatchObject({ displayName: "Grace Hopper" })
  })

  it("lets a loaded dataSource win over the literal defaults it is there to replace", async () => {
    stubHost({ "/api/persons": PEOPLE, "/api/settings": { theme: "dark", label: "Ops" } })
    renderBlocks([
      {
        type: "form",
        dataSource: { method: "GET", path: "/api/settings" },
        submit: { method: "PUT", endpoint: "/api/settings" },
        children: [
          { type: "textInput", name: "theme", label: "Theme", default: "light" },
          { type: "textInput", name: "label", label: "Label" },
          { type: "textInput", name: "region", label: "Region", default: "eu" },
          { type: "submitButton", label: "Save" },
        ],
      },
    ])

    // The stored state replaces the seeded default it was fetched to supply...
    expect(await screen.findByDisplayValue("dark")).toBeInTheDocument()
    await userEvent.click(screen.getByRole("button", { name: "Save" }))

    await waitFor(() => expect(submittedBody()).toBeDefined())
    // ...and a default the response has nothing to say about still survives.
    expect(submittedBody()).toEqual({ theme: "dark", label: "Ops", region: "eu" })
  })
})
