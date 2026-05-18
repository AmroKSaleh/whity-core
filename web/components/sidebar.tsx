'use client';

import Link from 'next/link';
import { usePathname, useRouter } from 'next/navigation';
import { useAuth } from '@/lib/auth-context';
import { Button } from '@/components/ui/button';
import {
  IconDashboard,
  IconUsers,
  IconShield,
  IconBuilding,
  IconChartBar,
  IconLogout,
  IconMenu2,
} from '@tabler/icons-react';
import { useState } from 'react';

const navItems = [
  { href: '/dashboard', label: 'Dashboard', icon: IconDashboard },
  { href: '/admin/users', label: 'Users', icon: IconUsers },
  { href: '/admin/roles', label: 'Roles', icon: IconShield },
  { href: '/admin/tenants', label: 'Tenants', icon: IconBuilding },
  { href: '/admin/stats', label: 'Statistics', icon: IconChartBar },
];

export function Sidebar() {
  const pathname = usePathname();
  const router = useRouter();
  const { logout, user } = useAuth();
  const [isOpen, setIsOpen] = useState(true);

  const handleLogout = () => {
    logout();
    router.push('/login');
  };

  return (
    <>
      {/* Mobile toggle button */}
      <button
        onClick={() => setIsOpen(!isOpen)}
        className="fixed top-4 left-4 z-50 p-2 rounded-lg lg:hidden bg-muted"
      >
        <IconMenu2 size={24} />
      </button>

      {/* Sidebar */}
      <aside
        className={`fixed inset-y-0 left-0 w-64 bg-muted border-r border-border transition-transform duration-200 ease-in-out transform ${
          isOpen ? 'translate-x-0' : '-translate-x-full'
        } lg:translate-x-0 lg:static lg:w-64 flex flex-col z-40`}
      >
        {/* Header */}
        <div className="p-6 border-b border-border">
          <h1 className="text-2xl font-bold">Whity</h1>
          <p className="text-sm text-muted-foreground mt-1">Admin Panel</p>
        </div>

        {/* Navigation */}
        <nav className="flex-1 p-4 space-y-2">
          {navItems.map((item) => {
            const Icon = item.icon;
            const isActive = pathname === item.href || pathname.startsWith(item.href + '/');
            return (
              <Link key={item.href} href={item.href}>
                <Button
                  variant={isActive ? 'default' : 'ghost'}
                  className="w-full justify-start"
                  onClick={() => setIsOpen(false)}
                >
                  <Icon size={20} className="mr-3" />
                  {item.label}
                </Button>
              </Link>
            );
          })}
        </nav>

        {/* Footer - User Info & Logout */}
        <div className="p-4 border-t border-border space-y-3">
          <div className="px-2 py-2 bg-background rounded-lg">
            <p className="text-xs text-muted-foreground">Logged in as</p>
            <p className="text-sm font-medium truncate">{user?.email}</p>
          </div>
          <Button
            onClick={handleLogout}
            variant="outline"
            className="w-full justify-start"
          >
            <IconLogout size={20} className="mr-3" />
            Logout
          </Button>
        </div>
      </aside>

      {/* Mobile overlay */}
      {isOpen && (
        <div
          className="fixed inset-0 bg-black/50 z-30 lg:hidden"
          onClick={() => setIsOpen(false)}
        />
      )}
    </>
  );
}
