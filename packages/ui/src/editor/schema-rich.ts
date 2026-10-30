import { Node, mergeAttributes } from "@tiptap/core"

/**
 * The rich blocks of editor milestone M3 (E.13–E.16, E.40–E.42), on top of
 * schema.ts. Containers (toggle, columns, table) hold editable content; the
 * rest are atoms whose contract fields live in attributes and are edited
 * through their block toolbar (E.8). Every block keeps its `id` and optional
 * `suggestion`, like the text blocks.
 */

const blockAttributes = {
  id: { default: null, keepOnSplit: false },
  suggestion: { default: null, rendered: false },
}

const dataAttrs = (attrs: Record<string, unknown>, type: string, extra: Record<string, unknown> = {}) =>
  mergeAttributes({ "data-block-id": attrs.id ?? undefined, "data-type": type, ...extra })

/** An attribute that holds contract JSON (objects, arrays) and is never rendered to the DOM. */
const json = (fallback: unknown = null) => ({ default: fallback, rendered: false })

/** E.14: two kinds only, Note (grey) and Important (warning tint). */
export const Callout = Node.create({
  name: "callout",
  group: "block",
  content: "inline*",
  defining: true,
  addAttributes: () => ({ ...blockAttributes, kind: { default: "note" } }),
  parseHTML: () => [{ tag: "div[data-type=callout]" }],
  renderHTML: ({ node }) => ["div", dataAttrs(node.attrs, "callout", { "data-kind": node.attrs.kind }), 0],
})

/** A toggle: a summary line and the blocks it hides. */
export const Toggle = Node.create({
  name: "toggle",
  group: "block",
  content: "toggleSummary block*",
  defining: true,
  addAttributes: () => ({ ...blockAttributes, open: { default: true } }),
  parseHTML: () => [{ tag: "div[data-type=toggle]" }],
  renderHTML: ({ node }) => ["div", dataAttrs(node.attrs, "toggle", { "data-open": node.attrs.open ? "true" : "false" }), 0],
})

export const ToggleSummary = Node.create({
  name: "toggleSummary",
  content: "inline*",
  defining: true,
  parseHTML: () => [{ tag: "div[data-type=toggle-summary]" }],
  renderHTML: () => ["div", { "data-type": "toggle-summary" }, 0],
})

/** E.13: two columns. A column may be empty, so the editor never invents content. */
export const Columns = Node.create({
  name: "columns",
  group: "block",
  content: "column column",
  defining: true,
  addAttributes: () => blockAttributes,
  parseHTML: () => [{ tag: "div[data-type=columns]" }],
  renderHTML: ({ node }) => ["div", dataAttrs(node.attrs, "columns"), 0],
})

export const Column = Node.create({
  name: "column",
  content: "block*",
  isolating: true,
  parseHTML: () => [{ tag: "div[data-type=column]" }],
  renderHTML: () => ["div", { "data-type": "column" }, 0],
})

/** E.15: a document table — rows, columns, an optional header row, end-aligned numbers. Not a database. */
export const Table = Node.create({
  name: "table",
  group: "block",
  content: "tableRow*",
  isolating: true,
  addAttributes: () => ({ ...blockAttributes, headerRow: { default: true }, columns: json([]) }),
  parseHTML: () => [{ tag: "table" }],
  renderHTML: ({ node }) => ["table", dataAttrs(node.attrs, "table", { "data-header-row": node.attrs.headerRow ? "true" : "false" }), ["tbody", 0]],
})

export const TableRow = Node.create({
  name: "tableRow",
  content: "tableCell*",
  parseHTML: () => [{ tag: "tr" }],
  renderHTML: () => ["tr", 0],
})

export const TableCell = Node.create({
  name: "tableCell",
  content: "inline*",
  isolating: true,
  parseHTML: () => [{ tag: "td" }, { tag: "th" }],
  renderHTML: () => ["td", 0],
})

function atom(name: string, attrs: Record<string, unknown>) {
  return Node.create({
    name,
    group: "block",
    atom: true,
    selectable: true,
    draggable: false,
    addAttributes: () => ({ ...blockAttributes, ...attrs }),
    parseHTML: () => [{ tag: `div[data-type=${name}]` }],
    renderHTML: ({ node }) => ["div", dataAttrs(node.attrs, name)],
  })
}

export const PageBreak = atom("pageBreak", {})
/** Image: half, wide or full width, alt text, an optional caption (E.13). */
export const Image = atom("image", { src: { default: "" }, alt: { default: "" }, width: { default: "full" }, caption: json(null) })
export const FileBlock = atom("file", { fileId: { default: "" }, name: { default: "" }, size: { default: 0 }, mime: { default: "" } })
/** E.16: live in the editor; each PDF freezes the values. */
export const RecordBlock = atom("record", { ref: json(null), view: { default: "card" } })
export const ChartBlock = atom("chart", { chart: { default: "bar" }, source: json(null), title: { default: null } })
/** Stage 6: signers, a date, an optional stamp. */
export const SignatureBlock = atom("signature", { signers: json([]), date: json([]), stamp: { default: true } })
/** E.40 */
export const BarcodeBlock = atom("barcode", {
  symbology: { default: "code128" },
  value: json([]),
  showText: { default: true },
  widthMm: { default: null },
  heightMm: { default: null },
})
/** E.41 */
export const QrBlock = atom("qr", { value: json([]), caption: json(null), sizeMm: { default: null } })
/** E.42 */
export const MathBlock = atom("math", { tex: { default: "" } })

export const richSchemaExtensions = [
  Callout,
  Toggle,
  ToggleSummary,
  Columns,
  Column,
  Table,
  TableRow,
  TableCell,
  PageBreak,
  Image,
  FileBlock,
  RecordBlock,
  ChartBlock,
  SignatureBlock,
  BarcodeBlock,
  QrBlock,
  MathBlock,
]
