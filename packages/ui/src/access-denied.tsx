import * as React from "react"
import {
  IconAlertCircle,
  IconAlertTriangle,
  IconArrowRight,
  IconCircleCheck,
  IconDeviceDesktop,
  IconFileText,
  IconLock,
  IconSearch,
  IconTools,
} from "@tabler/icons-react"

import { cn } from "./utils"
import { Button } from "./button"
import { Input } from "./input"
import { Badge } from "./badge"

export type AccessDeniedVariant =
  | "forbidden"
  | "unauthorized"
  | "not-found"
  | "error"
  | "success"
  | "maintenance"
  | "screen-too-small"

export interface ActionConfig {
  label: string
  onClick?: () => void
  href?: string
  icon?: React.ReactNode
}

export interface SearchResultItem {
  id: string
  title: string
  description?: string
  href: string
  category?: string
  icon?: React.ReactNode
}

export interface AccessDeniedProps extends Omit<React.ComponentProps<"div">, "title"> {
  /** Visual & semantic state variant. Defaults to "forbidden". */
  variant?: AccessDeniedVariant
  icon?: React.ReactNode
  title?: React.ReactNode
  description?: React.ReactNode
  /** Minimum width the feature needs, in px — shown to explain the gate when variant is "screen-too-small" or similar. */
  minWidth?: number
  /**
   * The "needs a wider window" line. A function because the pixel count sits
   * inside the sentence and languages order it differently.
   */
  minWidthHint?: (minWidth: number) => string
  /** Enable an inline site search form (automatically true for "not-found"). */
  showSearch?: boolean
  /** Placeholder text for the search input. */
  searchPlaceholder?: string
  /** Text of the search submit button. Defaults to "Search". */
  searchButtonLabel?: string
  /** Callback fired when search form is submitted or query changes. */
  onSearch?: (query: string) => void
  /** Optional pre-populated or filtered search results list. */
  searchResults?: SearchResultItem[]
  /** Primary action (rendered with a matching color solid larger button). */
  primaryAction?: ActionConfig | React.ReactNode
  /** Secondary action (rendered as a larger outline button). */
  secondaryAction?: ActionConfig | React.ReactNode
  /** Legacy action slot for custom action rendering. */
  action?: React.ReactNode
}

const VARIANT_CONFIGS: Record<
  AccessDeniedVariant,
  {
    title: string
    defaultDescription?: string
    defaultIcon: React.ReactNode
    iconWrapper: string
    buttonVariant: "destructive-solid" | "warning-solid" | "info-solid" | "success-solid" | "purple-solid" | "outline"
  }
> = {
  forbidden: {
    title: "Access Denied",
    defaultIcon: <IconAlertCircle />,
    iconWrapper:
      "bg-red-500/10 text-red-600 dark:bg-red-950/40 dark:text-red-400 border border-red-500/20 shadow-2xs",
    buttonVariant: "destructive-solid",
  },
  unauthorized: {
    title: "Authentication Required",
    defaultIcon: <IconLock />,
    iconWrapper:
      "bg-amber-500/10 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400 border border-amber-500/20 shadow-2xs",
    buttonVariant: "warning-solid",
  },
  "not-found": {
    title: "Page Not Found",
    defaultIcon: <IconSearch />,
    iconWrapper:
      "bg-blue-500/10 text-blue-600 dark:bg-blue-950/40 dark:text-blue-400 border border-blue-500/20 shadow-2xs",
    buttonVariant: "info-solid",
  },
  error: {
    title: "System Error",
    defaultIcon: <IconAlertTriangle />,
    iconWrapper:
      "bg-red-500/10 text-red-600 dark:bg-red-950/40 dark:text-red-400 border border-red-500/20 shadow-2xs",
    buttonVariant: "destructive-solid",
  },
  success: {
    title: "Action Complete",
    defaultIcon: <IconCircleCheck />,
    iconWrapper:
      "bg-emerald-500/10 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-500/20 shadow-2xs",
    buttonVariant: "success-solid",
  },
  maintenance: {
    title: "Under Maintenance",
    defaultIcon: <IconTools />,
    iconWrapper:
      "bg-purple-500/10 text-purple-600 dark:bg-purple-950/40 dark:text-purple-400 border border-purple-500/20 shadow-2xs",
    buttonVariant: "purple-solid",
  },
  "screen-too-small": {
    title: "This screen is too small",
    defaultDescription: "Please switch to a larger screen to continue.",
    defaultIcon: <IconDeviceDesktop />,
    iconWrapper:
      "bg-sky-500/10 text-sky-600 dark:bg-sky-950/40 dark:text-sky-400 border border-sky-500/20 shadow-2xs",
    buttonVariant: "outline",
  },
}

function renderAction(
  action: ActionConfig | React.ReactNode,
  defaultButtonVariant: "destructive-solid" | "warning-solid" | "info-solid" | "success-solid" | "purple-solid" | "outline",
  key: string
) {
  if (!action) return null

  if (React.isValidElement(action)) {
    return action
  }

  const config = action as ActionConfig

  return (
    <Button
      key={key}
      asChild={Boolean(config.href)}
      size="lg"
      variant={defaultButtonVariant}
      onClick={config.onClick}
    >
      {config.href ? (
        <a href={config.href}>
          {config.icon && <span data-icon="inline-start">{config.icon}</span>}
          {config.label}
        </a>
      ) : (
        <>
          {config.icon && <span data-icon="inline-start">{config.icon}</span>}
          {config.label}
        </>
      )}
    </Button>
  )
}

/**
 * Generic full-screen status and permission-denied feedback screen (AccessDenied / StatusPage).
 * Supports semantic variants (forbidden, unauthorized, not-found, error, success, maintenance, screen-too-small),
 * integrated site search for Page Not Found (404), live search results list, minWidth gate messaging,
 * and matching color larger action buttons.
 */
function AccessDenied({
  className,
  variant = "forbidden",
  icon,
  title,
  description,
  minWidth,
  minWidthHint = (px: number) => `Needs a window at least ${px}px wide.`,
  showSearch = variant === "not-found",
  searchPlaceholder = "Search site pages, resources, or docs...",
  searchButtonLabel = "Search",
  onSearch,
  searchResults,
  primaryAction,
  secondaryAction,
  action,
  ...props
}: AccessDeniedProps) {
  const [searchQuery, setSearchQuery] = React.useState("")
  const [hasSearched, setHasSearched] = React.useState(false)
  const config = VARIANT_CONFIGS[variant] ?? VARIANT_CONFIGS.forbidden
  const resolvedTitle = title ?? config.title
  const resolvedIcon = icon ?? config.defaultIcon
  const resolvedDescription = description ?? config.defaultDescription

  const handleSearchSubmit = (e: React.FormEvent) => {
    e.preventDefault()
    if (searchQuery.trim()) {
      setHasSearched(true)
      if (onSearch) {
        onSearch(searchQuery.trim())
      }
    }
  }

  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const val = e.target.value
    setSearchQuery(val)
    if (!val.trim()) {
      setHasSearched(false)
    } else if (onSearch) {
      onSearch(val)
    }
  }

  const activeResults = searchResults ?? []

  return (
    <div
      data-slot="access-denied"
      data-variant={variant}
      role="alert"
      className={cn(
        "flex min-h-[420px] w-full flex-col items-center justify-center rounded-2xl border border-border bg-card p-8 text-center shadow-xs transition-all",
        className
      )}
      {...props}
    >
      <div
        data-slot="access-denied-icon"
        aria-hidden="true"
        className={cn(
          "mb-5 flex items-center justify-center rounded-full p-4 [&>svg]:size-10 md:[&>svg]:size-12 transition-all",
          config.iconWrapper
        )}
      >
        {resolvedIcon}
      </div>

      <h2 data-slot="access-denied-title" className="mb-2 text-xl font-bold tracking-tight text-foreground md:text-2xl">
        {resolvedTitle}
      </h2>

      {resolvedDescription && (
        <div
          data-slot="access-denied-description"
          className={cn(
            "max-w-md text-xs/relaxed text-muted-foreground md:text-sm/relaxed",
            minWidth !== undefined ? "mb-2" : "mb-6"
          )}
        >
          {resolvedDescription}
        </div>
      )}

      {minWidth !== undefined && (
        <p data-slot="access-denied-min-width" className="mb-6 text-xs text-muted-foreground/80">
          {minWidthHint(minWidth)}
        </p>
      )}

      {showSearch && (
        <div className="mb-6 flex w-full max-w-md flex-col gap-3">
          <form onSubmit={handleSearchSubmit} className="flex w-full items-center gap-2">
            <div className="relative flex-1">
              <Input
                type="search"
                placeholder={searchPlaceholder}
                value={searchQuery}
                onChange={handleInputChange}
                className="h-8 text-xs"
              />
            </div>
            <Button type="submit" size="default" variant={config.buttonVariant === "outline" ? "outline" : config.buttonVariant}>
              <IconSearch data-icon="inline-start" />
              {searchButtonLabel}
            </Button>
          </form>

          {/* Inline Search Results List */}
          {(activeResults.length > 0 || (hasSearched && searchQuery.trim())) && (
            <div className="flex flex-col gap-2 rounded-xl border border-border/80 bg-muted/30 p-3 text-start transition-all">
              <div className="flex items-center justify-between px-1 text-[11px] font-semibold text-muted-foreground uppercase tracking-wider">
                <span>Search Results ({activeResults.length})</span>
                {searchQuery && <span className="normal-case italic opacity-80">&quot;{searchQuery}&quot;</span>}
              </div>

              {activeResults.length > 0 ? (
                <div className="flex flex-col gap-1.5">
                  {activeResults.map((result) => (
                    <a
                      key={result.id}
                      href={result.href}
                      className="group/res flex items-center justify-between gap-3 rounded-lg border border-border/50 bg-card p-2.5 transition-all hover:border-primary/40 hover:bg-card hover:shadow-2xs active:scale-[0.99]"
                    >
                      <div className="flex items-start gap-2.5 min-w-0">
                        <div className="mt-0.5 shrink-0 text-muted-foreground group-hover/res:text-primary [&_svg]:size-4">
                          {result.icon ?? <IconFileText />}
                        </div>
                        <div className="space-y-0.5 min-w-0">
                          <div className="flex items-center gap-2">
                            <span className="font-semibold text-xs text-foreground group-hover/res:text-primary truncate">
                              {result.title}
                            </span>
                            {result.category && (
                              <Badge variant="outline" className="text-[9px] px-1.5 py-0">
                                {result.category}
                              </Badge>
                            )}
                          </div>
                          {result.description && (
                            <p className="text-[11px] text-muted-foreground truncate">
                              {result.description}
                            </p>
                          )}
                        </div>
                      </div>
                      <IconArrowRight className="size-4 shrink-0 text-muted-foreground/60 transition-transform group-hover/res:translate-x-0.5 group-hover/res:text-primary rtl:rotate-180" />
                    </a>
                  ))}
                </div>
              ) : (
                <p className="py-2 text-center text-xs text-muted-foreground">
                  No matching pages or resources found for &quot;{searchQuery}&quot;.
                </p>
              )}
            </div>
          )}
        </div>
      )}

      {(primaryAction || secondaryAction || action) && (
        <div className="flex flex-wrap items-center justify-center gap-3">
          {renderAction(primaryAction, config.buttonVariant, "primary")}
          {renderAction(secondaryAction, "outline", "secondary")}
          {action}
        </div>
      )}
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

export { AccessDenied, useViewportAtLeast }
