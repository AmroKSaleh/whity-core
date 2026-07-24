import { useMemo } from "react"

import { AppSidebar } from "@amroksaleh/ui/app-sidebar"
import { PageHeader } from "@amroksaleh/ui/page-header"
import { PageShell } from "@amroksaleh/ui/page-shell"
import { resolveNavGroups, exampleNavConfig } from "@amroksaleh/features/nav"
import { DemoCatalogList, DemoCatalogDetail } from "@amroksaleh/features/demo-catalog"

import { HashLinkAdapter } from "./hash-link"
import { useHashPath } from "./use-hash-path"
import { createInMemoryDemoCatalogAdapter } from "./in-memory-adapter"

// One adapter instance for the harness's lifetime — same as a real app would
// construct its SQLite-backed adapter once, not per-render.
const adapter = createInMemoryDemoCatalogAdapter()

function navigate(path: string) {
  window.location.hash = path
}

export function App() {
  const path = useHashPath()
  const navGroups = useMemo(() => resolveNavGroups(exampleNavConfig, path), [path])

  const sidebar = (
    <AppSidebar
      groups={navGroups}
      linkComponent={HashLinkAdapter}
      header={<span className="px-2 text-sm font-semibold">SPA harness</span>}
    />
  )

  let body: React.ReactNode
  if (path === "/demo-catalog") {
    body = (
      <>
        <PageHeader
          title="Demo Catalog"
          description="Same DemoCatalogList component web/ renders — in-memory adapter, hash-router links, zero Next.js."
        />
        <DemoCatalogList
          adapter={adapter}
          onSelect={(id) => navigate(`/demo-catalog/${id}`)}
          onCreate={() => navigate("/demo-catalog/new")}
        />
      </>
    )
  } else if (path.startsWith("/demo-catalog/")) {
    const segment = path.slice("/demo-catalog/".length)
    const itemId = segment === "new" ? null : Number(segment)
    body = (
      <>
        <PageHeader title={itemId === null ? "New item" : `Item #${itemId}`} />
        <DemoCatalogDetail
          adapter={adapter}
          itemId={itemId}
          onCancel={() => navigate("/demo-catalog")}
          onSaved={() => navigate("/demo-catalog")}
        />
      </>
    )
  } else {
    body = (
      <PageHeader
        title="Home"
        description="Minimal Vite + Tailwind v4 SPA — no Next.js. Open Demo Catalog from the sidebar."
      />
    )
  }

  return <PageShell sidebar={sidebar}>{body}</PageShell>
}
