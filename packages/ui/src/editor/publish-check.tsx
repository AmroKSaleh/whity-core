"use client"

import * as React from "react"
import type { Node as PMNode } from "@tiptap/pm/model"

import { Button } from "../button"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "../dialog"
import { Icon, type IconName } from "../icon"
import type { PublishBlocker } from "./contract"
import { fill, type EditorLabels } from "./labels"

/**
 * The fields a document uses that its record type doesn't offer (E.37). The
 * server runs the same check again before it publishes.
 */
export function unknownFields(doc: PMNode, known: Set<string>): string[] {
  const missing = new Set<string>()
  doc.descendants((node) => {
    if (node.type.name === "field" && !known.has(String(node.attrs.path))) missing.add(String(node.attrs.path))
  })
  return [...missing]
}

const ICON: Record<PublishBlocker["kind"], IconName> = {
  "open-suggestions": "suggest",
  "missing-plugin": "plugin",
  "block-without-print-view": "plugin",
  "unknown-field": "field",
  "theme-unavailable": "branding",
}

/**
 * `fill` for text that holds a value from elsewhere: each `{key}` becomes the
 * node given, so a theme ref can sit in a `<bdi>` inside an Arabic sentence.
 */
function fillNodes(template: string, values: Record<string, React.ReactNode>): React.ReactNode {
  return template.split(/(\{\w+\})/).map((part, i) => {
    const m = /^\{(\w+)\}$/.exec(part)
    return m && m[1] in values ? <React.Fragment key={i}>{values[m[1]]}</React.Fragment> : part
  })
}

function blockerText(
  b: PublishBlocker,
  labels: EditorLabels,
  names: { plugin?: (id: string) => string; field?: (path: string) => string }
): { title: React.ReactNode; hint: string; action?: string } {
  switch (b.kind) {
    case "open-suggestions":
      return {
        title: b.count === 1 ? labels.blockerSuggestion : fill(labels.blockerSuggestions, { n: b.count }),
        hint: labels.blockerSuggestionsHint,
        action: labels.review,
      }
    case "missing-plugin":
      return { title: fill(labels.blockerPlugin, { plugin: names.plugin?.(b.plugin) ?? b.plugin }), hint: labels.blockerPluginHint, action: labels.show }
    case "block-without-print-view":
      return { title: labels.blockerNoPrint, hint: labels.blockerNoPrintHint, action: labels.show }
    case "unknown-field":
      return { title: fill(labels.blockerField, { field: names.field?.(b.path) ?? b.path }), hint: labels.blockerFieldHint, action: labels.show }
    case "theme-unavailable":
      // No way to fix it here until the theme picker (WHIT-736): the hint says what to do.
      return { title: fillNodes(labels.blockerTheme, { ref: <bdi dir="ltr">{b.ref}</bdi> }), hint: labels.blockerThemeHint }
  }
}

/**
 * Publish (E.9) opens this check first (TplDocDesigner): what blocks
 * publishing, each with a way to fix it, and Publish stays disabled until the
 * list is empty (E.37). Publishing makes the draft the version Whity Render
 * and agents use.
 */
function PublishCheck({
  open,
  onOpenChange,
  blockers,
  checking = false,
  nextVersion,
  recordLabel,
  onPublish,
  onFix,
  labels,
  pluginName,
  fieldLabel,
}: {
  open: boolean
  onOpenChange: (open: boolean) => void
  blockers: PublishBlocker[]
  /** While the server's check is running. */
  checking?: boolean
  nextVersion: number
  /** What the template prints, plural: "payment reminders". */
  recordLabel?: string
  onPublish: () => Promise<void> | void
  onFix: (blocker: PublishBlocker) => void
  labels: EditorLabels
  pluginName?: (id: string) => string
  fieldLabel?: (path: string) => string
}) {
  const [busy, setBusy] = React.useState(false)
  const [error, setError] = React.useState(false)
  React.useEffect(() => {
    if (open) setError(false)
  }, [open])
  const publish = async () => {
    setBusy(true)
    setError(false)
    try {
      await onPublish()
      onOpenChange(false)
    } catch {
      setError(true)
    } finally {
      setBusy(false)
    }
  }
  const clear = !checking && blockers.length === 0
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-[440px]" closeLabel={labels.keepEditing}>
        <DialogHeader>
          <DialogTitle>{labels.publishTitle}</DialogTitle>
          <DialogDescription>
            {recordLabel ? fill(labels.publishExplainFor, { records: recordLabel }) : labels.publishExplain}
          </DialogDescription>
        </DialogHeader>
        {checking ? (
          <p role="status" className="text-sm text-muted-foreground">
            {labels.publishChecking}
          </p>
        ) : blockers.length > 0 ? (
          <ul className="m-0 grid list-none gap-2 p-0" aria-label={labels.publishBlockers}>
            {blockers.map((b, i) => {
              const t = blockerText(b, labels, { plugin: pluginName, field: fieldLabel })
              return (
                <li key={i} className="flex items-start gap-2.5 rounded-control-lg border border-border px-3 py-2.5 text-sm">
                  <Icon name={ICON[b.kind]} className="mt-0.5 shrink-0 text-warning-text" />
                  <div className="grid flex-1 gap-0.5">
                    <span className="font-medium">{t.title}</span>
                    <span className="text-[0.8125rem] text-muted-foreground">{t.hint}</span>
                  </div>
                  {t.action && (
                    <Button
                      variant="outline"
                      size="sm"
                      onClick={() => {
                        onOpenChange(false)
                        onFix(b)
                      }}
                    >
                      {t.action}
                    </Button>
                  )}
                </li>
              )
            })}
          </ul>
        ) : (
          <p className="flex items-center gap-2 text-sm text-muted-foreground">
            <Icon name="check" className="text-success-text" />
            {labels.publishClear}
          </p>
        )}
        {error && (
          <p role="alert" className="text-sm text-error-text">
            {labels.publishFailed}
          </p>
        )}
        <DialogFooter>
          <Button variant="ghost" onClick={() => onOpenChange(false)}>
            {labels.keepEditing}
          </Button>
          <Button icon="check" disabled={!clear || busy} onClick={publish}>
            {fill(labels.publishVersion, { n: nextVersion })}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

export { PublishCheck }
