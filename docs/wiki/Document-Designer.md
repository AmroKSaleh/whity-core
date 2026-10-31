# Document & Label Designer (removed)

The old canvas designer at `/admin/documents` was replaced by the **document
editor** in 0.2.20 (editor milestone M4c). Templates and label sheets are now
written in the editor, listed on **Document templates** (`/admin/document-templates`),
published (E.9), and printed through [Whity Render](Document-Render-Service.md)'s
flowing and label modes. The design is in `docs/design/` (section 18, the
decision register, `handoff/editor/`).

## What the upgrade does

Migration `164_issue_documents_from_sources_and_drop_designer` runs once, for
every workspace, when you upgrade:

1. Each workspace gets the editor's starters (Letter, Invoice, Exam sheet,
   Production note, Shipping labels, Certificate), if it doesn't have them.
2. Each old template a person made or edited is copied into an editor
   **draft** with the same title, audience (scope, permission, unit) and
   owner. The old designer's own starters are not copied unless someone
   edited them.
   - A canvas is converted in **reading order**: page by page, top to bottom,
     start to end. Text becomes headings and paragraphs, barcodes, QR codes,
     maths and images become their blocks, a horizontal line becomes a divider.
   - A **saved block** used in a template is placed into it where it sat.
   - **Placeholders** (`{{ship_to}}`) become the template's own fields
     (`template.ship_to`), with their labels and samples.
   - **Label stock**, or a page the size of a label, becomes a label sheet.
   - What has no block (rectangles, vertical lines, a saved block that no
     longer exists, the running header and footer) is left out, and the copy
     starts with a note naming it, in the template's language. Check the
     layout, delete the note, then publish.
3. The `document_templates` and `document_blocks` tables are dropped.

If any template can't be converted, the migration stops **before** dropping
anything and names the template, so nothing is lost; fix it and run the
migration again. A re-run converts only what is new.

## Documents issued from old templates

They keep their PDFs, verification codes, routes and trail. They can't be
re-rendered: `POST /api/documents/{id}/render` answers 409 and names the
converted template (`details.document_source_id`); issue a new document from
it instead. Documents issued from editor templates record the template and
published version, and a correction re-renders exactly that version.

## The verification QR

It is automatic (register 6.7): Whity Render prints it at the end of each
document issued while the workspace setting `documents.qr_enabled` is on. The
old per-template switch and the placeable QR element went with the designer.
