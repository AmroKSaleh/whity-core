import type { Meta, StoryObj } from "@storybook/react-vite"
import * as React from "react"

import type { EditorMode, WhityDocument } from "./contract"
import { DocEditor } from "./doc-editor"
import { SAMPLE_DOCUMENT } from "./sample"

const meta = {
  title: "Editor/DocEditor",
  parameters: { layout: "fullscreen", fullBleed: true },
} satisfies Meta

export default meta
type Story = StoryObj<typeof meta>

function Live({ initial = SAMPLE_DOCUMENT, canEdit = true }: { initial?: WhityDocument; canEdit?: boolean }) {
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
      />
    </div>
  )
}

export const Editing: Story = { render: () => <Live /> }

export const ReadOnly: Story = { render: () => <Live canEdit={false} /> }

export const Empty: Story = {
  render: () => <Live initial={{ ...SAMPLE_DOCUMENT, title: "", blocks: [] }} />,
}
