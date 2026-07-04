import type { StorybookConfig } from "@storybook/react-vite"
import tailwindcss from "@tailwindcss/vite"

/**
 * Storybook lives inside the UI package so the component gallery is fully
 * decoupled from the `web` Next.js app — no backend, auth, or app build needed
 * to tune primitives. It renders through the same Tailwind v4 pipeline and the
 * package's own `src/globals.css` design tokens, so output is pixel-accurate.
 */
const config: StorybookConfig = {
  stories: ["../src/**/*.stories.@(ts|tsx)"],
  addons: ["@storybook/addon-docs"],
  framework: {
    name: "@storybook/react-vite",
    options: {},
  },
  viteFinal: async (viteConfig) => {
    // Tailwind v4 is a Vite plugin (not PostCSS) here — mirrors how `web`
    // wires `@tailwindcss/vite`. Content scanning is automatic in v4.
    viteConfig.plugins = viteConfig.plugins ?? []
    viteConfig.plugins.push(tailwindcss())
    return viteConfig
  },
}

export default config
