# @whity-org/features

## 0.3.0

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
- 8038ea4: `FlowEditor` can now set the four block properties the render service already
  honoured and nothing could reach: `heading.unnumbered`, `heading.inContents`,
  `paragraph.align` and `table.caption`.
  
  Each is read by `render-service/src/flow` — numbering and the contents front
  matter in `document.js`, the alignment class and the table caption in `html.js`
  — and each was declared in the model with no control anywhere. The features
  existed, shipped, and were unreachable. `table.caption` is the sharpest case:
  `flowToCanvas` READS it to represent a table when a document is switched to
  canvas mode, so a converted table always fell back to its "N x M" summary,
  because nothing could ever set one.
  
  THE HEADING PAIR SHOWS ITS DEPENDENCY. The renderer lists a heading only when
  `inContents !== false && !unnumbered`, so an unnumbered heading is never listed
  whatever the other setting says. The contents box is disabled and unchecked for
  an unnumbered heading rather than left live — a control that silently does
  nothing is the same failure as a control that is missing, arrived at from the
  other side.
  
  Defaults are stored as ABSENT rather than as a value meaning the same thing:
  turning numbering back on clears `unnumbered` instead of writing `false`,
  "aligned to the start" clears `align`, and an emptied caption clears rather than
  storing `''`. The renderer branches on presence, so the two are identical to it
  and only one of them adds a key every future reader has to learn is redundant.
  
  Alignment is logical, not left/right: `flow-align-end` is the end of the reading
  direction, which stays correct in Arabic.
- 3a5e2d4: New `@whity-org/features/mcp`: how each AI app connects to a Whity MCP server.
  `MCP_CLIENTS` lists Claude Code, Cursor, VS Code, Windsurf, Codex CLI, Gemini
  CLI, n8n and a generic "other", plus Claude and ChatGPT marked
  `requiresSignIn` until OAuth sign-in lands. Each entry's `setup({ name, url,
  token })` returns what that app reads: an install link (Cursor, VS Code), a
  command (`claude mcp add`, `gemini mcp add`), a config file with its paths
  (JSON or TOML), or the form fields to fill in (n8n). It holds no user-facing
  prose, so every client can wrap the same setup in its own translated steps.
  Install links not confirmed by the vendor for a remote server carry
  `verified: false`; show the copy-paste setup beside them.
- 6ba74e5: Tool search helpers in `@whity-org/features/mcp` (WHIT-707):
  - `recommendsToolSearch(client, toolCount)` says whether an app should connect through tool search, given how many tools the chosen access exposes. It uses each app's reported `toolLimit` (Cursor 40, Windsurf 100) and the general `MCP_TOOL_BUDGET` of 60.
  - `mcpEndpoint(endpoint, { toolSearch })` adds `?tools=discover` to the server's MCP URL, keeping any query it already has.
  
  In tool search the server offers three tools (`search_tools`, `describe_tool` and `call_tool`) in place of the full catalogue.
- f2308d0: The document designer gains **Save as a copy** (File menu and `/` palette):
  save the open document as a new personal template, leaving the original
  untouched.
  
  This closes a hole that was a safety problem rather than a missing convenience.
  `Save` updates in place whenever the editor has a loaded template, and an update
  deliberately leaves the stored scope alone — so opening a **tenant-wide**
  template, changing something, and pressing Save rewrote the template the whole
  tenant uses, with nothing on screen saying that was what Save meant here. There
  was no way to keep an experiment that did not also change everyone else's
  document.
  
  The copy creates rather than updates, is filed as **personal** whatever the
  original was, and the editor then follows the copy — that last part matters,
  because leaving the editor pointed at the original would put the next Ctrl+S
  straight back into the bug this exists to prevent.
  
  **`EditorCommandContext` gains a required `onSaveAsCopy`.** Anyone building the
  menus themselves (`buildEditorMenus`) must supply it. That is a required field
  on an exported interface, so an external consumer constructing this context
  breaks at compile time; it is marked `minor` because both consumers are in this
  repository and the package is pre-1.0, and it is called out here rather than
  buried because the type error is the only warning anybody else would get.
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

- Updated dependencies [15716b3]
- Updated dependencies [253d86e]
- Updated dependencies [3a5e2d4]
- Updated dependencies [8d535d6]
- Updated dependencies [52997e6]
- Updated dependencies [b63f22a]
- Updated dependencies [457921e]
- Updated dependencies [0af5caf]
  - @whity-org/ui@0.5.0

## 0.2.0

### Minor Changes

- 783fba4: Add the offline-first sync contract at `@whity-org/features/sync`: the
  `SyncStatus` / `Conflict` / `FieldConflict` / `Resolution` / `SyncController`
  types, the `useSyncStatus` hook (over `useSyncExternalStore`, with the
  referential-stability contract documented), and `createAlwaysSyncedController`
  for online-only clients (web, the SPA harness) so shared sync UI degrades to a
  no-op without client special-casing. Additive — existing exports unchanged.
- 7c8dac5: Add the shared sync UI to `@whity-org/features/sync`: `UnsyncedBanner` (an
  app-wide status strip composing `Alert` that self-hides when fully synced) and
  `ConflictResolver` (a field-level mine/theirs/custom picker with a live merged
  preview, bidi-safe via `dir="auto"` on user content). Presentational, driven by
  the injected `SyncStatus` / `Conflict` — online-only clients render nothing.

### Patch Changes

- Updated dependencies [b8ed390]
  - @whity-org/ui@0.4.0

## 0.1.0

### Minor Changes

- Initial release: the multi-client feature-extraction pilot.
  - App-shell nav contract (`@whity-org/features/nav`): `NavConfig`/`NavItemConfig`/`NavGroupConfig` plain-data types, an injectable `NavLinkAdapter`, and `resolveNavGroups()` bridging a client-authored config into `AppSidebar`'s `AppSidebarNavGroup[]` shape (label translation via an optional injected `t()`, active-route matching against the caller's current path). Ships `exampleNavConfig` as a working reference.
  - `DemoCatalogList` / `DemoCatalogDetail` (`@whity-org/features/demo-catalog`): the pilot feature itself — a small, deliberately generic list/detail screen pair with zero Next.js dependency and zero direct data fetching. All data access goes through an injected `DemoCatalogAdapter` (`list`/`get`/`save`); `web/` wires it to the `DemoCatalog` plugin's REST API via its own api-client, `packages/spa-harness` wires it to an in-memory store — proving the same components render unmodified under both.
  - Zero-Next-deps guard added to CI (`.github/workflows/publish-features.yml`), mirroring `packages/ui`'s guard.
