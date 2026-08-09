import { http, HttpResponse } from "msw"
import type { DocElement, DocTemplate, PageSpec, Placeholder, TextStyle } from "@/lib/documents/types"
import type { DocBlock } from "@/lib/documents/blocks"
import { DEFAULT_TEXT_STYLE } from "@/lib/documents/presets"
import type { SheetSpec } from "@/lib/documents/sheet"
import type { SequenceConfig } from "@/lib/documents/batch"

/**
 * Shared fixtures + MSW handlers for the document-designer gallery
 * (`components/documents/*.stories.tsx`).
 *
 * The designer's persistence is API-backed — templates via
 * `/api/v1/document-templates` and reusable blocks via
 * `/api/v1/document-blocks` (see `lib/documents/storage.ts` /
 * `lib/documents/blocks.ts`, both of which THROW so the designer can toast).
 * `documentHandlers()` keeps that offline and deterministic; `documentHandlers`
 * with an explicit `status` drives the failure paths.
 *
 * The element fixtures below deliberately cover EVERY `DocElement` type — text,
 * dynamicText, image, barcode, qr, rect, line, math and blockInstance — so a
 * single canvas story exercises the whole renderer surface.
 */

// ── style + element builders ─────────────────────────────────────────────────

/** A `TextStyle` over the app's real defaults. */
export const style = (o: Partial<TextStyle> = {}): TextStyle => ({ ...DEFAULT_TEXT_STYLE, ...o })

let seq = 0
const eid = (t: string) => `${t}-${(seq += 1)}`

export const text = (
  x: number,
  y: number,
  w: number,
  h: number,
  value: string,
  o: Partial<TextStyle> = {}
): DocElement => ({ id: eid("text"), type: "text", x, y, w, h, rotation: 0, z: 0, text: value, style: style(o) })

export const dynamicText = (
  x: number,
  y: number,
  w: number,
  h: number,
  template: string,
  o: Partial<TextStyle> = {}
): DocElement => ({ id: eid("dyn"), type: "dynamicText", x, y, w, h, rotation: 0, z: 0, template, style: style(o) })

export const line = (x: number, y: number, w: number): DocElement => ({
  id: eid("line"),
  type: "line",
  x,
  y,
  w,
  h: 0.4,
  rotation: 0,
  z: 0,
  stroke: "#333333",
  strokeWidth: 0.4,
})

/**
 * `ElementContent` only lets absolute http(s) URLs reach the `<img>` (a
 * deliberate XSS sink guard — data-URIs are rejected), so point at a real asset
 * Storybook serves from `staticDirs: ["../public"]` rather than inlining one.
 * Falls back to '' (the dashed "unresolved image" placeholder) outside a browser.
 */
export const LOGO_URL = typeof window === "undefined" ? "" : `${window.location.origin}/globe.svg`

// ── the sample template ──────────────────────────────────────────────────────

export const LABEL_PAGE: PageSpec = { widthMm: 101.6, heightMm: 152.4, marginMm: 6, background: "#ffffff" }

export const A4_PAGE: PageSpec = { widthMm: 210, heightMm: 297, marginMm: 10, background: "#ffffff" }

export const PLACEHOLDERS: Placeholder[] = [
  { key: "company_name", label: "Company name", sample: "Acme Corp" },
  { key: "ship_to", label: "Ship to", sample: "Recipient Name\n456 Delivery Rd\nCity, ZIP" },
  { key: "tracking", label: "Tracking", sample: "TRK-000123456" },
  { key: "sku", label: "SKU", sample: "WID-001" },
  { key: "logo_url", label: "Logo URL", sample: LOGO_URL },
]

/** The sample-data map the canvas/preview resolves `{{tokens}}` against. */
export const SAMPLE_DATA: Record<string, string> = Object.fromEntries(
  PLACEHOLDERS.map((p) => [p.key, p.sample])
)

/** One element of every type, laid out on a 4×6″ shipping label. */
export const ALL_TYPE_ELEMENTS: DocElement[] = [
  { id: "hdr", type: "blockInstance", x: 6, y: 5, w: 90, h: 21, rotation: 0, z: 1, blockId: "sys-header" },
  text(6, 30, 40, 5, "SHIP TO:", { fontSize: 8, fontWeight: "bold", color: "#555555" }),
  dynamicText(6, 36, 62, 24, "{{ship_to}}", { fontSize: 12 }),
  { id: "logo", type: "image", x: 72, y: 34, w: 24, h: 24, rotation: 0, z: 4, src: "", binding: "logo_url", fit: "contain" },
  {
    id: "bc",
    type: "barcode",
    x: 6,
    y: 64,
    w: 90,
    h: 24,
    rotation: 0,
    z: 5,
    symbology: "code128",
    value: "{{tracking}}",
    binding: undefined,
    showText: true,
  },
  { id: "qr", type: "qr", x: 6, y: 92, w: 24, h: 24, rotation: 0, z: 6, value: "{{sku}}", binding: undefined, eclevel: "M" },
  { id: "box", type: "rect", x: 34, y: 92, w: 62, h: 24, rotation: 0, z: 7, fill: "#f8fafc", stroke: "#cbd5e1", strokeWidth: 0.3, radius: 1 },
  { id: "math", type: "math", x: 36, y: 97, w: 58, h: 14, rotation: 0, z: 8, expression: "c = \\sqrt{a^2 + b^2}", block: true },
  line(6, 122, 90),
  dynamicText(6, 125, 90, 6, "{{sku}}", { fontSize: 8, align: "center", color: "#666666" }),
]

/** A second page, so multi-page navigation and print fan-out have something to show. */
export const PAGE_TWO_ELEMENTS: DocElement[] = [
  text(6, 8, 90, 8, "Handling notes", { fontSize: 14, fontWeight: "bold" }),
  line(6, 18, 90),
  dynamicText(6, 22, 90, 8, "Order {{tracking}}", { fontSize: 10 }),
  text(6, 34, 90, 60, "Fragile — do not stack. Keep dry.", { fontSize: 10 }),
]

/** Arabic / mixed-direction content: `direction: 'auto'` infers per paragraph,
 *  so a Latin serial inside Arabic text still reads correctly (WC RTL support). */
export const RTL_ELEMENTS: DocElement[] = [
  dynamicText(6, 8, 90, 10, "{{company_name}}", { fontSize: 16, fontWeight: "bold", align: "right" }),
  text(6, 20, 90, 10, "شركة أكمي المحدودة", { fontSize: 16, fontWeight: "bold", align: "right" }),
  text(6, 32, 90, 16, "يُرجى تسليم الطرد إلى العنوان المذكور أدناه.", { fontSize: 11, align: "right" }),
  text(6, 50, 90, 10, "رقم التتبع: TRK-000123456", { fontSize: 11, align: "right" }),
  text(6, 62, 90, 10, "Tracking no. — رقم التتبع", { fontSize: 11, direction: "rtl", align: "right" }),
  line(6, 74, 90),
]

export const SAMPLE_TEMPLATE: DocTemplate = {
  version: 2,
  name: "Shipping label",
  page: LABEL_PAGE,
  placeholders: PLACEHOLDERS,
  pages: [
    { id: "page-1", elements: ALL_TYPE_ELEMENTS },
    { id: "page-2", elements: PAGE_TWO_ELEMENTS },
  ],
}

export const RTL_TEMPLATE: DocTemplate = {
  version: 2,
  name: "بطاقة شحن",
  page: LABEL_PAGE,
  placeholders: PLACEHOLDERS,
  pages: [{ id: "page-1", elements: RTL_ELEMENTS }],
}

// ── reusable blocks ──────────────────────────────────────────────────────────

export const HEADER_BLOCK: DocBlock = {
  id: "sys-header",
  name: "Company header",
  scope: "system",
  w: 90,
  h: 21,
  elements: [
    dynamicText(0, 0, 70, 10, "{{company_name}}", { fontSize: 16, fontWeight: "bold" }),
    text(0, 11, 90, 5, "Address line · City · Country", { fontSize: 8, color: "#666666" }),
    line(0, 20, 90),
  ],
}

export const FOOTER_BLOCK: DocBlock = {
  id: "42",
  name: "Company footer",
  scope: "tenant",
  w: 90,
  h: 8,
  elements: [
    line(0, 0, 90),
    dynamicText(0, 2, 90, 6, "{{company_name}} · contact@example.com", { fontSize: 8, align: "center", color: "#666666" }),
  ],
}

export const ADDRESS_BLOCK: DocBlock = {
  id: "43",
  name: "Return address",
  scope: "personal",
  w: 60,
  h: 16,
  elements: [
    text(0, 0, 60, 5, "RETURN TO:", { fontSize: 7, fontWeight: "bold", color: "#555555" }),
    text(0, 6, 60, 10, "Acme Corp\n1 Warehouse Way", { fontSize: 9 }),
  ],
}

export const SAMPLE_BLOCKS: DocBlock[] = [HEADER_BLOCK, FOOTER_BLOCK, ADDRESS_BLOCK]

/** Keyed by id, as `Canvas`/`PrintDocument` consume blocks. */
export const SAMPLE_BLOCKS_MAP: Record<string, DocBlock> = Object.fromEntries(
  SAMPLE_BLOCKS.map((b) => [b.id, b])
)

// ── inspector-only fixtures ──────────────────────────────────────────────────

export const SAMPLE_SHEET: SheetSpec = {
  enabled: false,
  cols: 3,
  rows: 8,
  sheetWidthMm: 210,
  sheetHeightMm: 297,
  marginXMm: 7,
  marginYMm: 13,
  gutterXMm: 2.5,
  gutterYMm: 0,
}

export const TILED_SHEET: SheetSpec = { ...SAMPLE_SHEET, enabled: true, cols: 2, rows: 2 }

export const SAMPLE_SEQUENCE: SequenceConfig = {
  key: "sku",
  prefix: "SN-",
  start: 1,
  count: 10,
  step: 1,
  padding: 4,
  suffix: "",
}

/** Three variable-data rows, as a batch run produces. */
export const BATCH_ROWS: Record<string, string>[] = ["SN-0001", "SN-0002", "SN-0003"].map((sku) => ({
  ...SAMPLE_DATA,
  sku,
  tracking: `TRK-${sku}`,
}))

/** Look up one fixture element by type, for the per-type Inspector stories. */
export function elementOfType(type: DocElement["type"]): DocElement {
  const el = [...ALL_TYPE_ELEMENTS, ...RTL_ELEMENTS].find((e) => e.type === type)
  if (!el) throw new Error(`No fixture element of type ${type}`)
  return el
}

// ── MSW handlers ─────────────────────────────────────────────────────────────

const now = "2026-08-01T10:00:00+00:00"

function templateRow(id: number, t: DocTemplate) {
  return {
    id,
    tenant_id: 10,
    name: t.name,
    data: t as unknown as Record<string, unknown>,
    scope: "tenant" as const,
    required_permission: null,
    is_system: false,
    created_by: 1,
    created_at: now,
    updated_at: now,
  }
}

function blockRow(b: DocBlock) {
  return {
    id: Number(b.id) || 1,
    tenant_id: 10,
    name: b.name,
    // The API stores JUST the element fragment (no w/h) — recomputed on load.
    data: b.elements as unknown as Record<string, unknown>[],
    scope: b.scope,
    required_permission: null,
    is_system: b.scope === "system",
    created_by: 1,
    created_at: now,
    updated_at: now,
  }
}

/** The saved-template list the designer's "Load…" picker shows. */
export const SAVED_TEMPLATE_ROWS = [
  templateRow(1, SAMPLE_TEMPLATE),
  templateRow(2, { ...RTL_TEMPLATE }),
  templateRow(3, { ...SAMPLE_TEMPLATE, name: "Asset tag", page: A4_PAGE }),
]

/**
 * Handlers for the designer's two CRUD APIs. Pass `templates: []` / `blocks: []`
 * for the empty states, or `status` (e.g. 500) to exercise the load-failure
 * toasts — `listSaved`/`listBlocks` throw and the designer surfaces the error.
 * Writes echo back a row so Save/Delete resolve without a real backend.
 */
export function documentHandlers({
  templates = SAVED_TEMPLATE_ROWS,
  blocks = [FOOTER_BLOCK, ADDRESS_BLOCK],
  status,
}: {
  templates?: ReturnType<typeof templateRow>[]
  blocks?: DocBlock[]
  status?: number
} = {}) {
  const fail = (path: string) =>
    http.all(path, () => HttpResponse.json({ error: "Something went wrong" }, { status: status! }))

  if (status !== undefined) {
    return [fail("*/api/v1/document-templates"), fail("*/api/v1/document-templates/:id"), fail("*/api/v1/document-blocks"), fail("*/api/v1/document-blocks/:id")]
  }

  const rows = blocks.map(blockRow)
  return [
    http.get("*/api/v1/document-templates", () => HttpResponse.json({ data: templates })),
    http.post("*/api/v1/document-templates", async ({ request }) => {
      const body = (await request.json()) as { name: string; data: DocTemplate }
      return HttpResponse.json({ data: templateRow(99, body.data) }, { status: 201 })
    }),
    http.patch("*/api/v1/document-templates/:id", async ({ params, request }) => {
      const body = (await request.json()) as { name: string; data: DocTemplate }
      return HttpResponse.json({ data: templateRow(Number(params.id), body.data) })
    }),
    http.delete("*/api/v1/document-templates/:id", () => new HttpResponse(null, { status: 204 })),

    http.get("*/api/v1/document-blocks", () => HttpResponse.json({ data: rows })),
    http.post("*/api/v1/document-blocks", async ({ request }) => {
      const body = (await request.json()) as { name: string; data: DocElement[]; scope: DocBlock["scope"] }
      return HttpResponse.json(
        { data: blockRow({ id: "99", name: body.name, scope: body.scope, w: 40, h: 40, elements: body.data }) },
        { status: 201 }
      )
    }),
    http.patch("*/api/v1/document-blocks/:id", async ({ params, request }) => {
      const body = (await request.json()) as { name: string; data: DocElement[]; scope: DocBlock["scope"] }
      return HttpResponse.json({
        data: blockRow({ id: String(params.id), name: body.name, scope: body.scope, w: 40, h: 40, elements: body.data }),
      })
    }),
    http.delete("*/api/v1/document-blocks/:id", () => new HttpResponse(null, { status: 204 })),
  ]
}
