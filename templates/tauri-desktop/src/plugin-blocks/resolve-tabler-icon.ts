import type * as React from "react"

import * as TablerIcons from "@tabler/icons-react"
import { IconPointFilled } from "@tabler/icons-react"

/**
 * Maps a kebab/snake/space-separated icon name (as a plugin declares it,
 * e.g. "message-circle") to the matching `@tabler/icons-react` component
 * (`IconMessageCircle`), falling back to a neutral dot when unknown. Mirrors
 * `web/components/plugin/blocks/block-renderer.tsx`'s `resolveIcon` exactly
 * — reused here for both `icon` blocks and plugin nav-item icons (see
 * `plugin-nav-provider.tsx`).
 */
export function resolveTablerIcon(name: string | null | undefined): React.ComponentType<{ className?: string }> {
  if (!name) return IconPointFilled
  const pascal = name
    .trim()
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join("")
  const componentName = pascal.startsWith("Icon") ? pascal : `Icon${pascal}`
  const icons = TablerIcons as unknown as Record<string, React.ComponentType<{ className?: string }> | undefined>
  return icons[componentName] ?? IconPointFilled
}
