"use client"

import type { Editor } from "@tiptap/core"
import { isTextSelection } from "@tiptap/core"
import { useEditorState } from "@tiptap/react"
import { BubbleMenu } from "@tiptap/react/menus"

import type { IconName } from "../icon"
import { IconButton } from "../icon-button"
import type { EditorLabels } from "./labels"

/**
 * The selection bubble (EditorInline): after a short pause above a text
 * selection, bold, italic, underline, strikethrough, code · link, highlight.
 * Comment (M5) and Rewrite with an agent (M6) join it later.
 */
function SelectionBubble({ editor, labels, onLink }: { editor: Editor; labels: EditorLabels; onLink: () => void }) {
  const s = useEditorState({
    editor,
    selector: ({ editor: e }) => ({
      bold: e.isActive("bold"),
      italic: e.isActive("italic"),
      underline: e.isActive("underline"),
      strike: e.isActive("strike"),
      code: e.isActive("code"),
      link: e.isActive("link"),
      highlight: e.isActive("highlight"),
    }),
  })
  const b = (icon: IconName, label: string, on: boolean, onClick: () => void, shortcut?: string) => (
    <IconButton
      icon={icon}
      label={label}
      size="sm"
      shortcut={shortcut}
      pressed={on}
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
    />
  )
  const mark = (name: string) => () => editor.chain().focus().toggleMark(name).run()
  return (
    <BubbleMenu
      editor={editor}
      updateDelay={250}
      shouldShow={({ editor: e, state, from, to }) =>
        e.isEditable && isTextSelection(state.selection) && from !== to && !e.isActive("codeBlock")
      }
      options={{ placement: "top-start", offset: 8 }}
    >
      <div
        role="toolbar"
        aria-label={labels.selection}
        data-slot="selection-bubble"
        className="flex items-center gap-0.5 rounded-control border border-border bg-popover p-[3px] whitespace-nowrap shadow-float"
      >
        {b("bold", labels.bold, s.bold, mark("bold"), "⌘B")}
        {b("italic", labels.italic, s.italic, mark("italic"), "⌘I")}
        {b("underline", labels.underline, s.underline, mark("underline"), "⌘U")}
        {b("strike", labels.strike, s.strike, mark("strike"), "⇧⌘X")}
        {b("code", labels.code, s.code, mark("code"), "⌘E")}
        <span aria-hidden="true" className="mx-[3px] h-[18px] w-px bg-border" />
        {b("link", labels.link, s.link, onLink, "⌘K")}
        {b("highlight", labels.highlight, s.highlight, mark("highlight"))}
      </div>
    </BubbleMenu>
  )
}

export { SelectionBubble }
