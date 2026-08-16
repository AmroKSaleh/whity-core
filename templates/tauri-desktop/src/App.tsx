import { useMemo, useState } from "react"
import { IconLogout, IconMoon, IconSun, IconUserCog } from "@tabler/icons-react"

import { Button } from "@amroksaleh/ui/button"
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
import { Sidebar } from "./sidebar"
import { useHashPath } from "./use-hash-path"
import { demoCatalogAdapter } from "./demo-catalog-tauri-adapter"
import { PrinterDemo } from "./printer-demo"
import { PluginsPage } from "./plugins-page"
import { BlockRenderer } from "./plugin-blocks/block-renderer"
import { PluginFeaturesProvider, usePluginFeatures, usePluginNavGroups } from "./plugin-nav-provider"
import { AppStateProvider, AuthGate, useAppState, useAuthGateState } from "./app-state-provider"
import { authClient } from "./auth-client"
import { useThemeMode } from "./theme-mode-context"
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
  const state = useAuthGateState()

  // Matches the website's split between `/login` (app/login/page.tsx, no
  // sidebar at all) and `app/(protected)/layout.tsx` (sidebar only once
  // authenticated): the enroll form and the locked/relogin screen render
  // full-screen with zero chrome, exactly like an anonymous caller bounced to
  // `/login` there. The sidebar mounts ONLY once there is an authenticated,
  // unlocked session to show it around.
  if (state !== "ready") {
    return <AuthGate>{null}</AuthGate>
  }

  // Plugin features (and therefore their nav entries + /plugins/x/:id routes)
  // only make sense once there's a device to fetch them FROM — see
  // plugin-nav-provider.tsx's own dual php:status/plugin-sync:status
  // listeners for when it (re)fetches.
  return (
    <PluginFeaturesProvider>
      <AuthenticatedApp />
    </PluginFeaturesProvider>
  )
}

function AuthenticatedApp() {
  const path = useHashPath()
  const pluginNavGroups = usePluginNavGroups()
  const pluginFeatures = usePluginFeatures()
  const mergedNav = useMemo(() => ({ groups: [...navConfig.groups, ...pluginNavGroups] }), [pluginNavGroups])
  const navGroups = useMemo(() => resolveNavGroups(mergedNav, path), [mergedNav, path])
  const { controller, auth, reloadAuth } = useAppState()
  const status = useSyncStatus(controller)

  // Mirrors ReloginScreen's onReenroll (app-state-provider.tsx): clear the
  // keychain credential + auth_state (local DATA stays intact), then refresh
  // both the auth gate and the sync controller so the app falls back to
  // EnrollForm — the only screen with a Server field, which is otherwise
  // unreachable once a device is enrolled.
  const handleLogout = async () => {
    await authClient.logout()
    await reloadAuth()
    await controller.refresh()
  }

  const sidebar = (
    <Sidebar
      groups={navGroups}
      siteName="Whity"
      subtitle="Desktop"
      footer={auth?.enrolled ? <AccountFooter email={auth.email} serverUrl={auth.serverUrl} onLogout={handleLogout} /> : undefined}
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
  } else if (path === "/plugins") {
    body = (
      <>
        <PageHeader
          title="Plugins"
          description="Plugins your organization has enabled sync automatically when you sign in — nothing to install or manage here. See src-tauri/src/plugins/reconcile.rs."
        />
        <PluginsPage />
      </>
    )
  } else if (path.startsWith("/plugins/x/")) {
    // Generic route for ANY installed plugin's screen:'blocks' feature — one
    // provider + one route + one renderer serve every plugin, with zero
    // per-feature TypeScript (see plugin-nav-provider.tsx / plugin-blocks/).
    // Deliberately namespaced under /plugins/x/ so it can never collide with
    // /plugins above, mirroring the website's own /admin/x/[featureId].
    const featureId = path.slice("/plugins/x/".length)
    const feature = pluginFeatures.find((f) => f.id === featureId)
    body = feature ? (
      <>
        <PageHeader title={feature.label} />
        <BlockRenderer feature={feature} />
      </>
    ) : (
      <PageHeader title="Not found" description="This plugin feature isn't installed on this device." />
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
    // Matches the website's protected layout (web/app/(protected)/layout.tsx),
    // which caps page content at max-w-7xl rather than letting it stretch
    // full-bleed on a wide window. `AuthenticatedApp` only ever mounts once
    // `AppInner`'s `useAuthGateState()` is already "ready", so there's no
    // second gate check needed here.
    <PageShell sidebar={sidebar} topBar={topBar} contentClassName="max-w-7xl">
      {body}
    </PageShell>
  )
}

/**
 * Sidebar footer shown only once enrolled — mirrors the website's footer row
 * stack (web/components/sidebar.tsx: account row, theme toggle, logout
 * button), minus the tenant/language switcher rows the website has (not
 * applicable to a single-tenant device with no i18n here). This is also the
 * ONLY place in the app to log out (which clears the keychain credential +
 * auth_state, dropping the gate back to EnrollForm).
 */
function AccountFooter({
  email,
  serverUrl,
  onLogout,
}: {
  email: string | null
  serverUrl: string | null
  onLogout: () => void | Promise<void>
}) {
  const [busy, setBusy] = useState(false)
  const { resolved, toggle } = useThemeMode()
  const isDark = resolved === "dark"

  return (
    <div className="space-y-2">
      <div className="flex items-center gap-2 rounded-lg bg-background px-2 py-2">
        <IconUserCog className="size-5 shrink-0 text-muted-foreground" />
        <div className="min-w-0">
          <span className="block truncate text-xs text-muted-foreground">Logged in as</span>
          {email && <span className="block truncate text-sm font-medium">{email}</span>}
          {serverUrl && <span className="block truncate text-[11px] text-muted-foreground/70">{serverUrl}</span>}
        </div>
      </div>

      <Button
        onClick={toggle}
        variant="outline"
        className="w-full justify-start"
        aria-label="Toggle color scheme"
      >
        {isDark ? <IconSun className="size-5 me-3 shrink-0" /> : <IconMoon className="size-5 me-3 shrink-0" />}
        {isDark ? "Light mode" : "Dark mode"}
      </Button>

      <Button
        variant="outline"
        className="w-full justify-start"
        disabled={busy}
        onClick={async () => {
          setBusy(true)
          try {
            await onLogout()
          } finally {
            setBusy(false)
          }
        }}
      >
        <IconLogout className="size-5 me-3 shrink-0" />
        {busy ? "Logging out…" : "Log out"}
      </Button>
    </div>
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
