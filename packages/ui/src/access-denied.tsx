import * as React from "react"
import {
  IconAlertCircle,
  IconAlertTriangle,
  IconCheckCircle,
  IconLock,
  IconSearch,
  IconWrench,
} from "@tabler/icons-react"

import { cn } from "./utils"
import { Button } from "./button"

export type AccessDeniedVariant =
  | "forbidden"
  | "unauthorized"
  | "not-found"
  | "error"
  | "success"
  | "maintenance"

export interface ActionConfig {
  label: string
  onClick?: () => void
  href?: string
  icon?: React.ReactNode
}

export interface AccessDeniedProps extends Omit<React.ComponentProps<"div">, "title"> {
  /** Visual & semantic state variant. Defaults to "forbidden". */
  variant?: AccessDeniedVariant
  icon?: React.ReactNode
  title?: React.ReactNode
  description: React.ReactNode
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
    defaultIcon: React.ReactNode
    iconWrapper: string
    buttonVariant: "destructive-solid" | "warning-solid" | "info-solid" | "success-solid" | "purple-solid"
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
    defaultIcon: <IconCheckCircle />,
    iconWrapper:
      "bg-emerald-500/10 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400 border border-emerald-500/20 shadow-2xs",
    buttonVariant: "success-solid",
  },
  maintenance: {
    title: "Under Maintenance",
    defaultIcon: <IconWrench />,
    iconWrapper:
      "bg-purple-500/10 text-purple-600 dark:bg-purple-950/40 dark:text-purple-400 border border-purple-500/20 shadow-2xs",
    buttonVariant: "purple-solid",
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
  const ButtonComp = config.href ? "a" : "button"

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
 * Supports semantic variants (forbidden, unauthorized, not-found, error, success, maintenance)
 * and automatically styles primary/secondary actions using matching color larger buttons.
 */
function AccessDenied({
  className,
  variant = "forbidden",
  icon,
  title,
  description,
  primaryAction,
  secondaryAction,
  action,
  ...props
}: AccessDeniedProps) {
  const config = VARIANT_CONFIGS[variant] ?? VARIANT_CONFIGS.forbidden
  const resolvedTitle = title ?? config.title
  const resolvedIcon = icon ?? config.defaultIcon

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

      <div
        data-slot="access-denied-description"
        className="mb-8 max-w-md text-xs/relaxed text-muted-foreground md:text-sm/relaxed"
      >
        {description}
      </div>

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

export { AccessDenied }
