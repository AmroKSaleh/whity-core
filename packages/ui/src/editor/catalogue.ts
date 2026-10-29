import type { Editor } from "@tiptap/core"

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
  group: "groupBasic" | "groupLists" | "groupStructure"
  label: keyof EditorLabels
  hint: keyof EditorLabels
  icon: IconName
  /** The Markdown shortcut, shown beside the item. */
  markdown?: string
  keywords: string[]
  /** Text blocks can be a "Turn into" target. */
  textBlock: boolean
  node: { type: string; attrs?: Record<string, unknown> }
}

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
  { id: "divider", group: "groupStructure", label: "blockDivider", hint: "blockDividerHint", icon: "divider", markdown: "---", keywords: ["line", "separator", "hr", "rule"], textBlock: false, node: { type: "divider" } },
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
  if (empty && block) {
    // A divider on an empty line takes its place, with a fresh line after it.
    chain
      .insertContentAt({ from: block.pos, to: block.pos + block.node.nodeSize }, [{ type: item.node.type, attrs: item.node.attrs }, { type: "paragraph" }])
      .run()
    return
  }
  const content = item.textBlock
    ? [{ type: item.node.type, attrs: item.node.attrs }]
    : [{ type: item.node.type, attrs: item.node.attrs }, { type: "paragraph" }]
  // The caret lands in the new text block, or in the fresh line after a divider.
  chain
    .insertContentAt(atPos, content)
    .setTextSelection(atPos + (item.textBlock ? 1 : 2))
    .run()
}
