"use client"

import * as React from "react"
import { Dialog as SheetPrimitive } from "radix-ui"

import { cn, useIsDarkMode } from "./utils"
import { Button } from "./button"
import { IconX } from "@tabler/icons-react"

/**
 * A side drawer ("sheet") built on the Radix Dialog primitive — the same
 * accessibility model as {@link ./dialog} (focus trap, Esc to close, scroll
 * lock, labelled by title) but anchored to an edge of the viewport. Used by the
 * OU Management Hub'header detail drawer.
 */
function Sheet({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Root>) {
  return <SheetPrimitive.Root data-slot="sheet" {...props} />
}

function SheetTrigger({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Trigger>) {
  return <SheetPrimitive.Trigger data-slot="sheet-trigger" {...props} />
}

function SheetClose({
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Close>) {
  return <SheetPrimitive.Close data-slot="sheet-close" {...props} />
}

function SheetOverlay({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Overlay>) {
  return (
    <SheetPrimitive.Overlay
      data-slot="sheet-overlay"
      className={cn(
        "fixed inset-0 z-50 bg-overlay duration-100 data-open:animate-in data-open:fade-in-0 data-closed:animate-out data-closed:fade-out-0",
        className
      )}
      {...props}
    />
  )
}

/**
 * "left"/"right" are logical (start/end), not fixed screen sides — the
 * implementation below maps them to `start-`/`end-` + `border-e`/`border-s`,
 * so `side="right"` renders on the inline-end edge and flips with `dir="rtl"`
 * like every other directional value in this kit, not a fixed physical side.
 */
type SheetSide = "top" | "right" | "bottom" | "left"

function SheetContent({
  className,
  children,
  side = "right",
  showCloseButton = true,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Content> & {
  side?: SheetSide
  showCloseButton?: boolean
}) {
  const isDark = useIsDarkMode()

  return (
    <SheetPrimitive.Portal>
      <SheetOverlay />
      <SheetPrimitive.Content
        data-slot="sheet-content"
        className={cn(
          isDark && "dark",
          "fixed z-50 flex flex-col gap-4 overflow-y-auto bg-popover p-5 text-xs/relaxed text-popover-foreground shadow-lg duration-150 outline-none data-open:animate-in data-closed:animate-out",
          side === "right" &&
            "inset-y-0 end-0 h-full w-full max-w-md border-s border-border data-open:slide-in-from-right data-closed:slide-out-to-right",
          side === "left" &&
            "inset-y-0 start-0 h-full w-full max-w-md border-e border-border data-open:slide-in-from-left data-closed:slide-out-to-left",
          side === "top" &&
            "inset-x-0 top-0 h-auto max-h-[80%] w-full border-b border-border data-open:slide-in-from-top data-closed:slide-out-to-top",
          side === "bottom" &&
            "inset-x-0 bottom-0 h-auto max-h-[80%] w-full border-t border-border data-open:slide-in-from-bottom data-closed:slide-out-to-bottom",
          className
        )}
        {...props}
      >
        {children}
        {showCloseButton && (
          <SheetPrimitive.Close data-slot="sheet-close" asChild>
            <Button variant="ghost" className="absolute top-3 end-3" size="icon-sm">
              <IconX />
              <span className="sr-only">Close</span>
            </Button>
          </SheetPrimitive.Close>
        )}
      </SheetPrimitive.Content>
    </SheetPrimitive.Portal>
  )
}

function SheetHeader({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sheet-header"
      className={cn("flex flex-col gap-1 text-start", className)}
      {...props}
    />
  )
}

function SheetFooter({
  className,
  ...props
}: React.ComponentProps<"div">) {
  return (
    <div
      data-slot="sheet-footer"
      className={cn("mt-auto flex flex-col-reverse gap-2 sm:flex-row sm:justify-end", className)}
      {...props}
    />
  )
}

function SheetTitle({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Title>) {
  return (
    <SheetPrimitive.Title
      data-slot="sheet-title"
      className={cn("font-heading text-sm font-medium text-foreground", className)}
      {...props}
    />
  )
}

function SheetDescription({
  className,
  ...props
}: React.ComponentProps<typeof SheetPrimitive.Description>) {
  return (
    <SheetPrimitive.Description
      data-slot="sheet-description"
      className={cn("text-xs text-muted-foreground", className)}
      {...props}
    />
  )
}

export {
  Sheet,
  SheetTrigger,
  SheetClose,
  SheetContent,
  SheetHeader,
  SheetFooter,
  SheetTitle,
  SheetDescription,
}
