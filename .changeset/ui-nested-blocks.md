---
"@amroksaleh/ui": minor
---

Blocks may contain blocks. `flattenBlock` expands a block to leaves, resolving
nested `blockInstance` pointers; `wouldCycle` answers whether an insert would
make a block contain itself; `blockChildIds` lists what a block points at
directly. `BlockInstanceContent` gains an optional `blocks` prop.

Everything is additive and nothing changes without it. `BlockInstanceContent`
without `blocks` resolves a nested instance to nothing, which is exactly what it
did before nesting existed — so an existing consumer is no worse off, and one
that passes the library is strictly better.

`makeBlockFromElements` no longer drops block instances from a selection. That
is a behaviour change and it is the point: it used to filter them out and
report success, so saving a letterhead together with its logo produced a block
with the logo missing. A caller relying on the old filtering was relying on
losing part of the selection.

RESOLUTION RETURNS DIAGNOSTICS, NOT JUST ELEMENTS. Three things stop an
expansion — the block is gone, it is an ancestor of itself, or nesting exceeds
`MAX_BLOCK_DEPTH` — and all three look the same on the page: nothing is drawn.
Elements alone cannot tell "this block is empty" from "this block is broken",
which is how a hole reaches a printed document with nobody the wiser. So
`flattenBlock` hands back what it could not draw, and the caller decides whether
to say so: the designer shows a marker, print stays silent, matching the rule
the unresolved-block marker already follows.

A cycle is cut rather than refused. A block that contains itself is malformed in
one branch of a tree whose other branches are fine — the parts that resolve
still render, because turning one bad pointer into a blank page helps nobody.
