import { defineConfig } from "vite"
import react from "@vitejs/plugin-react"
import tailwindcss from "@tailwindcss/vite"

// Tailwind v4 as a Vite plugin (not PostCSS), mirroring packages/ui's
// Storybook config and web's own Tailwind wiring — same pipeline, three
// different build tools, proving the tokens/components don't care which one
// a client picks.
export default defineConfig({
  plugins: [react(), tailwindcss()],
})
