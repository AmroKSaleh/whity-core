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

const ARABIC_LABELS = {
  back: "رجوع",
  documentTitle: "عنوان المستند",
  saved: "حُفظ {time}",
  modeEditing: "تحرير",
  modeMenu: "الوضع",
  normalText: "نص عادي",
  insert: "إدراج",
  outline: "المخطط",
  words: "الكلمات: {n}",
  arabic: "العربية",
  kindNote: "ملاحظة",
  lastEdited: "آخر تعديل: {name} الساعة {time}",
  placeholder: "اكتب / للكتل، أو {{ للحقول، أو ابدأ الكتابة",
}

const t = (text: string) => ({ type: "text" as const, text })

/** EditorArabic: the shell mirrors, each block follows its own text, numbers stay Western. */
export const Arabic: Story = {
  globals: { direction: "rtl" },
  render: () => {
    const doc: WhityDocument = {
      ...SAMPLE_DOCUMENT,
      lang: "ar",
      title: "تذكير بالدفع",
      blocks: [
        { id: "a1", type: "paragraph", content: [t("السادة عيادة المثال المحترمين،")] },
        { id: "a2", type: "paragraph", content: [t("استحقت الفاتورة INV-1042 بقيمة JOD 301.600 بتاريخ 14 Oct 2026.")] },
        { id: "a3", type: "heading", level: 1, content: [t("الفواتير المفتوحة")] },
        { id: "a4", type: "number", level: 0, content: [t("INV-1042، بقيمة JOD 301.600")] },
        { id: "a5", type: "number", level: 0, content: [t("INV-1051، بقيمة JOD 96.000")] },
        { id: "a6", type: "paragraph", content: [t("Payment can also be made online by card.")] },
        { id: "a7", type: "checklist", level: 0, checked: false, content: [t("مراجعة المبالغ")] },
      ],
    }
    return <LiveWithLabels initial={doc} />
  },
}

function LiveWithLabels({ initial }: { initial: WhityDocument }) {
  const [doc, setDoc] = React.useState(initial)
  return (
    <div style={{ height: "100vh" }}>
      <DocEditor
        document={doc}
        onChange={setDoc}
        canEdit
        back={{ label: "رجوع", onClick: () => {} }}
        path="الملاحظات والويكي"
        save={{ state: "saved", at: "14:02" }}
        labels={ARABIC_LABELS}
      />
    </div>
  )
}
