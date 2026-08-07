import { useMemo } from "react"

import { AppSidebar } from "@amroksaleh/ui/app-sidebar"
import { PageHeader } from "@amroksaleh/ui/page-header"
import { PageShell } from "@amroksaleh/ui/page-shell"
import { resolveNavGroups } from "@amroksaleh/features/nav"
import { DemoCatalogList, DemoCatalogDetail } from "@amroksaleh/features/demo-catalog"
import {
  ConflictResolver,
  UnsyncedBanner,
  useSyncStatus,
  type SyncStatus,
} from "@amroksaleh/features/sync"

import { navConfig } from "./nav-config"
import { HashLinkAdapter } from "./hash-link"
import { useHashPath } from "./use-hash-path"
import { demoCatalogAdapter } from "./demo-catalog-tauri-adapter"
import { PrinterDemo } from "./printer-demo"
import { AppStateProvider, AuthGate, useAppState } from "./app-state-provider"
import { appT } from "./sync-i18n"

function navigate(path: string) {
  window.location.hash = path
}

export function App() {
  return (
    <AppStateProvider>
      <AppInner />
    </AppStateProvider>
  )
}

function AppInner() {
  const path = useHashPath()
  const navGroups = useMemo(() => resolveNavGroups(navConfig, path), [path])
  const { controller, auth } = useAppState()
  const status = useSyncStatus(controller)

  const sidebar = (
    <AppSidebar
      groups={navGroups}
      linkComponent={HashLinkAdapter}
      header={<span className="px-2 text-sm font-semibold">Whity Desktop</span>}
    />
  )

  // App-wide sync strip: self-hides when fully synced, so it's only chrome when
  // there's something to say (offline / unsynced / syncing / conflicts / locked).
  // Suppressed before enrollment — the "locked (not enrolled)" state is the
  // first-run enroll form's job, not a banner's.
  const topBar =
    auth?.enrolled && status && bannerVisible(status) ? (
      <div className="px-6 pt-4">
        <UnsyncedBanner
          status={status}
          t={appT}
          onSyncNow={() => void controller.syncNow()}
          onReviewConflicts={() => navigate("/conflicts")}
        />
      </div>
    ) : undefined

  let body: React.ReactNode
  if (path === "/demo-catalog") {
    body = (
      <>
        <PageHeader
          title="Demo Catalog"
          description="Backed by real local SQLite via a Tauri command (see src-tauri/src/commands/items.rs). Edits are saved locally first, then synced to the Whity backend — watch the banner above."
        />
        <DemoCatalogList
          adapter={demoCatalogAdapter}
          t={appT}
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
          t={appT}
          itemId={itemId}
          onCancel={() => navigate("/demo-catalog")}
          onSaved={() => {
            navigate("/demo-catalog")
            // Push the just-saved change right away (best-effort; stays pending offline).
            void controller.syncNow()
          }}
        />
      </>
    )
  } else if (path === "/conflicts") {
    body = (
      <>
        <PageHeader
          title="Sync conflicts"
          description="A record was changed here and on the server at the same time. Pick a value per field — or type a merged one — and save."
        />
        <ConflictsPage />
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
        description="Whity Tauri desktop boilerplate — @amroksaleh/ui + @amroksaleh/features, a real SQLite-backed feature, offline-first sync, and a native-crate command example. Open a page from the sidebar."
      />
    )
  }

  return (
    <PageShell sidebar={sidebar} topBar={topBar}>
      <AuthGate>{body}</AuthGate>
    </PageShell>
  )
}

function ConflictsPage() {
  const { controller } = useAppState()
  const status = useSyncStatus(controller)
  const conflicts = status?.conflicts ?? []

  if (conflicts.length === 0) {
    return <p className="text-sm text-muted-foreground">No conflicts to resolve.</p>
  }

  return (
    <div className="grid gap-6">
      {conflicts.map((conflict) => (
        <div
          key={conflict.id}
          className="max-w-2xl rounded-lg border border-border bg-card p-5 text-card-foreground shadow-sm"
        >
          <ConflictResolver
            conflict={conflict}
            t={appT}
            onResolve={(resolution) => void controller.resolveConflict(resolution)}
          />
        </div>
      ))}
    </div>
  )
}

function bannerVisible(status: SyncStatus): boolean {
  return (
    !status.online ||
    status.syncing ||
    status.unsyncedCount > 0 ||
    status.conflicts.length > 0 ||
    Boolean(status.locked)
  )
}
