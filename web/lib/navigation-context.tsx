'use client';

import React, { createContext, useContext, useState, useCallback } from 'react';
import type { IconProps } from '@tabler/icons-react';

export interface NavigationItem {
  id: string;
  label: string;
  href: string;
  icon: React.ComponentType<IconProps>;
  group?: string;
  order: number;
}

export interface NavigationGroup {
  id: string;
  label: string;
  order: number;
}

interface NavigationContextType {
  items: NavigationItem[];
  groups: NavigationGroup[];
  register: (item: NavigationItem) => void;
  registerGroup: (group: NavigationGroup) => void;
  unregister: (id: string) => void;
  unregisterGroup: (id: string) => void;
  getGroupedItems: () => Map<string, NavigationItem[]>;
}

const NavigationContext = createContext<NavigationContextType | undefined>(undefined);

export function NavigationProvider({ children }: { children: React.ReactNode }) {
  const [items, setItems] = useState<NavigationItem[]>([]);
  const [groups, setGroups] = useState<NavigationGroup[]>([]);

  const register = useCallback((item: NavigationItem) => {
    setItems((prev) => {
      const exists = prev.some((i) => i.id === item.id);
      if (exists) return prev;
      return [...prev, item].sort((a, b) => a.order - b.order);
    });
  }, []);

  const registerGroup = useCallback((group: NavigationGroup) => {
    setGroups((prev) => {
      const exists = prev.some((g) => g.id === group.id);
      if (exists) return prev;
      return [...prev, group].sort((a, b) => a.order - b.order);
    });
  }, []);

  const unregister = useCallback((id: string) => {
    setItems((prev) => prev.filter((i) => i.id !== id));
  }, []);

  const unregisterGroup = useCallback((id: string) => {
    setGroups((prev) => prev.filter((g) => g.id !== id));
  }, []);

  const getGroupedItems = useCallback(() => {
    const grouped = new Map<string, NavigationItem[]>();

    // Initialize groups
    groups.forEach((group) => {
      grouped.set(group.id, []);
    });
    grouped.set('_ungrouped', []);

    // Distribute items
    items.forEach((item) => {
      const groupId = item.group || '_ungrouped';
      const groupItems = grouped.get(groupId) || [];
      grouped.set(groupId, [...groupItems, item]);
    });

    return grouped;
  }, [items, groups]);

  return (
    <NavigationContext.Provider
      value={{ items, groups, register, registerGroup, unregister, unregisterGroup, getGroupedItems }}
    >
      {children}
    </NavigationContext.Provider>
  );
}

export function useNavigation() {
  const context = useContext(NavigationContext);
  if (!context) {
    throw new Error('useNavigation must be used within NavigationProvider');
  }
  return context;
}

export function useRegisterNavigation(item: NavigationItem) {
  const { register, unregister } = useNavigation();

  React.useEffect(() => {
    register(item);
    return () => unregister(item.id);
  }, [item, register, unregister]);
}

export function useRegisterNavigationGroup(group: NavigationGroup) {
  const { registerGroup, unregisterGroup } = useNavigation();

  React.useEffect(() => {
    registerGroup(group);
    return () => unregisterGroup(group.id);
  }, [group, registerGroup, unregisterGroup]);
}
