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
 *   roles.record.details.readOnly.permission = You don't have permission to change roles, so these details are read-only.
 *   roles.record.permissions.readOnly.permission = You may see what this role grants, but not change it.
 *   roles.record.readOnly.systemRole = This is a global base role. Only the system tenant can change it.
 *   roles.record.save = Save changes
 *   roles.record.saving = Saving…
 *   roles.record.stat.created = Created
 *   roles.record.stat.holders = Users with this role
 *   roles.record.stat.permissions = Permissions granted
 *   roles.record.stat.scope = Scope
 *   roles.record.stat.scope.global = Global base role
 *   roles.record.stat.scope.tenant = Your tenant's role
 *   roles.record.subtitle = Role record
 *   roles.record.success = Role updated successfully
 *   roles.record.validation.descriptionRequired = Description is required
 *   roles.record.validation.nameRequired = Name is required
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent } from 'react';
import { Alert, AlertDescription } from '@amroksaleh/ui/alert';
import { Button } from '@amroksaleh/ui/button';
import { Input } from '@amroksaleh/ui/input';
import { IconAlertTriangle, IconShieldLock } from '@tabler/icons-react';

import {
  RecordCollectionPanel,
  RecordList,
  RecordListItem,
  RecordPageError,
  RecordPageShell,
  RecordPageSkeleton,
  RecordTimeline,
  RecordTimelineItem,
  formatRecordDate,
  formatRecordDateTime,
  sectionAccessFrom,
  useRecordResource,
} from '../record';
import type { RecordFactsFn, RecordResource, RecordSectionSpec } from '../record';
import { identityTranslate } from '../nav/types';
import { PermissionsGrid } from './permissions-grid';
import type {
  Permission,
  RoleAssignment,
  RoleRecordScreenProps,
  RoleWithPermissions,
  RolesTranslate,
} from './types';

/** How many holders and history entries the record page asks for. */
const HOLDERS_PAGE_SIZE = 8;
const ACTIVITY_PAGE_SIZE = 8;

/**
 * What the SERVER says this role is.
 *
 * Note what is absent: `manageable`. It is the server's answer to "may YOU write
 * this?", which is a decision about the CALLER, and the shell's `RecordFields`
 * constraint rejects it by name — a `RoleRecordFields` that carried it would not
 * compile. That is #895 turned from a code-review rule into a type error: the
 * page used to derive "is this global" from `manageable`, which is true of every
 * role for a tenant-0 caller, so the system tenant read "Your tenant's role" on
 * the one record whose edit reaches every tenant. `global` is its own
 * server-computed fact and lives here; `manageable` moved to `resolveAccess`.
 */
interface RoleRecordFields {
  name: string;
  description: string;
  createdAt: string | null;
  global: boolean;
  /**
   * The role's permission count AS SAVED, not as currently ticked. The stat
   * strip states the record; the draft's count is already visible in the grid
   * below it, and a stat that moves while you tick boxes is a form control
   * wearing a fact's clothes.
   */
  permissionCount: number;
  /**
   * The full headcount from `/roles/{id}/assignments`' pagination total, or null
   * while that request is in flight. Server-derived like everything else here —
   * it is simply not part of the role's own payload.
   */
  holderCount: number | null;
}

/**
 * This screen's copy for a denial CODE, or null to fall back to the server's.
 *
 * The server ships the code, an audience-safe English sentence, and — for a
 * caller it decided may read it — the exact permission the write would need.
 * This is the localization half of that seam and nothing more: it never decides
 * whether a region is refused, only how this screen says so in this caller's
 * language. Returning null hands the sentence back to the server's own, which is
 * what makes a code this build has never heard of render as a vague explanation
 * rather than a blank space (#968's rule, and #951's point: a refusal with no
 * reason reads as a bug).
 *
 * PER REGION for `permission`, SHARED for `record`. `roles:write` governs the
 * details and `roles:manage` governs what the role grants, so a permission
 * refusal names a different slug in each region and a shared sentence would be
 * wrong in one of them. A record refusal is about the record, so both regions
 * say the same thing — and because they say the SAME thing, the shell hoists it
 * and prints it once above the page instead of paraphrasing one fact twice.
 */
function localizeDenial(
  t: RolesTranslate,
  section: 'details' | 'permissions'
): (code: string) => string | null {
  return (code) => {
    if (code === 'permission') {
      return section === 'details'
        ? t(
            'roles.record.details.readOnly.permission',
            "You don't have permission to change roles, so these details are read-only."
          )
        : t(
            'roles.record.permissions.readOnly.permission',
            'You may see what this role grants, but not change it.'
          );
    }
    if (code === 'record') {
      return t(
        'roles.record.readOnly.systemRole',
        'This is a global base role. Only the system tenant can change it.'
      );
    }
    // Unrecognised: the server's own sentence is better than none.
    return null;
  };
}

/**
 * What the page SAYS about a role — a pure projection, at module scope, of the
 * record and the dictionary. There is no `can` in scope for it to reach for.
 */
const roleFacts: RecordFactsFn<RoleRecordFields> = (role, t) => ({
  title: role.name,
  subtitle: role.description || t('roles.record.subtitle', 'Role record'),
  badges: role.global
    ? [
        {
          key: 'global',
          label: t('roles.record.stat.scope.global', 'Global base role'),
          tone: 'warning' as const,
        },
      ]
    : [],
  stats: [
    {
      key: 'holders',
      label: t('roles.record.stat.holders', 'Users with this role'),
      value: role.holderCount,
    },
    {
      key: 'permissions',
      label: t('roles.record.stat.permissions', 'Permissions granted'),
      value: role.permissionCount,
    },
    {
      key: 'created',
      label: t('roles.record.stat.created', 'Created'),
      value: formatRecordDate(role.createdAt),
    },
    {
      key: 'scope',
      label: t('roles.record.stat.scope', 'Scope'),
      value: role.global
        ? t('roles.record.stat.scope.global', 'Global base role')
        : t('roles.record.stat.scope.tenant', "Your tenant's role"),
    },
  ],
});

/**
 * The role RECORD PAGE (#882) — the first record page in the app, and since
 * #882's shell extraction the first CONSUMER of it rather than a hand-built
 * one-off. What used to be four hundred lines of layout, gate resolution and
 * three near-identical fetch effects is now the two things only a role knows:
 * its fields and its form.
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
 * router, no capability source of its own. Data goes through `adapter`, copy
 * through `t`, navigation through `onBack`, so the same component mounts under
 * Next, a Tauri shell, or the Vite harness.
 *
 * READ-ONLY IS A FIRST-CLASS STATE, AND IT IS PER REGION (#910). The page has
 * two regions — the role's details and the permission set it grants — and each
 * is independently hidden, read-only or editable, because "some parts have
 * permissions, not always everything is allowed".
 *
 * THE SERVER DECIDES ALL OF IT. This screen takes no `can` prop, and that
 * absence is the design rather than an omission: it resolves nothing, combines
 * nothing, and infers nothing. `GET /roles/{id}` returns a verdict per region,
 * resolved against the same `RoleChecker` the middleware enforces with and
 * against the per-record ownership rule (WC-110/WC-222: a global NULL-tenant
 * base role is visible to a tenant but writable only by the system tenant), and
 * OMITS both the verdict and the data for a region the caller may not see. The
 * shell takes both renderings of each region and picks; a hidden region renders
 * neither, and its rows were never in the payload to render from.
 */
export function RoleRecordScreen({
  adapter,
  roleId,
  t: injectedT,
  onNotify,
  onBack,
  className,
}: RoleRecordScreenProps) {
  const t: RolesTranslate = injectedT ?? identityTranslate;

  // The RECORD, and then the picker source it is edited against — SEQUENTIALLY,
  // which is a change #910 forced and an improvement it happens to bring.
  //
  // The catalogue is the whole installation's permission list: every core slug
  // plus every plugin-declared one, fetched only so the grid has boxes to tick.
  // A caller whose `permissions` region is read-only or hidden has nothing to
  // tick, and fetching it for them was always waste — but it was also, since
  // #910, a leak: the catalogue is most of what the permissions region shows,
  // and a page that withheld the region while still pulling the catalogue would
  // have handed over the interesting half anyway.
  //
  // So the record decides. `sections.permissions === 'editable'` is the only
  // state that needs the catalogue; the read-only rendering reads the role's OWN
  // permissions, which arrive with the record, and the hidden state has neither.
  // Their failure is still the page's failure — a page that rendered the role
  // but not the catalogue would offer an edit form with no permissions in it.
  const loaded = useRecordResource<{
    role: RoleWithPermissions;
    catalogue: Permission[];
  }>(
    async () => {
      const role = await adapter.getRole(roleId);
      const catalogue =
        role.sections?.permissions?.state === 'editable' ? await adapter.listPermissions() : [];
      return { role, catalogue };
    },
    [roleId],
    t('roles.record.error.load', 'Failed to load this role')
  );

  // Who holds it — the headcount comes back as the pagination total, so this one
  // request answers both "how many" and "who most recently".
  const holders = useRecordResource(
    () => adapter.getRoleAssignments(roleId, HOLDERS_PAGE_SIZE),
    [roleId],
    t('roles.record.holders.error', 'Failed to load who holds this role')
  );

  // The role's own audit trail. `audit:read` is a SEPARATE permission a role
  // administrator need not hold, so the adapter's `'forbidden'` sentinel becomes
  // an ABSENT panel rather than an error box.
  const activity = useRecordResource(
    () => adapter.getRoleActivity(roleId, ACTIVITY_PAGE_SIZE),
    [roleId],
    t('roles.record.activity.error', "Failed to load this role's history")
  );

  // The panel takes a collection; the stat takes the total. One request, read
  // two ways — counting `assignments.length` is the mistake the endpoint's
  // `total` exists to prevent.
  const holderList: RecordResource<readonly RoleAssignment[]> =
    holders.status === 'ready' ? { status: 'ready', value: holders.value.assignments } : holders;
  const holderCount = holders.status === 'ready' ? holders.value.total : null;
  const holderOverflow =
    holders.status === 'ready' ? holders.value.total - holders.value.assignments.length : 0;

  const role = loaded.status === 'ready' ? loaded.value.role : null;
  const catalogue = loaded.status === 'ready' ? loaded.value.catalogue : [];

  // The draft, and the record as last SAVED. Both carry the id they belong to.
  //
  // A record page is addressable, so `roleId` can change under a mounted
  // component — clicking another role from a link on this one. State seeded for
  // the previous record is stale the instant that happens, and the effect that
  // re-seeds it runs a render LATER, so an id-less draft would paint the old
  // role's name into the new role's form for one frame. Stamping the id and
  // ignoring a mismatch is what makes that frame the skeleton instead.
  const [draft, setDraft] = useState<{
    id: number;
    name: string;
    description: string;
    permissionIds: number[];
  } | null>(null);
  const [saved, setSaved] = useState<RoleWithPermissions | null>(null);
  const [isSaving, setIsSaving] = useState(false);
  const [fieldError, setFieldError] = useState<string | null>(null);

  const resetDraft = useCallback((source: RoleWithPermissions) => {
    setDraft({
      id: source.id,
      name: source.name ?? '',
      description: source.description ?? '',
      permissionIds: (source.permissions ?? []).map((p) => p.id),
    });
    setFieldError(null);
  }, []);

  useEffect(() => {
    if (role !== null) {
      resetDraft(role);
      setSaved(role);
    }
  }, [role, resetDraft]);

  // The record as it now stands: the loaded row until a save replaces it.
  const current = saved !== null && role !== null && saved.id === role.id ? saved : role;
  const form = draft !== null && current !== null && draft.id === current.id ? draft : null;

  // ---- WHO DECIDES (#910) ----
  //
  // Nothing here. `current.sections` is the SERVER's per-region ruling, resolved
  // against the same `RoleChecker` its middleware enforces with and against the
  // per-record ownership rule (`roleManageableByTenant`) that no route table can
  // express. This screen reads the verdict and renders it.
  //
  // What used to be here was an ordered list of two gates the browser folded
  // together: `can('roles:write')` from `/me/capabilities` AND the record's
  // `manageable` flag. Both were server answers, so the fold was defensible —
  // but it was one page-level answer for a page whose parts have different
  // ones, and extending it granularly would have meant the client inventing the
  // conjunctions. The moment a browser ANDs two grants into a third, the
  // deployment holds two different answers to one authorization question, and
  // the one that renders is whichever the client edited last.
  //
  // `manageable` is still on the record and still a decision about the CALLER,
  // so it stays out of `roleFacts` — the shell's `RecordFields` constraint
  // enforces that, and #895 is why.
  const detailsAccess = sectionAccessFrom(
    current?.sections,
    'details',
    localizeDenial(t, 'details')
  );
  const permissionsAccess = sectionAccessFrom(
    current?.sections,
    'permissions',
    localizeDenial(t, 'permissions')
  );
  const canEditDetails = detailsAccess.state === 'editable';
  const canEditPermissions = permissionsAccess.state === 'editable';

  // Dirty means "there is something to SAVE", so it counts only the regions this
  // caller may change. A page whose permissions region is read-only must not
  // light up its Save button because the grid re-derived a set — and, more to the
  // point, must never send a `permissions` key the server would refuse: the
  // submit below builds its body from the same two flags.
  const isDirty = useMemo(() => {
    if (!current || !form) return false;
    const original = (current.permissions ?? []).map((p) => p.id);
    const sameSet =
      original.length === form.permissionIds.length &&
      new Set(form.permissionIds).size === new Set([...form.permissionIds, ...original]).size;
    const detailsChanged =
      form.name !== (current.name ?? '') || form.description !== (current.description ?? '');
    return (canEditDetails && detailsChanged) || (canEditPermissions && !sameSet);
  }, [current, form, canEditDetails, canEditPermissions]);

  const back = { label: t('roles.record.back', 'Back to roles'), onBack };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!current || !form || !(canEditDetails || canEditPermissions)) return;

    if (canEditDetails) {
      if (form.name.trim() === '') {
        setFieldError(t('roles.record.validation.nameRequired', 'Name is required'));
        return;
      }
      if (form.description.trim() === '') {
        setFieldError(t('roles.record.validation.descriptionRequired', 'Description is required'));
        return;
      }
    }
    setFieldError(null);

    try {
      setIsSaving(true);
      // The body carries ONLY the regions this caller may write. The server
      // refuses a `permissions` key from a caller whose permissions region is
      // read-only — deliberately a 403 rather than a silent drop, because a save
      // that returns 200 without doing what it said is the failure mode that
      // sends an operator looking for a bug in the wrong place — so a page that
      // sent every field regardless would turn a read-only REGION into a failed
      // PAGE.
      const result = await adapter.updateRole(current.id, {
        ...(canEditDetails ? { name: form.name, description: form.description } : {}),
        ...(canEditPermissions ? { permissions: form.permissionIds } : {}),
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
      const next: RoleWithPermissions = {
        ...current,
        ...(canEditDetails ? { name: form.name, description: form.description } : {}),
        ...(canEditPermissions
          ? {
              permissions: catalogue.filter((p) => form.permissionIds.includes(p.id)),
            }
          : {}),
      };
      setSaved(next);
      resetDraft(next);
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

  // The record's own failure is the page's failure — unlike a side panel's.
  // `detail` carries the server's own sentence ("Role not found") when it sent
  // one, which is worth more here than the generic fallback.
  if (loaded.status === 'error') {
    return (
      <RecordPageError
        testId="role-record-error"
        title={t('roles.record.error.title', 'This role could not be loaded')}
        description={loaded.detail ?? t('roles.record.error.load', 'Failed to load this role')}
        back={back}
        className={className}
      />
    );
  }

  // `current`/`form` are seeded by the effect above, one render after the
  // record arrives, so they are checked here rather than assumed.
  if (loaded.status !== 'ready' || current === null || form === null) {
    return (
      <RecordPageSkeleton
        testId="role-record-loading"
        back={back}
        label={t('roles.record.loading', 'Loading role…')}
        className={className}
      />
    );
  }

  const fields: RoleRecordFields = {
    name: current.name,
    description: current.description,
    createdAt: current.createdAt ?? null,
    global: current.global === true,
    permissionCount: (current.permissions ?? []).length,
    holderCount,
  };

  // ---- THE REGIONS (#910) ----
  //
  // Two, and they are gated separately because they answer to different
  // authorities: renaming a role and changing what it AUTHORISES are different
  // acts with different blast radii, and #910 names this pair as the example.
  // Each declares both renderings — the shell picks, so a greyed-out form cannot
  // ship by omission — and the shell renders NEITHER when the region is hidden.
  //
  // The `key` is the same string the server keyed its verdict by. One name, so a
  // region and the ruling that governs it cannot drift apart.
  const detailsSection: RecordSectionSpec = {
    key: 'details',
    title: t('roles.record.details.title', 'Details'),
    description: t(
      'roles.record.details.subtitle',
      "The role's name and description, as they appear everywhere it is used."
    ),
    access: detailsAccess,
    editor: (
      <>
        <div className="space-y-1.5">
          <label htmlFor="role-record-name" className="block text-sm font-medium text-foreground">
            {t('roles.record.name.label', 'Role name')}
          </label>
          <Input
            id="role-record-name"
            form="role-record-form"
            value={form.name}
            onChange={(e) => setDraft({ ...form, name: e.target.value })}
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
            form="role-record-form"
            value={form.description}
            onChange={(e) => setDraft({ ...form, description: e.target.value })}
            placeholder={t('roles.record.description.placeholder', 'Role description')}
          />
        </div>
        {fieldError !== null && (
          <p className="text-sm text-destructive" role="alert">
            {fieldError}
          </p>
        )}
      </>
    ),
    // A description LIST, not labels pointing at paragraphs: `htmlFor` only
    // means anything against a labelable control, and a `<label>` with nothing
    // to label announces a form field that is not there.
    readOnly: (
      <dl className="space-y-4">
        <div className="space-y-1.5">
          <dt className="text-sm font-medium text-foreground">
            {t('roles.record.name.label', 'Role name')}
          </dt>
          <dd className="text-sm text-foreground">{current.name}</dd>
        </div>
        <div className="space-y-1.5">
          <dt className="text-sm font-medium text-foreground">
            {t('roles.record.description.label', 'Description')}
          </dt>
          <dd className="text-sm text-foreground">{current.description}</dd>
        </div>
      </dl>
    ),
  };

  const permissionsSection: RecordSectionSpec = {
    key: 'permissions',
    title: t('roles.record.permissions.title', 'Permissions'),
    description: t(
      'roles.record.permissions.subtitle',
      'Grouped by the resource each permission acts on.'
    ),
    access: permissionsAccess,
    // THE ACUTE CASE, in its final form. This grid used to be 53+ checkboxes in
    // a `max-h-80` scroll region nested inside a `max-h-[90vh]` dialog — a
    // scroll inside a scroll, which is what produced #882. It is now a
    // full-width region on an addressable page, and since #910 a region with its
    // own gate: who may READ what a role grants and who may CHANGE it are
    // different questions, and the modal had nowhere to put the difference.
    editor: (
      <PermissionsGrid
        permissions={catalogue}
        selectedIds={form.permissionIds}
        onChange={(permissionIds) => setDraft({ ...form, permissionIds })}
        t={t}
      />
    ),
    // The ROLE'S OWN permissions, never the installation's whole catalogue —
    // feeding the catalogue to a read-only view renders every permission on the
    // deployment as though the role had it. It is also all this branch HAS: the
    // catalogue is not fetched unless the region is editable.
    readOnly: (
      <PermissionsGrid
        permissions={current.permissions ?? []}
        selectedIds={form.permissionIds}
        onChange={() => undefined}
        t={t}
        readOnly
      />
    ),
  };

  const sections = [detailsSection, permissionsSection];
  const anyEditable = canEditDetails || canEditPermissions;

  return (
    <>
      {/* THE FORM OWNER, and nothing else.
          The shell renders the regions, so no element of this screen's wraps the
          inputs — and with regions gated independently there may not even BE a
          details region to hang a form on. HTML already solves this: a control
          carrying `form="role-record-form"` is owned by this form wherever it
          sits in the document, which is what makes Enter-to-submit work from the
          name field and the header's Save button work from outside the body
          entirely. An empty `<form>` is the honest expression of that ownership
          rather than a way around the shell. */}
      <form id="role-record-form" onSubmit={handleSubmit} hidden />
      <RecordPageShell
        testId="role-record"
        fields={fields}
        facts={roleFacts}
        t={t}
        back={back}
        icon={<IconShieldLock />}
        className={className}
        actions={
          <div className="flex items-center gap-2">
            <Button
              type="button"
              variant="outline"
              disabled={!isDirty || isSaving}
              onClick={() => resetDraft(current)}
            >
              {t('roles.record.cancel', 'Discard changes')}
            </Button>
            <Button type="submit" form="role-record-form" disabled={isSaving || !isDirty}>
              {isSaving
                ? t('roles.record.saving', 'Saving…')
                : t('roles.record.save', 'Save changes')}
            </Button>
          </div>
        }
        notices={
          // #886 — the blast radius, stated before the edit rather than after it.
          // Shown only when the record is BOTH global and actually editable here,
          // which is only ever a system-tenant operator: for anyone else the
          // region's own read-only line already explains why its inputs are
          // absent, and two notices saying overlapping things is one notice too
          // many.
          fields.global && anyEditable ? (
            <Alert variant="warning" data-testid="role-record-global-warning">
              <IconAlertTriangle className="h-4 w-4" />
              <AlertDescription>
                {t(
                  'roles.record.globalWarning',
                  'This is a global base role: one role shared by every tenant on this deployment. Saving changes it for all of them, including their existing users.'
                )}
              </AlertDescription>
            </Alert>
          ) : undefined
        }
        // The regions, each with its own three-state access. No page-level
        // `access` prop: with regions there is no page-level answer left to give
        // that would not be a second opinion about the same question, so the shell
        // DERIVES the one thing it still needs (does the action bar render?) from
        // the regions themselves. The two therefore cannot disagree.
        sections={sections}
        side={
          <>
            <RecordCollectionPanel
              testId="role-record-holders"
              title={t('roles.record.holders.title', 'Who holds this role')}
              subtitle={t('roles.record.holders.subtitle', 'Most recently assigned first.')}
              resource={holderList}
              emptyLabel={t('roles.record.holders.empty', 'Nobody holds this role yet.')}
              footer={
                holderOverflow > 0 ? (
                  <p className="pt-2 text-xs text-muted-foreground">
                    {t('roles.record.holders.more', 'and {count} more', {
                      count: holderOverflow,
                    })}
                  </p>
                ) : undefined
              }
            >
              {(items) => (
                <RecordList>
                  {items.map((holder) => {
                    const when = formatRecordDate(holder.assignedAt);
                    return (
                      <RecordListItem
                        key={holder.membershipId}
                        primary={holder.displayName || holder.email || `#${holder.profileId}`}
                        secondary={
                          when !== null
                            ? t('roles.record.holders.assignedOn', 'assigned {date}', {
                                date: when,
                              })
                            : t('roles.record.holders.assignedUnknown', 'assignment date unknown')
                        }
                      />
                    );
                  })}
                </RecordList>
              )}
            </RecordCollectionPanel>

            <RecordCollectionPanel
              testId="role-record-activity"
              title={t('roles.record.activity.title', 'History')}
              subtitle={t('roles.record.activity.subtitle', 'Changes recorded against this role.')}
              resource={activity}
              emptyLabel={t(
                'roles.record.activity.empty',
                'Nothing has been recorded for this role yet.'
              )}
            >
              {(entries) => (
                <RecordTimeline>
                  {entries.map((entry) => (
                    <RecordTimelineItem
                      key={entry.id}
                      // The action key is a stable machine identifier
                      // (`role.updated`), not a source string, so it renders
                      // verbatim and never enters the catalogue — the same rule
                      // permission slugs follow.
                      title={entry.action}
                      meta={
                        <>
                          {formatRecordDateTime(entry.createdAt) ?? '—'}
                          {' · '}
                          {entry.actorUserId !== null
                            ? t('roles.record.activity.actor', 'by user {id}', {
                                id: entry.actorUserId,
                              })
                            : t('roles.record.activity.unknownActor', 'by the system')}
                        </>
                      }
                    />
                  ))}
                </RecordTimeline>
              )}
            </RecordCollectionPanel>
          </>
        }
      />
    </>
  );
}
