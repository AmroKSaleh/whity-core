'use client';

/**
 * The tenant RECORD page (#882/#884) — what `/admin/tenants/[id]` renders.
 *
 * WHY THIS SCREEN MOVED OFF A MODAL, when its form is only two fields. The form
 * was never the reason. A tenant is the one record on this platform that other
 * subsystems describe from the outside — a subscription, a plan, an entitlement
 * override — and every one of those endpoints already existed with NO UI in
 * front of it. `EditTenantModal` could not have shown them: a dialog over a list
 * is sized for the fields it edits, and each of those reads sits behind a
 * DIFFERENT permission (`subscriptions:manage`, `entitlements:manage`) that the
 * operator opening the dialog may or may not hold. The record-page shell's side
 * column is built for exactly that shape — a panel per gate, absent rather than
 * refused when the gate says no.
 *
 * READ-ONLY IS A STATE HERE, and it is the state most callers will actually be
 * in. `TenantsApiHandler::canManageTenant()` lets a system-tenant operator write
 * any tenant and everyone else only their own, so a tenant admin browsing the
 * list is looking at rows they cannot change. The modal offered them a full
 * editable form and a Save button that 403'd; this page states the record and
 * says which rule holds.
 *
 * WHERE THE RECORD COMES FROM, and why it is not `GET /api/v1/tenants/{id}`:
 * there is no such route (index.php registers GET on the collection, then POST /
 * PATCH / DELETE on the item). So the record is read from the collection, walked
 * to the end — the same call the plugin record page makes for the same reason
 * (#960): row 26 of a paginated resource is not a deleted row, so a walk that
 * could not finish reports a LOAD FAILURE and only an exhausted walk may say
 * "not found".
 */

import { useCallback, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { apiClient } from '@/lib/api-client';
import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { useTranslation } from '@amroksaleh/features/i18n';
import {
  RecordCollectionPanel,
  RecordList,
  RecordListItem,
  RecordPageError,
  RecordPageShell,
  RecordPageSkeleton,
  formatRecordDate,
  formatRecordDateTime,
  resolveAccess,
  useRecordResource,
  type RecordFactsFn,
  type RecordResource,
} from '@amroksaleh/features/record';
import { Button } from '@amroksaleh/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Input } from '@/components/ui/input';
import { IconBuildingCommunity } from '@tabler/icons-react';

export type Tenant = components['schemas']['Tenant'];
type Subscription = components['schemas']['Subscription'];
type Entitlements = components['schemas']['TenantEntitlementsResponse']['data'];

/** The editable half of the record, stamped with the workspace it belongs to. */
interface TenantForm {
  id: number;
  name: string;
  slug: string;
}

/** One entitlement the operator changed for this workspace specifically. */
interface EntitlementOverride {
  key: string;
  value: boolean | number | undefined;
  description: string | null;
}

/** The reserved tenant whose holders administer the platform. */
const SYSTEM_TENANT_ID = 0;

/**
 * The slug rule, copied from the create/edit forms it replaces rather than
 * loosened: the backend accepts any string here, so the client is the only place
 * that keeps a slug URL-safe, and a page that dropped the check would let one
 * through that the old dialog refused.
 */
const SLUG_PATTERN = /^[a-z0-9]+(?:-[a-z0-9]+)*$/;

/** The em dash a stated value shows when the record carries none. */
const EMPTY_VALUE = '—';

/**
 * What the SERVER says this workspace IS.
 *
 * No `manageable`, no `canEdit`, and — the near miss worth naming — no
 * "is this the caller's own workspace" either. That last one is not a permission
 * flag, so `RecordFields` would have admitted it, but it is still a statement
 * about the CALLER wearing a fact's clothes, and #895 is the story of exactly
 * that inference reading correctly until the one caller who can act on it looks
 * at it. Whose workspace this is decides what the page LETS you do, so it lives
 * in `resolveAccess` below and nowhere else.
 */
interface TenantRecordFields {
  name: string;
  slug: string | null;
  userCount: number;
  createdAt: string | null;
}

/** A pure projection of the record and the dictionary, at module scope (#895). */
const tenantFacts: RecordFactsFn<TenantRecordFields> = (tenant, t) => ({
  title: tenant.name,
  subtitle: t('tenants.record.subtitle', 'Workspace record'),
  stats: [
    {
      key: 'users',
      label: t('tenants.record.stat.users', 'People'),
      value: tenant.userCount,
    },
    {
      key: 'slug',
      label: t('tenants.record.stat.slug', 'Slug'),
      value: tenant.slug,
    },
    {
      key: 'created',
      label: t('tenants.record.stat.created', 'Created'),
      value: formatRecordDate(tenant.createdAt),
    },
  ],
});

export interface TenantRecordScreenProps {
  tenantId: number;
  /** Whether the caller holds `tenants:write`. */
  canWrite: boolean;
  /** The tenant the caller is ACTING IN — decides the cross-tenant write rule. */
  callerTenantId: number | null;
  onNotify: (message: string, tone: 'success' | 'error' | 'info' | 'warning') => void;
  onBack: () => void;
}

export function TenantRecordScreen({
  tenantId,
  canWrite,
  callerTenantId,
  onNotify,
  onBack,
}: TenantRecordScreenProps) {
  const t = useTranslation('admin');

  const loaded = useRecordResource<Tenant | 'not-found'>(
    async () => {
      // The whole list, walked to the end. `complete: false` is a LOAD failure,
      // never "not found" — see the file docblock.
      const result = await fetchAllPages<Tenant>(apiClient, '/api/v1/tenants');
      if (!result.complete) {
        throw new Error(
          t('tenants.record.error.partial', 'The workspace list could not be read in full.')
        );
      }
      return result.items.find((tenant) => tenant.id === tenantId) ?? 'not-found';
    },
    [tenantId],
    t('tenants.record.error.load', 'Failed to load this workspace')
  );

  // Both of these describe the tenant from OUTSIDE the tenants table and sit
  // behind their own permissions, so a 403 is a clean absence: an operator who
  // administers workspaces but not billing should see no billing section at
  // all, rather than a panel explaining a capability nobody gave them.
  const subscription = useRecordResource<Subscription>(
    async () => {
      const { data, response } = await api.GET('/api/v1/tenants/{id}/subscription', {
        params: { path: { id: tenantId } },
      });
      if (response.status === 403) return 'forbidden';
      if (data === undefined) {
        throw new Error(t('tenants.record.billing.error', "Failed to load this workspace's plan"));
      }
      return data.data;
    },
    [tenantId],
    t('tenants.record.billing.error', "Failed to load this workspace's plan")
  );

  const entitlements = useRecordResource<Entitlements>(
    async () => {
      const { data, response } = await api.GET('/api/v1/tenants/{id}/entitlements', {
        params: { path: { id: tenantId } },
      });
      if (response.status === 403) return 'forbidden';
      if (data === undefined) {
        throw new Error(
          t('tenants.record.entitlements.error', "Failed to load this workspace's entitlements")
        );
      }
      return data.data;
    },
    [tenantId],
    t('tenants.record.entitlements.error', "Failed to load this workspace's entitlements")
  );

  /** The record as last SAVED, so a successful write costs no refetch. */
  const [saved, setSaved] = useState<Tenant | null>(null);
  /** What the operator has TYPED, or null while the form is untouched. */
  const [draft, setDraft] = useState<TenantForm | null>(null);
  const [slugError, setSlugError] = useState<string | null>(null);
  const [isSaving, setIsSaving] = useState(false);

  const record = loaded.status === 'ready' && loaded.value !== 'not-found' ? loaded.value : null;
  // Both pieces of state carry the id they belong to and are IGNORED when it
  // does not match: a record page is addressable, so `tenantId` can change under
  // a mounted component, and state left over from the previous workspace is
  // stale the instant that happens. Checking the id rather than clearing it in
  // an effect means there is no frame in which one workspace's typing is showing
  // under another's name.
  const current = saved !== null && record !== null && saved.id === record.id ? saved : record;

  // The form as the RECORD stands — what an untouched page shows, and what
  // "discard" returns to. Derived rather than copied into state on load: a
  // seeding effect is a render behind the record it seeds from, and this page
  // has no moment where the two can disagree.
  const pristine: TenantForm | null =
    current === null ? null : { id: current.id, name: current.name, slug: current.slug ?? '' };
  const form = draft !== null && pristine !== null && draft.id === pristine.id ? draft : pristine;

  const discard = useCallback(() => {
    setSlugError(null);
    setDraft(null);
  }, []);

  // The two gates, in the order they should be EXPLAINED. A caller who lacks the
  // capability entirely is told that first: it is the more fundamental of the
  // two, and telling them about the cross-tenant rule instead would suggest that
  // switching workspaces would help.
  const access = resolveAccess([
    {
      allowed: canWrite,
      reason: t(
        'tenants.record.readOnly.noPermission',
        "You don't have permission to edit workspaces, so this record is read-only."
      ),
    },
    {
      allowed: callerTenantId === SYSTEM_TENANT_ID || callerTenantId === tenantId,
      reason: t(
        'tenants.record.readOnly.otherTenant',
        'Only an operator in the system workspace can change another workspace, so this record is read-only.'
      ),
    },
  ]);

  const isDirty =
    form !== null &&
    pristine !== null &&
    (form.name !== pristine.name || form.slug !== pristine.slug);

  const back = { label: t('tenants.record.back', 'Back to workspaces'), onBack };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (!current || !form || !access.editable) return;

    const name = form.name.trim();
    const slug = form.slug.trim();
    if (name === '') {
      onNotify(t('tenants.record.name.required', 'A name is required.'), 'error');
      return;
    }
    if (!SLUG_PATTERN.test(slug)) {
      setSlugError(
        t(
          'tenants.record.slug.invalid',
          'The slug must contain only lowercase letters, numbers, and hyphens.'
        )
      );
      return;
    }
    setSlugError(null);

    try {
      setIsSaving(true);
      const response = await apiClient(`/api/v1/tenants/${current.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ name, slug }),
      });
      if (response.status === 409) {
        onNotify(
          t('tenants.record.error.conflict', 'Another workspace already uses that name or slug.'),
          'error'
        );
        return;
      }
      if (!response.ok) {
        const body: unknown = await response.json().catch(() => ({}));
        const message =
          body !== null && typeof body === 'object' && 'error' in body
            ? String((body as { error: unknown }).error)
            : t('tenants.record.error.save', 'Failed to save this workspace');
        throw new Error(message);
      }

      onNotify(t('tenants.record.save.success', 'Workspace updated'), 'success');
      // Re-seat on the saved values so the stat strip states what was actually
      // persisted, without a refetch nobody asked for. Dropping the draft is
      // what makes the form read from the new record rather than from typing
      // that now matches it.
      setSaved({ ...current, name, slug });
      setDraft(null);
    } catch (error) {
      onNotify(
        error instanceof Error && error.message
          ? error.message
          : t('tenants.record.error.save', 'Failed to save this workspace'),
        'error'
      );
    } finally {
      setIsSaving(false);
    }
  };

  if (loaded.status === 'error') {
    return (
      <RecordPageError
        testId="tenant-record-error"
        title={t('tenants.record.error.title', 'This workspace could not be loaded')}
        description={
          loaded.detail ?? t('tenants.record.error.load', 'Failed to load this workspace')
        }
        back={back}
      />
    );
  }

  // An unknown id KEEPS ITS URL and names the cause, rather than bouncing to the
  // list: "deleted", "never existed" and "not yours to see" are three different
  // answers, and a redirect renders all three as the same silent event (#951).
  if (loaded.status === 'ready' && loaded.value === 'not-found') {
    return (
      <RecordPageError
        testId="tenant-record-missing"
        title={t('tenants.record.error.title', 'This workspace could not be loaded')}
        description={t(
          'tenants.record.error.notFound',
          'No workspace with this id is visible to you. It may have been deleted, or belong to an operator you are not.'
        )}
        back={back}
      />
    );
  }

  if (loaded.status !== 'ready' || current === null || form === null) {
    return (
      <RecordPageSkeleton
        testId="tenant-record-loading"
        back={back}
        label={t('tenants.record.loading', 'Loading workspace…')}
        stats={3}
      />
    );
  }

  const fields: TenantRecordFields = {
    name: current.name,
    slug: current.slug,
    userCount: current.userCount,
    createdAt: current.createdAt,
  };

  const detailsCard = (children: ReactNode) => (
    <Card>
      <CardHeader>
        <CardTitle>{t('tenants.record.details.title', 'Details')}</CardTitle>
        <CardDescription>
          {t(
            'tenants.record.details.subtitle',
            'What this workspace is called, and where it lives.'
          )}
        </CardDescription>
      </CardHeader>
      <CardContent className="space-y-4">{children}</CardContent>
    </Card>
  );

  const editor = (
    <form id="tenant-record-form" onSubmit={handleSubmit}>
      {detailsCard(
        <>
          <div className="space-y-1.5">
            <label
              htmlFor="tenant-record-name"
              className="block text-sm font-medium text-foreground"
            >
              {t('tenants.record.name.label', 'Name')}
            </label>
            <Input
              id="tenant-record-name"
              data-testid="tenant-record-name"
              value={form.name}
              autoComplete="off"
              onChange={(event) => setDraft({ ...form, name: event.target.value })}
            />
          </div>

          <div className="space-y-1.5">
            <label
              htmlFor="tenant-record-slug"
              className="block text-sm font-medium text-foreground"
            >
              {t('tenants.record.slug.label', 'Slug')}
            </label>
            <Input
              id="tenant-record-slug"
              data-testid="tenant-record-slug"
              value={form.slug}
              autoComplete="off"
              onChange={(event) => setDraft({ ...form, slug: event.target.value })}
            />
            <p className="text-xs text-muted-foreground">
              {t(
                'tenants.record.slug.hint',
                'The URL-friendly identifier for this workspace. Changing it changes every address that names it.'
              )}
            </p>
            {slugError !== null && (
              <p className="text-xs text-destructive" data-testid="tenant-record-slug-error">
                {slugError}
              </p>
            )}
          </div>
        </>
      )}
    </form>
  );

  const readOnly = detailsCard(
    <dl className="space-y-4">
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('tenants.record.name.label', 'Name')}
        </dt>
        <dd className="text-sm text-foreground">{current.name}</dd>
      </div>
      <div className="space-y-1.5">
        <dt className="text-sm font-medium text-foreground">
          {t('tenants.record.slug.label', 'Slug')}
        </dt>
        <dd className="text-sm text-foreground">{current.slug ?? EMPTY_VALUE}</dd>
      </div>
    </dl>
  );

  return (
    <RecordPageShell
      testId="tenant-record"
      fields={fields}
      facts={tenantFacts}
      t={t}
      access={access}
      back={back}
      icon={<IconBuildingCommunity />}
      actions={
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="outline"
            disabled={!isDirty || isSaving}
            onClick={discard}
          >
            {t('tenants.record.cancel', 'Discard changes')}
          </Button>
          <Button
            type="submit"
            form="tenant-record-form"
            data-testid="tenant-record-save"
            disabled={isSaving || !isDirty}
          >
            {isSaving
              ? t('tenants.record.saving', 'Saving…')
              : t('tenants.record.save', 'Save changes')}
          </Button>
        </div>
      }
      main={{ editor, readOnly }}
      side={
        <>
          <BillingPanel subscription={subscription} />
          <EntitlementOverridesPanel entitlements={entitlements} />
        </>
      }
    />
  );
}

/**
 * What this workspace is PAYING for — the first UI anywhere in the product for
 * `GET /api/v1/tenants/{id}/subscription`, which has been serving nothing.
 *
 * A single record rather than a collection, so it is wrapped in a one-item array
 * for the panel's list primitives: one line per fact, rather than a table
 * pretending a subscription has rows.
 */
function BillingPanel({ subscription }: { subscription: RecordResource<Subscription> }) {
  const t = useTranslation('admin');

  const resource: RecordResource<readonly Subscription[]> =
    subscription.status === 'ready'
      ? { status: 'ready', value: [subscription.value] }
      : subscription;

  return (
    <RecordCollectionPanel
      testId="tenant-record-billing"
      title={t('tenants.record.billing.title', 'Plan and billing')}
      subtitle={t(
        'tenants.record.billing.subtitle',
        'What this workspace is subscribed to, and what happens when it lapses.'
      )}
      resource={resource}
      emptyLabel={t('tenants.record.billing.empty', 'This workspace has no subscription.')}
    >
      {(items) => {
        const value = items[0];
        return (
          <RecordList>
            <RecordListItem
              primary={value.plan?.name ?? t('tenants.record.billing.noPlan', 'No plan applied')}
              secondary={
                value.status ?? t('tenants.record.billing.noStatus', 'No billing status recorded')
              }
            />
            <RecordListItem
              primary={t('tenants.record.billing.enforcement', 'Enforcement')}
              // A machine value the operator sets and reads back verbatim
              // (`block_writes`), not prose — rendered as itself, the same rule
              // permission slugs follow.
              secondary={value.effective_enforcement_mode}
            />
            {value.current_period_end != null && (
              <RecordListItem
                primary={t('tenants.record.billing.periodEnd', 'Current period ends')}
                secondary={formatRecordDateTime(value.current_period_end) ?? EMPTY_VALUE}
              />
            )}
          </RecordList>
        );
      }}
    </RecordCollectionPanel>
  );
}

/**
 * The entitlements this workspace has been given SEPARATELY from its plan.
 *
 * Only the overrides, deliberately. The effective set is the plan's answer plus
 * these, and listing all of it would bury the three keys somebody deliberately
 * changed for this one workspace under thirty they never touched — which is the
 * only question this panel exists to answer.
 */
function EntitlementOverridesPanel({
  entitlements,
}: {
  entitlements: RecordResource<Entitlements>;
}) {
  const t = useTranslation('admin');

  const resource: RecordResource<readonly EntitlementOverride[]> =
    entitlements.status === 'ready'
      ? {
          status: 'ready',
          value: entitlements.value.overridden.map((key) => ({
            key,
            value: entitlements.value.effective[key],
            description: entitlements.value.registry[key]?.description ?? null,
          })),
        }
      : entitlements;

  return (
    <RecordCollectionPanel
      testId="tenant-record-entitlements"
      title={t('tenants.record.entitlements.title', 'Entitlement overrides')}
      subtitle={t(
        'tenants.record.entitlements.subtitle',
        'Limits set for this workspace specifically, on top of whatever its plan grants.'
      )}
      resource={resource}
      emptyLabel={t(
        'tenants.record.entitlements.empty',
        'None — this workspace uses the entitlements its plan grants, unchanged.'
      )}
    >
      {(items) => (
        <RecordList>
          {items.map((entry) => (
            <RecordListItem
              key={entry.key}
              // The entitlement key is a stable machine identifier, rendered
              // verbatim; its description is the catalogue's own English.
              primary={entry.key}
              secondary={entry.description ?? undefined}
              action={
                <span className="text-sm font-medium text-foreground">
                  {typeof entry.value === 'boolean'
                    ? entry.value
                      ? t('tenants.record.entitlements.on', 'On')
                      : t('tenants.record.entitlements.off', 'Off')
                    : String(entry.value ?? EMPTY_VALUE)}
                </span>
              }
            />
          ))}
        </RecordList>
      )}
    </RecordCollectionPanel>
  );
}
