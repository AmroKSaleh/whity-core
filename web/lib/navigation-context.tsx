'use client';

import React, { createContext, useContext, useState, useCallback, useEffect } from 'react';
import type { IconProps } from '@tabler/icons-react';

export interface NavigationItem {
  id: string;
  label: string;
  href: string;
  icon: string;
  group?: string;
  order: number;
}

interface NavigationContextType {
  items: NavigationItem[];
  isLoading: boolean;
  getGroupedItems: () => Map<string, NavigationItem[]>;
}

const NavigationContext = createContext<NavigationContextType | undefined>(undefined);

export function NavigationProvider({ children }: { children: React.ReactNode }) {
  const [items, setItems] = useState<NavigationItem[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  useEffect(() => {
    const fetchNavigation = async () => {
      try {
        const apiUrl = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';
        const response = await fetch(`${apiUrl}/api/navigation`);
        if (!response.ok) throw new Error('Failed to fetch navigation');
        const data = await response.json();
        setItems(data.data || []);
      } catch (error) {
        console.error('Error fetching navigation:', error);
      } finally {
        setIsLoading(false);
      }
    };

    fetchNavigation();
  }, []);

  const getGroupedItems = useCallback(() => {
    const grouped = new Map<string, NavigationItem[]>();
    grouped.set('_ungrouped', []);

    // Group items by group property
    items.forEach((item) => {
      const groupId = item.group || '_ungrouped';
      if (!grouped.has(groupId)) {
        grouped.set(groupId, []);
      }
      grouped.get(groupId)!.push(item);
    });

    return grouped;
  }, [items]);

  return (
    <NavigationContext.Provider value={{ items, isLoading, getGroupedItems }}>
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
