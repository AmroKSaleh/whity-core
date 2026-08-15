'use client';

import { useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { useAuth } from '@/lib/auth-context';
import { useToast } from '@/lib/toast-context';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Button } from '@amroksaleh/ui/button';
import { Badge } from '@amroksaleh/ui/badge';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { useTranslation } from '@amroksaleh/features/i18n';
import type { User } from './page';
import { useRoleOptions } from './use-role-options';

/** One membership row, derived from the published contract rather than mirrored. */
type Membership = components['schemas']['Membership'];

interface TenantOption {
  id: number;
  name: string;
}

/**
 * The reserved tenant id whose holders act with platform-wide authority. Only
 * they may name a target tenant on the grant below; the server refuses anyone
 * else, so the picker is hidden rather than offered and rejected.
 */
const SYSTEM_TENANT_ID = 0;

interface MembershipsModalProps {
  isOpen: boolean;
  onOpenChange: (open: boolean) => void;
  user: User;
  canManage: boolean;
}

/**
 * Which tenants a profile belongs to, and with what role in each (#797 §2).
 *
 * There was no surface for this at all: a deployment that needed one person in
 * two tenants had to write the membership row by hand. The modal is deliberately
 * two-faced, because the API is:
 *
 *  - a TENANT administrator sees and manages the roles held HERE, which is what
 *    `GET/POST /api/users/{id}/memberships` has always done;
 *  - a SYSTEM-tenant administrator sees every tenant the profile is in and can
 *    attach it to another one, which is what the optional `tenant_id` adds.
 *
 * Removal covers additional roles only. The PRIMARY row is a person's presence
 * in a tenant, and taking it away here would leave them holding secondary roles
 * with no answer to "what are they here" — evicting someone from a tenant stays
 * the Delete action on the users list.
 */
export function MembershipsModal({
  isOpen,
  onOpenChange,
  user,
  canManage,
}: MembershipsModalProps) {
  const { user: actor, apiClient } = useAuth();
  const { addToast } = useToast();
  const t = useTranslation('admin');
  const isSystemAdmin = actor?.tenant_id === SYSTEM_TENANT_ID;

  const [memberships, setMemberships] = useState<Membership[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [tenantOptions, setTenantOptions] = useState<TenantOption[]>([]);
  const [selectedTenantId, setSelectedTenantId] = useState<string>('');
  const [selectedRole, setSelectedRole] = useState<string>('');
  // Bumped after a grant or revoke to re-run the fetch below. The fetch is
  // defined INSIDE the effect (mirroring useRoleOptions) rather than hoisted
  // into a callback the effect calls: that keeps the setState calls indirect,
  // which is what the lint rule against synchronous setState in an effect wants.
  const [reloadToken, setReloadToken] = useState(0);
  const { roleOptions, isLoadingRoles } = useRoleOptions(isOpen);

  useEffect(() => {
    if (!isOpen) {
      return;
    }

    const fetchMemberships = async (): Promise<void> => {
      setIsLoading(true);
      try {
        const { data } = await api.GET('/api/v1/users/{id}/memberships', {
          params: { path: { id: user.id } },
        });
        if (data === undefined) {
          throw new Error(t('users.memberships.error.load', 'Failed to load memberships'));
        }
        setMemberships(data.data);
      } catch (error) {
        const message =
          error instanceof Error
            ? error.message
            : t('users.memberships.error.load', 'Failed to load memberships');
        addToast(message, 'error');
      } finally {
        setIsLoading(false);
      }
    };

    void fetchMemberships();
  }, [isOpen, user.id, reloadToken, addToast, t]);

  // The tenant picker is fetched only for a system administrator: for anyone
  // else the target tenant is not a choice, and GET /api/tenants would show them
  // a list they cannot act on.
  useEffect(() => {
    if (!isOpen || !isSystemAdmin) {
      return;
    }

    const fetchTenants = async (): Promise<void> => {
      try {
        // The raw client, not the typed one: `per_page` is a real query
        // parameter the tenants list honours but does not declare in the
        // published schema, so the generated types reject it. Same call the
        // Tenants admin page makes, and the same reason.
        const response = await apiClient('/api/v1/tenants?per_page=100');
        if (!response.ok) {
          throw new Error(t('users.memberships.error.tenants', 'Failed to load tenants'));
        }
        const body: { data: TenantOption[] } = await response.json();
        setTenantOptions(body.data.map((tenant) => ({ id: tenant.id, name: tenant.name })));
      } catch (error) {
        const message =
          error instanceof Error
            ? error.message
            : t('users.memberships.error.tenants', 'Failed to load tenants');
        addToast(message, 'error');
      }
    };

    void fetchTenants();
  }, [isOpen, isSystemAdmin, apiClient, addToast, t]);

  const handleGrant = async (): Promise<void> => {
    if (selectedRole === '') {
      return;
    }

    setIsSubmitting(true);
    try {
      // `tenant_id` is sent ONLY by a system administrator who picked one. A
      // tenant administrator sending it at all is a 403 server-side — the field
      // is refused rather than ignored — so it must stay absent here.
      const targetTenantId =
        isSystemAdmin && selectedTenantId !== '' ? Number(selectedTenantId) : undefined;

      const { error, response } = await api.POST('/api/v1/users/{id}/memberships', {
        params: { path: { id: user.id } },
        body:
          targetTenantId !== undefined
            ? { role: selectedRole, tenant_id: targetTenantId }
            : { role: selectedRole },
      });

      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('users.memberships.error.grant', 'Failed to grant the role')
        );
      }

      addToast(t('users.memberships.granted', 'Role granted'), 'success');
      setSelectedRole('');
      setReloadToken((token) => token + 1);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('users.memberships.error.grant', 'Failed to grant the role');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleRevoke = async (membership: Membership): Promise<void> => {
    setIsSubmitting(true);
    try {
      const { error, response } = await api.DELETE(
        '/api/v1/users/{id}/memberships/{membershipId}',
        { params: { path: { id: user.id, membershipId: membership.id } } }
      );

      if (error !== undefined || !response.ok) {
        throw new Error(
          error?.error ?? t('users.memberships.error.revoke', 'Failed to revoke the role')
        );
      }

      addToast(t('users.memberships.revoked', 'Role revoked'), 'success');
      setReloadToken((token) => token + 1);
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('users.memberships.error.revoke', 'Failed to revoke the role');
      addToast(message, 'error');
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Dialog open={isOpen} onOpenChange={onOpenChange}>
      <DialogContent>
        <DialogHeader>
          <DialogTitle>{t('users.memberships.title', 'Tenants and roles')}</DialogTitle>
          <DialogDescription>
            {isSystemAdmin
              ? t(
                  'users.memberships.description.system',
                  'Every tenant this person belongs to, and the role they hold in each.'
                )
              : t(
                  'users.memberships.description.tenant',
                  'The roles this person holds in your tenant.'
                )}
          </DialogDescription>
        </DialogHeader>

        <div className="space-y-4 py-2">
          <div className="rounded-lg bg-muted p-3">
            <div className="text-sm font-medium text-foreground">{user.name}</div>
            <div className="text-xs text-muted-foreground">{user.email}</div>
          </div>

          {isLoading ? (
            <p className="text-sm text-muted-foreground">
              {t('users.memberships.loading', 'Loading memberships…')}
            </p>
          ) : memberships.length === 0 ? (
            <p className="text-sm text-muted-foreground">
              {t('users.memberships.empty', 'This person belongs to no tenant.')}
            </p>
          ) : (
            <ul className="divide-y rounded-lg border">
              {memberships.map((membership) => (
                <li
                  key={membership.id}
                  className="flex items-center justify-between gap-3 p-3"
                >
                  <div className="min-w-0">
                    <div className="truncate text-sm font-medium text-foreground">
                      {membership.tenantName}
                    </div>
                    <div className="flex items-center gap-2 text-xs text-muted-foreground">
                      <span className="truncate">{membership.role}</span>
                      {membership.isPrimary && (
                        <Badge variant="outline">
                          {t('users.memberships.primary', 'Primary')}
                        </Badge>
                      )}
                    </div>
                  </div>
                  {/*
                    Only additional roles are revocable here: the primary row is
                    the person's presence in that tenant, and the server answers
                    409 rather than leave them there with no primary.
                  */}
                  {canManage && !membership.isPrimary && (
                    <Button
                      type="button"
                      variant="outline"
                      size="sm"
                      disabled={isSubmitting}
                      onClick={() => void handleRevoke(membership)}
                    >
                      {t('users.memberships.revoke', 'Revoke')}
                    </Button>
                  )}
                </li>
              ))}
            </ul>
          )}

          {canManage && (
            <div className="space-y-3 rounded-lg border p-3">
              <div className="text-sm font-medium text-foreground">
                {isSystemAdmin
                  ? t('users.memberships.grant.title.system', 'Add to a tenant')
                  : t('users.memberships.grant.title.tenant', 'Grant an additional role')}
              </div>

              {isSystemAdmin && (
                <div className="space-y-2">
                  <label
                    htmlFor="membership-tenant"
                    className="text-sm font-medium leading-none"
                  >
                    {t('users.memberships.grant.tenant.label', 'Tenant')}
                  </label>
                  <Select value={selectedTenantId} onValueChange={setSelectedTenantId}>
                    <SelectTrigger id="membership-tenant">
                      <SelectValue
                        placeholder={t(
                          'users.memberships.grant.tenant.placeholder',
                          'Select a tenant'
                        )}
                      />
                    </SelectTrigger>
                    <SelectContent>
                      {tenantOptions.map((tenant) => (
                        <SelectItem key={tenant.id} value={String(tenant.id)}>
                          {tenant.name}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <p className="text-xs text-muted-foreground">
                    {t(
                      'users.memberships.grant.tenant.hint',
                      'The role must exist in the chosen tenant, or be a global role.'
                    )}
                  </p>
                </div>
              )}

              <div className="space-y-2">
                <label
                  htmlFor="membership-role"
                  className="text-sm font-medium leading-none"
                >
                  {t('users.memberships.grant.role.label', 'Role')}
                </label>
                <Select value={selectedRole} onValueChange={setSelectedRole}>
                  <SelectTrigger id="membership-role">
                    <SelectValue
                      placeholder={
                        isLoadingRoles
                          ? t('users.memberships.grant.role.loading', 'Loading roles…')
                          : t('users.memberships.grant.role.placeholder', 'Select a role')
                      }
                    />
                  </SelectTrigger>
                  <SelectContent>
                    {roleOptions.map((role) => (
                      <SelectItem key={role.value} value={role.value}>
                        {role.label}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>

              <Button
                type="button"
                disabled={isSubmitting || selectedRole === ''}
                onClick={() => void handleGrant()}
              >
                {isSubmitting
                  ? t('users.memberships.grant.submitting', 'Saving…')
                  : t('users.memberships.grant.submit', 'Grant')}
              </Button>
            </div>
          )}
        </div>

        <DialogFooter>
          <Button type="button" variant="outline" onClick={() => onOpenChange(false)}>
            {t('users.memberships.close', 'Close')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
