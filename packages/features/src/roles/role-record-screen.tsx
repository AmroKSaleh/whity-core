'use client';

/**
 * Injected-translator keys this file renders through `t`. Declared here for
 * the i18n catalogue extractor: it cannot infer a domain from a prop-injected
 * translator (see RolesTranslate — deliberately NOT typed `TranslateFn`, so
 * these files stay unscanned like DemoCatalog does via NavTranslate), so the
 * keys are enumerated below instead. Feature copy resolves in the `admin`
 * domain, shared UI chrome in `common`.
 *
 * @i18n-keys admin
 *   roles.record.activity.actor = by user {id}
 *   roles.record.activity.empty = Nothing has been recorded for this role yet.
 *   roles.record.activity.error = Failed to load this role's history
 *   roles.record.activity.subtitle = Changes recorded against this role.
 *   roles.record.activity.title = History
 *   roles.record.activity.unknownActor = by the system
 *   roles.record.back = Back to roles
 *   roles.record.cancel = Discard changes
 *   roles.record.details.subtitle = The role's name and description, as they appear everywhere it is used.
 *   roles.record.details.title = Details
 *   roles.record.description.label = Description
 *   roles.record.description.placeholder = Role description
 *   roles.record.error.load = Failed to load this role
 *   roles.record.error.save = Failed to save this role
 *   roles.record.error.title = This role could not be loaded
 *   roles.record.globalWarning = This is a global base role: one role shared by every tenant on this deployment. Saving changes it for all of them, including their existing users.
 *   roles.record.holders.assignedOn = assigned {date}
 *   roles.record.holders.assignedUnknown = assignment date unknown
 *   roles.record.holders.empty = Nobody holds this role yet.
 *   roles.record.holders.error = Failed to load who holds this role
 *   roles.record.holders.more = and {count} more
 *   roles.record.holders.subtitle = Most recently assigned first.
 *   roles.record.holders.title = Who holds this role
 *   roles.record.loading = Loading role…
 *   roles.record.name.label = Role name
 *   roles.record.name.placeholder = e.g., Editor
 *   roles.record.notManageable = This role can't be modified by your tenant — global base roles are managed by the system tenant.
 *   roles.record.permissions.subtitle = Grouped by the resource each permission acts on.
 *   roles.record.permissions.title = Permissions
 *   roles.record.readOnly.noPermission = You don't have permission to edit roles, so this record is read-only.
 *   roles.record.readOnly.systemRole = This is a global base role. Only the system tenant can change it.
 *   roles.record.save = Save changes
 *   roles.record.saving = Saving…
 *   roles.record.stat.created = Created
 *   roles.record.stat.holders = Users with this role
 *   roles.record.stat.permissions = Permissions granted
 *   roles.record.stat.scope = Scope
 *   roles.record.stat.scope.global = Global base role
 *   roles.record.stat.scope.tenant = Your tenant's role
 *   roles.record.stat.unknown = —
 *   roles.record.subtitle = Role record
 *   roles.record.success = Role updated successfully
 *   roles.record.validation.descriptionRequired = Description is required
 *   roles.record.validation.nameRequired = Name is required
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { ErrorState } from '@amroksaleh/ui/empty-state';
import { Input } from '@amroksaleh/ui/input';
import { PageHeader } from '@amroksaleh/ui/page-header';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { IconAlertTriangle, IconArrowLeft, IconShieldLock } from '@tabler/icons-react';

import { identityTranslate } from '../nav/types';
import { ROLES_WRITE } from './capabilities';
import { PermissionsGrid } from './permissions-grid';
import type {
  Permission,
  RoleActivityEntry,
  RoleAssignment,
  RoleRecordScreenProps,
  RoleWithPermissions,
  RolesTranslate,
} from './types';

/** How many holders and history entries the record page asks for. */
const HOLDERS_PAGE_SIZE = 8;
const ACTIVITY_PAGE_SIZE = 8;

/**
 * Format a server timestamp for display, falling back to the raw value when it
 * is not parseable — the same shape of guard the other admin screens use, so a
 * malformed date renders as itself rather than "Invalid Date".
 */
function formatDate(value: string | null | undefined): string | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleDateString();
}

function formatDateTime(value: string | null | undefined): string | null {
  if (!value) return null;
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? value : parsed.toLocaleString();
}

/** One number and its label. Not a `Card` — a stat is a fact, not a panel. */
function Stat({ label, value }: { label: string; value: ReactNode }) {
  return (
    <div className="rounded-lg border border-border bg-card px-4 py-3">
      <div className="text-xs text-muted-foreground">{label}</div>
      <div className="mt-1 text-lg font-semibold text-foreground">{value}</div>
    </div>
  );
}

/**
 * The role RECORD PAGE (#882) — the first record page in the app, and a
 * deliberate prototype: a hand-built page whose LAYOUT is what the block
 * vocabulary should later be able to describe (see #883 for the three contract
 * gaps that stop it describing this one today).
 *
 * WHAT A PAGE CARRIES THAT A MODAL CANNOT. The acute complaint was the edit
 * dialog — `max-w-3xl max-h-[90vh] overflow-y-auto` wrapping a permissions
 * picker that opens a `max-h-80` popover, so 53+ permissions live in a ~320px
 * window nested in another scroll region. But a bigger dialog would only have
 * fixed the form. The rest of what is here is the actual argument for a page:
 *
 *  - **the record's own context** — how many people hold this role, who most
 *    recently, when it was created, whether it is a global base role at all;
 *  - **an addressable URL**, so "send me the link to that role" has an answer;
 *  - **room**, so permissions are laid out grouped and all-visible rather than
 *    scrolled through a porthole.
 *
 * Presentational and data-source-agnostic like its siblings: no fetching, no
 * router, no capability source of its own. Data goes through `adapter`,
 * capability checks through `can`, copy through `t`, navigation through
 * `onBack`, so the same component mounts under Next, a Tauri shell, or the Vite
 * harness.
 *
 * READ-ONLY IS A FIRST-CLASS STATE, not a disabled form. Two independent gates
 * decide it — the caller's `roles:write` capability and the role's own
 * server-computed `manageable` flag (WC-110/WC-222: a global NULL-tenant base
 * role is visible to a tenant but writable only by the system tenant). When
 * either says no, the page renders the same information with no inputs and says
 * WHY, rather than offering a form whose save would 404.
 *
 * RTL: every inset here is logical (`ps-`/`pe-`/`ms-`/`me-`/`text-start`/
 * `inset-s-`), so the page mirrors with the app's `<html dir>` and no branch of
 * this file reads the direction.
 */
export function RoleRecordScreen({
  adapter,
  roleId,
  can,
  t: injectedT,
  onNotify,
  onBack,
  className,
}: RoleRecordScreenProps) {
  const t: RolesTranslate = injectedT ?? identityTranslate;

  const [role, setRole] = useState<RoleWithPermissions | null>(null);
  const [catalogue, setCatalogue] = useState<Permission[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [loadError, setLoadError] = useState<string | null>(null);

  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [permissionIds, setPermissionIds] = useState<number[]>([]);
  const [isSaving, setIsSaving] = useState(false);
  const [fieldError, setFieldError] = useState<string | null>(null);

  // The two SUPPLEMENTARY panels own their own loading and failure state. A
  // record page that blanks because its history endpoint was slow or forbidden
  // is worse than one that shows the record and says the history is missing.
  const [holders, setHolders] = useState<RoleAssignment[] | null>(null);
  const [holderCount, setHolderCount] = useState<number | null>(null);
  const [holdersError, setHoldersError] = useState<string | null>(null);
  const [activity, setActivity] = useState<RoleActivityEntry[] | null>(null);
  const [activityForbidden, setActivityForbidden] = useState(false);
  const [activityError, setActivityError] = useState<string | null>(null);

  /** Reset the form to the loaded record — used on load and on discard. */
  const resetForm = useCallback((loaded: RoleWithPermissions) => {
    setName(loaded.name ?? '');
    setDescription(loaded.description ?? '');
    setPermissionIds((loaded.permissions ?? []).map((p) => p.id));
    setFieldError(null);
  }, []);

  // The RECORD itself. Its failure is the page's failure.
  useEffect(() => {
    let cancelled = false;
    setIsLoading(true);
    setLoadError(null);
    Promise.all([adapter.getRole(roleId), adapter.listPermissions()])
      .then(([loaded, all]) => {
        if (cancelled) return;
        setRole(loaded);
        setCatalogue(all);
        resetForm(loaded);
      })
      .catch((error: unknown) => {
        if (cancelled) return;
        setLoadError(
          error instanceof Error && error.message
            ? error.message
            : t('roles.record.error.load', 'Failed to load this role')
        );
      })
      .finally(() => {
        if (!cancelled) setIsLoading(false);
      });
    return () => {
      cancelled = true;
    };
    // Re-run only for a different role. t/onNotify/adapter identity changes must
    // not refetch (identityTranslate is a stable module-level reference).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roleId]);

  // Who holds it — the headcount comes back as the pagination total, so this one
  // request answers both "how many" and "who most recently".
  useEffect(() => {
    let cancelled = false;
    setHoldersError(null);
    adapter
      .getRoleAssignments(roleId, HOLDERS_PAGE_SIZE)
      .then((page) => {
        if (cancelled) return;
        setHolders(page.assignments);
        setHolderCount(page.total);
      })
      .catch(() => {
        if (!cancelled) {
          setHoldersError(t('roles.record.holders.error', 'Failed to load who holds this role'));
        }
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roleId]);

  // The role's own audit trail. `audit:read` is a SEPARATE permission a role
  // administrator need not hold, so a refusal hides the panel instead of
  // shouting — that is an absent capability, not an error.
  useEffect(() => {
    let cancelled = false;
    setActivityError(null);
    setActivityForbidden(false);
    adapter
      .getRoleActivity(roleId, ACTIVITY_PAGE_SIZE)
      .then((result) => {
        if (cancelled) return;
        if (result === 'forbidden') {
          setActivityForbidden(true);
          return;
        }
        setActivity(result);
      })
      .catch(() => {
        if (!cancelled) {
          setActivityError(t('roles.record.activity.error', "Failed to load this role's history"));
        }
      });
    return () => {
      cancelled = true;
    };
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [roleId]);

  const hasWrite = can(ROLES_WRITE);
  const isManageable = role?.manageable === true;
  const isEditable = hasWrite && isManageable;
  // #886: read from the server's own `global` flag rather than inferred from
  // `!isManageable`. The inference is right for a tenant and INVERTED for the
  // system tenant — for whom every role is manageable — so the page told the
  // one operator who can change a role for the whole deployment that it was
  // their tenant's own.
  const isGlobal = role?.global === true;

  const isDirty = useMemo(() => {
    if (!role) return false;
    const original = (role.permissions ?? []).map((p) => p.id);
    const sameSet =
      original.length === permissionIds.length &&
      new Set(permissionIds).size === new Set([...permissionIds, ...original]).size;
    return name !== (role.name ?? '') || description !== (role.description ?? '') || !sameSet;
  }, [role, name, description, permissionIds]);

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!role || !isEditable) return;

    if (name.trim() === '') {
      setFieldError(t('roles.record.validation.nameRequired', 'Name is required'));
      return;
    }
    if (description.trim() === '') {
      setFieldError(t('roles.record.validation.descriptionRequired', 'Description is required'));
      return;
    }
    setFieldError(null);

    try {
      setIsSaving(true);
      const result = await adapter.updateRole(role.id, {
        name,
        description,
        permissions: permissionIds,
      });
      // SAFETY NET (WC-222): the page is already gated on `manageable`, but a
      // role can become global-only between load and save. Surface the friendly
      // explanation rather than a raw failure.
      if (result === 'not-manageable') {
        onNotify?.(
          t(
            'roles.record.notManageable',
            "This role can't be modified by your tenant — global base roles are managed by the system tenant."
          ),
          'error'
        );
        return;
      }
      onNotify?.(t('roles.record.success', 'Role updated successfully'), 'success');
      // Re-seat the record on the saved values so the page stops reading dirty
      // without a refetch the user did not ask for.
      const saved: RoleWithPermissions = {
        ...role,
        name,
        description,
        permissions: catalogue.filter((p) => permissionIds.includes(p.id)),
      };
      setRole(saved);
      resetForm(saved);
    } catch (error) {
      onNotify?.(
        error instanceof Error && error.message
          ? error.message
          : t('roles.record.error.save', 'Failed to save this role'),
        'error'
      );
    } finally {
      setIsSaving(false);
    }
  };

  const backButton = (
    <Button type="button" variant="ghost" size="sm" onClick={onBack} className="gap-2">
      {/* The arrow is mirrored by direction — it points AWAY from the content in
          LTR and RTL alike, which a fixed left-arrow would not. */}
      <IconArrowLeft size={16} className="rtl:rotate-180" />
      {t('roles.record.back', 'Back to roles')}
    </Button>
  );

  if (isLoading) {
    return (
      <div className={className ?? 'space-y-6'} data-testid="role-record-loading">
        {backButton}
        <Skeleton className="h-10 w-64" />
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
          <Skeleton className="h-20" />
          <Skeleton className="h-20" />
          <Skeleton className="h-20" />
          <Skeleton className="h-20" />
        </div>
        <Skeleton className="h-64" />
        <span className="sr-only">{t('roles.record.loading', 'Loading role…')}</span>
      </div>
    );
  }

  if (loadError !== null || role === null) {
    return (
      // No breadcrumb back-button here: the error state's own action is the way
      // out, and two identical "Back to roles" controls on one screen is one
      // control too many for anyone navigating by keyboard or screen reader.
      <div className={className ?? 'space-y-6'} data-testid="role-record-error">
        <ErrorState
          title={t('roles.record.error.title', 'This role could not be loaded')}
          description={loadError ?? t('roles.record.error.load', 'Failed to load this role')}
          action={<Button onClick={onBack}>{t('roles.record.back', 'Back to roles')}</Button>}
        />
      </div>
    );
  }

  const createdAt = formatDate(role.createdAt);
  const readOnlyReason = !hasWrite
    ? t(
        'roles.record.readOnly.noPermission',
        "You don't have permission to edit roles, so this record is read-only."
      )
    : !isManageable
      ? t(
          'roles.record.readOnly.systemRole',
          'This is a global base role. Only the system tenant can change it.'
        )
      : null;

  return (
    <div className={className ?? 'space-y-6'} data-testid="role-record">
      <PageHeader
        variant="card"
        breadcrumb={backButton}
        icon={<IconShieldLock />}
        title={role.name}
        description={role.description || t('roles.record.subtitle', 'Role record')}
        badge={
          isGlobal ? (
            <Badge variant="warning" data-testid="role-record-global-badge">
              {t('roles.record.stat.scope.global', 'Global base role')}
            </Badge>
          ) : undefined
        }
        action={
          isEditable ? (
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="outline"
                disabled={!isDirty || isSaving}
                onClick={() => resetForm(role)}
              >
                {t('roles.record.cancel', 'Discard changes')}
              </Button>
              <Button type="submit" form="role-record-form" disabled={isSaving || !isDirty}>
                {isSaving ? t('roles.record.saving', 'Saving…') : t('roles.record.save', 'Save changes')}
              </Button>
            </div>
          ) : undefined
        }
      />

      {readOnlyReason !== null && (
        <p
          className="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
          data-testid="role-record-readonly-notice"
        >
          {readOnlyReason}
        </p>
      )}

      {/*
        #886 — the blast radius, stated before the edit rather than after it.
        Shown only when the record is BOTH global and actually editable here,
        which is only ever a system-tenant operator: for anyone else the
        read-only notice above already explains why the form is absent, and two
        notices saying overlapping things is one notice too many.
      */}
      {isGlobal && isEditable && (
        <Alert variant="warning" data-testid="role-record-global-warning">
          <IconAlertTriangle className="h-4 w-4" />
          <AlertDescription>
            {t(
              'roles.record.globalWarning',
              'This is a global base role: one role shared by every tenant on this deployment. Saving changes it for all of them, including their existing users.'
            )}
          </AlertDescription>
        </Alert>
      )}

      {/* The record's context, above everything editable: what this role IS on
          this installation, before what it may be changed to. */}
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <Stat
          label={t('roles.record.stat.holders', 'Users with this role')}
          value={
            holderCount === null ? (
              <span data-testid="role-record-holder-count-pending">
                {t('roles.record.stat.unknown', '—')}
              </span>
            ) : (
              <span data-testid="role-record-holder-count">{holderCount}</span>
            )
          }
        />
        <Stat
          label={t('roles.record.stat.permissions', 'Permissions granted')}
          value={<span data-testid="role-record-permission-count">{permissionIds.length}</span>}
        />
        <Stat
          label={t('roles.record.stat.created', 'Created')}
          value={createdAt ?? t('roles.record.stat.unknown', '—')}
        />
        <Stat
          label={t('roles.record.stat.scope', 'Scope')}
          value={
            isGlobal
              ? t('roles.record.stat.scope.global', 'Global base role')
              : t('roles.record.stat.scope.tenant', "Your tenant's role")
          }
        />
      </div>

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          <form id="role-record-form" onSubmit={handleSubmit} className="space-y-6">
            <Card>
              <CardHeader>
                <CardTitle>{t('roles.record.details.title', 'Details')}</CardTitle>
                <CardDescription>
                  {t(
                    'roles.record.details.subtitle',
                    "The role's name and description, as they appear everywhere it is used."
                  )}
                </CardDescription>
              </CardHeader>
              {/* Read-only renders a description LIST, not labels pointing at
                  paragraphs: `htmlFor` only means anything against a labelable
                  control, and a `<label>` with nothing to label announces a form
                  field that is not there. */}
              <CardContent className="space-y-4">
                {isEditable ? (
                  <>
                    <div className="space-y-1.5">
                      <label
                        htmlFor="role-record-name"
                        className="block text-sm font-medium text-foreground"
                      >
                        {t('roles.record.name.label', 'Role name')}
                      </label>
                      <Input
                        id="role-record-name"
                        value={name}
                        onChange={(e) => setName(e.target.value)}
                        placeholder={t('roles.record.name.placeholder', 'e.g., Editor')}
                      />
                    </div>
                    <div className="space-y-1.5">
                      <label
                        htmlFor="role-record-description"
                        className="block text-sm font-medium text-foreground"
                      >
                        {t('roles.record.description.label', 'Description')}
                      </label>
                      <Input
                        id="role-record-description"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        placeholder={t('roles.record.description.placeholder', 'Role description')}
                      />
                    </div>
                  </>
                ) : (
                  <dl className="space-y-4">
                    <div className="space-y-1.5">
                      <dt className="text-sm font-medium text-foreground">
                        {t('roles.record.name.label', 'Role name')}
                      </dt>
                      <dd className="text-sm text-foreground">{role.name}</dd>
                    </div>
                    <div className="space-y-1.5">
                      <dt className="text-sm font-medium text-foreground">
                        {t('roles.record.description.label', 'Description')}
                      </dt>
                      <dd className="text-sm text-foreground">{role.description}</dd>
                    </div>
                  </dl>
                )}
                {fieldError !== null && (
                  <p className="text-sm text-destructive" role="alert">
                    {fieldError}
                  </p>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardHeader>
                <CardTitle>{t('roles.record.permissions.title', 'Permissions')}</CardTitle>
                <CardDescription>
                  {t(
                    'roles.record.permissions.subtitle',
                    'Grouped by the resource each permission acts on.'
                  )}
                </CardDescription>
              </CardHeader>
              <CardContent>
                {/* Read-only shows the role's OWN permissions; editable shows the
                    whole catalogue with the role's set ticked. Feeding the
                    catalogue to a read-only view would render every permission
                    on the installation as though the role had them. */}
                <PermissionsGrid
                  permissions={isEditable ? catalogue : (role.permissions ?? [])}
                  selectedIds={permissionIds}
                  onChange={setPermissionIds}
                  t={t}
                  readOnly={!isEditable}
                />
              </CardContent>
            </Card>
          </form>
        </div>

        <div className="space-y-6">
          <Card data-testid="role-record-holders">
            <CardHeader>
              <CardTitle>{t('roles.record.holders.title', 'Who holds this role')}</CardTitle>
              <CardDescription>
                {t('roles.record.holders.subtitle', 'Most recently assigned first.')}
              </CardDescription>
            </CardHeader>
            <CardContent>
              {holdersError !== null ? (
                <p className="text-sm text-destructive">{holdersError}</p>
              ) : holders === null ? (
                <div className="space-y-2">
                  <Skeleton className="h-8" />
                  <Skeleton className="h-8" />
                </div>
              ) : holders.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                  {t('roles.record.holders.empty', 'Nobody holds this role yet.')}
                </p>
              ) : (
                <>
                  <ul className="divide-y divide-border/60">
                    {holders.map((holder) => {
                      const when = formatDate(holder.assignedAt);
                      return (
                        <li key={holder.membershipId} className="py-2">
                          <span className="block truncate text-sm font-medium text-foreground">
                            {holder.displayName || holder.email || `#${holder.profileId}`}
                          </span>
                          <span className="block truncate text-xs text-muted-foreground">
                            {when !== null
                              ? t('roles.record.holders.assignedOn', 'assigned {date}', {
                                  date: when,
                                })
                              : t('roles.record.holders.assignedUnknown', 'assignment date unknown')}
                          </span>
                        </li>
                      );
                    })}
                  </ul>
                  {holderCount !== null && holderCount > holders.length && (
                    <p className="pt-2 text-xs text-muted-foreground">
                      {t('roles.record.holders.more', 'and {count} more', {
                        count: holderCount - holders.length,
                      })}
                    </p>
                  )}
                </>
              )}
            </CardContent>
          </Card>

          {/* Absent entirely when the caller lacks `audit:read` — a panel that
              exists only to say "you may not see this" is noise. */}
          {!activityForbidden && (
            <Card data-testid="role-record-activity">
              <CardHeader>
                <CardTitle>{t('roles.record.activity.title', 'History')}</CardTitle>
                <CardDescription>
                  {t('roles.record.activity.subtitle', 'Changes recorded against this role.')}
                </CardDescription>
              </CardHeader>
              <CardContent>
                {activityError !== null ? (
                  <p className="text-sm text-destructive">{activityError}</p>
                ) : activity === null ? (
                  <div className="space-y-2">
                    <Skeleton className="h-8" />
                    <Skeleton className="h-8" />
                  </div>
                ) : activity.length === 0 ? (
                  <p className="text-sm text-muted-foreground">
                    {t(
                      'roles.record.activity.empty',
                      'Nothing has been recorded for this role yet.'
                    )}
                  </p>
                ) : (
                  <ul className="space-y-3">
                    {activity.map((entry) => (
                      <li key={entry.id} className="border-s-2 border-border ps-3">
                        {/* The action key is a stable machine identifier
                            (`role.updated`), not a source string, so it renders
                            verbatim and never enters the catalogue — the same
                            rule permission slugs follow. */}
                        <span className="block font-mono text-xs text-foreground">
                          {entry.action}
                        </span>
                        <span className="block text-xs text-muted-foreground">
                          {formatDateTime(entry.createdAt) ??
                            t('roles.record.stat.unknown', '—')}
                          {' · '}
                          {entry.actorUserId !== null
                            ? t('roles.record.activity.actor', 'by user {id}', {
                                id: entry.actorUserId,
                              })
                            : t('roles.record.activity.unknownActor', 'by the system')}
                        </span>
                      </li>
                    ))}
                  </ul>
                )}
              </CardContent>
            </Card>
          )}
        </div>
      </div>
    </div>
  );
}
