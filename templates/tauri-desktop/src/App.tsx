import { useMemo } from "react"

import { AppSidebar } from "@amroksaleh/ui/app-sidebar"
import { PageHeader } from "@amroksaleh/ui/page-header"
import { PageShell } from "@amroksaleh/ui/page-shell"
import { resolveNavGroups } from "@amroksaleh/features/nav"
import { DemoCatalogList, DemoCatalogDetail } from "@amroksaleh/features/demo-catalog"

import { navConfig } from "./nav-config"
import { HashLinkAdapter } from "./hash-link"
import { useHashPath } from "./use-hash-path"
import { demoCatalogAdapter } from "./demo-catalog-tauri-adapter"
import { PrinterDemo } from "./printer-demo"

function navigate(path: string) {
  window.location.hash = path
}

export function App() {
  const path = useHashPath()
  const navGroups = useMemo(() => resolveNavGroups(navConfig, path), [path])

  const sidebar = (
    <AppSidebar
      groups={navGroups}
      linkComponent={HashLinkAdapter}
      header={<span className="px-2 text-sm font-semibold">Whity Desktop</span>}
    />
  )

  let body: React.ReactNode
  if (path === "/demo-catalog") {
    body = (
      <>
        <PageHeader
          title="Demo Catalog"
          description="Backed by real local SQLite via a Tauri command (see src-tauri/src/commands/items.rs) — the exact same DemoCatalogList/DemoCatalogDetail components web/ and the SPA harness render."
        />
        <DemoCatalogList
          adapter={demoCatalogAdapter}
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
          adapter={demoCatalogAdapter}
          itemId={itemId}
          onCancel={() => navigate("/demo-catalog")}
          onSaved={() => navigate("/demo-catalog")}
        />
      </>
    )
  } else if (path === "/printer-demo") {
    body = (
      <>
        <PageHeader title="Printer demo" description="A worked example of a native-crate command." />
        <PrinterDemo />
      </>
    )
  } else {
    body = (
      <PageHeader
        title="Home"
        description="Whity Tauri desktop boilerplate — @amroksaleh/ui + @amroksaleh/features, a real SQLite-backed feature, and a native-crate command example. Open a page from the sidebar."
      />
    )
  }

  return <PageShell sidebar={sidebar}>{body}</PageShell>
}
