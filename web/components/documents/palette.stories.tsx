import * as React from "react"
import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { DocElement } from "@/lib/documents/types"
import type { BlockScope } from "@/lib/documents/blocks"

import { Palette } from "./palette"
import { ALL_TYPE_ELEMENTS, SAMPLE_BLOCKS, text } from "../../.storybook/document-fixtures"

type PaletteProps = React.ComponentProps<typeof Palette>

/**
 * `Palette` is controlled: every button reports upward and the parent owns the
 * element and block lists. This harness plays the designer's part so reorder /
 * lock / hide / delete and the block actions all behave live.
 */
function PaletteHarness(props: PaletteProps) {
  const [elements, setElements] = React.useState(props.elements)
  const [blocks, setBlocks] = React.useState(props.blocks)
  const [selectedIds, setSelectedIds] = React.useState(props.selectedIds)

  const toggle = (id: string, key: "locked" | "hidden") =>
    setElements((els) => els.map((e) => (e.id === id ? { ...e, [key]: !e[key] } : e)))

  return (
    <div className="flex gap-4">
      {/* Mirrors the designer's left rail. */}
      <aside className="h-[80vh] w-52 shrink-0 overflow-hidden rounded-lg border border-border bg-card p-3">
        <Palette
          {...props}
          elements={elements}
          blocks={blocks}
          selectedIds={selectedIds}
          onSelect={(id, additive) =>
            setSelectedIds((prev) =>
              additive ? (prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]) : [id]
            )
          }
          onReorder={(id, dir) =>
            setElements((els) => els.map((e) => (e.id === id ? { ...e, z: e.z + (dir === "up" ? 1.5 : -1.5) } : e)))
          }
          onToggleLock={(id) => toggle(id, "locked")}
          onToggleHidden={(id) => toggle(id, "hidden")}
          onDelete={(id) => {
            setElements((els) => els.filter((e) => e.id !== id))
            setSelectedIds((prev) => prev.filter((x) => x !== id))
          }}
          onInsertBlock={(blockId) => {
            const b = blocks.find((x) => x.id === blockId)
            if (!b) return
            const z = elements.reduce((m, e) => Math.max(m, e.z), 0) + 1
            const el: DocElement = {
              id: `block-${blockId}-${z}`,
              type: "blockInstance",
              x: 8,
              y: 8,
              w: b.w,
              h: b.h,
              rotation: 0,
              z,
              blockId,
            }
            setElements((els) => [...els, el])
            setSelectedIds([el.id])
          }}
          onDeleteBlock={(blockId) => setBlocks((bs) => bs.filter((b) => b.id !== blockId))}
          onSetBlockScope={(blockId, scope: BlockScope) =>
            setBlocks((bs) => bs.map((b) => (b.id === blockId ? { ...b, scope } : b)))
          }
        />
      </aside>
      <p className="pt-1 text-xs text-muted-foreground">
        Selected: {selectedIds.length ? selectedIds.join(", ") : "—"}
      </p>
    </div>
  )
}

const noop = () => {}

/** Remount the harness when an arg it seeds local state from changes — React's
 *  recommended "reset state with a key" pattern, so controls-panel edits apply. */
const seedKey = (...seeds: unknown[]) => JSON.stringify(seeds)

const meta = {
  title: "App/Documents/Palette",
  component: Palette,
  tags: ["autodocs"],
  parameters: {
    layout: "padded",
    docs: {
      description: {
        component:
          "The designer's left rail: the reusable-blocks library grouped by scope (system / personal / tenant / global), and the layers list in front-to-back order with per-element lock, hide, reorder and delete. Inserting elements is NOT here — that lives in the top bar's Insert menu and toolbar — so this rail is only about what is already on the page. Blocks are inserted as a *pointer* (`blockInstance`), never a copy: editing the block updates every instance. The callbacks are wired to local state here so every control is live.",
      },
    },
  },
  args: {
    elements: ALL_TYPE_ELEMENTS,
    selectedIds: [],
    blocks: SAMPLE_BLOCKS,
    // Overridden by the harness, which owns the state these would drive.
    onSelect: noop,
    onReorder: noop,
    onToggleLock: noop,
    onToggleHidden: noop,
    onDelete: noop,
    onInsertBlock: noop,
    onDeleteBlock: noop,
    onSetBlockScope: noop,
  },
  render: (args) => <PaletteHarness key={seedKey(args.elements, args.blocks, args.selectedIds)} {...args} />,
} satisfies Meta<typeof Palette>

export default meta
type Story = StoryObj<typeof meta>

/** The full rail: three blocks across three scopes, and one layer per element
 *  type. Every control is live. */
export const Default: Story = {}

/** A fresh document — layers empty, pointing at the Insert menu. */
export const EmptyDocument: Story = {
  args: { elements: [] },
}

/** No blocks saved yet, so the Blocks section is omitted entirely. */
export const NoBlocks: Story = {
  args: { blocks: [] },
}

/** One layer highlighted — the selection is shared with the canvas. */
export const WithSelection: Story = {
  args: { selectedIds: ["bc"] },
}

/** Shift/⌘-click on a layer extends the selection; several rows highlight at once. */
export const MultiSelection: Story = {
  args: { selectedIds: ["qr", "box", "math"] },
}

/** Locked (delete disabled) and hidden (struck through) layers. */
export const LockedAndHiddenLayers: Story = {
  args: {
    elements: ALL_TYPE_ELEMENTS.map((e) =>
      e.id === "bc" ? { ...e, locked: true } : e.id === "box" ? { ...e, hidden: true } : e
    ),
  },
}

/** A long layer list — the layers area scrolls while the blocks section stays put. */
export const ManyLayers: Story = {
  args: {
    elements: [
      ...ALL_TYPE_ELEMENTS,
      ...Array.from({ length: 14 }, (_, i) => ({
        ...text(6, 6 + i * 6, 60, 5, `Line item ${i + 1}`),
        id: `extra-${i}`,
        z: 20 + i,
      })),
    ],
  },
}
