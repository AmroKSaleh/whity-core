import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import type { Block, FieldRef, PublishBlocker, WhityDocument } from "./contract"
import { DocEditor } from "./doc-editor"
import type { FieldGroup } from "./fields"
import type { Letterhead } from "./page-view"
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
}: {
  initial: WhityDocument
  blockers?: PublishBlocker[]
  startPreview?: boolean
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
        letterhead={LETTERHEAD}
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
      ]}
    />
  ),
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
