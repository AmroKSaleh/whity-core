import * as React from "react"

import { Alert, AlertAction, AlertDescription } from "@amroksaleh/ui/alert"
import { Button } from "@amroksaleh/ui/button"
import { Spinner } from "@amroksaleh/ui/spinner"

import { identityTranslate, type NavTranslate } from "../nav/types"
import type { SyncStatus } from "./types"

export interface UnsyncedBannerProps {
  status: SyncStatus
  /** Trigger a sync (shown when there are unsynced changes and we're online). */
  onSyncNow?: () => void
  /** Open the conflict resolver (shown when there are conflicts). */
  onReviewConflicts?: () => void
  /** Translator; defaults to the identity function (see nav/types). */
  t?: NavTranslate
  /** Render a "synced" success line even when there's nothing to report. */
  alwaysShow?: boolean
  className?: string
}

/**
 * App-wide sync status strip (WC-desktop-sync). Presentational — it renders the
 * injected {@link SyncStatus}, composing the shared `Alert`. By default it
 * SELF-HIDES when everything is synced (online, nothing pending, no conflicts,
 * not locked), so an online-only client can mount it unconditionally and see
 * nothing. i18n keys resolve via the injected `t` (literal fallbacks otherwise).
 */
export function UnsyncedBanner({
  status,
  onSyncNow,
  onReviewConflicts,
  t = identityTranslate,
  alwaysShow = false,
  className,
}: UnsyncedBannerProps) {
  const { online, syncing, unsyncedCount, conflicts, locked } = status
  const hasConflicts = conflicts.length > 0
  const fullySynced = online && !syncing && unsyncedCount === 0 && !hasConflicts && !locked

  if (fullySynced && !alwaysShow) {
    return null
  }

  let variant: "info" | "success" | "warning" | "destructive" = "success"
  let message = t("sync.banner.synced")
  let action: React.ReactNode = null

  if (locked) {
    variant = "destructive"
    message = t("sync.banner.locked")
  } else if (hasConflicts) {
    variant = "destructive"
    message = `${conflicts.length} ${t("sync.banner.conflicts")}`
    if (onReviewConflicts) {
      action = (
        <Button variant="outline" onClick={onReviewConflicts}>
          {t("sync.banner.reviewConflicts")}
        </Button>
      )
    }
  } else if (!online) {
    variant = "warning"
    message = t("sync.banner.offline")
  } else if (syncing) {
    variant = "info"
    message = t("sync.banner.syncing")
  } else if (unsyncedCount > 0) {
    variant = "info"
    message = `${unsyncedCount} ${t("sync.banner.unsynced")}`
    if (onSyncNow) {
      action = (
        <Button variant="outline" onClick={onSyncNow}>
          {t("sync.banner.syncNow")}
        </Button>
      )
    }
  }

  return (
    <Alert variant={variant} className={className} data-slot="unsynced-banner">
      {syncing ? <Spinner /> : null}
      <AlertDescription>{message}</AlertDescription>
      {action ? <AlertAction>{action}</AlertAction> : null}
    </Alert>
  )
}
