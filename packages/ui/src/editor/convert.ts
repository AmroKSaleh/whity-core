import type { JSONContent } from "@tiptap/core"

import type { Block, Inline, Mark as DocMark, Text } from "./contract"
import { MARK_ORDER } from "./schema"

/**
 * WhityDocument blocks ⇄ ProseMirror JSON. `toEditorContent` and
 * `fromEditorContent` are inverses on normalised documents
 * (`normalizeBlocks`): adjacent text runs with the same marks are one run,
 * marks are in schema order, and empty runs are dropped. Blocks the editor
 * doesn't edit yet travel through `unknownBlock` unchanged.
 */

type PmMark = { type: string; attrs?: Record<string, unknown> }

const TEXT_BLOCKS = new Set(["paragraph", "heading", "quote", "bullet", "number", "checklist", "code", "divider", "callout"])

/* ------------------------------------------------------------- inline */

function markToPm(mark: DocMark): PmMark {
  switch (mark.type) {
    case "link":
      return { type: "link", attrs: { href: mark.href, recordRef: mark.recordRef ?? null } }
    case "comment":
      return { type: "comment", attrs: { threadId: mark.threadId } }
    case "insertion":
    case "deletion":
      return { type: mark.type, attrs: { suggestionId: mark.suggestionId } }
    default:
      return { type: mark.type }
  }
}

function markFromPm(mark: PmMark): DocMark {
  const a = mark.attrs ?? {}
  switch (mark.type) {
    case "link":
      return a.recordRef
        ? { type: "link", href: String(a.href ?? ""), recordRef: a.recordRef as { type: string; id: string } }
        : { type: "link", href: String(a.href ?? "") }
    case "comment":
      return { type: "comment", threadId: String(a.threadId ?? "") }
    case "insertion":
    case "deletion":
      return { type: mark.type, suggestionId: String(a.suggestionId ?? "") }
    default:
      return { type: mark.type } as DocMark
  }
}

const markRank = (m: DocMark) => MARK_ORDER.indexOf(m.type as (typeof MARK_ORDER)[number])

function sortMarks(marks: DocMark[] | undefined): DocMark[] | undefined {
  if (!marks || marks.length === 0) return undefined
  return [...marks].sort((a, b) => markRank(a) - markRank(b))
}

const sameMarks = (a?: DocMark[], b?: DocMark[]) => JSON.stringify(a ?? []) === JSON.stringify(b ?? [])

function inlineToPm(content: Inline[]): JSONContent[] {
  const out: JSONContent[] = []
  for (const run of content) {
    if (run.type === "text") {
      if (!run.text) continue
      const node: JSONContent = { type: "text", text: run.text }
      if (run.marks?.length) node.marks = run.marks.map(markToPm)
      out.push(node)
    } else if (run.type === "break") {
      out.push({ type: "hardBreak" })
    } else if (run.type === "math") {
      out.push({ type: "mathInline", attrs: { tex: run.tex } })
    } else {
      const node: JSONContent = {
        type: "field",
        attrs: { path: run.path, label: run.label, required: run.required ?? null, perDocument: run.perDocument ?? null },
      }
      if (run.marks?.length) node.marks = run.marks.map(markToPm)
      out.push(node)
    }
  }
  return out
}

function inlineFromPm(content: JSONContent[] | undefined): Inline[] {
  const out: Inline[] = []
  for (const node of content ?? []) {
    if (node.type === "text") {
      const marks = sortMarks(node.marks?.map((m) => markFromPm(m as PmMark)))
      const last = out[out.length - 1]
      if (last && last.type === "text" && sameMarks(last.marks, marks)) {
        last.text += node.text ?? ""
      } else {
        const run: Text = { type: "text", text: node.text ?? "" }
        if (marks) run.marks = marks
        out.push(run)
      }
    } else if (node.type === "hardBreak") {
      out.push({ type: "break" })
    } else if (node.type === "mathInline") {
      out.push({ type: "math", tex: String(node.attrs?.tex ?? "") })
    } else if (node.type === "field") {
      const a = node.attrs ?? {}
      const field: Inline = { type: "field", path: String(a.path ?? ""), label: String(a.label ?? "") }
      if (a.required != null) field.required = Boolean(a.required)
      if (a.perDocument != null) field.perDocument = Boolean(a.perDocument)
      const marks = sortMarks(node.marks?.map((m) => markFromPm(m as PmMark)))
      if (marks) field.marks = marks
      out.push(field)
    }
  }
  return out
}

/** The canonical form of inline content: what comes back out of the editor. */
export function normalizeInline(content: Inline[]): Inline[] {
  return inlineFromPm(inlineToPm(content))
}

/* ------------------------------------------------------------- blocks */

function common(block: Block) {
  return { id: block.id, suggestion: block.suggestion ?? null }
}

export function blockToPm(block: Block): JSONContent {
  const content = (inline: Inline[]) => {
    const c = inlineToPm(inline)
    return c.length ? { content: c } : {}
  }
  switch (block.type) {
    case "paragraph":
      return { type: "paragraph", attrs: common(block), ...content(block.content) }
    case "heading":
      return { type: "heading", attrs: { ...common(block), level: block.level }, ...content(block.content) }
    case "quote":
      return { type: "quote", attrs: common(block), ...content(block.content) }
    case "code":
      return {
        type: "codeBlock",
        attrs: { ...common(block), language: block.language ?? null },
        ...(block.text ? { content: [{ type: "text", text: block.text }] } : {}),
      }
    case "bullet":
    case "number":
      return { type: "listItem", attrs: { ...common(block), kind: block.type, level: block.level }, ...content(block.content) }
    case "checklist":
      return {
        type: "checkItem",
        attrs: { ...common(block), level: block.level, checked: block.checked },
        ...content(block.content),
      }
    case "divider":
      return { type: "divider", attrs: common(block) }
    case "callout":
      return { type: "callout", attrs: { ...common(block), kind: block.kind }, ...content(block.content) }
    case "toggle":
      return {
        type: "toggle",
        attrs: { ...common(block), open: block.open },
        content: [{ type: "toggleSummary", ...content(block.summary) }, ...block.children.map(blockToPm)],
      }
    case "columns":
      return {
        type: "columns",
        attrs: common(block),
        content: block.columns.map((col) => ({ type: "column", ...(col.length ? { content: col.map(blockToPm) } : {}) })),
      }
    case "table":
      return {
        type: "table",
        attrs: { ...common(block), headerRow: block.headerRow, columns: block.columns },
        content: block.rows.map((row) => ({
          type: "tableRow",
          content: row.map((cell) => ({ type: "tableCell", ...content(cell) })),
        })),
      }
    case "pageBreak":
      return { type: "pageBreak", attrs: common(block) }
    case "image":
      return { type: "image", attrs: { ...common(block), src: block.src, alt: block.alt, width: block.width, caption: block.caption ?? null } }
    case "file":
      return { type: "file", attrs: { ...common(block), fileId: block.fileId, name: block.name, size: block.size, mime: block.mime } }
    case "record":
      return { type: "record", attrs: { ...common(block), ref: block.ref, view: block.view } }
    case "chart":
      return { type: "chart", attrs: { ...common(block), chart: block.chart, source: block.source, title: block.title ?? null } }
    case "signature":
      // @date-display-ignore: the signature date is text the author typed or a merge field (Inline[]), not a record timestamp.
      return { type: "signature", attrs: { ...common(block), signers: block.signers, date: block.date, stamp: block.stamp } }
    case "barcode":
      return {
        type: "barcode",
        attrs: {
          ...common(block),
          symbology: block.symbology,
          value: block.value,
          showText: block.showText,
          widthMm: block.widthMm ?? null,
          heightMm: block.heightMm ?? null,
        },
      }
    case "qr":
      return { type: "qr", attrs: { ...common(block), value: block.value, caption: block.caption ?? null, sizeMm: block.sizeMm ?? null } }
    case "math":
      return { type: "math", attrs: { ...common(block), tex: block.tex } }
    default:
      return { type: "unknownBlock", attrs: { ...common(block), data: block } }
  }
}

/** Copy an optional contract field only when it is set, so a round trip adds nothing. */
function optional<T extends object>(block: T, key: string, value: unknown): T {
  if (value !== null && value !== undefined) (block as Record<string, unknown>)[key] = value
  return block
}

type Of<T extends Block["type"]> = Extract<Block, { type: T }>

function withCommon<T extends Block>(block: T, attrs: Record<string, unknown>): T {
  if (attrs.suggestion) (block as Block).suggestion = attrs.suggestion as Block["suggestion"]
  return block
}

export function blockFromPm(node: JSONContent): Block | null {
  const a = node.attrs ?? {}
  const id = String(a.id ?? "")
  const inline = () => inlineFromPm(node.content)
  switch (node.type) {
    case "paragraph":
      return withCommon({ id, type: "paragraph", content: inline() }, a)
    case "heading":
      return withCommon({ id, type: "heading", level: Number(a.level) as 1 | 2 | 3, content: inline() }, a)
    case "quote":
      return withCommon({ id, type: "quote", content: inline() }, a)
    case "codeBlock": {
      const block: Block = { id, type: "code", text: (node.content ?? []).map((t) => t.text ?? "").join("") }
      if (a.language) block.language = String(a.language)
      return withCommon(block, a)
    }
    case "listItem":
      return withCommon(
        { id, type: a.kind === "number" ? "number" : "bullet", level: Number(a.level) as 0 | 1 | 2, content: inline() },
        a
      )
    case "checkItem":
      return withCommon(
        { id, type: "checklist", level: Number(a.level) as 0 | 1 | 2, checked: Boolean(a.checked), content: inline() },
        a
      )
    case "divider":
      return withCommon({ id, type: "divider" }, a)
    case "callout":
      return withCommon({ id, type: "callout", kind: a.kind === "important" ? "important" : "note", content: inline() }, a)
    case "toggle": {
      const [summary, ...children] = node.content ?? []
      return withCommon(
        { id, type: "toggle", open: Boolean(a.open), summary: inlineFromPm(summary?.content), children: blocksFromPm(children) },
        a
      )
    }
    case "columns": {
      const cols = (node.content ?? []).map((col) => blocksFromPm(col.content))
      return withCommon({ id, type: "columns", columns: [cols[0] ?? [], cols[1] ?? []] }, a)
    }
    case "table":
      return withCommon(
        {
          id,
          type: "table",
          headerRow: Boolean(a.headerRow),
          columns: (a.columns ?? []) as Of<"table">["columns"],
          rows: (node.content ?? []).map((row) => (row.content ?? []).map((cell) => inlineFromPm(cell.content))),
        },
        a
      )
    case "pageBreak":
      return withCommon({ id, type: "pageBreak" }, a)
    case "image":
      return withCommon(
        optional<Of<"image">>(
          { id, type: "image", src: String(a.src ?? ""), alt: String(a.alt ?? ""), width: (a.width ?? "full") as Of<"image">["width"] },
          "caption",
          a.caption
        ),
        a
      )
    case "file":
      return withCommon(
        { id, type: "file", fileId: String(a.fileId ?? ""), name: String(a.name ?? ""), size: Number(a.size ?? 0), mime: String(a.mime ?? "") },
        a
      )
    case "record":
      return withCommon({ id, type: "record", ref: a.ref as Of<"record">["ref"], view: a.view === "summary" ? "summary" : "card" }, a)
    case "chart":
      return withCommon(
        optional<Of<"chart">>(
          { id, type: "chart", chart: (a.chart ?? "bar") as Of<"chart">["chart"], source: a.source as Of<"chart">["source"] },
          "title",
          a.title
        ),
        a
      )
    case "signature":
      return withCommon(
        { id, type: "signature", signers: (a.signers ?? []) as Of<"signature">["signers"], date: (a.date ?? []) as Inline[], stamp: Boolean(a.stamp) },
        a
      )
    case "barcode": {
      const block: Of<"barcode"> = {
        id,
        type: "barcode",
        symbology: (a.symbology ?? "code128") as Of<"barcode">["symbology"],
        value: (a.value ?? []) as Of<"barcode">["value"],
        showText: Boolean(a.showText),
      }
      return withCommon(optional(optional(block, "widthMm", a.widthMm), "heightMm", a.heightMm), a)
    }
    case "qr": {
      const block: Of<"qr"> = { id, type: "qr", value: (a.value ?? []) as Of<"qr">["value"] }
      return withCommon(optional(optional(block, "caption", a.caption), "sizeMm", a.sizeMm), a)
    }
    case "math":
      return withCommon({ id, type: "math", tex: String(a.tex ?? "") }, a)
    case "unknownBlock":
      return a.data ? { ...(a.data as Block), id } : null
    default:
      return null
  }
}

function blocksFromPm(nodes: JSONContent[] | undefined): Block[] {
  return (nodes ?? []).map(blockFromPm).filter((b): b is Block => b !== null)
}

/** The editor's content for a document's blocks. An empty document gets one empty paragraph. */
export function toEditorContent(blocks: Block[]): JSONContent {
  const content = blocks.map(blockToPm)
  return { type: "doc", content: content.length ? content : [{ type: "paragraph", attrs: { id: null, suggestion: null } }] }
}

export function fromEditorContent(doc: JSONContent): Block[] {
  return (doc.content ?? []).map(blockFromPm).filter((b): b is Block => b !== null)
}

/** The canonical form of a document's blocks: equal to what a round trip through the editor returns. */
export function normalizeBlocks(blocks: Block[]): Block[] {
  return blocks.map((block) => {
    switch (block.type) {
      case "toggle":
        return { ...block, summary: normalizeInline(block.summary), children: normalizeBlocks(block.children) }
      case "columns":
        return { ...block, columns: [normalizeBlocks(block.columns[0]), normalizeBlocks(block.columns[1])] as Of<"columns">["columns"] }
      case "table":
        return { ...block, rows: block.rows.map((row) => row.map(normalizeInline)) }
    }
    if (!TEXT_BLOCKS.has(block.type)) return block
    if ("content" in block && Array.isArray(block.content)) {
      return { ...block, content: normalizeInline(block.content) } as Block
    }
    return block
  })
}
