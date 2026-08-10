import type { Meta, StoryObj } from "@storybook/react-vite"

import { ElementContent } from "./element-content"
import type { DocElement, TextStyle } from "./types"

/**
 * Fixed-size frame — `ElementContent` fills 100% of its container, matching how
 * the document/label designer canvas always places it inside an
 * absolutely-positioned element box (see `element-layer.tsx`).
 */
function Frame({ w, h, children }: { w: number; h: number; children: React.ReactNode }) {
  return (
    <div style={{ width: w, height: h, border: "1px dashed var(--border)", padding: 4, position: "relative" }}>
      {children}
    </div>
  )
}

const BASE_STYLE: TextStyle = {
  fontSize: 14,
  fontWeight: "normal",
  fontStyle: "normal",
  align: "left",
  vAlign: "top",
  color: "#111111",
  direction: "auto",
  lineHeight: 1.2,
  letterSpacing: 0,
}

const BASE = { id: "e1", x: 0, y: 0, w: 60, h: 20, rotation: 0, z: 1 }

const meta = {
  title: "Documents/ElementContent",
  component: ElementContent,
  tags: ["autodocs"],
  args: {
    el: { ...BASE, type: "text", text: "Plain, unformatted text", style: BASE_STYLE } satisfies DocElement,
    data: {},
    preview: true,
  },
  parameters: {
    docs: {
      description: {
        component:
          "The single shared leaf renderer for one DocElement's visual content — the canvas, block-instance content and the print renderer all delegate to it. See GitHub issue #532 (schema-driven UI components) for a related-but-distinct effort building rich-text/math primitives for the plugin block DSL; this component reuses the same `@amroksaleh/ui/math-text` primitive rather than inventing a second one.",
      },
    },
  },
} satisfies Meta<typeof ElementContent>

export default meta
type Story = StoryObj<typeof meta>

/** Frames a story at the size its element type is meant to be inspected in. */
const framed =
  (w: number, h: number): Story["render"] =>
  (args) => (
    <Frame w={w} h={h}>
      <ElementContent {...args} />
    </Frame>
  )

export const TextPlain: Story = {
  render: framed(240, 60),
}

export const TextRichRuns: Story = {
  args: {
    el: {
      ...BASE,
      type: "text",
      text: "Hello bold and italic world",
      style: BASE_STYLE,
      runs: [
        { text: "Hello " },
        { text: "bold", bold: true },
        { text: " and " },
        { text: "italic", italic: true },
        { text: " world" },
      ],
    },
  },
  render: framed(240, 60),
}

const DYNAMIC_TEXT: DocElement = {
  ...BASE,
  type: "dynamicText",
  template: "Hello {{name}}, your order {{order}} shipped!",
  style: BASE_STYLE,
  runs: [
    { text: "Hello " },
    { text: "{{name}}", bold: true },
    { text: ", your order " },
    { text: "{{order}}", italic: true },
    { text: " shipped!" },
  ],
}

export const DynamicTextPreview: Story = {
  name: "Dynamic Text — Preview (interpolated)",
  args: {
    el: DYNAMIC_TEXT,
    data: { name: "Acme Corp", order: "ORD-1042" },
  },
  render: framed(280, 60),
}

export const DynamicTextEditing: Story = {
  name: "Dynamic Text — Editing (raw tokens)",
  args: {
    el: DYNAMIC_TEXT,
    data: { name: "Acme Corp", order: "ORD-1042" },
    preview: false,
  },
  render: framed(280, 60),
}

export const MathInline: Story = {
  args: {
    el: { ...BASE, type: "math", expression: "E = mc^2", block: false },
  },
  render: framed(200, 50),
}

export const MathBlock: Story = {
  args: {
    el: {
      ...BASE,
      type: "math",
      expression: "x = \\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}",
      block: true,
    },
  },
  render: framed(220, 80),
}

export const Image: Story = {
  args: {
    el: {
      ...BASE,
      type: "image",
      src: "https://images.unsplash.com/photo-1560179707-f14e90ef3623?w=400&auto=format&fit=crop&q=60",
      fit: "cover",
    },
  },
  render: framed(200, 130),
}

export const ImageMissingBinding: Story = {
  name: "Image — unresolved binding placeholder",
  args: {
    el: { ...BASE, type: "image", src: "", binding: "logo_url", fit: "contain" },
  },
  render: framed(160, 100),
}

export const Barcode: Story = {
  args: {
    el: {
      ...BASE,
      type: "barcode",
      symbology: "code128",
      value: "{{sku}}",
      binding: undefined,
      showText: true,
    },
    data: { sku: "SKU-00231" },
  },
  render: framed(220, 70),
}

export const Qr: Story = {
  name: "QR Code",
  args: {
    el: { ...BASE, type: "qr", value: "{{tracking}}", binding: undefined, eclevel: "M" },
    data: { tracking: "https://whity.jameedium.org/t/00231" },
  },
  render: framed(140, 140),
}

export const Rect: Story = {
  args: {
    el: {
      ...BASE,
      type: "rect",
      fill: "#eef2ff",
      stroke: "#4f46e5",
      strokeWidth: 2,
      radius: 8,
    },
  },
  render: framed(200, 100),
}

export const Line: Story = {
  args: {
    el: { ...BASE, type: "line", stroke: "#111111", strokeWidth: 2 },
  },
  render: framed(200, 4),
}
