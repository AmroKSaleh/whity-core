import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import type { Block, EditorMode, WhityDocument } from "./contract"
import { DocEditor } from "./doc-editor"
import { SAMPLE_DOCUMENT } from "./sample"
import type { EditorServices } from "./services"

const meta = {
  title: "Editor/Rich blocks",
  parameters: { layout: "fullscreen", fullBleed: true },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

const t = (text: string) => ({ type: "text" as const, text })
const wait = <T,>(value: T, ms = 400) => new Promise<T>((done) => setTimeout(() => done(value), ms))

/** Stand-ins for the server: Example Bakery's invoices and a monthly chart. */
const SERVICES: EditorServices = {
  recordTypes: [
    { key: "invoice", label: "Invoice" },
    { key: "document", label: "Issued document" },
  ],
  chartSources: [
    { key: "documents-by-month", label: "Documents issued per month" },
    { key: "revenue-by-month", label: "Revenue per month" },
  ],
  resolveRecord: (ref) =>
    wait(
      ref.id === "INV-1042"
        ? { typeLabel: "Invoice", title: "INV-1042 · Example Clinic", subtitle: "Due 14 Oct 2026", amount: "JOD 301.600", status: { label: "Overdue", tone: "warning" as const } }
        : ref.id === "INV-0001"
          ? null
          : { typeLabel: "Invoice", title: `${ref.id} · Example Bakery`, amount: "JOD 96.000", status: { label: "Paid", tone: "success" as const } }
    ),
  loadChart: (source) =>
    wait(
      source.query === "empty"
        ? { points: [] }
        : {
            valueLabel: source.query === "revenue-by-month" ? "JOD" : "Documents",
            points: [
              { label: "May", value: 18 },
              { label: "Jun", value: 24 },
              { label: "Jul", value: 21 },
              { label: "Aug", value: 30 },
              { label: "Sep", value: 27 },
              { label: "Oct", value: 35 },
            ],
          }
    ),
  uploadFile: (file) => wait({ fileId: String(Date.now()), url: URL.createObjectURL(file), name: file.name, size: file.size, mime: file.type }, 700),
  fileUrl: () => "#",
}

const BLOCKS: Block[] = [
  { id: "r1", type: "paragraph", content: [t("Every M3 block, with stand-in data. Click a block to see its toolbar.")] },
  { id: "r2", type: "callout", kind: "note", content: [t("Payment is due within 30 days of the invoice date.")] },
  { id: "r3", type: "callout", kind: "important", content: [t("Late payments add 2% a month.")] },
  {
    id: "r4",
    type: "toggle",
    open: true,
    summary: [t("How do I pay by card?")],
    children: [{ id: "r4a", type: "paragraph", content: [t("Open the link in the invoice email and follow the steps.")] }],
  },
  {
    id: "r5",
    type: "columns",
    columns: [
      [{ id: "r5a", type: "paragraph", content: [t("Example Bakery, 12 Rainbow Street, Amman")] }],
      [{ id: "r5b", type: "paragraph", content: [t("Example Clinic, 4 Garden Road, Irbid")] }],
    ],
  },
  {
    id: "r6",
    type: "table",
    headerRow: true,
    columns: [{ align: "start" }, { align: "start" }, { align: "end" }],
    rows: [
      [[t("Invoice")], [t("Customer")], [t("Amount")]],
      [[t("INV-1042")], [t("Example Clinic")], [t("JOD 301.600")]],
      [[t("INV-1051")], [t("Example Café")], [t("JOD 96.000")]],
    ],
  },
  { id: "r7", type: "image", src: "", alt: "", width: "wide" },
  { id: "r8", type: "file", fileId: "f1", name: "price-list-2026.pdf", size: 248_320, mime: "application/pdf" },
  { id: "r9", type: "record", ref: { type: "invoice", id: "INV-1042" }, view: "card" },
  { id: "r10", type: "record", ref: { type: "invoice", id: "INV-1051" }, view: "summary" },
  { id: "r11", type: "record", ref: { type: "invoice", id: "INV-0001" }, view: "card" },
  { id: "r12", type: "chart", chart: "bar", source: { query: "documents-by-month" }, title: "Documents issued per month" },
  { id: "r13", type: "chart", chart: "line", source: { query: "revenue-by-month" }, title: "Revenue per month" },
  {
    id: "r14",
    type: "signature",
    signers: [
      { name: [t("Lina Haddad")], role: [t("Finance manager")] },
      { name: [t("Omar Saleh")], role: [t("Owner")] },
    ],
    date: [t("29 Oct 2026")],
    stamp: true,
  },
  { id: "r15", type: "barcode", symbology: "code128", value: [t("INV-1042")], showText: true },
  { id: "r16", type: "qr", value: [t("https://example.com/pay/INV-1042")], caption: [t("Scan to pay")] },
  { id: "r17", type: "math", tex: "\\text{Total} = \\sum_{i=1}^{n} q_i \\times p_i" },
  { id: "r18", type: "pageBreak" },
  { id: "r19", type: "paragraph", content: [t("After the page break.")] },
]

function Live({ initial, canEdit = true, services = SERVICES }: { initial: WhityDocument; canEdit?: boolean; services?: EditorServices }) {
  const [doc, setDoc] = React.useState(initial)
  const [mode, setMode] = React.useState<EditorMode>("editing")
  return (
    <div style={{ height: "100vh" }}>
      <DocEditor
        document={doc}
        onChange={setDoc}
        canEdit={canEdit}
        mode={mode}
        onModeChange={setMode}
        back={{ label: "Back to notes", onClick: () => {} }}
        path="Notes · Billing"
        save={{ state: "saved", at: "14:02" }}
        services={services}
      />
    </div>
  )
}

const DOC: WhityDocument = { ...SAMPLE_DOCUMENT, title: "Rich blocks", blocks: BLOCKS }

export const AllBlocks: Story = { render: () => <Live initial={DOC} /> }

export const ReadOnly: Story = { render: () => <Live initial={DOC} canEdit={false} /> }

/** No services: records and charts say what is missing instead of loading. */
export const NoServices: Story = { render: () => <Live initial={DOC} services={{}} /> }

export const Arabic: Story = {
  globals: { direction: "rtl" },
  render: () => <Live initial={{ ...DOC, lang: "ar", title: "الكتل الغنية" }} />,
}
