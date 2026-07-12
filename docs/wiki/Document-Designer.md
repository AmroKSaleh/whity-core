# Document & Label Designer

A client-side WYSIWYG designer for printable **documents and labels** — invoices,
exam sheets, production notes, shipping/device-serial labels, and more. Route:
`/admin/documents`. Code lives in `web/components/documents/*` and
`web/lib/documents/*`.

It is print-accurate (millimetre layout), supports **multi-page** documents,
**variable-data batch** printing (serialised labels), **N-up label sheets**,
**reusable blocks** (Gutenberg-style synced patterns), and **starter templates**
so a new document is never a blank white sheet. It is fully **RTL/Arabic**-aware.

---

## Data model (`web/lib/documents/types.ts`) — version 2

```
DocTemplate {
  version: 2
  name: string
  page: PageSpec { widthMm, heightMm, marginMm, background }   // shared by all pages
  placeholders: { key, label, sample }[]                       // drive {{tokens}} + Preview
  pages: { id, elements: DocElement[] }[]                       // multi-page
  sheet?: SheetSpec          // saved N-up layout
  sequence?: SequenceConfig  // saved serial-generator settings
}
```

`DocElement` is a union — `text | dynamicText | image | barcode | qr | rect |
line | blockInstance` — sharing `ElementCommon { id, x, y, w, h, rotation, z,
locked?, hidden?, opacity? }`. Geometry is **absolute millimetres**; the canvas
converts with `PX_PER_MM = 96/25.4` and a CSS `transform: scale(zoom)`.

`blockInstance` is a **pointer** (`{ type:'blockInstance', blockId }`) into the
block library — documents never inline-copy block elements, so editing a block
propagates to every instance.

**Versioning:** `storage.ts#migrateTemplate` upgrades legacy v1 (a flat
top-level `elements` array) to v2 (single page). `isDocTemplate` accepts both;
persisted templates are migrated on read.

---

## Feature map (where things live)

| Area | Files |
|------|-------|
| Composition / state / toolbar | `document-designer.tsx` |
| Interactive canvas (drag/resize/select, snap guides, grid, rulers, readout) | `canvas.tsx` |
| Element content renderers (text/barcode/image/…) | `element-content.tsx`, `barcode-svg.tsx` |
| Shared non-interactive element layer (blocks + print) | `element-layer.tsx` |
| Palette (add elements, layers, blocks) | `palette.tsx` |
| Inspector (element / page / data / batch / sheet tabs) | `inspector.tsx` |
| Print/export render (all pages × rows, N-up) | `print-document.tsx` |
| Types + presets | `lib/documents/types.ts`, `presets.ts` |
| Local persistence + migration | `lib/documents/storage.ts` |
| Alignment-snap geometry (pure, unit-tested) | `lib/documents/geometry.ts` |
| Variable-data batch (serial/CSV/JSON) | `lib/documents/batch.ts`, `csv.ts` |
| N-up label sheets | `lib/documents/sheet.ts` |
| Reusable blocks store | `lib/documents/blocks.ts` |
| Starter templates + starter blocks | `lib/documents/starters.ts` |

### Editing
Undo/redo (coalesced snapshots), multi-select (shift/⌘-click; group
move/delete/nudge/clipboard), align (to the **page** for one element, to the
**selection bounding box** for many), distribute, z-order, lock, hide, opacity,
rotation. Precision aids: alignment snap guides (page/element/margin), a 5 mm
grid overlay, mm rulers, and a live `W × H mm` readout — all toggleable, edit-only.

### Variable data (serialised labels)
The **Batch** tab produces one rendered copy per data row from three sources:
a **serial/sequence generator** (prefix/start/count/step/zero-pad/suffix), a
**CSV/TSV upload**, or **pasted CSV/JSON**. Rows layer over the placeholder
sample data, so `{{sku}}` in a barcode/text resolves per row. Preview pages
through rows; Print emits `rows × pages` copies.

### N-up label sheets
The **Sheet** tab tiles many labels onto one physical sheet (Avery-style:
cols × rows, margins, gutters; presets included). Combined with a batch, rows
flow into cells across sheets. `sheet.ts` holds the pure layout maths.

### Reusable blocks (Gutenberg model)
Select elements → **Save as block** → insert as a `blockInstance` pointer.
**Edit block** (double-click) opens the block in the full editor; **Done**
writes it back and **every instance updates**. **Detach** inlines an independent
copy. Blocks have scope tiers **system / personal / tenant / global**. Built-in
**system** starters (company header/footer) ship so the Blocks panel is never
empty (`starters.ts#STARTER_BLOCKS`).

### Starters (no white sheet)
**Start from…** offers ready, editable templates (Invoice, Exam sheet,
Production note, Shipping label). These are the seed source the backend will use
to seed each tenant, pre-filled with real company info.

---

## Rendering & printing

- **Canvas** (`canvas.tsx`) is interactive. **Print** uses a separate off-screen
  `PrintDocument` that renders every page for every dataset row, tiled N-up when
  a sheet is enabled; `document-designer.tsx` injects `@page` CSS sized to the
  page (or sheet) and calls `window.print()`. Both paths share element rendering;
  `blockInstance` resolves via `element-layer.tsx#BlockInstanceContent`.
- **Security (CodeQL):** no `dangerouslySetInnerHTML`. Barcodes/QR are bwip-js
  SVG rendered as an inert `data:` URI `<img>` (no script/fetch). Image `src` is
  hardened to `http(s)` only via `new URL().protocol` (regex guards were not
  accepted by CodeQL).

---

## RTL / Arabic

Text and dynamic-text elements carry a per-text `direction` (`auto|ltr|rtl`,
default `auto`) applied in edit, preview and print — correct for Arabic and
mixed Arabic/Latin (e.g. a Latin serial inside Arabic). The designer **chrome**
uses logical CSS so it mirrors under RTL; the **canvas page keeps physical mm
coordinates** on purpose (print geometry must not mirror). See the
`Arabic / RTL` KB entry. The server-side PDF renderer must ship Arabic fonts.

---

## Persistence & backend contract

Today templates and blocks live in **browser localStorage** behind a small seam:
`storage.ts` (`listSaved` / `saveTemplate` / `deleteSaved`) and `blocks.ts`
(`listBlocks` / `saveBlock` / `deleteBlock`). The durable, tenant-scoped,
**RBAC-gated** backend store + per-tenant **seeding** + **server-side PDF** are
specified in Tasker tasks **58cdd88a** (templates + render) and **ca1d8c03**
(blocks). When the API lands, repoint the seam — the function signatures stay the
same, so the designer itself is unchanged. RBAC rule: the API returns only the
templates/blocks a user may see (server-enforced); the client filters + offers a
permission picker at publish time.

---

## Extending

- **New element type:** add to the `DocElement` union + `ElementType`
  (`types.ts`); a `case` in `element-content.tsx` (and the exhaustive `never`
  guard); an add button in `palette.tsx`; a factory in `storage.ts#newElement`;
  inspector fields in `inspector.tsx`.
- **New starter template/block:** add a factory to `starters.ts`
  (`STARTER_TEMPLATES` / `STARTER_BLOCKS`).
- **New barcode symbology:** add to `BARCODE_SYMBOLOGIES` (`types.ts`) — bwip-js
  ids.

## Testing

- Unit (jest): `web/__tests__/documents-*.test.ts` cover the pure libs
  (geometry, batch, csv, sheet, storage migration).
- E2E (Playwright, admin project): `web/e2e/document-designer.spec.ts` — mount,
  barcode/QR render, dynamic-text interpolation, keyboard/drag, multi-page,
  batch, sheets, blocks + edit-propagate, RTL, grid/rulers, distribute, starters.
  Run against a live stack via `E2E_PORT=3000`.
