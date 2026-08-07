import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { Slot } from "radix-ui"

import { cn } from "./utils"

const badgeVariants = cva(
  "group/badge inline-flex h-5.5 w-fit shrink-0 items-center justify-center gap-1 overflow-hidden rounded-full border border-transparent px-2.5 py-0.5 text-[0.6875rem] font-semibold whitespace-nowrap transition-all focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 has-data-[icon=inline-end]:pe-1.5 has-data-[icon=inline-start]:ps-1.5 aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:aria-invalid:ring-destructive/40 [&>svg]:pointer-events-none [&>svg]:size-3!",
  {
    variants: {
      variant: {
        default: "bg-primary text-primary-foreground [a]:hover:bg-primary/80",
        secondary:
          "bg-secondary text-secondary-foreground [a]:hover:bg-secondary/80",
        info:
          "border-blue-500/30 bg-blue-500/10 text-blue-800 dark:border-blue-500/30 dark:bg-blue-950/40 dark:text-blue-300 [a]:hover:bg-blue-500/20",
        "info-solid":
          "bg-blue-600 text-white dark:bg-blue-600 dark:text-white [a]:hover:bg-blue-700",
        success:
          "border-emerald-500/30 bg-emerald-500/10 text-emerald-800 dark:border-emerald-500/30 dark:bg-emerald-950/40 dark:text-emerald-300 [a]:hover:bg-emerald-500/20",
        "success-solid":
          "bg-emerald-600 text-white dark:bg-emerald-600 dark:text-white [a]:hover:bg-emerald-700",
        warning:
          "border-amber-500/35 bg-amber-500/10 text-amber-900 dark:border-amber-500/35 dark:bg-amber-950/50 dark:text-amber-300 [a]:hover:bg-amber-500/20",
        "warning-solid":
          "bg-amber-600 text-white dark:bg-amber-600 dark:text-white [a]:hover:bg-amber-700",
        destructive:
          "border-red-500/30 bg-red-500/10 text-red-800 dark:border-red-500/30 dark:bg-red-950/40 dark:text-red-300 [a]:hover:bg-red-500/20",
        "destructive-solid":
          "bg-destructive text-destructive-foreground [a]:hover:bg-destructive/90",
        purple:
          "border-purple-500/30 bg-purple-500/10 text-purple-800 dark:border-purple-500/30 dark:bg-purple-950/40 dark:text-purple-300 [a]:hover:bg-purple-500/20",
        "purple-solid":
          "bg-purple-600 text-white dark:bg-purple-600 dark:text-white [a]:hover:bg-purple-700",
        outline:
          "border-border bg-card text-foreground dark:bg-card [a]:hover:bg-muted [a]:hover:text-muted-foreground",
        ghost:
          "hover:bg-muted hover:text-muted-foreground dark:hover:bg-muted/50",
        link: "text-primary underline underline-offset-4 decoration-primary/60 decoration-1 hover:underline-offset-2 hover:decoration-2 hover:decoration-primary active:underline-offset-1 active:decoration-4 active:scale-[0.97] transition-all duration-200 ease-out",
      },
    },
    defaultVariants: {
      variant: "default",
    },
  }
)

function Badge({
  className,
  variant = "default",
  asChild = false,
  ...props
}: React.ComponentProps<"span"> &
  VariantProps<typeof badgeVariants> & { asChild?: boolean }) {
  const Comp = asChild ? Slot.Root : "span"

  return (
    <Comp
      data-slot="badge"
      data-variant={variant}
      className={cn(badgeVariants({ variant }), className)}
      {...props}
    />
  )
}

export { Badge, badgeVariants }
