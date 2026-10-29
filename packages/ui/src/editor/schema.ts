import { Mark, Node, mergeAttributes } from "@tiptap/core"

import { richSchemaExtensions } from "./schema-rich"

/**
 * The ProseMirror schema for the WhityDocument contract (contract.ts,
 * handoff/editor/README.md). Every block node carries a stable `id` (BlockId)
 * and an optional `suggestion` (E.4). Lists are flat items with a `level`
 * (Notion style), so drag-and-drop and Turn into stay simple. Blocks the
 * editor can't edit yet (later milestones) are kept whole in `unknownBlock`,
 * so a document never loses data by passing through the editor.
 *
 * Headings 1–3 render as h2–h4: the document title is the page's h1.
 */

/** Attributes every block node has. */
const blockAttributes = {
  id: { default: null, keepOnSplit: false },
  suggestion: { default: null, rendered: false },
}

function blockAttrs(attrs: Record<string, unknown>, extra: Record<string, unknown> = {}) {
  return mergeAttributes({ "data-block-id": attrs.id ?? undefined, ...extra })
}

export const DocumentNode = Node.create({
  name: "doc",
  topNode: true,
  content: "block+",
})

export const TextNode = Node.create({
  name: "text",
  group: "inline",
})

export const Paragraph = Node.create({
  name: "paragraph",
  group: "block",
  content: "inline*",
  priority: 1000,
  addAttributes: () => blockAttributes,
  parseHTML: () => [{ tag: "p" }],
  renderHTML: ({ node }) => ["p", blockAttrs(node.attrs, { "data-type": "paragraph" }), 0],
})

export const Heading = Node.create({
  name: "heading",
  group: "block",
  content: "inline*",
  defining: true,
  addAttributes: () => ({ ...blockAttributes, level: { default: 1 } }),
  parseHTML: () => [
    { tag: "h2", attrs: { level: 1 } },
    { tag: "h3", attrs: { level: 2 } },
    { tag: "h4", attrs: { level: 3 } },
  ],
  renderHTML: ({ node }) => [
    `h${Number(node.attrs.level) + 1}`,
    blockAttrs(node.attrs, { "data-type": "heading", "data-level": node.attrs.level }),
    0,
  ],
})

export const Quote = Node.create({
  name: "quote",
  group: "block",
  content: "inline*",
  defining: true,
  addAttributes: () => blockAttributes,
  parseHTML: () => [{ tag: "blockquote" }],
  renderHTML: ({ node }) => ["blockquote", blockAttrs(node.attrs, { "data-type": "quote" }), 0],
})

export const CodeBlock = Node.create({
  name: "codeBlock",
  group: "block",
  content: "text*",
  marks: "",
  code: true,
  defining: true,
  addAttributes: () => ({ ...blockAttributes, language: { default: null } }),
  parseHTML: () => [{ tag: "pre", preserveWhitespace: "full" }],
  renderHTML: ({ node }) => [
    "pre",
    blockAttrs(node.attrs, { "data-type": "code", "data-language": node.attrs.language ?? undefined }),
    ["code", 0],
  ],
})

/** A bulleted or numbered item; `level` 0–2. Numbering is a decoration (lists.ts). */
export const ListItem = Node.create({
  name: "listItem",
  group: "block",
  content: "inline*",
  defining: true,
  addAttributes: () => ({ ...blockAttributes, kind: { default: "bullet" }, level: { default: 0 } }),
  parseHTML: () => [{ tag: "div[data-type=list-item]" }],
  renderHTML: ({ node }) => [
    "div",
    blockAttrs(node.attrs, { "data-type": "list-item", "data-kind": node.attrs.kind, "data-level": node.attrs.level }),
    0,
  ],
})

/** A checklist item; `level` 0–2. The checkbox is a node view (doc-editor). */
export const CheckItem = Node.create({
  name: "checkItem",
  group: "block",
  content: "inline*",
  defining: true,
  addAttributes: () => ({ ...blockAttributes, level: { default: 0 }, checked: { default: false } }),
  parseHTML: () => [{ tag: "div[data-type=check-item]" }],
  renderHTML: ({ node }) => [
    "div",
    blockAttrs(node.attrs, {
      "data-type": "check-item",
      "data-level": node.attrs.level,
      "data-checked": node.attrs.checked ? "true" : "false",
    }),
    0,
  ],
})

export const Divider = Node.create({
  name: "divider",
  group: "block",
  atom: true,
  selectable: true,
  addAttributes: () => blockAttributes,
  parseHTML: () => [{ tag: "hr" }],
  renderHTML: ({ node }) => ["hr", blockAttrs(node.attrs, { "data-type": "divider" })],
})

/** A block a later milestone edits (callout, table, record, plugin…): kept whole, shown as a placeholder. */
export const UnknownBlock = Node.create<{ label: (blockType: string) => string }>({
  name: "unknownBlock",
  group: "block",
  atom: true,
  selectable: true,
  draggable: false,
  addOptions: () => ({ label: (blockType: string) => blockType }),
  addAttributes: () => ({ ...blockAttributes, data: { default: null, rendered: false } }),
  parseHTML: () => [{ tag: "div[data-type=unknown-block]" }],
  renderHTML({ node }) {
    const blockType = String(node.attrs.data?.type ?? "")
    return [
      "div",
      blockAttrs(node.attrs, {
        "data-type": "unknown-block",
        "data-block-type": blockType,
        "data-label": this.options.label(blockType),
      }),
    ]
  },
})

export const HardBreak = Node.create({
  name: "hardBreak",
  group: "inline",
  inline: true,
  selectable: false,
  parseHTML: () => [{ tag: "br" }],
  renderHTML: () => ["br"],
  addKeyboardShortcuts() {
    return { "Shift-Enter": () => this.editor.commands.insertContent({ type: this.name }) }
  },
})

/** E.26: a merge field, one character for the caret. The chip and the picker come in M4. */
export const Field = Node.create({
  name: "field",
  group: "inline",
  inline: true,
  atom: true,
  selectable: true,
  addAttributes: () => ({
    path: { default: "" },
    label: { default: "" },
    required: { default: null },
    perDocument: { default: null },
  }),
  parseHTML: () => [{ tag: "span[data-field]" }],
  renderHTML: ({ node }) => [
    "span",
    { "data-field": node.attrs.path, title: node.attrs.path, class: "wy-field" },
    node.attrs.label || node.attrs.path,
  ],
})

/** E.42: an inline formula in TeX. KaTeX renders it in M3; until then it shows its source. */
export const MathInline = Node.create({
  name: "mathInline",
  group: "inline",
  inline: true,
  atom: true,
  selectable: true,
  addAttributes: () => ({ tex: { default: "" } }),
  parseHTML: () => [{ tag: "span[data-math]" }],
  renderHTML: ({ node }) => ["span", { "data-math": "", dir: "ltr", class: "wy-math" }, node.attrs.tex],
})

/* --------------------------------------------------------------- Marks */

const simpleMark = (name: string, tag: string, extra: Record<string, string> = {}) =>
  Mark.create({
    name,
    parseHTML: () => [{ tag }],
    renderHTML: () => [tag, extra, 0],
  })

/** Bold renders at 600, never 700 (decision 3.6). */
export const Bold = Mark.create({
  name: "bold",
  parseHTML: () => [{ tag: "strong" }, { tag: "b" }],
  renderHTML: () => ["strong", 0],
})
export const Italic = simpleMark("italic", "em")
export const Underline = simpleMark("underline", "u")
export const Strike = simpleMark("strike", "s")
// Code doesn't exclude other marks: the contract allows bold code, and the
// editor must not drop a mark a document already has.
export const Code = Mark.create({
  name: "code",
  code: true,
  parseHTML: () => [{ tag: "code" }],
  renderHTML: () => ["code", 0],
})

export const Link = Mark.create({
  name: "link",
  inclusive: false,
  addAttributes: () => ({ href: { default: "" }, recordRef: { default: null, rendered: false } }),
  parseHTML: () => [{ tag: "a[href]", getAttrs: (el) => ({ href: (el as HTMLElement).getAttribute("href") }) }],
  renderHTML: ({ mark }) => ["a", { href: mark.attrs.href, rel: "noopener noreferrer", target: "_blank" }, 0],
})

/** E.25: one highlight colour. */
export const Highlight = simpleMark("highlight", "mark", { "data-type": "highlight" })

/* Marks later milestones give a UI (comments M5, suggestions M5, per-document parts M7). */
export const CommentMark = Mark.create({
  name: "comment",
  inclusive: false,
  excludes: "",
  addAttributes: () => ({ threadId: { default: "" } }),
  parseHTML: () => [{ tag: "span[data-comment]" }],
  renderHTML: ({ mark }) => ["span", { "data-comment": mark.attrs.threadId }, 0],
})
export const Insertion = Mark.create({
  name: "insertion",
  excludes: "deletion",
  addAttributes: () => ({ suggestionId: { default: "" } }),
  parseHTML: () => [{ tag: "ins" }],
  renderHTML: ({ mark }) => ["ins", { "data-suggestion": mark.attrs.suggestionId }, 0],
})
export const Deletion = Mark.create({
  name: "deletion",
  excludes: "insertion",
  addAttributes: () => ({ suggestionId: { default: "" } }),
  parseHTML: () => [{ tag: "del" }],
  renderHTML: ({ mark }) => ["del", { "data-suggestion": mark.attrs.suggestionId }, 0],
})
export const PerDocument = Mark.create({
  name: "perDocument",
  parseHTML: () => [{ tag: "span[data-per-document]" }],
  renderHTML: () => ["span", { "data-per-document": "" }, 0],
})

/** In schema order; the contract's marks come out of the editor in this order. */
export const MARK_ORDER = [
  "link",
  "bold",
  "italic",
  "underline",
  "strike",
  "code",
  "highlight",
  "comment",
  "insertion",
  "deletion",
  "perDocument",
] as const

export const schemaExtensions = [
  DocumentNode,
  TextNode,
  Paragraph,
  Heading,
  Quote,
  CodeBlock,
  ListItem,
  CheckItem,
  Divider,
  UnknownBlock,
  ...richSchemaExtensions,
  HardBreak,
  Field,
  MathInline,
  Link,
  Bold,
  Italic,
  Underline,
  Strike,
  Code,
  Highlight,
  CommentMark,
  Insertion,
  Deletion,
  PerDocument,
]
