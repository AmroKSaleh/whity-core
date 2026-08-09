"use client"

import * as React from "react"
import { IconDeviceDesktop } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * Full-page "this screen is too small" state — for a workspace that genuinely
 * cannot be made useful on a phone (a canvas editor with two side rails and a
 * millimetre-accurate page, say). A sibling of `AccessDenied` and
 * `LockedScreen`: domain-free, presentational, `role="alert"`.
 *
 * This is deliberately a GATE, not responsive layout: some tools are worse when
 * squeezed than when honestly withheld. Use it only where that is true — the
 * rest of the app should still adapt.
 */
export interface ScreenTooSmallProps extends Omit<React.ComponentProps<"div">, "title"> {
  icon?: React.ReactNode
  title?: React.ReactNode
  description?: React.ReactNode
  /** Minimum width the feature needs, in px — shown to explain the gate. */
  minWidth?: number
  /** e.g. a "Back to dashboard" link, so the screen is never a dead end. */
  action?: React.ReactNode
}

function ScreenTooSmall({
  className,
  icon,
  title = "This screen is too small",
  description,
  minWidth,
  action,
  ...props
}: ScreenTooSmallProps) {
  return (
    <div
      role="alert"
      data-slot="screen-too-small"
      data-testid="screen-too-small"
      className={cn(
        "flex min-h-svh flex-col items-center justify-center gap-3 p-8 text-center",
        className
      )}
      {...props}
    >
      <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground [&_svg:not([class*='size-'])]:size-6">
        {icon ?? <IconDeviceDesktop />}
      </span>
      <h1 className="text-base font-semibold text-foreground">{title}</h1>
      <p className="max-w-sm text-sm text-muted-foreground">
        {description ?? "Please switch to a larger screen to continue."}
      </p>
      {minWidth !== undefined && (
        <p className="text-xs text-muted-foreground/80">Needs a window at least {minWidth}px wide.</p>
      )}
      {action}
    </div>
  )
}

const hasMatchMedia = () => typeof window !== "undefined" && typeof window.matchMedia === "function"

/**
 * Whether the viewport is at least `minWidth` CSS px, tracked live.
 *
 * Returns `undefined` for the hydration render — i.e. "not measured yet" — so a
 * caller can hold off rendering EITHER branch for one paint instead of guessing.
 * Guessing is what goes wrong here: assume wide and phones flash the editor;
 * assume narrow and desktops flash the gate.
 *
 * A media query IS an external store, so this uses `useSyncExternalStore` rather
 * than measuring in an effect: React swaps from the server snapshot to the live
 * value immediately after hydration, with no setState cascade and no mismatch.
 */
function useViewportAtLeast(minWidth: number): boolean | undefined {
  const subscribe = React.useCallback(
    (onChange: () => void) => {
      if (!hasMatchMedia()) return () => {}
      const query = window.matchMedia(`(min-width: ${minWidth}px)`)
      query.addEventListener("change", onChange)
      return () => query.removeEventListener("change", onChange)
    },
    [minWidth]
  )

  const getSnapshot = React.useCallback(
    // No matchMedia (very old browser / non-DOM env): fail OPEN rather than
    // permanently gating someone out of the feature.
    () => (hasMatchMedia() ? window.matchMedia(`(min-width: ${minWidth}px)`).matches : true),
    [minWidth]
  )

  return React.useSyncExternalStore(subscribe, getSnapshot, () => undefined)
}

export { ScreenTooSmall, useViewportAtLeast }
