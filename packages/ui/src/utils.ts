import * as React from "react"
import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/**
 * Hook to detect if dark mode is active anywhere in the document (on <html>,
 * <body>, or any ancestor container like Storybook root). Essential for
 * portaled overlays (Popover, DropdownMenu, Select, Tooltip, Sheet) mounting
 * outside of the main DOM tree.
 */
export function useIsDarkMode(): boolean {
  const [isDark, setIsDark] = React.useState(false)

  React.useEffect(() => {
    const checkDark = () => {
      if (typeof document === "undefined") return
      const dark =
        document.documentElement.classList.contains("dark") ||
        document.body.classList.contains("dark") ||
        !!document.querySelector(".dark")
      setIsDark(dark)
    }

    checkDark()

    if (typeof document !== "undefined") {
      const observer = new MutationObserver(checkDark)
      observer.observe(document.documentElement, { attributes: true, attributeFilter: ["class"] })
      observer.observe(document.body, { attributes: true, attributeFilter: ["class"] })

      const sbRoot = document.getElementById("storybook-root")
      if (sbRoot) {
        observer.observe(sbRoot, { attributes: true, attributeFilter: ["class"] })
      }

      return () => observer.disconnect()
    }
  }, [])

  return isDark
}
