"use client"

import * as React from "react"

import { cn } from "./utils"

/**
 * PRESENTATIONAL single-choice toggle — a small set of mutually exclusive
 * options rendered as one segmented control.
 *
 * WHY THIS IS IN THE KIT AND NOT IN A SCREEN
 * ------------------------------------------
 * It arrived as a hand-rolled list/grid switch inside the document library's
 * toolbar. Nothing about it is about documents: it is "pick exactly one of these
 * two or three, and show me which is on", which is the same control a media
 * browser, a report view and a settings density picker each want. Left in the
 * screen it would have been copied the second time somebody needed one, and the
 * two copies would have disagreed about the accessible name, the pressed state,
 * or which corner gets rounded.
 *
 * WHY BUTTONS WITH `aria-pressed` AND NOT A RADIO GROUP
 * ----------------------------------------------------
 * Both are defensible and this picks one deliberately. A radio group is for
 * choosing a VALUE that is submitted; this changes the view immediately, which
 * is what a toggle button is for. `aria-pressed` announces on/off per option, so
 * a screen reader hears "Grid, pressed" rather than having to walk the group to
 * discover the selection. The wrapper still carries `role="group"` and a name,
 * so the options are announced as belonging together.
 *
 * WHY EVERY OPTION MUST CARRY A LABEL
 * -----------------------------------
 * `label` is required, not optional, and it is used as the accessible name even
 * when `icon` is supplied. An icon-only toggle whose buttons have no name is the
 * single most common way this control ships broken — it looks finished, and it
 * is two unlabelled buttons to anybody not looking at it.
 *
 * DIRECTION
 * ---------
 * Nothing here is direction-aware and that is intentional: the group is a flex
 * row, so it flows along the inline axis and reverses under `dir="rtl"` on its
 * own. Callers should pass symmetrical icons, or icons they are happy to see
 * unmirrored — an icon with an intrinsic direction (an arrow) is a caller
 * decision, not something a generic control can flip safely.
 */
export interface ViewToggleOption<TValue extends string> {
  value: TValue
  /** The accessible name. Required — see the note above about icon-only groups. */
  label: string
  /** Optional glyph. Decorative: mark it `aria-hidden` at the call site. */
  icon?: React.ReactNode
  /** When set, the option is disabled and this is why. Shown on hover and announced. */
  disabledReason?: string
}

export interface ViewToggleProps<TValue extends string> {
  options: ViewToggleOption<TValue>[]
  value: TValue
  onChange: (value: TValue) => void
  /** Names the group, e.g. "Layout". Required: an unnamed group is an orphan. */
  label: string
  /** Show each option's label beside its icon rather than relying on the icon alone. */
  showLabels?: boolean
  className?: string
}

function ViewToggle<TValue extends string>({
  options,
  value,
  onChange,
  label,
  showLabels = false,
  className,
}: ViewToggleProps<TValue>) {
  return (
    <div
      role="group"
      aria-label={label}
      className={cn("flex items-center rounded-md border border-border", className)}
    >
      {options.map((option) => {
        const active = option.value === value
        const disabled = option.disabledReason !== undefined
        return (
          <button
            key={option.value}
            type="button"
            onClick={() => onChange(option.value)}
            disabled={disabled}
            aria-pressed={active}
            aria-label={option.label}
            title={option.disabledReason ?? option.label}
            className={cn(
              "flex h-9 items-center justify-center gap-2 text-sm transition-colors",
              showLabels ? "px-3" : "w-9",
              active
                ? "bg-accent text-accent-foreground"
                : "text-muted-foreground hover:bg-accent/60",
              disabled && "cursor-not-allowed opacity-50 hover:bg-transparent"
            )}
          >
            {option.icon}
            {showLabels && <span>{option.label}</span>}
          </button>
        )
      })}
    </div>
  )
}

export { ViewToggle }
