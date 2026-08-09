import * as React from "react"
import type { Meta, StoryObj } from "@storybook/nextjs-vite"
import type { DocElement, PageSpec, Placeholder } from "@/lib/documents/types"
import { generateSequence } from "@/lib/documents/batch"

import { Inspector } from "./inspector"
import {
  ALL_TYPE_ELEMENTS,
  BATCH_ROWS,
  RTL_ELEMENTS,
  SAMPLE_SEQUENCE,
  SAMPLE_SHEET,
  SAMPLE_TEMPLATE,
  TILED_SHEET,
  elementOfType,
} from "../../.storybook/document-fixtures"

type InspectorProps = React.ComponentProps<typeof Inspector>

/**
 * Click one of the Batch tab's source-mode buttons. The active TAB is a prop
 * (stories set it directly via args), but the batch SOURCE mode is still local
 * to `BatchTab`, so these stories reach it the way a user does. Polls, because
 * the button only exists once the Batch tab has rendered. Plain DOM, so these
 * stories need no interaction-addon dependency.
 */
const openBatchMode =
  (mode: "sequence" | "csv" | "paste") =>
  async ({ canvasElement }: { canvasElement: HTMLElement }) => {
    const selector = `[data-testid="doc-batch-mode-${mode}"]`
    const started = performance.now()
    for (;;) {
      const el = canvasElement.querySelector<HTMLButtonElement>(selector)
      if (el) return el.click()
      if (performance.now() - started > 5000) throw new Error(`Timed out waiting for ${selector}`)
      await new Promise<void>((r) => requestAnimationFrame(() => r()))
    }
  }

/**
 * Every Inspector field is controlled from `template`/`selected`, so the panel
 * needs a state owner to be typeable. This harness plays the designer's part: it
 * applies each patch and echoes the live element/page JSON beside the panel,
 * which makes it obvious what a given control actually writes.
 */
function InspectorHarness(props: InspectorProps) {
  const [template, setTemplate] = React.useState(props.template)
  const [selected, setSelected] = React.useState(props.selected)
  const [sheet, setSheet] = React.useState(props.sheet)
  const [sequence, setSequence] = React.useState(props.sequence)
  const [batch, setBatch] = React.useState(props.batch)

  return (
    <div className="flex gap-4">
      {/* Mirrors the designer's right rail. */}
      <aside className="h-[80vh] w-72 shrink-0 overflow-hidden rounded-lg border border-border bg-card p-3">
        <Inspector
          {...props}
          template={template}
          selected={selected}
          batch={batch}
          sheet={sheet}
          sequence={sequence}
          onChangeSelected={(patch: Partial<DocElement>) =>
            setSelected((s) => (s ? ({ ...s, ...patch } as DocElement) : s))
          }
          onChangePage={(patch: Partial<PageSpec>) => setTemplate((t) => ({ ...t, page: { ...t.page, ...patch } }))}
          onChangePlaceholders={(list: Placeholder[]) => setTemplate((t) => ({ ...t, placeholders: list }))}
          onGenerateBatch={(cfg) => {
            const total = generateSequence(cfg).length
            setSequence(cfg)
            setBatch({ active: total > 0, index: 0, total })
          }}
          onLoadBatchRecords={(records) => setBatch({ active: records.length > 0, index: 0, total: records.length })}
          onClearBatch={() => setBatch({ active: false, index: 0, total: 0 })}
          onBatchIndex={(index) => setBatch((b) => ({ ...b, index }))}
          onChangeSheet={(patch) => setSheet((s) => ({ ...s, ...patch }))}
          onChangeSequence={(patch) => setSequence((s) => ({ ...s, ...patch }))}
        />
      </aside>
      <pre className="max-h-[80vh] flex-1 overflow-auto rounded-lg border border-border bg-muted/30 p-3 text-[10px] leading-relaxed">
        {JSON.stringify(
          { selected, page: template.page, placeholders: template.placeholders, sheet, sequence, batch },
          null,
          2
        )}
      </pre>
    </div>
  )
}

const noop = () => {}

/** Remount the harness when an arg it seeds local state from changes — React's
 *  recommended "reset state with a key" pattern, so controls-panel edits apply. */
const seedKey = (...seeds: unknown[]) => JSON.stringify(seeds)

const meta = {
  title: "App/Documents/Inspector",
  component: Inspector,
  tags: ["autodocs"],
  parameters: {
    layout: "padded",
    docs: {
      description: {
        component:
          "The designer's right rail — five tabs over one panel. **Element**: geometry, per-type properties and text styling for the single selected element. **Page**: size preset / orientation / margin / background. **Data**: the `{{placeholder}}` list and their sample values. **Batch**: variable-data runs from a serial sequence, a CSV/TSV upload or pasted CSV/JSON. **Sheet**: N-up label-sheet tiling for print. The active tab is a `tab` prop (the editor's menu bar opens specific ones — Page setup…, Placeholders…, Label sheet layout…), so each story below just sets it; the panel writes into local state, so every field is editable.",
      },
    },
  },
  args: {
    template: SAMPLE_TEMPLATE,
    selected: null,
    selectedCount: 0,
    batch: { active: false, index: 0, total: 0 },
    sheet: SAMPLE_SHEET,
    sequence: SAMPLE_SEQUENCE,
    tab: 'element',
    // Overridden by the harness, which owns the state these would drive.
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
  render: (args) => (
    <InspectorHarness
      key={seedKey(args.template, args.selected, args.sheet, args.sequence, args.batch, args.tab)}
      {...args}
    />
  ),
} satisfies Meta<typeof Inspector>

export default meta
type Story = StoryObj<typeof meta>

// ── Element tab, one story per element type ─────────────────────────────────

/** Nothing selected — the panel prompts instead of showing empty fields. */
export const NoSelection: Story = {}

/** A multi-selection defers to the align/distribute actions above the panel. */
export const MultiSelection: Story = {
  args: { selected: elementOfType("text"), selectedCount: 3 },
}

/** Text: geometry, the rich-text bold/italic run controls over the selection,
 *  and the shared `TextStyle` block (size, weight, alignment, colour, direction,
 *  line height, letter spacing). */
export const TextElement: Story = {
  args: { selected: elementOfType("text"), selectedCount: 1 },
}

/** Dynamic text: the `{{token}}` template plus an insert-placeholder picker. */
export const DynamicTextElement: Story = {
  args: { selected: elementOfType("dynamicText"), selectedCount: 1 },
}

/** Image: a static URL or a placeholder binding that overrides it, plus object-fit. */
export const ImageElement: Story = {
  args: { selected: elementOfType("image"), selectedCount: 1 },
}

/** Barcode: symbology (the full bwip-js list), value or binding, human-readable text. */
export const BarcodeElement: Story = {
  args: { selected: elementOfType("barcode"), selectedCount: 1 },
}

/** QR: value or binding plus the error-correction level. */
export const QrElement: Story = {
  args: { selected: elementOfType("qr"), selectedCount: 1 },
}

/** Rectangle: fill, stroke, stroke width and corner radius. */
export const RectElement: Story = {
  args: { selected: elementOfType("rect"), selectedCount: 1 },
}

/** Line: stroke colour and width. */
export const LineElement: Story = {
  args: { selected: elementOfType("line"), selectedCount: 1 },
}

/** Math: LaTeX source with a live KaTeX render below the field. */
export const MathElement: Story = {
  args: { selected: elementOfType("math"), selectedCount: 1 },
}

/** A locked + hidden + semi-transparent element, showing those toggles set. */
export const LockedHiddenElement: Story = {
  args: {
    selected: {
      ...(ALL_TYPE_ELEMENTS.find((e) => e.id === "bc") as DocElement),
      locked: true,
      hidden: true,
      opacity: 0.5,
    },
    selectedCount: 1,
  },
}

/** Arabic text with an explicit `rtl` direction — one of the three direction
 *  settings (`auto`, the default, infers per paragraph). */
export const ArabicTextElement: Story = {
  args: { selected: RTL_ELEMENTS[4], selectedCount: 1 },
}

// ── the other four tabs ─────────────────────────────────────────────────────

/** Page tab: size presets, portrait/landscape, margin and background. */
export const PageTab: Story = {
  args: { tab: 'page' },
}

/** Data tab: the placeholder list — key, label and the sample value that both
 *  the canvas preview and print resolve against. */
export const DataTab: Story = {
  args: { tab: 'data' },
}

/** Data tab with no placeholders yet. */
export const DataTabEmpty: Story = {
  args: { tab: 'data', template: { ...SAMPLE_TEMPLATE, placeholders: [] } },
}

/** Batch tab, sequence source: a serial run generated into one placeholder,
 *  with a live example of the first values. */
export const BatchTabSequence: Story = {
  args: { tab: 'batch' },
  play: openBatchMode("sequence"),
}

/** Batch tab, CSV source: a header row names the placeholders to fill. */
export const BatchTabCsv: Story = {
  args: { tab: 'batch' },
  play: openBatchMode("csv"),
}

/** Batch tab, paste source: CSV rows or a JSON array of objects. */
export const BatchTabPaste: Story = {
  args: { tab: 'batch' },
  play: openBatchMode("paste"),
}

/** A batch already running: the row stepper drives which data row the preview
 *  and canvas show. */
export const BatchTabActive: Story = {
  args: { tab: 'batch', batch: { active: true, index: 1, total: BATCH_ROWS.length } },
}

/** Sheet tab, tiling off: print emits one label per physical page. */
export const SheetTabDisabled: Story = {
  args: { tab: 'sheet' },
}

/** Sheet tab, tiling on: an N-up grid (Avery-style presets, margins, gutters)
 *  with the resulting cells-per-sheet and sheet count. */
export const SheetTabTiled: Story = {
  args: { tab: 'sheet', sheet: TILED_SHEET, batch: { active: true, index: 0, total: 40 } },
}
