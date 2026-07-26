import { defineConfig } from "vite"
import react from "@vitejs/plugin-react"
import tailwindcss from "@tailwindcss/vite"

// Standard Tauri + Vite recipe (matches create-tauri-app's template, and
// Elmak-Desktop's existing scaffold): a fixed dev port Tauri's `devUrl`
// points at, plus Tailwind v4 as a Vite plugin (mirrors packages/ui's
// Storybook config and packages/spa-harness — same pipeline everywhere).
export default defineConfig({
  plugins: [react(), tailwindcss()],

  clearScreen: false,
  server: {
    port: 1420,
    strictPort: true,
    watch: {
      // Don't watch src-tauri's target/ build output — Vite HMR-reloading on
      // every Rust rebuild artifact is pure noise.
      ignored: ["**/src-tauri/**"],
    },
  },
})
