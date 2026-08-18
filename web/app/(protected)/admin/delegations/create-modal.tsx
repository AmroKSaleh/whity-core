'use client';

import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import { fetchAllPagesTyped } from '@/lib/api/fetch-all-pages';
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
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { useRichTranslation, useTranslation } from '@amroksaleh/features/i18n';
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
  const t = useTranslation('admin');
  const rt = useRichTranslation('admin');

  // Starts true: the dialog remounts on open (parent `key`) and immediately
  // loads its picker options, so the loading state is shown from first paint
  // without a synchronous setState in the load effect.
  const [isLoadingOptions, setIsLoadingOptions] = useState(true);
  const [isSubmitting, setIsSubmitting] = useState(false);

  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [roles, setRoles] = useState<RoleOption[]>([]);
  const [users, setUsers] = useState<UserOption[]>([]);
  const [ous, setOus] = useState<OuOption[]>([]);
  // Set when the OU list could not be walked to the end. Non-null disables the
  // scope picker — see the OU field below for why the dialog stays usable.
  const [ousFailure, setOusFailure] = useState<{
    loaded: number;
    total: number | null;
  } | null>(null);

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
        // The OU scope narrows a delegation, so a unit that is merely on page 2
        // reads as "that OU does not exist" and pushes the grantor towards the
        // only remaining option — tenant-wide, a strictly broader grant.
        fetchAllPagesTyped<OuOption>((query) =>
          api.GET('/api/v1/ous', { params: { query } })
        ),
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
      if (ousRes.complete) {
        setOus(ousRes.items);
      } else {
        setOus([]);
        setOusFailure({ loaded: ousRes.items.length, total: ousRes.total });
      }
    } catch {
      // A rejection here loses the OU list too, and an empty scope dropdown is
      // the same lie as a short one — mark it failed rather than let the toast
      // scroll away and leave a picker that looks merely empty.
      setOus([]);
      setOusFailure({ loaded: 0, total: null });
      addToast(
        t('delegations.create.optionsError', 'Failed to load delegation options'),
        'error'
      );
    } finally {
      setIsLoadingOptions(false);
    }
  }, [addToast, t]);

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
      addToast(t('delegations.create.validation.grantee', 'Select a grantee'), 'error');
      return;
    }
    if (selectedPermissions.length === 0) {
      addToast(
        t(
          'delegations.create.validation.permissions',
          'Select at least one permission to delegate'
        ),
        'error'
      );
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
        throw new Error(
          error?.error ?? t('delegations.create.error', 'Failed to create delegation')
        );
      }

      addToast(t('delegations.create.success', 'Delegation created successfully'), 'success');
      onSuccess();
    } catch (error) {
      const message =
        error instanceof Error
          ? error.message
          : t('delegations.create.error', 'Failed to create delegation');
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
          <DialogTitle>{t('delegations.create.title', 'Create Delegation')}</DialogTitle>
          <DialogDescription>
            {t(
              'delegations.create.description',
              'Grant a subset of your own permissions to a role or a user. You can ' +
                'only delegate permissions you currently hold.'
            )}
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
              <label className="text-sm font-medium">
                {t('delegations.create.granteeType.label', 'Delegate to')}
              </label>
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
                  <SelectItem value="role">
                    {t('delegations.create.granteeType.role', 'Role')}
                  </SelectItem>
                  <SelectItem value="user">
                    {t('delegations.create.granteeType.user', 'User')}
                  </SelectItem>
                </SelectContent>
              </Select>
            </div>

            {/* Grantee */}
            <div className="space-y-2">
              <label className="text-sm font-medium">
                {granteeType === 'role'
                  ? t('delegations.create.granteeType.role', 'Role')
                  : t('delegations.create.granteeType.user', 'User')}
              </label>
              <Select value={granteeId} onValueChange={setGranteeId}>
                <SelectTrigger>
                  {/*
                    Two keys rather than one with the grantee type interpolated
                    in: "Select a role" and "Select a user" are separate
                    sentences to a translator (gender, article, case all differ),
                    and the raw enum value is not a word in any language.
                  */}
                  <SelectValue
                    placeholder={
                      granteeType === 'role'
                        ? t('delegations.create.grantee.placeholderRole', 'Select a role')
                        : t('delegations.create.grantee.placeholderUser', 'Select a user')
                    }
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
              {/*
                One key for the whole label, with "(optional)" as a hole rather
                than a seam: the qualifier moves where a translator's grammar
                needs it instead of being frozen after the noun phrase.
              */}
              <label className="text-sm font-medium">
                {rt(
                  'delegations.create.ou.label',
                  'Scope to organizational unit <0>(optional)</0>',
                  undefined,
                  [<span key="optional" className="text-muted-foreground" />]
                )}
              </label>
              <Select
                disabled={ousFailure !== null}
                value={ouId === '' ? 'none' : ouId}
                onValueChange={(value) => setOuId(value === 'none' ? '' : value)}
              >
                <SelectTrigger>
                  <SelectValue
                    placeholder={t('delegations.create.ou.tenantWide', 'Tenant-wide')}
                  />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="none">
                    {t('delegations.create.ou.tenantWide', 'Tenant-wide')}
                  </SelectItem>
                  {ous.map((ou) => (
                    <SelectItem key={ou.id} value={String(ou.id)}>
                      {ou.name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              {/*
                Scoping is optional, so the dialog stays usable — a tenant-wide
                delegation is still a complete, deliberate choice, and blocking
                it would remove a working capability over an unrelated fetch.
                But the fallback is BROADER than what the grantor was reaching
                for, so the reason has to be stated rather than left as an
                empty-looking dropdown they shrug past.
              */}
              {ousFailure !== null ? (
                <Alert variant="destructive">
                  <AlertDescription>
                    {ousFailure.total === null
                      ? t('ous.error.load', 'Failed to fetch organizational units')
                      : t(
                          'ous.error.partial',
                          'Loaded only {loaded} of {total} organizational units.',
                          { loaded: ousFailure.loaded, total: ousFailure.total }
                        )}{' '}
                    {t(
                      'delegations.create.ou.loadFailed',
                      'Scoping is unavailable: a partial list would hide units that exist, and delegating tenant-wide instead grants more than an OU scope would.'
                    )}
                  </AlertDescription>
                </Alert>
              ) : (
                <Alert variant="info">
                  <AlertDescription>
                    {t(
                      'delegations.create.ou.hint',
                      'When set, the delegation applies only to grantees within that OU ' +
                        'or its descendants.'
                    )}
                  </AlertDescription>
                </Alert>
              )}
            </div>

            {/* Permissions */}
            <div className="space-y-2">
              <label className="text-sm font-medium">
                {t('delegations.create.permissions.label', 'Permissions')}
              </label>
              <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border border-border p-2">
                {permissions.length === 0 ? (
                  <p className="px-2 py-4 text-center text-sm text-muted-foreground">
                    {t('delegations.create.permissions.empty', 'No permissions available.')}
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
            {t('delegations.create.cancel', 'Cancel')}
          </Button>
          <Button
            type="button"
            onClick={handleSubmit}
            disabled={isSubmitting || isLoadingOptions}
          >
            {isSubmitting
              ? t('delegations.create.submitting', 'Creating...')
              : t('delegations.create.submit', 'Create Delegation')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}
