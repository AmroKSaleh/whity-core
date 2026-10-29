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

const TEXT_BLOCKS = new Set(["paragraph", "heading", "quote", "bullet", "number", "checklist", "code", "divider"])

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
    default:
      return { type: "unknownBlock", attrs: { ...common(block), data: block } }
  }
}

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
    case "unknownBlock":
      return a.data ? { ...(a.data as Block), id } : null
    default:
      return null
  }
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
    if (!TEXT_BLOCKS.has(block.type)) return block
    if ("content" in block && Array.isArray(block.content)) {
      return { ...block, content: normalizeInline(block.content) } as Block
    }
    return block
  })
}
