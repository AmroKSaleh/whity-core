import * as React from "react"
import { cva, type VariantProps } from "class-variance-authority"
import { Slot } from "radix-ui"

import { Icon, type IconName } from "./icon"
import { cn } from "./utils"

/**
 * The reference is docs/design/components/Button (bundle.css `.wy-btn`).
 * Heights 36 / 28 / 40 with corners ¼ of the height (decisions 4.1, 4.2).
 * Secondary buttons are outline (7.1). The filled `default` button is the one
 * action a view is for and the only one on the plane (4.3): a hard offset
 * down-left that grows on hover and never mirrors in RTL, which is why its
 * margin is physical (`ml`) rather than logical.
 */
const buttonVariants = cva(
  "group/button inline-flex shrink-0 items-center justify-center gap-1.5 rounded-control border border-transparent bg-clip-padding text-sm leading-none font-medium whitespace-nowrap outline-none select-none focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:pointer-events-none disabled:opacity-50 aria-invalid:border-destructive [&_svg]:pointer-events-none [&_svg]:shrink-0",
  {
    variants: {
      variant: {
        default:
          "mb-(--plane-offset) ml-(--plane-offset) bg-primary text-primary-foreground shadow-plane-primary transition-[box-shadow,translate] duration-(--dur-fast) ease-out-whity hover:translate-x-px hover:-translate-y-px hover:shadow-plane-primary-hover active:-translate-x-[3px] active:translate-y-[3px] active:shadow-none",
        outline:
          "border-input bg-card text-foreground hover:bg-muted aria-expanded:bg-muted",
        secondary:
          "border-input bg-card text-foreground hover:bg-muted aria-expanded:bg-muted",
        ghost: "bg-transparent text-foreground hover:bg-muted aria-expanded:bg-muted",
        destructive: "bg-destructive text-destructive-foreground hover:bg-destructive/90",
        link: "bg-transparent text-primary underline underline-offset-4",
      },
      size: {
        default: "h-9 px-3.5 [&_svg:not([class*='size-'])]:size-4",
        xs: "h-6 gap-1 rounded-[6px] px-2 text-xs [&_svg:not([class*='size-'])]:size-3",
        sm: "h-7 gap-1 rounded-control-sm px-2.5 text-[0.8125rem] [&_svg:not([class*='size-'])]:size-4",
        lg: "h-10 rounded-control-lg px-4 [&_svg:not([class*='size-'])]:size-4",
        icon: "size-9 px-0 [&_svg:not([class*='size-'])]:size-4",
        "icon-xs": "size-6 rounded-[6px] px-0 [&_svg:not([class*='size-'])]:size-3",
        "icon-sm": "size-7 rounded-control-sm px-0 [&_svg:not([class*='size-'])]:size-4",
        "icon-lg": "size-10 rounded-control-lg px-0 [&_svg:not([class*='size-'])]:size-4",
      },
    },
    compoundVariants: [
      // The link variant reads as text: no height, no padding at any size.
      { variant: "link", class: "h-auto px-0" },
    ],
    defaultVariants: {
      variant: "default",
      size: "default",
    },
  }
)

function Button({
  className,
  variant = "default",
  size = "default",
  asChild = false,
  loading = false,
  active = false,
  icon,
  iconEnd,
  disabled,
  children,
  ...props
}: React.ComponentProps<"button"> &
  VariantProps<typeof buttonVariants> & {
    asChild?: boolean
    /** Show an inline spinner, disable the button, and expose `aria-busy`. */
    loading?: boolean
    /** Pressed state for toggle buttons: the tint with text in the main colour (7.3). */
    active?: boolean
    /** A curated icon before the label. */
    icon?: IconName
    /** A curated icon after the label. */
    iconEnd?: IconName
  }) {
  const Comp = asChild ? Slot.Root : "button"
  // When rendering via `asChild`, Slot requires a single child element, so we
  // cannot inject a spinner or icons alongside it — only apply the state.
  const decorate = !asChild

  return (
    <Comp
      data-slot="button"
      data-variant={variant}
      data-size={size}
      data-active={active || undefined}
      aria-pressed={active || undefined}
      className={cn(
        buttonVariants({ variant, size }),
        active && "bg-primary-tint text-primary-tint-foreground hover:bg-primary-tint",
        className
      )}
      disabled={disabled || (loading && !asChild)}
      aria-busy={loading || undefined}
      {...props}
    >
      {decorate ? (
        <>
          {loading ? (
            <span
              aria-hidden="true"
              className="size-3 animate-spin rounded-full border-2 border-current border-e-transparent motion-reduce:animate-none"
            />
          ) : (
            icon && <Icon name={icon} />
          )}
          {children}
          {iconEnd && <Icon name={iconEnd} />}
        </>
      ) : (
        children
      )}
    </Comp>
  )
}

export { Button, buttonVariants }
