import { Extension } from "@tiptap/core"
import type { Node as PmNode } from "@tiptap/pm/model"
import type { EditorState } from "@tiptap/pm/state"
import { TextSelection } from "@tiptap/pm/state"

/** The cell the caret is in: its table, row and column. */
function cellAt(state: EditorState) {
  const { $from } = state.selection
  for (let d = $from.depth; d > 2; d--) {
    if ($from.node(d).type.name !== "tableCell") continue
    return { table: $from.node(d - 2), tablePos: $from.before(d - 2), row: $from.index(d - 2), col: $from.index(d - 1) }
  }
  return null
}

/** Position just inside cell (row, col) of the table at tablePos. */
function cellStart(doc: PmNode, tablePos: number, row: number, col: number) {
  const table = doc.nodeAt(tablePos)
  if (!table) return null
  let pos = tablePos + 1
  for (let r = 0; r < row; r++) pos += table.child(r).nodeSize
  pos += 1
  const tr = table.child(row)
  for (let c = 0; c < col; c++) pos += tr.child(c).nodeSize
  return pos + 1
}

/**
 * Tab and Shift-Tab move between table cells (E.8); Tab on the last cell adds
 * a row. Outside a table they fall through to the list indent keys.
 */
export const TableKeys = Extension.create({
  name: "tableKeys",
  priority: 200,
  addKeyboardShortcuts() {
    const move = (dir: 1 | -1) => () => {
      const { state, view } = this.editor
      const at = cellAt(state)
      if (!at) return false
      const width = at.table.child(at.row).childCount
      let row = at.row
      let col = at.col + dir
      if (col >= width) {
        row += 1
        col = 0
      } else if (col < 0) {
        row -= 1
        col = row >= 0 ? at.table.child(row).childCount - 1 : 0
      }
      if (row < 0) return true
      let tr = state.tr
      if (row >= at.table.childCount) {
        const { tableRow, tableCell } = state.schema.nodes
        const blank = tableRow.create(null, Array.from({ length: width }, () => tableCell.create()))
        tr = tr.insert(at.tablePos + at.table.nodeSize - 1, blank)
      }
      const pos = cellStart(tr.doc, at.tablePos, row, col)
      if (pos === null) return true
      const cell = tr.doc.nodeAt(pos - 1)
      tr.setSelection(TextSelection.create(tr.doc, pos, pos + (cell?.content.size ?? 0)))
      view.dispatch(tr.scrollIntoView())
      return true
    }
    return { Tab: move(1), "Shift-Tab": move(-1) }
  },
})
