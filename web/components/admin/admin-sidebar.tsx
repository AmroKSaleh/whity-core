'use client';

import Link from 'next/link';
import { usePathname } from 'next/navigation';
import { useNavigation } from '@/lib/navigation-context';
import { cn } from '@/lib/utils';

export function AdminSidebar() {
  const pathname = usePathname();
  const { groups, getGroupedItems } = useNavigation();
  const groupedItems = getGroupedItems();

  // Sort groups by order
  const sortedGroupIds = Array.from(groupedItems.keys()).sort((a, b) => {
    if (a === '_ungrouped') return 1;
    if (b === '_ungrouped') return -1;

    const groupA = groups.find((g) => g.id === a);
    const groupB = groups.find((g) => g.id === b);
    return (groupA?.order || 0) - (groupB?.order || 0);
  });

  return (
    <div className="fixed left-0 top-0 h-screen w-64 bg-slate-900 text-slate-50 flex flex-col">
      {/* Logo/Branding */}
      <div className="px-6 py-8">
        <h2 className="text-xl font-bold">Admin Panel</h2>
      </div>

      {/* Navigation */}
      <nav className="flex-1 px-4 py-6 overflow-y-auto">
        {sortedGroupIds.map((groupId) => {
          const items = groupedItems.get(groupId) || [];
          if (items.length === 0) return null;

          const group = groups.find((g) => g.id === groupId);
          const isUngrouped = groupId === '_ungrouped';

          return (
            <div key={groupId} className="mb-6">
              {!isUngrouped && group && (
                <h3 className="px-4 py-2 text-xs font-semibold uppercase text-slate-400">
                  {group.label}
                </h3>
              )}
              <ul className="space-y-2">
                {items.map((item, index) => {
                  const Icon = item.icon;
                  const isActive =
                    pathname === item.href ||
                    pathname.startsWith(item.href + '/');

                  return (
                    <li key={item.id}>
                      <Link
                        href={item.href}
                        className={cn(
                          'flex items-center gap-3 rounded-lg px-4 py-2.5 text-sm font-medium transition-colors',
                          isActive
                            ? 'bg-slate-700 text-white'
                            : 'text-slate-400 hover:bg-slate-800 hover:text-slate-200'
                        )}
                        title={`${index + 1}. ${item.label}`}
                      >
                        <span className="text-xs w-4 text-slate-500">
                          {index + 1}
                        </span>
                        <Icon size={20} />
                        <span>{item.label}</span>
                      </Link>
                    </li>
                  );
                })}
              </ul>
            </div>
          );
        })}
      </nav>

      {/* Footer */}
      <div className="border-t border-slate-700 px-6 py-4">
        <p className="text-xs text-slate-500">Whity Core Admin</p>
      </div>
    </div>
  );
}
