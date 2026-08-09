import * as React from "react"
import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { PrintDocument } from "./print-document"
import {
  BATCH_ROWS,
  RTL_TEMPLATE,
  SAMPLE_BLOCKS_MAP,
  SAMPLE_DATA,
  SAMPLE_TEMPLATE,
  TILED_SHEET,
} from "../../.storybook/document-fixtures"

/**
 * On screen inside the designer this renderer is `display: none` — that rule
 * lives in the designer's own print stylesheet, which these stories don't mount,
 * so the pages are visible here. That is the point: it's the only way to review
 * print output without a print dialog. The frame below just separates the
 * physical pages the way a print preview would.
 */
function PrintFrame({ children }: { children: React.ReactNode }) {
  return (
    <div className="max-h-[80vh] overflow-auto rounded-lg border border-border bg-muted/30 p-6 [&_.doc-print-page]:mb-4 [&_.doc-print-page]:shadow-md">
      {children}
    </div>
  )
}

const meta = {
  title: "App/Documents/PrintDocument",
  component: PrintDocument,
  tags: ["autodocs"],
  parameters: {
    layout: "padded",
    docs: {
      description: {
        component:
          "The off-screen render used only for printing. Physical output is `datasets × template.pages` render units: one page per unit normally, or tiled N-up onto sheet-sized pages when a `sheet` is enabled. Everything renders as if in preview — `{{tokens}}` resolved, hidden elements dropped, block instances resolved to their block's elements.",
      },
    },
  },
} satisfies Meta<typeof PrintDocument>

export default meta
type Story = StoryObj<typeof meta>

/** One data row × two template pages → two physical pages. */
export const Default: Story = {
  args: { template: SAMPLE_TEMPLATE, datasets: [SAMPLE_DATA], blocks: SAMPLE_BLOCKS_MAP },
  render: (args) => (
    <PrintFrame>
      <PrintDocument {...args} />
    </PrintFrame>
  ),
}

/** A single-page template — the common label case. */
export const SinglePage: Story = {
  args: {
    template: { ...SAMPLE_TEMPLATE, pages: [SAMPLE_TEMPLATE.pages[0]] },
    datasets: [SAMPLE_DATA],
    blocks: SAMPLE_BLOCKS_MAP,
  },
  render: Default.render,
}

/**
 * A variable-data batch: three data rows × one page. Print order is row-major —
 * every page of row 0, then row 1, and so on — so the barcode and SKU differ on
 * each page while the layout is identical.
 */
export const VariableDataBatch: Story = {
  args: {
    template: { ...SAMPLE_TEMPLATE, pages: [SAMPLE_TEMPLATE.pages[0]] },
    datasets: BATCH_ROWS,
    blocks: SAMPLE_BLOCKS_MAP,
  },
  render: Default.render,
}

/**
 * N-up tiling: the same three-row batch flowed into a 2 × 2 A4 sheet. The label
 * size is the template's page size; the sheet defines the physical page, grid,
 * margins and gutters. Rows spill onto as many sheets as needed.
 */
export const TiledSheet: Story = {
  args: {
    template: { ...SAMPLE_TEMPLATE, pages: [SAMPLE_TEMPLATE.pages[0]] },
    datasets: BATCH_ROWS,
    blocks: SAMPLE_BLOCKS_MAP,
    sheet: TILED_SHEET,
  },
  render: Default.render,
}

/** A batch large enough to need several sheets — 9 rows into a 2 × 2 grid → 3 sheets. */
export const TiledMultipleSheets: Story = {
  args: {
    template: { ...SAMPLE_TEMPLATE, pages: [SAMPLE_TEMPLATE.pages[0]] },
    datasets: Array.from({ length: 9 }, (_, i) => ({
      ...SAMPLE_DATA,
      sku: `SN-${String(i + 1).padStart(4, "0")}`,
      tracking: `TRK-${String(i + 1).padStart(6, "0")}`,
    })),
    blocks: SAMPLE_BLOCKS_MAP,
    sheet: TILED_SHEET,
  },
  render: Default.render,
}

/** Hidden elements are omitted from print entirely (here: the rectangle). */
export const HiddenElementsOmitted: Story = {
  args: {
    template: {
      ...SAMPLE_TEMPLATE,
      pages: [
        {
          ...SAMPLE_TEMPLATE.pages[0],
          elements: SAMPLE_TEMPLATE.pages[0].elements.map((e) => (e.id === "box" ? { ...e, hidden: true } : e)),
        },
      ],
    },
    datasets: [SAMPLE_DATA],
    blocks: SAMPLE_BLOCKS_MAP,
  },
  render: Default.render,
}

/** A block instance whose block is missing renders as nothing in print (no
 *  placeholder box leaks onto paper). */
export const MissingBlockPrintsNothing: Story = {
  args: {
    template: { ...SAMPLE_TEMPLATE, pages: [SAMPLE_TEMPLATE.pages[0]] },
    datasets: [SAMPLE_DATA],
    blocks: {},
  },
  render: Default.render,
}

/** Arabic content, print path — the same bidi handling as the canvas preview. */
export const ArabicRtl: Story = {
  args: { template: RTL_TEMPLATE, datasets: [SAMPLE_DATA], blocks: SAMPLE_BLOCKS_MAP },
  render: Default.render,
}
