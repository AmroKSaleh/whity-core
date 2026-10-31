import type { Editor, JSONContent } from "@tiptap/core"

import type { IconName } from "../icon"
import { currentBlock } from "./behaviour"
import type { EditorLabels } from "./labels"

/**
 * The blocks the editor inserts in this version, grouped as the / menu shows
 * them (EditorInsert, E.13). The same list feeds the + inserter, Insert ▾ and
 * Turn into. Media, Whity, saved and plugin blocks join in later milestones.
 */
export interface CatalogueItem {
  id: string
  group: "groupBasic" | "groupLists" | "groupStructure" | "groupMedia" | "groupWhity"
  label: keyof EditorLabels
  hint: keyof EditorLabels
  icon: IconName
  /** The Markdown shortcut, shown beside the item. */
  markdown?: string
  keywords: string[]
  /** Text blocks can be a "Turn into" target. */
  textBlock: boolean
  node: { type: string; attrs?: Record<string, unknown>; content?: JSONContent[] }
}

const cell = (text = "") => ({ type: "tableCell", ...(text ? { content: [{ type: "text", text }] } : {}) })
const row = (n: number) => ({ type: "tableRow", content: Array.from({ length: n }, () => cell()) })

export const CATALOGUE: CatalogueItem[] = [
  { id: "text", group: "groupBasic", label: "blockText", hint: "blockTextHint", icon: "text", keywords: ["paragraph", "plain"], textBlock: true, node: { type: "paragraph" } },
  { id: "h1", group: "groupBasic", label: "blockH1", hint: "blockH1Hint", icon: "heading-1", markdown: "#", keywords: ["title", "h1"], textBlock: true, node: { type: "heading", attrs: { level: 1 } } },
  { id: "h2", group: "groupBasic", label: "blockH2", hint: "blockH2Hint", icon: "heading-2", markdown: "##", keywords: ["subtitle", "h2"], textBlock: true, node: { type: "heading", attrs: { level: 2 } } },
  { id: "h3", group: "groupBasic", label: "blockH3", hint: "blockH3Hint", icon: "heading-3", markdown: "###", keywords: ["h3"], textBlock: true, node: { type: "heading", attrs: { level: 3 } } },
  { id: "quote", group: "groupBasic", label: "blockQuote", hint: "blockQuoteHint", icon: "blockquote", markdown: ">", keywords: ["citation"], textBlock: true, node: { type: "quote" } },
  { id: "code", group: "groupBasic", label: "blockCode", hint: "blockCodeHint", icon: "code", markdown: "```", keywords: ["snippet", "monospace"], textBlock: true, node: { type: "codeBlock" } },
  { id: "bullet", group: "groupLists", label: "blockBullet", hint: "blockBulletHint", icon: "list-bullet", markdown: "-", keywords: ["unordered", "ul"], textBlock: true, node: { type: "listItem", attrs: { kind: "bullet", level: 0 } } },
  { id: "number", group: "groupLists", label: "blockNumber", hint: "blockNumberHint", icon: "list-numbered", markdown: "1.", keywords: ["ordered", "ol"], textBlock: true, node: { type: "listItem", attrs: { kind: "number", level: 0 } } },
  { id: "checklist", group: "groupLists", label: "blockChecklist", hint: "blockChecklistHint", icon: "list-todo", markdown: "[]", keywords: ["todo", "task", "check"], textBlock: true, node: { type: "checkItem", attrs: { level: 0, checked: false } } },
  { id: "callout", group: "groupBasic", label: "blockCallout", hint: "blockCalloutHint", icon: "info", keywords: ["note", "important", "warning", "aside"], textBlock: true, node: { type: "callout", attrs: { kind: "note" } } },
  { id: "math", group: "groupBasic", label: "blockMath", hint: "blockMathHint", icon: "text", keywords: ["formula", "equation", "tex", "latex", "katex"], textBlock: false, node: { type: "math", attrs: { tex: "" } } },
  { id: "toggle", group: "groupStructure", label: "blockToggle", hint: "blockToggleHint", icon: "chevron-right", keywords: ["collapse", "expand", "details", "faq"], textBlock: false, node: { type: "toggle", attrs: { open: true }, content: [{ type: "toggleSummary" }, { type: "paragraph" }] } },
  { id: "columns", group: "groupStructure", label: "blockColumns", hint: "blockColumnsHint", icon: "columns", keywords: ["layout", "side by side", "split"], textBlock: false, node: { type: "columns", content: [{ type: "column", content: [{ type: "paragraph" }] }, { type: "column", content: [{ type: "paragraph" }] }] } },
  { id: "divider", group: "groupStructure", label: "blockDivider", hint: "blockDividerHint", icon: "divider", markdown: "---", keywords: ["line", "separator", "hr", "rule"], textBlock: false, node: { type: "divider" } },
  { id: "pageBreak", group: "groupStructure", label: "blockPageBreak", hint: "blockPageBreakHint", icon: "page-break", keywords: ["new page", "print"], textBlock: false, node: { type: "pageBreak" } },
  { id: "table", group: "groupMedia", label: "blockTable", hint: "blockTableHint", icon: "table", keywords: ["grid", "rows", "columns"], textBlock: false, node: { type: "table", attrs: { headerRow: true, columns: [{ align: "start" }, { align: "start" }, { align: "end" }] }, content: [row(3), row(3)] } },
  { id: "image", group: "groupMedia", label: "blockImage", hint: "blockImageHint", icon: "image", keywords: ["picture", "photo", "logo"], textBlock: false, node: { type: "image", attrs: { src: "", alt: "", width: "full" } } },
  { id: "file", group: "groupMedia", label: "blockFile", hint: "blockFileHint", icon: "document", keywords: ["attachment", "pdf", "download"], textBlock: false, node: { type: "file" } },
  { id: "record", group: "groupWhity", label: "blockRecord", hint: "blockRecordHint", icon: "invoice", keywords: ["invoice", "customer", "live", "embed"], textBlock: false, node: { type: "record", attrs: { ref: null, view: "card" } } },
  { id: "chart", group: "groupWhity", label: "blockChart", hint: "blockChartHint", icon: "chart", keywords: ["graph", "bars", "line", "donut", "live"], textBlock: false, node: { type: "chart", attrs: { chart: "bar", source: null } } },
  { id: "signature", group: "groupWhity", label: "blockSignature", hint: "blockSignatureHint", icon: "edit", keywords: ["sign", "stamp", "signer"], textBlock: false, node: { type: "signature", attrs: { signers: [{ name: [], role: [] }], date: [], stamp: true } } },
  { id: "barcode", group: "groupWhity", label: "blockBarcode", hint: "blockBarcodeHint", icon: "qr", keywords: ["code128", "ean", "code39", "label"], textBlock: false, node: { type: "barcode", attrs: { symbology: "code128", value: [], showText: true } } },
  { id: "qr", group: "groupWhity", label: "blockQr", hint: "blockQrHint", icon: "qr", keywords: ["qrcode", "scan", "link"], textBlock: false, node: { type: "qr", attrs: { value: [] } } },
]

/** Filter by the item's name, hint and keywords, in the current language and in English. */
export function filterCatalogue(query: string, labels: EditorLabels, english: EditorLabels): CatalogueItem[] {
  const q = query.trim().toLowerCase()
  if (!q) return CATALOGUE
  return CATALOGUE.filter((item) =>
    [labels[item.label], labels[item.hint], english[item.label], english[item.hint], item.id, ...item.keywords]
      .some((word) => String(word).toLowerCase().includes(q))
  )
}

/** The catalogue item that matches the current block, for the style menu and Turn into. */
export function currentItem(editor: Editor): CatalogueItem | undefined {
  const block = currentBlock(editor)
  if (!block) return undefined
  const { name } = block.node.type
  return CATALOGUE.find(
    (item) =>
      item.node.type === name &&
      (name !== "heading" || item.node.attrs?.level === block.node.attrs.level) &&
      (name !== "listItem" || item.node.attrs?.kind === block.node.attrs.kind)
  )
}

/**
 * Put the item where the caret is (E.13, EditorInsert): an empty text line
 * turns into the block; a line with text gets the block after it.
 */
export function applyItem(editor: Editor, item: CatalogueItem, { after }: { after?: number } = {}): void {
  const block = currentBlock(editor)
  const atPos = after ?? (block ? block.pos + block.node.nodeSize : editor.state.doc.content.size)
  const empty = after === undefined && block && block.node.isTextblock && block.node.content.size === 0
  const chain = editor.chain().focus()
  if (empty && item.textBlock) {
    chain.setNode(item.node.type, { ...item.node.attrs, id: block.node.attrs.id }).run()
    return
  }
  const node: JSONContent = { type: item.node.type, attrs: item.node.attrs, ...(item.node.content ? { content: item.node.content } : {}) }
  if (empty && block) {
    // A block on an empty line takes its place, with a fresh line after it.
    chain.insertContentAt({ from: block.pos, to: block.pos + block.node.nodeSize }, [node, { type: "paragraph" }]).run()
    return
  }
  const content = item.textBlock ? [node] : [node, { type: "paragraph" }]
  // The caret lands in the new text block, or in the fresh line after a divider.
  chain
    .insertContentAt(atPos, content)
    .setTextSelection(atPos + (item.textBlock ? 1 : 2))
    .run()
}
