"use client"

import * as React from "react"
import { NodeViewContent, NodeViewWrapper, ReactNodeViewRenderer, type ReactNodeViewProps } from "@tiptap/react"

import { Badge } from "../badge"
import { Chart } from "../chart"
import { BarcodeSvg } from "../documents/barcode-svg"
import { Icon } from "../icon"
import { MathText } from "../math-text"
import { cn } from "../utils"
import type { Inline } from "./contract"
import { fill } from "./labels"
import {
  BarcodeBlock,
  Callout,
  ChartBlock,
  FileBlock,
  Image,
  MathBlock,
  PageBreak,
  QrBlock,
  RecordBlock,
  SignatureBlock,
  Table,
  Toggle,
} from "./schema-rich"
import { MathInline } from "./schema"
import { formatSize, inlineText, useEditorServices, type ChartData, type RecordView } from "./services"

/**
 * How the rich blocks look in the editor (EditorBlocks, bundle.css
 * `.wy-blk--*`). Atoms are read-only here; their block toolbar edits them
 * (block-toolbar.tsx). Containers (callout, toggle) keep their text editable.
 */

const blockAttrs = (props: ReactNodeViewProps, type: string) => ({
  "data-type": type,
  "data-block-id": props.node.attrs.id ?? undefined,
  className: cn("wy-rich", props.selected && "is-selected"),
})

function CalloutView(props: ReactNodeViewProps) {
  const important = props.node.attrs.kind === "important"
  return (
    <NodeViewWrapper {...blockAttrs(props, "callout")} data-kind={important ? "important" : "note"}>
      <span contentEditable={false} className="wy-callout-icon">
        <Icon name={important ? "alert" : "info"} />
      </span>
      <NodeViewContent as="div" className="wy-callout-text" />
    </NodeViewWrapper>
  )
}

function ToggleView(props: ReactNodeViewProps) {
  const { labels } = useEditorServices()
  const open = Boolean(props.node.attrs.open)
  return (
    <NodeViewWrapper {...blockAttrs(props, "toggle")} data-open={open ? "true" : "false"}>
      <button
        type="button"
        contentEditable={false}
        className="wy-toggle-button"
        aria-expanded={open}
        aria-label={open ? labels.toggleClose : labels.toggleOpen}
        onMouseDown={(e) => e.preventDefault()}
        onClick={() => props.updateAttributes({ open: !open })}
      >
        <Icon name="chevron-right" />
      </button>
      <NodeViewContent as="div" className="wy-toggle-content" />
    </NodeViewWrapper>
  )
}

function PageBreakView(props: ReactNodeViewProps) {
  const { labels } = useEditorServices()
  return (
    <NodeViewWrapper {...blockAttrs(props, "pageBreak")}>
      <div className="wy-pagebreak">{labels.blockPageBreak}</div>
    </NodeViewWrapper>
  )
}

function ImageView(props: ReactNodeViewProps) {
  const { labels } = useEditorServices()
  const { src, alt, width, caption } = props.node.attrs as { src: string; alt: string; width: string; caption: Inline[] | null }
  return (
    <NodeViewWrapper {...blockAttrs(props, "image")} data-width={width}>
      <figure className="wy-figure">
        {src ? <img src={src} alt={alt} draggable={false} /> : <div className="wy-placeholder">{labels.imageEmpty}</div>}
        {caption && caption.length > 0 && <figcaption dir="auto">{inlineText(caption)}</figcaption>}
      </figure>
    </NodeViewWrapper>
  )
}

function FileView(props: ReactNodeViewProps) {
  const { services, labels } = useEditorServices()
  const { fileId, name, size, mime } = props.node.attrs as { fileId: string; name: string; size: number; mime: string }
  const kind = (mime.split("/")[1] ?? "").toUpperCase()
  const href = fileId && services.fileUrl ? services.fileUrl(fileId) : undefined
  return (
    <NodeViewWrapper {...blockAttrs(props, "file")}>
      <div className="wy-file">
        <span className="wy-tile" aria-hidden="true">
          <Icon name="document" />
        </span>
        <span className="wy-file-meta">
          <span className="font-medium" dir="auto">
            {name || labels.fileEmpty}
          </span>
          {fileId && <span className="text-xs text-muted-foreground">{[formatSize(size), kind].filter(Boolean).join(" · ")}</span>}
        </span>
        {href && (
          <a href={href} download={name} className="wy-file-download" aria-label={fill(labels.fileDownload, { name })} contentEditable={false}>
            <Icon name="download" />
          </a>
        )}
      </div>
    </NodeViewWrapper>
  )
}

/** Loads live data for a block, again whenever the block's source changes. */
function useLive<T>(key: string, load: (() => Promise<T | null>) | null): { state: "loading" | "ready" | "hidden" | "error" | "none"; data: T | null } {
  const [result, setResult] = React.useState<{ key: string; state: "loading" | "ready" | "hidden" | "error" | "none"; data: T | null }>({ key: "", state: "loading", data: null })
  React.useEffect(() => {
    if (!load) {
      setResult({ key, state: "none", data: null })
      return
    }
    let live = true
    setResult({ key, state: "loading", data: null })
    load()
      .then((data) => live && setResult({ key, state: data === null ? "hidden" : "ready", data }))
      .catch(() => live && setResult({ key, state: "error", data: null }))
    return () => {
      live = false
    }
    // `load` is rebuilt every render; the key says when the source really changed.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [key])
  return result.key === key ? result : { state: "loading", data: null }
}

function RecordView_(props: ReactNodeViewProps) {
  const { services, labels } = useEditorServices()
  const ref = props.node.attrs.ref as { type: string; id: string } | null
  const key = ref ? `${ref.type}:${ref.id}` : ""
  const { state, data } = useLive<RecordView>(key, ref && services.resolveRecord ? () => services.resolveRecord!(ref) : null)
  const summary = props.node.attrs.view === "summary"
  return (
    <NodeViewWrapper {...blockAttrs(props, "record")}>
      <div className="wy-embed">
        <div className="wy-embed-head">
          <Icon name="invoice" size={14} />
          {data?.typeLabel ?? labels.blockRecord}
          <span className="flex-1" />
          {state === "ready" && (
            <>
              <Icon name="sync" size={12} />
              {labels.live}
            </>
          )}
        </div>
        {!ref ? (
          <div className="wy-placeholder">{labels.recordEmpty}</div>
        ) : state === "loading" ? (
          <div className="wy-skeleton" aria-busy="true" />
        ) : state === "hidden" || state === "none" ? (
          <div className="wy-placeholder">{state === "hidden" ? labels.recordHidden : labels.recordUnavailable}</div>
        ) : state === "error" ? (
          <div className="wy-placeholder">{labels.recordFailed}</div>
        ) : data ? (
          <div className="wy-embed-row">
            {!summary && (
              <span className="wy-tile is-tint" aria-hidden="true">
                <Icon name="invoice" />
              </span>
            )}
            <span className="wy-embed-main">
              <span className="font-semibold" dir="auto">
                {data.title}
              </span>
              {data.subtitle && <span className="text-[0.8125rem] text-muted-foreground">{data.subtitle}</span>}
            </span>
            {data.amount && <span className="text-base font-semibold tabular-nums" dir="ltr">{data.amount}</span>}
            {data.status && <Badge variant={data.status.tone}>{data.status.label}</Badge>}
          </div>
        ) : null}
      </div>
    </NodeViewWrapper>
  )
}

/** 4860 → "4.9K": the one labelled bar (BarChart card). Western digits in every language. */
function compact(value: number): string {
  if (Math.abs(value) >= 1_000_000) return `${(value / 1_000_000).toFixed(1).replace(/\.0$/, "")}M`
  if (Math.abs(value) >= 1_000) return `${(value / 1_000).toFixed(1).replace(/\.0$/, "")}K`
  return String(value)
}

function ChartView(props: ReactNodeViewProps) {
  const { services, labels } = useEditorServices()
  const source = props.node.attrs.source as { query: string; params?: Record<string, string> } | null
  const chart = (props.node.attrs.chart ?? "bar") as "bar" | "line" | "donut"
  const title = props.node.attrs.title as string | null
  const key = source ? JSON.stringify(source) : ""
  const { state, data } = useLive<ChartData>(key, source && services.loadChart ? () => services.loadChart!(source) : null)
  const [asTable, setAsTable] = React.useState(false)
  const rows = (data?.points ?? []).map((p) => ({ label: p.label, value: p.value }))
  const valueLabel = data?.valueLabel ?? title ?? labels.blockChart
  const highest = rows.reduce((best, r, i) => (r.value > (rows[best]?.value ?? -Infinity) ? i : best), 0)
  const ready = state === "ready" && rows.length > 0
  return (
    <NodeViewWrapper {...blockAttrs(props, "chart")}>
      <div className="wy-embed">
        {/* The BarChart card's header: the title, what it covers and that it is live, and a table view. */}
        <div className="wy-chart-head">
          <div className="grid min-w-0">
            <span className="wy-chart-title" dir="auto">
              {title || (source ? services.chartSources?.find((s) => s.key === source.query)?.label : null) || labels.blockChart}
            </span>
            {state === "ready" && (
              <span className="wy-chart-sub" dir="auto">
                {data?.subtitle ? fill(labels.chartLiveRange, { range: data.subtitle }) : labels.live}
              </span>
            )}
          </div>
          {ready && (
            <button
              type="button"
              className="wy-chart-toggle"
              contentEditable={false}
              aria-pressed={asTable}
              onMouseDown={(e) => e.preventDefault()}
              onClick={() => setAsTable((v) => !v)}
            >
              <Icon name={asTable ? "chart" : "table"} size={14} />
              {asTable ? labels.chartShowChart : labels.chartShowTable}
            </button>
          )}
        </div>
        {!source ? (
          <div className="wy-placeholder">{labels.chartEmpty}</div>
        ) : state === "loading" ? (
          <div className="wy-skeleton is-tall" aria-busy="true" />
        ) : ready && asTable ? (
          <table className="wy-chart-table">
            <thead>
              <tr>
                <th scope="col">{labels.chartCategory}</th>
                <th scope="col">{valueLabel}</th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r) => (
                <tr key={r.label}>
                  <td dir="auto">{r.label}</td>
                  <td dir="ltr">{r.value.toLocaleString("en")}</td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : ready ? (
          <Chart
            type={chart === "donut" ? "pie" : chart}
            data={rows}
            xKey="label"
            series={[{ key: "value", label: valueLabel, color: 1 }]}
            height={170}
            // One series needs no legend; a ring needs one to name its slices.
            legend={chart === "donut"}
            highlight={chart === "bar" ? highest : undefined}
            formatValue={compact}
            donut={chart === "donut"}
          />
        ) : (
          <div className="wy-placeholder">
            {state === "hidden" ? labels.chartHidden : state === "error" ? labels.chartFailed : state === "none" ? labels.chartUnavailable : labels.chartNoData}
          </div>
        )}
      </div>
    </NodeViewWrapper>
  )
}

function SignatureView(props: ReactNodeViewProps) {
  const { labels } = useEditorServices()
  const signers = (props.node.attrs.signers ?? []) as { name: Inline[]; role: Inline[] }[]
  const date = (props.node.attrs.date ?? []) as Inline[]
  return (
    <NodeViewWrapper {...blockAttrs(props, "signature")}>
      <div className="wy-sign">
        {signers.map((s, i) => (
          <div key={i} className="wy-sign-line">
            <b dir="auto">{inlineText(s.name) || labels.signatureName}</b>
            <span dir="auto">{inlineText(s.role)}</span>
          </div>
        ))}
        <div className="wy-sign-line">
          {/* @date-display-ignore: the signature date is text the author typed or a merge field (Inline[]), not a record timestamp; this is its label. */}
          <b>{labels.signatureDate}</b>
          <span dir="auto">{inlineText(date)}</span>
        </div>
        {props.node.attrs.stamp && <div className="wy-sign-stamp">{labels.signatureStamp}</div>}
      </div>
    </NodeViewWrapper>
  )
}

/** A barcode or QR code; a merge field can't be drawn until there's a value, so it shows what fills it. */
function CodeView(props: ReactNodeViewProps & { qr: boolean }) {
  const { labels } = useEditorServices()
  const value = (props.node.attrs.value ?? []) as Inline[]
  const hasField = value.some((r) => r.type === "field")
  const text = inlineText(value)
  const symbology = props.qr ? "qrcode" : String(props.node.attrs.symbology ?? "code128")
  const caption = props.qr ? inlineText(props.node.attrs.caption as Inline[] | null) : ""
  return (
    <NodeViewWrapper {...blockAttrs(props, props.qr ? "qr" : "barcode")}>
      <figure className={cn("wy-code", props.qr ? "is-qr" : "is-bar")}>
        {!text ? (
          <div className="wy-placeholder">{props.qr ? labels.qrEmpty : labels.barcodeEmpty}</div>
        ) : hasField ? (
          <div className="wy-placeholder is-field" dir="auto">
            {fill(labels.codeFromField, { value: text })}
          </div>
        ) : (
          <div className="wy-code-art" dir="ltr">
            <BarcodeSvg symbology={symbology} value={text} showText={!props.qr && Boolean(props.node.attrs.showText)} />
          </div>
        )}
        {caption && <figcaption dir="auto">{caption}</figcaption>}
      </figure>
    </NodeViewWrapper>
  )
}

function MathView(props: ReactNodeViewProps) {
  const { labels } = useEditorServices()
  const tex = String(props.node.attrs.tex ?? "")
  return (
    <NodeViewWrapper {...blockAttrs(props, "math")}>
      <div className="wy-math-block" dir="ltr">
        {tex ? <MathText expression={tex} block /> : <div className="wy-placeholder">{labels.mathEmpty}</div>}
      </div>
    </NodeViewWrapper>
  )
}

/** An inline formula (E.42), rendered with KaTeX; its source is the tooltip. */
function MathInlineView(props: ReactNodeViewProps) {
  const tex = String(props.node.attrs.tex ?? "")
  return (
    <NodeViewWrapper as="span" data-math="" dir="ltr" title={tex} className={cn("wy-math", props.selected && "is-selected")}>
      {tex ? <MathText expression={tex} /> : "…"}
    </NodeViewWrapper>
  )
}

/** Table cells take their column's alignment (E.15: end-aligned numbers). */
const TableWithAlign = Table.extend({
  addNodeView() {
    return ({ node: initial }) => {
      let node = initial
      const dom = document.createElement("table")
      const contentDOM = document.createElement("tbody")
      dom.append(contentDOM)
      const paint = () => {
        dom.dataset.type = "table"
        dom.dataset.headerRow = node.attrs.headerRow ? "true" : "false"
        if (node.attrs.id) dom.dataset.blockId = node.attrs.id
        const columns = (node.attrs.columns ?? []) as { align?: string }[]
        dom.style.setProperty("--wy-columns", String(columns.length))
        requestAnimationFrame(() => {
          contentDOM.querySelectorAll("tr").forEach((tr) =>
            Array.from(tr.children).forEach((cell, i) => {
              ;(cell as HTMLElement).dataset.align = columns[i]?.align === "end" ? "end" : "start"
            })
          )
        })
      }
      paint()
      return {
        dom,
        contentDOM,
        update(next) {
          if (next.type !== node.type) return false
          node = next
          paint()
          return true
        },
      }
    }
  },
})

const react = (component: React.ComponentType<ReactNodeViewProps>) => () => ReactNodeViewRenderer(component)

/** The M3 node types with their editor views. */
export const richNodeViews = [
  Callout.extend({ addNodeView: react(CalloutView) }),
  Toggle.extend({ addNodeView: react(ToggleView) }),
  TableWithAlign,
  PageBreak.extend({ addNodeView: react(PageBreakView) }),
  Image.extend({ addNodeView: react(ImageView) }),
  FileBlock.extend({ addNodeView: react(FileView) }),
  RecordBlock.extend({ addNodeView: react(RecordView_) }),
  ChartBlock.extend({ addNodeView: react(ChartView) }),
  SignatureBlock.extend({ addNodeView: react(SignatureView) }),
  BarcodeBlock.extend({ addNodeView: react((p) => <CodeView {...p} qr={false} />) }),
  QrBlock.extend({ addNodeView: react((p) => <CodeView {...p} qr />) }),
  MathBlock.extend({ addNodeView: react(MathView) }),
  MathInline.extend({ addNodeView: react(MathInlineView) }),
]
