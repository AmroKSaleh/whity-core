import { cn } from "@/lib/utils"

/**
 * Loading placeholder for structured content. Preserves layout while data is
 * in flight (preferred over a bare spinner for known shapes — see UI-Patterns).
 * Token-driven: fill is `muted`, animated with `animate-pulse`.
 */
function Skeleton({ className, ...props }: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="skeleton"
      aria-hidden="true"
      className={cn("animate-pulse rounded-md bg-muted", className)}
      {...props}
    />
  )
}

export { Skeleton }
