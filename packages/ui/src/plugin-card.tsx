"use client"

import * as React from "react"
import {
  IconAlertCircle,
  IconArrowBackUp,
  IconCheck,
  IconChevronLeft,
  IconChevronRight,
  IconDownload,
  IconHistory,
  IconInfoCircle,
  IconLock,
  IconPhoto,
  IconPower,
  IconPuzzle,
  IconRefresh,
  IconSettings,
  IconShieldCheck,
  IconStarFilled,
} from "@tabler/icons-react"

import { cn } from "./utils"
import { Card, CardHeader, CardTitle, CardDescription, CardAction, CardContent, CardFooter } from "./card"
import { Button } from "./button"
import { Badge } from "./badge"
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from "./dialog"
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuLabel, DropdownMenuSeparator, DropdownMenuTrigger } from "./dropdown-menu"
import { Tabs, TabsContent, TabsList, TabsTrigger } from "./tabs"

export type InstalledPluginState = "active" | "inactive" | "disabled" | "update-available"

export interface PluginVersion {
  version: string
  releasedAt: string
  changelog?: string
  isCurrent?: boolean
}

export interface PluginItem {
  id: string
  name: string
  author: string
  version: string
  latestVersion?: string
  description: string
  longDescription?: string
  category: string
  rating: number
  reviewCount: number
  icon?: React.ReactNode
  bannerUrl?: string
  bannerGradient?: string
  screenshots?: string[]
  state?: InstalledPluginState
  verified?: boolean
  permissions?: string[]
  versions?: PluginVersion[]
}

export interface PluginStoreCardProps extends React.ComponentProps<"div"> {
  plugin: PluginItem
  onInstall?: (plugin: PluginItem) => void
  onConfigure?: (plugin: PluginItem) => void
}

/**
 * Real-Store Marketplace Card with Store Banner Preview image, clean borderless icon,
 * rating stars positioned above title using neutral tones, quick install action, and "More Info" details dialog.
 */
export function PluginStoreCard({
  className,
  plugin,
  onInstall,
  onConfigure,
  ...props
}: PluginStoreCardProps) {
  const [detailsOpen, setDetailsOpen] = React.useState(false)

  return (
    <>
      <Card
        interactive
        variant="elevated"
        className={cn("group/plugin flex flex-col justify-between w-full transition-all overflow-hidden", className)}
        {...props}
      >
        {/* Real-Store Banner Preview Image Header (data-slot=card-banner removes top card padding for flush alignment) */}
        <div data-slot="card-banner" className="relative h-32 w-full overflow-hidden border-b border-border/40 bg-muted">
          {plugin.bannerUrl ? (
            <img
              src={plugin.bannerUrl}
              alt={plugin.name}
              className="size-full object-cover transition-transform duration-300 group-hover/plugin:scale-105"
            />
          ) : (
            <div
              className={cn(
                "size-full flex items-center justify-center bg-linear-to-br transition-all duration-300 group-hover/plugin:scale-105 p-4 text-center",
                plugin.bannerGradient ?? "from-primary/20 via-muted to-muted/80"
              )}
            >
              <div className="flex items-center gap-2 text-foreground/40 font-heading font-semibold text-xs uppercase tracking-widest">
                <IconPhoto className="size-4" />
                <span>{plugin.category} Store Preview</span>
              </div>
            </div>
          )}

          {plugin.verified && (
            <div className="absolute top-2.5 right-2.5">
              <Badge variant="default" className="text-[10px] shadow-xs">
                Verified
              </Badge>
            </div>
          )}
        </div>

        <CardHeader className="pt-3 space-y-1.5">
          {/* Rating Stars ABOVE Plugin Title in Color Neutral Styling */}
          <div className="flex items-center gap-1.5 text-[11px] font-medium text-muted-foreground/80">
            <IconStarFilled className="size-3.5 text-muted-foreground/60" />
            <span className="font-semibold text-foreground">{plugin.rating.toFixed(1)}</span>
            <span className="text-[10px] text-muted-foreground/70">({plugin.reviewCount} reviews)</span>
          </div>

          <div className="flex items-center gap-3">
            {/* Clean Plugin Icon without bubble container */}
            <div className="shrink-0 text-primary [&_svg]:size-6">
              {plugin.icon ?? <IconPuzzle />}
            </div>
            <div className="min-w-0 flex-1">
              <CardTitle className="text-sm font-bold truncate">{plugin.name}</CardTitle>
              <CardDescription className="text-xs truncate">
                by {plugin.author} · v{plugin.version}
              </CardDescription>
            </div>
          </div>
        </CardHeader>

        <CardContent className="space-y-3">
          <p className="text-xs text-muted-foreground line-clamp-2 leading-relaxed">
            {plugin.description}
          </p>

          <div className="flex items-center gap-2">
            <Badge variant="outline" className="text-[10px]">{plugin.category}</Badge>
          </div>
        </CardContent>

        <CardFooter className="border-t border-border/40 pt-3 flex items-center justify-between gap-2">
          <Button
            size="sm"
            variant="ghost"
            onClick={() => setDetailsOpen(true)}
            className="text-xs text-muted-foreground hover:text-foreground"
          >
            <IconInfoCircle data-icon="inline-start" />
            More Info
          </Button>

          <Button
            size="sm"
            variant="default"
            onClick={() => onInstall?.(plugin)}
          >
            <IconDownload data-icon="inline-start" />
            Install
          </Button>
        </CardFooter>
      </Card>

      {/* Plugin Details Popup Modal */}
      <PluginDetailsModal
        open={detailsOpen}
        onOpenChange={setDetailsOpen}
        plugin={plugin}
        onInstall={onInstall}
      />
    </>
  )
}

export interface InstalledPluginCardProps extends React.ComponentProps<"div"> {
  plugin: PluginItem
  onToggleState?: (plugin: PluginItem, newState: "active" | "inactive") => void
  onUpdate?: (plugin: PluginItem) => void
  onRollback?: (plugin: PluginItem, targetVersion: string) => void
  onConfigure?: (plugin: PluginItem) => void
  /**
   * Copy for the card's chrome. Each entry defaults to the English below, so
   * an untranslated caller renders exactly as it does today.
   *
   * `updateAvailable` is a function because the version number sits inside
   * the phrase and languages place it differently.
   */
  labels?: {
    active?: string
    inactive?: string
    disabledBySystem?: string
    updateAvailable?: (latestVersion?: string) => string
    governanceNotice?: string
    rollback?: string
    versionHistory?: string
    configure?: string
  }
}

/**
 * Management Card for installed plugins with toned-down background colors, clean borderless icons (no bubble container),
 * semantic state badges using our design system's official blue (`info-solid`), and version rollback capability.
 */
export function InstalledPluginCard({
  className,
  plugin,
  onToggleState,
  onUpdate,
  onRollback,
  onConfigure,
  labels,
  ...props
}: InstalledPluginCardProps) {
  const text = {
    active: "Active",
    inactive: "Inactive",
    disabledBySystem: "Disabled by System",
    updateAvailable: (latestVersion?: string) => `Update Available v${latestVersion}`,
    governanceNotice:
      "This plugin has been automatically disabled by system governance due to security or compatibility policy.",
    rollback: "Rollback",
    versionHistory: "Version History",
    configure: "Configure plugin",
    ...labels,
  }
  const [detailsOpen, setDetailsOpen] = React.useState(false)
  const [rollbackTarget, setRollbackTarget] = React.useState<string | null>(null)

  const state = plugin.state ?? "active"

  const stateConfig = {
    active: {
      cardVariant: "success" as const,
      borderStyle: "solid" as const,
      badge: <Badge variant="success-solid">{text.active}</Badge>,
      disabled: false,
    },
    inactive: {
      cardVariant: "flat" as const,
      borderStyle: "solid" as const,
      badge: <Badge variant="secondary">{text.inactive}</Badge>,
      disabled: false,
    },
    disabled: {
      cardVariant: "destructive" as const,
      borderStyle: "dotted" as const,
      badge: <Badge variant="destructive-solid">{text.disabledBySystem}</Badge>,
      disabled: true,
    },
    "update-available": {
      cardVariant: "info" as const,
      borderStyle: "solid" as const,
      badge: <Badge variant="info-solid">{text.updateAvailable(plugin.latestVersion)}</Badge>,
      disabled: false,
    },
  }[state]

  const pastVersions = (plugin.versions ?? [
    { version: plugin.version, releasedAt: "Current", isCurrent: true },
    { version: "2.3.1", releasedAt: "2 weeks ago" },
    { version: "2.2.0", releasedAt: "1 month ago" },
  ]).filter((v) => !v.isCurrent && v.version !== plugin.version)

  return (
    <>
      <Card
        variant={stateConfig.cardVariant}
        borderStyle={stateConfig.borderStyle}
        disabled={stateConfig.disabled}
        className={cn("group/installed flex flex-col justify-between w-full transition-all", className)}
        {...props}
      >
        <CardHeader>
          <div className="flex items-center gap-3">
            {/* Completely Clean Plugin Icon without notification or status bubble overlay */}
            <div className="shrink-0 text-foreground [&_svg]:size-6">
              {plugin.icon ?? <IconPuzzle />}
            </div>
            <div className="min-w-0 flex-1">
              <CardTitle className="text-sm font-bold truncate">{plugin.name}</CardTitle>
              <CardDescription className="text-xs truncate">
                Installed v{plugin.version} · by {plugin.author}
              </CardDescription>
            </div>
          </div>
          <CardAction>{stateConfig.badge}</CardAction>
        </CardHeader>

        <CardContent className="space-y-3">
          <p className="text-xs text-muted-foreground line-clamp-2 leading-relaxed">
            {state === "disabled"
              ? text.governanceNotice
              : plugin.description}
          </p>

          <div className="flex flex-wrap items-center gap-2">
            <Badge variant="outline" className="text-[10px]">{plugin.category}</Badge>

            {/* Version Rollback Dropdown */}
            {pastVersions.length > 0 && !stateConfig.disabled && (
              <DropdownMenu>
                <DropdownMenuTrigger asChild>
                  <Button size="xs" variant="outline" className="ms-auto text-[11px] gap-1.5 h-6">
                    <IconHistory className="size-3" />
                    <span>{text.rollback}</span>
                  </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end" className="w-56">
                  <DropdownMenuLabel className="text-xs">{text.versionHistory}</DropdownMenuLabel>
                  <DropdownMenuSeparator />
                  {pastVersions.map((ver) => (
                    <DropdownMenuItem
                      key={ver.version}
                      onClick={() => setRollbackTarget(ver.version)}
                      className="flex items-center justify-between text-xs cursor-pointer"
                    >
                      <div className="flex items-center gap-2">
                        <IconArrowBackUp className="size-3.5 text-muted-foreground" />
                        <span className="font-semibold">v{ver.version}</span>
                      </div>
                      <span className="text-[10px] text-muted-foreground">{ver.releasedAt}</span>
                    </DropdownMenuItem>
                  ))}
                </DropdownMenuContent>
              </DropdownMenu>
            )}
          </div>
        </CardContent>

        <CardFooter className="border-t border-border/40 pt-3 flex items-center justify-between gap-2">
          <Button
            size="sm"
            variant="ghost"
            onClick={() => setDetailsOpen(true)}
            className="text-xs text-muted-foreground hover:text-foreground"
          >
            <IconInfoCircle data-icon="inline-start" />
            Details
          </Button>

          <div className="flex items-center gap-2">
            {state === "update-available" && (
              <Button
                size="sm"
                variant="info-solid"
                onClick={() => onUpdate?.(plugin)}
              >
                <IconRefresh data-icon="inline-start" />
                Update v{plugin.latestVersion}
              </Button>
            )}

            {state === "active" && (
              <Button
                size="sm"
                variant="outline"
                onClick={() => onToggleState?.(plugin, "inactive")}
              >
                <IconPower data-icon="inline-start" />
                Deactivate
              </Button>
            )}

            {state === "inactive" && (
              <Button
                size="sm"
                variant="success-solid"
                onClick={() => onToggleState?.(plugin, "active")}
              >
                <IconCheck data-icon="inline-start" />
                Activate
              </Button>
            )}

            {state === "disabled" && (
              <Button size="sm" variant="outline" disabled>
                <IconLock data-icon="inline-start" />
                Restricted
              </Button>
            )}

            {onConfigure && !stateConfig.disabled && (
              <Button
                size="icon-xs"
                variant="outline"
                onClick={() => onConfigure(plugin)}
                aria-label={text.configure}
              >
                <IconSettings />
              </Button>
            )}
          </div>
        </CardFooter>
      </Card>

      {/* Plugin Details Popup Modal */}
      <PluginDetailsModal
        open={detailsOpen}
        onOpenChange={setDetailsOpen}
        plugin={plugin}
        installed
      />

      {/* Rollback Confirmation Modal */}
      {rollbackTarget && (
        <Dialog open={Boolean(rollbackTarget)} onOpenChange={(open) => !open && setRollbackTarget(null)}>
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2 text-base">
                <IconArrowBackUp className="size-5 text-amber-600 dark:text-amber-400" />
                Confirm Version Rollback
              </DialogTitle>
              <DialogDescription className="text-xs">
                Are you sure you want to rollback <strong>{plugin.name}</strong> from version <strong>v{plugin.version}</strong> back to <strong>v{rollbackTarget}</strong>?
              </DialogDescription>
            </DialogHeader>

            <div className="rounded-lg bg-amber-500/10 border border-amber-500/30 p-3 text-xs text-amber-950 dark:text-amber-100 space-y-1">
              <div className="font-semibold flex items-center gap-1.5">
                <IconAlertCircle className="size-4 shrink-0" />
                Warning: Version Degradation
              </div>
              <p className="text-[11px] opacity-90 leading-relaxed">
                Rolling back to v{rollbackTarget} will restore legacy schemas and configuration defaults. Ensure any data migration backups are complete.
              </p>
            </div>

            <DialogFooter className="gap-2 sm:gap-0">
              <Button variant="outline" onClick={() => setRollbackTarget(null)}>
                Cancel
              </Button>
              <Button
                variant="warning-solid"
                onClick={() => {
                  if (rollbackTarget) {
                    onRollback?.(plugin, rollbackTarget)
                    setRollbackTarget(null)
                  }
                }}
              >
                <IconArrowBackUp data-icon="inline-start" />
                Rollback to v{rollbackTarget}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      )}
    </>
  )
}

interface PluginDetailsModalProps {
  open: boolean
  onOpenChange: (open: boolean) => void
  plugin: PluginItem
  installed?: boolean
  onInstall?: (plugin: PluginItem) => void
}

function PluginDetailsModal({
  open,
  onOpenChange,
  plugin,
  installed = false,
  onInstall,
}: PluginDetailsModalProps) {
  const versions = plugin.versions ?? [
    { version: plugin.version, releasedAt: "2 weeks ago", changelog: "Added multi-tenant isolation support and performance indexing." },
    { version: "2.3.1", releasedAt: "1 month ago", changelog: "Fixed memory leak in background worker queue." },
    { version: "2.2.0", releasedAt: "2 months ago", changelog: "Initial release with basic telemetry dispatchers." },
  ]

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="sm:max-w-xl">
        <DialogHeader className="border-b border-border/40 pb-4">
          <div className="flex items-center gap-3.5">
            <div className="text-foreground shrink-0 [&_svg]:size-8 text-primary">
              {plugin.icon ?? <IconPuzzle />}
            </div>
            <div>
              <DialogTitle className="text-lg font-bold flex items-center gap-2">
                {plugin.name}
                {plugin.verified && <Badge variant="default" className="text-[10px]">Verified</Badge>}
              </DialogTitle>
              <DialogDescription className="text-xs">
                by {plugin.author} · Version v{plugin.version}
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>

        <Tabs defaultValue="overview" className="w-full text-xs">
          <TabsList className="w-full justify-start border-b rounded-none bg-transparent p-0 h-9">
            <TabsTrigger value="overview" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent">
              Overview
            </TabsTrigger>
            <TabsTrigger value="versions" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent">
              Changelog & Versions
            </TabsTrigger>
            <TabsTrigger value="permissions" className="rounded-none border-b-2 border-transparent data-[state=active]:border-primary data-[state=active]:bg-transparent">
              Permissions & Security
            </TabsTrigger>
          </TabsList>

          <TabsContent value="overview" className="py-4 space-y-4">
            <p className="text-xs text-muted-foreground leading-relaxed">
              {plugin.longDescription ?? plugin.description}
            </p>

            {/* Interactive Image Gallery in More Info > Overview */}
            <PluginImageGallery
              screenshots={plugin.screenshots}
              pluginName={plugin.name}
              category={plugin.category}
              bannerGradient={plugin.bannerGradient}
            />

            <div className="flex flex-wrap items-center gap-3 pt-1">
              <div className="flex items-center gap-1.5 rounded-lg border border-border/50 bg-muted/40 px-3 py-1.5 text-xs">
                <IconStarFilled className="size-3.5 text-muted-foreground/70" />
                <span className="font-semibold">{plugin.rating.toFixed(1)}</span>
                <span className="text-muted-foreground">({plugin.reviewCount} reviews)</span>
              </div>
              <div className="flex items-center gap-1.5 rounded-lg border border-border/50 bg-muted/40 px-3 py-1.5 text-xs">
                <IconShieldCheck className="size-3.5 text-emerald-500" />
                <span className="font-semibold">Security Verified</span>
              </div>
            </div>
          </TabsContent>

          <TabsContent value="versions" className="py-4 space-y-3">
            <div className="space-y-2">
              {versions.map((ver) => (
                <div key={ver.version} className="rounded-lg border border-border/60 bg-muted/20 p-3 space-y-1">
                  <div className="flex items-center justify-between">
                    <span className="font-semibold text-xs text-foreground">Version {ver.version}</span>
                    <span className="text-[10px] text-muted-foreground">{ver.releasedAt}</span>
                  </div>
                  {ver.changelog && (
                    <p className="text-xs text-muted-foreground leading-relaxed">{ver.changelog}</p>
                  )}
                </div>
              ))}
            </div>
          </TabsContent>

          <TabsContent value="permissions" className="py-4 space-y-3">
            <p className="text-xs text-muted-foreground">
              This plugin requests the following system permissions when activated:
            </p>
            <div className="space-y-1.5">
              {(plugin.permissions ?? ["storage:read-write", "network:outbound-http", "events:subscribe"]).map((perm) => (
                <div key={perm} className="flex items-center gap-2 rounded-md bg-muted/30 border border-border/40 px-2.5 py-1 text-xs font-mono">
                  <IconLock className="size-3.5 text-primary shrink-0" />
                  <span>{perm}</span>
                </div>
              ))}
            </div>
          </TabsContent>
        </Tabs>

        <DialogFooter className="border-t border-border/40 pt-3 flex items-center justify-between">
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            Close
          </Button>
          {!installed && (
            <Button
              variant="default"
              onClick={() => {
                onInstall?.(plugin)
                onOpenChange(false)
              }}
            >
              <IconDownload data-icon="inline-start" />
              Install Plugin
            </Button>
          )}
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

interface PluginImageGalleryProps {
  screenshots?: string[]
  pluginName: string
  category: string
  bannerGradient?: string
  /** Accessible names for the gallery arrows. */
  previousLabel?: string
  nextLabel?: string
}

/**
 * One gallery slide: either a real screenshot, or — when the plugin ships none —
 * a gradient placeholder standing in for a screenshot that does not exist yet.
 * The two shapes carry different fields, so the variant is explicit rather than
 * inferred from a truthy `url`.
 */
type PluginGalleryItem =
  | { kind: "screenshot"; title: string; url: string }
  | { kind: "placeholder"; title: string; label: string; gradient: string }

function PluginImageGallery({
  screenshots,
  pluginName,
  category,
  bannerGradient = "from-primary/20 via-muted to-muted/80",
  previousLabel = "Previous screenshot",
  nextLabel = "Next screenshot",
}: PluginImageGalleryProps) {
  const [activeIndex, setActiveIndex] = React.useState(0)

  const items: PluginGalleryItem[] = screenshots && screenshots.length > 0
    ? screenshots.map((url, i) => ({
        kind: "screenshot",
        title: `${pluginName} Screenshot ${i + 1}`,
        url,
      }))
    : [
        {
          kind: "placeholder",
          title: "Main Interface View",
          label: "Dashboard View",
          gradient: bannerGradient,
        },
        {
          kind: "placeholder",
          title: "Configuration & Settings",
          label: "Rule Engine Settings",
          gradient: "from-blue-600/30 via-slate-800/30 to-muted/80",
        },
        {
          kind: "placeholder",
          title: "Telemetry & Live Logs",
          label: "Realtime Analytics",
          gradient: "from-emerald-600/30 via-teal-800/30 to-muted/80",
        },
      ]

  const currentItem = items[activeIndex] || items[0]

  return (
    <div className="space-y-2">
      <div className="flex items-center justify-between">
        <span className="text-[11px] font-semibold text-foreground uppercase tracking-wider">
          Interface Gallery & Screenshots
        </span>
        <span className="text-[10px] text-muted-foreground">
          {activeIndex + 1} of {items.length}
        </span>
      </div>

      {/* Main Preview Container */}
      <div className="group/gallery relative h-48 w-full overflow-hidden rounded-xl border border-border/60 bg-muted">
        {currentItem.kind === "screenshot" ? (
          <img
            src={currentItem.url}
            alt={currentItem.title}
            className="size-full object-cover transition-all duration-300"
          />
        ) : (
          <div
            className={cn(
              "size-full flex flex-col items-center justify-center bg-linear-to-br p-6 text-center transition-all duration-300",
              currentItem.gradient
            )}
          >
            <IconPhoto className="size-8 text-foreground/40 mb-2" />
            <span className="font-heading font-semibold text-sm text-foreground/90">
              {currentItem.title}
            </span>
            <span className="text-xs text-muted-foreground mt-0.5">
              {category} Module Preview · {currentItem.label}
            </span>
          </div>
        )}

        {/* Carousel Prev/Next Overlay Buttons */}
        {items.length > 1 && (
          <>
            <button
              type="button"
              onClick={() => setActiveIndex((prev) => (prev > 0 ? prev - 1 : items.length - 1))}
              className="absolute left-2 top-1/2 -translate-y-1/2 rounded-full bg-background/80 p-1.5 text-foreground backdrop-blur-xs opacity-0 transition-opacity group-hover/gallery:opacity-100 hover:bg-background shadow-xs"
              aria-label={previousLabel}
            >
              <IconChevronLeft className="size-4" />
            </button>

            <button
              type="button"
              onClick={() => setActiveIndex((prev) => (prev < items.length - 1 ? prev + 1 : 0))}
              className="absolute right-2 top-1/2 -translate-y-1/2 rounded-full bg-background/80 p-1.5 text-foreground backdrop-blur-xs opacity-0 transition-opacity group-hover/gallery:opacity-100 hover:bg-background shadow-xs"
              aria-label={nextLabel}
            >
              <IconChevronRight className="size-4" />
            </button>
          </>
        )}
      </div>

      {/* Thumbnail Selector Strip */}
      {items.length > 1 && (
        <div className="grid grid-cols-3 gap-2 pt-1">
          {items.map((item, idx) => (
            <button
              key={idx}
              type="button"
              onClick={() => setActiveIndex(idx)}
              className={cn(
                "relative h-14 rounded-lg overflow-hidden border transition-all text-left p-2 flex flex-col justify-end",
                activeIndex === idx
                  ? "border-primary ring-2 ring-primary/40 shadow-xs"
                  : "border-border/50 opacity-70 hover:opacity-100 hover:border-border"
              )}
            >
              {item.kind === "screenshot" ? (
                <img src={item.url} alt={item.title} className="absolute inset-0 size-full object-cover" />
              ) : (
                <div className={cn("absolute inset-0 bg-linear-to-br", item.gradient)} />
              )}
              <span className="relative z-10 text-[9px] font-semibold text-foreground/90 truncate drop-shadow-xs">
                {item.kind === "placeholder" ? item.label : item.title}
              </span>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
