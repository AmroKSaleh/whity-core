import * as React from "react"
import type { Preview, Decorator } from "@storybook/nextjs-vite"
import { initialize, mswLoader } from "msw-storybook-addon"

// The app's real Tailwind entry + design tokens. Resolves `@source
// "../packages/ui/src"` relative to the web/ root, exactly as `next dev` does.
import "../app/globals.css"

import { AuthProvider } from "@/lib/auth-context"
import { ToastProvider } from "@/lib/toast-context"
import { BrandingProvider } from "@/lib/branding-context"
import { NavigationProvider } from "@/lib/navigation-context"
import { defaultHandlers, MOCK_BRANDING } from "./mocks"

// Start the MSW worker. Unhandled requests pass through (harmless in SB).
initialize({ onUnhandledRequest: "bypass" })

/**
 * Wrap every story in the real provider stack the app mounts in its root
 * layout. Order matters: Navigation reads Auth, so Auth is outermost.
 */
const withProviders: Decorator = (Story, context) => {
  const isDark = context.globals.theme === "dark"
  return (
    <AuthProvider>
      <BrandingProvider initial={MOCK_BRANDING}>
        <NavigationProvider>
          <ToastProvider>
            <div className={isDark ? "dark" : ""}>
              <div className="bg-background text-foreground min-h-svh p-8">
                <Story />
              </div>
            </div>
          </ToastProvider>
        </NavigationProvider>
      </BrandingProvider>
    </AuthProvider>
  )
}

const preview: Preview = {
  parameters: {
    layout: "fullscreen",
    msw: { handlers: defaultHandlers },
    controls: { matchers: { color: /(background|color)$/i, date: /Date$/i } },
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
  loaders: [mswLoader],
  decorators: [withProviders],
}

export default preview
