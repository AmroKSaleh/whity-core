import { http, HttpResponse, delay } from "msw"
import type { Membership } from "@/lib/auth-context"
import type { NavigationItem } from "@/lib/navigation-context"

/**
 * Shared MSW fixtures + handlers for the app-component gallery. The real
 * context providers (Auth/Navigation) bootstrap by fetching `/api/v1/me` and
 * `/api/v1/navigation`; these handlers keep them offline and deterministic.
 * Stories that need a different server shape override `parameters.msw.handlers`.
 */

export const MOCK_BRANDING = {
  siteName: "Acme Corp",
  logoWideUrl: null,
  logoSquareUrl: null,
  faviconUrl: null,
}

export const MOCK_USER = {
  id: 1,
  email: "admin@acme.test",
  role: "admin",
  tenant_id: 10,
}

export const MOCK_MEMBERSHIPS: Membership[] = [
  { tenant_id: 10, tenant_name: "Acme Corp", role: "admin" },
  { tenant_id: 20, tenant_name: "Globex", role: "editor" },
]

export const MOCK_NAV: NavigationItem[] = [
  { id: "dashboard", label: "Dashboard", href: "/admin", icon: "dashboard", group: "Overview", order: 1 },
  { id: "users", label: "Users", href: "/admin/users", icon: "users", group: "Access", order: 1 },
  { id: "roles", label: "Roles", href: "/admin/roles", icon: "shield-lock", group: "Access", order: 2 },
  { id: "plugins", label: "Plugins", href: "/admin/plugins", icon: "puzzle", group: "System", order: 1 },
  { id: "settings", label: "Settings", href: "/admin/settings", icon: "settings", group: "System", order: 2 },
]

/** Full permission set — write controls visible. */
export const ALL_PERMISSIONS = ["users:read", "users:write", "users:delete", "plugins:write"]

/** GET /api/v1/me/capabilities with a specific permission set. */
export function capabilitiesHandler(permissions: string[] = ALL_PERMISSIONS) {
  return http.get("*/api/v1/me/capabilities", () =>
    HttpResponse.json({ data: { permissions } })
  )
}

/** The baseline handlers every story gets unless it overrides them. */
export const defaultHandlers = [
  http.get("*/api/v1/me", () =>
    HttpResponse.json({ user: MOCK_USER, memberships: MOCK_MEMBERSHIPS })
  ),
  http.get("*/api/v1/navigation", () => HttpResponse.json({ data: MOCK_NAV })),
  http.get("*/api/v1/branding", () => HttpResponse.json({ data: MOCK_BRANDING })),
  capabilitiesHandler(),
]

/** Re-export MSW helpers so stories import them from one place. */
export { http, HttpResponse, delay }
