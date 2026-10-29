# DataTable

Rows of records: a muted header row, 44px rows with hairline dividers, numbers end-aligned in tabular figures.

**Props:** `columns` (`[{key, label, align?, width?, render?, sortable?, sortValue?}]`), `rows` (each with an `id`), `footer` (usually `Pagination`), and the stage 7 behaviour:
- `selectable`, `defaultSelected`, `onSelect`: a checkbox column; the header checkbox selects all and shows a mixed state when some are selected; selected rows are tinted.
- `bulkActions` (nodes, or a function of the selected rows) and `selectionSum` (a function returning the money total): the dark bulk bar that rises at the foot (7.6).
- `defaultSort`; sortable headers toggle ascending and descending and set `aria-sort`.
- `rowMenu` (true for a `more` button, or a function of the row returning a `Menu`), `onRowClick` (opens the record, usually in a `Drawer`).
- `totals` (`{ [key]: node }`) and `totalsLabel`: the totals row for money columns.
- `state`: `'loading'` (skeleton rows), `'error'` (with `errorText`, `onRetry`); an empty `rows` shows `empty` or a default empty state.
- `sticky` and `maxHeight` for a sticky header; `labels` for Arabic.

See also Data table, selection and Data table, states.
