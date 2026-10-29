"use client"

import * as React from "react"
import { IconFlask, IconX } from "@tabler/icons-react"

import { cn } from "./utils"
import { Badge } from "./badge"
import { Button } from "./button"

export interface BetaWarningActionConfig {
  label: string
  onClick?: () => void
  href?: string
}

// `title` is the component's own rich heading (a ReactNode rendered inside the
// banner), not the DOM's `title` tooltip attribute — which only accepts a
// string. Omitting the DOM one keeps the two from colliding: it is consumed by
// destructuring below and never reaches the underlying <div> through `...props`.
export interface BetaWarningProps extends Omit<React.ComponentProps<"div">, "title"> {
  /** Title or feature name (defaults to "Experimental Feature"). */
  title?: React.ReactNode
  /** Description message explaining the beta/experimental status. */
  description?: React.ReactNode
  /** Badge text label (defaults to "Beta"). */
  badgeText?: string
  /** Badge color scheme variant. Defaults to "amber". */
  color?: "amber" | "blue" | "purple"
  /** Display format: "card" (padded container), "banner" (full-width banner bar), or "inline" (compact strip). */
  variant?: "card" | "banner" | "inline"
  /** Optional feedback / action button configuration. */
  action?: BetaWarningActionConfig | React.ReactNode
  /** Allow closing / dismissing the warning. */
  dismissible?: boolean
  /** Accessible name for the dismiss button. Defaults to "Dismiss beta notice". */
  dismissLabel?: string
  /** Callback fired when dismissed. */
  onDismiss?: () => void
}

/**
 * Reusable Beta / Experimental Feature Warning banner & inline indicator.
 * Displays prominent BETA badges, experimental disclaimer copy, feedback actions,
 * and non-squashed icon layout for experimental features.
 */
export function BetaWarning({
  className,
  title = "Experimental Feature",
  description = "This feature is currently in Beta preview. Interfaces and behaviors may be refined based on feedback.",
  badgeText = "Beta",
  color = "amber",
  variant = "card",
  action,
  dismissible = false,
  dismissLabel = "Dismiss beta notice",
  onDismiss,
  ...props
}: BetaWarningProps) {
  const [dismissed, setDismissed] = React.useState(false)

  if (dismissed) return null

  const handleDismiss = () => {
    setDismissed(true)
    onDismiss?.()
  }

  // Status tints only (decision 7.4): warning for amber, the selected tint for
  // blue and purple, since blue is kept for action and the brand (2.5b).
  const colorStyles = {
    amber: {
      card: "border-transparent bg-warning-tint text-foreground",
      badge: "warning" as const,
      icon: "text-warning-text",
    },
    blue: {
      card: "border-transparent bg-primary-tint text-foreground",
      badge: "secondary" as const,
      icon: "text-primary-tint-foreground",
    },
    purple: {
      card: "border-transparent bg-primary-tint text-foreground",
      badge: "secondary" as const,
      icon: "text-primary-tint-foreground",
    },
  }[color]

  if (variant === "inline") {
    return (
      <div
        data-slot="beta-warning"
        data-variant="inline"
        role="status"
        className={cn(
          "inline-flex items-center gap-2 rounded-lg border px-2.5 py-1 text-xs/relaxed font-medium transition-all",
          colorStyles.card,
          className
        )}
        {...props}
      >
        <Badge variant={colorStyles.badge} className="shrink-0">
          {badgeText}
        </Badge>
        <span className="truncate">{title}</span>
        {dismissible && (
          <button
            type="button"
            onClick={handleDismiss}
            aria-label={dismissLabel}
            className="ms-auto flex items-center justify-center size-3.5 text-current opacity-70 hover:opacity-100 focus-visible:outline-none shrink-0"
          >
            <IconX className="size-3 shrink-0" />
          </button>
        )}
      </div>
    )
  }

  return (
    <div
      data-slot="beta-warning"
      data-variant={variant}
      role="status"
      className={cn(
        "group/beta relative flex w-full gap-3 transition-all",
        variant === "banner"
          ? "items-center justify-between border-b px-4 py-2.5 text-xs/relaxed"
          : "flex-col rounded-xl border p-3.5 text-xs/relaxed sm:flex-row sm:items-center",
        colorStyles.card,
        className
      )}
      {...props}
    >
      <div className="flex items-start gap-3 min-w-0">
        {/* Unsquashed Flask Icon Container */}
        <div className={cn("mt-0.5 shrink-0 flex items-center justify-center size-5", colorStyles.icon)}>
          <IconFlask className="size-5 shrink-0" aria-hidden="true" />
        </div>
        <div className="space-y-0.5 min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={colorStyles.badge} className="shrink-0">
              {badgeText}
            </Badge>
            <span className="font-semibold text-foreground text-xs">{title}</span>
          </div>
          {description && (
            <p className="text-xs text-muted-foreground opacity-90 leading-normal">
              {description}
            </p>
          )}
        </div>
      </div>

      <div className="flex items-center gap-2 shrink-0 ms-auto">
        {action && (
          React.isValidElement(action) ? (
            action
          ) : (
            <Button
              size="xs"
              variant="outline"
              onClick={(action as BetaWarningActionConfig).onClick}
              asChild={Boolean((action as BetaWarningActionConfig).href)}
            >
              {(action as BetaWarningActionConfig).href ? (
                <a href={(action as BetaWarningActionConfig).href}>
                  {(action as BetaWarningActionConfig).label}
                </a>
              ) : (
                (action as BetaWarningActionConfig).label
              )}
            </Button>
          )
        )}

        {dismissible && (
          <button
            type="button"
            onClick={handleDismiss}
            aria-label={dismissLabel}
            className="flex items-center justify-center size-5 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted/30 focus-visible:outline-none transition-colors shrink-0"
          >
            <IconX className="size-3.5 shrink-0" />
          </button>
        )}
      </div>
    </div>
  )
}
