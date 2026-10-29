"use client"

import * as React from "react"
import type { Editor } from "@tiptap/core"
import { EditorContent, useEditor, useEditorState } from "@tiptap/react"

import { Badge } from "../badge"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "../dropdown-menu"
import { Icon, type IconName } from "../icon"
import { IconButton } from "../icon-button"
import { cn } from "../utils"
import { BlockList } from "./block-list"
import { SelectionBubble } from "./bubble"
import { applyItem, filterCatalogue } from "./catalogue"
import type { Actor, DocumentKind, EditorMode, WhityDocument } from "./contract"
import { fromEditorContent, toEditorContent } from "./convert"
import { DocumentBehaviour } from "./behaviour"
import { documentExtensions } from "./extensions"
import { BlockGutter } from "./gutter"
import { EDITOR_LABELS, fill, type EditorLabels } from "./labels"
import { LinkCard, LinkPopover } from "./link-tools"
import { SlashCommand, type SlashState } from "./slash"
import { EditorToolbar } from "./toolbar"
import { UnknownBlock } from "./schema"

export type SaveState =
  | { state: "saved"; at: string }
  | { state: "saving" }
  | { state: "offline" }
  | { state: "error" }

const KIND_ICON: Record<DocumentKind, IconName> = {
  template: "template",
  letter: "mail",
  report: "chart",
  note: "document",
  wiki: "world",
  label: "print",
}
const KIND_LABEL: Record<DocumentKind, keyof EditorLabels> = {
  template: "kindTemplate",
  letter: "kindLetter",
  report: "kindReport",
  note: "kindNote",
  wiki: "kindWiki",
  label: "kindTemplate",
}

export interface DocEditorProps {
  document: WhityDocument
  /** Debounced by the caller; fires with the whole document on every change. */
  onChange: (doc: WhityDocument) => void
  canEdit: boolean
  mode?: EditorMode
  onModeChange?: (mode: EditorMode) => void
  /** Where Back goes, and where the document lives ("Notes · Finance"). */
  back?: { label: string; onClick: () => void }
  path?: string
  save?: SaveState
  /** The command palette, for ⌘K with nothing selected (E.36, 2.1c). */
  onCommandPalette?: () => void
  /** The address "Copy link to block" copies. */
  blockUrl?: (blockId: string) => string
  /** Every string has an English default; Arabic comes in here. */
  labels?: Partial<EditorLabels>
  className?: string
}

function actorName(actor: Actor): string {
  return actor.kind === "system" ? "Whity" : actor.name
}

function countWords(editor: Editor): number {
  const text = editor.state.doc.textBetween(0, editor.state.doc.content.size, " ", " ")
  return text.split(/\s+/).filter(Boolean).length
}

/**
 * The document editor (section 18, EditorShell): header, the fixed toolbar,
 * the outline, the continuous sheet and the status bar. Blocks, the / menu,
 * the + inserter, Markdown shortcuts, the selection bubble, links and the
 * block gutter are this milestone's (M2); comments and suggestions, fields,
 * the page view, agents and plugin blocks join in M3–M7.
 */
function DocEditor({
  document: doc,
  onChange,
  canEdit,
  mode: modeProp,
  onModeChange,
  back,
  path,
  save,
  onCommandPalette,
  blockUrl = (id) => `${typeof window === "undefined" ? "" : window.location.href.split("#")[0]}#block-${id}`,
  labels: labelProp,
  className,
}: DocEditorProps) {
  const labels = React.useMemo(() => ({ ...EDITOR_LABELS, ...labelProp }), [labelProp])
  const mode: EditorMode = canEdit ? (modeProp === "viewing" ? "viewing" : "editing") : "viewing"
  const editable = mode === "editing"
  const [outlineOpen, setOutlineOpen] = React.useState(true)
  const [slash, setSlash] = React.useState<SlashState>({ open: false, query: "", range: null, rect: null })
  const [slashActive, setSlashActive] = React.useState(0)
  const [linkAt, setLinkAt] = React.useState<{ top: number; left: number } | null>(null)
  const [linkCard, setLinkCard] = React.useState<{ href: string; pos: number; top: number; left: number } | null>(null)
  const [menuAt, setMenuAt] = React.useState<number | null>(null)
  const contentRef = React.useRef<HTMLDivElement>(null)
  const latest = React.useRef(doc)
  latest.current = doc
  const slashKeys = React.useRef<(e: KeyboardEvent) => boolean>(() => false)
  const cardTimer = React.useRef<ReturnType<typeof setTimeout> | undefined>(undefined)

  const editor = useEditor({
    immediatelyRender: false,
    editable,
    extensions: [
      SlashCommand.configure({ onState: setSlash, onKeyDown: (e) => slashKeys.current(e) }),
      ...documentExtensions({ placeholder: labels.placeholder }).map((ext) =>
        ext.name === "documentBehaviour"
          ? DocumentBehaviour.configure({ onBlockMenu: setMenuAt })
          : ext.name === "unknownBlock"
            ? UnknownBlock.configure({ label: (type) => fill(labels.laterBlock, { block: type }) })
            : ext
      ),
    ],
    content: toEditorContent(doc.blocks),
    editorProps: {
      attributes: {
        class: "wy-doc",
        "aria-label": doc.title || labels.untitled,
        "aria-multiline": "true",
        role: "textbox",
      },
    },
    onUpdate: ({ editor: e }) => {
      onChange({ ...latest.current, blocks: fromEditorContent(e.getJSON()) })
    },
  })

  React.useEffect(() => {
    editor?.setEditable(editable)
  }, [editor, editable])

  const relative = React.useCallback((rect: { top: number; bottom: number; left: number }) => {
    const box = contentRef.current?.getBoundingClientRect()
    if (!box) return { top: 0, left: 0 }
    return { top: rect.bottom - box.top + 6, left: Math.max(0, rect.left - box.left) }
  }, [])

  const openLink = React.useCallback(
    (at?: number) => {
      if (!editor) return
      if (at !== undefined) editor.chain().focus().setTextSelection(at).extendMarkRange("link").run()
      const { from, to } = editor.state.selection
      if (from === to && !editor.isActive("link")) return
      const start = editor.view.coordsAtPos(from)
      setLinkCard(null)
      setLinkAt(relative(start))
    },
    [editor, relative]
  )

  // ⌘K (E.36): with text selected it adds a link; with nothing selected it opens the command palette.
  React.useEffect(() => {
    if (!editor) return
    const onKey = (e: KeyboardEvent) => {
      if (!(e.metaKey || e.ctrlKey) || e.key.toLowerCase() !== "k" || e.shiftKey || e.altKey) return
      if (!editor.view.hasFocus()) return
      e.preventDefault()
      e.stopPropagation()
      if (editable && !editor.state.selection.empty) openLink()
      else onCommandPalette?.()
    }
    const dom = editor.view.dom
    dom.addEventListener("keydown", onKey, true)
    return () => dom.removeEventListener("keydown", onKey, true)
  }, [editor, editable, openLink, onCommandPalette])

  // Hovering a link shows where it goes.
  React.useEffect(() => {
    if (!editor) return
    const dom = editor.view.dom
    const over = (e: MouseEvent) => {
      const a = (e.target as HTMLElement).closest?.("a[href]") as HTMLAnchorElement | null
      if (!a || !dom.contains(a)) return
      clearTimeout(cardTimer.current)
      const pos = editor.view.posAtDOM(a, 0)
      const r = a.getBoundingClientRect()
      setLinkCard({ href: a.getAttribute("href") ?? "", pos, ...relative(r) })
    }
    const out = (e: MouseEvent) => {
      if ((e.target as HTMLElement).closest?.("a[href]")) {
        cardTimer.current = setTimeout(() => setLinkCard(null), 300)
      }
    }
    dom.addEventListener("mouseover", over)
    dom.addEventListener("mouseout", out)
    return () => {
      dom.removeEventListener("mouseover", over)
      dom.removeEventListener("mouseout", out)
    }
  }, [editor, relative])

  const slashItems = React.useMemo(() => filterCatalogue(slash.query, labels, EDITOR_LABELS), [slash.query, labels])
  React.useEffect(() => setSlashActive(0), [slash.query, slash.open])
  const pickSlash = React.useCallback(
    (index: number) => {
      const item = slashItems[index]
      if (!editor || !item || !slash.range) return
      editor.chain().focus().deleteRange(slash.range).run()
      setSlash({ open: false, query: "", range: null, rect: null })
      applyItem(editor, item)
    },
    [editor, slash.range, slashItems]
  )
  slashKeys.current = (e) => {
    if (!slash.open) return false
    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
      const n = slashItems.length || 1
      setSlashActive((i) => (i + (e.key === "ArrowDown" ? 1 : n - 1)) % n)
      return true
    }
    if (e.key === "Enter") {
      if (!slashItems.length) return false
      pickSlash(slashActive)
      return true
    }
    return false
  }

  const meta = useEditorState({
    editor,
    selector: ({ editor: e }) => {
      if (!e) return { words: 0, headings: [] as { level: number; text: string; pos: number; current: boolean }[] }
      const caret = e.state.selection.from
      const headings: { level: number; text: string; pos: number; current: boolean }[] = []
      e.state.doc.forEach((node, pos) => {
        if (node.type.name === "heading") headings.push({ level: Number(node.attrs.level), text: node.textContent, pos, current: false })
      })
      const current = [...headings].reverse().find((h) => h.pos <= caret)
      if (current) current.current = true
      return { words: countWords(e), headings }
    },
  })

  const lineDir = doc.lang === "ar" ? "rtl" : "ltr"
  const saveText =
    !save
      ? null
      : save.state === "saved"
        ? fill(labels.saved, { time: save.at })
        : save.state === "saving"
          ? labels.saving
          : save.state === "offline"
            ? labels.offline
            : labels.saveFailed

  return (
    <div
      data-slot="doc-editor"
      dir={lineDir}
      lang={doc.lang}
      className={cn("grid h-full min-h-0 grid-rows-[auto_auto_1fr_auto] overflow-hidden bg-background text-sm text-foreground", className)}
    >
      {/* Header (56px) */}
      <header className="flex h-14 min-w-0 items-center gap-3 border-b border-border bg-card ps-2 pe-4">
        {back && <IconButton icon="chevron-left" label={back.label} tooltip="bottom" onClick={back.onClick} />}
        <span aria-hidden="true" className="grid size-8 shrink-0 place-items-center rounded-[8px] bg-primary-tint text-primary-tint-foreground">
          <Icon name={KIND_ICON[doc.kind]} />
        </span>
        <div className="grid min-w-0 flex-1 gap-px">
          <input
            aria-label={labels.documentTitle}
            value={doc.title}
            placeholder={labels.untitled}
            readOnly={!editable}
            onChange={(e) => onChange({ ...latest.current, title: e.target.value })}
            size={Math.max(12, Math.min(48, doc.title.length + 2))}
            className="-ms-[7px] w-fit max-w-full rounded-[6px] border border-transparent bg-transparent px-1.5 py-px text-[0.9375rem] leading-[1.3] font-semibold text-foreground outline-none hover:border-border focus-visible:border-input"
          />
          <div className="flex items-center gap-1.5 overflow-hidden text-xs whitespace-nowrap text-muted-foreground">
            {path && (
              <>
                {path}
                {saveText && <span aria-hidden="true">·</span>}
              </>
            )}
            {saveText && (
              <span role="status" className="inline-flex items-center gap-1">
                {save?.state === "saved" && <Icon name="check" size={12} />}
                {save?.state === "error" && <Icon name="alert" size={12} className="text-error-text" />}
                {saveText}
              </span>
            )}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <DropdownMenu modal={false}>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                aria-label={`${labels.modeMenu}: ${mode === "editing" ? labels.modeEditing : labels.modeViewing}`}
                className="inline-flex h-7 items-center gap-1.5 rounded-control-sm border border-input bg-card ps-2.5 pe-2 text-[0.8125rem] font-medium text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
              >
                <Icon name={mode === "editing" ? "edit" : "view"} size={14} />
                {mode === "editing" ? labels.modeEditing : labels.modeViewing}
                <Icon name="chevron-down" size={14} />
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              <DropdownMenuItem disabled={!canEdit} onSelect={() => onModeChange?.("editing")}>
                <Icon name="edit" />
                <span className="grid gap-px">
                  {labels.modeEditing}
                  <span className="text-xs text-muted-foreground">{labels.modeEditingHint}</span>
                </span>
              </DropdownMenuItem>
              <DropdownMenuItem onSelect={() => onModeChange?.("viewing")}>
                <Icon name="view" />
                <span className="grid gap-px">
                  {labels.modeViewing}
                  <span className="text-xs text-muted-foreground">{labels.modeViewingHint}</span>
                </span>
              </DropdownMenuItem>
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </header>

      {/* Toolbar (44px) */}
      {editor ? (
        <EditorToolbar editor={editor} labels={labels} editable={editable} outline={outlineOpen} onOutline={() => setOutlineOpen((o) => !o)} onLink={() => openLink()} />
      ) : (
        <div className="h-11 border-b border-border bg-card" />
      )}

      {/* Body: outline, canvas */}
      <div className={cn("relative grid min-h-0 overflow-auto", outlineOpen ? "xl:grid-cols-[220px_minmax(0,1fr)]" : "grid-cols-[minmax(0,1fr)]")}>
        {outlineOpen && (
          <nav aria-label={labels.outline} className="sticky top-0 hidden content-start gap-0.5 self-start py-6 ps-4 pe-3 text-[0.8125rem] xl:grid">
            <div className="px-2 pb-1.5 text-xs font-medium text-muted-foreground">{labels.outline}</div>
            {meta?.headings.length === 0 && <div className="px-2 text-xs text-muted-foreground">{labels.outlineEmpty}</div>}
            {meta?.headings.map((h) => (
              <a
                key={h.pos}
                href="#"
                aria-current={h.current ? "location" : undefined}
                onClick={(e) => {
                  e.preventDefault()
                  editor?.chain().focus().setTextSelection(h.pos + 1).scrollIntoView().run()
                }}
                className={cn(
                  "truncate rounded-[6px] border-s-2 border-transparent px-2 py-1 text-muted-foreground no-underline hover:bg-muted hover:text-foreground",
                  h.level === 2 && "ps-5",
                  h.level === 3 && "ps-8",
                  h.current && "rounded-s-none border-primary text-primary-tint-foreground"
                )}
              >
                {h.text || labels.untitled}
              </a>
            ))}
          </nav>
        )}
        <div className="min-w-0 px-6 pt-8 pb-16">
          <article className="relative mx-auto box-border max-w-[760px] rounded-card border border-border bg-card px-[72px] pt-14 pb-[72px] max-md:px-6">
            <h1
              className="m-0 mb-1.5 font-display text-[1.9375rem] leading-[1.15] font-semibold tracking-[-0.01em] text-foreground"
              dir="auto"
            >
              {doc.title || <span className="text-muted-foreground">{labels.untitled}</span>}
            </h1>
            <div className="mb-7 flex items-center gap-2 text-[0.8125rem] text-muted-foreground">
              <Badge variant="secondary">{labels[KIND_LABEL[doc.kind]]}</Badge>
            </div>
            <div ref={contentRef} className="relative">
              <EditorContent editor={editor} />
              {editor && (
                <>
                  <BlockGutter editor={editor} labels={labels} container={contentRef} menuAt={menuAt} onMenuAtChange={setMenuAt} blockUrl={blockUrl} />
                  <SelectionBubble editor={editor} labels={labels} onLink={() => openLink()} />
                </>
              )}
              {editor && slash.open && slash.rect && (
                <div className="absolute z-30" style={relative(slash.rect)}>
                  <BlockList
                    id="wy-slash"
                    items={slashItems}
                    active={Math.min(slashActive, Math.max(0, slashItems.length - 1))}
                    onActive={setSlashActive}
                    onPick={(item) => pickSlash(slashItems.indexOf(item))}
                    labels={labels}
                    footer={
                      slash.query && editor.state.selection.$from.parent.textContent.trim() !== `/${slash.query}` ? (
                        <span>{labels.slashInsertAfter}</span>
                      ) : (
                        <>
                          <span>{labels.slashMove}</span>
                          <span>{labels.slashInsert}</span>
                          <span>{labels.slashClose}</span>
                        </>
                      )
                    }
                  />
                </div>
              )}
              {editor && linkAt && <LinkPopover editor={editor} labels={labels} position={linkAt} onClose={() => setLinkAt(null)} />}
              {editor && linkCard && !linkAt && (
                <LinkCard
                  editor={editor}
                  labels={labels}
                  href={linkCard.href}
                  pos={linkCard.pos}
                  position={{ top: linkCard.top, left: linkCard.left }}
                  onEdit={(pos) => openLink(pos)}
                  onClose={() => setLinkCard(null)}
                  onPointerEnter={() => clearTimeout(cardTimer.current)}
                  onPointerLeave={() => setLinkCard(null)}
                />
              )}
            </div>
          </article>
        </div>
      </div>

      {/* Status bar (28px) */}
      <footer className="flex h-7 items-center gap-4 overflow-hidden border-t border-border bg-card px-4 text-xs whitespace-nowrap text-muted-foreground">
        <span>{meta?.words === 1 ? labels.word : fill(labels.words, { n: meta?.words ?? 0 })}</span>
        <span>{doc.lang === "ar" ? labels.arabic : labels.english}</span>
        <span>{labels[KIND_LABEL[doc.kind]]}</span>
        <span className="flex-1" />
        <span>{fill(labels.lastEdited, { name: actorName(doc.updatedBy), time: formatTime(doc.updatedAt) })}</span>
      </footer>
    </div>
  )
}

/** 24-hour times in Western digits (stage 5). */
function formatTime(iso: string): string {
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return iso
  return `${String(d.getHours()).padStart(2, "0")}:${String(d.getMinutes()).padStart(2, "0")}`
}


export { DocEditor }
