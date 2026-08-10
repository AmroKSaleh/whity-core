import type { Meta, StoryObj } from "@storybook/react-vite"

import { ElementLayer, BlockInstanceContent } from "./element-layer"
import type { DocElement, TextStyle } from "./types"
import type { DocBlock } from "./blocks"

/** A page-sized frame — elements inside `ElementLayer` position themselves in
 * millimetres relative to this positioned container, mirroring the document
 * designer's canvas/print page. */
function Page({ w, h, children }: { w: number; h: number; children: React.ReactNode }) {
  return (
    <div
      style={{
        position: "relative",
        width: `${w}mm`,
        height: `${h}mm`,
        background: "#ffffff",
        border: "1px solid var(--border)",
      }}
    >
      {children}
    </div>
  )
}

const STYLE: TextStyle = {
  fontSize: 12,
  fontWeight: "normal",
  fontStyle: "normal",
  align: "left",
  vAlign: "top",
  color: "#111111",
  direction: "auto",
  lineHeight: 1.2,
  letterSpacing: 0,
}

/** Header text, a divider line, an interpolated body line and a body rect. */
const MULTI_ELEMENT_LAYOUT: DocElement[] = [
  { id: "title", type: "text", x: 5, y: 5, w: 80, h: 10, rotation: 0, z: 1, text: "Acme Corp", style: { ...STYLE, fontSize: 16, fontWeight: "bold" } },
  { id: "rule", type: "line", x: 5, y: 16, w: 90, h: 0.5, rotation: 0, z: 2, stroke: "#333333", strokeWidth: 0.5 },
  {
    id: "body",
    type: "dynamicText",
    x: 5,
    y: 20,
    w: 90,
    h: 12,
    rotation: 0,
    z: 3,
    template: "Invoice {{invoice_no}} — {{date}}",
    style: STYLE,
  },
  { id: "box", type: "rect", x: 5, y: 34, w: 90, h: 20, rotation: 0, z: 4, fill: "#f8fafc", stroke: "#cbd5e1", strokeWidth: 0.3, radius: 1 },
];

const meta = {
  title: "Documents/ElementLayer",
  component: ElementLayer,
  tags: ["autodocs"],
  args: {
    elements: MULTI_ELEMENT_LAYOUT,
    data: { invoice_no: "INV-1001", date: "2026-01-15" },
  },
  parameters: {
    docs: {
      description: {
        component:
          "Renders a positioned, z-ordered list of document/label-designer elements — used inside a resolved block instance and by the print renderer. `BlockInstanceContent` (also exported from this module) wraps it to resolve a `blockInstance` element's referenced block.",
      },
    },
  },
} satisfies Meta<typeof ElementLayer>

export default meta
type Story = StoryObj<typeof meta>

/** A simple multi-element layout: header text, a divider line and a body rect. */
export const MultiElementLayout: Story = {
  render: (args) => (
    <Page w={100} h={60}>
      <ElementLayer {...args} />
    </Page>
  ),
}

const HEADER_BLOCK: DocBlock = {
  id: "sys-header",
  name: "Company header",
  scope: "system",
  w: 90,
  h: 18,
  elements: [
    {
      id: "hdr-name",
      type: "dynamicText",
      x: 0,
      y: 0,
      w: 60,
      h: 8,
      rotation: 0,
      z: 1,
      template: "{{company_name}}",
      style: { ...STYLE, fontSize: 14, fontWeight: "bold" },
    },
    { id: "hdr-addr", type: "text", x: 0, y: 8, w: 90, h: 5, rotation: 0, z: 2, text: "Address line - City - Country", style: { ...STYLE, fontSize: 8, color: "#666666" } },
    { id: "hdr-rule", type: "line", x: 0, y: 15, w: 90, h: 0.4, rotation: 0, z: 3, stroke: "#333333", strokeWidth: 0.4 },
  ],
};

/** A `blockInstance` resolved to its block's elements, via `BlockInstanceContent`. */
export const BlockInstanceResolved: Story = {
  args: { data: { company_name: "Acme Corp" } },
  render: ({ data }) => (
    <Page w={100} h={20}>
      <BlockInstanceContent block={HEADER_BLOCK} data={data} preview />
    </Page>
  ),
}

/** A `blockInstance` whose block was deleted — a visible placeholder while
 * editing, nothing at all in Preview/print. */
export const MissingBlockPlaceholder: Story = {
  args: { data: {} },
  render: ({ data }) => (
    <Page w={60} h={20}>
      <BlockInstanceContent block={undefined} data={data} preview={false} />
    </Page>
  ),
}
