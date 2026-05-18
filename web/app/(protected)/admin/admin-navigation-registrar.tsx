'use client';

import {
  IconDashboard,
  IconUsers,
  IconLock,
  IconBuilding,
  IconBuildingCommunity,
} from '@tabler/icons-react';
import { useRegisterNavigationGroup, useRegisterNavigation } from '@/lib/navigation-context';

export function AdminNavigationRegistrar() {
  // Register admin group
  useRegisterNavigationGroup({
    id: 'admin',
    label: 'Admin',
    order: 1,
  });

  // Register dashboard
  useRegisterNavigation({
    id: 'dashboard',
    label: 'Dashboard',
    href: '/admin',
    icon: IconDashboard,
    group: 'admin',
    order: 1,
  });

  // Register users
  useRegisterNavigation({
    id: 'users',
    label: 'Users',
    href: '/admin/users',
    icon: IconUsers,
    group: 'admin',
    order: 2,
  });

  // Register roles
  useRegisterNavigation({
    id: 'roles',
    label: 'Roles',
    href: '/admin/roles',
    icon: IconLock,
    group: 'admin',
    order: 3,
  });

  // Register organizational units
  useRegisterNavigation({
    id: 'ous',
    label: 'Organizational Units',
    href: '/admin/ous',
    icon: IconBuildingCommunity,
    group: 'admin',
    order: 4,
  });

  // Register tenants
  useRegisterNavigation({
    id: 'tenants',
    label: 'Tenants',
    href: '/admin/tenants',
    icon: IconBuilding,
    group: 'admin',
    order: 5,
  });

  return null;
}
