'use client';

import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@amroksaleh/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import type {
  GranteeType,
  OuOption,
  Permission,
  RoleOption,
  UserOption,
} from './types';

interface CreateDelegationModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  onSuccess: () => void;
}

/**
 * Create-delegation dialog (WC-34).
 *
 * Lets the acting user delegate a SUBSET of their own permissions to a role or a
 * user, optionally scoped to an OU subtree. The HARD subset invariant is enforced
 * server-side: if the grantor selects a permission they do not hold, the API
 * returns 422 and the message is surfaced as an error toast — the form never
 * fabricates an entitlement client-side.
 */
export function CreateDelegationModal({
  isOpen,
  onOpenChange,
  onSuccess,
}: CreateDelegationModalProps) {
  const { addToast } = useToast();

  // Starts true: the dialog remounts on open (parent `key`) and immediately
  // loads its picker options, so the loading state is shown from first paint
  // without a synchronous setState in the load effect.
  const [isLoadingOptions, setIsLoadingOptions] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [roles, setRoles] = useState<RoleOption[]>([]);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [ous, setOus] = useState<OuOption[]>([]);

  // Form state. The parent remounts this component (via `key`) each time the
  // dialog opens, so these defaults reset on open — no synchronous setState in
  // an effect is needed (which this React version's lint rules disallow).
  const [granteeType, setGranteeType] = useState<GranteeType>('role');
  const [granteeId, setGranteeId] = useState<string>('');
  const [selectedPermissions, setSelectedPermissions] = useState<string[]>([]);
  const [ouId, setOuId] = useState<string>('');

  const loadOptions = useCallback(async () => {
    try {
      const [permsRes, rolesRes, usersRes, ousRes] = await Promise.all([
        // per_page=100 (the max) fetches the WHOLE permission catalogue in one
        // page — the picker must show every delegatable permission. Without it the
        // default page size (25) silently drops permissions past the first page as
        // the catalogue grows (mirrors the roles editor's per_page=100).
        api.GET('/api/v1/permissions', { params: { query: { per_page: 100 } } }),
        api.GET('/api/v1/roles'),
        api.GET('/api/v1/users'),
        api.GET('/api/v1/ous'),
      ]);

      if (permsRes.data !== undefined) {
        setPermissions(permsRes.data.data);
      }
      if (rolesRes.data !== undefined) {
        setRoles(rolesRes.data.data);
      }
      if (usersRes.data !== undefined) {
        setUsers(usersRes.data.data);
      }
      if (ousRes.data !== undefined) {
        setOus(ousRes.data.data);
      }
    } catch {
      addToast('Failed to load delegation options', 'error');
    } finally {
      setIsLoadingOptions(false);
    }
  }, [addToast]);

  // Load the picker options when the dialog opens. Fetching external data is a
  // legitimate effect (synchronising with an external system); the async work is
  // wrapped so the load runs off the synchronous effect tick.
  useEffect(() => {
    if (isOpen) {
      void (async () => {
        await loadOptions();
      })();
    }
  }, [isOpen, loadOptions]);

  const togglePermission = (name: string) => {
    setSelectedPermissions((current) =>
      current.includes(name)
        ? current.filter((p) => p !== name)
        : [...current, name]
    );
  };

  const handleSubmit = async () => {
    if (granteeId === '') {
      addToast('Select a grantee', 'error');
      return;
    }
    if (selectedPermissions.length === 0) {
      addToast('Select at least one permission to delegate', 'error');
      return;
    }

    try {
      setIsSubmitting(true);
      const { error, response } = await api.POST('/api/v1/delegations', {
        body: {
          granteeType,
          granteeId: Number(granteeId),
          permissions: selectedPermissions,
          ouId: ouId === '' ? null : Number(ouId),
        },
      });

      // `error` is undefined for body-less failures too — also gate on the
      // status so a 5xx without a JSON body can never toast success.
      if (error !== undefined || !response.ok) {
        // 422 = subset-invariant violation (a permission the grantor lacks).
        throw new Error(error?.error ?? 'Failed to create delegation');
      }

      addToast('Delegation created successfully', 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error ? error.message : 'Failed to create delegation';
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const granteeOptions =
    granteeType === 'role'
      ? roles.map((r) => ({ value: String(r.id), label: r.name }))
      : users.map((u) => ({ value: String(u.id), label: u.email }));

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent className="max-w-2xl max-h-[90vh] overflow-y-auto">
        <DialogHeader>
          <DialogTitle>Create Delegation</DialogTitle>
          <DialogDescription>
            Grant a subset of your own permissions to a role or a user. You can
            only delegate permissions you currently hold.
          </DialogDescription>
        </DialogHeader>

        {isLoadingOptions ? (
          <div className="space-y-3 py-4">
            <Skeleton className="h-10 w-full rounded-md" />
            <Skeleton className="h-10 w-full rounded-md" />
            <Skeleton className="h-40 w-full rounded-md" />
          </div>
        ) : (
          <div className="space-y-5 py-2">
            {/* Grantee type */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Delegate to</label>
              <Select
                value={granteeType}
                onValueChange={(value) => {
                  setGranteeType(value as GranteeType);
                  setGranteeId('');
                }}
              >
                <SelectTrigger>
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="role">Role</SelectItem>
                  <SelectItem value="user">User</SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Grantee */}
            <div className="space-y-2">
              <label className="text-sm font-medium">
                {granteeType === 'role' ? 'Role' : 'User'}
              </label>
              <Select value={granteeId} onValueChange={setGranteeId}>
                <SelectTrigger>
                  <SelectValue
                    placeholder={`Select a ${granteeType}`}
                  />
                </SelectTrigger>
                <SelectContent>
                  {granteeOptions.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                      {option.label}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            {/* OU scope (optional) */}
            <div className="space-y-2">
              <label className="text-sm font-medium">
                Scope to organizational unit{' '}
                <span className="text-muted-foreground">(optional)</span>
              </label>
              <Select
                value={ouId === '' ? 'none' : ouId}
                onValueChange={(value) => setOuId(value === 'none' ? '' : value)}
              >
                <SelectTrigger>
                  <SelectValue placeholder="Tenant-wide" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">Tenant-wide</SelectItem>
                  {ous.map((ou) => (
                    <SelectItem key={ou.id} value={String(ou.id)}>
                      {ou.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              <Alert variant="info">
                <AlertDescription>
                  When set, the delegation applies only to grantees within that OU
                  or its descendants.
                </AlertDescription>
              </Alert>
            </div>

            {/* Permissions */}
            <div className="space-y-2">
              <label className="text-sm font-medium">Permissions</label>
              <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border border-border p-2">
                {permissions.length === 0 ? (
                  <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                    No permissions available.
                  </p>
                ) : (
                  permissions.map((permission) => (
                    <label
                      // Registry-only permissions carry id: null — the name is
                      // the stable identity (it is the catalogue's merge key).
                      key={permission.name}
                      className="flex cursor-pointer items-center gap-2 rounded-md px-2 py-1.5 hover:bg-muted"
                    >
                      <input
                        type="checkbox"
                        checked={selectedPermissions.includes(permission.name)}
                        onChange={() => togglePermission(permission.name)}
                        className="size-4 rounded border-border"
                      />
                      <span className="text-sm font-medium">
                        {permission.name}
                      </span>
                    </label>
                  ))
                )}
              </div>
            </div>
          </div>
        )}

        <DialogFooter>
          <Button
            type="button"
            variant="outline"
            onClick={() => onOpenChange(false)}
            disabled={isSubmitting}
          >
            Cancel
          </Button>
          <Button
            type="button"
            onClick={handleSubmit}
            disabled={isSubmitting || isLoadingOptions}
          >
            {isSubmitting ? 'Creating...' : 'Create Delegation'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
