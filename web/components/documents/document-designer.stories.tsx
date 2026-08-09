import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { DocumentDesigner } from "./document-designer"
import { defaultHandlers } from "../../.storybook/mocks"
import { documentHandlers, SAVED_TEMPLATE_ROWS } from "../../.storybook/document-fixtures"

/**
 * The designer takes no props beyond `onClose` — it IS the editor — so every
 * state below is reached the way a user reaches it: through its own chrome, over
 * the mocked `/api/v1/document-templates` + `/api/v1/document-blocks` APIs.
 */
const msw = (handlers: ReturnType<typeof documentHandlers>) => ({
  msw: { handlers: [...handlers, ...defaultHandlers] },
})

// ── play-function plumbing (plain DOM — no interaction-addon dependency) ─────

const frame = () => new Promise<void>((r) => requestAnimationFrame(() => r()))

/**
 * Poll for an element. Searches the whole DOCUMENT, not just the story root:
 * Radix renders menu content in a portal on `document.body`, so a menu item is
 * never inside `canvasElement`.
 */
async function waitFor<T extends Element>(selector: string, timeout = 8000): Promise<T> {
  const started = performance.now()
  for (;;) {
    const el = document.querySelector<T>(selector)
    if (el) return el
    if (performance.now() - started > timeout) throw new Error(`Timed out waiting for ${selector}`)
    await frame()
  }
}

/**
 * A full pointer interaction, not just `click()`. Radix opens a menu on
 * `pointerdown` (a lone click event won't) and selects an item on the `click`
 * (pointerup alone won't) — verified against this build rather than assumed, so
 * the sequence covers both without double-activating either.
 */
function pointerClick(el: Element) {
  const base = { bubbles: true, cancelable: true, pointerId: 1, pointerType: "mouse", button: 0 }
  el.dispatchEvent(new PointerEvent("pointerdown", { ...base, buttons: 1 }))
  el.dispatchEvent(new PointerEvent("pointerup", { ...base, buttons: 0 }))
  el.dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }))
}

/** Click any control by test id, once it exists. */
async function click(testId: string) {
  pointerClick(await waitFor(`[data-testid="${testId}"]`))
  await frame()
}

/**
 * Dismiss any open menu and wait until it's really gone.
 *
 * Necessary because a menubar that is still mid-dismiss swallows the next
 * trigger's `pointerdown` — so opening a SECOND menu right after using a first
 * one silently does nothing. Escaping to a known-closed state first makes each
 * menu interaction independent of what preceded it.
 */
async function closeMenus() {
  document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape", bubbles: true }))
  const started = performance.now()
  while (document.querySelector('[data-testid^="menu-item-"]')) {
    if (performance.now() - started > 3000) throw new Error("A menu would not dismiss")
    await frame()
  }
  await frame()
}

/** Open a top-level menu and choose one of its items. */
async function chooseMenu(menuId: string, itemId: string) {
  await closeMenus()
  await click(`menu-${menuId}`)
  await click(`menu-item-${itemId}`)
}

/**
 * Open a top-level menu, then a submenu, then an item inside it. Radix opens a
 * submenu on pointer-MOVE over its trigger (hover), so that is what's sent
 * rather than a click.
 */
async function chooseSubmenu(menuId: string, subId: string, itemId: string) {
  await closeMenus()
  await click(`menu-${menuId}`)
  const trigger = await waitFor(`[data-testid="menu-item-${subId}"]`)
  trigger.dispatchEvent(
    new PointerEvent("pointermove", { bubbles: true, cancelable: true, pointerId: 1, pointerType: "mouse" })
  )
  await click(`menu-item-${itemId}`)
}

const SHIPPING_LABEL_ID = String(SAVED_TEMPLATE_ROWS[0].id)
const ARABIC_LABEL_ID = String(SAVED_TEMPLATE_ROWS[1].id)

/** Open the sample shipping-label template via Templates ▸ Open saved ▸. */
const openSaved = (id: string) => async () => {
  await chooseSubmenu("templates", "open-saved", `open-saved-${id}`)
  await waitFor('[data-testid="doc-layer-select-bc"]')
  await closeMenus()
}

const meta = {
  title: "App/Documents/DocumentDesigner",
  component: DocumentDesigner,
  tags: ["autodocs"],
  parameters: {
    layout: "fullscreen",
    ...msw(documentHandlers()),
    docs: {
      description: {
        component:
          "The whole document/label editor, full-screen. Chrome is a Google-Docs-style stack: a title bar (exit, name, Save/Preview/Print), a **menu bar** that is the complete command index, and an icon **toolbar** of the frequent subset — all three rendered from one command registry (`editor-commands.tsx`). Below that the canvas takes the window, with a SINGLE collapsible rail on the inline-end side holding Layers plus the property tabs. Templates and reusable blocks persist through the tenant-scoped, RBAC-gated `/api/v1/document-templates` and `/api/v1/document-blocks` APIs, mocked here.",
      },
    },
  },
} satisfies Meta<typeof DocumentDesigner>

export default meta
type Story = StoryObj<typeof meta>

/** A fresh session: blank template, rail on Layers, blocks library loaded from
 *  the API (plus any built-in starter blocks the tenant lacks). */
export const Default: Story = {}

/**
 * A saved template loaded through Templates ▸ Open saved — one element of every
 * type on a 4×6″ label, over two pages. The richest single view of the editor.
 */
export const LoadedTemplate: Story = {
  play: openSaved(SHIPPING_LABEL_ID),
}

/** The File menu open, showing the shortcut hints the registry supplies. */
export const FileMenuOpen: Story = {
  play: async () => {
    await click("menu-file")
    await waitFor('[data-testid="menu-item-export"]')
  },
}

/** Insert ▸ — every element type, the reusable-block submenu and Page. */
export const InsertMenuOpen: Story = {
  play: async () => {
    await click("menu-insert")
    await waitFor('[data-testid="menu-item-insert-block"]')
  },
}

/**
 * View ▸ with the edit-time aids as checkable items. Everything that used to be
 * a loose switch in the toolbar now lives here.
 */
export const ViewMenuOpen: Story = {
  play: async () => {
    await click("menu-view")
    await waitFor('[data-testid="menu-item-zoom-fit"]')
  },
}

/** Started from the built-in Invoice starter: a full A4 layout, so a new
 *  document is never an empty white sheet. */
export const StartedFromInvoice: Story = {
  play: async () => {
    await chooseSubmenu("templates", "start-from", "start-from-invoice")
  },
}

/** Elements inserted from the toolbar's insert group (the Palette's old
 *  add-element grid, now part of the top bar). Inserting hands the rail to the
 *  new element's properties, so step back to Layers to see all three. */
export const ElementsInserted: Story = {
  play: async () => {
    await click("toolbar-insert-text")
    await click("toolbar-insert-barcode")
    await click("toolbar-insert-qr")
    await click("doc-tab-layers")
  },
}

/** One element selected — the rail's Element tab holds its properties (where
 *  inserting already lands you), while align/arrange/clipboard live in the
 *  toolbar. */
export const ElementSelected: Story = {
  play: async () => {
    await click("toolbar-insert-barcode")
  },
}

/**
 * The rail collapsed: the document gets the entire window, with a one-button
 * strip to bring the panel back.
 */
export const RailCollapsed: Story = {
  play: async () => {
    await openSaved(SHIPPING_LABEL_ID)()
    await click("doc-rail-collapse")
  },
}

/** The rail on its Page tab, opened from Page ▸ Page setup… — a menu item that
 *  targets a tab also reveals the rail if it was hidden. */
export const PageSetupFromMenu: Story = {
  play: async () => {
    await openSaved(SHIPPING_LABEL_ID)()
    await click("doc-rail-collapse")
    await chooseMenu("page", "page-setup")
    await waitFor('[data-testid="doc-side-rail"]')
  },
}

/** Preview mode: `{{tokens}}` resolved, editing affordances gone. */
export const PreviewMode: Story = {
  play: async () => {
    await openSaved(SHIPPING_LABEL_ID)()
    await click("doc-preview-toggle")
  },
}

/** Grid and rulers on, via View ▸ (both are checkable menu items). */
export const GridAndRulers: Story = {
  play: async () => {
    await openSaved(SHIPPING_LABEL_ID)()
    await chooseMenu("view", "grid")
    // The menu stays open after a checkbox toggle, so the second one needs no
    // reopening — that is deliberate (see MenuBar's checkbox onSelect).
    await click("menu-item-rulers")
  },
}

/** A multi-selection made with Edit ▸ Select all: the status bar reports the
 *  count and the distribute actions light up. */
export const SelectAll: Story = {
  play: async () => {
    await openSaved(SHIPPING_LABEL_ID)()
    await chooseMenu("edit", "select-all")
  },
}

/** Format ▸ with a block instance selected: Edit block / Detach replace
 *  "Save selection as block". */
export const FormatMenuOnBlockInstance: Story = {
  play: async () => {
    await openSaved(SHIPPING_LABEL_ID)()
    await click("doc-layer-select-hdr")
    await click("menu-format")
    await waitFor('[data-testid="menu-item-edit-block"]')
  },
}

/**
 * Block edit mode: the editor is temporarily repurposed to edit ONE block's
 * elements, with a banner saying so — the edit propagates to every instance.
 */
export const BlockEditMode: Story = {
  play: async () => {
    await openSaved(SHIPPING_LABEL_ID)()
    await click("doc-layer-select-hdr")
    await chooseMenu("format", "edit-block")
    await waitFor('[data-testid="doc-block-edit-banner"]')
  },
}

/** Help ▸ Keyboard shortcuts — the list is generated from the same registry
 *  that renders the menus, so it can't drift from what's implemented. */
export const ShortcutsDialog: Story = {
  play: async () => {
    await chooseMenu("help", "shortcuts")
    await waitFor('[data-testid="doc-shortcuts-dialog"]')
  },
}

/** A third page added — the status bar's page navigator. */
export const MultiplePages: Story = {
  play: async () => {
    await openSaved(SHIPPING_LABEL_ID)()
    await click("doc-add-page")
  },
}

/** Arabic template — the document content infers direction per paragraph. */
export const ArabicRtlTemplate: Story = {
  play: async () => {
    await chooseSubmenu("templates", "open-saved", `open-saved-${ARABIC_LABEL_ID}`)
  },
}

/** A tenant with nothing saved yet: Templates ▸ Open saved is empty and the
 *  blocks library falls back to the built-in starter blocks. */
export const NoSavedTemplatesOrBlocks: Story = {
  parameters: msw(documentHandlers({ templates: [], blocks: [] })),
}

/**
 * Both list endpoints failing. `listSaved`/`listBlocks` throw and the designer
 * catches and toasts rather than rendering a broken editor — it stays usable on
 * the blank template.
 */
export const LoadFailure: Story = {
  parameters: msw(documentHandlers({ status: 500 })),
}
