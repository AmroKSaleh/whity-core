import * as React from "react"
import { listen } from "@tauri-apps/api/event"
import type { NavGroupConfig } from "@amroksaleh/features/nav"

import { fetchPluginFeatures } from "./plugin-blocks/fetch-features"
import { resolveTablerIcon } from "./plugin-blocks/resolve-tabler-icon"
import type { PluginFeature } from "./plugin-blocks/types"

type PhpStatusEvent =
  | { state: "starting" }
  | { state: "ready"; port: number }
  | { state: "crashed"; message: string }
  | { state: "restarting"; attempt: number }
  | { state: "reloading" }
  | { state: "failed"; message: string }

type PluginSyncEvent =
  | { state: "syncing" }
  | { state: "synced"; installed: number; updated: number; removed: number }
  | { state: "failed"; message: string }

interface PluginFeaturesContextValue {
  features: PluginFeature[]
}
const PluginFeaturesContext = React.createContext<PluginFeaturesContextValue>({ features: [] })

/**
 * Fetches this device's installed plugins' declared UI features (WC-plugin-
 * block-renderer) once the PHP host is ready, and again on every completed
 * plugin sync — mirrors `plugins-page.tsx`'s existing dual-listener pattern
 * (`php:status`/`plugin-sync:status`) exactly. `App.tsx` merges the result
 * into the sidebar nav (`usePluginNavGroups`) alongside the static
 * `navConfig`, and resolves `/plugins/x/:id` routes against it
 * (`usePluginFeatures`) — this is the ONLY place either needs to change to
 * pick up a newly installed plugin's screens.
 */
export function PluginFeaturesProvider({ children }: { children: React.ReactNode }) {
  const [features, setFeatures] = React.useState<PluginFeature[]>([])

  const load = React.useCallback(async () => {
    setFeatures(await fetchPluginFeatures())
  }, [])

  React.useEffect(() => {
    void load()

    let unlistenPhp: (() => void) | undefined
    let unlistenSync: (() => void) | undefined

    void listen<PhpStatusEvent>("php:status", (event) => {
      if (event.payload.state === "ready") void load()
    }).then((un) => {
      unlistenPhp = un
    })

    void listen<PluginSyncEvent>("plugin-sync:status", (event) => {
      if (event.payload.state === "synced") void load()
    }).then((un) => {
      unlistenSync = un
    })

    return () => {
      unlistenPhp?.()
      unlistenSync?.()
    }
  }, [load])

  const value = React.useMemo(() => ({ features }), [features])
  return <PluginFeaturesContext.Provider value={value}>{children}</PluginFeaturesContext.Provider>
}

export function usePluginFeatures(): PluginFeature[] {
  return React.useContext(PluginFeaturesContext).features
}

/** Only `screen: 'blocks'` features get a nav entry today — the desktop
 * renderer doesn't yet support `crud`/`custom`/`action`/`embed` screens (see
 * `plugin-blocks/block-renderer.tsx`), so a feature this app can't render
 * shouldn't dead-end a nav click. */
export function usePluginNavGroups(): NavGroupConfig[] {
  const features = usePluginFeatures()

  return React.useMemo(() => {
    const byGroup = new Map<string, PluginFeature[]>()
    for (const feature of features) {
      if (feature.screen !== "blocks") continue
      const list = byGroup.get(feature.group) ?? []
      list.push(feature)
      byGroup.set(feature.group, list)
    }

    return Array.from(byGroup.entries()).map(([group, groupFeatures]) => ({
      id: `plugin-group-${group}`,
      label: group.charAt(0).toUpperCase() + group.slice(1),
      items: [...groupFeatures]
        .sort((a, b) => a.order - b.order)
        .map((feature) => {
          const Icon = resolveTablerIcon(feature.icon)
          return {
            id: `plugin-${feature.id}`,
            label: feature.label,
            href: `/plugins/x/${feature.id}`,
            activeMatch: `/plugins/x/${feature.id}`,
            icon: <Icon className="size-5" />,
          }
        }),
    }))
  }, [features])
}
