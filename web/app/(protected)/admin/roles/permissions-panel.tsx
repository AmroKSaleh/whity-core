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
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import type { Permission, Role } from './types';

/** Group permissions by resource (segment before ':'), sorted, stable within. */
function groupPermissions(permissions: Permission[]): [string, Permission[]][] {
  const map = new Map<string, Permission[]>();
  for (const p of permissions) {
    const i = (p.name ?? '').indexOf(':');
    const g = i > 0 ? p.name.slice(0, i) : 'general';
    const list = map.get(g);
    if (list) list.push(p);
    else map.set(g, [p]);
  }
  return [...map.entries()].sort(([a], [b]) => a.localeCompare(b));
}

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
      const response = await apiClient(`/api/v1/roles/${role.id}/permissions`);

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
            <div className="space-y-3 max-h-80 overflow-y-auto">
              {groupPermissions(permissions).map(([group, perms]) => (
                <div key={group}>
                  <div className="mb-1 flex items-center justify-between">
                    <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                      {group.charAt(0).toUpperCase() + group.slice(1)}
                    </span>
                    <span className="text-[10px] text-muted-foreground">{perms.length}</span>
                  </div>
                  <div className="space-y-1">
                    {perms.map(permission => (
                      <div key={permission.id} className="rounded-md border border-border p-2">
                        <div className="font-mono text-sm text-foreground">{permission.name}</div>
                        {permission.description && (
                          <div className="mt-0.5 text-xs text-muted-foreground">{permission.description}</div>
                        )}
                      </div>
                    ))}
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
