import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import type { Block, FieldRef, PublishBlocker, ResolvedThemeTokens, WhityDocument } from "./contract"
import { DocEditor } from "./doc-editor"
import type { FieldGroup } from "./fields"
import { themedLetterhead, type Letterhead } from "./page-view"
import { SAMPLE_DOCUMENT } from "./sample"
import type { FieldPreview } from "./services"

const meta = {
  title: "Editor/Templates",
  parameters: { layout: "fullscreen", fullBleed: true },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const t = (text: string) => ({ type: "text" as const, text })
const f = (path: string, label: string, required?: boolean): FieldRef => ({ type: "field", path, label, ...(required ? { required } : {}) })
const wait = <T,>(value: T, ms = 500) => new Promise<T>((done) => setTimeout(() => done(value), ms))

/** What an invoice template's record type offers (EditorInline). */
const FIELDS: FieldGroup[] = [
  {
    group: "Customer",
    items: [
      { path: "customer.name", label: "Customer name", sample: "Example Clinic" },
      { path: "customer.tax_number", label: "Customer tax number", sample: "0987654" },
    ],
  },
  {
    group: "Invoice",
    items: [
      { path: "invoice.number", label: "Invoice number", sample: "INV-1042", required: true },
      { path: "invoice.total", label: "Invoice total", sample: "JOD 301.600", required: true },
      { path: "invoice.due_date", label: "Due date", sample: "14 Oct 2026" },
      { path: "invoice.days_overdue", label: "Days overdue", sample: "15" },
    ],
  },
  {
    group: "Company",
    items: [
      { path: "company.name", label: "Company name", sample: "Example Bakery" },
      { path: "company.iban", label: "Company IBAN", sample: "JO94 CBJO 0010 0000 0000 0131 0003 02" },
      { path: "sender.name", label: "Sender name", sample: "Rana Haddad" },
    ],
  },
  { group: "Today", items: [{ path: "today", label: "Today", sample: "29 October 2026" }] },
]

const LETTERHEAD: Letterhead = {
  name: "Example Bakery",
  lines: ["Mecca Street 112, Amman · Tax no. 0123456", "+962 6 555 0100 · accounts@bakery.example.jo"],
}

const BLOCKS: Block[] = [
  { id: "t1", type: "paragraph", content: [t("Dear "), f("customer.name", "Customer name"), t(",")] },
  {
    id: "t2",
    type: "paragraph",
    content: [
      t("Invoice "),
      f("invoice.number", "Invoice number", true),
      t(" for "),
      f("invoice.total", "Invoice total", true),
      t(" was due on "),
      f("invoice.due_date", "Due date"),
      t(". Please pay within 14 days of this letter."),
    ],
  },
  {
    id: "t3",
    type: "callout",
    kind: "note",
    content: [
      t("Pay by bank transfer to "),
      { type: "text", text: "Example Bank", marks: [{ type: "bold" }] },
      t(", IBAN "),
      f("company.iban", "Company IBAN"),
      t(", with the invoice number as the reference."),
    ],
  },
  { id: "t4", type: "heading", level: 2, content: [t("Open invoices")] },
  {
    id: "t5",
    type: "table",
    headerRow: true,
    columns: [{ align: "start" }, { align: "start" }, { align: "end" }],
    rows: [
      [[t("Invoice")], [t("Due")], [t("Amount")]],
      [[t("INV-1042")], [t("14 Oct 2026")], [t("JOD 301.600")]],
      [[t("INV-1051")], [t("21 Oct 2026")], [t("JOD 96.000")]],
    ],
  },
  { id: "t6", type: "paragraph", content: [t("Customer tax number: "), f("customer.tax_number", "Customer tax number")] },
  { id: "t7", type: "paragraph", content: [t("Kind regards,")] },
  { id: "t8", type: "paragraph", content: [f("sender.name", "Sender name"), { type: "break" }, f("company.name", "Company name")] },
]

/** INV-1042's values; Example Clinic has no tax number on file (E.27). */
const PREVIEW: FieldPreview = {
  label: "INV-1042 · Example Clinic",
  values: {
    "customer.name": "Example Clinic",
    "customer.tax_number": null,
    "invoice.number": "INV-1042",
    "invoice.total": "JOD 301.600",
    "invoice.due_date": "14 Oct 2026",
    "invoice.days_overdue": "15",
    "company.name": "Example Bakery",
    "company.iban": "JO94 CBJO 0010 0000 0000 0131 0003 02",
    "sender.name": "Rana Haddad",
    today: "29 October 2026",
  },
}

const TEMPLATE: WhityDocument = {
  ...SAMPLE_DOCUMENT,
  id: "tpl-payment-reminder",
  kind: "template",
  title: "Payment reminder",
  defaultView: "page",
  recordType: "invoice",
  page: { size: "A4", marginMm: 16, letterhead: true },
  status: "draft",
  publishedVersion: 3,
  blocks: BLOCKS,
}

function Live({
  initial,
  blockers = [],
  startPreview = false,
  theme = null,
  letterhead = LETTERHEAD,
}: {
  initial: WhityDocument
  blockers?: PublishBlocker[]
  startPreview?: boolean
  theme?: ResolvedThemeTokens | null
  letterhead?: Letterhead
}) {
  const [doc, setDoc] = React.useState(initial)
  const [preview, setPreview] = React.useState<FieldPreview | null>(startPreview ? PREVIEW : null)
  const [version, setVersion] = React.useState<number | null>(initial.publishedVersion ?? null)
  return (
    <div style={{ height: "100vh" }}>
      <DocEditor
        document={doc}
        onChange={setDoc}
        canEdit
        back={{ label: "Back to templates", onClick: () => {} }}
        path="Templates · Letters"
        save={{ state: "saved", at: "14:02" }}
        fields={FIELDS}
        letterhead={letterhead}
        theme={theme}
        pageFields={{ reference: FIELDS[1].items[0], date: FIELDS[3].items[0] }}
        preview={preview}
        onPreview={() => setPreview(PREVIEW)}
        onClosePreview={() => setPreview(null)}
        onExportPdf={() => wait(undefined)}
        publish={{
          publishedVersion: version,
          recordLabel: "payment reminder",
          check: () => wait(blockers),
          publish: () => wait(undefined).then(() => setVersion((v) => (v ?? 0) + 1)),
        }}
      />
    </div>
  )
}

/** A33: the template in the page view, with fields as chips. Type {{ or use Field ▾. */
export const Designer: Story = { render: () => <Live initial={TEMPLATE} /> }

/** E.12: the pages as they will print with INV-1042, read-only, a missing value flagged. */
export const PreviewWithRecord: Story = { render: () => <Live initial={TEMPLATE} startPreview /> }

/** E.37: Publish opens the check; it stays disabled while anything blocks it. */
export const PublishBlocked: Story = {
  render: () => (
    <Live
      initial={TEMPLATE}
      blockers={[
        { kind: "open-suggestions", count: 2 },
        { kind: "unknown-field", path: "customer.vat" },
        { kind: "theme-unavailable", ref: "tenant.autumn" },
      ]}
    />
  ),
}

/**
 * E.44 and E.45: a workspace theme as the server resolves it, with the print
 * colour on the title and H1-H3, the phone and address lines in that order,
 * and no logo (the name alone).
 */
const THEME: ResolvedThemeTokens = {
  accent: "#8A3D12",
  accentOnDark: "#E7A27A",
  accentUse: "title-and-headings",
  letterhead: { logo: "none", lines: ["phone", "address"] },
}

export const Themed: Story = {
  render: () => (
    <Live
      initial={TEMPLATE}
      theme={THEME}
      letterhead={themedLetterhead(
        LETTERHEAD,
        THEME,
        { address: "Mecca Street 112, Amman", taxId: "Tax no. 0123456", phone: "+962 6 555 0101", email: null },
        { wide: null, square: null }
      )}
    />
  ),
}

/** E.11: the continuous sheet follows the app theme; in dark mode its title takes `accentOnDark`. */
export const ThemedContinuous: Story = {
  render: () => <Live initial={{ ...TEMPLATE, defaultView: "continuous" }} theme={THEME} />,
}

/** Long enough for the running header, a page break and "Page x of y". */
export const TwoPages: Story = {
  render: () => (
    <Live
      initial={{
        ...TEMPLATE,
        blocks: [
          ...BLOCKS,
          { id: "pb", type: "pageBreak" },
          { id: "n1", type: "heading", level: 2, content: [t("Terms")] },
          ...Array.from({ length: 6 }, (_, i): Block => ({
            id: `n${i + 2}`,
            type: "paragraph",
            content: [t("Payments are due within 30 days of the invoice date. Late payments may be charged a fee as agreed in the contract, and goods stay ours until paid in full.")],
          })),
        ],
      }}
    />
  ),
}

export const Arabic: Story = {
  globals: { direction: "rtl" },
  render: () => <Live initial={{ ...TEMPLATE, lang: "ar", title: "تذكير بالدفع" }} />,
}

const LABELS: WhityDocument = {
  ...SAMPLE_DOCUMENT,
  id: "tpl-shipping-labels",
  kind: "label",
  title: "Shipping labels",
  defaultView: "page",
  page: { size: "A4", marginMm: 16, letterhead: false },
  labels: { columns: 2, rows: 4, size: { widthMm: 99.1, heightMm: 67.7 }, gapMm: 2.5, gapYMm: 0 },
  ownFields: [
    { key: "ship_to", label: "Ship to", sample: "Example Clinic" },
    { key: "address", label: "Address", sample: "Rainbow Street 21, Amman" },
    { key: "tracking", label: "Tracking number", sample: "TRK000123456" },
  ],
  status: "draft",
  blocks: [
    { id: "l1", type: "paragraph", content: [t("From "), f("company.name", "Company name")] },
    {
      id: "l2",
      type: "paragraph",
      content: [{ ...f("template.ship_to", "Ship to"), marks: [{ type: "bold" }] }, { type: "break" }, f("template.address", "Address")],
    },
    { id: "l3", type: "barcode", symbology: "code128", value: [f("template.tracking", "Tracking number")], showText: true, heightMm: 12 },
  ],
}

/**
 * E.43: a label sheet. The first label is edited and the others repeat it;
 * the stock is set beside the zoom; Print labels replaces PDF. Ship to,
 * Address and Tracking number are the template's own fields (Field ▾ → This template).
 */
export const LabelSheet: Story = { render: () => <LabelLive initial={LABELS} /> }

function LabelLive({ initial }: { initial: WhityDocument }) {
  const [doc, setDoc] = React.useState(initial)
  return (
    <div style={{ height: "100vh" }}>
      <DocEditor
        document={doc}
        onChange={setDoc}
        canEdit
        back={{ label: "Back to templates", onClick: () => {} }}
        path="Templates · Labels"
        save={{ state: "saved", at: "14:02" }}
        fields={[FIELDS[2]]}
        onPrintLabels={() => {}}
        publish={{ publishedVersion: null, check: () => wait([]), publish: () => wait(undefined) }}
      />
    </div>
  )
}

/** A label whose content runs past its edge is flagged; on paper it would be cut. */
export const LabelOverflow: Story = {
  render: () => (
    <div style={{ height: "100vh" }}>
      <DocEditor
        document={{
          ...LABELS,
          labels: { columns: 3, rows: 8, size: { widthMm: 63.5, heightMm: 33.9 }, gapMm: 2.5, gapYMm: 0 },
          blocks: [
            ...LABELS.blocks,
            { id: "l4", type: "paragraph", content: [t("Handle with care. Keep upright and away from heat.")] },
          ],
        }}
        onChange={() => {}}
        canEdit
        fields={[FIELDS[2]]}
        onPrintLabels={() => {}}
      />
    </div>
  ),
}
