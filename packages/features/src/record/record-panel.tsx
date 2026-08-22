'use client';

/**
 * The SIDE of a record page: the record's related collections.
 *
 * Each of these panels is assembled from a DIFFERENT endpoint with a DIFFERENT
 * permission gate, and the roles prototype established the three rules that
 * follow from that — repeated verbatim in every panel it hand-wrote, which is
 * why they are here instead:
 *
 *  1. **A panel's failure is not the page's failure.** A record that blanks
 *     because its history endpoint was slow is worse than one that shows the
 *     record and says the history is missing.
 *  2. **An ungranted capability is a CLEAN ABSENCE, not an error.** `audit:read`
 *     is separate from record administration; a panel that exists only to say
 *     "you may not see this" is noise about a decision the operator made on
 *     purpose.
 *  3. **Empty says so.** "Nobody holds this role yet" is information; an empty
 *     panel is a bug the reader has to rule out.
 *
 * Like the shell, these render no source strings — every word arrives already
 * translated from the screen above.
 */

import type { ReactNode } from 'react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@amroksaleh/ui/card';
import { Skeleton } from '@amroksaleh/ui/skeleton';

import type { RecordResource } from './types';

export interface RecordCollectionPanelProps<T> {
  title: string;
  subtitle?: string;
  /** `data-testid` for the panel. Absent from the DOM entirely when forbidden. */
  testId: string;
  resource: RecordResource<readonly T[]>;
  /** Shown when the collection loaded and is empty. */
  emptyLabel: string;
  /** Rendered after the items — a "and {n} more" line, a link to the full list. */
  footer?: ReactNode;
  /** How many skeleton rows to show while loading. */
  placeholderRows?: number;
  children: (items: readonly T[]) => ReactNode;
}

export function RecordCollectionPanel<T>({
  title,
  subtitle,
  testId,
  resource,
  emptyLabel,
  footer,
  placeholderRows = 2,
  children,
}: RecordCollectionPanelProps<T>) {
  // Rule 2, and the reason this is a `return null` rather than a variant: the
  // panel is ABSENT from the document, so nothing — not a heading, not a
  // landmark, not a screen-reader announcement — reports a section the caller
  // was never meant to see.
  if (resource.status === 'forbidden') {
    return null;
  }

  return (
    <Card data-testid={testId}>
      <CardHeader>
        <CardTitle>{title}</CardTitle>
        {subtitle !== undefined && <CardDescription>{subtitle}</CardDescription>}
      </CardHeader>
      <CardContent>
        {resource.status === 'error' ? (
          <p className="text-sm text-destructive">{resource.message}</p>
        ) : resource.status === 'loading' ? (
          <div className="space-y-2">
            {Array.from({ length: placeholderRows }, (_, index) => (
              <Skeleton key={index} className="h-8" />
            ))}
          </div>
        ) : resource.value.length === 0 ? (
          <p className="text-sm text-muted-foreground">{emptyLabel}</p>
        ) : (
          <>
            {children(resource.value)}
            {footer}
          </>
        )}
      </CardContent>
    </Card>
  );
}

/** A divided list of related records. */
export function RecordList({ children }: { children: ReactNode }) {
  return <ul className="divide-y divide-border/60">{children}</ul>;
}

/**
 * One related record: what it is, a line of detail, and an optional control.
 *
 * `truncate` on both lines rather than wrapping: the side column is narrow, and
 * a tenant name that reflows to three lines pushes the panels below it off the
 * fold. Logical text alignment, so it mirrors under RTL.
 */
export function RecordListItem({
  primary,
  secondary,
  action,
}: {
  primary: ReactNode;
  secondary?: ReactNode;
  action?: ReactNode;
}) {
  return (
    <li className="flex items-center justify-between gap-3 py-2">
      <div className="min-w-0 text-start">
        <span className="block truncate text-sm font-medium text-foreground">{primary}</span>
        {secondary !== undefined && (
          <span className="block truncate text-xs text-muted-foreground">{secondary}</span>
        )}
      </div>
      {action !== undefined && <div className="shrink-0">{action}</div>}
    </li>
  );
}

/** A chronological list — a record's history. */
export function RecordTimeline({ children }: { children: ReactNode }) {
  return <ul className="space-y-3">{children}</ul>;
}

/**
 * One thing that happened to this record.
 *
 * `border-s-2 … ps-3` — a LOGICAL inline-start rule, so the timeline's spine
 * moves to the right-hand side under RTL without this component knowing which
 * side that is.
 */
export function RecordTimelineItem({
  title,
  meta,
  detail,
}: {
  /** What happened. A machine action key renders monospaced by convention. */
  title: ReactNode;
  /** When, and by whom. */
  meta: ReactNode;
  /** What it changed — the fields an entry carried, when it carried any. */
  detail?: ReactNode;
}) {
  return (
    <li className="border-s-2 border-border ps-3 text-start">
      <span className="block font-mono text-xs text-foreground">{title}</span>
      <span className="block text-xs text-muted-foreground">{meta}</span>
      {detail !== undefined && (
        <span className="mt-1 block text-xs text-foreground/80">{detail}</span>
      )}
    </li>
  );
}
