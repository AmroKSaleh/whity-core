"use client"

import * as React from "react"

import { Icon } from "../icon"
import { cn } from "../utils"
import type { CatalogueItem } from "./catalogue"
import type { EditorLabels } from "./labels"

/**
 * The grouped block list the / menu and the + inserter share (EditorInsert,
 * `.wy-slash`): an icon tile, the name, a one-line hint and the Markdown
 * shortcut; the active row is on the tint.
 */
function BlockList({
  items,
  active,
  onActive,
  onPick,
  labels,
  footer,
  search,
  id,
}: {
  items: CatalogueItem[]
  active: number
  onActive: (index: number) => void
  onPick: (item: CatalogueItem) => void
  labels: EditorLabels
  /** The hints along the bottom. */
  footer: React.ReactNode
  /** The + inserter's search box. */
  search?: React.ReactNode
  id: string
}) {
  const listRef = React.useRef<HTMLDivElement>(null)
  React.useEffect(() => {
    listRef.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: "nearest" })
  }, [active])

  let lastGroup = ""
  return (
    <div
      data-slot="block-list"
      className="grid w-[320px] gap-0.5 rounded-lg border border-border bg-popover p-1 text-sm text-popover-foreground shadow-float"
    >
      {search}
      <div ref={listRef} id={id} role="listbox" aria-label={labels.insert} className="grid max-h-[320px] gap-0.5 overflow-y-auto">
        {items.length === 0 && <div className="px-2 py-3 text-muted-foreground">{labels.noBlocks}</div>}
        {items.map((item, index) => {
          const heading = item.group !== lastGroup ? labels[item.group] : null
          lastGroup = item.group
          const on = index === active
          return (
            <React.Fragment key={item.id}>
              {heading && (
                <div role="presentation" className="px-2 pt-1.5 pb-0.5 text-xs font-medium text-muted-foreground">
                  {heading}
                </div>
              )}
              <div
                role="option"
                id={`${id}-${item.id}`}
                aria-selected={on}
                data-index={index}
                onMouseDown={(e) => e.preventDefault()}
                onMouseMove={() => onActive(index)}
                onClick={() => onPick(item)}
                className={cn(
                  "flex min-h-10 cursor-pointer items-center gap-2.5 rounded-control py-1 ps-1 pe-2",
                  on && "bg-primary-tint text-primary-tint-foreground"
                )}
              >
                <span
                  className={cn(
                    "grid size-8 shrink-0 place-items-center rounded-[8px] border border-border bg-card text-foreground",
                    on && "border-[color-mix(in_oklab,var(--primary)_40%,var(--border))] text-primary-tint-foreground"
                  )}
                >
                  <Icon name={item.icon} />
                </span>
                <span className="grid min-w-0 flex-1 leading-[1.3]">
                  {labels[item.label]}
                  <span className={cn("truncate text-xs text-muted-foreground", on && "text-current opacity-80")}>
                    {labels[item.hint]}
                  </span>
                </span>
                {item.markdown && <span className="font-mono text-2xs text-muted-foreground">{item.markdown}</span>}
              </div>
            </React.Fragment>
          )
        })}
      </div>
      <div className="-mx-1 -mb-1 mt-0.5 flex gap-3 border-t border-border px-2 pt-1.5 pb-1 text-2xs text-muted-foreground">
        {footer}
      </div>
    </div>
  )
}

export { BlockList }
