import * as React from "react"
import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { DocElement } from "@/lib/documents/types"

import { Canvas } from "./canvas"
import {
  ALL_TYPE_ELEMENTS,
  LABEL_PAGE,
  RTL_ELEMENTS,
  SAMPLE_BLOCKS_MAP,
  SAMPLE_DATA,
} from "../../.storybook/document-fixtures"

type CanvasProps = React.ComponentProps<typeof Canvas>

/**
 * `Canvas` is fully controlled — it reports every move/resize through
 * `onChange`/`onChangeMany` and never mutates its own `elements`. This harness
 * plays the designer's part, so dragging, resizing, grid/alignment snapping and
 * multi-select all work live in Storybook. The `elements`/`selectedIds` args
 * seed that state (see `seedKey` below for how a control edit re-seeds it).
 */
function CanvasHarness(props: CanvasProps) {
  const [elements, setElements] = React.useState(props.elements)
  const [selectedIds, setSelectedIds] = React.useState(props.selectedIds)
  const [editedBlock, setEditedBlock] = React.useState<string | null>(null)

  return (
    <div className="space-y-2">
      {/* Mirrors the designer's canvas viewport (scrolling, muted backdrop). */}
      <main className="max-h-[70vh] overflow-auto rounded-lg border border-border bg-muted/30 p-6">
        <Canvas
          {...props}
          elements={elements}
          selectedIds={selectedIds}
          onSelect={(id, additive) =>
            setSelectedIds((prev) => {
              if (id === null) return []
              if (!additive) return [id]
              return prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
            })
          }
          onChange={(id, patch) =>
            setElements((els) => els.map((e) => (e.id === id ? ({ ...e, ...patch } as DocElement) : e)))
          }
          onChangeMany={(updates) =>
            setElements((els) =>
              els.map((e) => {
                const u = updates.find((x) => x.id === e.id)
                return u ? ({ ...e, ...u.patch } as DocElement) : e
              })
            )
          }
          onEditBlock={setEditedBlock}
        />
      </main>
      <p className="text-xs text-muted-foreground">
        Selected: {selectedIds.length ? selectedIds.join(", ") : "—"}
        {editedBlock ? ` · double-clicked block instance → onEditBlock("${editedBlock}")` : ""}
      </p>
    </div>
  )
}

const noop = () => {}

/**
 * Remount the harness whenever an arg it seeds local state from changes, so
 * editing `elements`/`selectedIds` in the controls panel takes effect — React's
 * recommended "reset state with a key" pattern.
 */
const seedKey = (...seeds: unknown[]) => JSON.stringify(seeds)

const meta = {
  title: "App/Documents/Canvas",
  component: Canvas,
  tags: ["autodocs"],
  parameters: {
    layout: "padded",
    docs: {
      description: {
        component:
          "The designer's editing surface: a fixed, print-accurate page in millimetres holding absolutely-positioned elements. Owns pointer interaction only — drag to move, corner/edge handles to resize, shift/⌘-click to multi-select, double-click a block instance to edit it — and reports every change upward. Grid snapping plus edge/centre alignment guides (against the page and other elements) are applied while dragging when `gridMm > 0`. The selection and element callbacks are wired to local state here so every interaction is live.",
      },
    },
  },
  args: {
    elements: ALL_TYPE_ELEMENTS,
    page: LABEL_PAGE,
    data: SAMPLE_DATA,
    blocks: SAMPLE_BLOCKS_MAP,
    selectedIds: [],
    zoom: 1,
    gridMm: 1,
    showGrid: false,
    showRulers: false,
    preview: false,
    // Overridden by the harness, which owns the state these would drive.
    onSelect: noop,
    onChange: noop,
    onChangeMany: noop,
    onEditBlock: noop,
  },
  render: (args) => <CanvasHarness key={seedKey(args.elements, args.selectedIds)} {...args} />,
} satisfies Meta<typeof Canvas>

export default meta
type Story = StoryObj<typeof meta>

/** Every element type on one 4×6″ label: block instance, text, dynamic text,
 *  image, barcode, QR, rectangle, math and a rule. Drag or resize anything. */
export const Default: Story = {}

/** One element selected — outline, mm size readout and the eight resize handles. */
export const SingleSelection: Story = {
  args: { selectedIds: ["bc"] },
}

/** A multi-selection: no resize handles or readout (group affordances only);
 *  dragging any member moves the whole set by the same snapped delta. */
export const MultiSelection: Story = {
  args: { selectedIds: ["qr", "box", "math"] },
}

/** Grid overlay + mm rulers along the top and left edges — both edit-time aids. */
export const GridAndRulers: Story = {
  args: { showGrid: true, showRulers: true },
}

/**
 * Preview mode: `{{tokens}}` resolve against the sample data, hidden elements
 * drop out, and all editing affordances (selection, handles, grid, rulers,
 * margin guide) disappear — what print will emit.
 */
export const Preview: Story = {
  args: { preview: true, showGrid: true, showRulers: true },
}

/** Locked elements select (so they can be inspected/unlocked) but never move or
 *  resize; hidden elements stay visible while editing, dimmed to 0.4. */
export const LockedAndHidden: Story = {
  args: {
    elements: ALL_TYPE_ELEMENTS.map((e) =>
      e.id === "bc" ? { ...e, locked: true } : e.id === "box" ? { ...e, hidden: true } : e
    ),
    selectedIds: ["bc"],
  },
}

/** The same page at 2× — pointer deltas are converted through the zoom factor,
 *  so dragging still tracks the cursor exactly. */
export const ZoomedIn: Story = {
  args: { zoom: 2, showRulers: true },
}

/** Zoomed out to 0.6× — how a full page is reviewed. */
export const ZoomedOut: Story = {
  args: { zoom: 0.6 },
}

/** Snapping off (`gridMm = 0`): free positioning, no grid rounding and no
 *  alignment guides. */
export const SnapDisabled: Story = {
  args: { gridMm: 0 },
}

/** Rotation and per-element opacity, applied in edit, preview and print alike. */
export const RotationAndOpacity: Story = {
  args: {
    elements: ALL_TYPE_ELEMENTS.map((e) =>
      e.id === "box" ? { ...e, rotation: -12, opacity: 0.5 } : e.id === "qr" ? { ...e, rotation: 30 } : e
    ),
  },
}

/** A `blockInstance` whose referenced block was deleted: a visible placeholder
 *  while editing, nothing at all in preview/print. */
export const MissingBlock: Story = {
  args: {
    elements: [
      { id: "orphan", type: "blockInstance", x: 6, y: 6, w: 60, h: 20, rotation: 0, z: 1, blockId: "gone" },
    ],
    blocks: {},
  },
}

/** An empty page — just the margin guide. */
export const EmptyPage: Story = {
  args: { elements: [], showRulers: true },
}

/** Arabic and mixed Arabic/Latin content. `direction: 'auto'` (the default) lets
 *  the renderer infer direction per paragraph, so a Latin tracking number inside
 *  Arabic text still reads correctly. */
export const ArabicRtlContent: Story = {
  args: { elements: RTL_ELEMENTS, preview: true },
}
