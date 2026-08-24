'use client';

/**
 * The append-only routing trail of one document (#993, rendering #989's tables).
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHY NOT THE `timeline` BLOCK TYPE, WHICH EXISTS AND IS FOR EXACTLY THIS
 * ─────────────────────────────────────────────────────────────────────────────
 * The SDK's `timeline` block declares `actorField`, `actionField`,
 * `timestampField`, `noteField`, `fromField`, `toField` — a field list that
 * reads like it was written for this table, because it was. It is still the
 * wrong tool here, and the reasons are structural rather than stylistic:
 *
 *  1. IT IS BOUND TO THE PLUGIN DATA PIPELINE. `TimelineRenderer` resolves its
 *     rows through `useEffectiveSource(block.source, …)` + `usePluginData` — the
 *     plugin data-source registry. A core admin screen has no `source` key to
 *     give it, and minting a fake one so a core page could pretend to be a
 *     plugin would route this screen through a renderer built to interpret a
 *     manifest, for no gain. That is the same argument the organizer's own
 *     docblock makes for not declaring itself as `dataTable` blocks.
 *  2. IT STRINGIFIES. Every field arrives as `String(row[field] ?? '')`, so
 *     `actor_profile_id` renders as `42`, `from_ou_id` → `to_ou_id` renders as
 *     `7 → 9`, and `action` renders as the raw enum `forwarded`. Ids are what
 *     this table stores; turning them into people and units needs two more
 *     endpoints and two more permission gates, and a block that cannot make a
 *     request cannot do it.
 *  3. ITS EMPTY STATE SAYS THE ONE THING THIS PAGE MUST NOT SAY. "No events
 *     recorded." is false for a document that was never circulated — nothing
 *     was recorded because nothing was ASKED, not because nothing happened —
 *     and a reader cannot tell that sentence apart from a trail that failed to
 *     load. #756's rule is an empty state, never invented content; here the
 *     server's own `trail` verdict supplies the honest sentence instead, and
 *     the block has no way to receive it.
 *  4. IT PAGINATES A LIST IT ALREADY HOLDS. `GET /{id}/trail` is
 *     SERVER-paginated, without bound, because a widely circulated document
 *     accumulates events forever. The block slices an array it fetched whole,
 *     which is paging within one page.
 *
 * What IS reused is the record slice's own timeline primitives —
 * `RecordTimeline`/`RecordTimelineItem`, the same pair the roles and users
 * record pages render their history through. They are in the same package as
 * the shell above them, their spine is a LOGICAL inline-start border so it
 * mirrors under RTL without this file knowing which side that is, and reusing
 * them is what stops a third record page inventing a third history layout. The
 * movement (`from` → `to`) goes in the `detail` slot as badges, which is the one
 * thing an audit-log trail does not have and a routing trail is mostly about.
 */

import { IconArrowRight } from '@tabler/icons-react';
import { RecordTimeline, RecordTimelineItem, formatRecordDateTime } from '@amroksaleh/features/record';
import { useFormattingLocale, useTranslation } from '@amroksaleh/features/i18n';
import { Badge } from '@amroksaleh/ui/badge';
import { Pagination } from '@/components/ui/pagination';

import type { Directory, TrailEvent, TrailPagination } from './types';

/**
 * The five verbs, as sentences.
 *
 * A lookup rather than a composed key, so the extractor sees five literal keys
 * and a translator sees five whole sentences. `null` for a verb this build has
 * never heard of, which renders the server's own value verbatim — a newer server
 * then shows a trail with one unfamiliar word in it rather than a trail with a
 * blank line in it.
 */
function actionLabel(t: (key: string, fallback?: string) => string, action: string): string {
  switch (action) {
    case 'issued':
      return t('record.trail.action.issued', 'Issued');
    case 'forwarded':
      return t('record.trail.action.forwarded', 'Forwarded');
    case 'acknowledged':
      return t('record.trail.action.acknowledged', 'Acknowledged');
    case 'returned':
      return t('record.trail.action.returned', 'Returned');
    case 'noted':
      return t('record.trail.action.noted', 'Note added');
    default:
      // The raw verb, not a guess and not a blank. A vocabulary this build does
      // not know is still information; inventing a label for it would not be.
      return action;
  }
}

/**
 * How this page names a person.
 *
 * Three outcomes, and they must stay three: a name when the directory answered,
 * `#42` when the directory was WITHHELD (the screen prints the reason beside the
 * list — see `DocumentTrail`), and "the system" when the column is null, which
 * migration 112 uses for an actor that is gone (`ON DELETE SET NULL`). Folding
 * the second and third together would tell a reader an absent actor is a
 * permission problem, or the reverse.
 */
export function personName(
  t: (key: string, fallback?: string, vars?: Record<string, string | number>) => string,
  directory: Directory,
  profileId: number | null
): string {
  if (profileId === null) {
    return t('record.person.gone', 'An account that no longer exists');
  }
  const known = directory.people.get(profileId);
  if (known !== undefined) {
    return known;
  }
  return t('record.person.byId', 'Account #{id}', { id: profileId });
}

/** The same three outcomes for a unit. */
export function unitName(
  t: (key: string, fallback?: string, vars?: Record<string, string | number>) => string,
  directory: Directory,
  ouId: number | null
): string {
  if (ouId === null) {
    // Migration 108/112 record the absence of a unit as an absence. Somebody
    // who belongs to none genuinely acted from nowhere; "unknown" would be a
    // different and wrong claim.
    return t('record.unit.none', 'No unit');
  }
  const known = directory.units.get(ouId);
  if (known !== undefined) {
    return known;
  }
  return t('record.unit.byId', 'Unit #{id}', { id: ouId });
}

export interface DocumentTrailProps {
  events: readonly TrailEvent[];
  pagination: TrailPagination | null;
  directory: Directory;
  onPageChange: (page: number) => void;
}

/**
 * The trail, oldest first — the order the server returns and the order a trail
 * is read in.
 *
 * Oldest-first with a pager, rather than newest-first, is deliberate on a RECORD
 * page: "what has happened to this" is a story, and the question "where is it
 * right now" has its own region above with its own gate. Reversing this list to
 * answer that second question would give the page two answers to it.
 *
 * This component never renders an empty list. A document with no trail does not
 * reach it — the screen shows the server's own `trail` refusal instead, which
 * says "not put into circulation" rather than implying nothing happened.
 */
export function DocumentTrail({ events, pagination, directory, onPageChange }: DocumentTrailProps) {
  const t = useTranslation('documents');
  const locale = useFormattingLocale();

  return (
    <div className="space-y-3" data-testid="document-record-trail">
      <RecordTimeline>
        {events.map((event) => {
          const moved = event.from_ou_id !== null || event.to_ou_id !== null;

          return (
            <RecordTimelineItem
              key={event.id}
              title={actionLabel(t, event.action)}
              meta={t('record.trail.by', '{who} — {when}', {
                who: personName(t, directory, event.actor_profile_id),
                when: formatRecordDateTime(event.occurred_at, locale) ?? event.occurred_at,
              })}
              detail={
                moved || event.note !== null ? (
                  <span className="block space-y-1">
                    {moved && (
                      <span className="flex flex-wrap items-center gap-1.5">
                        <Badge variant="outline">{unitName(t, directory, event.from_ou_id)}</Badge>
                        {/* Mirrored by direction, like the shell's back arrow:
                            movement points forward in reading order, which is
                            leftward under RTL. */}
                        <IconArrowRight size={12} className="rtl:rotate-180" aria-hidden />
                        <Badge variant="secondary">{unitName(t, directory, event.to_ou_id)}</Badge>
                      </span>
                    )}
                    {event.note !== null && (
                      // `whitespace-pre-line`: a note is free text somebody
                      // typed, and the trail is append-only, so the line breaks
                      // they chose are the only formatting they will ever get.
                      <span className="block whitespace-pre-line">{event.note}</span>
                    )}
                  </span>
                ) : undefined
              }
            />
          );
        })}
      </RecordTimeline>

      {/* Server-driven: the API already paginated. Rendered only when there is
          more than one page — a pager over a single page of five events is
          chrome that answers a question nobody asked. */}
      {pagination !== null && pagination.totalPages > 1 && (
        <Pagination
          page={pagination.page}
          perPage={pagination.perPage}
          total={pagination.total}
          onPageChange={onPageChange}
          navLabel={t('record.trail.pager', 'Trail pages')}
        />
      )}
    </div>
  );
}
