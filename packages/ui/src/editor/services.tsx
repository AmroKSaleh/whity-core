"use client"

import * as React from "react"

import type { Inline, RecordRef } from "./contract"
import { EDITOR_LABELS, type EditorLabels } from "./labels"

/** What a Record block shows (E.16): the record as the reader may see it. */
export interface RecordView {
  /** The record type's name, e.g. "Invoice". */
  typeLabel: string
  /** "INV-1042 · Example Clinic" */
  title: string
  subtitle?: string
  /** A formatted amount, e.g. "JOD 301.600". */
  amount?: string
  status?: { label: string; tone: "success" | "warning" | "destructive" | "info" | "secondary" }
  /** Where the record opens. */
  href?: string
}

/** What a Chart block draws: one series of labelled values. */
export interface ChartData {
  points: { label: string; value: number }[]
  /** What the values are, for the table view and screen readers. */
  valueLabel?: string
  /** What the series covers, e.g. "Last 6 months". */
  subtitle?: string
}

export interface UploadedFile {
  fileId: string
  /** Where the file is served, for images. */
  url: string
  name: string
  size: number
  mime: string
}

/**
 * How rich blocks reach live data and storage. The editor never fetches on its
 * own: the screen passes these in, and every call is answered within the
 * reader's permissions (E.16, E.31). `null` from a resolver means the reader
 * may not see it.
 */
export interface EditorServices {
  resolveRecord?: (ref: RecordRef) => Promise<RecordView | null>
  loadChart?: (source: { query: string; params?: Record<string, string> }) => Promise<ChartData | null>
  uploadFile?: (file: File) => Promise<UploadedFile>
  /** The download address of a stored file. */
  fileUrl?: (fileId: string) => string
  /** What a Chart block can read from, for its source menu. */
  chartSources?: { key: string; label: string }[]
  /** What a Record block can point at, for its record picker. */
  recordTypes?: { key: string; label: string }[]
}

interface ServicesValue {
  services: EditorServices
  labels: EditorLabels
}

const ServicesContext = React.createContext<ServicesValue>({ services: {}, labels: EDITOR_LABELS })

export function EditorServicesProvider({ services, labels, children }: ServicesValue & { children: React.ReactNode }) {
  const value = React.useMemo(() => ({ services, labels }), [services, labels])
  return <ServicesContext.Provider value={value}>{children}</ServicesContext.Provider>
}

export function useEditorServices(): ServicesValue {
  return React.useContext(ServicesContext)
}

/** Inline content as plain text; a merge field shows as {{its label}}. */
export function inlineText(content: Inline[] | null | undefined): string {
  return (content ?? [])
    .map((run) => (run.type === "text" ? run.text : run.type === "break" ? "\n" : run.type === "math" ? run.tex : `{{${run.label || run.path}}}`))
    .join("")
}

/** Plain text back to inline content, keeping `{{label}}` fields that were there before. */
export function textToInline(text: string, previous: Inline[] | null | undefined): Inline[] {
  const fields = new Map((previous ?? []).filter((r) => r.type === "field").map((r) => [`{{${(r as { label: string; path: string }).label || (r as { path: string }).path}}}`, r]))
  // Scans for the known tokens rather than a {{…}} regex: only fields that were
  // there before come back, and a run of "{" stays linear (CodeQL js/polynomial-redos).
  const out: Inline[] = []
  let from = 0
  let i = text.indexOf("{{")
  while (i !== -1) {
    let token: string | undefined
    for (const key of fields.keys()) {
      if (text.startsWith(key, i)) {
        token = key
        break
      }
    }
    if (token) {
      if (i > from) out.push({ type: "text", text: text.slice(from, i) })
      out.push(fields.get(token)!)
      from = i + token.length
      i = text.indexOf("{{", from)
    } else {
      i = text.indexOf("{{", i + 1)
    }
  }
  if (from < text.length) out.push({ type: "text", text: text.slice(from) })
  return out
}

/** "482 KB", "1.2 MB" — Western digits (3.7b). */
export function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}
