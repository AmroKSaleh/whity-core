'use client';

import { useCallback, useEffect, useState } from 'react';
import { api } from '@/lib/api/client';
import {
  fetchAllPagesTyped,
  type FetchAllPagesResult,
} from '@/lib/api/fetch-all-pages';
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
 * Why one of the dialog's lists must not be offered.
 *
 * `loaded`/`total` are carried rather than a finished sentence because the
 * alerts render them through `t()`. `total` is null when even the first page
 * failed, so the size of the gap is unknown — and "we are missing an unknown
 * amount" is a different statement to the grantor than "we are missing 140 of
 * 240".
 */
interface ListFailure {
  loaded: number;
  total: number | null;
}

/** One entry per list this dialog walks; null means the walk reached the end. */
interface ListFailures {
  permissions: ListFailure | null;
  roles: ListFailure | null;
  users: ListFailure | null;
  ous: ListFailure | null;
}

const NO_LIST_FAILURES: ListFailures = {
  permissions: null,
  roles: null,
  users: null,
  ous: null,
};

/**
 * Every list lost, and by how much unknown — a rejected `loadOptions()`, where
 * not even a first page arrived to count against a total.
 */
const ALL_LISTS_FAILED: ListFailures = {
  permissions: { loaded: 0, total: null },
  roles: { loaded: 0, total: null },
  users: { loaded: 0, total: null },
  ous: { loaded: 0, total: null },
};

/**
 * A completed walk has no failure; an incomplete one carries how far it got, so
 * the alert can state the size of the gap rather than only that something went
 * wrong.
 */
function listFailure<T>(result: FetchAllPagesResult<T>): ListFailure | null {
  return result.complete
    ? null
    : { loaded: result.items.length, total: result.total };
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
  // All four lists above are paginated, and none of them is ever presented
  // short: an entry here means that walk did not reach the end, so the list is
  // withheld and the gap is stated where the picker was. What each field then
  // does about it differs — see the fields below.
  const [failures, setFailures] = useState<ListFailures>(NO_LIST_FAILURES);

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
        // The catalogue used to be fetched with per_page=100, which is not a fix
        // but a moved cliff: the server caps per_page at 100, so the list simply
        // started dropping entries at 101 instead of 26 — and core's registry
        // plus every installed plugin's contributions grow past that with
        // installed plugins rather than with tenant size.
        fetchAllPagesTyped<Permission>((query) =>
          api.GET('/api/v1/permissions', { params: { query } })
        ),
        // The grantee IS the subject of the grant. A role or a user that is
        // merely on page 2 is indistinguishable from one that does not exist,
        // and the grantor's next move is to pick the nearest thing that IS
        // listed — writing real authority against the wrong subject.
        fetchAllPagesTyped<RoleOption>((query) =>
          api.GET('/api/v1/roles', { params: { query } })
        ),
        fetchAllPagesTyped<UserOption>((query) =>
          api.GET('/api/v1/users', { params: { query } })
        ),
        // The OU scope narrows a delegation, so a unit that is merely on page 2
        // reads as "that OU does not exist" and pushes the grantor towards the
        // only remaining option — tenant-wide, a strictly broader grant.
        fetchAllPagesTyped<OuOption>((query) =>
          api.GET('/api/v1/ous', { params: { query } })
        ),
      ]);

      // An incomplete list is dropped, not shown short. The rows that did
      // arrive are real, but a list of them is not: it looks exactly like a
      // complete list of a smaller tenant, which is the whole defect.
      setPermissions(permsRes.complete ? permsRes.items : []);
      setRoles(rolesRes.complete ? rolesRes.items : []);
      setUsers(usersRes.complete ? usersRes.items : []);
      setOus(ousRes.complete ? ousRes.items : []);
      setFailures({
        permissions: listFailure(permsRes),
        roles: listFailure(rolesRes),
        users: listFailure(usersRes),
        ous: listFailure(ousRes),
      });
    } catch {
      // A rejection here loses every list, and an empty picker is the same lie
      // as a short one — mark them all failed rather than let the toast scroll
      // away and leave pickers that look merely empty.
      setPermissions([]);
      setRoles([]);
      setUsers([]);
      setOus([]);
      setFailures(ALL_LISTS_FAILED);
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

  // Only the SELECTED type's list gates the grantee picker: if roles walked to
  // the end and users did not, refusing both would remove a capability that
  // loaded fine. The type toggle itself stays live — switching it is a
  // deliberate change of subject KIND, not the near-miss substitution being
  // guarded against, since nobody mistakes an email address for a role name.
  const granteeFailure =
    granteeType === 'role' ? failures.roles : failures.users;

  const granteeOptions =
    granteeType === 'role'
      ? roles.map((r) => ({ value: String(r.id), label: r.name }))
      : users.map((u) => ({ value: String(u.id), label: u.email }));

  /**
   * How much of the grantee list is missing, as a sentence.
   *
   * Written out per list and per known/unknown total rather than interpolating
   * the noun into one string: "roles" and "users" decline differently to a
   * translator, and every key has to stay a literal for the extractor to see
   * the English at all.
   */
  const granteeGap = (failure: ListFailure): string => {
    if (granteeType === 'role') {
      return failure.total === null
        ? t('delegations.create.grantee.rolesError', 'Failed to fetch roles')
        : t(
            'delegations.create.grantee.rolesPartial',
            'Loaded only {loaded} of {total} roles.',
            { loaded: failure.loaded, total: failure.total }
          );
    }

    return failure.total === null
      ? t('delegations.create.grantee.usersError', 'Failed to fetch users')
      : t(
          'delegations.create.grantee.usersPartial',
          'Loaded only {loaded} of {total} users.',
          { loaded: failure.loaded, total: failure.total }
        );
  };

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
              <Select
                disabled={granteeFailure !== null}
                value={granteeId}
                onValueChange={setGranteeId}
              >
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
              {/*
                Unlike the OU scope below, the grantee has no safe fallback: it
                is the subject of the grant and the form cannot be submitted
                without one. So the picker refuses rather than offering a short
                list — an admin who cannot find the role they came for concludes
                it does not exist and grants to whatever looks closest, which is
                a real, durable grant of real permissions to the wrong subject.
              */}
              {granteeFailure !== null ? (
                <Alert variant="destructive">
                  <AlertDescription>
                    {granteeGap(granteeFailure)}{' '}
                    {t(
                      'delegations.create.grantee.loadFailed',
                      'The list is withheld: a partial one hides grantees that exist, and delegating to the nearest listed alternative hands your permissions to the wrong role or user.'
                    )}
                  </AlertDescription>
                </Alert>
              ) : null}
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
                disabled={failures.ous !== null}
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
              {failures.ous !== null ? (
                <Alert variant="destructive">
                  <AlertDescription>
                    {failures.ous.total === null
                      ? t('ous.error.load', 'Failed to fetch organizational units')
                      : t(
                          'ous.error.partial',
                          'Loaded only {loaded} of {total} organizational units.',
                          { loaded: failures.ous.loaded, total: failures.ous.total }
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
              {/*
                A partial catalogue blocks the whole dialog, and that is a
                deliberate call rather than caution.
                  - The permission set IS the grant, not a qualifier on it. The
                    OU scope could fall back to tenant-wide, which is a complete
                    and deliberate choice; there is no equivalent here, so every
                    tick is made in the belief that the list is the catalogue.
                  - Under-granting is not the only failure. A missing
                    `documents:publish` invites the nearest visible neighbour —
                    `documents:write` — and that is real authority the grantor
                    never meant to hand over.
                  - Submitting already requires at least one permission, so
                    there is no read-only use of this dialog worth preserving:
                    everything it does is write a grant, and it cannot write a
                    correct one from a list it cannot vouch for.
                Reopening the dialog remounts it and retries the walk.
              */}
              {failures.permissions !== null ? (
                <Alert variant="destructive">
                  <AlertDescription>
                    {failures.permissions.total === null
                      ? t(
                          'delegations.create.permissions.error',
                          'Failed to fetch the permission catalogue'
                        )
                      : t(
                          'delegations.create.permissions.partial',
                          'Loaded only {loaded} of {total} permissions.',
                          {
                            loaded: failures.permissions.loaded,
                            total: failures.permissions.total,
                          }
                        )}{' '}
                    {t(
                      'delegations.create.permissions.loadFailed',
                      'No delegation can be created from a partial catalogue: it hides permissions you hold, and the nearest one that is listed grants something you did not intend.'
                    )}
                  </AlertDescription>
                </Alert>
              ) : (
                <div className="max-h-56 space-y-1 overflow-y-auto rounded-md border border-border p-2">
                  {permissions.length === 0 ? (
                    // A genuinely empty catalogue, which the walk COMPLETED —
                    // distinct from the failure above, and the distinction is
                    // the point.
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
              )}
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
          {/*
            Blocked, not merely validated: without the permission catalogue or
            the selected type's grantee list there is nothing correct this
            button can write, and letting it look clickable invites a second
            attempt at the same impossible submit.
          */}
          <Button
            type="button"
            onClick={handleSubmit}
            disabled={
              isSubmitting ||
              isLoadingOptions ||
              failures.permissions !== null ||
              granteeFailure !== null
            }
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
