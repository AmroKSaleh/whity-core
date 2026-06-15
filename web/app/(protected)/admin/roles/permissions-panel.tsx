'use client';

import { useCallback, useState, useEffect } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import type { Permission, Role } from './types';

interface PermissionsPanelProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  role: Role;
}

export function PermissionsPanel({
  isOpen,
  onOpenChange,
  role,
}: PermissionsPanelProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [isLoading, setIsLoading] = useState(false);

  const fetchRolePermissions = useCallback(async () => {
    try {
      setIsLoading(true);
      const response = await apiClient(`/api/roles/${role.id}/permissions`);

      if (!response.ok) {
        throw new Error('Failed to fetch role permissions');
      }

      const data: { data: Permission[] } = await response.json();
      setPermissions(data.data);
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to fetch permissions';
      addToast(message, 'error');
    } finally {
      setIsLoading(false);
    }
  }, [apiClient, role.id, addToast]);

  useEffect(() => {
    if (isOpen) {
      void (async () => {
        await fetchRolePermissions();
      })();
    }
  }, [isOpen, fetchRolePermissions]);

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl">
        <DialogHeader>
          <DialogTitle>{role.name} - Permissions</DialogTitle>
          <DialogDescription>
            View all permissions assigned to this role.
          </DialogDescription>
        </DialogHeader>

        <div className="py-4">
          {isLoading ? (
            <div className="text-sm text-muted-foreground py-8 text-center">
              Loading permissions...
            </div>
          ) : permissions.length === 0 ? (
            <div className="text-sm text-muted-foreground py-8 text-center">
              No permissions assigned to this role.
            </div>
          ) : (
            <div className="space-y-2 max-h-64 overflow-y-auto">
              {permissions.map(permission => (
                <div
                  key={permission.id}
                  className="rounded-lg border border-slate-200 dark:border-slate-700 p-3"
                >
                  <div className="text-sm font-medium text-slate-900 dark:text-slate-50">
                    {permission.name}
                  </div>
                  <div className="text-xs text-slate-600 dark:text-slate-400 mt-1">
                    {permission.description}
                  </div>
                </div>
              ))}
            </div>
          )}
        </div>

        <div className="flex justify-end">
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
          >
            Close
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
