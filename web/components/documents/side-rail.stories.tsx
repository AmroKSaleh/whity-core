import * as React from "react"
import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { DocElement } from "@/lib/documents/types"

import { SideRail, type RailTab } from "./side-rail"
import {
  ALL_TYPE_ELEMENTS,
  BATCH_ROWS,
  SAMPLE_BLOCKS,
  SAMPLE_SEQUENCE,
  SAMPLE_SHEET,
  SAMPLE_TEMPLATE,
  TILED_SHEET,
  elementOfType,
} from "../../.storybook/document-fixtures"

type RailProps = React.ComponentProps<typeof SideRail>

const noop = () => {}

/** Baseline props: the fixture template with every element type, one selected. */
function baseProps(overrides: {
  selected?: DocElement | null
  selectedCount?: number
  batch?: RailProps["inspector"]["batch"]
  sheet?: RailProps["inspector"]["sheet"]
}): Omit<RailProps, "tab" | "onTabChange" | "onCollapse"> {
  return {
    palette: {
      elements: ALL_TYPE_ELEMENTS,
      selectedIds: overrides.selected ? [overrides.selected.id] : [],
      blocks: SAMPLE_BLOCKS,
      onSelect: noop,
      onReorder: noop,
      onToggleLock: noop,
      onToggleHidden: noop,
      onDelete: noop,
      onInsertBlock: noop,
      onDeleteBlock: noop,
      onSetBlockScope: noop,
    },
    inspector: {
      template: SAMPLE_TEMPLATE,
      selected: overrides.selected ?? null,
      selectedCount: overrides.selectedCount ?? (overrides.selected ? 1 : 0),
      batch: overrides.batch ?? { active: false, index: 0, total: 0 },
      sheet: overrides.sheet ?? SAMPLE_SHEET,
      sequence: SAMPLE_SEQUENCE,
      onChangeSelected: noop,
      onChangePage: noop,
      onChangePlaceholders: noop,
      onGenerateBatch: noop,
      onLoadBatchRecords: noop,
      onClearBatch: noop,
      onBatchIndex: noop,
      onChangeSheet: noop,
      onChangeSequence: noop,
    },
  }
}

/** Owns the active tab and the collapsed state, as the designer does. */
function RailHarness(props: RailProps) {
  const [tab, setTab] = React.useState<RailTab>(props.tab)
  const [open, setOpen] = React.useState(true)

  return (
    <div className="flex h-[85vh] overflow-hidden rounded-lg border border-border">
      <div className="flex flex-1 items-center justify-center bg-muted/30 text-xs text-muted-foreground">
        {open ? "canvas" : "canvas — full width with the rail collapsed"}
      </div>
      {open ? (
        <SideRail {...props} tab={tab} onTabChange={setTab} onCollapse={() => setOpen(false)} />
      ) : (
        <div className="flex shrink-0 flex-col border-s border-border bg-card p-1">
          <button
            type="button"
            aria-label="Show side panel"
            className="flex size-7 items-center justify-center rounded-md text-muted-foreground hover:bg-accent"
            onClick={() => setOpen(true)}
          >
            ›
          </button>
        </div>
      )}
    </div>
  )
}

const meta = {
  title: "App/Documents/SideRail",
  component: SideRail,
  tags: ["autodocs"],
  parameters: {
    layout: "padded",
    docs: {
      description: {
        component:
          "The editor's ONE side rail, on the inline-END side (right in LTR, left in RTL — grid flow and logical properties, not a left/right switch). It replaced two rails: layers on the left and properties on the right used to squeeze the page between them, which is the wrong trade for a canvas app where those panels are consulted occasionally and the page is looked at constantly. So Layers folds in as one more tab and the whole rail collapses away. The tab strip lives here rather than in `Inspector` precisely so Layers can share it; tabs are icon-only (named via `aria-label`/`title`, with the active one spelled out beneath) so six fit a narrow rail.",
      },
    },
  },
  args: { ...baseProps({}), tab: "layers", onTabChange: noop, onCollapse: noop },
  render: (args) => <RailHarness key={args.tab} {...args} />,
} satisfies Meta<typeof SideRail>

export default meta
type Story = StoryObj<typeof meta>

/** Layers — the default tab: the blocks library plus the front-to-back layer
 *  list, with per-element lock / hide / reorder / delete. */
export const Layers: Story = {}

/** Element — properties of the single selected element. */
export const Element: Story = {
  args: { tab: "element", ...baseProps({ selected: elementOfType("barcode") }) },
}

/** Element with several selected: the panel defers to the toolbar and Format
 *  menu for the group actions. */
export const ElementMultiSelection: Story = {
  args: { tab: "element", ...baseProps({ selected: elementOfType("text"), selectedCount: 4 }) },
}

/** Page — size preset, orientation, margin and background. */
export const Page: Story = {
  args: { tab: "page" },
}

/** Data — the `{{placeholder}}` list and the sample values preview resolves. */
export const Data: Story = {
  args: { tab: "data" },
}

/** Batch — variable-data runs from a sequence, CSV upload or pasted rows. */
export const Batch: Story = {
  args: { tab: "batch" },
}

/** Batch, already running: the row stepper drives which data row is shown. */
export const BatchActive: Story = {
  args: { tab: "batch", ...baseProps({ batch: { active: true, index: 1, total: BATCH_ROWS.length } }) },
}

/** Sheet — N-up label-sheet tiling for print. */
export const Sheet: Story = {
  args: { tab: "sheet", ...baseProps({ sheet: TILED_SHEET }) },
}

/** Collapsed via the rail's own hide button — the canvas takes the full width
 *  and a one-button strip brings it back. */
export const Collapsed: Story = {
  play: async ({ canvasElement }) => {
    canvasElement.querySelector<HTMLButtonElement>('[data-testid="doc-rail-collapse"]')?.click()
  },
}
