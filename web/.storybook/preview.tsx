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
import { DirectionProvider } from "@/lib/direction-context"
import { ThemeModeProvider } from "@/lib/theme-mode-context"
import { ToastContainer } from "@/components/ui/toast-container"
import { CapabilitiesProvider } from "@/lib/capabilities-context"
import { defaultHandlers, MOCK_BRANDING } from "./mocks"

// Start the MSW worker. Unhandled requests pass through (harmless in SB).
initialize({ onUnhandledRequest: "bypass" })

/**
 * Wrap every story in the real provider stack the app mounts in its root
 * layout. Order matters: Navigation reads Auth, so Auth is outermost.
 * `ToastProvider` only supplies context — `ToastContainer` is what draws the
 * toasts, so it's mounted as a sibling of the story exactly as `app/layout.tsx`
 * mounts it, otherwise any toast a story raises would be silently swallowed.
 */
const withProviders: Decorator = (Story, context) => {
  const isDark = context.globals.theme === "dark"
  return (
    <AuthProvider>
      <CapabilitiesProvider>
        <BrandingProvider initial={MOCK_BRANDING}>
          <ThemeModeProvider>
            <DirectionProvider>
              <NavigationProvider>
                <ToastProvider>
                  <div className={isDark ? "dark" : ""}>
                    <div className="bg-background text-foreground min-h-svh p-8">
                      <Story />
                    </div>
                  </div>
                  <ToastContainer />
                </ToastProvider>
              </NavigationProvider>
            </DirectionProvider>
          </ThemeModeProvider>
        </BrandingProvider>
      </CapabilitiesProvider>
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
