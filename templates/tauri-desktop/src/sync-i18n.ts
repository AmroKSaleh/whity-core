import type { NavTranslate } from "@amroksaleh/features/nav"

/**
 * English strings for the shared feature UI that still resolves i18n keys
 * through an injected {@link NavTranslate} — `UnsyncedBanner`,
 * `ConflictResolver`, and this template's own enrollment screen.
 *
 * This template ships no i18n runtime, so we map the known keys to literals and
 * fall back to the key itself for anything unmapped. A localized app would
 * inject its real translator here instead (RTL/Arabic content is already
 * handled by the shared components via `dir="auto"`).
 *
 * `DemoCatalogList`/`Detail` are NO LONGER in that list, and the reason is
 * worth keeping. Injecting a key-only translator is what made their vocabulary
 * invisible: `NavTranslate` is `(key: string) => string` with nowhere to put an
 * English fallback, and a `t` arriving as a prop binds no domain, so the
 * extractor never saw the keys and `demoCatalog.*` existed in no catalogue —
 * only in the map below, only in English, only on this device (#984).
 *
 * FALLING BACK TO THE KEY IS THE HAZARD, not the safety net. Anything unmapped
 * here renders its own key at a user. That is survivable for a template with
 * one screen and a short list; it is why the shared components stopped
 * accepting an injected translator by default.
 */
const STRINGS: Record<string, string> = {
  // Enrollment (app-state-provider.tsx). Every string that screen renders
  // resolves through appT, so the whole screen is localizable in one place - a
  // half-translated screen is how the Arabic/RTL requirement rots.
  "enroll.title": "Welcome to Whity Desktop",
  "enroll.description":
    "Sign in once to register this device with the Whity backend. A long-lived credential is stored in your OS keychain; work then continues offline until the login window elapses.",
  "enroll.emailLabel": "Email",
  "enroll.passwordLabel": "Password",
  "enroll.deviceNameLabel": "Device name",
  "enroll.submit": "Enroll device",
  "enroll.submitting": "Enrolling…",
  "enroll.error.requires2fa":
    "This account has two-factor authentication enabled, which this app cannot complete yet. Enroll with an account that does not require a code.",
  "enroll.error.noSelectionToken":
    "The server asked which tenant to use but did not say how to answer. Try signing in again.",
  // Tenant picker (#914) - shown whenever the server reports more than one
  // active membership, and never resolved automatically however few the choices.
  "enroll.tenant.title": "Choose a tenant",
  "enroll.tenant.description":
    "This account is active in more than one tenant. Pick the one this device should enroll into.",
  "enroll.tenant.legend": "Tenant",
  "enroll.tenant.unnamed": "Tenant",
  "enroll.tenant.submit": "Enroll device",
  "enroll.tenant.back": "Use a different account",
  "enroll.tenant.empty":
    "The server asked for a tenant but listed none to choose from. Try signing in again.",
  "enroll.tenant.lapsed":
    "That sign-in step expired before a tenant was chosen, or the membership changed. Please sign in again.",
  // DemoCatalog strings USED to live here, injected through the `t` prop.
  // They are gone because the components translate themselves now (#984):
  // the prop bound no domain, so the extractor could not see the keys and
  // `demoCatalog.*` reached no catalogue at all — this map was the only place
  // those strings existed, and only in English.
  //
  // This template ships no i18n runtime, so `useTranslation` finds no provider
  // and returns each call site's English fallback — the same words this map
  // held. Nothing on screen changed; the strings are now in
  // `database/i18n/plugin.json` where a translator can reach them, and their
  // Arabic is committed alongside.
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

/**
 * Translator for `@amroksaleh/features/roles`'s `RolesScreen`, whose `t` prop
 * is richer than `NavTranslate`: it passes an English `fallback` and `{token}`
 * `vars` alongside each key. The screen's own default (`identityTranslate`)
 * renders the raw KEY, so a real translator is required for a usable UI. This
 * one resolves `STRINGS[key]` first (nothing roles-specific is mapped yet —
 * add keys here to localize), then the fallback, then the key, and interpolates
 * any `{token}` placeholders. Typed structurally so it needs no import from the
 * roles package.
 */
export const rolesT = (key: string, fallback?: string, vars?: Record<string, string | number>): string => {
  const template = STRINGS[key] ?? fallback ?? key
  if (!vars) return template
  return template.replace(/\{(\w+)\}/g, (_match, name: string) => (name in vars ? String(vars[name]) : `{${name}}`))
}
