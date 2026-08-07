import type { NavTranslate } from "@amroksaleh/features/nav"

/**
 * English strings for the shared feature UI (`DemoCatalogList`/`Detail`,
 * `UnsyncedBanner`, `ConflictResolver`), which resolve i18n keys through an
 * injected {@link NavTranslate}. This template ships no i18n runtime, so we map
 * the known keys to literals and fall back to the key itself for anything
 * unmapped. A localized app would inject its real translator here instead
 * (RTL/Arabic content is already handled by the shared components via
 * `dir="auto"`).
 */
const STRINGS: Record<string, string> = {
  // DemoCatalog list
  "demoCatalog.list.create": "New item",
  "demoCatalog.list.emptyTitle": "No items yet",
  "demoCatalog.list.emptyDescription": "Create your first item to get started.",
  "demoCatalog.list.errorTitle": "Couldn't load the catalog",
  "demoCatalog.list.error": "Something went wrong loading your items.",
  "demoCatalog.list.retry": "Retry",
  // DemoCatalog detail
  "demoCatalog.detail.createTitle": "New item",
  "demoCatalog.detail.editTitle": "Edit item",
  "demoCatalog.detail.nameLabel": "Name",
  "demoCatalog.detail.descriptionLabel": "Description",
  "demoCatalog.detail.statusLabel": "Status",
  "demoCatalog.detail.cancel": "Cancel",
  "demoCatalog.detail.save": "Save",
  "demoCatalog.detail.saving": "Saving…",
  "demoCatalog.detail.back": "Back",
  "demoCatalog.detail.errorTitle": "Something went wrong",
  "demoCatalog.detail.notFound": "Item not found.",
  "demoCatalog.detail.loadError": "Couldn't load this item.",
  "demoCatalog.detail.saveError": "Couldn't save. Check your input and try again.",
  "demoCatalog.status.active": "Active",
  "demoCatalog.status.archived": "Archived",
  // UnsyncedBanner
  "sync.banner.synced": "All changes synced",
  "sync.banner.locked": "Session locked — sign in online to continue",
  "sync.banner.conflicts": "conflict(s) need review",
  "sync.banner.reviewConflicts": "Review conflicts",
  "sync.banner.offline": "You're offline — changes are saved locally and will sync when you reconnect",
  "sync.banner.syncing": "Syncing…",
  "sync.banner.unsynced": "change(s) not yet synced",
  "sync.banner.syncNow": "Sync now",
  // ConflictResolver
  "sync.conflict.title": "Resolve conflict",
  "sync.conflict.mine": "Yours",
  "sync.conflict.theirs": "Server",
  "sync.conflict.custom": "Custom",
  "sync.conflict.merged": "Result",
  "sync.conflict.cancel": "Cancel",
  "sync.conflict.resolve": "Save resolution",
}

/** The app-wide translator injected into every shared feature component. */
export const appT: NavTranslate = (key) => STRINGS[key] ?? key
