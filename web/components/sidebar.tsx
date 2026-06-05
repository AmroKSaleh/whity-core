'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { useNavigation } from '@/lib/navigation-context';
import { Button } from '@/components/ui/button';
import * as TablerIcons from '@tabler/icons-react';
import {
  IconLogout,
  IconMenu2,
  IconX,
  IconChevronLeft,
  IconChevronRight,
  IconDashboard,
} from '@tabler/icons-react';
import { useState, useEffect } from 'react';
import type { Icon } from '@tabler/icons-react';

/**
 * Resolve a navigation `icon` name to a Tabler icon component.
 *
 * Core nav items emit kebab-case names (e.g. `"building-community"`), but
 * plugins may supply any Tabler icon by its kebab/snake name or its full
 * PascalCase component name (e.g. `"IconUsers"`). We normalize the name to the
 * `Icon<PascalCase>` export and look it up dynamically against the full
 * `@tabler/icons-react` set, falling back to a safe default for unknown names
 * so a plugin can never render a missing-icon hole.
 */
const tablerIcons = TablerIcons as unknown as Record<string, Icon | undefined>;

function resolveIcon(name: string | undefined): Icon {
  if (!name) {
    return IconDashboard;
  }

  // Split on hyphen/underscore/whitespace, capitalize each segment, join.
  const pascal = name
    .trim()
    .split(/[-_\s]+/)
    .filter(Boolean)
    .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
    .join('');

  const componentName = pascal.startsWith('Icon') ? pascal : `Icon${pascal}`;

  return tablerIcons[componentName] ?? IconDashboard;
}

export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const { logout, user } = useAuth();
  const { items, getGroupedItems } = useNavigation();
  const groupedItems = getGroupedItems();
  const [isOpen, setIsOpen] = useState(false);
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isMobile, setIsMobile] = useState(false);

  useEffect(() => {
    const handleResize = () => {
      const mobile = window.innerWidth < 768;
      setIsMobile(mobile);
      if (!mobile) {
        setIsOpen(true);
      }
    };

    handleResize();
    window.addEventListener('resize', handleResize);
    return () => window.removeEventListener('resize', handleResize);
  }, []);

  const handleLogout = () => {
    logout();
    router.push('/login');
  };

  const toggleSidebar = () => {
    if (isMobile) {
      setIsOpen(!isOpen);
    } else {
      setIsCollapsed(!isCollapsed);
    }
  };

  const sidebarWidth = isCollapsed ? 'w-20' : 'w-64';
  const isDrawerOpen = isMobile ? isOpen : true;

  return (
    <>
      {/* Mobile toggle button - only show on mobile */}
      <button
        onClick={toggleSidebar}
        className="fixed top-4 left-4 z-50 md:hidden p-2 rounded-lg bg-background border border-border hover:bg-muted transition-colors"
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

      {/* Sidebar */}
      <aside
        className={`
          transition-all duration-300 ease-in-out
          ${isMobile 
            ? `fixed top-0 left-0 h-screen ${sidebarWidth} bg-muted border-r border-border flex flex-col z-40 ${
                isOpen ? 'translate-x-0' : '-translate-x-full'
              }` 
            : `relative h-screen ${sidebarWidth} bg-muted border-r border-border flex flex-col`
          }
        `}
      >
        {/* Header with collapse button for desktop */}
        <div className={`border-b border-border transition-all duration-300 flex items-center justify-between ${isCollapsed ? 'p-3' : 'p-6'}`}>
          <div className="flex-1">
            {!isCollapsed ? (
              <>
                <h1 className="text-2xl font-bold">Whity</h1>
                <p className="text-sm text-muted-foreground mt-1">Admin</p>
              </>
            ) : (
              <div className="text-xl font-bold text-center font-black">W</div>
            )}
          </div>

          {/* Collapse/Expand button - only show on desktop */}
          {!isMobile && (
            <button
              onClick={() => setIsCollapsed(!isCollapsed)}
              className="p-1 hover:bg-background rounded transition-colors ml-2"
              title={isCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            >
              {isCollapsed ? (
                <IconChevronRight size={20} />
              ) : (
                <IconChevronLeft size={20} />
              )}
            </button>
          )}
        </div>

        {/* Navigation */}
        <nav className="flex-1 p-2 space-y-3 overflow-y-auto">
          {Array.from(groupedItems.entries()).map(([groupId, navItems]) => {
            if (navItems.length === 0) return null;

            const isUngrouped = groupId === '_ungrouped';
            const groupLabel = groupId.charAt(0).toUpperCase() + groupId.slice(1);

            return (
              <div key={groupId}>
                {!isUngrouped && (
                  <div className={`text-xs font-semibold uppercase text-muted-foreground px-2 mb-2 ${isCollapsed && !isMobile ? 'text-center' : ''}`}>
                    {!isCollapsed && !isMobile ? groupLabel : ''}
                  </div>
                )}
                <div className="space-y-1">
                  {navItems.map((item, index) => {
                    const Icon = resolveIcon(item.icon);
                    const hrefSegments = item.href.split('/').filter(Boolean).length;
                    const isActive = pathname === item.href ||
                      (pathname.startsWith(item.href + '/') && hrefSegments > 1);

                    return (
                      <Link
                        key={item.id}
                        href={item.href}
                        onClick={() => isMobile && setIsOpen(false)}
                      >
                        <Button
                          variant={isActive ? 'default' : 'ghost'}
                          size={isCollapsed && !isMobile ? 'icon' : 'default'}
                          className={`w-full ${isCollapsed && !isMobile ? 'justify-center' : 'justify-start'}`}
                          title={isCollapsed && !isMobile ? item.label : `${index + 1}. ${item.label}`}
                        >
                          <Icon size={20} className={isCollapsed && !isMobile ? '' : 'mr-3 flex-shrink-0'} />
                          {(!isCollapsed || isMobile) && (
                            <>
                              <span className="text-xs text-muted-foreground mr-2 w-5">
                                {index + 1}
                              </span>
                              <span className="flex-1 text-left">{item.label}</span>
                            </>
                          )}
                        </Button>
                      </Link>
                    );
                  })}
                </div>
              </div>
            );
          })}
        </nav>

        {/* Footer */}
        <div className={`border-t border-border transition-all duration-300 ${isCollapsed ? 'p-2' : 'p-4'} space-y-2`}>
          {(!isCollapsed || isMobile) && (
            <div className="px-2 py-2 bg-background rounded-lg text-center md:text-left">
              <p className="text-xs text-muted-foreground truncate">Logged in as</p>
              <p className="text-sm font-medium truncate">{user?.email}</p>
            </div>
          )}
          <Button
            onClick={handleLogout}
            variant="outline"
            size={isCollapsed && !isMobile ? 'icon' : 'default'}
            className={`w-full ${isCollapsed && !isMobile ? 'justify-center' : 'justify-start'}`}
            title={isCollapsed && !isMobile ? 'Logout' : undefined}
          >
            <IconLogout size={20} className={isCollapsed && !isMobile ? '' : 'mr-3 flex-shrink-0'} />
            {(!isCollapsed || isMobile) && 'Logout'}
          </Button>
        </div>
      </aside>
    </>
  );
}
