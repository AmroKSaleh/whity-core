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

export const TextPlain: Story = {
  render: () => {
    const el: DocElement = { ...BASE, type: "text", text: "Plain, unformatted text", style: BASE_STYLE };
    return (
      <Frame w={240} h={60}>
        <ElementContent el={el} data={{}} preview />
      </Frame>
    );
  },
}

export const TextRichRuns: Story = {
  render: () => {
    const el: DocElement = {
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
    };
    return (
      <Frame w={240} h={60}>
        <ElementContent el={el} data={{}} preview />
      </Frame>
    );
  },
}

export const DynamicTextPreview: Story = {
  name: "Dynamic Text — Preview (interpolated)",
  render: () => {
    const el: DocElement = {
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
    };
    return (
      <Frame w={280} h={60}>
        <ElementContent el={el} data={{ name: "Acme Corp", order: "ORD-1042" }} preview />
      </Frame>
    );
  },
}

export const DynamicTextEditing: Story = {
  name: "Dynamic Text — Editing (raw tokens)",
  render: () => {
    const el: DocElement = {
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
    };
    return (
      <Frame w={280} h={60}>
        <ElementContent el={el} data={{ name: "Acme Corp", order: "ORD-1042" }} preview={false} />
      </Frame>
    );
  },
}

export const MathInline: Story = {
  render: () => {
    const el: DocElement = { ...BASE, type: "math", expression: "E = mc^2", block: false };
    return (
      <Frame w={200} h={50}>
        <ElementContent el={el} data={{}} preview />
      </Frame>
    );
  },
}

export const MathBlock: Story = {
  render: () => {
    const el: DocElement = {
      ...BASE,
      type: "math",
      expression: "x = \\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}",
      block: true,
    };
    return (
      <Frame w={220} h={80}>
        <ElementContent el={el} data={{}} preview />
      </Frame>
    );
  },
}

export const Image: Story = {
  render: () => {
    const el: DocElement = {
      ...BASE,
      type: "image",
      src: "https://images.unsplash.com/photo-1560179707-f14e90ef3623?w=400&auto=format&fit=crop&q=60",
      fit: "cover",
    };
    return (
      <Frame w={200} h={130}>
        <ElementContent el={el} data={{}} preview />
      </Frame>
    );
  },
}

export const ImageMissingBinding: Story = {
  name: "Image — unresolved binding placeholder",
  render: () => {
    const el: DocElement = { ...BASE, type: "image", src: "", binding: "logo_url", fit: "contain" };
    return (
      <Frame w={160} h={100}>
        <ElementContent el={el} data={{}} preview />
      </Frame>
    );
  },
}

export const Barcode: Story = {
  render: () => {
    const el: DocElement = {
      ...BASE,
      type: "barcode",
      symbology: "code128",
      value: "{{sku}}",
      binding: undefined,
      showText: true,
    };
    return (
      <Frame w={220} h={70}>
        <ElementContent el={el} data={{ sku: "SKU-00231" }} preview />
      </Frame>
    );
  },
}

export const Qr: Story = {
  name: "QR Code",
  render: () => {
    const el: DocElement = { ...BASE, type: "qr", value: "{{tracking}}", binding: undefined, eclevel: "M" };
    return (
      <Frame w={140} h={140}>
        <ElementContent el={el} data={{ tracking: "https://whity.jameedium.org/t/00231" }} preview />
      </Frame>
    );
  },
}

export const Rect: Story = {
  render: () => {
    const el: DocElement = {
      ...BASE,
      type: "rect",
      fill: "#eef2ff",
      stroke: "#4f46e5",
      strokeWidth: 2,
      radius: 8,
    };
    return (
      <Frame w={200} h={100}>
        <ElementContent el={el} data={{}} preview />
      </Frame>
    );
  },
}

export const Line: Story = {
  render: () => {
    const el: DocElement = { ...BASE, type: "line", stroke: "#111111", strokeWidth: 2 };
    return (
      <Frame w={200} h={4}>
        <ElementContent el={el} data={{}} preview />
      </Frame>
    );
  },
}
