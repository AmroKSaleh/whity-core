'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import {
  IconDashboard,
  IconUsers,
  IconLock,
  IconBuilding,
  IconOrganization,
} from '@tabler/icons-react';
import { cn } from '@/lib/utils';

const navItems = [
  {
    label: 'Dashboard',
    href: '/admin',
    icon: IconDashboard,
  },
  {
    label: 'Users',
    href: '/admin/users',
    icon: IconUsers,
  },
  {
    label: 'Roles',
    href: '/admin/roles',
    icon: IconLock,
  },
  {
    label: 'Organizational Units',
    href: '/admin/ous',
    icon: IconOrganization,
  },
  {
    label: 'Tenants',
    href: '/admin/tenants',
    icon: IconBuilding,
  },
];

export function AdminSidebar() {
  const pathname = usePathname();

  return (
    <div className="fixed left-0 top-0 h-screen w-64 bg-slate-900 text-slate-50 flex flex-col">
      {/* Logo/Branding */}
      <div className="px-6 py-8">
        <h2 className="text-xl font-bold">Admin Panel</h2>
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-4 py-6">
        <ul className="space-y-2">
          {navItems.map((item) => {
            const Icon = item.icon;
            const isActive =
              pathname === item.href || pathname.startsWith(item.href + '/');

            return (
              <li key={item.href}>
                <Link
                  href={item.href}
                  className={cn(
                    'flex items-center gap-3 rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
                    isActive
                      ? 'bg-slate-700 text-white'
                      : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'
                  )}
                >
                  <Icon size={20} />
                  <span>{item.label}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      </nav>

      {/* Footer */}
      <div className="border-t border-slate-700 px-6 py-4">
        <p className="text-xs text-slate-500">Whity Core Admin</p>
      </div>
    </div>
  );
}
