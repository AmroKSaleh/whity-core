# Document designer (A33)

whity-core's `/admin/documents` editor, drawn from the editor parts: a template opens in the page view (E.7) with its letterhead and merge fields, the outline, the margin rail and the status bar. The header says what's in use ("Draft saved 14:02 · published version 3 is in use").

**Publish** (E.9) opens a check first. It lists what blocks publishing (E.37, `PublishBlocker` in `handoff/editor/whity-document.ts`): open suggestions, a block whose plugin is off (E.19), a block without a print view (E.18), or a field the record type doesn't have. Each line has a way to fix it, and Publish stays disabled until the list is empty. Publishing makes the draft the version Whity Render and agents use; the history keeps every published version.

Opening a template from Document templates (A31) or a record's "Edit template" lands here; Back returns there.
