import * as React from "react"
import type { Meta, StoryObj } from "@storybook/react-vite"
import { IconFilePlus, IconPrinter, IconTrash, IconTypography } from "@tabler/icons-react"

import { MenuBar, type MenuBarMenu } from "./menu-bar"

/**
 * A live model: the toggles and the "last command" readout prove the menu bar is
 * driven entirely by the `menus` array it's handed — every `checked` and
 * `disabled` state below comes from this state, not from anything the menu bar
 * keeps for itself.
 */
function Demo({
  build,
}: {
  build: (state: {
    grid: boolean
    setGrid: (v: boolean) => void
    snap: boolean
    setSnap: (v: boolean) => void
    hasSelection: boolean
    setHasSelection: (v: boolean) => void
    run: (id: string) => void
  }) => MenuBarMenu[]
}) {
  const [grid, setGrid] = React.useState(true)
  const [snap, setSnap] = React.useState(false)
  const [hasSelection, setHasSelection] = React.useState(false)
  const [last, setLast] = React.useState<string | null>(null)

  const menus = build({ grid, setGrid, snap, setSnap, hasSelection, setHasSelection, run: setLast })

  return (
    <div className="w-full">
      <div className="rounded-lg border border-border bg-card">
        <MenuBar menus={menus} aria-label="Demo menu" className="px-1 py-1" />
      </div>
      <p className="mt-3 text-xs text-muted-foreground">
        Last command: <span className="font-medium text-foreground">{last ?? "—"}</span> · grid {grid ? "on" : "off"} ·
        snap {snap ? "on" : "off"}
      </p>
      <button
        type="button"
        className="mt-2 rounded-md border border-input px-2 py-1 text-xs"
        onClick={() => setHasSelection((v) => !v)}
      >
        {hasSelection ? "Clear selection" : "Simulate a selection"} (drives the disabled items)
      </button>
    </div>
  )
}

const meta = {
  title: "MenuBar",
  component: MenuBar,
  tags: ["autodocs"],
  parameters: {
    docs: {
      description: {
        component:
          "A desktop-application menu bar (File / Edit / …) for command sets far too large for one toolbar. Built on Radix `Menubar`, so it behaves natively: roving tab focus across the menus, arrow-key traversal, hover-to-switch once a menu is open, character typeahead, Escape/click-outside dismissal and focus restoration. Prefer the declarative `menus` prop — describe commands once (a registry) and render them here, in a toolbar and in a shortcuts sheet without restating any of them.",
      },
    },
  },
} satisfies Meta<typeof MenuBar>

export default meta
type Story = StoryObj<typeof meta>

/** The full vocabulary: actions with shortcut hints, checkable toggles, nested
 *  submenus, group labels, separators, disabled and destructive items. */
export const Default: Story = {
  args: { menus: [] },
  render: () => (
    <Demo
      build={({ grid, setGrid, snap, setSnap, hasSelection, run }) => [
        {
          id: "file",
          label: "File",
          items: [
            { id: "new", label: "New", icon: <IconFilePlus />, shortcut: "Ctrl+N", onSelect: () => run("new") },
            { id: "save", label: "Save", shortcut: "Ctrl+S", onSelect: () => run("save") },
            { kind: "separator", id: "s1" },
            { id: "print", label: "Print…", icon: <IconPrinter />, shortcut: "Ctrl+P", onSelect: () => run("print") },
            { kind: "separator", id: "s2" },
            { id: "delete", label: "Delete document", icon: <IconTrash />, destructive: true, onSelect: () => run("delete") },
          ],
        },
        {
          id: "edit",
          label: "Edit",
          items: [
            { id: "undo", label: "Undo", shortcut: "Ctrl+Z", onSelect: () => run("undo") },
            { id: "redo", label: "Redo", shortcut: "Ctrl+Shift+Z", disabled: true, onSelect: () => run("redo") },
            { kind: "separator", id: "s1" },
            { id: "cut", label: "Cut", shortcut: "Ctrl+X", disabled: !hasSelection, onSelect: () => run("cut") },
            { id: "copy", label: "Copy", shortcut: "Ctrl+C", disabled: !hasSelection, onSelect: () => run("copy") },
          ],
        },
        {
          id: "insert",
          label: "Insert",
          items: [
            { id: "text", label: "Text", icon: <IconTypography />, onSelect: () => run("insert text") },
            { kind: "separator", id: "s1" },
            {
              kind: "submenu",
              id: "block",
              label: "Block",
              items: [
                { kind: "label", id: "l-sys", label: "System" },
                { id: "b-header", label: "Company header", onSelect: () => run("insert header block") },
                { kind: "label", id: "l-mine", label: "Personal" },
                { id: "b-addr", label: "Return address", onSelect: () => run("insert address block") },
              ],
            },
            {
              kind: "submenu",
              id: "empty",
              label: "Nothing in here",
              items: [],
              emptyLabel: "No blocks in your library",
            },
          ],
        },
        {
          id: "view",
          label: "View",
          items: [
            { kind: "checkbox", id: "grid", label: "Grid", checked: grid, onCheckedChange: setGrid },
            { kind: "checkbox", id: "snap", label: "Snap to grid", checked: snap, onCheckedChange: setSnap },
          ],
        },
        { id: "disabled-menu", label: "Unavailable", disabled: true, items: [] },
      ]}
    />
  ),
}

/** Checkable items keep the menu OPEN when toggled, so several view options can
 *  be flipped in one visit. */
export const CheckableToggles: Story = {
  args: { menus: [] },
  render: () => (
    <Demo
      build={({ grid, setGrid, snap, setSnap }) => [
        {
          id: "view",
          label: "View",
          items: [
            { kind: "checkbox", id: "grid", label: "Grid", checked: grid, onCheckedChange: setGrid },
            { kind: "checkbox", id: "snap", label: "Snap to grid & guides", checked: snap, onCheckedChange: setSnap },
            { kind: "separator", id: "s1" },
            { kind: "checkbox", id: "locked", label: "Disabled toggle", checked: false, disabled: true, onCheckedChange: () => {} },
          ],
        },
      ]}
    />
  ),
}

/** An empty submenu renders a disabled placeholder rather than opening onto
 *  nothing — a dead end reads as a bug. */
export const EmptySubmenu: Story = {
  args: {
    menus: [
      {
        id: "templates",
        label: "Templates",
        items: [
          {
            kind: "submenu",
            id: "open-saved",
            label: "Open saved",
            items: [],
            emptyLabel: "No saved templates yet",
          },
        ],
      },
    ],
  },
}

/** Many menus, as a real editor has — the bar wraps nothing and keyboard
 *  traversal covers all of them. */
export const ManyMenus: Story = {
  args: {
    menus: ["File", "Edit", "Insert", "Format", "Page", "View", "Data", "Templates", "Help"].map((label) => ({
      id: label.toLowerCase(),
      label,
      items: [
        { id: `${label}-a`, label: `${label} action`, onSelect: () => {} },
        { kind: "separator", id: `${label}-s` },
        { id: `${label}-b`, label: `Another ${label.toLowerCase()} action`, shortcut: "Ctrl+K", onSelect: () => {} },
      ],
    })),
  },
}
