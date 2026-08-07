import * as React from "react"

import { cn } from "./utils"

export type CardVariant =
  | "default"
  | "flat"
  | "outline"
  | "elevated"
  | "purple"
  | "info"
  | "success"
  | "warning"
  | "destructive"

export type CardBorderStyle = "solid" | "dashed" | "dotted" | "none"

export type CardState = "default" | "disabled" | "selected" | "active"

export interface CardProps extends React.ComponentProps<"div"> {
  size?: "default" | "sm" | "lg"
  variant?: CardVariant
  borderStyle?: CardBorderStyle
  state?: CardState
  disabled?: boolean
  /**
   * Opt into hover/elevated treatment for interactive cards.
   */
  interactive?: boolean
}

function Card({
  className,
  size = "default",
  variant = "default",
  borderStyle = "solid",
  state = "default",
  disabled = false,
  interactive = false,
  ...props
}: CardProps) {
  const isDisabled = disabled || state === "disabled"
  const isSelected = state === "selected"
  const isActive = state === "active"

  // Elevated solid background & shadow styling according to variant
  const variantClasses = {
    default: "bg-card text-card-foreground shadow-2xs border border-border/60 ring-0",
    flat: "bg-card text-card-foreground shadow-2xs border border-border/40 ring-0",
    outline: "bg-card/60 text-card-foreground border border-border ring-0",
    elevated: "bg-card text-card-foreground shadow-md border border-border/60 ring-0",
    purple: "bg-card text-card-foreground shadow-2xs border border-purple-500/25 dark:bg-purple-950/20 dark:border-purple-500/35 ring-0",
    info: "bg-card text-card-foreground shadow-2xs border border-blue-500/25 dark:bg-blue-950/20 dark:border-blue-500/35 ring-0",
    success: "bg-card text-card-foreground shadow-2xs border border-emerald-500/25 dark:bg-emerald-950/20 dark:border-emerald-500/35 ring-0",
    warning: "bg-card text-card-foreground shadow-2xs border border-amber-500/25 dark:bg-amber-950/25 dark:border-amber-500/30 ring-0",
    destructive: "bg-card text-card-foreground shadow-2xs border border-red-500/25 dark:bg-red-950/20 dark:border-red-500/35 ring-0",
  }[variant]

  // Border style modifier
  const borderStyleClasses = {
    solid: "border-solid",
    dashed: "border-dashed border",
    dotted: "border-dotted border-2",
    none: "border-none ring-0",
  }[borderStyle]

  // Card size spacing
  const sizeClasses = {
    sm: "gap-3 py-3 data-[size=sm]:gap-3 data-[size=sm]:py-3",
    default: "gap-4 py-4",
    lg: "gap-6 py-6",
  }[size]

  return (
    <div
      data-slot="card"
      data-size={size}
      data-variant={variant}
      data-border-style={borderStyle}
      data-state={isDisabled ? "disabled" : isSelected ? "selected" : isActive ? "active" : "default"}
      data-interactive={interactive && !isDisabled ? true : undefined}
      aria-disabled={isDisabled || undefined}
      className={cn(
        "group/card flex flex-col overflow-hidden rounded-xl text-xs/relaxed transition-all duration-200",
        variantClasses,
        borderStyleClasses,
        sizeClasses,
        // State modifiers
        isSelected && "ring-2 ring-primary border-primary bg-primary/[0.04] shadow-xs",
        isActive && "ring-2 ring-primary/80 border-primary",
        isDisabled && "opacity-55 pointer-events-none bg-muted/20 grayscale-[20%] select-none cursor-not-allowed shadow-none",
        // Interactive state
        interactive && !isDisabled && "cursor-pointer hover:-translate-y-0.5 hover:shadow-md hover:border-border/80 focus-within:ring-2 focus-within:ring-ring/50 active:translate-y-0 active:shadow-xs",
        // Media first/last child rounded corners & banner top padding removal
        "has-[>img:first-child]:pt-0 has-[>[data-slot=card-banner]:first-child]:pt-0 *:[img:first-child]:rounded-t-xl *:[img:last-child]:rounded-b-xl",
        className
      )}
      {...props}
    />
  )
}

function CardHeader({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-header"
      className={cn(
        "group/card-header @container/card-header grid auto-rows-min items-start gap-1 rounded-t-xl px-4 group-data-[size=sm]/card:px-3 group-data-[size=lg]/card:px-6 has-data-[slot=card-action]:grid-cols-[1fr_auto] has-data-[slot=card-description]:grid-rows-[auto_auto] [.border-b]:pb-4 group-data-[size=sm]/card:[.border-b]:pb-3 group-data-[size=lg]/card:[.border-b]:pb-6",
        className
      )}
      {...props}
    />
  )
}

function CardTitle({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-title"
      className={cn("font-heading text-sm font-medium tracking-tight text-foreground", className)}
      {...props}
    />
  )
}

function CardDescription({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-description"
      className={cn("text-xs/relaxed text-muted-foreground", className)}
      {...props}
    />
  )
}

function CardAction({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-action"
      className={cn(
        "col-start-2 row-span-2 row-start-1 self-start justify-self-end",
        className
      )}
      {...props}
    />
  )
}

function CardContent({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-content"
      className={cn("px-4 group-data-[size=sm]/card:px-3 group-data-[size=lg]/card:px-6", className)}
      {...props}
    />
  )
}

function CardFooter({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="card-footer"
      className={cn(
        "flex items-center rounded-b-xl px-4 group-data-[size=sm]/card:px-3 group-data-[size=lg]/card:px-6 [.border-t]:pt-4 group-data-[size=sm]/card:[.border-t]:pt-3 group-data-[size=lg]/card:[.border-t]:pt-6",
        className
      )}
      {...props}
    />
  )
}

export {
  Card,
  CardHeader,
  CardFooter,
  CardTitle,
  CardAction,
  CardDescription,
  CardContent,
}
