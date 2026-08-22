'use client';

/**
 * The record-page SHELL (#882) — the layout and the states every record page on
 * this platform shares, extracted from the roles prototype (#885) against the
 * user record page as its second consumer.
 *
 * WHAT IT RENDERS, TOP TO BOTTOM
 *   1. a header — back link, icon, title, badges, and the caller's actions;
 *   2. the read-only notice, when a gate refused, naming WHICH gate;
 *   3. notices — the blast radius of an edit, stated before the edit;
 *   4. the stat strip — the record's context, above anything editable;
 *   5. a main-plus-side split — the record's own fields beside its related
 *      collections.
 *
 * THE #895 SPLIT, WHICH IS THE POINT OF THE PROP SHAPE. `fields` is what the
 * SERVER says the record is; `access` is what THIS CALLER may do to it. The
 * `facts` projection receives only the former, and the former's type cannot
 * carry a permission flag (see `RecordFields` in ./types). So the mistake that put
 * "Your tenant's role" on a global base role — inferring a fact from the
 * `manageable` flag beside it — is a compile error here rather than a code
 * review's job.
 *
 * RTL: every inset is logical (`ps-`/`pe-`/`ms-`/`me-`/`text-start`), and no
 * branch of this file reads the direction — the page mirrors with the app's
 * `<html dir>`.
 *
 * This file renders NO source strings of its own. Every word on screen arrives
 * already translated from the screen above it, which is why it declares no
 * `@i18n-keys` block: a shell with its own copy would be a second place a
 * record page's words live.
 */

import { Badge } from '@amroksaleh/ui/badge';
import { Button } from '@amroksaleh/ui/button';
import { ErrorState } from '@amroksaleh/ui/empty-state';
import { PageHeader } from '@amroksaleh/ui/page-header';
import { Skeleton } from '@amroksaleh/ui/skeleton';
import { IconArrowLeft } from '@tabler/icons-react';

import type {
  RecordBack,
  RecordFact,
  RecordPageShellProps,
  RecordProjection,
  RecordTone,
  RecordTranslate,
} from './types';

/**
 * The default translator: a stable, module-level reference (NOT an inline
 * arrow), for the same reason `identityTranslate` is one — an inline default
 * allocates a new function every render and re-runs anything depending on it.
 */
const identityRecordTranslate: RecordTranslate = (key, fallback) => fallback ?? key;

/** The shell's tone vocabulary, mapped onto the UI kit's `Badge` variants. */
const BADGE_VARIANT: Record<RecordTone, 'outline' | 'info' | 'success' | 'warning' | 'destructive'> =
  {
    neutral: 'outline',
    info: 'info',
    success: 'success',
    warning: 'warning',
    danger: 'destructive',
  };

/**
 * The em dash a stat shows when the server has not answered yet.
 *
 * A literal, not a translated key: it is punctuation, and routing it through the
 * catalogue would ask a translator to translate a dash. The roles page had it as
 * `roles.record.stat.unknown` for exactly one screen; centralising it here is
 * what stops the second screen inventing `users.record.stat.unknown` with a
 * different glyph.
 */
const UNKNOWN = '—';

/** The back control, shared by the shell and its loading/error siblings. */
function BackButton({ back }: { back: RecordBack }) {
  return (
    <Button type="button" variant="ghost" size="sm" onClick={back.onBack} className="gap-2">
      {/* Mirrored by direction: the arrow points AWAY from the content in LTR
          and RTL alike, which a fixed left-arrow would not. */}
      <IconArrowLeft size={16} className="rtl:rotate-180" />
      {back.label}
    </Button>
  );
}

/** One number and its label. Not a `Card` — a stat is a fact, not a panel. */
function Stat({ fact, testId }: { fact: RecordFact; testId: string }) {
  return (
    <div className="rounded-lg border border-border bg-card px-4 py-3">
      <div className="text-xs text-muted-foreground">{fact.label}</div>
      <div className="mt-1 text-lg font-semibold text-foreground" data-testid={testId}>
        {fact.value === null || fact.value === '' ? UNKNOWN : fact.value}
      </div>
    </div>
  );
}

/**
 * The skeleton a record page shows while it loads.
 *
 * Exported because a ROUTE also needs it: the host resolves capabilities before
 * it may mount the screen at all (a fail-closed `can` would otherwise render
 * "you don't have permission" to an administrator who does), and a route that
 * hand-rolls its own skeleton makes the page jump when the real one takes over.
 */
export function RecordPageSkeleton({
  back,
  /** Announced to assistive tech; the visual skeleton says nothing out loud. */
  label,
  stats = 4,
  className,
  testId,
}: {
  back?: RecordBack;
  label?: string;
  stats?: number;
  className?: string;
  testId?: string;
}) {
  return (
    <div
      className={className ?? 'space-y-6'}
      aria-busy="true"
      data-testid={testId ?? 'record-loading'}
    >
      {back && <BackButton back={back} />}
      <Skeleton className="h-10 w-64" />
      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {Array.from({ length: stats }, (_, index) => (
          <Skeleton key={index} className="h-20" />
        ))}
      </div>
      <Skeleton className="h-64" />
      {label !== undefined && <span className="sr-only">{label}</span>}
    </div>
  );
}

/**
 * The record could not be loaded.
 *
 * No breadcrumb back-button here: the error state's own action is the way out,
 * and two identical "back" controls on one screen is one control too many for
 * anyone navigating by keyboard or screen reader.
 */
export function RecordPageError({
  title,
  description,
  back,
  className,
  testId,
}: {
  title: string;
  description: string;
  back: RecordBack;
  className?: string;
  testId?: string;
}) {
  return (
    <div className={className ?? 'space-y-6'} data-testid={testId ?? 'record-error'}>
      <ErrorState
        title={title}
        description={description}
        action={<Button onClick={back.onBack}>{back.label}</Button>}
      />
    </div>
  );
}

export function RecordPageShell<TFields extends object>({
  testId,
  fields,
  facts,
  t,
  access,
  back,
  icon,
  actions,
  notices,
  main,
  side,
  className,
}: RecordPageShellProps<TFields>) {
  // Called with the record and the dictionary, and NOTHING ELSE — `access` is
  // not in scope for it, and `fields` cannot carry a permission flag. These two
  // lines are the #895 fix expressed as a call signature.
  //
  // The cast unwraps `RecordFactsFn`'s compile-time check: with `TFields` still
  // generic here, TypeScript cannot yet decide the conditional, so it does not
  // know the prop is callable. At every real call site it HAS decided — a fields
  // type carrying `manageable` resolves the prop to `CallerFlagInRecordFields`
  // and the screen does not compile.
  const project = facts as RecordProjection<TFields>;
  const statement = project(fields, t ?? identityRecordTranslate);
  const badges = statement.badges ?? [];
  const stats = statement.stats ?? [];

  return (
    <div className={className ?? 'space-y-6'} data-testid={testId}>
      <PageHeader
        variant="card"
        breadcrumb={<BackButton back={back} />}
        icon={icon}
        title={statement.title}
        description={statement.subtitle}
        badge={
          badges.length > 0 ? (
            <span className="flex flex-wrap items-center gap-2">
              {badges.map((badge) => (
                <Badge
                  key={badge.key}
                  variant={BADGE_VARIANT[badge.tone ?? 'neutral']}
                  title={badge.title}
                  data-testid={`${testId}-badge-${badge.key}`}
                >
                  {badge.label}
                </Badge>
              ))}
            </span>
          ) : undefined
        }
        // Only when the record is editable: an action bar over a read-only page
        // promises an edit the page has already said is unavailable.
        action={access.editable ? actions : undefined}
      />

      {access.readOnlyReason !== null && (
        <p
          className="rounded-lg border border-border bg-muted/40 px-4 py-3 text-sm text-muted-foreground"
          data-testid={`${testId}-readonly-notice`}
        >
          {access.readOnlyReason}
        </p>
      )}

      {notices}

      {stats.length > 0 && (
        <div className="grid grid-cols-2 gap-3 lg:grid-cols-4" data-testid={`${testId}-stats`}>
          {stats.map((fact) => (
            <Stat key={fact.key} fact={fact} testId={`${testId}-stat-${fact.key}`} />
          ))}
        </div>
      )}

      <div className="grid grid-cols-1 gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          {/* Two DISTINCT renderings, never one form wearing `disabled`. The
              shell picks; the screen cannot accidentally ship the other. */}
          {access.editable ? main.editor : main.readOnly}
        </div>
        {side !== undefined && <div className="space-y-6">{side}</div>}
      </div>
    </div>
  );
}
