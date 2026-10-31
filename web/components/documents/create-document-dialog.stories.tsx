import type { Meta, StoryObj } from "@storybook/nextjs-vite"

import { CreateDocumentDialog, type CreatableTemplate } from "./create-document-dialog"
import { arabicHandlers, withArabic } from "../../.storybook/arabic"
import { defaultHandlers } from "../../.storybook/mocks"

/**
 * New document: choose the published template to issue from; issuing itself
 * happens in the editor (M4c). Example Bakery data.
 */
const TEMPLATES: CreatableTemplate[] = [
  { id: 1, title: "Example Bakery invoice", recordType: "invoice", recordTypeLabel: "Invoice" },
  { id: 7, title: "Example Bakery notice", recordType: null },
  { id: 9, title: "Example Café delivery note", recordType: "invoice", recordTypeLabel: "Invoice" },
]

const meta = {
  title: "Documents/Create document dialog",
  component: CreateDocumentDialog,
  args: {
    open: true,
    onOpenChange: () => {},
    templates: TEMPLATES,
    templatesLoading: false,
    templatesError: null,
    onChoose: () => {},
  },
  parameters: { msw: { handlers: defaultHandlers } },
} satisfies Meta<typeof CreateDocumentDialog>

export default meta
type Story = StoryObj<typeof meta>

export const Default: Story = {}

export const Arabic: Story = {
  decorators: [withArabic],
  parameters: { msw: { handlers: [...arabicHandlers, ...defaultHandlers] } },
}

export const NoPublishedTemplates: Story = { args: { templates: [] } }
