'use client';

/**
 * The document RECORD page (#993) — the screen you land on when you open a
 * document, and the thing that made everything #947 shipped reachable.
 *
 * A great deal of documents backend arrived with no screen: #947 item 1 gave
 * core an immutable issued document, item 3 gave it a routing engine with an
 * append-only trail, item 4 gave it a viewer that could only be mounted by a
 * PLUGIN-declared page, and #978's organizer listed documents with nowhere to
 * navigate. This is the address all four of those meet at.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * HOW THE VIEWER IS REUSED — NOT REBUILT, NOT EXTRACTED
 * ─────────────────────────────────────────────────────────────────────────────
 * `DocumentViewer` (#986) is mounted DIRECTLY, with its own props, from this
 * core screen. It turned out not to need extracting: only its `documentViewer`
 * BLOCK WRAPPER (`DocumentViewerRenderer`, which reads `documentIdFrom` out of
 * the master-detail context) is block-shaped. The component under it takes
 * `{documentId, pinnedArtifactId, emptyText}` — plain props, no block, no
 * manifest — and fetches core's own routes through web's `apiClient`. So the
 * seam already existed and the honest thing was to use it rather than to move a
 * 750-line file for the pleasure of moving it.
 *
 * What that reuse BUYS is the thing this page must not get wrong: the viewer
 * owns which artifact is on screen. It states version N of M on every render
 * including the M = 1 case, it warns when a superseded artifact is open and
 * names the newer one, and it offers the version picker. This screen passes
 * `pinnedArtifactId={null}`, which is "open at the CURRENT one" — deliberately
 * not a quieter default, because pinning from here would make a record page open
 * at a version somebody chose in code, and defaulting to anything other than
 * current is exactly how a superseded artifact starts reading as current.
 *
 * The one consequence worth naming: because the viewer fetches
 * `/api/v1/documents/{id}` for itself, this page issues that request twice —
 * once for the record's own fields and verdicts, once inside the viewer. That is
 * a deliberate trade. The alternative was widening the viewer's props to accept
 * an already-fetched record, which would have given it a second code path (props
 * OR fetch) in the one component on the platform whose whole purpose is to be
 * the single authority on which bytes a reader is looking at. A duplicated GET is
 * cheap; a viewer with two ways to learn what it is showing is not.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * WHO DECIDES WHAT IS ON THIS PAGE
 * ─────────────────────────────────────────────────────────────────────────────
 * Nothing here. `record.sections` is the SERVER's per-region ruling (#993,
 * following #910/#975), resolved against the same `RoleChecker` its middleware
 * enforces with and against three independent record predicates no route table
 * could express: whether the document can be re-issued at all, whether it has a
 * trail, and whether it is awaiting THIS caller. This screen reads three
 * verdicts and renders them.
 *
 * The rule that follows, and the reason the fetches below are conditional: a
 * region the caller may not SEE is absent from the verdict map, and this screen
 * must not then request its data. `sectionAccessFrom` returns `hidden` for an
 * absent key (and for an absent MAP, which is how a host with no resolver fails
 * closed), and the trail and recipient fetches are gated on that. A screen that
 * hid a region and fetched it anyway would be a gate with a network request
 * around the side of it.
 *
 * ─────────────────────────────────────────────────────────────────────────────
 * NAMES, AND WHAT HAPPENS WHEN THIS CALLER MAY NOT SEE THEM
 * ─────────────────────────────────────────────────────────────────────────────
 * The trail and the recipient rows are made of ids. Turning `actor_profile_id`
 * into a person needs `users:read` and turning `origin_ou_id` into a unit needs
 * `ous:read` — two gates that have nothing to do with `documents:read`, and
 * migration 101 removed `ous:read` from the plain user role, so a reader with
 * neither is an ordinary state.
 *
 * So the directory is fetched best-effort and its ABSENCE IS REPORTED. Ids
 * render as `Account #42` / `Unit #7` with a notice above the region saying which
 * permission would have named them — the pattern the organizer next door already
 * uses for the same reason. Printing a bare `#7` with no explanation is what
 * #951 is about: the reader cannot tell a permission boundary from a data bug,
 * and the one who could have fixed it is usually the one who cannot tell.
 */

import { useCallback, useMemo, useState } from 'react';
import { IconFileText } from '@tabler/icons-react';
import {
  RecordPageError,
  RecordPageShell,
  RecordPageSkeleton,
  formatRecordDate,
  sectionAccessFrom,
  useRecordResource,
} from '@amroksaleh/features/record';
import type { RecordFactsFn, RecordSectionSpec } from '@amroksaleh/features/record';
import { useFormattingLocale, useTranslation } from '@amroksaleh/features/i18n';
import { useAuth } from '@/lib/auth-context';
import { fetchAllPages } from '@/lib/api/fetch-all-pages';
import { DocumentViewer } from '@/components/plugin/blocks/document-viewer';

import { DocumentTrail, unitName } from './trail';
import { OpenRecipients } from './recipients';
import type {
  Directory,
  DocumentRecord,
  RouteRecipient,
  TrailEvent,
  TrailPage,
  TrailPagination,
} from './types';

/** The translator shape this file and its two children share. */
type Translate = (
  key: string,
  fallback?: string,
  vars?: Record<string, string | number>
) => string;

/**
 * The record's FIELDS — what the SERVER says the document IS.
 *
 * Carries no `sections`, no `starred`, and nothing else that states a decision
 * about the CALLER. That is enforced rather than remembered: the shell's
 * `RecordFields` guard turns a caller-permission key in this type into a compile
 * error naming the offending property (#895), and the fact projection below
 * receives only this.
 */
interface DocumentRecordFields {
  title: string;
  templateName: string;
  createdAt: string;
  versionCount: number;
  /**
   * Already resolved to a NAME by the screen, so the projection needs no
   * directory — and NULL while the directory is still in flight.
   *
   * Null rather than the id, deliberately: the stat strip has no room for the
   * notice that explains an id, so showing `Unit #7` there and then replacing it
   * with `Registry` a moment later would flash a raw number nobody could
   * interpret. The shell renders null as an em dash, which is the honest "not
   * answered yet".
   */
  originUnit: string | null;
  /**
   * How many people are still holding it.
   *
   * Null in BOTH the cases where this page does not know: the caller may not see
   * the region, or the request has not come back. Zero is a claim — "nobody is
   * holding this" — and making it before the answer arrives would be inventing
   * one for the duration of the request.
   */
  awaitingCount: number | null;
}

/**
 * What the page says about the document, at module scope.
 *
 * A pure function of the record and the dictionary, with no `can` in reach and a
 * fields type that cannot carry one. Note what is NOT a badge here: "Superseded".
 * Whether the artifact on screen is the current one is the VIEWER's statement to
 * make, on the artifact the reader is actually looking at, and a header badge
 * derived from the record would contradict it the moment they used the picker.
 */
// A FACTORY, not a constant, purely so the locale can reach it. `RecordFactsFn`
// is `(record, t) => facts` and that shape is shared with every other record
// page, so widening it here to carry a locale would change a contract this
// screen does not own. Closing over the value instead keeps the change local.
const documentFactsFor =
  (locale: string | undefined): RecordFactsFn<DocumentRecordFields> =>
  (document, t) => ({
  title: document.title,
  subtitle: t('record.subtitle', 'Issued from {template}', { template: document.templateName }),
  stats: [
    {
      key: 'versions',
      label: t('record.stat.versions', 'Versions issued'),
      value: document.versionCount,
    },
    {
      key: 'issued',
      label: t('record.stat.issued', 'First issued'),
      value: formatRecordDate(document.createdAt, locale),
    },
    {
      key: 'unit',
      label: t('record.stat.unit', 'Raised from'),
      value: document.originUnit,
    },
    {
      key: 'awaiting',
      label: t('record.stat.awaiting', 'Awaiting'),
      // null renders as an em dash by the shell rather than as 0 — "this caller
      // was not shown the recipients" and "nobody is holding it" are different
      // facts, and 0 would assert the second.
      value: document.awaitingCount,
    },
  ],
});

/**
 * This screen's copy for a denial code, per region, falling back to the
 * server's own sentence.
 *
 * Returning `null` for an unrecognised code is the point: a newer server sending
 * a code this build has never heard of leaves the region correctly read-only
 * with a vague explanation, rather than correctly read-only with a blank space
 * where the explanation goes.
 *
 * The `record` code is where the three regions genuinely diverge, so each gets
 * its own sentence — a shared one would have to be vague enough to cover "the
 * template is gone", "it was never circulated" and "it is not with you".
 */
function localizeDenial(
  t: Translate,
  region: 'document' | 'trail' | 'recipients'
): (code: string) => string | null {
  return (code) => {
    if (code === 'permission') {
      return region === 'document'
        ? t(
            'record.document.readOnly.permission',
            'You can read this document. Issuing a corrected version of it needs a capability your account does not have.'
          )
        : null;
    }
    if (code === 'record') {
      if (region === 'document') {
        return t(
          'record.document.readOnly.record',
          'No corrected version of this document can be issued. The versions already issued are unaffected and stay available.'
        );
      }
      if (region === 'trail') {
        return t(
          'record.trail.readOnly.record',
          'This document has not been put into circulation, so nothing has happened to it yet.'
        );
      }
      return t(
        'record.recipients.readOnly.record',
        'This document is not awaiting you. You are reading it as a record rather than as something to act on.'
      );
    }
    return null;
  };
}

/** One page of trail events. Bumped by the pager, and part of the fetch key. */
const FIRST_PAGE = 1;

export interface DocumentRecordScreenProps {
  documentId: number;
  onBack: () => void;
}

export function DocumentRecordScreen({ documentId, onBack }: DocumentRecordScreenProps) {
  const { apiClient, user } = useAuth();
  const t = useTranslation('documents');
  const locale = useFormattingLocale();
  const [trailPage, setTrailPage] = useState(FIRST_PAGE);

  const documentFactsMemo = useMemo(() => documentFactsFor(locale), [locale]);

  const back = useMemo(
    () => ({ label: t('record.back', 'Back to documents'), onBack }),
    [t, onBack]
  );

  // ── the record ──────────────────────────────────────────────────────────
  //
  // `'not-found'` rather than a throw: core answers "you may not see this" with
  // a 404 on purpose (a 403 confirms an enumerable id exists, which leaks the
  // shape of a tenant's activity), so this client genuinely does not know which
  // of the two it is and must not claim to.
  const loaded = useRecordResource<DocumentRecord | 'not-found'>(
    async () => {
      const response = await apiClient(`/api/v1/documents/${documentId}`);
      if (response.status === 404) {
        return 'not-found';
      }
      if (!response.ok) {
        throw new Error(t('record.error.load', 'Failed to load this document'));
      }
      const body = (await response.json()) as { data?: DocumentRecord };
      if (body.data === undefined) {
        throw new Error(t('record.error.load', 'Failed to load this document'));
      }
      return body.data;
    },
    [documentId],
    t('record.error.load', 'Failed to load this document')
  );

  const record = loaded.status === 'ready' && loaded.value !== 'not-found' ? loaded.value : null;

  // ── the three verdicts, resolved server-side ────────────────────────────
  const documentAccess = sectionAccessFrom(
    record?.sections,
    'document',
    localizeDenial(t, 'document')
  );
  const trailAccess = sectionAccessFrom(record?.sections, 'trail', localizeDenial(t, 'trail'));
  const recipientsAccess = sectionAccessFrom(
    record?.sections,
    'recipients',
    localizeDenial(t, 'recipients')
  );

  const recipientsVisible = recipientsAccess.state !== 'hidden';

  // WHY `editable` AND NOT `!== 'hidden'`, which is what the recipients region
  // uses. The `trail` region declares NO write permission server-side, so its
  // only possible refusal is the RECORD one — and that refusal means exactly
  // "there is no route to append to", i.e. nothing has ever happened to this
  // document. Read-only therefore implies an empty trail, and asking the trail
  // endpoint anyway would spend a round trip to be told the empty set the
  // verdict already stated.
  //
  // That is a real coupling to the server's requirement list, so it is written
  // down: if `trail` ever gains a `writePermission`, a caller lacking it would
  // be read-only WITH a trail, and this line would start hiding a trail they are
  // entitled to read. `DocumentRecordSectionsRealEngineTest` pins the contract
  // this depends on ("the trail region is editable for a reader who holds no
  // routing permission"), so changing it there fails a test rather than quietly
  // changing this page.
  const trailWorthFetching = trailAccess.state === 'editable';

  // ── the trail ───────────────────────────────────────────────────────────
  // `<TrailPage>`, not `<TrailPage | 'forbidden'>`: the hook takes
  // `() => Promise<T | 'forbidden'>` and turns that sentinel into its own
  // `{status: 'forbidden'}`, so `'forbidden'` can never reach `value`. Naming it
  // in `T` would have made every read site narrow against a state that does not
  // exist — and would have quietly typed the real one as possibly-a-string.
  const trail = useRecordResource<TrailPage>(
    async () => {
      if (!trailWorthFetching) {
        // `'forbidden'` is the record slice's "this panel is absent", which is
        // the right rendering for a region the verdict already explained: the
        // section prints the server's sentence and nothing tries to draw a list.
        return 'forbidden';
      }
      const response = await apiClient(
        `/api/v1/documents/${documentId}/trail?page=${trailPage}`
      );
      if (response.status === 403 || response.status === 404) {
        return 'forbidden';
      }
      if (!response.ok) {
        throw new Error(t('record.error.trail', "Failed to load this document's history"));
      }
      return (await response.json()) as TrailPage;
    },
    [documentId, trailPage, trailWorthFetching],
    t('record.error.trail', "Failed to load this document's history")
  );

  // ── the recipients ──────────────────────────────────────────────────────
  const recipients = useRecordResource<RouteRecipient[]>(
    async () => {
      if (!recipientsVisible) {
        return 'forbidden';
      }
      const response = await apiClient(`/api/v1/documents/${documentId}/recipients`);
      if (response.status === 403 || response.status === 404) {
        return 'forbidden';
      }
      if (!response.ok) {
        throw new Error(t('record.error.recipients', 'Failed to load who has this document'));
      }
      const body = (await response.json()) as { data?: RouteRecipient[] };
      return body.data ?? [];
    },
    [documentId, recipientsVisible],
    t('record.error.recipients', 'Failed to load who has this document')
  );

  // ── the directory ───────────────────────────────────────────────────────
  //
  // Two independent walks, two independent verdicts. Never throws: a directory
  // this caller may not read is a state to REPORT, not a failure that should
  // take the page down — the document, its versions and its trail are all still
  // readable with ids in place of names.
  const directory = useRecordResource<Directory>(
    async () => {
      const [peopleResult, unitsResult] = await Promise.all([
        fetchAllPages<{ id: number; name?: string; email?: string }>(apiClient, '/api/v1/users'),
        fetchAllPages<{ id: number; name: string }>(apiClient, '/api/v1/ous'),
      ]);

      const people = new Map<number, string>();
      if (peopleResult.complete) {
        for (const person of peopleResult.items) {
          // The email, falling back to the derived `name` the users endpoint
          // synthesises from its local part. There is no name column in the
          // identity model, so the email IS how a person is identified here.
          const label = person.email !== undefined && person.email !== '' ? person.email : person.name;
          if (label !== undefined && label !== '') {
            people.set(person.id, label);
          }
        }
      }

      const units = new Map<number, string>();
      if (unitsResult.complete) {
        for (const unit of unitsResult.items) {
          units.set(unit.id, unit.name);
        }
      }

      return {
        people,
        units,
        // A PARTIAL walk counts as unavailable, not as available-and-short. A
        // directory missing rows would name some ids and silently number
        // others, which reads as "these two people were deleted".
        peopleAvailable: peopleResult.complete,
        unitsAvailable: unitsResult.complete,
      };
    },
    [documentId],
    t('record.error.directory', 'Failed to load names')
  );

  const resolvedDirectory: Directory = useMemo(
    () =>
      directory.status === 'ready'
        ? directory.value
        : // While it is in flight, or if it failed outright, ids are what we
          // have and the notices below say so. Fail-closed on the FLAGS, so the
          // explanation is shown rather than withheld.
          { people: new Map(), units: new Map(), peopleAvailable: false, unitsAvailable: false },
    [directory]
  );

  const onTrailPage = useCallback((page: number) => setTrailPage(page), []);

  // ── loading and failure ─────────────────────────────────────────────────

  if (loaded.status === 'error') {
    return (
      <RecordPageError
        testId="document-record-error"
        title={t('record.error.title', 'This document could not be loaded')}
        // The underlying message, where it genuinely helps: on the record's own
        // failure page "Document not found" beats "Failed to load".
        description={loaded.detail ?? t('record.error.load', 'Failed to load this document')}
        back={back}
      />
    );
  }

  if (loaded.status === 'ready' && loaded.value === 'not-found') {
    return (
      <RecordPageError
        testId="document-record-missing"
        title={t('record.missing.title', 'This document is not available to you')}
        description={t(
          'record.missing.body',
          'It may have been removed, or it may not be shared with your account. Core answers those two the same way on purpose.'
        )}
        back={back}
      />
    );
  }

  if (loaded.status !== 'ready' || record === null) {
    return (
      <RecordPageSkeleton
        testId="document-record-loading"
        back={back}
        label={t('record.loading', 'Loading document…')}
      />
    );
  }

  // ── the regions ─────────────────────────────────────────────────────────

  // `status === 'ready'` is the only check needed: the hook has already turned
  // its `'forbidden'` sentinel into a status of its own, so a ready resource
  // carries real rows or the screen never gets here.
  const trailEvents: readonly TrailEvent[] = trail.status === 'ready' ? trail.value.data : [];
  const trailPagination: TrailPagination | null =
    trail.status === 'ready' ? trail.value.pagination : null;
  const recipientsSettled = recipients.status === 'ready';
  const recipientRows: readonly RouteRecipient[] = recipientsSettled ? recipients.value : [];

  const fields: DocumentRecordFields = {
    title: record.title,
    templateName: record.template_name,
    createdAt: record.created_at,
    versionCount: record.artifacts.length,
    // The name once the directory has answered, and nothing before that. A unit
    // with no id at all is a real answer ("No unit") and is not withheld.
    originUnit:
      record.origin_ou_id === null || directory.status === 'ready'
        ? unitName(t, resolvedDirectory, record.origin_ou_id)
        : null,
    // Counted only from a settled answer, and only from the OPEN rows —
    // `closed_by_event_id` is what makes a row something already done, so
    // `rows.length` here would report finished work as outstanding.
    awaitingCount:
      recipientsVisible && recipientsSettled
        ? recipientRows.filter((recipient) => recipient.open).length
        : null,
  };

  /**
   * The viewer, in both renderings.
   *
   * The read-only one and the editable one differ by the notice above the
   * viewer, never by the viewer itself: what a reader is shown of the DOCUMENT
   * is not something a permission changes, and a version picker that appeared
   * only for privileged callers would be hiding "this was corrected twice" from
   * the audience most likely to be auditing it.
   */
  const viewer = (
    <DocumentViewer
      documentId={String(record.id)}
      // Always the current artifact. See the file docblock: pinning from a
      // record page would open at a version chosen in code, and any default
      // other than "current" is how a superseded artifact starts reading as
      // current.
      pinnedArtifactId={null}
    />
  );

  const documentSection: RecordSectionSpec = {
    key: 'document',
    title: t('record.document.title', 'The document'),
    description: t(
      'record.document.subtitle',
      'Every version ever issued is here and stays fetchable. A correction adds a version; it never replaces one.'
    ),
    access: documentAccess,
    editor: viewer,
    readOnly: viewer,
  };

  const trailSection: RecordSectionSpec = {
    key: 'trail',
    title: t('record.trail.title', 'What has happened to it'),
    description: t(
      'record.trail.subtitle',
      'Append-only. An entry is never edited or removed — a correction is a new entry.'
    ),
    access: trailAccess,
    editor: (
      <div className="space-y-3">
        {!resolvedDirectory.peopleAvailable && <DirectoryNotice kind="people" t={t} />}
        {!resolvedDirectory.unitsAvailable && <DirectoryNotice kind="units" t={t} />}
        {trail.status === 'error' ? (
          <p className="text-sm text-destructive">{trail.message}</p>
        ) : trail.status === 'loading' ? (
          <p className="text-sm text-muted-foreground">
            {t('record.trail.loading', 'Reading the trail…')}
          </p>
        ) : trailEvents.length === 0 ? (
          // Reachable only in the narrow window where the verdict says there IS
          // a trail and the page it asked for is past the end. Says which, rather
          // than showing a blank region that reads as "nothing happened".
          <p className="text-sm text-muted-foreground">
            {t('record.trail.pastEnd', 'There are no entries on this page of the trail.')}
          </p>
        ) : (
          <DocumentTrail
            events={trailEvents}
            pagination={trailPagination}
            directory={resolvedDirectory}
            onPageChange={onTrailPage}
          />
        )}
      </div>
    ),
    // The read-only rendering of a trail with nothing in it is NOT an empty
    // list. The section's own read-only line carries the server's sentence
    // ("not put into circulation"), and this is the actionable half of it: what
    // would have to happen for there to be a trail at all.
    readOnly: (
      <p className="text-sm text-muted-foreground" data-testid="document-record-no-trail">
        {t(
          'record.trail.none',
          'Nothing has been recorded against this document. A trail begins when it is sent into a route, and every step it takes is appended here.'
        )}
      </p>
    ),
  };

  const recipientsBody = (awaitingViewer: boolean) => (
    <div className="space-y-3">
      {!resolvedDirectory.peopleAvailable && <DirectoryNotice kind="people" t={t} />}
      {recipients.status === 'error' ? (
        <p className="text-sm text-destructive">{recipients.message}</p>
      ) : recipients.status === 'loading' ? (
        <p className="text-sm text-muted-foreground">
          {t('record.recipients.loading', 'Checking who has it…')}
        </p>
      ) : recipientRows.length === 0 ? (
        // No rows at all: this document was never sent to anybody. Distinct
        // from "everyone has acted", which `OpenRecipients` reports.
        <p className="text-sm text-muted-foreground" data-testid="document-record-no-recipients">
          {t(
            'record.recipients.none',
            'This document has not been sent to anyone, so nobody is holding it.'
          )}
        </p>
      ) : (
        <OpenRecipients
          recipients={recipientRows}
          directory={resolvedDirectory}
          // `user.id` IS the profile id: `GET /api/me` returns the
          // `profile_id` claim under that name, and ADR 0005's hard cutover
          // made profile the canonical identity everywhere. Used only to mark
          // which row is the reader's, never to decide whether they may act.
          viewerProfileId={typeof user?.id === 'number' ? user.id : null}
          awaitingViewer={awaitingViewer}
        />
      )}
    </div>
  );

  const recipientsSection: RecordSectionSpec = {
    key: 'recipients',
    title: t('record.recipients.title', 'Where it is now'),
    description: t(
      'record.recipients.subtitle',
      'Everyone a route reached who has not acted yet. A row closes when they do.'
    ),
    access: recipientsAccess,
    // `editable` for this region means one thing and one thing only: an open row
    // names you. The two renderings differ by saying so — see `OpenRecipients`
    // for why the act buttons themselves are the inbox's and not this page's.
    editor: recipientsBody(true),
    readOnly: recipientsBody(false),
  };

  return (
    <RecordPageShell
      testId="document-record"
      fields={fields}
      facts={documentFactsMemo}
      t={t}
      back={back}
      icon={<IconFileText />}
      // No `actions`: an immutable record has no save bar, and the one write it
      // does have (`POST /{id}/render`) has no UI on this page — see the PR body.
      // No page-level `access` either: with regions, the shell DERIVES the one
      // page-level answer it still needs from the regions themselves, so the
      // header and the body cannot disagree.
      sections={[documentSection, trailSection, recipientsSection]}
    />
  );
}

/**
 * Why an id is showing where a name should be.
 *
 * Named rather than inlined twice: the trail and the recipient list both need
 * it, and two copies of an explanation is two chances for them to explain it
 * differently. It names the PERMISSION because the audience for this sentence is
 * whoever can grant it, and #951's finding is that a screen which degrades
 * silently makes a permission boundary indistinguishable from a bug.
 */
function DirectoryNotice({ kind, t }: { kind: 'people' | 'units'; t: Translate }) {
  return (
    <p
      className="rounded-md border border-border bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
      data-testid={`document-record-directory-${kind}`}
    >
      {kind === 'people'
        ? t(
            'record.directory.people',
            'People are shown by account number: naming them needs the users:read permission.'
          )
        : t(
            'record.directory.units',
            'Units are shown by number: naming them needs the ous:read permission.'
          )}
    </p>
  );
}
