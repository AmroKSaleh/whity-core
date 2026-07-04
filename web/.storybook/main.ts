import path from "node:path"
import { fileURLToPath } from "node:url"
import type { StorybookConfig } from "@storybook/nextjs-vite"
import tailwindcss from "@tailwindcss/vite"

// Storybook 10 loads this config as an ES module, where `__dirname` is not
// defined — derive it from import.meta.url instead.
const dirname = path.dirname(fileURLToPath(import.meta.url))

/**
 * App-level component gallery. Unlike the primitives in `packages/ui` (which
 * have their own Storybook), these components depend on Next navigation, the
 * `@/lib/*` context providers, and the `/api/*` client — so this Storybook runs
 * on the Next-Vite framework (which mocks `next/navigation` & `next/link`) and
 * pairs with MSW (see .storybook/mocks.ts) so every screen renders offline.
 */
const config: StorybookConfig = {
  stories: ["../components/**/*.stories.@(ts|tsx)"],
  addons: ["@storybook/addon-docs"],
  framework: { name: "@storybook/nextjs-vite", options: {} },
  // Serves public/mockServiceWorker.js for MSW.
  staticDirs: ["../public"],
  viteFinal: async (viteConfig) => {
    viteConfig.plugins = viteConfig.plugins ?? []
    viteConfig.plugins.push(tailwindcss())

    viteConfig.resolve = viteConfig.resolve ?? {}
    // Match the app's `@/*` tsconfig path. A regex (not a bare "@" prefix) so it
    // never swallows the `@amroksaleh/ui` / `@radix-ui/*` package imports.
    viteConfig.resolve.alias = [
      ...(Array.isArray(viteConfig.resolve.alias) ? viteConfig.resolve.alias : []),
      { find: /^@\/(.*)$/, replacement: path.resolve(dirname, "../$1") },
    ]
    // npm workspaces hoist a single copy of the peer deps to the root
    // node_modules, so React/react-hook-form are already deduped. Keep an
    // explicit React dedupe as a low-cost guard for the symlinked workspace src.
    viteConfig.resolve.dedupe = [
      ...(viteConfig.resolve.dedupe ?? []),
      "react",
      "react-dom",
    ]
    return viteConfig
  },
}

export default config
