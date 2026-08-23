'use client';

/**
 * ONE REGION of a record page, in the three states #910 asks for.
 *
 * The operator's requirement was "some parts have permissions, not always
 * everything is allowed" — so the unit a record page gates is not the page, it
 * is the region. This component is that unit, and it exists so the three states
 * are decided in ONE place rather than per screen:
 *
 *  - **hidden** — `return null`. Not a collapsed card, not `hidden`, not
 *    `display:none`, not a heading with an explanation under it. The same rule
 *    `RecordCollectionPanel` follows for a forbidden resource, for the same
 *    reason: a region that exists only to announce a region you may not see
 *    tells you the region exists, which is the thing withholding it was for.
 *    The DOM is the last line of that defence, not the first — the region's data
 *    never left the server (see `sectionAccessFrom`) — but a client that
 *    rendered a placeholder would still be disclosing the shape of the record.
 *
 *  - **read-only** — the region, its heading, its read-only rendering, and a
 *    line saying WHY it cannot be changed. This is #951 applied one level up: an
 *    unavailable affordance is refused with a reason, because "you may not" and
 *    "this is broken" are otherwise identical on screen, and the person who
 *    cannot tell them apart is usually the one who could have fixed it.
 *
 *  - **editable** — the region and its editor.
 *
 * The heading is rendered by the SHELL SIDE of the seam rather than by each
 * region's own `Card`, so a read-only region and an editable one are the same
 * furniture with different contents. A screen that drew its own card per state
 * would be free to make the read-only one look like a failure.
 *
 * Renders no source strings: every word arrives already translated from the
 * screen above, like the rest of this slice.
 */

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';

import type { RecordSectionSpec } from './types';

export interface RecordSectionProps {
  /** The region and its resolved access. */
  section: RecordSectionSpec;
  /** The page's `data-testid` prefix; the region appends `-section-{key}`. */
  testId: string;
  /**
   * Suppress this region's own read-only line because the PAGE is already
   * saying the same sentence above it.
   *
   * When every visible region is read-only for the identical reason, the reason
   * is a property of the page rather than of any one region, and repeating it
   * under each heading is the same sentence three times. The shell decides this
   * — see `RecordPageShell` — because only it can see all the regions at once.
   */
  reasonHoisted?: boolean;
}

export function RecordSection({ section, testId, reasonHoisted = false }: RecordSectionProps) {
  const { key, title, description, access, editor, readOnly } = section;

  if (access.state === 'hidden') {
    return null;
  }

  const isReadOnly = access.state === 'read-only';

  return (
    <Card data-testid={`${testId}-section-${key}`} data-section-state={access.state}>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {description !== undefined && <CardDescription>{description}</CardDescription>}
      </CardHeader>
      <CardContent className="space-y-4">
        {isReadOnly && access.readOnlyReason !== null && !reasonHoisted && (
          // `role="note"`, not an alert: a region the caller may read but not
          // change is the system working as configured, and an alert role would
          // interrupt a screen reader to announce ordinary policy.
          <p
            role="note"
            className="rounded-md border border-border bg-muted/40 px-3 py-2 text-sm text-muted-foreground"
            data-testid={`${testId}-section-${key}-readonly`}
          >
            {access.readOnlyReason}
          </p>
        )}
        {isReadOnly ? readOnly : editor}
      </CardContent>
    </Card>
  );
}
