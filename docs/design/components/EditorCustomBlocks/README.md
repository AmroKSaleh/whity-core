# Plugin and saved blocks

Two ways the block catalogue grows beyond Whity's own blocks.

## Plugin blocks (E.17–E.20)

A plugin registers a block with a manifest: name, icon, group (the plugin's name), the permissions it reads (`resource:action`, granted when the plugin is installed), its fields, an editor view and, to be used where documents print, a **print view** for Whity Render.

- **Verified and first-party plugins** render natively with the Whity SDK components; **others** run in a sandboxed frame with the same UI kit, sized by the editor, talking to the document only through the block API.
- Selecting a plugin block shows its **source label** above it: the plugin's logo and name, Verified or Sandboxed, and Prints or Notes only.
- **No print view:** the block is offered in notes and wiki pages only, never in templates (E.18).
- **Plugin off or uninstalled:** a placeholder ("Needs the Delivery Tracker plugin", Enable for admins); the block's data stays in the document and a template containing it can't be published (E.19).
- **Inserting:** each plugin gets its own group in the / menu and + inserter, with its logo, after Whity's blocks (E.20). Agents can insert plugin blocks only as suggestions, within the plugin's and their own permissions.

## Saved blocks (E.21–E.24)

Select several blocks (shift-click or drag across the gutter) and **Save as block**: a name, **Synced** or **Copy**, and **Only me** or **Everyone in the workspace**. Saved blocks appear in the / menu under Saved blocks.

- **Synced:** a light outline in the main colour and a label ("Bank details · Synced · used in 12 documents") with Edit original and Detach. Editing the original updates drafts, notes and wiki pages at once; a published template picks up the change at its next publish, so what prints never changes without a person publishing it (E.38).
- **Per document:** in the original, text or fields can be marked "filled per document" (dotted outline); each document fills its own value while the rest stays synced. Merge fields work inside.
- **Copy:** inserts ordinary blocks with no link.
- Owners and admins manage the workspace library (rename, delete, see where a synced block is used). Exporting a saved block as a plugin comes later (E.24).
