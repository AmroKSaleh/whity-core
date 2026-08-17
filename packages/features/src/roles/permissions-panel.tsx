'use client';

import { useState, useEffect } from 'react';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import type { Permission, Role, RolesAdapter, TranslateFn } from './types';

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
  /** Injected data-source adapter. */
  adapter: RolesAdapter;
  /** Injected translator (resolved by RolesScreen). */
  t: TranslateFn;
  /** Optional notifier. */
  onNotify?: (message: string, type: 'success' | 'error') => void;
}

export function PermissionsPanel({
  isOpen,
  onOpenChange,
  role,
  adapter,
  t,
  onNotify,
}: PermissionsPanelProps) {
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [isLoading, setIsLoading] = useState(false);

  useEffect(() => {
    if (!isOpen) return;
    let cancelled = false;
    setIsLoading(true);
    adapter
      .getRolePermissions(role.id)
      .then((data) => {
        if (!cancelled) setPermissions(data);
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        const message =
          error instanceof Error && error.message
            ? error.message
            : t('roles.permissionsPanel.error', 'Failed to fetch permissions');
        onNotify?.(message, 'error');
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // Re-run only when the modal opens for a given role.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [isOpen, role.id]);

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-xl" closeLabel={t('ui.dialog.close', 'Close')}>
        <DialogHeader>
          <DialogTitle>
            {t('roles.permissionsPanel.title', '{name} - Permissions', { name: role.name })}
          </DialogTitle>
          <DialogDescription>
            {t('roles.permissionsPanel.subtitle', 'View all permissions assigned to this role.')}
          </DialogDescription>
        </DialogHeader>

        <div className="py-4">
          {isLoading ? (
            <div className="text-sm text-muted-foreground py-8 text-center">
              {t('roles.permissionsPanel.loading', 'Loading permissions...')}
            </div>
          ) : permissions.length === 0 ? (
            <div className="text-sm text-muted-foreground py-8 text-center">
              {t('roles.permissionsPanel.empty', 'No permissions assigned to this role.')}
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
            {t('roles.permissionsPanel.close', 'Close')}
          </Button>
        </div>
      </DialogContent>
    </Dialog>
  );
}
