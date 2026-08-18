import { StrictMode } from "react"
import { createRoot } from "react-dom/client"
import { isThemeModePreference, resolveIsDark, THEME_STORAGE_KEY } from "@amroksaleh/ui/theme-mode"

import "./index.css"
import { App } from "./App"
import { ThemeModeProvider } from "./theme-mode-context"

// Apply the resolved color scheme BEFORE the first render — the same "no
// flash of the wrong theme" requirement the website solves with a blocking
// <script> in <head> (see theme-mode-context.tsx's doc comment for why that
// trick doesn't apply here: a Tauri window has no server-rendered HTML to
// flash-then-correct, just this one synchronous step before render()).
const storedPreference = localStorage.getItem(THEME_STORAGE_KEY)
const preference = isThemeModePreference(storedPreference) ? storedPreference : "system"
document.documentElement.classList.toggle(
  "dark",
  resolveIsDark(preference, window.matchMedia("(prefers-color-scheme: dark)").matches)
)

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <ThemeModeProvider>
      <App />
    </ThemeModeProvider>
  </StrictMode>
)
