import * as React from "react"
import { IconChevronLeft, IconChevronRight, IconMenu2, IconX } from "@tabler/icons-react"

import { Button } from "@amroksaleh/ui/button"
import type { AppSidebarNavGroup } from "@amroksaleh/ui/app-sidebar"

import { HashLinkAdapter } from "./hash-link"

/**
 * A close port of the website's OWN sidebar (web/components/sidebar.tsx) —
 * not the generic `@amroksaleh/ui/app-sidebar` primitive the app used
 * before. The website's sidebar predates that shared primitive and was never
 * swapped over to it, so matching the website pixel-for-pixel means mirroring
 * ITS bespoke JSX/classes (Button-wrapped, numbered nav rows; p-6 header;
 * mobile overlay + collapse-to-w-20) rather than `AppSidebar`'s own (visually
 * different) rendering of the same nav data.
 *
 * Ported: header, nav (Button rows with a numbered index, exactly like the
 * website), mobile toggle/overlay, collapse-to-icon-only. NOT ported: the
 * tenant switcher and language switcher rows in the website's footer — this
 * is a single-tenant device with no i18n layer, so those rows don't apply;
 * the `footer` slot below is exactly where they'd have gone.
 */
export interface SidebarProps {
  groups: AppSidebarNavGroup[]
  /** Wordmark shown in place of a tenant-branding logo (the website falls
   * back to this same plain-text treatment when no logo is configured). */
  siteName: string
  /** Caption under the wordmark (website: "Admin"). */
  subtitle: string
  /** Rendered in the footer, below the nav — e.g. account/theme/logout rows. */
  footer?: React.ReactNode
}

export function Sidebar({ groups, siteName, subtitle, footer }: SidebarProps) {
  const [isOpen, setIsOpen] = React.useState(false)
  const [isCollapsed, setIsCollapsed] = React.useState(false)
  const [isMobile, setIsMobile] = React.useState(false)

  React.useEffect(() => {
    const handleResize = () => {
      const mobile = window.innerWidth < 768
      setIsMobile(mobile)
      if (!mobile) setIsOpen(true)
    }
    handleResize()
    window.addEventListener("resize", handleResize)
    return () => window.removeEventListener("resize", handleResize)
  }, [])

  const toggleSidebar = () => {
    if (isMobile) {
      setIsOpen(!isOpen)
    } else {
      setIsCollapsed(!isCollapsed)
    }
  }

  const sidebarWidth = isCollapsed ? "w-20" : "w-64"

  return (
    <>
      {/* Mobile toggle button */}
      <button
        onClick={toggleSidebar}
        className="fixed top-4 inset-s-4 z-50 md:hidden p-2 rounded-lg bg-background border border-border hover:bg-muted transition-colors"
        aria-label="Toggle sidebar"
      >
        {isOpen ? <IconX size={24} /> : <IconMenu2 size={24} />}
      </button>

      {/* Mobile overlay */}
      {isMobile && isOpen && (
        <div
          className="fixed inset-0 bg-black/50 backdrop-blur-sm z-30 md:hidden"
          onClick={() => setIsOpen(false)}
        />
      )}

      <aside
        className={`
          transition-all duration-300 ease-in-out
          ${
            isMobile
              ? `fixed top-0 inset-s-0 h-screen ${sidebarWidth} bg-sidebar text-sidebar-foreground border-e border-sidebar-border flex flex-col z-40 ${
                  isOpen ? "translate-x-0" : "ltr:-translate-x-full rtl:translate-x-full"
                }`
              : `relative h-screen ${sidebarWidth} bg-sidebar text-sidebar-foreground border-e border-sidebar-border flex flex-col`
          }
        `}
      >
        {/* Header with collapse button */}
        <div
          className={`border-b border-sidebar-border transition-all duration-300 flex items-center justify-between ${isCollapsed ? "p-3" : "p-6"}`}
        >
          <div className="flex-1">
            {!isCollapsed ? (
              <>
                <h1 className="text-2xl font-bold">{siteName}</h1>
                <p className="text-sm text-muted-foreground mt-1">{subtitle}</p>
              </>
            ) : (
              <div className="text-xl font-bold text-center font-black">{siteName.charAt(0).toUpperCase()}</div>
            )}
          </div>

          {!isMobile && (
            <button
              onClick={() => setIsCollapsed(!isCollapsed)}
              className="p-1 hover:bg-background rounded transition-colors ms-2"
              title={isCollapsed ? "Expand sidebar" : "Collapse sidebar"}
            >
              {isCollapsed ? <IconChevronRight size={20} /> : <IconChevronLeft size={20} />}
            </button>
          )}
        </div>

        {/* Navigation */}
        <nav className="flex-1 p-2 space-y-3 overflow-y-auto">
          {groups.map((group) => {
            if (group.items.length === 0) return null

            return (
              <div key={group.id}>
                {group.label && (
                  <div
                    className={`text-xs font-semibold uppercase text-muted-foreground px-2 mb-2 ${isCollapsed && !isMobile ? "text-center" : ""}`}
                  >
                    {!isCollapsed && !isMobile ? group.label : ""}
                  </div>
                )}
                <div className="space-y-1">
                  {group.items.map((item, index) => (
                    <HashLinkAdapter
                      key={item.id}
                      href={item.href}
                      aria-current={item.active ? "page" : undefined}
                    >
                      <Button
                        variant={item.active ? "default" : "ghost"}
                        size={isCollapsed && !isMobile ? "icon" : "default"}
                        className={`w-full ${isCollapsed && !isMobile ? "justify-center" : "justify-start"}`}
                        title={isCollapsed && !isMobile ? item.label : `${index + 1}. ${item.label}`}
                      >
                        <span className={isCollapsed && !isMobile ? "" : "me-3 shrink-0"}>{item.icon}</span>
                        {(!isCollapsed || isMobile) && (
                          <>
                            <span className="text-xs text-muted-foreground me-2 w-5">{index + 1}</span>
                            <span className="flex-1 text-start">{item.label}</span>
                          </>
                        )}
                      </Button>
                    </HashLinkAdapter>
                  ))}
                </div>
              </div>
            )
          })}
        </nav>

        {/* Footer */}
        {footer && (
          <div className={`border-t border-sidebar-border transition-all duration-300 ${isCollapsed ? "p-2" : "p-4"} space-y-2`}>
            {footer}
          </div>
        )}
      </aside>
    </>
  )
}
