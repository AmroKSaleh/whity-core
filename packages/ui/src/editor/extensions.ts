import type { AnyExtension } from "@tiptap/core"
import { Gapcursor, Placeholder, UndoRedo } from "@tiptap/extensions"

import { BlockIds } from "./block-ids"
import { DocumentBehaviour } from "./behaviour"
import { richNodeViews } from "./node-views"
import { CheckItem, schemaExtensions } from "./schema"
import { TableKeys } from "./table-keys"

/** The checklist box: a real checkbox button, outside the editable text. */
const CheckItemWithBox = CheckItem.extend({
  addNodeView() {
    return ({ node: initial, getPos, editor }) => {
      let node = initial
      const dom = document.createElement("div")
      const box = document.createElement("button")
      const contentDOM = document.createElement("div")
      box.type = "button"
      box.contentEditable = "false"
      box.setAttribute("role", "checkbox")
      box.className = "wy-check"
      contentDOM.className = "wy-check-text"
      dom.append(box, contentDOM)

      const paint = () => {
        dom.dataset.type = "check-item"
        dom.dataset.level = String(node.attrs.level)
        dom.dataset.checked = node.attrs.checked ? "true" : "false"
        if (node.attrs.id) dom.dataset.blockId = node.attrs.id
        box.setAttribute("aria-checked", node.attrs.checked ? "true" : "false")
        box.setAttribute("aria-label", node.textContent || "Checklist item")
      }
      paint()

      box.addEventListener("mousedown", (e) => e.preventDefault())
      box.addEventListener("click", () => {
        if (!editor.isEditable) return
        const pos = typeof getPos === "function" ? getPos() : undefined
        if (pos === undefined) return
        editor.view.dispatch(editor.state.tr.setNodeMarkup(pos, undefined, { ...node.attrs, checked: !node.attrs.checked }))
      })

      return {
        dom,
        contentDOM,
        update(next) {
          if (next.type !== node.type) return false
          node = next
          paint()
          return true
        },
        ignoreMutation: (m) => m.target === box || box.contains(m.target as globalThis.Node),
      }
    }
  },
})

export interface DocumentExtensionOptions {
  /** The focused empty line's hint (EditorInsert card). */
  placeholder?: string
}

/** Everything the document editor runs on, in one list. */
export function documentExtensions({ placeholder }: DocumentExtensionOptions = {}): AnyExtension[] {
  return [
    ...schemaExtensions.map((ext) => {
      if (ext.name === "checkItem") return CheckItemWithBox
      // M3 blocks get their editor views (node-views.tsx).
      return richNodeViews.find((view) => view.name === ext.name) ?? ext
    }),
    Gapcursor,
    TableKeys,
    BlockIds,
    DocumentBehaviour,
    UndoRedo.configure({ depth: 200 }),
    Placeholder.configure({
      placeholder: placeholder ?? "Type / for blocks, {{ for fields, or just write",
      showOnlyCurrent: true,
      includeChildren: false,
      emptyNodeClass: "is-empty",
    }),
  ]
}
