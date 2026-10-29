"use client"

import * as React from "react"

import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

export interface ToggleOption {
  value: string
  label: string
  icon?: IconName
  /** Show the icon alone; the label becomes the accessible name and the tooltip. */
  iconOnly?: boolean
  shortcut?: string
  disabled?: boolean
}

type ToggleValue = string | string[] | null

/**
 * Buttons that stay pressed: a view switch (`single`) or filters (`multiple`).
 * A pressed toggle is the tint with text in the main colour, never a solid
 * fill (decision 7.3). Every button reports `aria-pressed`; arrow keys move
 * between them. The reference is docs/design/components/ToggleGroup
 * (`.wy-toggles`, `.wy-toggle`).
 */
function ToggleGroup({
  options,
  type = "single",
  defaultValue = null,
  value,
  required = false,
  size = "default",
  label,
  onChange,
  className,
}: {
  options: ToggleOption[]
  type?: "single" | "multiple"
  defaultValue?: ToggleValue
  value?: ToggleValue
  /** A single group can't be emptied by pressing its pressed toggle again. */
  required?: boolean
  /** `sm` is 28px overall, for toolbars. */
  size?: "sm" | "default"
  /** The group's accessible name. */
  label?: string
  onChange?: (value: ToggleValue) => void
  className?: string
}) {
  const [inner, setInner] = React.useState<ToggleValue>(defaultValue)
  const current = value !== undefined ? value : inner
  const pressed = (v: string) => (Array.isArray(current) ? current.includes(v) : current === v)
  const buttons = React.useRef<(HTMLButtonElement | null)[]>([])

  const toggle = (v: string) => {
    let next: ToggleValue
    if (type === "multiple") {
      const list = Array.isArray(current) ? current : []
      next = list.includes(v) ? list.filter((x) => x !== v) : [...list, v]
    } else {
      next = current === v ? (required ? v : null) : v
    }
    if (value === undefined) setInner(next)
    onChange?.(next)
  }

  const move = (from: number, step: number) => {
    const enabled = options.map((o, i) => (o.disabled ? -1 : i)).filter((i) => i >= 0)
    const at = enabled.indexOf(from)
    const to = enabled[(at + step + enabled.length) % enabled.length]
    buttons.current[to]?.focus()
  }

  return (
    <div
      role="group"
      aria-label={label}
      data-slot="toggle-group"
      className={cn(
        "inline-flex w-fit gap-0.5 border border-border bg-card p-0.5",
        size === "sm" ? "rounded-control-sm" : "rounded-control",
        className
      )}
    >
      {options.map((option, i) => {
        const on = pressed(option.value)
        const iconOnly = option.iconOnly && option.icon
        return (
          <span key={option.value} className="group/tip relative inline-flex">
            <button
              ref={(el) => {
                buttons.current[i] = el
              }}
              type="button"
              aria-pressed={on}
              aria-label={iconOnly ? option.label : undefined}
              disabled={option.disabled}
              onClick={() => toggle(option.value)}
              onKeyDown={(e) => {
                const forward = e.key === "ArrowRight" || e.key === "ArrowDown"
                const back = e.key === "ArrowLeft" || e.key === "ArrowUp"
                if (!forward && !back) return
                e.preventDefault()
                const rtl = getComputedStyle(e.currentTarget).direction === "rtl"
                const horizontal = e.key === "ArrowRight" || e.key === "ArrowLeft"
                move(i, (forward ? 1 : -1) * (rtl && horizontal ? -1 : 1))
              }}
              data-state={on ? "on" : "off"}
              className={cn(
                "inline-flex items-center justify-center gap-1.5 border-0 bg-transparent font-medium text-muted-foreground outline-none select-none",
                "hover:bg-muted hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-45",
                "data-[state=on]:bg-primary-tint data-[state=on]:text-primary-tint-foreground",
                size === "sm"
                  ? cn("h-[22px] rounded-[5px] text-[0.8125rem]", iconOnly ? "w-[22px]" : "px-2")
                  : cn("h-[30px] rounded-control-sm text-sm", iconOnly ? "w-[30px]" : "px-2.5")
              )}
            >
              {option.icon && <Icon name={option.icon} />}
              {!iconOnly && option.label}
            </button>
            {iconOnly && (
              <span
                aria-hidden="true"
                className="pointer-events-none absolute start-1/2 bottom-[calc(100%+6px)] z-40 inline-flex -translate-x-1/2 items-center gap-1.5 rounded-md bg-foreground px-2 py-[5px] text-xs leading-tight font-medium whitespace-nowrap text-background opacity-0 transition-opacity duration-(--dur-fast) ease-out-whity group-hover/tip:opacity-100 group-hover/tip:delay-500 group-focus-within/tip:opacity-100 group-focus-within/tip:delay-0 rtl:translate-x-1/2"
              >
                {option.label}
                {option.shortcut && <kbd className="font-mono text-2xs opacity-75">{option.shortcut}</kbd>}
              </span>
            )}
          </span>
        )
      })}
    </div>
  )
}

export { ToggleGroup }
