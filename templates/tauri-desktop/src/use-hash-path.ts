import { useEffect, useState } from "react"

/** Read `#/foo` as `/foo`, defaulting to `/` when there's no hash yet. */
function readHashPath(): string {
  const hash = window.location.hash.slice(1)
  return hash === "" ? "/" : hash
}

/**
 * Minimal hash-router substitute (mirrors packages/spa-harness/src/use-hash-path.ts).
 * Swap this out for a real router as the app grows past a couple of routes —
 * it's here purely so the boilerplate has zero routing dependencies to start.
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
