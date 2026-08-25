'use client';

/**
 * Injected-translator keys this file renders through `t`. Declared here for the
 * i18n catalogue extractor: it cannot infer a domain from a prop-injected
 * translator (see UsersTranslate — deliberately NOT typed `TranslateFn`, so
 * these files stay unscanned the way the roles slice does), so the keys are
 * enumerated below instead. All of this screen's copy resolves in the `admin`
 * domain.
 *
 * @i18n-keys admin
 *   users.record.activity.actor = by user {id}
 *   users.record.activity.empty = Nothing has been recorded against this person yet.
 *   users.record.activity.error = Failed to load this person's history
 *   users.record.activity.heldSince = held since {date}
 *   users.record.activity.ou = in unit {id}
 *   users.record.activity.role = role {role}
 *   users.record.activity.subtitle = Every grant and revocation recorded against this person, newest first.
 *   users.record.activity.title = Authority history
 *   users.record.activity.unknownActor = by the system
 *   users.record.back = Back to users
 *   users.record.badge.deactivated = Deactivated
 *   users.record.badge.deactivated.hint = The account is switched off globally, so this person cannot sign in to any tenant.
 *   users.record.badge.invited = Invited
 *   users.record.badge.suspended = Suspended
 *   users.record.cancel = Discard changes
 *   users.record.details.subtitle = Who this person is here, and what they may do.
 *   users.record.details.title = Details
 *   users.record.email.hint = The email is this person's identity across every tenant and cannot be changed here.
 *   users.record.email.label = Email
 *   users.record.error.load = Failed to load this person
 *   users.record.error.notFound = This person is no longer in your tenant.
 *   users.record.error.save = Failed to save this person
 *   users.record.error.title = This person could not be loaded
 *   users.record.loading = Loading person…
 *   users.record.memberships.empty = This person belongs to no tenant.
 *   users.record.memberships.error = Failed to load this person's tenants and roles
 *   users.record.memberships.primary = Primary
 *   users.record.memberships.subtitle = Every tenant this person belongs to, and the role they hold in each.
 *   users.record.memberships.title = Tenants and roles
 *   users.record.name.hint = Derived from the email address; there is no separate name to edit.
 *   users.record.name.label = Name
 *   users.record.ou.error = Failed to load the organisational units
 *   users.record.ou.incomplete = The organisational units could not be listed in full, so the picker is withheld — a short list would hide units that exist.
 *   users.record.ou.label = Organisational unit
 *   users.record.ou.none = None (root)
 *   users.record.ou.placeholder = Select an organisational unit
 *   users.record.ou.unknown = Unit #{id}
 *   users.record.password.action = Send reset link
 *   users.record.password.error = Failed to send the password-reset link
 *   users.record.password.hint = The person receives a one-time link by email and chooses their own password. No password is ever shown to you, and using the link signs the account out of every existing session.
 *   users.record.password.sending = Sending…
 *   users.record.password.subtitle = Recovering an account without ever handling a credential.
 *   users.record.password.success = A password-reset link has been sent to {email}
 *   users.record.password.title = Password
 *   users.record.readOnly.noPermission = You don't have permission to edit users, so this record is read-only.
 *   users.record.role.error = Failed to load the roles that can be assigned
 *   users.record.role.label = Role
 *   users.record.role.loading = Loading roles…
 *   users.record.role.placeholder = Select a role
 *   users.record.save = Save changes
 *   users.record.save.success = User updated successfully
 *   users.record.saving = Saving…
 *   users.record.stat.created = Joined
 *   users.record.stat.memberships = Roles held
 *   users.record.stat.ou = Organisational unit
 *   users.record.stat.role = Role in this tenant
 *   users.record.subtitle = User record
 *   users.record.tenant.label = Tenant
 */

import { useCallback, useEffect, useMemo, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@amroksaleh/ui/select';
import { IconUser } from '@tabler/icons-react';

import {
  RecordCollectionPanel,
  RecordList,
  RecordListItem,
  RecordPageError,
  RecordPageShell,
  RecordPageSkeleton,
  RecordTimeline,
  RecordTimelineItem,
  resolveAccess,
  useRecordResource,
} from '../record';
import { useDateDisplay } from '../datetime';
import type { DateDisplay } from '../datetime';
import type { RecordBadge, RecordFactsFn } from '../record';
import { identityTranslate } from '../nav/types';
import { USERS_WRITE } from './capabilities';
import type {
  OuOptionsResult,
  UserActivityEntry,
  UserRecord,
  UserRecordScreenProps,
  UsersTranslate,
} from './types';

/** How many history entries the record page asks for. */
const ACTIVITY_PAGE_SIZE = 12;

/** The Select's own value for "no organisational unit" — `''` is not selectable. */
const NO_OU = '__none__';

/**
 * What the SERVER says this person is.
 *
 * Two things to notice, both of them #895's lesson at different scales.
 *
 * FIRST: there is no `manageable`, no `canEdit`, no permission flag of any kind.
 * The shell's `RecordFields` check rejects those names outright, so the page
 * physically cannot label a person by what the CALLER may do to them. Whether
 * this record is editable lives in `resolveAccess` below.
 *
 * SECOND, and the same mistake one level down: `accountStatus` and
 * `membershipStatus` are two fields, not one "status". The first is the global
 * account switch and the second is this tenant's membership lifecycle. Reading
 * either as "active" says nothing about the other — an operator looking at an
 * `invited` membership on a globally deactivated profile needs both sentences,
 * and a page that picked one would be confidently wrong about the other.
 */
interface UserRecordFields {
  name: string;
  email: string;
  /** The PRIMARY role held in this tenant. */
  role: string;
  ouId: number | null;
  /** Resolved from `/api/ous`; null when that list is unavailable or incomplete. */
  ouName: string | null;
  createdAt: string | null;
  /** `profiles.status` — the global account switch. */
  accountStatus: string;
  /** `memberships.status` in this tenant. */
  membershipStatus: string;
  /** How many memberships this person holds; null while that request is in flight. */
  membershipCount: number | null;
}

/**
 * What the page SAYS about a person — a pure projection, at module scope, of the
 * record and the dictionary. There is no `can` in scope for it to reach for, and
 * the fields type it receives could not carry one if there were.
 */
const userFacts: RecordFactsFn<UserRecordFields> = (user, t, dates) => {
  const badges: RecordBadge[] = [];
  if (user.accountStatus === 'inactive') {
    badges.push({
      key: 'deactivated',
      label: t('users.record.badge.deactivated', 'Deactivated'),
      tone: 'danger' as const,
      title: t(
        'users.record.badge.deactivated.hint',
        'The account is switched off globally, so this person cannot sign in to any tenant.'
      ),
    });
  }
  if (user.membershipStatus === 'invited') {
    badges.push({
      key: 'invited',
      label: t('users.record.badge.invited', 'Invited'),
      tone: 'warning' as const,
    });
  }
  if (user.membershipStatus === 'suspended') {
    badges.push({
      key: 'suspended',
      label: t('users.record.badge.suspended', 'Suspended'),
      tone: 'warning' as const,
    });
  }

  return {
    title: user.name,
    subtitle: user.email || t('users.record.subtitle', 'User record'),
    badges,
    stats: [
      {
        key: 'role',
        label: t('users.record.stat.role', 'Role in this tenant'),
        value: user.role,
      },
      {
        key: 'ou',
        label: t('users.record.stat.ou', 'Organisational unit'),
        // Three distinct answers, and the third is the honest one: "the server
        // says unit 7, and we could not learn its name" is not the same as "no
        // unit", so it is not rendered as one.
        value:
          user.ouId === null
            ? t('users.record.ou.none', 'None (root)')
            : (user.ouName ??
              t('users.record.ou.unknown', 'Unit #{id}', { id: user.ouId })),
      },
      {
        key: 'memberships',
        label: t('users.record.stat.memberships', 'Roles held'),
        value: user.membershipCount,
      },
      // #1068: the stat GOES when this tenant hides dates. "Joined —" is a
      // label refusing to answer its own question; an absent stat is a fact
      // the page simply does not report.
      ...(dates.hidden
        ? []
        : [
            {
              key: 'created',
              label: t('users.record.stat.created', 'Joined'),
              value: dates.date(user.createdAt),
            },
          ]),
    ],
  };
};

/** A metadata value read defensively — the wire type is `unknown` by construction. */
function metaString(metadata: Record<string, unknown>, key: string): string | null {
  const value = metadata[key];
  if (typeof value === 'string' && value !== '') return value;
  if (typeof value === 'number') return String(value);
  return null;
}

function metaNumber(metadata: Record<string, unknown>, key: string): number | null {
  const value = metadata[key];
  return typeof value === 'number' ? value : null;
}

/**
 * What an authority-history entry CHANGED, when it recorded enough to say.
 *
 * This is the whole reason #889/#890 targeted the audit row at the USER: a
 * revocation DELETES the membership row, so this metadata is the only surviving
 * record of which role was taken away and how long it was held. Rendering it is
 * what turns a list of action keys into "manager, held since 3 March — revoked".
 * Nothing consumed it before this page.
 */
function activityDetail(
  entry: UserActivityEntry,
  t: UsersTranslate,
  dates: DateDisplay,
): string | null {
  const parts: string[] = [];

  const roleName = metaString(entry.metadata, 'role_name');
  if (roleName !== null) {
    parts.push(t('users.record.activity.role', 'role {role}', { role: roleName }));
  }

  const ouId = metaNumber(entry.metadata, 'ou_id');
  if (ouId !== null) {
    parts.push(t('users.record.activity.ou', 'in unit {id}', { id: ouId }));
  }

  // #1068: the clause is DROPPED rather than emptied. "manager, held since —"
  // reads as a broken sentence; "manager" reads as a complete one.
  const grantedAt = dates.date(metaString(entry.metadata, 'granted_at'));
  if (grantedAt !== null) {
    parts.push(t('users.record.activity.heldSince', 'held since {date}', { date: grantedAt }));
  }

  return parts.length > 0 ? parts.join(' · ') : null;
}

/** A field the API does not accept a change to: shown, explained, never faked. */
function StaticField({ label, value, hint }: { label: string; value: ReactNode; hint?: string }) {
  return (
    <div className="space-y-1.5">
      <span className="block text-sm font-medium text-foreground">{label}</span>
      <p className="text-sm text-foreground">{value}</p>
      {hint !== undefined && <p className="text-xs text-muted-foreground">{hint}</p>}
    </div>
  );
}

/**
 * The user RECORD PAGE (#882) — the record-page shell's SECOND consumer, and the
 * screen that proves it is a shell rather than the roles page with a different
 * name.
 *
 * WHY USERS. It is the most-used record on the platform, and since #889/#890 it
 * is the only one that can show a person's COMPLETE authority history: membership
 * grants and revocations are audited against the USER, so
 * `target_type=user&target_id=N` returns every change to what this person may do
 * in one query — including, for a revocation, what was removed and how long they
 * held it. That trail existed and nothing read it.
 *
 * WHAT IT DOES NOT DO, on purpose. Granting and revoking a membership still
 * happens in the memberships MODAL on the list page, which is untouched and still
 * works. This page is additive exactly the way #885 was: it adds an address for a
 * person and the context a dialog cannot carry, and the modals are retired per
 * screen by #884's audit rather than by this change.
 *
 * Presentational and data-source-agnostic like every screen in this package: data
 * through `adapter`, capabilities through `can`, copy through `t`, navigation
 * through `onBack`. RTL-safe throughout — every inset is logical and no branch
 * here reads the direction.
 */
export function UserRecordScreen({
  adapter,
  userId,
  can,
  t: injectedT,
  onNotify,
  onBack,
  className,
}: UserRecordScreenProps) {
  const t: UsersTranslate = injectedT ?? identityTranslate;
  // #1068: the only sanctioned way to put a date on a screen.
  const dates = useDateDisplay();

  const loaded = useRecordResource<UserRecord>(
    () => adapter.getUser(userId),
    [userId],
    t('users.record.error.load', 'Failed to load this person')
  );

  const memberships = useRecordResource(
    () => adapter.listUserMemberships(userId),
    [userId],
    t('users.record.memberships.error', "Failed to load this person's tenants and roles")
  );

  // `audit:read` is a SEPARATE permission from user administration, so the
  // adapter's `'forbidden'` sentinel becomes an ABSENT panel — clean absence,
  // not an error box about a capability the operator withheld deliberately.
  const activity = useRecordResource(
    () => adapter.getUserActivity(userId, ACTIVITY_PAGE_SIZE),
    [userId],
    t('users.record.activity.error', "Failed to load this person's history")
  );

  // The picker sources. Both are page-independent, so they load once rather than
  // per record — and their failure withholds a PICKER, never the page.
  const roleNames = useRecordResource<string[]>(
    () => adapter.listRoleNames(),
    [],
    t('users.record.role.error', 'Failed to load the roles that can be assigned')
  );
  const ous = useRecordResource<OuOptionsResult>(
    () => adapter.listOus(),
    [],
    t('users.record.ou.error', 'Failed to load the organisational units')
  );

  // The draft, and the record as last SAVED. Both carry the id they belong to.
  //
  // A record page is addressable, so `userId` can change under a mounted
  // component. State seeded for the previous person is stale the instant that
  // happens, and the effect that re-seeds it runs a render LATER — so an id-less
  // draft would paint one person's role into another person's form for a frame.
  // Stamping the id and ignoring a mismatch makes that frame the skeleton.
  const [saved, setSaved] = useState<UserRecord | null>(null);
  const [draft, setDraft] = useState<{ id: number; role: string; ouId: number | null } | null>(
    null
  );
  const [isSaving, setIsSaving] = useState(false);
  const [isSendingReset, setIsSendingReset] = useState(false);

  const record = loaded.status === 'ready' ? loaded.value : null;
  const current = saved !== null && record !== null && saved.id === record.id ? saved : record;

  const resetDraft = useCallback((source: UserRecord) => {
    setDraft({ id: source.id, role: source.role ?? '', ouId: source.ouId });
  }, []);

  useEffect(() => {
    if (record !== null) {
      resetDraft(record);
      setSaved(record);
    }
  }, [record, resetDraft]);

  const form = draft !== null && current !== null && draft.id === current.id ? draft : null;

  // A complete OU list, or none at all. A SHORT list is indistinguishable from a
  // correct one, and acting on it writes the wrong unit onto a real person — the
  // same rule the users modals follow, and the reason `complete` travels with
  // the options rather than being inferred from their length.
  const ouOptions = ous.status === 'ready' && ous.value.complete ? ous.value.options : null;
  const ouName = useMemo(() => {
    if (current?.ouId == null || ouOptions === null) return null;
    return ouOptions.find((ou) => ou.id === current.ouId)?.name ?? null;
  }, [current?.ouId, ouOptions]);

  const membershipCount = memberships.status === 'ready' ? memberships.value.length : null;

  // ONE gate, and it is the caller's capability — not a property of the person.
  // `users:write` is an EXISTING grant (migration 022); nothing here invents a
  // permission, which would reach only `admin` and silently strip the capability
  // from operators on custom administrative roles (#834).
  const access = resolveAccess([
    {
      allowed: can(USERS_WRITE),
      reason: t(
        'users.record.readOnly.noPermission',
        "You don't have permission to edit users, so this record is read-only."
      ),
    },
  ]);

  const isDirty =
    current !== null &&
    form !== null &&
    (form.role !== (current.role ?? '') || form.ouId !== current.ouId);

  const back = { label: t('users.record.back', 'Back to users'), onBack };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!current || !form || !access.editable) return;

    try {
      setIsSaving(true);
      const result = await adapter.updateUser(current.id, {
        role: form.role,
        ouId: form.ouId,
      });
      if (result === 'not-found') {
        onNotify?.(
          t('users.record.error.notFound', 'This person is no longer in your tenant.'),
          'error'
        );
        return;
      }
      onNotify?.(t('users.record.save.success', 'User updated successfully'), 'success');
      // Re-seat the record on the saved values so the page stops reading dirty
      // without a refetch nobody asked for — and so the stat strip states what
      // was actually persisted.
      const next: UserRecord = { ...current, role: form.role, ouId: form.ouId };
      setSaved(next);
      resetDraft(next);
    } catch (error) {
      onNotify?.(
        error instanceof Error && error.message
          ? error.message
          : t('users.record.error.save', 'Failed to save this person'),
        'error'
      );
    } finally {
      setIsSaving(false);
    }
  };

  const handleSendResetLink = async () => {
    if (!current) return;
    try {
      setIsSendingReset(true);
      await adapter.sendPasswordResetLink(current.id);
      onNotify?.(
        t('users.record.password.success', 'A password-reset link has been sent to {email}', {
          email: current.email,
        }),
        'success'
      );
    } catch (error) {
      onNotify?.(
        error instanceof Error && error.message
          ? error.message
          : t('users.record.password.error', 'Failed to send the password-reset link'),
        'error'
      );
    } finally {
      setIsSendingReset(false);
    }
  };

  if (loaded.status === 'error') {
    return (
      <RecordPageError
        testId="user-record-error"
        title={t('users.record.error.title', 'This person could not be loaded')}
        description={loaded.detail ?? t('users.record.error.load', 'Failed to load this person')}
        back={back}
        className={className}
      />
    );
  }

  if (loaded.status !== 'ready' || current === null || form === null) {
    return (
      <RecordPageSkeleton
        testId="user-record-loading"
        back={back}
        label={t('users.record.loading', 'Loading person…')}
        className={className}
      />
    );
  }

  const fields: UserRecordFields = {
    name: current.name,
    email: current.email,
    role: current.role,
    ouId: current.ouId,
    ouName,
    createdAt: current.createdAt,
    accountStatus: current.accountStatus,
    membershipStatus: current.status,
    membershipCount,
  };

  const primaryMembership =
    memberships.status === 'ready'
      ? (memberships.value.find((m) => m.isPrimary) ?? memberships.value[0] ?? null)
      : null;

  const ouLabelFor = (id: number | null): string =>
    id === null
      ? t('users.record.ou.none', 'None (root)')
      : (ouOptions?.find((ou) => ou.id === id)?.name ??
        t('users.record.ou.unknown', 'Unit #{id}', { id }));

  const detailsCard = (children: ReactNode) => (
    <Card>
      <CardHeader>
        <CardTitle>{t('users.record.details.title', 'Details')}</CardTitle>
        <CardDescription>
          {t('users.record.details.subtitle', 'Who this person is here, and what they may do.')}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">{children}</CardContent>
    </Card>
  );

  const identityFields = (
    <>
      {/* Neither of these is a disabled input. `email` is this person's identity
          across every tenant and `name` has no column behind it at all, so both
          are STATED with the reason — a greyed-out box invites the reader to
          look for the permission that would ungrey it. */}
      <StaticField
        label={t('users.record.email.label', 'Email')}
        value={current.email}
        hint={t(
          'users.record.email.hint',
          "The email is this person's identity across every tenant and cannot be changed here."
        )}
      />
      <StaticField
        label={t('users.record.name.label', 'Name')}
        value={current.name}
        hint={t(
          'users.record.name.hint',
          'Derived from the email address; there is no separate name to edit.'
        )}
      />
    </>
  );

  const editor = (
    <div className="space-y-6">
      <form id="user-record-form" onSubmit={handleSubmit}>
        {detailsCard(
          <>
            {identityFields}

            <div className="space-y-1.5">
              <label
                htmlFor="user-record-role"
                className="block text-sm font-medium text-foreground"
              >
                {t('users.record.role.label', 'Role')}
              </label>
              <Select
                value={form.role}
                onValueChange={(role) => setDraft({ ...form, role })}
              >
                <SelectTrigger id="user-record-role" data-testid="user-record-role">
                  <SelectValue
                    placeholder={
                      roleNames.status === 'loading'
                        ? t('users.record.role.loading', 'Loading roles…')
                        : t('users.record.role.placeholder', 'Select a role')
                    }
                  />
                </SelectTrigger>
                <SelectContent>
                  {(roleNames.status === 'ready' ? roleNames.value : []).map((name) => (
                    <SelectItem key={name} value={name}>
                      {name}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>

            <div className="space-y-1.5">
              <label htmlFor="user-record-ou" className="block text-sm font-medium text-foreground">
                {t('users.record.ou.label', 'Organisational unit')}
              </label>
              {ouOptions === null ? (
                // Withheld, not offered short — and the current unit is stated so
                // the operator still knows where this person is.
                <>
                  <p className="text-sm text-foreground">{ouLabelFor(form.ouId)}</p>
                  <p className="text-xs text-muted-foreground" data-testid="user-record-ou-withheld">
                    {t(
                      'users.record.ou.incomplete',
                      'The organisational units could not be listed in full, so the picker is withheld — a short list would hide units that exist.'
                    )}
                  </p>
                </>
              ) : (
                <Select
                  value={form.ouId === null ? NO_OU : String(form.ouId)}
                  onValueChange={(value) =>
                    setDraft({ ...form, ouId: value === NO_OU ? null : Number(value) })
                  }
                >
                  <SelectTrigger id="user-record-ou" data-testid="user-record-ou">
                    <SelectValue
                      placeholder={t('users.record.ou.placeholder', 'Select an organisational unit')}
                    />
                  </SelectTrigger>
                  <SelectContent>
                    <SelectItem value={NO_OU}>{t('users.record.ou.none', 'None (root)')}</SelectItem>
                    {ouOptions.map((ou) => (
                      <SelectItem key={ou.id} value={String(ou.id)}>
                        {ou.name}
                      </SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              )}
            </div>
          </>
        )}
      </form>

      {/* A LINK, never a credential. The reset path already invalidates every
          existing session, already rate-limits and already audits; an
          admin-typed password control here would have to reproduce all of that
          a second time, and would put a plaintext password into whatever
          support channel it got read out over. */}
      <Card>
        <CardHeader>
          <CardTitle>{t('users.record.password.title', 'Password')}</CardTitle>
          <CardDescription>
            {t(
              'users.record.password.subtitle',
              'Recovering an account without ever handling a credential.'
            )}
          </CardDescription>
        </CardHeader>
        <CardContent className="space-y-2">
          <Button
            type="button"
            variant="outline"
            data-testid="user-record-send-reset-link"
            disabled={isSendingReset}
            onClick={() => void handleSendResetLink()}
          >
            {isSendingReset
              ? t('users.record.password.sending', 'Sending…')
              : t('users.record.password.action', 'Send reset link')}
          </Button>
          <p className="text-xs text-muted-foreground">
            {t(
              'users.record.password.hint',
              'The person receives a one-time link by email and chooses their own password. No password is ever shown to you, and using the link signs the account out of every existing session.'
            )}
          </p>
        </CardContent>
      </Card>
    </div>
  );

  const readOnly = (
    <div className="space-y-6">
      {detailsCard(
        <dl className="space-y-4">
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.email.label', 'Email')}
            </dt>
            <dd className="text-sm text-foreground">{current.email}</dd>
          </div>
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.name.label', 'Name')}
            </dt>
            <dd className="text-sm text-foreground">{current.name}</dd>
          </div>
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.role.label', 'Role')}
            </dt>
            <dd className="text-sm text-foreground">{current.role}</dd>
          </div>
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.ou.label', 'Organisational unit')}
            </dt>
            <dd className="text-sm text-foreground">{ouLabelFor(current.ouId)}</dd>
          </div>
          <div className="space-y-1.5">
            <dt className="text-sm font-medium text-foreground">
              {t('users.record.tenant.label', 'Tenant')}
            </dt>
            <dd className="text-sm text-foreground">
              {primaryMembership?.tenantName ?? `#${current.tenantId}`}
            </dd>
          </div>
        </dl>
      )}
    </div>
  );

  return (
    <RecordPageShell
      testId="user-record"
      fields={fields}
      facts={userFacts}
      t={t}
      access={access}
      back={back}
      icon={<IconUser />}
      className={className}
      actions={
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={!isDirty || isSaving}
            onClick={() => resetDraft(current)}
          >
            {t('users.record.cancel', 'Discard changes')}
          </Button>
          <Button type="submit" form="user-record-form" disabled={isSaving || !isDirty}>
            {isSaving ? t('users.record.saving', 'Saving…') : t('users.record.save', 'Save changes')}
          </Button>
        </div>
      }
      main={{ editor, readOnly }}
      side={
        <>
          <RecordCollectionPanel
            testId="user-record-memberships"
            title={t('users.record.memberships.title', 'Tenants and roles')}
            subtitle={t(
              'users.record.memberships.subtitle',
              'Every tenant this person belongs to, and the role they hold in each.'
            )}
            resource={memberships}
            emptyLabel={t('users.record.memberships.empty', 'This person belongs to no tenant.')}
          >
            {(items) => (
              <RecordList>
                {items.map((membership) => (
                  <RecordListItem
                    key={membership.id}
                    primary={membership.tenantName}
                    secondary={membership.role}
                    action={
                      membership.isPrimary ? (
                        <Badge variant="outline">
                          {t('users.record.memberships.primary', 'Primary')}
                        </Badge>
                      ) : undefined
                    }
                  />
                ))}
              </RecordList>
            )}
          </RecordCollectionPanel>

          <RecordCollectionPanel
            testId="user-record-activity"
            title={t('users.record.activity.title', 'Authority history')}
            subtitle={t(
              'users.record.activity.subtitle',
              'Every grant and revocation recorded against this person, newest first.'
            )}
            resource={activity}
            emptyLabel={t(
              'users.record.activity.empty',
              'Nothing has been recorded against this person yet.'
            )}
            placeholderRows={3}
          >
            {(entries) => (
              <RecordTimeline>
                {entries.map((entry) => (
                  <RecordTimelineItem
                    key={entry.id}
                    // A stable machine identifier (`user.membership.removed`),
                    // not a source string — it renders verbatim and never enters
                    // the catalogue, the same rule permission slugs follow.
                    title={entry.action}
                    // #1068: the WHO survives, the WHEN goes — the rows are
                    // still in order, which is what a trail is for.
                    meta={
                      <>
                        {!dates.hidden && (
                          <>
                            {dates.dateTime(entry.createdAt) ?? '—'}
                            {' · '}
                          </>
                        )}
                        {entry.actorUserId !== null
                          ? t('users.record.activity.actor', 'by user {id}', {
                              id: entry.actorUserId,
                            })
                          : t('users.record.activity.unknownActor', 'by the system')}
                      </>
                    }
                    detail={activityDetail(entry, t, dates) ?? undefined}
                  />
                ))}
              </RecordTimeline>
            )}
          </RecordCollectionPanel>
        </>
      }
    />
  );
}
