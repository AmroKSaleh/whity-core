"use client"

import * as React from "react"
import type { Editor } from "@tiptap/core"
import { getMarkRange } from "@tiptap/core"

import { Button } from "../button"
import { Icon } from "../icon"
import { IconButton } from "../icon-button"
import type { EditorLabels } from "./labels"

/** A bare domain gets https:// (EditorInline). Mail and site-relative links stay as typed. */
export function normaliseHref(input: string): string {
  const href = input.trim()
  if (!href) return ""
  if (/^(?:[a-z][a-z0-9+.-]*:|\/|#)/i.test(href)) return href
  if (looksLikeEmail(href)) return `mailto:${href}`
  return `https://${href}`
}

/**
 * name@domain.tld: one @, a non-empty name, and a dot inside the domain, with
 * no spaces. Checked by splitting rather than one regular expression, which
 * backtracks badly on long hostile input (CodeQL js/polynomial-redos).
 */
function looksLikeEmail(text: string): boolean {
  if (/\s/.test(text)) return false
  const at = text.indexOf("@")
  if (at <= 0 || at !== text.lastIndexOf("@")) return false
  const domain = text.slice(at + 1)
  const dot = domain.indexOf(".")
  return dot > 0 && dot < domain.length - 1
}

/** Only web, mail and in-app links: never javascript: or data: (the href lands in an <a>). */
export function isSafeHref(href: string): boolean {
  return /^(?:https?:|mailto:|tel:|\/|#)/i.test(href)
}

const FLOAT = "absolute z-30 flex items-center gap-1.5 rounded-lg border border-border bg-popover shadow-float"

/** The address field at the selection (⌘K with text selected, or the bubble's link). */
function LinkPopover({
  editor,
  labels,
  position,
  onClose,
}: {
  editor: Editor
  labels: EditorLabels
  position: { top: number; left: number }
  onClose: () => void
}) {
  const [value, setValue] = React.useState(() => String(editor.getAttributes("link").href ?? ""))
  const input = React.useRef<HTMLInputElement>(null)
  React.useEffect(() => input.current?.focus(), [])

  const apply = () => {
    const href = normaliseHref(value)
    const chain = editor.chain().focus().extendMarkRange("link")
    if (!href) chain.unsetMark("link").run()
    else if (isSafeHref(href)) chain.setMark("link", { href }).run()
    onClose()
  }

  return (
    <div data-slot="link-popover" className={`${FLOAT} w-[360px] p-1.5`} style={position}>
      <Icon name="link" className="ms-1 text-muted-foreground" />
      <input
        ref={input}
        aria-label={labels.linkAddress}
        value={value}
        dir="ltr"
        onChange={(e) => setValue(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === "Enter") {
            e.preventDefault()
            apply()
          } else if (e.key === "Escape") {
            e.preventDefault()
            onClose()
            editor.commands.focus()
          }
        }}
        className="h-7 min-w-0 flex-1 rounded-control-sm border border-input bg-card px-2 text-[0.8125rem] outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring"
      />
      <Button size="sm" variant="outline" onMouseDown={(e) => e.preventDefault()} onClick={apply}>
        {labels.linkApply}
      </Button>
    </div>
  )
}

/** Hovering a link shows its address with copy, edit and remove. */
function LinkCard({
  editor,
  labels,
  href,
  pos,
  position,
  onEdit,
  onClose,
  onPointerEnter,
  onPointerLeave,
}: {
  editor: Editor
  labels: EditorLabels
  href: string
  /** A document position inside the link. */
  pos: number
  position: { top: number; left: number }
  onEdit: (pos: number) => void
  onClose: () => void
  onPointerEnter: () => void
  onPointerLeave: () => void
}) {
  const remove = () => {
    const range = getMarkRange(editor.state.doc.resolve(pos), editor.schema.marks.link)
    if (range) editor.chain().focus().setTextSelection(range).unsetMark("link").run()
    onClose()
  }
  return (
    <div
      data-slot="link-card"
      className={`${FLOAT} max-w-[360px] py-1 ps-2.5 pe-1 text-[0.8125rem]`}
      style={position}
      onPointerEnter={onPointerEnter}
      onPointerLeave={onPointerLeave}
    >
      <Icon name="external" size={14} className="text-muted-foreground" />
      <a href={href} target="_blank" rel="noopener noreferrer" dir="ltr" className="truncate text-primary" aria-label={labels.linkOpen}>
        {href}
      </a>
      <IconButton icon="copy" label={labels.linkCopy} size="sm" onClick={() => void navigator.clipboard?.writeText(href)} />
      <IconButton icon="edit" label={labels.linkEdit} size="sm" onClick={() => onEdit(pos)} />
      {editor.isEditable && <IconButton icon="close" label={labels.linkRemove} size="sm" onClick={remove} />}
    </div>
  )
}

export { LinkCard, LinkPopover }
