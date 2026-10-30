"use client"

import * as React from "react"

import { Icon } from "../icon"
import { cn } from "../utils"
import type { FieldDef, FieldGroup } from "./fields"
import type { EditorLabels } from "./labels"

/**
 * The field picker (EditorInline): a searchable list grouped by record, each
 * field as its chip with a sample value. Typing {{ fills the query from the
 * text; Field ▾ in the toolbar gives it a search box instead.
 */
function FieldPicker({
  groups,
  query,
  active,
  onActive,
  onPick,
  labels,
  search,
  id,
}: {
  /** Already filtered by the query. */
  groups: FieldGroup[]
  /** What was typed after {{, shown at the top. */
  query?: string
  active: number
  onActive: (index: number) => void
  onPick: (field: FieldDef) => void
  labels: EditorLabels
  /** Field ▾'s own search box, in place of the query line. */
  search?: React.ReactNode
  id: string
}) {
  const listRef = React.useRef<HTMLDivElement>(null)
  React.useEffect(() => {
    listRef.current?.querySelector(`[data-index="${active}"]`)?.scrollIntoView({ block: "nearest" })
  }, [active])

  let index = -1
  return (
    <div
      data-slot="field-picker"
      className="wy-fpick grid w-[300px] gap-0.5 rounded-lg border border-border bg-popover p-1 text-sm text-popover-foreground shadow-float"
    >
      {search ??
        (query !== undefined && (
          <div className="flex items-center gap-1.5 px-2 pt-1 pb-1.5 text-xs text-muted-foreground">
            <Icon name="search" size={14} />
            {query ? <b className="font-medium text-foreground">{query}</b> : labels.fieldSearch}
          </div>
        ))}
      <div ref={listRef} id={id} role="listbox" aria-label={labels.fieldPicker} className="grid max-h-[320px] gap-0.5 overflow-y-auto">
        {groups.length === 0 && <div className="px-2 py-3 text-muted-foreground">{labels.noFields}</div>}
        {groups.map((group) => (
          <div key={group.group} role="group" aria-label={group.group} className="grid gap-0.5">
            <div role="presentation" className="px-2 pt-1.5 pb-0.5 text-xs font-medium text-muted-foreground">
              {group.group}
            </div>
            {group.items.map((field) => {
              index += 1
              const i = index
              const on = i === active
              return (
                <div
                  key={field.path}
                  role="option"
                  id={`${id}-${i}`}
                  aria-selected={on}
                  data-index={i}
                  title={field.path}
                  onMouseDown={(e) => e.preventDefault()}
                  onMouseMove={() => onActive(i)}
                  onClick={() => onPick(field)}
                  className={cn(
                    "wy-fpick__it flex cursor-pointer items-center gap-2 rounded-control px-2 py-1.5",
                    on && "bg-primary-tint text-primary-tint-foreground"
                  )}
                >
                  <span className="wy-mf">{field.label}</span>
                  {field.sample && (
                    <span dir="auto" className="ms-auto max-w-[120px] truncate text-xs text-muted-foreground">
                      {field.sample}
                    </span>
                  )}
                </div>
              )
            })}
          </div>
        ))}
      </div>
    </div>
  )
}

/** The fields in picker order, so the active index maps back to one. */
function flatFields(groups: FieldGroup[]): FieldDef[] {
  return groups.flatMap((g) => g.items)
}

export { FieldPicker, flatFields }
