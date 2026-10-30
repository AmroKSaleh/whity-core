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
import { Popover, PopoverContent, PopoverTrigger } from "../popover"
import { ToggleGroup } from "../toggle-group"
import { cn } from "../utils"
import { CATALOGUE, applyItem, currentItem } from "./catalogue"
import { turnInto } from "./behaviour"
import { FieldPicker, flatFields } from "./field-picker"
import { filterFields, type FieldDef, type FieldGroup } from "./fields"
import { fill, type EditorLabels } from "./labels"

/** E.7: the page view's zoom steps; "fit" fits the page to the canvas width. */
export type PageZoom = "fit" | 50 | 75 | 100 | 125
export const ZOOM_STEPS: PageZoom[] = ["fit", 50, 75, 100, 125]
export type EditorView = "continuous" | "page"

/** Inserts a merge field at the caret as one character, followed by a space. */
export function insertField(editor: Editor, field: FieldDef) {
  editor
    .chain()
    .focus()
    .insertContent([
      { type: "field", attrs: { path: field.path, label: field.label, required: field.required ? true : null } },
      { type: "text", text: " " },
    ])
    .run()
}

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

/** Field ▾ (EditorInline): the field picker with its own search box. */
function FieldMenu({ editor, groups, labels, disabled }: { editor: Editor; groups: FieldGroup[]; labels: EditorLabels; disabled: boolean }) {
  const [open, setOpen] = React.useState(false)
  const [query, setQuery] = React.useState("")
  const [active, setActive] = React.useState(0)
  const shown = React.useMemo(() => filterFields(groups, query), [groups, query])
  const flat = flatFields(shown)
  React.useEffect(() => setActive(0), [query, open])
  const pick = (field: FieldDef) => {
    setOpen(false)
    setQuery("")
    insertField(editor, field)
  }
  return (
    <Popover open={open} onOpenChange={setOpen}>
      <PopoverTrigger asChild>
        <BarDrop disabled={disabled}>
          <Icon name="field" size={14} />
          {labels.field}
        </BarDrop>
      </PopoverTrigger>
      <PopoverContent align="start" className="w-auto border-0 bg-transparent p-0 shadow-none" onCloseAutoFocus={(e) => e.preventDefault()}>
        <FieldPicker
          id="wy-fieldmenu"
          groups={shown}
          active={Math.min(active, Math.max(0, flat.length - 1))}
          onActive={setActive}
          onPick={pick}
          labels={labels}
          search={
            <label className="flex items-center gap-1.5 border-b border-border px-2 pb-1.5 pt-1 text-muted-foreground">
              <Icon name="search" size={14} />
              <input
                autoFocus
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                onKeyDown={(e) => {
                  const n = flat.length || 1
                  if (e.key === "ArrowDown" || e.key === "ArrowUp") {
                    e.preventDefault()
                    setActive((i) => (i + (e.key === "ArrowDown" ? 1 : n - 1)) % n)
                  } else if (e.key === "Enter" && flat[active]) {
                    e.preventDefault()
                    pick(flat[active])
                  }
                }}
                placeholder={labels.fieldSearch}
                aria-label={labels.fieldSearch}
                aria-controls="wy-fieldmenu"
                aria-activedescendant={flat.length ? `wy-fieldmenu-${active}` : undefined}
                className="h-7 min-w-0 flex-1 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
              />
            </label>
          }
        />
      </PopoverContent>
    </Popover>
  )
}

/**
 * The fixed formatting toolbar, in Google Docs order (E.5, EditorShell):
 * undo and redo · style · bold, italic, underline, strikethrough · link,
 * highlight · lists · Insert ▾ · Field ▾ · zoom, view, outline. Every icon
 * button has a tooltip with its shortcut; pressed states use the tint. Under
 * 768px it scrolls sideways.
 */
function EditorToolbar({
  editor,
  labels,
  editable,
  outline,
  onOutline,
  onLink,
  fields,
  view,
  onView,
  zoom,
  onZoom,
  sheet,
}: {
  editor: Editor
  labels: EditorLabels
  editable: boolean
  outline: boolean
  onOutline: () => void
  onLink: () => void
  /** The template's fields; Field ▾ shows only when there are some. */
  fields?: FieldGroup[]
  view?: EditorView
  /** Omitted when the document can't switch (label sheets are page view only, E.43). */
  onView?: (view: EditorView) => void
  zoom?: PageZoom
  onZoom?: (zoom: PageZoom) => void
  /** Label sheets: the sheet setup, beside the zoom (E.43). */
  sheet?: React.ReactNode
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
      {fields && fields.length > 0 && <FieldMenu editor={editor} groups={fields} labels={labels} disabled={!editable} />}
      <span className="min-w-2 flex-1" />
      {sheet}
      {view === "page" && onZoom && (
        <DropdownMenu modal={false}>
          <DropdownMenuTrigger asChild>
            <BarDrop aria-label={labels.zoom}>{zoomLabel(zoom ?? "fit", labels)}</BarDrop>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            {ZOOM_STEPS.map((step) => (
              <DropdownMenuItem key={String(step)} onSelect={() => onZoom(step)} className={cn(step === (zoom ?? "fit") && "bg-primary-tint text-primary-tint-foreground")}>
                {zoomLabel(step, labels)}
              </DropdownMenuItem>
            ))}
          </DropdownMenuContent>
        </DropdownMenu>
      )}
      {view && onView && (
        <ToggleGroup
          size="sm"
          label={labels.view}
          required
          value={view}
          onChange={(v) => v && onView(v as EditorView)}
          options={[
            { value: "continuous", label: labels.viewContinuous, icon: "continuous", iconOnly: true },
            { value: "page", label: labels.viewPage, icon: "page", iconOnly: true },
          ]}
        />
      )}
      <IconButton icon="outline" label={labels.outline} size="sm" tooltip="bottom" pressed={outline} onClick={onOutline} />
    </div>
  )
}

function zoomLabel(zoom: PageZoom, labels: EditorLabels): string {
  return zoom === "fit" ? labels.zoomFit : fill(labels.zoomPercent, { n: zoom })
}

export { EditorToolbar }
