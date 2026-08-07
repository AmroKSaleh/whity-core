"use client"

import * as React from "react"
import { Collapsible as CollapsiblePrimitive } from "radix-ui"

/**
 * Thin expand/collapse primitive (Root/Trigger/Content) — a keyboard- and
 * screen-reader-accessible replacement for hand-rolled expand/collapse state
 * (e.g. web/app/(protected)/admin/ous/ou-tree.tsx's manual `Set<number>`
 * toggle state, or web/app/onboarding/page.tsx's native `<details>` used
 * specifically "so it needs no extra dependency" — this primitive removes
 * that constraint since the dependency is already bundled via `radix-ui`).
 * No default styling is applied to Content — animate/size it per use site
 * via `data-[state=open]`/`data-[state=closed]` (matches this kit's other
 * Radix wrappers, which leave animation to the consumer's className).
 */

function Collapsible({ ...props }: React.ComponentProps<typeof CollapsiblePrimitive.Root>) {
  return <CollapsiblePrimitive.Root data-slot="collapsible" {...props} />
}

function CollapsibleTrigger({
  ...props
}: React.ComponentProps<typeof CollapsiblePrimitive.Trigger>) {
  return <CollapsiblePrimitive.Trigger data-slot="collapsible-trigger" {...props} />
}

function CollapsibleContent({
  ...props
}: React.ComponentProps<typeof CollapsiblePrimitive.Content>) {
  return <CollapsiblePrimitive.Content data-slot="collapsible-content" {...props} />
}

export { Collapsible, CollapsibleTrigger, CollapsibleContent }
