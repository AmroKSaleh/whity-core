import * as React from "react"

import { Button } from "./button"
import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

/**
 * A button that is only an icon. Its `label` is both the accessible name and
 * the tooltip, so the tooltip bubble is hidden from assistive technology and
 * the name is not read twice. The bubble appears after 500 ms on hover and at
 * once on keyboard focus, and can carry a shortcut (docs/design/components/
 * Tooltip, `.wy-tip`). Tooltips name things; they never hold information
 * needed to finish a task.
 */
function IconButton({
  icon,
  label,
  variant = "ghost",
  size = "default",
  tooltip = true,
  shortcut,
  className,
  ...props
}: Omit<React.ComponentProps<"button">, "children"> & {
  icon: IconName
  label: string
  variant?: "ghost" | "outline" | "secondary"
  /** `sm` is 28px for tables and toolbars; `default` is 36px. */
  size?: "sm" | "default"
  /** Show the label as a tooltip above (`true`), below (`"bottom"`), or not at all. */
  tooltip?: boolean | "bottom"
  /** A keyboard shortcut shown in the tooltip, e.g. "⌘B". */
  shortcut?: string
}) {
  const button = (
    <Button
      variant={variant}
      size={size === "sm" ? "icon-sm" : "icon"}
      aria-label={label}
      aria-keyshortcuts={shortcut ? toAriaShortcut(shortcut) : undefined}
      className={cn(!tooltip && className)}
      {...props}
    >
      <Icon name={icon} />
    </Button>
  )
  if (!tooltip) return button

  return (
    <span data-slot="icon-button" className={cn("group/tip relative inline-flex", className)}>
      {button}
      <span
        aria-hidden="true"
        className={cn(
          "pointer-events-none absolute start-1/2 z-40 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-md bg-foreground px-2 py-[5px] text-xs leading-tight font-medium whitespace-nowrap text-background opacity-0 transition-opacity duration-(--dur-fast) ease-out-whity rtl:translate-x-1/2",
          "group-hover/tip:opacity-100 group-hover/tip:delay-500 group-focus-within/tip:opacity-100 group-focus-within/tip:delay-0",
          tooltip === "bottom" ? "top-[calc(100%+6px)]" : "bottom-[calc(100%+6px)]"
        )}
      >
        {label}
        {shortcut && <kbd className="font-mono text-2xs opacity-75">{shortcut}</kbd>}
      </span>
    </span>
  )
}

/** "⌘⇧K" → "Meta+Shift+K", for `aria-keyshortcuts`. */
function toAriaShortcut(shortcut: string): string {
  const names: Record<string, string> = { "⌘": "Meta", "⌃": "Control", "⌥": "Alt", "⇧": "Shift" }
  const keys: string[] = []
  let rest = ""
  for (const ch of shortcut) {
    if (names[ch]) keys.push(names[ch])
    else rest += ch
  }
  const key = rest.replace(/^\+/, "").trim()
  return [...keys, ...(key ? [key] : [])].join("+")
}

export { IconButton }
