"use client"

import * as React from "react"
import type { Editor } from "@tiptap/core"
import { useEditorState } from "@tiptap/react"

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuShortcut,
  DropdownMenuTrigger,
} from "../dropdown-menu"
import { Icon } from "../icon"
import { IconButton } from "../icon-button"
import { cn } from "../utils"
import { CATALOGUE, applyItem, currentItem } from "./catalogue"
import { turnInto } from "./behaviour"
import type { EditorLabels } from "./labels"

const STYLE_IDS = ["text", "h1", "h2", "h3"]
const STYLE_SHORTCUTS: Record<string, string> = { text: "⌘⌥0", h1: "⌘⌥1", h2: "⌘⌥2", h3: "⌘⌥3" }

function Sep() {
  return <span aria-hidden="true" className="mx-1.5 h-5 w-px shrink-0 bg-border" />
}

/** A text dropdown in the bar: "Normal text ▾", "Insert ▾" (`.wy-edx__drop`). */
const BarDrop = React.forwardRef<HTMLButtonElement, React.ComponentProps<"button">>(function BarDrop(
  { className, children, ...props },
  ref
) {
  return (
    <button
      ref={ref}
      type="button"
      className={cn(
        "inline-flex h-7 shrink-0 cursor-pointer items-center gap-1 rounded-control-sm border-0 bg-transparent ps-2 pe-1.5 text-[0.8125rem] whitespace-nowrap text-foreground hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-40",
        className
      )}
      {...props}
    >
      {children}
      <Icon name="chevron-down" size={14} className="text-muted-foreground" />
    </button>
  )
})

/**
 * The fixed formatting toolbar, in Google Docs order (E.5, EditorShell):
 * undo and redo · style · bold, italic, underline, strikethrough · link,
 * highlight · lists · Insert ▾ · outline. Every icon button has a tooltip with
 * its shortcut; pressed states use the tint. Under 768px it scrolls sideways.
 */
function EditorToolbar({
  editor,
  labels,
  editable,
  outline,
  onOutline,
  onLink,
}: {
  editor: Editor
  labels: EditorLabels
  editable: boolean
  outline: boolean
  onOutline: () => void
  onLink: () => void
}) {
  const s = useEditorState({
    editor,
    selector: ({ editor: e }) => ({
      bold: e.isActive("bold"),
      italic: e.isActive("italic"),
      underline: e.isActive("underline"),
      strike: e.isActive("strike"),
      link: e.isActive("link"),
      highlight: e.isActive("highlight"),
      canUndo: e.can().undo(),
      canRedo: e.can().redo(),
      item: currentItem(e)?.id ?? "text",
    }),
  })
  const mark = (name: string) => () => editor.chain().focus().toggleMark(name).run()
  const list = (id: string) => () => {
    const item = CATALOGUE.find((i) => i.id === id)!
    if (s.item === id) turnInto(editor, "paragraph")
    else turnInto(editor, item.node.type, item.node.attrs)
  }
  const styleItem = CATALOGUE.find((i) => i.id === (STYLE_IDS.includes(s.item) ? s.item : "text"))!
  const tb = (icon: React.ComponentProps<typeof IconButton>["icon"], label: string, pressed: boolean, onClick: () => void, shortcut?: string) => (
    <IconButton
      icon={icon}
      label={label}
      size="sm"
      tooltip="bottom"
      shortcut={shortcut}
      pressed={pressed}
      disabled={!editable}
      onMouseDown={(e) => e.preventDefault()}
      onClick={onClick}
    />
  )

  return (
    <div
      role="toolbar"
      aria-label={labels.formatting}
      data-slot="editor-toolbar"
      className="flex h-11 items-center gap-0.5 overflow-x-auto border-b border-border bg-card px-3 [scrollbar-width:none]"
    >
      <IconButton icon="undo" label={labels.undo} size="sm" tooltip="bottom" shortcut="⌘Z" disabled={!editable || !s.canUndo} onMouseDown={(e) => e.preventDefault()} onClick={() => editor.chain().focus().undo().run()} />
      <IconButton icon="redo" label={labels.redo} size="sm" tooltip="bottom" shortcut="⇧⌘Z" disabled={!editable || !s.canRedo} onMouseDown={(e) => e.preventDefault()} onClick={() => editor.chain().focus().redo().run()} />
      <Sep />
      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <BarDrop aria-label={labels.styleMenu} disabled={!editable} className="min-w-28 justify-between">
            {styleItem.id === "text" ? labels.normalText : labels[styleItem.label]}
          </BarDrop>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" onCloseAutoFocus={(e) => { e.preventDefault(); editor.commands.focus() }}>
          {STYLE_IDS.map((id) => {
            const item = CATALOGUE.find((i) => i.id === id)!
            return (
              <DropdownMenuItem key={id} onSelect={() => turnInto(editor, item.node.type, item.node.attrs)} className={cn(s.item === id && "bg-primary-tint text-primary-tint-foreground")}>
                <Icon name={item.icon} /> {id === "text" ? labels.normalText : labels[item.label]}
                <DropdownMenuShortcut>{STYLE_SHORTCUTS[id]}</DropdownMenuShortcut>
              </DropdownMenuItem>
            )
          })}
        </DropdownMenuContent>
      </DropdownMenu>
      <Sep />
      {tb("bold", labels.bold, s.bold, mark("bold"), "⌘B")}
      {tb("italic", labels.italic, s.italic, mark("italic"), "⌘I")}
      {tb("underline", labels.underline, s.underline, mark("underline"), "⌘U")}
      {tb("strike", labels.strike, s.strike, mark("strike"), "⇧⌘X")}
      <Sep />
      {tb("link", labels.link, s.link, onLink, "⌘K")}
      {tb("highlight", labels.highlight, s.highlight, mark("highlight"))}
      <Sep />
      {tb("list-bullet", labels.blockBullet, s.item === "bullet", list("bullet"), "⇧⌘8")}
      {tb("list-numbered", labels.blockNumber, s.item === "number", list("number"), "⇧⌘7")}
      {tb("list-todo", labels.blockChecklist, s.item === "checklist", list("checklist"), "⇧⌘9")}
      <Sep />
      <DropdownMenu modal={false}>
        <DropdownMenuTrigger asChild>
          <BarDrop disabled={!editable}>
            <Icon name="plus" size={14} />
            {labels.insert}
          </BarDrop>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="start" onCloseAutoFocus={(e) => { e.preventDefault(); editor.commands.focus() }}>
          {CATALOGUE.map((item) => (
            <DropdownMenuItem key={item.id} onSelect={() => applyItem(editor, item)}>
              <Icon name={item.icon} /> {labels[item.label]}
              {item.markdown && <DropdownMenuShortcut>{item.markdown}</DropdownMenuShortcut>}
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
      <span className="min-w-2 flex-1" />
      <IconButton icon="outline" label={labels.outline} size="sm" tooltip="bottom" pressed={outline} onClick={onOutline} />
    </div>
  )
}

export { EditorToolbar }
