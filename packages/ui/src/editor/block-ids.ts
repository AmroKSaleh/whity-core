import { Extension } from "@tiptap/core"
import { Plugin, PluginKey, type Transaction } from "@tiptap/pm/state"

/** A short random id, unique within a document (BlockId in the contract). */
export function newBlockId(): string {
  const bytes = new Uint8Array(9)
  crypto.getRandomValues(bytes)
  return Array.from(bytes, (b) => "abcdefghijklmnopqrstuvwxyz0123456789"[b % 36]).join("")
}

/**
 * Every block, nested ones included, keeps a stable, unique `id`: a new block (typed,
 * split, pasted or duplicated) gets a fresh one, and a duplicate of an
 * existing id is renamed, the first occurrence keeping it.
 */
export const BlockIds = Extension.create({
  name: "blockIds",
  addProseMirrorPlugins() {
    return [
      new Plugin({
        key: new PluginKey("blockIds"),
        appendTransaction: (transactions, _old, state) => {
          if (!transactions.some((t) => t.docChanged)) return null
          const seen = new Set<string>()
          let tr: Transaction | null = null
          // Every block, including those inside toggles and columns, has a document-wide unique id.
          state.doc.descendants((node, pos) => {
            if (!("id" in node.attrs)) return
            const id = node.attrs.id as string | null
            if (id && !seen.has(id)) {
              seen.add(id)
              return
            }
            const fresh = newBlockId()
            seen.add(fresh)
            tr = (tr ?? state.tr).setNodeMarkup(pos, undefined, { ...node.attrs, id: fresh })
          })
          // Id bookkeeping isn't an edit: it never becomes its own undo step.
          return tr ? (tr as Transaction).setMeta("addToHistory", false) : null
        },
      }),
    ]
  },
})
