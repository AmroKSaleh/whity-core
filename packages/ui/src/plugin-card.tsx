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

/**
 * Copy for the plugin cards and their details/rollback dialogs.
 *
 * Every entry defaults to the English it replaced, so a caller that passes
 * nothing renders exactly as before. `rollbackConfirmation` returns a NODE
 * because its default is one sentence with three emphasised values inside it —
 * the plugin name and both versions. Splitting that into fixed fragments would
 * freeze English word order around them, so the caller supplies the finished
 * sentence (built with useRichTranslation if it wants).
 */
export interface PluginCardLabels {
  verified?: string
  moreInfo?: string
  install?: string
  details?: string
  activate?: string
  deactivate?: string
  restricted?: string
  active?: string
  inactive?: string
  disabledBySystem?: string
  updateAvailable?: (latestVersion?: string) => string
  governanceNotice?: string
  rollback?: string
  versionHistory?: string
  configure?: string
  confirmRollbackTitle?: string
  rollbackConfirmation?: (pluginName: string, from: string, to: string) => React.ReactNode
  cancel?: string
  overview?: string
  securityVerified?: string
  close?: string
  installPlugin?: string
  previousScreenshot?: string
  nextScreenshot?: string
  noVersionHistory?: string
  noPermissions?: string
  permissionsUndeclared?: string
}

const DEFAULT_PLUGIN_CARD_LABELS = {
  verified: "Verified",
  moreInfo: "More Info",
  install: "Install",
  details: "Details",
  activate: "Activate",
  deactivate: "Deactivate",
  restricted: "Restricted",
  active: "Active",
  inactive: "Inactive",
  disabledBySystem: "Disabled by System",
  updateAvailable: (latestVersion?: string) => `Update Available v${latestVersion}`,
  governanceNotice:
    "This plugin has been automatically disabled by system governance due to security or compatibility policy.",
  rollback: "Rollback",
  versionHistory: "Version History",
  configure: "Configure plugin",
  confirmRollbackTitle: "Confirm Version Rollback",
  rollbackConfirmation: (pluginName: string, from: string, to: string): React.ReactNode => (
    <>
      Are you sure you want to rollback <strong>{pluginName}</strong> from version{" "}
      <strong>v{from}</strong> back to <strong>v{to}</strong>?
    </>
  ),
  cancel: "Cancel",
  overview: "Overview",
  securityVerified: "Security Verified",
  close: "Close",
  installPlugin: "Install Plugin",
  previousScreenshot: "Previous screenshot",
  nextScreenshot: "Next screenshot",
  noVersionHistory: "No version history available.",
  noPermissions: "This plugin does not request any special permissions.",
  permissionsUndeclared: "This plugin has not declared which system permissions it requests.",
} satisfies Required<PluginCardLabels>

export interface PluginStoreCardProps extends React.ComponentProps<"div"> {
  plugin: PluginItem
  onInstall?: (plugin: PluginItem) => void
  onConfigure?: (plugin: PluginItem) => void
  labels?: PluginCardLabels
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
  labels,
  ...props
}: PluginStoreCardProps) {
  const text = { ...DEFAULT_PLUGIN_CARD_LABELS, ...labels }
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
                {text.verified}
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
            {text.moreInfo}
          </Button>

          <Button
            size="sm"
            variant="default"
            onClick={() => onInstall?.(plugin)}
          >
            <IconDownload data-icon="inline-start" />
            {text.install}
          </Button>
        </CardFooter>
      </Card>

      {/* Plugin Details Popup Modal */}
      <PluginDetailsModal
        open={detailsOpen}
        onOpenChange={setDetailsOpen}
        plugin={plugin}
        onInstall={onInstall}
        labels={labels}
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
  labels?: PluginCardLabels
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
  const text = { ...DEFAULT_PLUGIN_CARD_LABELS, ...labels }
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

  // No fallback (#756). Offering a rollback target the plugin never published
  // is an action the host cannot honour, so an absent history has to REMOVE the
  // affordance rather than populate it with plausible-looking version numbers.
  const pastVersions = (plugin.versions ?? []).filter(
    (v) => !v.isCurrent && v.version !== plugin.version
  )

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
            {text.details}
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
                {text.deactivate}
              </Button>
            )}

            {state === "inactive" && (
              <Button
                size="sm"
                variant="success-solid"
                onClick={() => onToggleState?.(plugin, "active")}
              >
                <IconCheck data-icon="inline-start" />
                {text.activate}
              </Button>
            )}

            {state === "disabled" && (
              <Button size="sm" variant="outline" disabled>
                <IconLock data-icon="inline-start" />
                {text.restricted}
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
        labels={labels}
      />

      {/* Rollback Confirmation Modal */}
      {rollbackTarget && (
        <Dialog open={Boolean(rollbackTarget)} onOpenChange={(open) => !open && setRollbackTarget(null)}>
          <DialogContent className="sm:max-w-md">
            <DialogHeader>
              <DialogTitle className="flex items-center gap-2 text-base">
                <IconArrowBackUp className="size-5 text-amber-600 dark:text-amber-400" />
                {text.confirmRollbackTitle}
              </DialogTitle>
              <DialogDescription className="text-xs">
                {text.rollbackConfirmation(plugin.name, plugin.version, rollbackTarget)}
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
                {text.cancel}
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
  labels?: PluginCardLabels
}

function PluginDetailsModal({
  open,
  onOpenChange,
  plugin,
  installed = false,
  onInstall,
  labels,
}: PluginDetailsModalProps) {
  const text = { ...DEFAULT_PLUGIN_CARD_LABELS, ...labels }
  // Both of these are OPTIONAL on PluginItem, so their old sample-array
  // fallbacks were the DEFAULT rendering, not a defensive branch — this modal
  // stated release numbers, dates, a changelog and a permission set that
  // belonged to no plugin, in the screen where someone decides whether to
  // install third-party code. Empty is the only honest answer; the tabs below
  // say so explicitly rather than rendering an unexplained blank. (#756)
  const versions = plugin.versions ?? []
  // Deliberately NOT defaulted to [] alongside `versions`: for permissions the
  // two are different statements. An empty array is the plugin saying it asks
  // for nothing; `undefined` is nobody having told us what it asks for, and
  // answering that with "does not request any special permissions" is a
  // reassurance about third-party code that nobody actually made — the same
  // class of invention as the sample array it replaced, only in the safe
  // direction. The tab below says which of the two it has.
  const permissions = plugin.permissions

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
                {plugin.verified && <Badge variant="default" className="text-[10px]">{text.verified}</Badge>}
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
              {text.overview}
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
              previousLabel={text.previousScreenshot}
              nextLabel={text.nextScreenshot}
            />

            <div className="flex flex-wrap items-center gap-3 pt-1">
              <div className="flex items-center gap-1.5 rounded-lg border border-border/50 bg-muted/40 px-3 py-1.5 text-xs">
                <IconStarFilled className="size-3.5 text-muted-foreground/70" />
                <span className="font-semibold">{plugin.rating.toFixed(1)}</span>
                <span className="text-muted-foreground">({plugin.reviewCount} reviews)</span>
              </div>
              <div className="flex items-center gap-1.5 rounded-lg border border-border/50 bg-muted/40 px-3 py-1.5 text-xs">
                <IconShieldCheck className="size-3.5 text-emerald-500" />
                <span className="font-semibold">{text.securityVerified}</span>
              </div>
            </div>
          </TabsContent>

          <TabsContent value="versions" className="py-4 space-y-3">
            <div className="space-y-2">
              {versions.length === 0 && (
                <p className="text-xs text-muted-foreground">{text.noVersionHistory}</p>
              )}
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
              {permissions === undefined
                ? text.permissionsUndeclared
                : permissions.length === 0
                  ? text.noPermissions
                  : "This plugin requests the following system permissions when activated:"}
            </p>
            <div className="space-y-1.5">
              {(permissions ?? []).map((perm) => (
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
            {text.close}
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
              {text.installPlugin}
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
  previousLabel,
  nextLabel,
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
