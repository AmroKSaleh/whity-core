import * as React from "react"
import type { Preview, Decorator } from "@storybook/react-vite"

// The package's own design tokens + Tailwind entry. Dark tokens live under
// `.dark {}` and the dark variant is `&:is(.dark *)`, so wrapping a story in a
// `.dark` element is all that's needed to preview dark mode accurately.
import "../src/globals.css"

const withTheme: Decorator = (Story, context) => {
  const isDark = context.globals.theme === "dark"
  return (
    <div className={isDark ? "dark" : ""}>
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
  },
  decorators: [withTheme],
}

export default preview
