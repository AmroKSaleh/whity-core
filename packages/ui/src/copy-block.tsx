import * as React from "react"
import { IconCheck, IconCopy } from "@tabler/icons-react"

import { Button } from "./button"
import { cn } from "./utils"

export interface CopyBlockProps extends Omit<React.HTMLAttributes<HTMLDivElement>, "onCopy"> {
  /** The exact text copied, and shown unless `display` is given. */
  value: string
  /** What to show instead of `value` — a masked secret, say. The copy is still `value`. */
  display?: string
  /** A caption above the block: a file path, a field name. */
  caption?: React.ReactNode
  /** Render as a multi-line code block (a command or file) rather than one line. */
  multiline?: boolean
  copyLabel?: string
  copiedLabel?: string
  /** Called after a copy attempt, so the consumer can toast. */
  onCopy?: (copied: boolean) => void
}

/**
 * Text the user is meant to copy somewhere else — a command, a config file, a
 * token — with a copy button.
 *
 * Shown as code: left-to-right and monospaced even in an RTL layout, because
 * the text is read by a program, not a person.
 */
export function CopyBlock({
  value,
  display,
  caption,
  multiline = false,
  copyLabel = "Copy",
  copiedLabel = "Copied",
  onCopy,
  className,
  ...props
}: CopyBlockProps) {
  const [copied, setCopied] = React.useState(false)

  React.useEffect(() => {
    if (!copied) return
    const timer = setTimeout(() => setCopied(false), 2000)
    return () => clearTimeout(timer)
  }, [copied])

  const copy = async () => {
    try {
      await navigator.clipboard.writeText(value)
      setCopied(true)
      onCopy?.(true)
    } catch {
      onCopy?.(false)
    }
  }

  return (
    <div data-slot="copy-block" className={cn("space-y-1", className)} {...props}>
      {caption !== undefined && <div className="text-xs font-medium text-muted-foreground">{caption}</div>}
      <div className="relative rounded-md border border-border bg-muted">
        <pre
          dir="ltr"
          className={cn(
            "overflow-x-auto py-2 ps-3 pe-12 text-start font-mono text-xs text-foreground",
            multiline ? "whitespace-pre" : "whitespace-nowrap"
          )}
        >
          <code>{display ?? value}</code>
        </pre>
        <Button
          type="button"
          variant="ghost"
          size="icon-sm"
          onClick={copy}
          aria-label={copied ? copiedLabel : copyLabel}
          title={copied ? copiedLabel : copyLabel}
          className="absolute end-1.5 top-1.5"
        >
          {copied ? <IconCheck size={14} /> : <IconCopy size={14} />}
        </Button>
      </div>
    </div>
  )
}
