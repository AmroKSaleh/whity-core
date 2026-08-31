---
"@amroksaleh/ui": patch
---

`DataTable`'s sortable column headers are no longer mouse-only. The header cell
renders a `<button>` inside the `th`, and the `th` carries
`aria-sort="ascending" | "descending" | "none"`.

Before this, a sortable header was a `<th onClick>`. A `th` takes no keyboard
focus and has no activation behaviour, so **a keyboard user could not sort any
table this component renders** — and with no `aria-sort`, a screen-reader user
was never told which column the rows were ordered by, or in which direction.

That was a known, deliberately deferred trade-off while sorting only reordered
rows already in the browser. `DataTableServerSorting` changed the stakes: the
header is now the only route to rows on OTHER pages, so on a 400-row table a
keyboard user who cannot sort cannot reach row 300 by any means the UI offers.

`aria-sort` is read off the resolved sorting state, so it is correct in both
modes — the caller's sort in server mode, this component's in client mode.

Nothing in the props contract changes and no caller needs edits. The whole cell
stays the click target: the sort handler stays on the `th` and the button
carries no handler of its own, so one activation — mouse, Enter or Space — is
one toggle, and the third one still returns to unsorted. The button takes no
`aria-label`, because a `columnheader`'s accessible name is computed from its
contents and naming the button would rename the cell for every
`getByRole('columnheader', { name })` selector aimed at it.
