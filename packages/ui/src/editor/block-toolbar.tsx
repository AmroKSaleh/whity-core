"use client"

import * as React from "react"
import type { Editor } from "@tiptap/core"
import type { Node as PmNode } from "@tiptap/pm/model"
import { NodeSelection, TextSelection } from "@tiptap/pm/state"
import { useEditorState } from "@tiptap/react"

import { Button } from "../button"
import { IconButton } from "../icon-button"
import { ToggleGroup } from "../toggle-group"
import { cn } from "../utils"
import type { Inline } from "./contract"
import { deleteBlock } from "./behaviour"
import { fill, type EditorLabels } from "./labels"
import { inlineText, textToInline, useEditorServices } from "./services"

const RICH = new Set(["callout", "table", "image", "file", "record", "chart", "signature", "barcode", "qr", "math"])

/** The rich block the selection is on or in: its node and position. */
function selectedRich(editor: Editor): { node: PmNode; pos: number } | null {
  const sel = editor.state.selection
  if (sel instanceof NodeSelection && RICH.has(sel.node.type.name)) return { node: sel.node, pos: sel.from }
  const { $from } = sel
  for (let d = $from.depth; d > 0; d--) {
    const node = $from.node(d)
    if (node.type.name === "table" || node.type.name === "callout") return { node, pos: $from.before(d) }
  }
  return null
}

function Sep() {
  return <span aria-hidden="true" className="mx-[3px] h-[18px] w-px bg-border" />
}

/** A labelled text field the toolbar opens for one attribute; Enter keeps it, Esc leaves it. */
function FieldRow({ label, value, onDone, onCancel, mono }: { label: string; value: string; onDone: (v: string) => void; onCancel: () => void; mono?: boolean }) {
  const [text, setText] = React.useState(value)
  const ref = React.useRef<HTMLInputElement>(null)
  React.useEffect(() => ref.current?.focus(), [])
  const { labels } = useEditorServices()
  return (
    <div className="flex items-center gap-1.5 border-t border-border pt-1">
      <label className="flex flex-1 items-center gap-2 text-[0.8125rem] text-muted-foreground">
        {label}
        <input
          ref={ref}
          value={text}
          dir="auto"
          onChange={(e) => setText(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === "Enter") {
              e.preventDefault()
              onDone(text)
            } else if (e.key === "Escape") {
              e.preventDefault()
              onCancel()
            }
          }}
          className={cn(
            "h-7 min-w-0 flex-1 rounded-control-sm border border-input bg-card px-2 text-[0.8125rem] text-foreground outline-none focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-ring",
            mono && "font-mono"
          )}
        />
      </label>
      <Button size="sm" variant="outline" onClick={() => onDone(text)}>
        {labels.done}
      </Button>
    </div>
  )
}

type Editing = { key: string; label: string; value: string; apply: (v: string) => void; mono?: boolean } | null

/**
 * A selected rich block's own toolbar, above it (E.8, EditorBlockChrome):
 * image width, caption, alt text, replace; table header row, rows and columns;
 * callout kind; record and chart source; signature signers and stamp; barcode
 * and QR value; the maths formula. Agents never use it (E.4).
 */
function BlockToolbar({ editor, labels, container }: { editor: Editor; labels: EditorLabels; container: React.RefObject<HTMLDivElement | null> }) {
  const { services } = useEditorServices()
  const target = useEditorState({ editor, selector: ({ editor: e }) => (e.isEditable ? selectedRich(e) : null) })
  const [editing, setEditing] = React.useState<Editing>(null)
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState<string | null>(null)
  const fileInput = React.useRef<HTMLInputElement>(null)
  const [top, setTop] = React.useState<number | null>(null)

  const pos = target?.pos
  React.useEffect(() => setEditing(null), [pos])
  React.useLayoutEffect(() => {
    if (pos === undefined || !container.current) {
      setTop(null)
      return
    }
    const dom = editor.view.nodeDOM(pos) as HTMLElement | null
    if (!dom) return
    const box = container.current.getBoundingClientRect()
    setTop(dom.getBoundingClientRect().top - box.top)
  }, [editor, pos, container, target?.node])

  if (!target || top === null) return null
  const { node } = target
  const type = node.type.name
  const attrs = node.attrs as Record<string, unknown>
  const set = (next: Record<string, unknown>) => {
    const current = editor.state.doc.nodeAt(target.pos)
    if (!current) return
    const tr = editor.state.tr.setNodeMarkup(target.pos, undefined, { ...current.attrs, ...next })
    if (editor.state.selection instanceof NodeSelection) tr.setSelection(NodeSelection.create(tr.doc, target.pos))
    editor.view.dispatch(tr)
  }
  const edit = (key: string, label: string, value: string, apply: (v: string) => void, mono?: boolean) => setEditing({ key, label, value, apply, mono })
  const remove = () => deleteBlock(editor, target.pos)
  const del = <IconButton icon="delete" label={labels.deleteBlock} size="sm" onClick={remove} />

  const upload = async (file: File) => {
    if (!services.uploadFile) return
    setBusy(true)
    setError(null)
    try {
      const up = await services.uploadFile(file)
      if (type === "image") set({ src: up.url, alt: (attrs.alt as string) || file.name.replace(/\.[^.]+$/, "") })
      else set({ fileId: up.fileId, name: up.name, size: up.size, mime: up.mime })
    } catch {
      setError(labels.uploadFailed)
    } finally {
      setBusy(false)
    }
  }

  let controls: React.ReactNode = null
  if (type === "callout") {
    controls = (
      <ToggleGroup
        size="sm"
        label={labels.blockCallout}
        required
        value={attrs.kind === "important" ? "important" : "note"}
        onChange={(v) => set({ kind: v })}
        options={[
          { value: "note", label: labels.calloutNote, icon: "info" },
          { value: "important", label: labels.calloutImportant, icon: "alert" },
        ]}
      />
    )
  } else if (type === "image" || type === "file") {
    controls = (
      <>
        {type === "image" && (
          <>
            <ToggleGroup
              size="sm"
              label={labels.blockImage}
              required
              value={String(attrs.width ?? "full")}
              onChange={(v) => set({ width: v })}
              options={[
                { value: "half", label: labels.widthHalf, icon: "columns", iconOnly: true },
                { value: "wide", label: labels.widthWide, icon: "continuous", iconOnly: true },
                { value: "full", label: labels.widthFull, icon: "page", iconOnly: true },
              ]}
            />
            <Sep />
            <Button variant="ghost" size="sm" onClick={() => edit("caption", labels.caption, inlineText(attrs.caption as Inline[] | null), (v) => set({ caption: v.trim() ? textToInline(v, attrs.caption as Inline[] | null) : null }))}>
              {labels.caption}
            </Button>
            <Button variant="ghost" size="sm" onClick={() => edit("alt", labels.altText, String(attrs.alt ?? ""), (v) => set({ alt: v.trim() }))}>
              {labels.altText}
            </Button>
          </>
        )}
        {services.uploadFile && (
          <Button variant="ghost" size="sm" loading={busy} onClick={() => fileInput.current?.click()}>
            {busy ? labels.uploading : (type === "image" ? attrs.src : attrs.fileId) ? labels.replace : labels.upload}
          </Button>
        )}
        <input
          ref={fileInput}
          type="file"
          hidden
          accept={type === "image" ? "image/png,image/jpeg,image/webp,image/gif,image/svg+xml" : undefined}
          onChange={(e) => {
            const file = e.target.files?.[0]
            e.target.value = ""
            if (file) void upload(file)
          }}
        />
        <Sep />
        {del}
      </>
    )
  } else if (type === "table") {
    const sel = editor.state.selection
    const cellPos = (() => {
      const { $from } = sel
      for (let d = $from.depth; d > 0; d--) if ($from.node(d).type.name === "tableCell") return { row: $from.index(d - 2), col: $from.index(d - 1) }
      return null
    })()
    const columns = ((attrs.columns as { align: string; widthPct?: number }[]) ?? []).slice()
    const width = Math.max(columns.length, ...Array.from({ length: node.childCount }, (_, i) => node.child(i).childCount))
    const setRows = (mutate: (rows: Inline[][][]) => Inline[][][], nextColumns = columns) => {
      // Rebuild through the contract so cells keep their content and marks.
      const json = node.toJSON() as { content?: { content?: { content?: unknown[] }[] }[] }
      const rows: Inline[][][] = (json.content ?? []).map((r) => (r.content ?? []).map((c) => (c.content ?? []) as Inline[]))
      const next = mutate(rows)
      const table = editor.schema.nodeFromJSON({
        type: "table",
        attrs: { ...node.attrs, columns: nextColumns },
        content: next.map((r) => ({ type: "tableRow", content: r.map((c) => ({ type: "tableCell", ...(c.length ? { content: c } : {}) })) })),
      })
      const tr = editor.state.tr.replaceWith(target.pos, target.pos + node.nodeSize, table)
      tr.setSelection(TextSelection.near(tr.doc.resolve(Math.min(target.pos + 3, tr.doc.content.size))))
      editor.view.dispatch(tr)
    }
    const blankRow = () => Array.from({ length: width }, () => [] as Inline[])
    controls = (
      <>
        <Button variant="ghost" size="sm" icon={attrs.headerRow ? "check" : undefined} active={Boolean(attrs.headerRow)} onClick={() => set({ headerRow: !attrs.headerRow })}>
          {labels.headerRow}
        </Button>
        <Sep />
        <Button variant="ghost" size="sm" icon="plus" onClick={() => setRows((rows) => { const r = rows.slice(); r.splice(cellPos ? cellPos.row + 1 : r.length, 0, blankRow()); return r })}>
          {labels.addRow}
        </Button>
        <Button
          variant="ghost"
          size="sm"
          icon="plus"
          onClick={() => {
            const at = cellPos ? cellPos.col + 1 : width
            const nextColumns = Array.from({ length: width }, (_, i) => columns[i] ?? { align: "start" as const })
            nextColumns.splice(at, 0, { align: "start" })
            setRows((rows) => rows.map((r) => { const c = r.slice(); while (c.length < width) c.push([]); c.splice(at, 0, []); return c }), nextColumns)
          }}
        >
          {labels.addColumn}
        </Button>
        {cellPos && (
          <>
            <Sep />
            <ToggleGroup
              size="sm"
              label={labels.columnAlign}
              required
              value={columns[cellPos.col]?.align === "end" ? "end" : "start"}
              onChange={(v) => {
                const next = Array.from({ length: width }, (_, i) => columns[i] ?? { align: "start" as const })
                next[cellPos.col] = { ...next[cellPos.col], align: v === "end" ? "end" : "start" }
                set({ columns: next })
              }}
              options={[
                { value: "start", label: labels.alignStart },
                { value: "end", label: labels.alignEnd },
              ]}
            />
            <IconButton icon="minus" label={labels.deleteRow} size="sm" disabled={node.childCount <= 1} onClick={() => setRows((rows) => rows.filter((_, i) => i !== cellPos.row))} />
            <IconButton
              icon="close"
              label={labels.deleteColumn}
              size="sm"
              disabled={width <= 1}
              onClick={() => setRows((rows) => rows.map((r) => r.filter((_, i) => i !== cellPos.col)), columns.filter((_, i) => i !== cellPos.col))}
            />
          </>
        )}
        <Sep />
        {del}
      </>
    )
  } else if (type === "record") {
    const ref = attrs.ref as { type: string; id: string } | null
    controls = (
      <>
        {services.recordTypes && services.recordTypes.length > 0 && (
          <select
            aria-label={labels.recordType}
            value={ref?.type ?? ""}
            onChange={(e) => set({ ref: e.target.value ? { type: e.target.value, id: ref?.id ?? "" } : null })}
            className="h-7 rounded-control-sm border border-input bg-card px-1.5 text-[0.8125rem]"
          >
            <option value="">{labels.recordType}</option>
            {services.recordTypes.map((t) => (
              <option key={t.key} value={t.key}>
                {t.label}
              </option>
            ))}
          </select>
        )}
        <Button variant="ghost" size="sm" disabled={!ref?.type} onClick={() => edit("record", labels.recordId, ref?.id ?? "", (v) => set({ ref: { type: ref!.type, id: v.trim() } }), true)}>
          {labels.recordChoose}
        </Button>
        <Sep />
        <ToggleGroup
          size="sm"
          label={labels.blockRecord}
          required
          value={attrs.view === "summary" ? "summary" : "card"}
          onChange={(v) => set({ view: v })}
          options={[
            { value: "card", label: labels.viewCard },
            { value: "summary", label: labels.viewSummary },
          ]}
        />
        <Sep />
        {del}
      </>
    )
  } else if (type === "chart") {
    const source = attrs.source as { query: string; params?: Record<string, string> } | null
    controls = (
      <>
        <ToggleGroup
          size="sm"
          label={labels.blockChart}
          required
          value={String(attrs.chart ?? "bar")}
          onChange={(v) => set({ chart: v })}
          options={[
            { value: "bar", label: labels.chartBar },
            { value: "line", label: labels.chartLine },
            { value: "donut", label: labels.chartDonut },
          ]}
        />
        {services.chartSources && services.chartSources.length > 0 && (
          <>
            <Sep />
            <select
              aria-label={labels.chartSource}
              value={source?.query ?? ""}
              onChange={(e) => set({ source: e.target.value ? { query: e.target.value } : null })}
              className="h-7 rounded-control-sm border border-input bg-card px-1.5 text-[0.8125rem]"
            >
              <option value="">{labels.chartSource}</option>
              {services.chartSources.map((s) => (
                <option key={s.key} value={s.key}>
                  {s.label}
                </option>
              ))}
            </select>
          </>
        )}
        <Button variant="ghost" size="sm" onClick={() => edit("title", labels.chartTitle, String(attrs.title ?? ""), (v) => set({ title: v.trim() || null }))}>
          {labels.chartTitle}
        </Button>
        <Sep />
        {del}
      </>
    )
  } else if (type === "signature") {
    const signers = (attrs.signers as { name: Inline[]; role: Inline[] }[]) ?? []
    controls = (
      <>
        {signers.map((s, i) => (
          <Button
            key={i}
            variant="ghost"
            size="sm"
            onClick={() =>
              edit(`signer-${i}`, fill(labels.signatureName, {}), `${inlineText(s.name)} | ${inlineText(s.role)}`, (v) => {
                const [name, role = ""] = v.split("|").map((x) => x.trim())
                const next = signers.slice()
                next[i] = { name: textToInline(name, s.name), role: textToInline(role, s.role) }
                set({ signers: next })
              })
            }
          >
            {inlineText(s.name) || labels.signatureName}
          </Button>
        ))}
        <Button variant="ghost" size="sm" icon="plus" disabled={signers.length >= 3} onClick={() => set({ signers: [...signers, { name: [], role: [] }] })}>
          {labels.addSigner}
        </Button>
        <Button variant="ghost" size="sm" onClick={() => edit("date", labels.signatureDate, inlineText(attrs.date as Inline[]), (v) => set({ date: textToInline(v, attrs.date as Inline[]) }))}>
          {labels.signatureDate}
        </Button>
        <Button variant="ghost" size="sm" active={Boolean(attrs.stamp)} onClick={() => set({ stamp: !attrs.stamp })}>
          {labels.stamp}
        </Button>
        <Sep />
        {del}
      </>
    )
  } else if (type === "barcode" || type === "qr") {
    controls = (
      <>
        {type === "barcode" && (
          <select
            aria-label={labels.symbology}
            value={String(attrs.symbology ?? "code128")}
            onChange={(e) => set({ symbology: e.target.value })}
            className="h-7 rounded-control-sm border border-input bg-card px-1.5 text-[0.8125rem]"
          >
            <option value="code128">Code 128</option>
            <option value="ean13">EAN-13</option>
            <option value="code39">Code 39</option>
          </select>
        )}
        <Button variant="ghost" size="sm" onClick={() => edit("value", labels.value, inlineText(attrs.value as Inline[]), (v) => set({ value: textToInline(v, attrs.value as Inline[]) }), true)}>
          {labels.value}
        </Button>
        {type === "barcode" ? (
          <Button variant="ghost" size="sm" active={Boolean(attrs.showText)} onClick={() => set({ showText: !attrs.showText })}>
            {labels.showText}
          </Button>
        ) : (
          <Button variant="ghost" size="sm" onClick={() => edit("caption", labels.caption, inlineText(attrs.caption as Inline[] | null), (v) => set({ caption: v.trim() ? textToInline(v, attrs.caption as Inline[] | null) : null }))}>
            {labels.caption}
          </Button>
        )}
        <Sep />
        {del}
      </>
    )
  } else if (type === "math") {
    controls = (
      <>
        <Button variant="ghost" size="sm" onClick={() => edit("tex", labels.formula, String(attrs.tex ?? ""), (v) => set({ tex: v }), true)}>
          {labels.formula}
        </Button>
        <Sep />
        {del}
      </>
    )
  }
  if (!controls) return null

  return (
    <div
      role="toolbar"
      aria-label={labels.blockToolbar}
      data-slot="block-toolbar"
      className="absolute start-0 z-20 grid max-w-full gap-1 rounded-control border border-border bg-popover p-[3px] shadow-float"
      style={{ top, transform: "translateY(calc(-100% - 10px))" }}
      onMouseDown={(e) => {
        if ((e.target as HTMLElement).tagName !== "INPUT" && (e.target as HTMLElement).tagName !== "SELECT") e.preventDefault()
      }}
    >
      <div className="flex flex-wrap items-center gap-0.5">{controls}</div>
      {error && (
        <div role="alert" className="px-1.5 pb-0.5 text-xs text-error-text">
          {error}
        </div>
      )}
      {editing && (
        <FieldRow
          key={editing.key}
          label={editing.label}
          value={editing.value}
          mono={editing.mono}
          onCancel={() => {
            setEditing(null)
            editor.commands.focus()
          }}
          onDone={(v) => {
            editing.apply(v)
            setEditing(null)
          }}
        />
      )}
    </div>
  )
}

export { BlockToolbar }
