---
"@amroksaleh/ui": minor
"@amroksaleh/features": minor
---

Document mode's `table` block can be reshaped: `addTableRow`, `removeTableRow`,
`addTableColumn`, `removeTableColumn` and `tableColumnCount`, with controls in
`FlowEditor`.

A table was frozen at the shape `newFlowBlock` created — two columns, one row,
forever. Every cell was editable, which is what made the limit costly: the block
looked like a working table and only revealed it could not grow when somebody
had a third row to enter.

THE INVARIANT THESE KEEP is that a table stays rectangular — every row holds
exactly as many cells as there are columns. It is easy to add a column by
pushing one heading and forgetting the body, and the result validates (the
renderer only checks that rows are arrays), then prints with the last column
missing from every row, which reads as a styling bug rather than as lost data.
So they operate on the whole block rather than on one list, and the tests assert
rectangularity after every operation and across a long mixed sequence.

Removing the last row or column is refused. A table with no rows prints as a
header with nothing under it, or with no header as nothing at all — an
invisible entry in the document's reading order. The control is disabled rather
than hidden, because a control that vanishes reads as a bug.
