# @whity-org/ui

## 0.5.0

### Minor Changes

- 15716b3: Add `@whity-org/features/datetime`: `DateDisplayProvider` and
  `useDateDisplay()`, the one path a date takes to a screen. The hook carries both
  things a call site should never have to remember — the reader's resolved
  language, and whether this tenant has asked for dates to be off the screen
  entirely (`ui.hide_dates`, #1068). It exposes `date`, `dateTime`, `age`,
  `relative` and `dateColumns`, all of which return nothing when dates are hidden.
  
  **Breaking (`@whity-org/features`).** `formatRecordDate` and
  `formatRecordDateTime` are no longer exported from `@whity-org/features/record`,
  and the `./record/format` subpath is gone. They were one of six ways a date
  reached a screen, and a gated one of six is a setting that is 90% true. Use
  `useDateDisplay()` instead; it needs no locale argument, which is what eight of
  the twenty former call sites had forgotten to pass.
  
  Two behaviours changed in the consolidation. A wire timestamp is now read as the
  UTC instant the server meant — PostgreSQL returns `2026-08-25 14:02:11` with no
  offset, which `new Date()` reads in the *local* zone — and the formatters return
  `null` rather than the raw wire string for a value they cannot parse, because
  `formatter(x) ?? x` prints the timestamp the formatter has just declined to.
  
  **Breaking (`@whity-org/ui`).** `PluginVersion.releasedAt` becomes
  `releasedLabel`, and is optional. It was always display text in practice
  ("Current (Installed)", "2 weeks ago") printed verbatim, and a name that says
  "timestamp" over a field that is really a label is how an unformatted
  `2026-08-25 14:02:11` reaches a reader. This package cannot format a date — it
  has access to neither the reader's language nor the tenant preference — so the
  caller formats and omits.
  
  `RegistrySettingControl` now renders its inherited/overridden badge on `bool`
  controls too, when a caller passes `status`.
- 253d86e: A BLOCK SETTINGS view in document mode: the spacing around the selected block,
  how it behaves at a page boundary, and how wide it is.
  
  `@whity-org/ui` gains `FlowBlockLayout` (six optional keys on the four block
  types that have a box), `FlowBoxedBlock`, `blockAcceptsLayout` and the two
  bounds the renderer validates. `@whity-org/features` gains `FlowBlockSettings`,
  and `SideRail` gains an optional `blockSettings` slot.
  
  Document mode edits a block AS ITSELF — a heading is a line at heading size, a
  paragraph is a text area — which is most of why it reads like writing. That left
  nowhere for the things that are not the content. The panel goes in the rail's
  existing properties slot rather than a new tab, because "the properties of what
  I have selected" is the question that slot already answers on the canvas; a
  seventh tab would sit in the strip doing nothing whenever the canvas was open.
  
  `blockAcceptsLayout` is a TYPE PREDICATE, not a boolean. Written as a boolean it
  compiled at the call site and TypeScript then refused every property access
  after it — which was the check working: the renderer refuses layout keys on
  `pageBreak` and `spacer`, and a UI able to reach them would be offering settings
  the printer rejects.
  
  Every control is read by the renderer, and the bounds are the ones it validates,
  so an author cannot compose a document that is then refused. Defaults are stored
  as ABSENT rather than as a value meaning the same thing.
- 3a5e2d4: New `CopyBlock`: text the user is meant to copy somewhere else (a command, a
  config file, a token) with a copy button. It renders as left-to-right monospace
  code even in an RTL layout, can show a masked `display` while copying the real
  `value`, takes a `caption` (a file path, a field name) and translated
  `copyLabel` / `copiedLabel`, and reports each copy through `onCopy` so the
  consumer can toast a failure. Also in the shadcn registry as `copy-block`.
- 8d535d6: `DataTable` gains `sorting` and `search` props, the two halves of server-side
  list handling it was missing. Both are optional and nothing changes without
  them: sorting and global search stay client-side, which is what every existing
  caller is using.
  
  The table has supported server PAGINATION for a while but always sorted and
  filtered the rows itself, and those two facts do not compose. `getSortedRowModel`
  sorts the rows it was handed — the twenty five on screen — so clicking a header
  reorders one page and presents the result as a sorted list; page 2 then re-sorts
  a different twenty five. A global filter over the same table reports "no
  results" for a term whose match is sitting on page 4. Neither throws and neither
  looks wrong, so the only way to notice is to already know.
  
  Screens had started routing around it. The document library made its columns
  non-sortable on purpose and moved sort into its own toolbar, with a comment
  explaining that the alternative was shipping the untruth; that workaround is now
  a choice rather than the only option.
  
  `search` debounces before calling back — typing "engineering" is otherwise
  twelve requests — with the delay overridable per table via `debounceMs` and the
  default exported as `DATA_TABLE_SEARCH_DEBOUNCE_MS`. The input keeps its own
  draft so typing stays responsive while a request is in flight, and adopts an
  external change to `value` (a "clear search" control elsewhere on the page)
  without clobbering characters typed in the meantime.
  
  Per-column filters are unaffected in both modes. `manualFiltering` would have
  been the obvious way to stop the component filtering by the search term, but
  table-core has a single flag covering global AND column filtering, so setting it
  would have silently disabled the per-column inputs that around eighteen admin
  columns rely on. Keeping the term out of `state.globalFilter` does the same job
  with no collateral.
- b63f22a: Document mode's `figure` block can now be given an image.
  
  It could not before, and that made **Insert ▸ Image a dead command**.
  `newFlowBlock('figure')` starts as a 1×1 transparent PNG — the only honest
  empty state, since the flowing renderer refuses a remote source and an empty
  string would be a block that exists and cannot print — and nothing anywhere let
  the author replace it. The command appeared to work and put an invisible dot on
  the page.
  
  `@whity-org/ui` gains `judgeFigureFile`, `FIGURE_MIME_TYPES` and
  `DEFAULT_MAX_FIGURE_BYTES`; `FlowEditor` gains optional `maxFigureBytes` and
  `onError`. Both props are optional, so existing callers are unaffected — though
  a caller that omits `onError` gets a picker that refuses silently, which is why
  the designer wires it to a toast.
  
  **SVG is refused, and that is not an oversight about vectors.** An SVG can carry
  script, and this value becomes a `data:` URI rendered both in the browser and in
  headless Chromium; `element-content` already refuses script-carrying SVG data
  URIs on the canvas side. `accept` on the input is a convenience — the file
  dialog's "All files" option defeats it — so the JS check is the actual guard.
  
  The size cap is a client-side pre-flight, not the rule: the server caps a whole
  template at `documents.render_max_template_bytes` and the render service caps
  the payload again. It exists so somebody is told "that image is too big" while
  choosing it, rather than having a later save refused by a limit that names bytes
  instead of the picture they just picked. The default sits well under the server's
  because base64 inflates by about a third, and a client guard set equal to the
  server's would pass files the encoded template then fails on.
- 457921e: Blocks may contain blocks. `flattenBlock` expands a block to leaves, resolving
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
- 0af5caf: Document mode's `table` block can be reshaped: `addTableRow`, `removeTableRow`,
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

### Patch Changes

- 52997e6: `DataTable`'s sortable column headers are no longer mouse-only. The header cell
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

## 0.4.0

### Minor Changes

- b8ed390: Add two primitives for the offline-sync UI: `LockedScreen` (a full-page locked
  state, sibling of `AccessDenied`, for an offline-TTL-expired session) and
  `RadioGroup` / `RadioGroupItem` (accessible single-select on Radix, used by the
  field-level conflict resolver's per-field picker). Additive — new subpath exports
  (`@whity-org/ui/locked-screen`, `@whity-org/ui/radio-group`) + barrel re-exports.

## 0.3.1

### Patch Changes

- `globals.css` now sources its theme tokens from the new `@whity-org/tokens` package instead of a hand-maintained, manually-synced block — no visible behavior change, but removes a duplication risk between this package and `web/app/globals.css`. Also adds a CI guard that fails the build if a Next.js-only import ever appears in this package (it must stay usable from a plain Vite/Tauri SPA).

## 0.3.0

### Minor Changes

- Add a shared `AccessDenied` component (a full-page permission-denied card: icon, title, description, optional action) — unifies markup that was previously hand-copied across every admin page gating a whole route on a permission.

## 0.2.0

### Minor Changes

- Add a real, interactive `DataTable` (and its `Table` primitives), built on `@tanstack/react-table`: multi-column sort, per-column and global filtering, client- or server-driven pagination, column visibility toggling, column resizing, and custom cell renderers. Replaces the three previously divergent, hand-rolled table implementations across the app.
