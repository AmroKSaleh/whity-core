"use client"

import * as React from "react"
import { Popover as PopoverPrimitive } from "radix-ui"

import { cn, useIsDarkMode } from "./utils"

/**
 * Compound Popover (Root/Trigger/Content/Anchor), matching the same Radix-
 * wrapping convention as Dialog/DropdownMenu/Select/Tooltip in this package.
 * Generic floating-panel-with-arbitrary-content primitive — e.g. the
 * hand-rolled searchable-checkbox-list popover in
 * web/app/(protected)/admin/roles/permission-checkbox.tsx (manual
 * open-state + absolute positioning + no outside-click/Escape handling)
 * should be migrated onto this instead of a bespoke implementation.
 */

function Popover({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Root>) {
  return <PopoverPrimitive.Root data-slot="popover" {...props} />
}

function PopoverTrigger({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Trigger>) {
  return <PopoverPrimitive.Trigger data-slot="popover-trigger" {...props} />
}

function PopoverAnchor({ ...props }: React.ComponentProps<typeof PopoverPrimitive.Anchor>) {
  return <PopoverPrimitive.Anchor data-slot="popover-anchor" {...props} />
}

function PopoverContent({
  className,
  align = "center",
  sideOffset = 6,
  ...props
}: React.ComponentProps<typeof PopoverPrimitive.Content>) {
  const isDark = useIsDarkMode()

  return (
    <PopoverPrimitive.Portal>
      <PopoverPrimitive.Content
        data-slot="popover-content"
        align={align}
        sideOffset={sideOffset}
        className={cn(
          isDark && "dark",
          "z-50 w-72 origin-(--radix-popover-content-transform-origin) rounded-lg border border-border bg-popover p-4 text-popover-foreground shadow-md outline-none duration-100 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 data-open:animate-in data-open:fade-in-0 data-open:zoom-in-95 data-closed:animate-out data-closed:fade-out-0 data-closed:zoom-out-95",
          className
        )}
        {...props}
      />
    </PopoverPrimitive.Portal>
  )
}

export { Popover, PopoverTrigger, PopoverAnchor, PopoverContent }
