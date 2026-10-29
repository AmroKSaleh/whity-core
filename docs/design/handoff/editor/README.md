# Document editor: engineering handoff

The design system defines the editor (decisions E.1–E.43 in `00-decision-register.md`, cards in the **Document editor** group, section 18). The Whity team builds it in whity-core for the Document designer (A33, `/admin/documents`) and every other place documents are written.

`whity-document.ts` is the contract: the document model, inline marks, block types, comments and suggestions, the block registry for plugins, saved blocks, and the editor's props and events. Whity Render, the MCP tools agents use and plugins all speak it.

## Suggested stack

TipTap 2 (ProseMirror) inside `packages/ui`, styled with the tokens. ProseMirror gives the schema, transactions, collaboration-ready steps and decorations that this design needs; TipTap gives the extension model plugins will use.

| Design part | TipTap / ProseMirror | Notes |
| --- | --- | --- |
| Blocks (E.13) | Nodes: `paragraph`, `heading` (1–3), `blockquote`, `codeBlock`, `bulletItem`/`numberItem`/`checkItem` as flat nodes with a `level` attr, `callout`, `toggle` (content `block+`), `columns` → `column` ×2, `horizontalRule`, `pageBreak`, `table` (tiptap table, header row attr), `image`, `file`, `record`, `chart`, `signature`, `barcode`, `qrCode`, `mathBlock`, `savedBlock`, `pluginBlock` | Flat lists (Notion style) make drag-and-drop and Turn into simple; render numbering in a decoration |
| Marks (part 4) | `bold`, `italic`, `underline`, `strike`, `code`, `link`, `highlight`, `comment`, `insertion`, `deletion`, `perDocument` | `bold` renders at 600 |
| Barcode, QR code, Maths (E.40–E.42) | Atom nodes `barcode` and `qrCode` whose value is text or a `field`; `mathBlock` and an inline atom `mathInline` holding TeX | Draw barcodes with bwip-js and formulas with KaTeX, both already used by Whity Render, so screen and print match |
| Label sheets (E.43) | `kind: 'label'` documents: the page view repeats the document's blocks in each cell of `labels` (a grid decoration); the continuous view is off | Print sends a `LabelPrintSource` (records or a CSV) and Whity Render fills one label per record or row |
| Merge fields (E.26) | Inline atom node `field` with `path`, `label`, `required` | Node view: the chip; in preview a decoration swaps in the value |
| Block chrome (E.8) | A drag-handle plugin (gutter + and handle) and a node-view toolbar for rich blocks | The gutter sits in the sheet padding or the page margin |
| / menu and + (part 5) | `@tiptap/suggestion` with the block registry as items, grouped (Whity, Saved blocks, one group per plugin, Ask an agent) | The same list feeds the + inserter |
| Markdown shortcuts (E.28) | Input rules, undoable with one ⌘Z (`undoInputRule`) | |
| Bubble (part 4) | `BubbleMenu` | Rewrite calls `onAskAgent` with `kind: 'rewrite'` |
| Suggesting mode (E.10) | A transaction filter that turns inserts into `insertion` marks and deletes into `deletion` marks (and block inserts into `suggestion` attrs) | Accept: remove the deletion range, drop the insertion mark; Reject: the reverse |
| Comments (part 6) | `comment` mark + thread store; rail positions from `coordsAtPos` | Rail cards stack without overlap; drawer under 1024px; the warning-tint highlight is a decoration, never printed (E.39) |
| Agents (part 7) | `onAskAgent` streams `AgentResult`; results are inserted already marked as suggestions | Agents never write without a suggestion mark (E.4) |
| Page view (E.7, E.11) | A pagination decoration that measures blocks and adds page gaps, letterhead and footer; pages stay white in dark mode | Whity Render paginates the final PDF |
| Plugin blocks (E.17–E.20) | `pluginBlock` node; node view mounts the plugin's native component (verified) or a sandboxed iframe that talks over `postMessage` | Validate `data` against the plugin's JSON Schema before saving |
| Saved blocks (E.21–E.24) | `savedBlock` node; synced ones render the library version with `overrides` for per-document parts; Detach replaces the node with its blocks | Published templates pick up library changes at their next publish |
| Collaboration (later, E.3) | Yjs + `@tiptap/extension-collaboration` | The model above already keys everything by stable ids |

## Rules the build must keep

- Nothing an agent writes lands without a person accepting it; every suggestion and decision carries the actor, the person it acts for and the permission used (`Suggestion.permission`), and a routine when one started it (E.4, E.29, E.32); all of it goes to the audit log.
- People without edit permission are in Suggesting (E.10).
- Templates publish only with no open suggestions, no missing plugin, no block without a print view and no unknown field (E.37, `PublishBlocker`). A published template picks up synced block changes at its next publish (E.38).
- ⌘K adds a link only when text is selected; otherwise it opens the command palette (E.36).
- Record and Chart blocks read within the reader's permissions; Whity Render freezes their values in each PDF (E.16).
- Every string has an English default and an Arabic label; each block takes its direction from its own text; Latin inside Arabic is isolated.
- Type: 16px body on screen, stage 6 print sizes in the page view, Readex Pro only for the title (its Arabic design for Arabic titles, E.35), no 700 (E.33).
