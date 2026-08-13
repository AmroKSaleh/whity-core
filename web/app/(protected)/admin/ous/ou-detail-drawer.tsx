'use client';

import { useCallback, useEffect, useState } from 'react';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  Sheet,
  SheetContent,
  SheetDescription,
  SheetHeader,
  SheetTitle,
} from '@/components/ui/sheet';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import {
  IconEdit,
  IconPlus,
  IconTrash,
  IconUsers,
  IconX,
} from '@tabler/icons-react';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { OU } from './types';
import type { OuAction } from './ou-view';

/** A role as returned by GET /api/ous/{id}/roles and GET /api/roles. */
interface OuRole {
  id: number;
  name: string;
  description: string;
}

/** A member as returned by GET /api/ous/{id}/members (public user shape). */
interface OuMember {
  id: number;
  name: string;
  email: string;
  role: string;
  tenantId: number;
}

interface OuDetailDrawerProps {
  /** The selected OU (drawer is open when non-null), or null when closed. */
  ou: OU | null;
  /** Close the drawer (clears the selection on the page). */
  onClose: () => void;
  /** Bubble a structural action (edit / create-child / move / delete) to the page. */
  onAction: (action: OuAction, ou: OU) => void;
  /** Notify the page that the OU changed (e.g. a role was added/removed). */
  onChanged: () => void;
}

/**
 * `OuDetailDrawer` — the management "hub" for the selected OU.
 *
 * Owns its own roles/members fetch (re-fetched whenever the selected OU
 * changes). Shows the OU details with the structural actions (which it bubbles
 * up via `onAction`), a Roles section that lists and mutates the OU's role
 * assignments in place, and a read-only Members section.
 */
export function OuDetailDrawer({ ou, onClose, onAction, onChanged }: OuDetailDrawerProps) {
  const { apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');

  const [roles, setRoles] = useState<OuRole[]>([]);
  const [members, setMembers] = useState<OuMember[]>([]);
  const [allRoles, setAllRoles] = useState<OuRole[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [pendingRoleId, setPendingRoleId] = useState<string>('');
  const [isMutating, setIsMutating] = useState(false);

  const ouId = ou?.id ?? null;

  const loadDetail = useCallback(async () => {
    if (ouId === null) {
      return;
    }
    setIsLoading(true);
    setPendingRoleId('');
    try {
      const [rolesRes, membersRes, allRolesRes] = await Promise.all([
        apiClient(`/api/v1/ous/${ouId}/roles`),
        apiClient(`/api/v1/ous/${ouId}/members`),
        apiClient('/api/v1/roles'),
      ]);

      if (rolesRes.ok) {
        setRoles(((await rolesRes.json()).data ?? []) as OuRole[]);
      }
      if (membersRes.ok) {
        setMembers(((await membersRes.json()).data ?? []) as OuMember[]);
      }
      if (allRolesRes.ok) {
        setAllRoles(((await allRolesRes.json()).data ?? []) as OuRole[]);
      }
    } catch {
      addToast(t('ous.detail.loadError', 'Failed to load OU details'), 'error');
    } finally {
      setIsLoading(false);
    }
  }, [apiClient, ouId, addToast, t]);

  useEffect(() => {
    if (ouId !== null) {
      void (async () => {
        await loadDetail();
      })();
    }
  }, [ouId, loadDetail]);

  const assignedRoleIds = new Set(roles.map((r) => r.id));
  const assignableRoles = allRoles.filter((r) => !assignedRoleIds.has(r.id));

  const handleAssignRole = async () => {
    if (ouId === null || !pendingRoleId) {
      return;
    }
    setIsMutating(true);
    try {
      const res = await apiClient(`/api/v1/ous/${ouId}/roles`, {
        method: 'POST',
        body: JSON.stringify({ role_id: parseInt(pendingRoleId, 10) }),
      });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error || t('ous.detail.roles.assignError', 'Failed to assign role'));
      }
      addToast(t('ous.detail.roles.assignSuccess', 'Role assigned'), 'success');
      setPendingRoleId('');
      await loadDetail();
      onChanged();
    } catch (error) {
      addToast(
        error instanceof Error
          ? error.message
          : t('ous.detail.roles.assignError', 'Failed to assign role'),
        'error'
      );
    } finally {
      setIsMutating(false);
    }
  };

  const handleRemoveRole = async (roleId: number) => {
    if (ouId === null) {
      return;
    }
    setIsMutating(true);
    try {
      const res = await apiClient(`/api/v1/ous/${ouId}/roles/${roleId}`, { method: 'DELETE' });
      if (!res.ok) {
        const body = await res.json().catch(() => ({}));
        throw new Error(body.error || t('ous.detail.roles.removeError', 'Failed to remove role'));
      }
      addToast(t('ous.detail.roles.removeSuccess', 'Role removed'), 'success');
      await loadDetail();
      onChanged();
    } catch (error) {
      addToast(
        error instanceof Error
          ? error.message
          : t('ous.detail.roles.removeError', 'Failed to remove role'),
        'error'
      );
    } finally {
      setIsMutating(false);
    }
  };

  return (
    <Sheet open={ou !== null} onOpenChange={(open) => !open && onClose()}>
      <SheetContent aria-describedby={undefined}>
        {ou && (
          <>
            <SheetHeader>
              <SheetTitle>{ou.name}</SheetTitle>
              <SheetDescription>
                {ou.description ? ou.description : t('ous.detail.noDescription', 'No description.')}
              </SheetDescription>
              <p className="mt-1 font-mono text-[0.625rem] text-muted-foreground">
                {t('ous.detail.slug', 'slug: {slug}', { slug: ou.slug })}
              </p>
            </SheetHeader>

            <div className="flex flex-wrap gap-2">
              <Button size="sm" variant="outline" onClick={() => onAction('create-child', ou)}>
                <IconPlus />
                {t('ous.detail.action.addChild', 'Add child')}
              </Button>
              <Button size="sm" variant="outline" onClick={() => onAction('edit', ou)}>
                <IconEdit />
                {t('ous.detail.action.edit', 'Edit')}
              </Button>
              <Button size="sm" variant="destructive" onClick={() => onAction('delete', ou)}>
                <IconTrash />
                {t('ous.detail.action.delete', 'Delete')}
              </Button>
            </div>

            <section aria-labelledby="ou-roles-heading" className="space-y-2">
              <h3 id="ou-roles-heading" className="font-heading text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {t('ous.detail.roles.heading', 'Roles')}
              </h3>

              <div className="flex gap-2">
                <Select
                  value={pendingRoleId}
                  onValueChange={setPendingRoleId}
                  disabled={isMutating || assignableRoles.length === 0}
                >
                  <SelectTrigger
                    aria-label={t('ous.detail.roles.select', 'Select a role to assign')}
                    className="flex-1"
                  >
                    <SelectValue
                      placeholder={
                        assignableRoles.length === 0
                          ? t('ous.detail.roles.allAssigned', 'All roles assigned')
                          : t('ous.detail.roles.select', 'Select a role to assign')
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {assignableRoles.map((role) => (
                      <SelectItem key={role.id} value={role.id.toString()}>
                        {role.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
                <Button
                  size="sm"
                  onClick={handleAssignRole}
                  disabled={isMutating || !pendingRoleId}
                >
                  {t('ous.detail.roles.assign', 'Assign')}
                </Button>
              </div>

              {isLoading ? (
                <Skeleton className="h-8 w-full" />
              ) : roles.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  {t('ous.detail.roles.empty', 'No roles assigned to this OU.')}
                </p>
              ) : (
                <ul className="space-y-1">
                  {roles.map((role) => (
                    <li
                      key={role.id}
                      className="flex items-center justify-between gap-2 rounded-md border border-border bg-card px-2 py-1"
                    >
                      <div className="min-w-0">
                        <Badge variant="secondary">{role.name}</Badge>
                        {role.description && (
                          <span className="ms-2 truncate text-xs text-muted-foreground">
                            {role.description}
                          </span>
                        )}
                      </div>
                      <Button
                        variant="ghost"
                        size="icon-sm"
                        aria-label={t('ous.detail.roles.remove', 'Remove role {name}', {
                          name: role.name,
                        })}
                        disabled={isMutating}
                        onClick={() => handleRemoveRole(role.id)}
                      >
                        <IconX />
                      </Button>
                    </li>
                  ))}
                </ul>
              )}
            </section>

            <section aria-labelledby="ou-members-heading" className="space-y-2">
              <h3 id="ou-members-heading" className="flex items-center gap-1.5 font-heading text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <IconUsers className="size-3.5" />
                {t('ous.detail.members.heading', 'Members')}
              </h3>
              {isLoading ? (
                <Skeleton className="h-8 w-full" />
              ) : members.length === 0 ? (
                <p className="text-xs text-muted-foreground">
                  {t('ous.detail.members.empty', 'No users are assigned to this OU.')}
                </p>
              ) : (
                <ul className="space-y-1">
                  {members.map((member) => (
                    <li
                      key={member.id}
                      className="flex items-center justify-between gap-2 rounded-md border border-border bg-card px-2 py-1"
                    >
                      <span className="min-w-0 truncate">{member.email}</span>
                      <Badge variant="outline">{member.role}</Badge>
                    </li>
                  ))}
                </ul>
              )}
              <Alert variant="info">
                <AlertDescription>
                  {t(
                    'ous.detail.members.note',
                    'Members are read-only here; assign users to an OU from user management.'
                  )}
                </AlertDescription>
              </Alert>
            </section>
          </>
        )}
      </SheetContent>
    </Sheet>
  );
}
