import {
  Extension,
  InputRule,
  markInputRule,
  nodeInputRule,
  type Editor,
} from "@tiptap/core"
import type { NodeType, Node as PmNode } from "@tiptap/pm/model"
import { Plugin, PluginKey, TextSelection } from "@tiptap/pm/state"
import { Decoration, DecorationSet } from "@tiptap/pm/view"

/**
 * Editing behaviour for the document schema (schema.ts): flat lists with
 * levels, Markdown shortcuts (E.28; ⌘Z straight after one turns it back into
 * what was typed), Google Docs shortcuts, and moving blocks from the keyboard.
 */

const LIST_TYPES = new Set(["listItem", "checkItem"])
const MAX_LEVEL = 2

/** The top-level block holding the selection: its node, position and index. */
export function currentBlock(editor: Editor): { node: PmNode; pos: number; index: number } | null {
  const { $from } = editor.state.selection
  if ($from.depth < 1) {
    const node = editor.state.doc.nodeAt($from.pos)
    return node ? { node, pos: $from.pos, index: $from.index(0) } : null
  }
  return { node: $from.node(1), pos: $from.before(1), index: $from.index(0) }
}

/** Move the block holding the selection one place up (-1) or down (+1). */
export function moveBlock(editor: Editor, dir: -1 | 1): boolean {
  const block = currentBlock(editor)
  if (!block) return false
  const { doc } = editor.state
  const target = block.index + dir
  if (target < 0 || target >= doc.childCount) return false
  const { node, pos } = block
  const offset = editor.state.selection.from - pos
  const tr = editor.state.tr.delete(pos, pos + node.nodeSize)
  let insertAt = 0
  for (let i = 0; i < target; i++) insertAt += tr.doc.child(i).nodeSize
  tr.insert(insertAt, node)
  tr.setSelection(TextSelection.near(tr.doc.resolve(Math.min(insertAt + offset, tr.doc.content.size))))
  editor.view.dispatch(tr.scrollIntoView())
  return true
}

/** Turn the current block into another text block type, keeping its id and content. */
export function turnInto(editor: Editor, type: string, attrs: Record<string, unknown> = {}): boolean {
  const block = currentBlock(editor)
  if (!block) return false
  return editor.chain().focus().setNode(type, { id: block.node.attrs.id, ...attrs }).run()
}

function setLevel(editor: Editor, delta: 1 | -1): boolean {
  const block = currentBlock(editor)
  if (!block || !LIST_TYPES.has(block.node.type.name)) return false
  const level = Math.max(0, Math.min(MAX_LEVEL, Number(block.node.attrs.level) + delta))
  if (level === block.node.attrs.level) return true
  editor.view.dispatch(editor.state.tr.setNodeMarkup(block.pos, undefined, { ...block.node.attrs, level }))
  return true
}

/* --------------------------------------------------- list numbering */

const ALPHA = "abcdefghijklmnopqrstuvwxyz"
const ROMAN = ["i", "ii", "iii", "iv", "v", "vi", "vii", "viii", "ix", "x", "xi", "xii"]
const BULLETS = ["•", "◦", "▪"]

function numberLabel(n: number, level: number): string {
  if (level === 1) return `${ALPHA[(n - 1) % 26]}.`
  if (level === 2) return `${ROMAN[(n - 1) % ROMAN.length] ?? n}.`
  return `${n}.`
}

/** The markers of flat list items. Numbering restarts after any other block. */
export function listMarkers(doc: PmNode): Map<number, string> {
  const markers = new Map<number, string>()
  let counters = [0, 0, 0]
  doc.forEach((node, pos) => {
    if (node.type.name !== "listItem" || node.attrs.kind !== "number") {
      counters = [0, 0, 0]
      if (node.type.name === "listItem") markers.set(pos, BULLETS[Number(node.attrs.level)] ?? "•")
      return
    }
    const level = Number(node.attrs.level)
    counters[level] += 1
    for (let l = level + 1; l <= MAX_LEVEL; l++) counters[l] = 0
    markers.set(pos, numberLabel(counters[level], level))
  })
  return markers
}

const listMarkerPlugin = new Plugin({
  key: new PluginKey("listMarkers"),
  props: {
    decorations(state) {
      const decos: Decoration[] = []
      for (const [pos, marker] of listMarkers(state.doc)) {
        const node = state.doc.nodeAt(pos)
        if (node) decos.push(Decoration.node(pos, pos + node.nodeSize, { "data-marker": marker }))
      }
      return DecorationSet.create(state.doc, decos)
    },
  },
})

/**
 * A Markdown block shortcut at the start of a line: like TipTap's
 * textblockTypeInputRule, but the block keeps its id (BlockId is stable).
 */
function blockRule(
  find: RegExp,
  type: NodeType,
  attrs: Record<string, unknown> | ((match: RegExpMatchArray) => Record<string, unknown>) = {}
): InputRule {
  return new InputRule({
    find,
    handler: ({ state, range, match }) => {
      const $start = state.doc.resolve(range.from)
      if (!$start.node(-1).canReplaceWith($start.index(-1), $start.indexAfter(-1), type)) return null
      const extra = typeof attrs === "function" ? attrs(match) : attrs
      state.tr
        .delete(range.from, range.to)
        .setBlockType(range.from, range.from, type, { ...extra, id: $start.parent.attrs.id ?? null })
    },
  })
}

/* ------------------------------------------------------- the extension */

export const DocumentBehaviour = Extension.create({
  name: "documentBehaviour",

  addProseMirrorPlugins() {
    return [listMarkerPlugin]
  },

  addInputRules() {
    const s = this.editor.schema
    const n = s.nodes
    const m = s.marks
    return [
      blockRule(/^#\s$/, n.heading, { level: 1 }),
      blockRule(/^##\s$/, n.heading, { level: 2 }),
      blockRule(/^###\s$/, n.heading, { level: 3 }),
      blockRule(/^[-*]\s$/, n.listItem, { kind: "bullet", level: 0 }),
      blockRule(/^1[.)]\s$/, n.listItem, { kind: "number", level: 0 }),
      blockRule(/^\[ ?\]\s$/, n.checkItem, { level: 0, checked: false }),
      blockRule(/^>\s$/, n.quote),
      blockRule(/^```([a-z0-9-]*)[\s\n]$/, n.codeBlock, (match) => ({ language: match[1] || null })),
      nodeInputRule({ find: /^(?:---|—-|___)$/, type: n.divider }),
      markInputRule({ find: /(?:^|\s)(\*\*(?!\s)([^*]+)(?<!\s)\*\*)$/, type: m.bold }),
      markInputRule({ find: /(?:^|\s)(_(?!\s)([^_]+)(?<!\s)_)$/, type: m.italic }),
      markInputRule({ find: /(?:^|\s)(`(?!\s)([^`]+)(?<!\s)`)$/, type: m.code }),
      markInputRule({ find: /(?:^|\s)(~~(?!\s)([^~]+)(?<!\s)~~)$/, type: m.strike }),
      markInputRule({ find: /(?:^|\s)(==(?!\s)([^=]+)(?<!\s)==)$/, type: m.highlight }),
    ]
  },

  addKeyboardShortcuts() {
    const editor = this.editor
    const toggleMark = (name: string) => () => editor.chain().focus().toggleMark(name).run()
    const listToggle = (type: string, attrs: Record<string, unknown>) => () => {
      const block = currentBlock(editor)
      const on = block?.node.type.name === type && (type !== "listItem" || block.node.attrs.kind === attrs.kind)
      return on ? turnInto(editor, "paragraph") : turnInto(editor, type, { level: 0, ...attrs })
    }
    return {
      // E.28: straight after a Markdown shortcut, ⌘Z turns it back into what was typed.
      "Mod-z": () => editor.commands.undoInputRule() || editor.commands.undo(),
      "Mod-Shift-z": () => editor.commands.redo(),
      "Mod-y": () => editor.commands.redo(),
      "Mod-b": toggleMark("bold"),
      "Mod-i": toggleMark("italic"),
      "Mod-u": toggleMark("underline"),
      "Mod-Shift-x": toggleMark("strike"),
      "Mod-e": toggleMark("code"),
      "Mod-Shift-h": toggleMark("highlight"),
      "Mod-Alt-0": () => turnInto(editor, "paragraph"),
      "Mod-Alt-1": () => turnInto(editor, "heading", { level: 1 }),
      "Mod-Alt-2": () => turnInto(editor, "heading", { level: 2 }),
      "Mod-Alt-3": () => turnInto(editor, "heading", { level: 3 }),
      "Mod-Shift-8": listToggle("listItem", { kind: "bullet" }),
      "Mod-Shift-7": listToggle("listItem", { kind: "number" }),
      "Mod-Shift-9": listToggle("checkItem", { checked: false }),
      "Alt-Shift-ArrowUp": () => moveBlock(editor, -1),
      "Alt-Shift-ArrowDown": () => moveBlock(editor, 1),
      Tab: () => setLevel(editor, 1),
      "Shift-Tab": () => setLevel(editor, -1),
      Enter: () => {
        const block = currentBlock(editor)
        if (!block || !LIST_TYPES.has(block.node.type.name) || !editor.state.selection.empty) return false
        if (block.node.content.size === 0) {
          // An empty item steps out a level, then out of the list.
          if (Number(block.node.attrs.level) > 0) return setLevel(editor, -1)
          return turnInto(editor, "paragraph")
        }
        const { kind, level } = block.node.attrs
        return editor
          .chain()
          .splitBlock()
          .command(({ tr, state }) => {
            const pos = state.selection.$from.before(1)
            const type = block.node.type
            tr.setNodeMarkup(pos, type, { ...type.defaultAttrs, kind, level, id: null })
            return true
          })
          .run()
      },
      Backspace: () => {
        const { selection } = editor.state
        const block = currentBlock(editor)
        if (!block || !selection.empty || selection.$from.parentOffset !== 0) return false
        const name = block.node.type.name
        // At the start of a list item, heading, quote or code block, Backspace makes it text first.
        if (LIST_TYPES.has(name) || name === "heading" || name === "quote" || name === "codeBlock") {
          return turnInto(editor, "paragraph")
        }
        return false
      },
    }
  },
})
