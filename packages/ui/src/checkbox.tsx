"use client"

import * as React from "react"
import { Checkbox as CheckboxPrimitive } from "radix-ui"
import { IconCheck, IconMinus } from "@tabler/icons-react"

import { cn } from "./utils"

/**
 * Tri-state checkbox (checked / unchecked / indeterminate) built on Radix
 * Checkbox — required by the DataTable selection primitive's select-all
 * (indeterminate when some-but-not-all rows are selected) and per-row
 * checkboxes.
 *
 * PLATFORM NOTE: `checked`/`disabled`/`onCheckedChange` map directly onto a
 * Flutter Checkbox's `value`(tristate)/`onChanged` — this prop shape is
 * plain enough to mirror there. `checked` accepts `boolean | "indeterminate"`
 * (Radix's own type), same tri-state concept a Flutter `tristate: true`
 * Checkbox exposes.
 *
 * NOTE (found while building this): the plugin Blocks DSL's `checkbox` block
 * type (sdk/src/Frontend/Blocks/BlockContract.php + block-renderer.tsx)
 * currently renders its OWN inline `<input type="checkbox">` markup — a
 * second, visually-divergent implementation. Not addressed here (out of
 * scope for this task); flagged for the "richer data-bound blocks" task in
 * this same flow, which touches block-renderer.tsx anyway and could migrate
 * that block onto this shared primitive at the same time.
 */
function Checkbox({
  className,
  checked,
  ...props
}: React.ComponentProps<typeof CheckboxPrimitive.Root>) {
  return (
    <CheckboxPrimitive.Root
      data-slot="checkbox"
      checked={checked}
      className={cn(
        "peer size-4 shrink-0 rounded-[4px] border border-input shadow-xs outline-none transition-shadow focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30 disabled:cursor-not-allowed disabled:opacity-50 data-[state=checked]:border-primary data-[state=checked]:bg-primary data-[state=checked]:text-primary-foreground data-[state=indeterminate]:border-primary data-[state=indeterminate]:bg-primary data-[state=indeterminate]:text-primary-foreground",
        className
      )}
      {...props}
    >
      <CheckboxPrimitive.Indicator
        data-slot="checkbox-indicator"
        className="flex items-center justify-center text-current [&_svg]:size-3"
      >
        {checked === "indeterminate" ? <IconMinus /> : <IconCheck />}
      </CheckboxPrimitive.Indicator>
    </CheckboxPrimitive.Root>
  )
}

export { Checkbox }
