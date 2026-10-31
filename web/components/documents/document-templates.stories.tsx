import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import DocumentTemplatesPage from "@/app/(protected)/admin/document-templates/page"
import { arabicHandlers, withArabic } from "../../.storybook/arabic"
import { capabilitiesHandler, defaultHandlers, http, HttpResponse } from "../../.storybook/mocks"

/**
 * Document templates (A31): the editor templates and label sheets documents
 * are issued from, and who can see each one. Example Bakery data.
 */
const base = {
  lang: "en",
  own_fields: [],
  draft_version: 3,
  required_permission: null,
  owner_ou_id: null,
  created_by: 1,
  updated_at: "2026-10-28T09:30:00Z",
  starter_key: null,
  legacy_template_id: null,
}

const TEMPLATES = [
  { ...base, id: 1, kind: "template", title: "Example Bakery invoice", record_type: "invoice", published_version: 2, scope: "system", created_by: null, starter_key: "invoice" },
  { ...base, id: 6, kind: "template", title: "Example Bakery works order", record_type: null, published_version: null, scope: "tenant", owner_ou_id: 2, legacy_template_id: 41 },
  { ...base, id: 7, kind: "template", title: "Example Café delivery note", record_type: "invoice", published_version: 5, scope: "tenant", required_permission: "documents:publish" },
]
const LABELS = [
  { ...base, id: 8, kind: "label", title: "Example Bakery shelf labels", record_type: null, published_version: 1, scope: "personal" },
]

const pageHandlers = [
  capabilitiesHandler(["documents:read", "documents:write", "documents:publish", "documents:issue"]),
  http.get("*/api/v1/document-sources", ({ request }) => {
    const kind = new URL(request.url).searchParams.get("kind")
    return HttpResponse.json({ data: kind === "label" ? LABELS : TEMPLATES })
  }),
  http.get("*/api/v1/document-embeds", () =>
    HttpResponse.json({ data: { record_types: [{ key: "invoice", label: "Invoice" }], chart_sources: [] } })
  ),
  http.get("*/api/v1/ous", () =>
    HttpResponse.json({
      data: [
        { id: 1, name: "Example Bakery" },
        { id: 2, name: "Example Bakery kitchen" },
      ],
      pagination: { page: 1, perPage: 100, total: 2, totalPages: 1 },
    })
  ),
  http.get("*/api/v1/permissions", () =>
    HttpResponse.json({ data: [{ name: "documents:publish" }, { name: "documents:write" }] })
  ),
]

const meta = {
  title: "Documents/Document templates",
  component: DocumentTemplatesPage,
  parameters: { nextjs: { appDirectory: true }, msw: { handlers: [...pageHandlers, ...defaultHandlers] } },
} satisfies Meta<typeof DocumentTemplatesPage>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Arabic: Story = {
  decorators: [withArabic],
  parameters: { msw: { handlers: [...arabicHandlers, ...pageHandlers, ...defaultHandlers] } },
}

export const Empty: Story = {
  parameters: {
    msw: {
      handlers: [
        http.get("*/api/v1/document-sources", () => HttpResponse.json({ data: [] })),
        ...pageHandlers,
        ...defaultHandlers,
      ],
    },
  },
}
