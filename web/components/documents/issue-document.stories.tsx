import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { DocumentEditorScreen, type DocumentEditorAdapter } from "@whity-org/features/document-editor"
import type { Block, FieldRef, WhityDocument } from "@whity-org/ui/editor/contract"
import type { FieldGroup } from "@whity-org/ui/editor/fields"
import { SAMPLE_DOCUMENT } from "@whity-org/ui/editor/sample"
import { arabicHandlers, withArabic } from "../../.storybook/arabic"
import { defaultHandlers } from "../../.storybook/mocks"

/**
 * Issuing a document in the editor (M4c): a template's published version with
 * the record's values, the issuing bar with its own fields, and Issue; and a
 * letter made by "Edit before issuing", editable with the preview on.
 * An in-memory adapter stands in for the server. Example Bakery data.
 */
const t = (text: string) => ({ type: "text" as const, text })
const f = (path: string, label: string): FieldRef => ({ type: "field", path, label })

const FIELDS: FieldGroup[] = [
  {
    group: "Invoice",
    items: [
      { path: "invoice.number", label: "Invoice number", sample: "INV-1042", required: true },
      { path: "invoice.total", label: "Invoice total", sample: "JOD 301.600" },
      { path: "customer.name", label: "Customer name", sample: "Example Café" },
    ],
  },
  { group: "Today", items: [{ path: "today", label: "Today", sample: "31 October 2026" }] },
]

const BLOCKS: Block[] = [
  { id: "b1", type: "paragraph", content: [t("Dear "), f("customer.name", "Customer name"), t(",")] },
  {
    id: "b2",
    type: "paragraph",
    content: [t("Invoice "), f("invoice.number", "Invoice number"), t(" for "), f("invoice.total", "Invoice total"), t(" is attached.")],
  },
  { id: "b3", type: "paragraph", content: [t("Delivery slot: "), f("template.slot", "Delivery slot")] },
  { id: "b4", type: "paragraph", content: [t("Kind regards,"), { type: "break" }, t("Example Bakery")] },
]

const base: WhityDocument = {
  ...SAMPLE_DOCUMENT,
  id: "1",
  title: "Example Bakery invoice",
  defaultView: "page",
  recordType: "invoice",
  page: { size: "A4", marginMm: 16, letterhead: true },
  ownFields: [{ key: "slot", label: "Delivery slot", sample: "Tuesday morning" }],
  blocks: BLOCKS,
}

function adapter(document: WhityDocument): DocumentEditorAdapter {
  return {
    load: async () => ({ document, version: 3 }),
    save: async (_id, _patch, version) => ({ ok: true, version: version + 1, updatedAt: "2026-10-31T09:00:00Z" }),
    loadServices: async () => ({ recordTypes: [{ key: "invoice", label: "Invoice" }] }),
    loadTemplate: async () => ({
      fields: FIELDS,
      letterhead: { name: "Example Bakery", lines: ["Mecca Street 112, Amman · Tax no. 0123456"] },
      letterheadParts: { address: "Mecca Street 112, Amman", taxId: "Tax no. 0123456", phone: "+962 6 555 0101", email: null },
    }),
    findRecords: async () => [
      { ref: "invoice:31", label: "INV-031 · Example Café" },
      { ref: "invoice:32", label: "INV-032 · Example Bakery kitchen" },
    ],
    preview: async () => ({
      label: "INV-031 · Example Café",
      values: { "invoice.number": "INV-031", "invoice.total": "JOD 48.250", "customer.name": "Example Café", today: "31 October 2026" },
    }),
    loadVersion: async () => ({ title: document.title, blocks: BLOCKS, ownFields: document.ownFields }),
    copyForIssue: async () => "2",
    exportPdf: async () => {},
    issue: async () => ({ ok: false, message: "Some required fields have no value", missing: ["template.slot"] }),
  }
}

const TEMPLATE: WhityDocument = { ...base, kind: "template", status: "published", publishedVersion: 4 }
const LETTER: WhityDocument = { ...base, id: "2", kind: "letter", title: "Example Bakery invoice", status: "draft" }

const issue = { record: "invoice:31", onIssued: () => {}, onEditCopy: () => {} }

const meta = {
  title: "Documents/Issue a document",
  component: DocumentEditorScreen,
  parameters: { layout: "fullscreen", msw: { handlers: defaultHandlers } },
  decorators: [
    (Story) => (
      <div className="-m-8 h-svh">
        <Story />
      </div>
    ),
  ],
  args: { id: "1", adapter: adapter(TEMPLATE), canEdit: true, path: "New document", issue },
} satisfies Meta<typeof DocumentEditorScreen>

export default meta
type Story = StoryObj<typeof meta>

/** From the template: its published version, read-only, previewed with the record. */
export const FromTemplate: Story = {}

/** "Edit before issuing": the letter copy, editable with the preview on. */
export const EditBeforeIssuing: Story = { args: { id: "2", adapter: adapter(LETTER) } }

export const Arabic: Story = {
  decorators: [withArabic],
  parameters: { msw: { handlers: [...arabicHandlers, ...defaultHandlers] } },
}
