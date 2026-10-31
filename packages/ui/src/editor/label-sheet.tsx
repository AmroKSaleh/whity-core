"use client"

import * as React from "react"
import type { Editor } from "@tiptap/core"

import { Icon } from "../icon"
import { Input } from "../input"
import { Popover, PopoverContent, PopoverTrigger } from "../popover"
import { ToggleGroup } from "../toggle-group"
import { cn } from "../utils"
import type { LabelGrid, ResolvedThemeTokens } from "./contract"
import { fill, type EditorLabels } from "./labels"
import { themeStyle } from "./page-view"

/** 96 dpi, as the page view (EditorPageView). */
const PX_PER_MM = 96 / 25.4

/** The sheets a label document prints on (E.43): A4 or Letter, portrait. */
export const SHEETS = {
  A4: { widthMm: 210, heightMm: 297 },
  Letter: { widthMm: 215.9, heightMm: 279.4 },
} as const
export type SheetSize = keyof typeof SHEETS

/**
 * Common label stock. The grid is centred on the sheet, which matches these
 * sheets' own margins to within half a millimetre. A certificate is a
 * one-label sheet.
 */
export const LABEL_PRESETS: { id: string; name: string; sheet: SheetSize; grid: LabelGrid }[] = [
  { id: "avery-l7160", name: "Avery L7160", sheet: "A4", grid: { columns: 3, rows: 7, size: { widthMm: 63.5, heightMm: 38.1 }, gapMm: 2.5, gapYMm: 0 } },
  { id: "avery-l7159", name: "Avery L7159", sheet: "A4", grid: { columns: 3, rows: 8, size: { widthMm: 63.5, heightMm: 33.9 }, gapMm: 2.5, gapYMm: 0 } },
  { id: "avery-l7165", name: "Avery L7165", sheet: "A4", grid: { columns: 2, rows: 4, size: { widthMm: 99.1, heightMm: 67.7 }, gapMm: 2.5, gapYMm: 0 } },
  { id: "avery-l7651", name: "Avery L7651", sheet: "A4", grid: { columns: 5, rows: 13, size: { widthMm: 38.1, heightMm: 21.2 }, gapMm: 2.5, gapYMm: 0 } },
  { id: "letter-2x5", name: "Letter 2 × 5", sheet: "Letter", grid: { columns: 2, rows: 5, size: { widthMm: 101.6, heightMm: 50.8 }, gapMm: 4.8, gapYMm: 0 } },
  { id: "certificate", name: "Certificate", sheet: "A4", grid: { columns: 1, rows: 1, size: { widthMm: 190, heightMm: 277 }, gapMm: 0 } },
]

export const DEFAULT_LABEL_GRID: LabelGrid = LABEL_PRESETS[0].grid

/** Where each label sits on the sheet, in mm from the top start corner. */
export function labelCells(grid: LabelGrid, sheet: SheetSize): { x: number; y: number }[] {
  const { widthMm, heightMm } = SHEETS[sheet]
  const gapX = grid.gapMm
  const gapY = grid.gapYMm ?? grid.gapMm
  const left = (widthMm - grid.columns * grid.size.widthMm - (grid.columns - 1) * gapX) / 2
  const top = (heightMm - grid.rows * grid.size.heightMm - (grid.rows - 1) * gapY) / 2
  const cells: { x: number; y: number }[] = []
  for (let r = 0; r < grid.rows; r++) {
    for (let c = 0; c < grid.columns; c++) {
      cells.push({ x: left + c * (grid.size.widthMm + gapX), y: top + r * (grid.size.heightMm + gapY) })
    }
  }
  return cells
}

/** Whether the labels fit on the sheet. */
export function gridFits(grid: LabelGrid, sheet: SheetSize): boolean {
  const cells = labelCells(grid, sheet)
  const last = cells[cells.length - 1]
  const { widthMm, heightMm } = SHEETS[sheet]
  return !!last && cells[0].x >= 0 && cells[0].y >= 0 && last.x + grid.size.widthMm <= widthMm + 0.01 && last.y + grid.size.heightMm <= heightMm + 0.01
}

/** The inner padding of a label, in px (2 mm). */
const LABEL_PAD = Math.round(2 * PX_PER_MM)

/**
 * The page view of a label sheet (E.43): one sheet with the grid. The first
 * label is the one you edit; the others repeat it, as Print fills one label
 * per record or CSV row. Content that runs past the label is cut off on
 * paper, so the label shows where it ends and `onOverflow` reports it.
 */
export function LabelSheetView({
  editor,
  grid,
  sheet,
  zoom,
  theme,
  labels,
  onOverflow,
  children,
}: {
  editor: Editor | null
  grid: LabelGrid
  sheet: SheetSize
  zoom: number
  /** The document's resolved theme: the print colour, and the headings with `title-and-headings`. */
  theme?: ResolvedThemeTokens | null
  labels: EditorLabels
  onOverflow?: (overflow: boolean) => void
  children: React.ReactNode
}) {
  const cells = labelCells(grid, sheet)
  const w = SHEETS[sheet].widthMm * PX_PER_MM
  const h = SHEETS[sheet].heightMm * PX_PER_MM
  const copies = React.useRef<(HTMLDivElement | null)[]>([])
  const first = React.useRef<HTMLDivElement>(null)
  const [overflow, setOverflow] = React.useState(false)

  React.useEffect(() => {
    if (!editor) return
    let frame = 0
    const mirror = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(() => {
        if (editor.isDestroyed) return
        const source = editor.view.dom
        for (const slot of copies.current) {
          if (!slot) continue
          const copy = source.cloneNode(true) as HTMLElement
          copy.removeAttribute("contenteditable")
          copy.removeAttribute("role")
          copy.removeAttribute("aria-label")
          copy.removeAttribute("aria-multiline")
          copy.classList.remove("ProseMirror-focused")
          slot.replaceChildren(copy)
        }
        const box = first.current
        const over = !!box && source.scrollHeight > box.clientHeight - 2 * LABEL_PAD + 1
        setOverflow(over)
      })
    }
    mirror()
    editor.on("update", mirror)
    const dom = editor.view.dom
    dom.addEventListener("load", mirror, true)
    return () => {
      cancelAnimationFrame(frame)
      editor.off("update", mirror)
      dom.removeEventListener("load", mirror, true)
    }
  }, [editor, grid, sheet])

  React.useEffect(() => onOverflow?.(overflow), [overflow, onOverflow])

  const place = (i: number): React.CSSProperties => ({
    insetInlineStart: cells[i].x * PX_PER_MM,
    top: cells[i].y * PX_PER_MM,
    width: grid.size.widthMm * PX_PER_MM,
    height: grid.size.heightMm * PX_PER_MM,
    padding: LABEL_PAD,
  })

  return (
    <div className="wy-pv" style={{ zoom, width: w } as React.CSSProperties}>
      <div className="wy-pv__stack wy-ls light" data-accent-use={theme?.accentUse} style={{ ...themeStyle(theme), width: w, height: h }}>
        <div className="wy-pv__page" style={{ top: 0, width: w, height: h }} aria-hidden="true" />
        {cells.map((_, i) =>
          i === 0 ? (
            <div key={i} ref={first} className={cn("wy-ls__label is-first", overflow && "is-over")} style={place(i)}>
              {children}
              {overflow && (
                <span className="wy-ls__over" role="status">
                  <Icon name="alert" size={12} />
                  {labels.labelOverflow}
                </span>
              )}
            </div>
          ) : (
            <div
              key={i}
              ref={(el) => {
                copies.current[i - 1] = el
              }}
              className="wy-ls__label"
              style={place(i)}
              aria-hidden="true"
              inert
            />
          )
        )}
      </div>
    </div>
  )
}

/** The sheet setup for a label document: a stock preset, or the grid by hand. */
export function LabelSetup({
  grid,
  sheet,
  onChange,
  labels,
  disabled,
}: {
  grid: LabelGrid
  sheet: SheetSize
  onChange: (grid: LabelGrid, sheet: SheetSize) => void
  labels: EditorLabels
  disabled?: boolean
}) {
  const preset = LABEL_PRESETS.find((p) => p.sheet === sheet && sameGrid(p.grid, grid))
  const fits = gridFits(grid, sheet)
  const num = (value: number, set: (n: number) => LabelGrid, opts: { min: number; max: number; step: number; label: string }) => (
    <Input
        label={opts.label}
        type="number"
        inputMode="decimal"
        min={opts.min}
        max={opts.max}
        step={opts.step}
        value={value}
        onChange={(e) => {
          const n = Number(e.target.value)
          if (Number.isFinite(n) && n >= opts.min && n <= opts.max) onChange(set(n), sheet)
        }}
      />
  )

  return (
    <Popover>
      <PopoverTrigger asChild>
        <button
          type="button"
          disabled={disabled}
          className="inline-flex h-7 items-center gap-1 rounded-control px-2 text-xs text-foreground hover:bg-accent disabled:opacity-50"
        >
          <Icon name="table" size={14} />
          {fill(labels.labelGrid, { columns: grid.columns, rows: grid.rows })}
          <Icon name="chevron-down" size={12} />
        </button>
      </PopoverTrigger>
      <PopoverContent align="end" className="grid w-[300px] gap-3">
        <div className="grid gap-1">
          <span className="text-xs font-semibold text-foreground">{labels.labelStock}</span>
          <div role="listbox" aria-label={labels.labelStock} className="grid gap-0.5">
            {LABEL_PRESETS.map((p) => (
              <button
                key={p.id}
                type="button"
                role="option"
                aria-selected={p === preset}
                onClick={() => onChange(p.grid, p.sheet)}
                className={cn(
                  "flex items-center justify-between rounded-control px-2 py-1.5 text-start text-sm hover:bg-accent",
                  p === preset && "bg-primary-tint text-primary-tint-foreground"
                )}
              >
                <span>{p.id === "certificate" ? labels.labelCertificate : p.name}</span>
                <span className="text-xs text-muted-foreground" dir="ltr">
                  {p.sheet} · {p.grid.columns} × {p.grid.rows}
                </span>
              </button>
            ))}
          </div>
        </div>
        <div className="grid grid-cols-2 gap-2">
          <div className="col-span-2 flex items-center justify-between gap-2 text-xs text-muted-foreground">
            {labels.labelSheet}
            <ToggleGroup
              size="sm"
              label={labels.labelSheet}
              required
              value={sheet}
              onChange={(v) => v && onChange(grid, v as SheetSize)}
              options={[
                { value: "A4", label: "A4" },
                { value: "Letter", label: "Letter" },
              ]}
            />
          </div>
          {num(grid.columns, (n) => ({ ...grid, columns: Math.round(n) }), { min: 1, max: 10, step: 1, label: labels.labelColumns })}
          {num(grid.rows, (n) => ({ ...grid, rows: Math.round(n) }), { min: 1, max: 30, step: 1, label: labels.labelRows })}
          {num(grid.size.widthMm, (n) => ({ ...grid, size: { ...grid.size, widthMm: n } }), { min: 10, max: 216, step: 0.1, label: labels.labelWidth })}
          {num(grid.size.heightMm, (n) => ({ ...grid, size: { ...grid.size, heightMm: n } }), { min: 10, max: 297, step: 0.1, label: labels.labelHeight })}
          {num(grid.gapMm, (n) => ({ ...grid, gapMm: n }), { min: 0, max: 20, step: 0.1, label: labels.labelGapX })}
          {num(grid.gapYMm ?? grid.gapMm, (n) => ({ ...grid, gapYMm: n }), { min: 0, max: 20, step: 0.1, label: labels.labelGapY })}
        </div>
        {!fits && (
          <p role="alert" className="m-0 flex items-center gap-1.5 text-xs text-error-text">
            <Icon name="alert" size={12} />
            {labels.labelTooBig}
          </p>
        )}
      </PopoverContent>
    </Popover>
  )
}

function sameGrid(a: LabelGrid, b: LabelGrid): boolean {
  return (
    a.columns === b.columns &&
    a.rows === b.rows &&
    a.size.widthMm === b.size.widthMm &&
    a.size.heightMm === b.size.heightMm &&
    a.gapMm === b.gapMm &&
    (a.gapYMm ?? a.gapMm) === (b.gapYMm ?? b.gapMm)
  )
}
