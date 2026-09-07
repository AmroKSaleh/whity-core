'use client';

import { useTranslation, type TranslateFn } from '@amroksaleh/features/i18n';
import { Badge } from '@amroksaleh/ui/badge';
import { Tooltip, TooltipContent, TooltipTrigger } from '@amroksaleh/ui/tooltip';
import type { DocumentScope, GovernedRow } from './types';

/**
 * "Who can see this, and why" — in words.
 *
 * THIS FILE IS THE POINT OF THE SCREEN. A list of template names tells you
 * nothing you did not already know; the reason the subsystem exists is that a
 * dean's secretary reaches more than a department head's secretary, and that
 * fact lives in three columns nobody can read at a glance: `scope`,
 * `owner_ou_id` and `required_permission`. So the columns are rendered AND
 * translated into a sentence, in the table (as the visibility cell's tooltip)
 * and again in the scope dialog (live, as the publisher changes the fields) —
 * one function, so the two can never say different things about the same row.
 *
 * The rules mirror `DocumentAccessPolicy::canView()` exactly, and the mirroring
 * is the risk: this is a SECOND statement of a decision the server already owns,
 * and a drift makes the screen confidently wrong. Two things keep it honest:
 *
 *  - it is described, never enforced. Nothing here gates a control or filters a
 *    row; the server withholds what a caller may not see, and this only explains
 *    the rows that arrived.
 *  - it is unit-tested against the same four cases the policy branches on, so a
 *    change to the policy that is not reflected here fails a test rather than
 *    shipping a misleading sentence.
 *
 * Kept as pure functions taking `t` rather than hooks, so the tests assert on
 * the strings without rendering anything.
 */

/** Everything the audience sentence needs beyond the row itself. */
export interface AudienceContext {
  /** Resolved unit name for `owner_ou_id`, or null when units are unreadable. */
  ouName: string | null;
  /** The viewing profile's own id, so a personal row can say "only you". */
  viewerProfileId: number | null;
}

/** A short, human label for a scope. Used on the badge. */
export function scopeLabel(scope: DocumentScope, t: TranslateFn): string {
  switch (scope) {
    case 'personal':
      return t('documentTemplates.scope.personal', 'Personal');
    case 'tenant':
      return t('documentTemplates.scope.tenant', 'Tenant-wide');
    case 'global':
      return t('documentTemplates.scope.global', 'Global');
    case 'system':
      return t('documentTemplates.scope.system', 'System');
    default:
      // A scope the client does not know is NOT rendered as if it were fine.
      // The policy fails closed on an unrecognised scope (the row is hidden from
      // everybody), so the label says the row is unreachable rather than
      // inventing a friendly name for a value that means "nobody".
      return t('documentTemplates.scope.unknown', 'Unrecognised');
  }
}

/**
 * A `Badge` variant per scope, so the tier is legible before the text is read.
 *
 * Deliberately not a rainbow: `personal` is the quiet default, the two SHARED
 * tiers are the ones worth noticing, and `system` is neutral because a seeded
 * starter is not a governance decision anybody made.
 */
export function scopeBadgeVariant(
  scope: DocumentScope
): 'secondary' | 'info' | 'warning' | 'outline' | 'destructive' {
  switch (scope) {
    case 'personal':
      return 'secondary';
    case 'tenant':
      return 'info';
    case 'global':
      return 'warning';
    case 'system':
      return 'outline';
    default:
      return 'destructive';
  }
}

/**
 * How the row's placement reads. `null` placement is NOT "unknown" — an unplaced
 * row is tenant-wide, which is what every row was before migration 117 and what
 * an unplaced row still is, so it is stated as the positive fact it is.
 */
export function placementLabel(
  row: Pick<GovernedRow, 'owner_ou_id'>,
  ctx: AudienceContext,
  t: TranslateFn
): string {
  if (row.owner_ou_id === null) {
    return t('documentTemplates.placement.none', 'Not filed at a unit');
  }
  // A bare `#4` when the unit list could not be read, with the notice on the page
  // saying why — an id in this column must never be mistaken for a unit whose
  // name is genuinely missing. Listing units needs `ous:read`, which this page's
  // own gate (documents:read) does not imply.
  return ctx.ouName ?? `#${row.owner_ou_id}`;
}

/**
 * The full "who can see this" sentence.
 *
 * Composed from the two independent predicates the policy applies — WHERE the
 * row is filed and WHAT KIND of person may see it — because both must pass and
 * a sentence that mentions only one is the misleading half.
 */
export function describeAudience(
  row: Pick<GovernedRow, 'scope' | 'owner_ou_id' | 'required_permission' | 'created_by'>,
  ctx: AudienceContext,
  t: TranslateFn
): string {
  if (row.scope === 'personal') {
    // Placement is not consulted for a personal row, and saying otherwise would
    // be a lie the dialog then contradicts: creator-only is already narrower
    // than any placement could make it.
    return row.created_by !== null && row.created_by === ctx.viewerProfileId
      ? t('documentTemplates.audience.personalYou', 'Only you. Nobody else can see this, wherever it is filed.')
      : t(
          'documentTemplates.audience.personalOther',
          'Only whoever created it. Nobody else can see this, wherever it is filed.'
        );
  }

  if (row.scope !== 'tenant' && row.scope !== 'global' && row.scope !== 'system') {
    return t(
      'documentTemplates.audience.unrecognised',
      'Nobody. The scope is not one the server recognises, and an unrecognised scope is hidden from everyone.'
    );
  }

  const where =
    row.owner_ou_id === null
      ? t('documentTemplates.audience.whereAnywhere', 'everyone in the tenant')
      : t('documentTemplates.audience.whereUnit', 'everyone at {unit} and below it', {
          unit: placementLabel(row, ctx, t),
        });

  // A system-scope row skips the permission gate entirely — that is the policy's
  // branch, not a simplification here.
  if (row.scope === 'system') {
    return t('documentTemplates.audience.system', 'Visible to {where}. System rows carry no permission gate.', {
      where,
    });
  }

  const tag = row.required_permission;
  if (tag === null || tag === '') {
    return t('documentTemplates.audience.untagged', 'Visible to {where}. No permission is required.', { where });
  }

  return t(
    'documentTemplates.audience.tagged',
    'Visible to {where} who also hold {permission}. Anyone without it never receives this row at all.',
    { where, permission: tag }
  );
}

/**
 * Whether saving these fields would need `documents:publish`.
 *
 * The client twin of `DocumentAccessPolicy::needsPublish()`, and it has to exist
 * because the server's rule is not the obvious one: FILING A ROW AT A UNIT
 * counts as publishing even on a personal row, so a writer without publish who
 * is offered a placement field gets a 403 on submit with no way to have known.
 * Mirroring the rule lets the dialog disable what it cannot save and say why.
 */
export function needsPublish(
  scope: DocumentScope,
  requiredPermission: string | null,
  ownerOuId: number | null
): boolean {
  if (requiredPermission !== null && requiredPermission !== '') return true;
  if (ownerOuId !== null) return true;
  return scope !== 'personal';
}

/**
 * The visibility cell: the tier as a badge, the whole audience sentence as its
 * tooltip.
 *
 * A component rather than a render function inlined in the table, because the
 * usage dialog needs the same badge for the templates it lists and two
 * renderings of "who can see this" that could disagree would defeat the point of
 * having one `describeAudience`.
 *
 * It is also what binds this file's translation domain. The pure functions above
 * take `t` as a parameter so they can be unit-tested without rendering anything,
 * and a module of nothing but such helpers has no domain for the key extractor to
 * attribute them to (`useTranslation` is the binding, and a parameter is not one).
 * Keeping the one component that uses them in the same file resolves that, which
 * is the arrangement `admin/document-library/view-rail.tsx` already uses.
 */
export function ScopeBadge({
  row,
  ouName,
  viewerProfileId,
  withTooltip = true,
}: {
  row: Pick<GovernedRow, 'scope' | 'owner_ou_id' | 'required_permission' | 'created_by'>;
  ouName: string | null;
  viewerProfileId: number | null;
  withTooltip?: boolean;
}) {
  const t = useTranslation('admin');
  const badge = <Badge variant={scopeBadgeVariant(row.scope)}>{scopeLabel(row.scope, t)}</Badge>;

  if (!withTooltip) return badge;

  return (
    <Tooltip>
      <TooltipTrigger asChild>{badge}</TooltipTrigger>
      <TooltipContent>{describeAudience(row, { ouName, viewerProfileId }, t)}</TooltipContent>
    </Tooltip>
  );
}

/** Where the row is filed, muted when it is filed nowhere. */
export function PlacementText({
  row,
  ouName,
}: {
  row: Pick<GovernedRow, 'owner_ou_id'>;
  ouName: string | null;
}) {
  const t = useTranslation('admin');

  return (
    <span className={row.owner_ou_id === null ? 'text-muted-foreground' : undefined}>
      {placementLabel(row, { ouName, viewerProfileId: null }, t)}
    </span>
  );
}
