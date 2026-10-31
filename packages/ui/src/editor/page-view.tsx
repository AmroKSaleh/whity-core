"use client"

import * as React from "react"
import { Extension, type Editor } from "@tiptap/core"
import { Plugin, PluginKey } from "@tiptap/pm/state"
import { Decoration, DecorationSet } from "@tiptap/pm/view"

import { TenantLogo } from "../tenant-logo"
import { cn } from "../utils"
import type { LetterheadLine, ResolvedThemeTokens } from "./contract"
import { fill, type EditorLabels } from "./labels"

/**
 * A4 at 96 dpi (EditorPageView): 794 × 1123px, 16mm margins (stage 6), 24px
 * between pages on screen. The running header on the pages after the first
 * takes RUN_HEADER px of the content box.
 */
export const PAGE = { width: 794, height: 1123, margin: 60, gap: 24 } as const
const RUN_HEADER = 44

export interface Letterhead {
  /** The tenant's logo, framed; without one, its initials on the print colour. */
  logo?: string | null
  /**
   * E.44: how the logo shows. `square` (the default) frames it, `wide` shows a
   * wordmark unframed, `none` prints the name without a mark.
   */
  logoShape?: "square" | "wide" | "none"
  name: string
  /** Address, tax number, phone and email lines at the start side. */
  lines: string[]
}

/** Every workspace line a theme can put in the letterhead, by its theme name; null when not set. */
export type LetterheadParts = Record<LetterheadLine, string | null>

/** The workspace's uploaded logos (branding); null when one isn't uploaded. */
export interface BrandLogos {
  wide: string | null
  square: string | null
}

/**
 * The letterhead a theme asks for (E.44, stage 6.2): the lines its
 * `letterhead.lines` names, in that order, from `parts`; and the logo its
 * `letterhead.logo` chooses, falling back to the other shape when that one
 * isn't uploaded (and to the initials when neither is). Without a theme, or
 * without the parts, the letterhead stays as `base` has it.
 */
export function themedLetterhead(
  base: Letterhead,
  theme: Pick<ResolvedThemeTokens, "letterhead"> | null | undefined,
  parts?: LetterheadParts | null,
  logos?: BrandLogos | null
): Letterhead {
  if (!theme) return base
  const { logo, lines } = theme.letterhead
  const picked =
    logo === "none"
      ? { logo: null, logoShape: "none" as const }
      : logo === "wide"
        ? logos?.wide
          ? { logo: logos.wide, logoShape: "wide" as const }
          : { logo: logos?.square ?? null, logoShape: "square" as const }
        : logos?.square
          ? { logo: logos.square, logoShape: "square" as const }
          : logos?.wide
            ? { logo: logos.wide, logoShape: "wide" as const }
            : { logo: null, logoShape: "square" as const }
  return {
    ...base,
    ...(logos || logo === "none" ? picked : {}),
    lines: parts ? lines.map((key) => parts[key]).filter((line): line is string => !!line && line.trim() !== "") : base.lines,
  }
}

/**
 * The theme's colours as the `--doc-*` variables the page view and sheet read
 * (E.11): `--doc-accent` on paper, `--doc-accent-on-dark` for the continuous
 * sheet's title in dark mode.
 */
export function themeStyle(theme: ResolvedThemeTokens | null | undefined): React.CSSProperties | undefined {
  if (!theme) return undefined
  return { "--doc-accent": theme.accent, "--doc-accent-on-dark": theme.accentOnDark } as React.CSSProperties
}

/** The letterhead's mark at `size`, as the theme's logo shape asks. */
function LetterheadMark({ letterhead, size, showName }: { letterhead: Letterhead; size: number; showName?: boolean }) {
  if (letterhead.logoShape === "none") return showName ? <span className="text-sm font-semibold text-foreground">{letterhead.name}</span> : <span />
  return (
    <TenantLogo
      name={letterhead.name}
      src={letterhead.logo}
      size={size}
      showName={showName}
      shape={letterhead.logoShape === "wide" ? "wide" : "square"}
    />
  )
}

/* ------------------------------------------------------- Page breaks */

interface Spacer {
  /** The document position the gap goes before. */
  pos: number
  height: number
}

const paginationKey = new PluginKey<DecorationSet>("pagePagination")

function spacerWidget(height: number) {
  return () => {
    const el = document.createElement("div")
    el.className = "wy-pv__gap"
    el.setAttribute("aria-hidden", "true")
    el.contentEditable = "false"
    el.style.height = `${height}px`
    return el
  }
}

/**
 * Pushes a block that would cross the bottom of a page to the top of the next
 * one, and starts a page after every Page break block. Whity Render paginates
 * the PDF itself (it measures rows); these breaks are a close preview (E.7).
 */
export const PagePagination = Extension.create({
  name: "pagePagination",
  addProseMirrorPlugins() {
    return [
      new Plugin<DecorationSet>({
        key: paginationKey,
        state: {
          init: () => DecorationSet.empty,
          apply(tr, set) {
            const next = tr.getMeta(paginationKey) as Spacer[] | undefined
            if (next) {
              return DecorationSet.create(
                tr.doc,
                next.map((s) => Decoration.widget(s.pos, spacerWidget(s.height), { side: -1, key: `gap-${s.pos}-${s.height}` }))
              )
            }
            return set.map(tr.mapping, tr.doc)
          },
        },
        props: { decorations: (state) => paginationKey.getState(state) },
      }),
    ]
  },
})

function sameSpacers(a: Spacer[], b: Spacer[]): boolean {
  return a.length === b.length && a.every((s, i) => s.pos === b[i].pos && Math.abs(s.height - b[i].height) < 2)
}

/**
 * Lays the top-level blocks out on pages. `column` is the content column,
 * `firstTop` where the editor's content starts on page 1 (below the
 * letterhead), both in unzoomed px. Returns the number of pages.
 */
function usePagination(editor: Editor | null, column: React.RefObject<HTMLDivElement | null>, enabled: boolean): number {
  const [pages, setPages] = React.useState(1)
  const applied = React.useRef<Spacer[]>([])

  const layout = React.useCallback(() => {
    if (!editor || editor.isDestroyed || !column.current) return
    const view = editor.view
    const box = column.current.getBoundingClientRect()
    // CSS zoom scales what getBoundingClientRect reports; offsetWidth isn't.
    const scale = column.current.offsetWidth ? box.width / column.current.offsetWidth : 1
    const pageStart = (k: number) => k * (PAGE.height + PAGE.gap) + (k === 0 ? 0 : PAGE.margin + RUN_HEADER)
    const pageEnd = (k: number) => k * (PAGE.height + PAGE.gap) + PAGE.height - PAGE.margin

    const spacers: Spacer[] = []
    let shiftBefore = 0 // the gaps applied last time above this block, to recover its natural place
    let shift = 0 // the gaps this pass adds above it
    let page = 0
    let breakNext = false
    const old = new Map(applied.current.map((s) => [s.pos, s.height]))

    view.state.doc.forEach((node, offset) => {
      const dom = view.nodeDOM(offset)
      if (!(dom instanceof HTMLElement)) return
      shiftBefore += old.get(offset) ?? 0
      const r = dom.getBoundingClientRect()
      const naturalTop = (r.top - box.top) / scale - shiftBefore
      const height = r.height / scale
      let top = naturalTop + shift
      if (breakNext || (top + height > pageEnd(page) && top > pageStart(page) + 1)) {
        const target = pageStart(page + 1)
        const gap = Math.max(0, target - top)
        if (gap > 0) spacers.push({ pos: offset, height: Math.round(gap) })
        shift += gap
        top += gap
        page += 1
      }
      breakNext = node.type.name === "pageBreak"
      // A block taller than a page runs over the next ones.
      while (top + height > pageEnd(page) + 1) page += 1
    })

    setPages(page + 1)
    if (!sameSpacers(spacers, applied.current)) {
      applied.current = spacers
      view.dispatch(view.state.tr.setMeta(paginationKey, spacers).setMeta("addToHistory", false))
    }
  }, [editor, column])

  React.useEffect(() => {
    if (!editor) return
    if (!enabled) {
      if (applied.current.length) {
        applied.current = []
        editor.view.dispatch(editor.state.tr.setMeta(paginationKey, []).setMeta("addToHistory", false))
      }
      setPages(1)
      return
    }
    let frame = 0
    const schedule = () => {
      cancelAnimationFrame(frame)
      frame = requestAnimationFrame(layout)
    }
    schedule()
    const onUpdate = ({ transaction }: { transaction: { docChanged: boolean } }) => {
      if (transaction.docChanged) schedule()
    }
    editor.on("update", onUpdate)
    const ro = new ResizeObserver(schedule)
    if (column.current) ro.observe(column.current)
    // Images and charts change height when they load.
    const dom = editor.view.dom
    dom.addEventListener("load", schedule, true)
    return () => {
      cancelAnimationFrame(frame)
      editor.off("update", onUpdate)
      ro.disconnect()
      dom.removeEventListener("load", schedule, true)
    }
  }, [editor, enabled, layout, column])

  return pages
}

/* -------------------------------------------------------- Page view */

/**
 * The page view (E.7, EditorPageView): white A4 pages in both themes (E.11),
 * the letterhead on page 1, a running header after it, and "Page x of y" with
 * the reference in each footer. The title is the tenant's print colour.
 */
function PageView({
  editor,
  title,
  letterhead,
  reference,
  date,
  footerRef,
  theme,
  zoom,
  showMargins = false,
  labels,
  onPages,
  children,
}: {
  editor: Editor | null
  title: string
  letterhead?: Letterhead | null
  /** The end side of the letterhead: the reference ("Ref INV-1042") and the date. */
  reference?: React.ReactNode
  date?: React.ReactNode
  /** Beside "Page x of y" in the footer: the document's reference. */
  footerRef?: string
  /** The document's resolved theme: the print colour and where it's used (E.44, E.45). */
  theme?: ResolvedThemeTokens | null
  /** 0.5 to 1.25. */
  zoom: number
  showMargins?: boolean
  labels: EditorLabels
  onPages?: (pages: number) => void
  children: React.ReactNode
}) {
  const column = React.useRef<HTMLDivElement>(null)
  const pages = usePagination(editor, column, true)
  React.useEffect(() => onPages?.(pages), [pages, onPages])

  return (
    <div className="wy-pv" style={{ zoom } as React.CSSProperties}>
      <div
        className="wy-pv__stack light"
        data-accent-use={theme?.accentUse}
        style={{ ...themeStyle(theme), height: pages * PAGE.height + (pages - 1) * PAGE.gap }}
      >
        {Array.from({ length: pages }, (_, i) => (
          <div
            key={i}
            className={cn("wy-pv__page", showMargins && "show-margins")}
            style={{ top: i * (PAGE.height + PAGE.gap) }}
            aria-hidden="true"
          >
            <div className="wy-pv__margin" />
            {i > 0 && (
              <div className="wy-pv__run">
                {letterhead ? <LetterheadMark letterhead={letterhead} size={20} showName={false} /> : <span />}
                <span>{fill(labels.pageContinued, { title: title || labels.untitled })}</span>
              </div>
            )}
            <div className="wy-pv__foot">
              <span>
                {fill(labels.pageOf, { page: i + 1, pages })}
                {footerRef ? ` · ${footerRef}` : ""}
              </span>
              <span>{letterhead?.name}</span>
            </div>
          </div>
        ))}
        <div ref={column} className="wy-pv__column">
          {letterhead !== null && (
            <div className="wy-pv__lh">
              {letterhead ? (
                <div className="grid content-start gap-1.5">
                  <LetterheadMark letterhead={letterhead} size={32} showName />
                  {letterhead.lines.map((line, i) => (
                    <span key={i} dir="auto">
                      {line}
                    </span>
                  ))}
                </div>
              ) : (
                <span />
              )}
              <div className="wy-pv__lhend">
                <h1 className="wy-pv__title" dir="auto">
                  {title || labels.untitled}
                </h1>
                {reference && <b>{reference}</b>}
                {date && <span>{date}</span>}
              </div>
            </div>
          )}
          {children}
        </div>
      </div>
    </div>
  )
}

/** The zoom that fits a page to `width` px of canvas, no larger than 100%. */
export function fitZoom(width: number): number {
  return Math.max(0.3, Math.min(1, (width - 48) / PAGE.width))
}

export { PageView }
