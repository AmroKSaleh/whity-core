"use client"

import * as React from "react"
import type { Editor } from "@tiptap/core"
import { NodeSelection } from "@tiptap/pm/state"

import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuShortcut,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
  DropdownMenuTrigger,
} from "../dropdown-menu"
import { Icon } from "../icon"
import { cn } from "../utils"
import { deleteBlock, duplicateBlock, moveBlockTo, turnInto } from "./behaviour"
import { BlockList } from "./block-list"
import { CATALOGUE, applyItem, filterCatalogue, type CatalogueItem } from "./catalogue"
import { EDITOR_LABELS, type EditorLabels } from "./labels"

/** Top padding of a block before its first line, so the gutter sits on that line. */
function firstLineOffset(el: HTMLElement): number {
  return parseFloat(getComputedStyle(el).paddingTop) || 0
}

interface Hovered {
  pos: number
  index: number
  top: number
  height: number
}

/**
 * The block gutter (E.8, EditorBlockChrome): hovering a block, or putting the
 * caret in it, shows + and the drag handle in the margin at its first line.
 * + opens the inserter for a new block below; the handle drags the block or,
 * clicked, opens its menu. Agents never use it: they only suggest (E.4).
 */
function BlockGutter({
  editor,
  labels,
  container,
  menuAt,
  onMenuAtChange,
  blockUrl,
}: {
  editor: Editor
  labels: EditorLabels
  /** The positioned element around the editable content. */
  container: React.RefObject<HTMLDivElement | null>
  /** A block position whose menu should open (Esc then Enter). */
  menuAt: number | null
  onMenuAtChange: (pos: number | null) => void
  /** The address "Copy link to block" copies. */
  blockUrl: (blockId: string) => string
}) {
  const [hovered, setHovered] = React.useState<Hovered | null>(null)
  const [inserter, setInserter] = React.useState<{ pos: number; query: string; active: number } | null>(null)
  const [drag, setDrag] = React.useState<{ from: number; x: number; y: number; dropIndex: number; dropTop: number; text: string } | null>(null)
  const pointerDown = React.useRef<{ x: number; y: number; pos: number } | null>(null)

  const measure = React.useCallback(
    (index: number): Hovered | null => {
      const wrap = container.current
      const el = editor.view.dom.children[index] as HTMLElement | undefined
      if (!wrap || !el) return null
      let pos = 0
      for (let i = 0; i < index; i++) pos += editor.state.doc.child(i).nodeSize
      const box = wrap.getBoundingClientRect()
      const r = el.getBoundingClientRect()
      return { pos, index, top: r.top - box.top + firstLineOffset(el), height: r.height }
    },
    [editor, container]
  )

  // Hovering a block shows its gutter.
  React.useEffect(() => {
    const wrap = container.current
    if (!wrap) return
    const onMove = (e: PointerEvent) => {
      if (drag) return
      const children = Array.from(editor.view.dom.children) as HTMLElement[]
      const index = children.findIndex((el) => {
        const r = el.getBoundingClientRect()
        return e.clientY >= r.top - 2 && e.clientY <= r.bottom + 2
      })
      if (index >= 0) setHovered(measure(index))
    }
    wrap.addEventListener("pointermove", onMove)
    return () => wrap.removeEventListener("pointermove", onMove)
  }, [editor, container, measure, drag])

  // So does putting the caret in it.
  React.useEffect(() => {
    const onSelection = () => {
      if (!editor.isFocused) return
      const { $from } = editor.state.selection
      setHovered(measure($from.depth === 0 ? $from.index(0) : $from.index(0)))
    }
    editor.on("selectionUpdate", onSelection)
    editor.on("update", onSelection)
    return () => {
      editor.off("selectionUpdate", onSelection)
      editor.off("update", onSelection)
    }
  }, [editor, measure])

  // Esc then Enter on a block opens its menu.
  React.useEffect(() => {
    if (menuAt === null) return
    const index = editor.state.doc.resolve(menuAt).index(0)
    setHovered(measure(index))
  }, [menuAt, editor, measure])

  if (!editor.isEditable) return null

  const dropIndexAt = (clientY: number) => {
    const wrap = container.current!
    const box = wrap.getBoundingClientRect()
    const children = Array.from(editor.view.dom.children) as HTMLElement[]
    let index = children.length
    let top = children.length ? children[children.length - 1].getBoundingClientRect().bottom - box.top : 0
    for (let i = 0; i < children.length; i++) {
      const r = children[i].getBoundingClientRect()
      if (clientY < r.top + r.height / 2) {
        index = i
        top = r.top - box.top
        break
      }
    }
    return { index, top }
  }

  const startPointer = (e: React.PointerEvent) => {
    if (!hovered || e.button !== 0) return
    pointerDown.current = { x: e.clientX, y: e.clientY, pos: hovered.pos }
    ;(e.target as HTMLElement).setPointerCapture(e.pointerId)
  }
  const movePointer = (e: React.PointerEvent) => {
    const down = pointerDown.current
    if (!down) return
    if (!drag && Math.hypot(e.clientX - down.x, e.clientY - down.y) < 4) return
    const node = editor.state.doc.nodeAt(down.pos)
    const box = container.current!.getBoundingClientRect()
    const { index, top } = dropIndexAt(e.clientY)
    const el = editor.view.nodeDOM(down.pos) as HTMLElement | null
    el?.classList.add("is-dragging")
    setDrag({ from: down.pos, x: e.clientX - box.left, y: e.clientY - box.top, dropIndex: index, dropTop: top, text: node?.textContent.slice(0, 80) ?? "" })
  }
  const endPointer = () => {
    const down = pointerDown.current
    pointerDown.current = null
    if (!down) return
    if (!drag) {
      onMenuAtChange(down.pos)
      return
    }
    ;(editor.view.nodeDOM(down.pos) as HTMLElement | null)?.classList.remove("is-dragging")
    const fromIndex = editor.state.doc.resolve(down.pos).index(0)
    // Dropping below its own place: the block isn't counted once it's lifted out.
    const target = drag.dropIndex > fromIndex ? drag.dropIndex - 1 : drag.dropIndex
    setDrag(null)
    if (target !== fromIndex) moveBlockTo(editor, down.pos, target)
  }

  const blockAt = (pos: number) => editor.state.doc.nodeAt(pos)
  const select = (pos: number) => editor.chain().focus().command(({ tr }) => {
    tr.setSelection(NodeSelection.create(tr.doc, pos))
    return true
  }).run()
  const inside = (pos: number) => editor.chain().focus().setTextSelection(pos + 1).run()

  const menuOpen = menuAt !== null
  const menuBlock = menuOpen ? blockAt(menuAt) : null
  const inserterItems: CatalogueItem[] = inserter ? filterCatalogue(inserter.query, labels, EDITOR_LABELS) : []

  return (
    <>
      {hovered && !drag && (
        <div
          data-slot="block-gutter"
          className="absolute end-full z-10 flex pe-1.5"
          style={{ top: hovered.top }}
          onPointerDown={(e) => e.stopPropagation()}
        >
          <button
            type="button"
            aria-label={labels.addBelow}
            className={cn(
              "grid size-6 place-items-center rounded-[6px] text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring",
              inserter && "bg-muted text-foreground"
            )}
            onMouseDown={(e) => e.preventDefault()}
            onClick={() => setInserter({ pos: hovered.pos, query: "", active: 0 })}
          >
            <Icon name="plus" />
          </button>
          <DropdownMenu
            modal={false}
            open={menuOpen && menuAt === hovered.pos}
            onOpenChange={(open) => onMenuAtChange(open ? hovered.pos : null)}
          >
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                aria-label={labels.dragHandle}
                className="grid h-6 w-[18px] cursor-grab touch-none place-items-center rounded-[6px] text-muted-foreground hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-ring"
                onPointerDown={(e) => {
                  e.preventDefault()
                  startPointer(e)
                }}
                onPointerMove={movePointer}
                onPointerUp={endPointer}
                onKeyDown={(e) => {
                  if (e.key === "Enter" || e.key === " ") {
                    e.preventDefault()
                    onMenuAtChange(hovered.pos)
                  }
                }}
              >
                <Icon name="drag" />
              </button>
            </DropdownMenuTrigger>
            {menuBlock && menuAt !== null && (
              <DropdownMenuContent
                align="start"
                aria-label={labels.blockMenu}
                onCloseAutoFocus={(e) => {
                  e.preventDefault()
                  editor.commands.focus()
                }}
              >
                {menuBlock.isTextblock && (
                  <DropdownMenuSub>
                    <DropdownMenuSubTrigger>
                      <Icon name="sync" /> {labels.turnInto}
                    </DropdownMenuSubTrigger>
                    <DropdownMenuSubContent>
                      {CATALOGUE.filter((i) => i.textBlock).map((item) => (
                        <DropdownMenuItem
                          key={item.id}
                          onSelect={() => {
                            inside(menuAt)
                            turnInto(editor, item.node.type, item.node.attrs)
                          }}
                        >
                          <Icon name={item.icon} /> {labels[item.label]}
                        </DropdownMenuItem>
                      ))}
                    </DropdownMenuSubContent>
                  </DropdownMenuSub>
                )}
                <DropdownMenuItem onSelect={() => duplicateBlock(editor, menuAt)}>
                  <Icon name="copy" /> {labels.duplicate}
                  <DropdownMenuShortcut>⌘D</DropdownMenuShortcut>
                </DropdownMenuItem>
                <DropdownMenuItem
                  onSelect={() => {
                    const id = menuBlock.attrs.id as string | null
                    if (id) void navigator.clipboard?.writeText(blockUrl(id))
                  }}
                >
                  <Icon name="link" /> {labels.copyLinkToBlock}
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem
                  disabled={editor.state.doc.resolve(menuAt).index(0) === 0}
                  onSelect={() => moveBlockTo(editor, menuAt, editor.state.doc.resolve(menuAt).index(0) - 1)}
                >
                  <Icon name="arrow-up" /> {labels.moveUp}
                  <DropdownMenuShortcut>⌥⇧↑</DropdownMenuShortcut>
                </DropdownMenuItem>
                <DropdownMenuItem
                  disabled={editor.state.doc.resolve(menuAt).index(0) >= editor.state.doc.childCount - 1}
                  onSelect={() => moveBlockTo(editor, menuAt, editor.state.doc.resolve(menuAt).index(0) + 1)}
                >
                  <Icon name="arrow-down" /> {labels.moveDown}
                  <DropdownMenuShortcut>⌥⇧↓</DropdownMenuShortcut>
                </DropdownMenuItem>
                <DropdownMenuSeparator />
                <DropdownMenuItem variant="destructive" onSelect={() => deleteBlock(editor, menuAt)}>
                  <Icon name="delete" /> {labels.delete}
                  <DropdownMenuShortcut>Del</DropdownMenuShortcut>
                </DropdownMenuItem>
              </DropdownMenuContent>
            )}
          </DropdownMenu>
        </div>
      )}

      {inserter && hovered && (
        <div className="absolute start-0 z-30" style={{ top: hovered.top + 28 }}>
          <BlockList
            id="wy-inserter"
            items={inserterItems}
            active={Math.min(inserter.active, Math.max(0, inserterItems.length - 1))}
            onActive={(active) => setInserter({ ...inserter, active })}
            onPick={(item) => {
              const node = blockAt(inserter.pos)
              setInserter(null)
              if (node) applyItem(editor, item, { after: inserter.pos + node.nodeSize })
            }}
            labels={labels}
            footer={
              <>
                <span>{labels.slashMove}</span>
                <span>{labels.slashInsert}</span>
                <span>{labels.slashClose}</span>
              </>
            }
            search={
              <div className="-mx-1 -mt-1 mb-1 flex h-9 items-center gap-2 border-b border-border px-2.5 text-muted-foreground">
                <Icon name="search" size={14} />
                <input
                  autoFocus
                  aria-label={labels.searchBlocks}
                  aria-controls="wy-inserter"
                  placeholder={labels.searchBlocks}
                  value={inserter.query}
                  onChange={(e) => setInserter({ ...inserter, query: e.target.value, active: 0 })}
                  onBlur={() => setInserter(null)}
                  onKeyDown={(e) => {
                    if (e.key === "ArrowDown" || e.key === "ArrowUp") {
                      e.preventDefault()
                      const n = inserterItems.length || 1
                      setInserter({ ...inserter, active: (inserter.active + (e.key === "ArrowDown" ? 1 : n - 1)) % n })
                    } else if (e.key === "Enter") {
                      e.preventDefault()
                      const item = inserterItems[inserter.active]
                      const node = blockAt(inserter.pos)
                      setInserter(null)
                      if (item && node) applyItem(editor, item, { after: inserter.pos + node.nodeSize })
                    } else if (e.key === "Escape") {
                      e.preventDefault()
                      setInserter(null)
                      select(inserter.pos)
                    }
                  }}
                  className="h-full min-w-0 flex-1 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground"
                />
              </div>
            }
          />
        </div>
      )}

      {drag && (
        <>
          <div aria-hidden="true" className="pointer-events-none absolute inset-x-0 z-20 h-0.5 rounded-[1px] bg-primary" style={{ top: drag.dropTop - 1 }} />
          <div
            aria-hidden="true"
            className="pointer-events-none absolute z-30 max-w-[420px] -rotate-1 truncate rounded-control border border-border bg-card px-2.5 py-1.5 text-base shadow-float"
            style={{ top: drag.y + 8, left: drag.x + 12 }}
          >
            {drag.text || "…"}
          </div>
        </>
      )}
    </>
  )
}

export { BlockGutter }
