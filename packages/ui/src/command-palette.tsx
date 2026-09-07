"use client"

import * as React from "react"

import { cn } from "./utils"

/**
 * A searchable, keyboard-driven command list — the `/` or Ctrl-K surface.
 *
 * BUILT FROM PRIMITIVES RATHER THAN `cmdk`. The platform's stated selling
 * point is a lean, near-zero-dependency stack (ADR 0003), and what a palette
 * actually needs — filter a list, move a cursor, run a callback — is less code
 * than the wiring a dependency would take. Nothing here is novel; the value is
 * in not owing anyone an upgrade for it.
 *
 * PRESENTATIONAL AND CONTROLLED. It holds the query and the cursor, because
 * those die with the dialog; it holds no notion of what a command IS. The
 * caller supplies items and decides what running one means, which is what lets
 * the document designer feed it a flattened menu registry while another screen
 * feeds it something else entirely.
 *
 * COPY ARRIVES AS PROPS, with English defaults, for the reason every component
 * in this package does: `@amroksaleh/ui` is published standalone and may not
 * depend on the i18n feature (#758). Translating is the consumer's job.
 */

export interface CommandPaletteItem {
  id: string
  label: string
  /** Heading this item sits under. Items are shown in the order given, grouped by first appearance. */
  group?: string
  /** Rendered right-aligned, e.g. "Ctrl+S". Never used for matching. */
  shortcut?: string
  icon?: React.ReactNode
  /**
   * Extra words that should MATCH but need not be shown — a block's scope, an
   * element's other name ("picture" finding "image").
   */
  keywords?: string
  /**
   * Shown greyed and skipped by the cursor, never hidden.
   *
   * A command absent from the list reads as "this editor cannot do that"; a
   * command present and greyed reads as "not right now", which is the true
   * statement and the one that stops somebody hunting through menus for
   * something that was never going to be available.
   */
  disabled?: boolean
  onSelect: () => void
}

export interface CommandPaletteProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  items: CommandPaletteItem[]
  placeholder?: string
  emptyLabel?: string
  /** Accessible name for the dialog. */
  label?: string
}

/** Case-insensitive subsequence-free substring match over label + keywords. */
function matches(item: CommandPaletteItem, query: string): boolean {
  if (query === "") return true
  const q = query.toLowerCase()
  return (
    item.label.toLowerCase().includes(q) ||
    (item.group?.toLowerCase().includes(q) ?? false) ||
    (item.keywords?.toLowerCase().includes(q) ?? false)
  )
}

export function CommandPalette({
  open,
  onOpenChange,
  items,
  placeholder = "Type a command…",
  emptyLabel = "No matching command",
  label = "Command palette",
}: CommandPaletteProps) {
  const [query, setQuery] = React.useState("")
  const [cursor, setCursor] = React.useState(0)
  const inputRef = React.useRef<HTMLInputElement>(null)
  const listRef = React.useRef<HTMLDivElement>(null)

  const shown = React.useMemo(() => items.filter((i) => matches(i, query)), [items, query])

  // The indices the cursor may land on. A disabled item is displayed but never
  // selected, so navigation skips it rather than stopping on something Enter
  // would silently ignore.
  const selectable = React.useMemo(
    () => shown.map((item, index) => (item.disabled === true ? -1 : index)).filter((i) => i >= 0),
    [shown]
  )

  // Reset on open, and whenever the query changes the previous cursor is
  // meaningless — it pointed into a different list.
  React.useEffect(() => {
    if (open) {
      setQuery("")
      setCursor(0)
      // Focus after paint; the input does not exist until this renders.
      const id = requestAnimationFrame(() => inputRef.current?.focus())
      return () => cancelAnimationFrame(id)
    }
    return undefined
  }, [open])

  React.useEffect(() => {
    setCursor(selectable[0] ?? 0)
  }, [query, selectable])

  // Escape at the DOCUMENT level, not only on the dialog.
  //
  // Focus arrives a frame late (the input does not exist until this renders),
  // so an Escape pressed in that gap would reach whatever had focus before —
  // in the document designer, a window handler that deselects the canvas — and
  // the palette would stay open having eaten the keystroke. Rare, and exactly
  // the kind of race that shows up as a flaky test rather than a bug report.
  React.useEffect(() => {
    if (!open) return undefined;
    const onEscape = (e: KeyboardEvent) => {
      if (e.key !== 'Escape') return
      e.preventDefault()
      e.stopPropagation()
      onOpenChange(false)
    }
    // Capture phase: ahead of any window listener the host already has.
    document.addEventListener('keydown', onEscape, true)
    return () => document.removeEventListener('keydown', onEscape, true)
  }, [open, onOpenChange])

  // Keep the highlighted row in view when arrowing past the fold.
  React.useEffect(() => {
    if (!open) return
    const el = listRef.current?.querySelector<HTMLElement>(`[data-index="${cursor}"]`)
    el?.scrollIntoView({ block: "nearest" })
  }, [cursor, open])

  if (!open) return null

  const step = (delta: number) => {
    if (selectable.length === 0) return
    const at = selectable.indexOf(cursor)
    const next = at === -1 ? 0 : (at + delta + selectable.length) % selectable.length
    setCursor(selectable[next])
  }

  const run = (item: CommandPaletteItem | undefined) => {
    if (!item || item.disabled === true) return
    // Close FIRST. A command that opens a dialog of its own would otherwise
    // find this one still mounted and fighting it for focus.
    onOpenChange(false)
    item.onSelect()
  }

  const onKeyDown = (e: React.KeyboardEvent) => {
    if (e.key === "ArrowDown") {
      e.preventDefault()
      step(1)
      return
    }
    if (e.key === "ArrowUp") {
      e.preventDefault()
      step(-1)
      return
    }
    if (e.key === "Enter") {
      e.preventDefault()
      run(shown[cursor])
      return
    }
    if (e.key === "Escape") {
      e.preventDefault()
      onOpenChange(false)
    }
    // Everything else falls through to the input, including "/" — a palette
    // you cannot type a slash into would be unable to search for anything
    // named with one.
  }

  let lastGroup: string | undefined

  return (
    <div
      className="fixed inset-0 z-50 flex items-start justify-center bg-black/40 pt-[12vh]"
      // A backdrop click closes, matching every other dismissible surface. The
      // dialog itself stops propagation below.
      onMouseDown={() => onOpenChange(false)}
      data-testid="command-palette-backdrop"
    >
      <div
        role="dialog"
        aria-modal="true"
        aria-label={label}
        className="w-full max-w-xl overflow-hidden rounded-lg border border-border bg-popover shadow-lg"
        onMouseDown={(e) => e.stopPropagation()}
        onKeyDown={onKeyDown}
        data-testid="command-palette"
      >
        <input
          ref={inputRef}
          value={query}
          onChange={(e) => setQuery(e.target.value)}
          placeholder={placeholder}
          aria-label={label}
          role="combobox"
          aria-expanded
          aria-controls="command-palette-list"
          aria-activedescendant={shown[cursor] ? `command-item-${shown[cursor].id}` : undefined}
          className="w-full border-b border-border bg-transparent px-4 py-3 text-sm outline-none placeholder:text-muted-foreground"
          data-testid="command-palette-input"
        />

        <div
          ref={listRef}
          id="command-palette-list"
          role="listbox"
          aria-label={label}
          className="max-h-80 overflow-y-auto p-1"
        >
          {shown.length === 0 && (
            <p className="px-3 py-6 text-center text-sm text-muted-foreground" data-testid="command-palette-empty">
              {emptyLabel}
            </p>
          )}

          {shown.map((item, index) => {
            const header = item.group !== undefined && item.group !== lastGroup ? item.group : null
            lastGroup = item.group

            return (
              <React.Fragment key={item.id}>
                {header !== null && (
                  <div className="px-3 pb-1 pt-3 text-[0.625rem] font-medium uppercase tracking-wide text-muted-foreground/70">
                    {header}
                  </div>
                )}
                <div
                  id={`command-item-${item.id}`}
                  role="option"
                  aria-selected={index === cursor}
                  aria-disabled={item.disabled === true}
                  data-index={index}
                  data-testid={`command-item-${item.id}`}
                  onMouseEnter={() => item.disabled !== true && setCursor(index)}
                  onClick={() => run(item)}
                  className={cn(
                    "flex cursor-pointer items-center gap-2 rounded-md px-3 py-2 text-sm",
                    index === cursor && item.disabled !== true && "bg-accent text-accent-foreground",
                    item.disabled === true && "cursor-not-allowed opacity-45"
                  )}
                >
                  {item.icon !== undefined && <span className="shrink-0 text-muted-foreground">{item.icon}</span>}
                  <span className="min-w-0 flex-1 truncate">{item.label}</span>
                  {item.shortcut !== undefined && (
                    <span className="shrink-0 text-xs text-muted-foreground">{item.shortcut}</span>
                  )}
                </div>
              </React.Fragment>
            )
          })}
        </div>
      </div>
    </div>
  )
}
