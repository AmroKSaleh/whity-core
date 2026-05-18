'use client';

import { useMemo } from 'react';
import {
  IconDashboard,
  IconUsers,
  IconLock,
  IconBuilding,
  IconBuildingCommunity,
} from '@tabler/icons-react';
import { useRegisterNavigationGroup, useRegisterNavigation } from '@/lib/navigation-context';

export function AdminNavigationRegistrar() {
  // Memoize group to prevent infinite loops
  const adminGroup = useMemo(() => ({
    id: 'admin',
    label: 'Admin',
    order: 1,
  }), []);

  useRegisterNavigationGroup(adminGroup);

  // Memoize items to prevent dependencies from changing
  const dashboardItem = useMemo(() => ({
    id: 'dashboard',
    label: 'Dashboard',
    href: '/admin',
    icon: IconDashboard,
    group: 'admin',
    order: 1,
  }), []);

  const usersItem = useMemo(() => ({
    id: 'users',
    label: 'Users',
    href: '/admin/users',
    icon: IconUsers,
    group: 'admin',
    order: 2,
  }), []);

  const rolesItem = useMemo(() => ({
    id: 'roles',
    label: 'Roles',
    href: '/admin/roles',
    icon: IconLock,
    group: 'admin',
    order: 3,
  }), []);

  const ousItem = useMemo(() => ({
    id: 'ous',
    label: 'Organizational Units',
    href: '/admin/ous',
    icon: IconBuildingCommunity,
    group: 'admin',
    order: 4,
  }), []);

  const tenantsItem = useMemo(() => ({
    id: 'tenants',
    label: 'Tenants',
    href: '/admin/tenants',
    icon: IconBuilding,
    group: 'admin',
    order: 5,
  }), []);

  // Register each item
  useRegisterNavigation(dashboardItem);
  useRegisterNavigation(usersItem);
  useRegisterNavigation(rolesItem);
  useRegisterNavigation(ousItem);
  useRegisterNavigation(tenantsItem);

  return null;
}
