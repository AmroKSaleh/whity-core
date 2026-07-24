import { useEffect, useState } from "react"

/** Read `#/foo` as `/foo`, defaulting to `/` when there's no hash yet. */
function readHashPath(): string {
  const hash = window.location.hash.slice(1)
  return hash === "" ? "/" : hash
}

/**
 * Minimal hash-router substitute — just enough to prove the nav contract's
 * link-adapter injection and `resolveNavGroups`'s active-route matching work
 * with a router-less client. A real Tauri/Vite SPA would likely reach for a
 * proper router; this harness deliberately doesn't, to keep the "no Next.js
 * anywhere in the render path" claim easy to audit.
 */
export function useHashPath(): string {
  const [path, setPath] = useState(readHashPath)

  useEffect(() => {
    const onHashChange = () => setPath(readHashPath())
    window.addEventListener("hashchange", onHashChange)
    return () => window.removeEventListener("hashchange", onHashChange)
  }, [])

  return path
}
