import * as React from "react"
import type { Preview, Decorator } from "@storybook/react-vite"

// The package's own design tokens + Tailwind entry. Dark tokens live under
// `.dark {}` and the dark variant is `&:is(.dark *)`, so wrapping a story in a
// `.dark` element is all that's needed to preview dark mode accurately.
import "../src/globals.css"

const withTheme: Decorator = (Story, context) => {
  const isDark = context.globals.theme === "dark"
  const isRtl = context.globals.direction === "rtl"
  return (
    <div className={isDark ? "dark" : ""} dir={isRtl ? "rtl" : "ltr"}>
      <div className="bg-background text-foreground flex min-h-svh items-start p-8">
        <Story />
      </div>
    </div>
  )
}

const preview: Preview = {
  parameters: {
    layout: "fullscreen",
    controls: {
      matchers: { color: /(background|color)$/i, date: /Date$/i },
    },
  },
  globalTypes: {
    theme: {
      description: "Light / dark token set",
      defaultValue: "light",
      toolbar: {
        title: "Theme",
        icon: "contrast",
        items: [
          { value: "light", title: "Light", icon: "sun" },
          { value: "dark", title: "Dark", icon: "moon" },
        ],
        dynamicTitle: true,
      },
    },
    // Sets `dir` on the story wrapper — the same attribute the real app sets
    // on <html> (see web/lib/direction-context.tsx) — so every story can be
    // spot-checked for RTL/logical-property correctness without a bespoke
    // per-component RTL story.
    direction: {
      description: "Text/layout direction",
      defaultValue: "ltr",
      toolbar: {
        title: "Direction",
        icon: "transfer",
        items: [
          { value: "ltr", title: "LTR", icon: "arrowright" },
          { value: "rtl", title: "RTL", icon: "arrowleft" },
        ],
        dynamicTitle: true,
      },
    },
  },
  decorators: [withTheme],
}

export default preview
