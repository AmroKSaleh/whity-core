"use client"

import * as React from "react"
import {
  IconBell,
  IconCommand,
  IconLanguage,
  IconLogout,
  IconMenu2,
  IconMoon,
  IconSearch,
  IconSettings,
  IconSun,
  IconUser,
} from "@tabler/icons-react"

import { cn } from "./utils"
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "./dropdown-menu"
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from "./tooltip"

export interface TopNavUser {
  name: string
  email?: string
  avatarUrl?: string
  initials?: string
}

export interface TopNavProps {
  /**
   * Called when the mobile menu toggle button is clicked.
   */
  onMobileMenuToggle?: () => void
  /**
   * Slot for left-aligned content (e.g. breadcrumb, logo, or page title snippet).
   */
  leftContent?: React.ReactNode
  /**
   * Custom search bar or command palette trigger button. Defaults to a standard Ctrl+K trigger.
   */
  searchContent?: React.ReactNode
  /**
   * Placeholder for default command palette search trigger. Defaults to "Search or press ⌘K...".
   */
  searchPlaceholder?: string
  /** Accessible name for the mobile-menu button. */
  mobileMenuLabel?: string
  /** Accessible name for the notifications button. */
  notificationsLabel?: string
  /**
   * Tooltip on the theme toggle, given the theme it would switch TO.
   * A function because the mode name sits inside the phrase.
   */
  themeToggleLabel?: (next: "light" | "dark") => string
  /** Tooltip on the language toggle, given the language it would switch TO. */
  languageToggleLabel?: (next: string) => string
  /**
   * Notification-count summary. A function for the same reason `Pagination`'s
   * `entriesLabel` is one: the number is inside the sentence, and plural rules
   * differ between languages.
   */
  notificationSummary?: (count: number) => string
  /** Labels of the DEFAULT user-menu items (ignored when `userMenuItems` is given). */
  profileLabel?: string
  settingsLabel?: string
  signOutLabel?: string
  /**
   * Called when the default search trigger is clicked.
   */
  onSearchClick?: () => void
  /**
   * User profile information displayed in the right dropdown menu.
   */
  user?: TopNavUser
  /**
   * Called when "Profile" item is selected in user dropdown.
   */
  onProfileClick?: () => void
  /**
   * Called when "Settings" item is selected in user dropdown.
   */
  onSettingsClick?: () => void
  /**
   * Called when "Sign out" item is selected in user dropdown.
   */
  onLogoutClick?: () => void
  /**
   * Custom items slot to override or append to the user dropdown menu.
   */
  userMenuItems?: React.ReactNode
  /**
   * Unread notifications badge count. Renders a dot/badge over the notification bell icon.
   */
  notificationCount?: number
  /**
   * Called when the notification bell button is clicked.
   */
  onNotificationClick?: () => void
  /**
   * Active language code e.g. "en" or "ar".
   */
  language?: "en" | "ar"
  /**
   * Called when language toggle is clicked.
   */
  onLanguageToggle?: () => void
  /**
   * Active theme mode e.g. "light" or "dark".
   */
  theme?: "light" | "dark"
  /**
   * Called when theme mode toggle is clicked.
   */
  onThemeToggle?: () => void
  /**
   * Additional custom action controls rendered before user profile.
   */
  rightContent?: React.ReactNode
  className?: string
}

export function TopNav({
  onMobileMenuToggle,
  leftContent,
  searchContent,
  searchPlaceholder = "Search or press ⌘K...",
  mobileMenuLabel = "Open mobile navigation",
  notificationsLabel = "Notifications",
  themeToggleLabel = (next: "light" | "dark") =>
    next === "light" ? "Switch to Light mode" : "Switch to Dark mode",
  languageToggleLabel = (next: string) => `Switch to ${next}`,
  notificationSummary = (count: number) =>
    count > 0 ? `${count} new notifications` : "No new notifications",
  profileLabel = "Profile",
  settingsLabel = "Settings",
  signOutLabel = "Sign out",
  onSearchClick,
  user,
  onProfileClick,
  onSettingsClick,
  onLogoutClick,
  userMenuItems,
  notificationCount = 0,
  onNotificationClick,
  language,
  onLanguageToggle,
  theme,
  onThemeToggle,
  rightContent,
  className,
}: TopNavProps) {
  const userInitials = user?.initials ?? (user?.name ? user.name.slice(0, 2).toUpperCase() : "U")

  return (
    <header
      data-slot="top-nav"
      className={cn(
        "sticky top-0 z-30 flex h-14 w-full items-center justify-between gap-4 border-b border-border bg-card px-4 shadow-2xs transition-colors",
        className
      )}
    >
      {/* Left Section: Mobile Menu Toggle & Left Content / Breadcrumbs */}
      <div className="flex items-center gap-3 min-w-0">
        {onMobileMenuToggle && (
          <button
            type="button"
            aria-label={mobileMenuLabel}
            onClick={onMobileMenuToggle}
            className="flex size-8 shrink-0 items-center justify-center rounded-md border border-input text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring/30 md:hidden"
          >
            <IconMenu2 className="size-4" />
          </button>
        )}
        {leftContent && <div className="min-w-0 truncate">{leftContent}</div>}
      </div>

      {/* Center Section: Command Palette / Search Trigger */}
      <div className="flex-1 max-w-md hidden sm:block">
        {searchContent ?? (
          <button
            type="button"
            onClick={onSearchClick}
            className="flex h-8 w-full items-center justify-between gap-2 rounded-md border border-input bg-background/60 px-3 text-xs text-muted-foreground transition-colors hover:bg-muted/50 hover:text-foreground outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/30"
          >
            <div className="flex items-center gap-2 min-w-0 truncate">
              <IconSearch className="size-3.5 shrink-0 text-muted-foreground" aria-hidden="true" />
              <span className="truncate">{searchPlaceholder}</span>
            </div>
            <kbd className="pointer-events-none inline-flex h-5 select-none items-center gap-0.5 rounded border border-border/80 bg-muted px-1.5 font-mono text-[0.625rem] font-semibold text-muted-foreground">
              <IconCommand className="size-2.5" />K
            </kbd>
          </button>
        )}
      </div>

      {/* Right Section: Language, Theme, Notifications & User Dropdown */}
      <div className="flex items-center gap-1.5 shrink-0">
        {/* Language Switcher */}
        {language && onLanguageToggle && (
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  onClick={onLanguageToggle}
                  aria-label={`Switch language, current: ${language.toUpperCase()}`}
                  className="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                  <IconLanguage className="size-4" />
                </button>
              </TooltipTrigger>
              <TooltipContent>{languageToggleLabel(language === "en" ? "Arabic (العربية)" : "English")}</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        )}

        {/* Theme Mode Toggle */}
        {theme && onThemeToggle && (
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  onClick={onThemeToggle}
                  aria-label={`Switch theme, current: ${theme}`}
                  className="flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                  {theme === "dark" ? <IconSun className="size-4" /> : <IconMoon className="size-4" />}
                </button>
              </TooltipTrigger>
              <TooltipContent>{themeToggleLabel(theme === "dark" ? "light" : "dark")}</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        )}

        {/* Notification Bell */}
        {onNotificationClick && (
          <TooltipProvider>
            <Tooltip>
              <TooltipTrigger asChild>
                <button
                  type="button"
                  onClick={onNotificationClick}
                  aria-label={notificationsLabel}
                  className="relative flex size-8 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted hover:text-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                >
                  <IconBell className="size-4" />
                  {notificationCount > 0 && (
                    <span className="absolute top-1.5 end-1.5 flex size-2 rounded-full bg-destructive ring-2 ring-card" />
                  )}
                </button>
              </TooltipTrigger>
              <TooltipContent>
                {notificationSummary(notificationCount)}
              </TooltipContent>
            </Tooltip>
          </TooltipProvider>
        )}

        {rightContent}

        {/* User Profile Dropdown */}
        {user && (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                aria-label={`User menu for ${user.name}`}
                className="flex items-center gap-2 rounded-full p-0.5 outline-none focus-visible:ring-2 focus-visible:ring-ring/30 transition-transform hover:scale-105"
              >
                {user.avatarUrl ? (
                  <img
                    src={user.avatarUrl}
                    alt={user.name}
                    className="size-8 rounded-full border border-border object-cover shadow-2xs"
                  />
                ) : (
                  <div className="flex size-8 items-center justify-center rounded-full bg-primary/10 text-primary font-bold text-xs ring-1 ring-border shadow-2xs">
                    {userInitials}
                  </div>
                )}
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56 p-1.5 shadow-lg border border-border bg-popover text-popover-foreground rounded-xl">
              <DropdownMenuLabel className="font-normal p-2">
                <div className="flex flex-col gap-0.5">
                  <p className="text-xs font-semibold text-foreground truncate">{user.name}</p>
                  {user.email && <p className="text-[0.6875rem] text-muted-foreground truncate">{user.email}</p>}
                </div>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              {userMenuItems ?? (
                <>
                  <DropdownMenuGroup>
                    <DropdownMenuItem onClick={onProfileClick}>
                      <IconUser className="me-2 size-3.5 text-muted-foreground" />
                      <span>{profileLabel}</span>
                    </DropdownMenuItem>
                    <DropdownMenuItem onClick={onSettingsClick}>
                      <IconSettings className="me-2 size-3.5 text-muted-foreground" />
                      <span>{settingsLabel}</span>
                    </DropdownMenuItem>
                  </DropdownMenuGroup>
                  <DropdownMenuSeparator />
                  <DropdownMenuItem onClick={onLogoutClick} className="text-destructive focus:text-destructive">
                    <IconLogout className="me-2 size-3.5" />
                    <span>{signOutLabel}</span>
                  </DropdownMenuItem>
                </>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        )}
      </div>
    </header>
  )
}
